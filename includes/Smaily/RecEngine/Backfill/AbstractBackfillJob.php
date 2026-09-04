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
				"SELECT id, cursor_value, processed_count, total_count FROM {$table} WHERE job_type = %s AND target = %s",
				$this->job_type(),
				self::TARGET
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $state ) ) {
			return array(
				'processed' => 0,
				'sent'      => 0,
				'failed'    => 0,
				'remaining' => 0,
				'completed' => true,
			);
		}

		$after = isset( $state['cursor_value'] ) ? (int) $state['cursor_value'] : 0;
		$ids   = $this->fetch_ids_after( $after, $batch_size );

		foreach ( $ids as $entity_id ) {
			$this->enqueue_record( (int) $entity_id );
		}

		// Inline-flush this batch before advancing — bounds the queue + makes
		// progress mean "sent", not "enqueued".
		$flush = $this->drain_queue();

		$processed = (int) $state['processed_count'] + count( $ids );
		$cursor    = empty( $ids ) ? $after : (int) end( $ids );
		$completed = count( $ids ) < $batch_size;

		$wpdb->update(
			$table,
			array(
				'processed_count' => $processed,
				'cursor_value'    => (string) $cursor,
				'status'          => $completed ? 'completed' : 'running',
				'completed_at'    => $completed ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => (int) $state['id'] ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'processed' => count( $ids ),
			'sent'      => $flush['sent'],
			'failed'    => $flush['failed'],
			'remaining' => max( 0, (int) $state['total_count'] - $processed ),
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

	protected function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
