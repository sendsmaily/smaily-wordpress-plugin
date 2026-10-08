<?php
/**
 * Shared rec-engine backfill: cursor-paginated traversal of existing WC records
 * → the SAME ingest queue + D6 flusher the live hooks use.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\AbstractD6Flusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * One ingest path, two triggers: a live WC hook enqueues a single changed
 * record; a backfill walks the whole table and enqueues each. Both land in the
 * SAME `smly_rec_event_queue` and drain through the SAME `AbstractD6Flusher`
 * (so D6 error-split, retry, and engine dedup are reused, not reimplemented).
 *
 * Resumable: the cursor (last-seen entity id) lives in the
 * `smly_plus_backfill_job` row, so a tick that times out or crashes continues
 * from the saved cursor — never from the start. The `(job_type, target)` UNIQUE
 * key lets the rec-engine rows (target `rec_engine`) coexist with the legacy
 * contacts row (target `smaily`).
 *
 * Backfill→engine path = enqueue a batch, then **flush inline before the next
 * batch** (decision 3.5 (b)): progress reflects records actually SENT (not just
 * queued), and the queue stays bounded (each batch is drained before the next
 * is enqueued) rather than ballooning to thousands of pending rows. No
 * freshness marker (decision 3.5 (i)) — the engine ingest is an idempotent
 * UPSERT, so re-sending a record is harmless; skipping unchanged records is a
 * future re-run optimisation, not correctness.
 *
 * Not final: tests subclass with in-memory doubles for the WC enumeration.
 */
abstract class AbstractBackfillJob implements BackfillJobInterface {

	public const TARGET = 'rec_engine';

	public const TABLE_SUFFIX = 'smly_plus_backfill_job';

	/**
	 * Safety cap on inline-flush passes per batch. Each pass drains up to the
	 * flusher's batch size; a 100-record batch (even with variation fan-out)
	 * never needs anywhere near this many, so it bounds a pathological loop
	 * without truncating real work.
	 */
	private const MAX_DRAIN_PASSES = 200;

	/**
	 * How long after start() a running import may have no batch queued before
	 * it counts as stalled (PRO-3886). start() and its first batch being queued
	 * are a few lines apart; ten minutes keeps a just-started import from ever
	 * reading as stopped, and is short against a night the manifest would skip.
	 */
	public const STALL_GRACE_SECONDS = 600;

	protected IngestQueue $queue;

	protected AbstractD6Flusher $flusher;

	public function __construct( IngestQueue $queue, AbstractD6Flusher $flusher ) {
		$this->queue   = $queue;
		$this->flusher = $flusher;
	}

	// --- per-domain --------------------------------------------------------

	/** Job type slug — the (job_type, target) key + the REST/UI identifier. */
	abstract public function job_type(): string;

	/** Engine batch cap for this domain (catalog/customers 100, orders 50). */
	abstract protected function batch_size(): int;

	/** Total records to backfill (for the progress denominator). */
	abstract protected function count_total(): int;

	/**
	 * The next page of entity ids strictly after $after_id, ascending, capped
	 * at $limit. MUST be a real `WHERE id > cursor ORDER BY id LIMIT` so the
	 * walk is resumable and can't shift under inserts/deletes (unlike offset).
	 *
	 * @return int[]
	 */
	abstract protected function fetch_ids_after( int $after_id, int $limit ): array;

	/**
	 * Enqueue one record into the shared ingest queue — mirroring the domain's
	 * live HookHandler (catalog expands variations; customers/orders enqueue
	 * the single id with their flush hook/group).
	 */
	abstract protected function enqueue_record( int $entity_id ): void;

	// --- lifecycle ---------------------------------------------------------

	public function start(): int {
		global $wpdb;

		$total = $this->count_total();
		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (job_type, target, status, total_count, processed_count, started_at) VALUES (%s, %s, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE status = VALUES(status), total_count = VALUES(total_count), processed_count = 0, cursor_value = NULL, started_at = VALUES(started_at), completed_at = NULL, error_message = NULL",
				$this->job_type(),
				self::TARGET,
				'running',
				$total,
				0,
				current_time( 'mysql', true )
			)
		);

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE job_type = %s AND target = %s",
				$this->job_type(),
				self::TARGET
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $id;
	}

	/**
	 * @return array{processed: int, sent: int, failed: int, remaining: int, completed: bool}
	 */
	public function process_batch(): array {
		global $wpdb;

		$batch_size = $this->batch_size();
		$table      = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$state = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, cursor_value, processed_count, total_count FROM {$table} WHERE job_type = %s AND target = %s",
				$this->job_type(),
				self::TARGET
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $state ) ) {
			return $this->batch_result( 0, 0, 0, 0, true );
		}

		$after = isset( $state['cursor_value'] ) ? (int) $state['cursor_value'] : 0;

		// The merchant cancelled the import (Cancel / Hold back) after Action
		// Scheduler had already claimed this tick (PRO-3821), or an earlier
		// batch failed (PRO-3890): send nothing, leave the row as it is, and
		// stop the tick chain. Only a new start() takes it out of either state.
		$status = (string) ( $state['status'] ?? '' );
		if ( $status === BackfillJobInterface::STATUS_CANCELLED || $status === BackfillJobInterface::STATUS_FAILED ) {
			return $this->batch_result(
				0,
				0,
				0,
				max( 0, (int) $state['total_count'] - (int) $state['processed_count'] ),
				true
			);
		}

		// The engine refused this account outright (contract §2
		// `403 tenant_inactive`) — every row this batch enqueued would sit
		// unsendable, so stop before enumerating anything. The cursor and the
		// `running` status are left exactly as they are: the import is paused,
		// not lost, and the next tick resumes it once a new connection is set
		// up (PRO-1893).
		if ( ! $this->flusher->sending_allowed() ) {
			return $this->batch_result(
				0,
				0,
				0,
				max( 0, (int) $state['total_count'] - (int) $state['processed_count'] ),
				false
			);
		}

		try {
			return $this->walk_batch( $state, $after, $batch_size );
		} catch ( \Throwable $e ) {
			// Anything but the engine's own errors (the flusher handles those):
			// a database error, a product that cannot be built. Left to escape,
			// it ends the tick before the next one is scheduled and the row
			// stays `running` forever (PRO-3890). Stop the import instead —
			// Import now runs it again.
			$this->record_failure( (int) $state['id'], $e );
			return $this->batch_result(
				0,
				0,
				0,
				max( 0, (int) $state['total_count'] - (int) $state['processed_count'] ),
				true
			);
		}
	}

	/**
	 * Enqueue and send the next page, then write the progress.
	 *
	 * @param array<string, mixed> $state The job row this batch read.
	 * @return array{processed: int, sent: int, failed: int, remaining: int, completed: bool}
	 */
	private function walk_batch( array $state, int $after, int $batch_size ): array {
		global $wpdb;

		$ids = $this->fetch_ids_after( $after, $batch_size );

		foreach ( $ids as $entity_id ) {
			$this->enqueue_record( (int) $entity_id );
		}

		// Inline-flush this batch before advancing — bounds the queue + makes
		// progress mean "sent", not "enqueued".
		$flush = $this->drain_queue();

		$processed = (int) $state['processed_count'] + count( $ids );
		$cursor    = empty( $ids ) ? $after : (int) end( $ids );
		$completed = count( $ids ) < $batch_size;

		// Written only while the row is still running: a cancel that lands
		// while this batch sends stays a cancel (PRO-3821).
		$wpdb->update(
			$this->table_name(),
			array(
				'processed_count' => $processed,
				'cursor_value'    => (string) $cursor,
				'status'          => $completed ? 'completed' : 'running',
				'completed_at'    => $completed ? current_time( 'mysql', true ) : null,
			),
			array(
				'id'     => (int) $state['id'],
				'status' => BackfillJobInterface::STATUS_RUNNING,
			),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return $this->batch_result(
			count( $ids ),
			$flush['sent'],
			$flush['failed'],
			max( 0, (int) $state['total_count'] - $processed ),
			$completed
		);
	}

	/**
	 * Mark the import failed — only while it is still running, so a cancel
	 * that landed meanwhile stays a cancel (PRO-3821). The stored reason is
	 * the error class and where it was thrown, never the message: a message
	 * can carry a customer's email.
	 */
	private function record_failure( int $job_id, \Throwable $e ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'status'        => BackfillJobInterface::STATUS_FAILED,
				'error_message' => sprintf( '%s at %s:%d', get_class( $e ), basename( $e->getFile() ), $e->getLine() ),
			),
			array(
				'id'     => $job_id,
				'status' => BackfillJobInterface::STATUS_RUNNING,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * The one shape process_batch() answers in, whatever happened.
	 *
	 * @return array{processed: int, sent: int, failed: int, remaining: int, completed: bool}
	 */
	private function batch_result( int $processed, int $sent, int $failed, int $remaining, bool $completed ): array {
		return array(
			'processed' => $processed,
			'sent'      => $sent,
			'failed'    => $failed,
			'remaining' => $remaining,
			'completed' => $completed,
		);
	}

	/**
	 * Drain the freshly-enqueued batch through the flusher. Repeats until a
	 * pass processes nothing (queue empty, or only retry-deferred rows remain —
	 * pending() excludes future next_retry_at, so the loop terminates), capped
	 * for safety. Deferred-retry rows are picked up by the recurring flusher.
	 *
	 * @return array{sent: int, failed: int, skipped: int}
	 */
	private function drain_queue(): array {
		$totals = array(
			'sent'    => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		for ( $pass = 0; $pass < self::MAX_DRAIN_PASSES; $pass++ ) {
			$stats              = $this->flusher->flush();
			$totals['sent']    += $stats['sent'];
			$totals['failed']  += $stats['failed'];
			$totals['skipped'] += $stats['skipped'];
			if ( $stats['processed'] === 0 ) {
				break;
			}
		}

		return $totals;
	}

	/**
	 * The state row of one backfill — any job type, the legacy contacts one
	 * (target `smaily`) included — or null before it was ever started.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read_state( string $job_type, string $target = self::TARGET ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, processed_count, synced_count, total_count, started_at, completed_at, error_message FROM {$table} WHERE job_type = %s AND target = %s",
				$job_type,
				$target
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/** Whether this import's state row is `running` — queued or mid-walk — and not stalled. */
	public function is_running(): bool {
		$row = self::read_state( $this->job_type() );
		return is_array( $row ) && $row['status'] === BackfillJobInterface::STATUS_RUNNING && ! self::is_stalled( $this->job_type(), $row );
	}

	/**
	 * A `running` import that nothing drives any more (PRO-3886): no batch of
	 * it is queued or running in Action Scheduler, and it started longer than
	 * STALL_GRACE_SECONDS ago. Deactivation cancels the queued batch, and a
	 * batch that dies on a fatal error schedules none. Read-only: the row is
	 * not rewritten and the import is not restarted — Import now does that.
	 * The status route asks this for the contact import as well (PRO-3902):
	 * it runs on the same tick hook, keyed by its job_type. The daily contact
	 * refresh restarts a stalled contact import (PRO-3981).
	 *
	 * @param array<string, mixed> $row A state row from read_state().
	 */
	public static function is_stalled( string $job_type, array $row ): bool {
		if ( ( $row['status'] ?? '' ) !== BackfillJobInterface::STATUS_RUNNING || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		$started = strtotime( (string) ( $row['started_at'] ?? '' ) . ' UTC' );
		if ( $started === false || $started > time() - self::STALL_GRACE_SECONDS ) {
			return false;
		}

		return ! as_has_scheduled_action( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ) );
	}

	protected function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
