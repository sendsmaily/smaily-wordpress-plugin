<?php
/**
 * Rec-engine durable ingest queue (backed by smly_rec_event_queue + AS).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

/**
 * Persistent queue for events headed to the Smaily Recommendation Engine
 * ingest API: catalog (sub-PR 3.2), customers + orders (3.3),
 * identity.merge (3.3+). Browse events deliberately do NOT use this queue
 * — they go to a 30s transient buffer (migration 004 header, PLUGIN.md §3).
 *
 * This is the rec-engine sibling of Smaily\EventQueue (which serves the
 * Smaily marketing API via smly_plus_event_queue). They are separate on
 * purpose: different destination API, different table, and — critically —
 * different idempotency model. The rec queue carries an `event_uuid`
 * (CHAR(36), UNIQUE) that travels to the engine as the wire field
 * `event_id`. The engine deduplicates on (tenant_id, event_id), so a row
 * resent by an Action Scheduler retry — same row, same event_uuid — is
 * recognised as a duplicate and answered 200 {"deduplicated": true}
 * rather than double-applied. queue.event_uuid == HTTP body.event_id is a
 * pinned read/write-symmetry invariant (CatalogPayloadBuilder converts;
 * its test asserts the exact field name).
 *
 * Storage layout: rows in {prefix}smly_rec_event_queue, status
 * pending → sent (terminal happy path, incl. a deduplicated 200) or
 * pending → failed (terminal after max_attempts). A retry parks the row
 * with next_retry_at in the future; pending() skips rows not yet due.
 *
 * enqueue() uses INSERT IGNORE so a duplicate event_uuid (a double-fired
 * hook handing us the same uuid, or a re-enqueue race) is a silent no-op
 * rather than a fatal — idempotency at the DB layer, mirroring the
 * engine's own dedup.
 *
 * Not final: tests subclass to observe generate_uuid() / schedule without
 * standing up $wpdb + Action Scheduler. Same rationale as Smaily\Client
 * and EventQueue.
 */
class IngestQueue {

	public const TABLE_SUFFIX = 'smly_rec_event_queue';

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';

	public const FLUSH_HOOK = 'smly_rec_flush_ingest';
	public const AS_GROUP   = 'smaily-rec-ingest';

	/** Mirrors the smly_rec_event_queue.max_attempts column default. */
	public const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Persist an ingest event and ensure a flush is scheduled.
	 *
	 * @param string               $event_type  e.g. "catalog.upsert", "customer.upsert".
	 * @param string               $entity_id   Free-form identifier (product_id, user_id, order_id).
	 * @param array<string, mixed> $payload     JSON-serialisable data the flush job hands to PayloadBuilder.
	 * @param string|null          $event_uuid  Wire idempotency key; auto-generated (UUID v4) when null.
	 * @param string|null          $flush_hook  Action Scheduler hook to schedule for this row's endpoint;
	 *                                           null = the catalog flush hook (backward compatible).
	 * @param string|null          $flush_group AS group for $flush_hook; null = the catalog group.
	 *
	 * @return int|null Inserted row id on success; null when the payload can't be
	 *                  JSON-encoded, the insert errored, or the event_uuid was a
	 *                  duplicate that INSERT IGNORE skipped. Null is intentionally
	 *                  silent — callers are hot WP hooks that must not bail.
	 */
	public function enqueue(
		string $event_type,
		string $entity_id,
		array $payload,
		?string $event_uuid = null,
		?string $flush_hook = null,
		?string $flush_group = null
	): ?int {
		global $wpdb;

		$json = wp_json_encode( $payload );
		if ( $json === false ) {
			return null;
		}

		$uuid  = $event_uuid ?? $this->generate_uuid();
		$table = $this->table_name();

		// INSERT IGNORE: a duplicate event_uuid is a no-op (0 rows), never a
		// fatal. Table name interpolation is unavoidable (MySQL forbids
		// parameterising it); $table is controlled.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(event_type, entity_id, event_uuid, payload, created_at, attempts, max_attempts, status)
					VALUES (%s, %s, %s, %s, %s, %d, %d, %s)",
				$event_type,
				$entity_id,
				$uuid,
				$json,
				current_time( 'mysql', true ),
				0,
				self::DEFAULT_MAX_ATTEMPTS,
				self::STATUS_PENDING
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( $affected === false || (int) $affected === 0 ) {
			// false = DB error; 0 = duplicate event_uuid ignored. No new row.
			return null;
		}

		$id = (int) $wpdb->insert_id;
		$this->maybe_schedule_flush( $flush_hook ?? self::FLUSH_HOOK, $flush_group ?? self::AS_GROUP );

		return $id;
	}

	/**
	 * Fetch the next batch of due pending events.
	 *
	 * "Due" = status pending AND (never retried OR next_retry_at has
	 * passed). Ordering by created_at keeps the queue FIFO so a product's
	 * catalog.delete can't overtake an earlier catalog.upsert for the same
	 * SKU. The flush job processes the batch and advances each row with
	 * mark_sent() / record_attempt() / mark_failed().
	 *
	 * The queue is shared across ingest endpoints (catalog, customers,
	 * orders — see the class doc). $event_types scopes a drain to one
	 * endpoint's rows so each flusher only sees rows it can build: the
	 * catalog flusher passes catalog.* and the customer flusher passes
	 * customer.*, and neither silently consumes the other's rows. null (the
	 * default) keeps the original unscoped behaviour — backward compatible.
	 *
	 * @param array<int, string>|null $event_types Restrict to these event
	 *        types; null/empty = every pending row regardless of type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pending( int $limit = 100, ?array $event_types = null ): array {
		global $wpdb;

		$table = $this->table_name();

		// Build the optional event_type filter as a placeholder list so the
		// values stay parameterised (never interpolated into the SQL).
		$type_clause = '';
		$type_args   = array();
		if ( is_array( $event_types ) && $event_types !== array() ) {
			$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
			$type_clause  = " AND event_type IN ( {$placeholders} )";
			$type_args    = array_values( array_map( 'strval', $event_types ) );
		}

		$args = array_merge(
			array( self::STATUS_PENDING, current_time( 'mysql', true ) ),
			$type_args,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// The placeholder count is dynamic (optional event_type IN-list), so the
		// args are spread; the static replacement-count sniff can't follow that.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, entity_id, event_uuid, payload, created_at, attempts, max_attempts
					FROM {$table}
					WHERE status = %s AND ( next_retry_at IS NULL OR next_retry_at <= %s ){$type_clause}
					ORDER BY created_at ASC
					LIMIT %d",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	public function mark_sent( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array( 'status' => self::STATUS_SENT ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'status'     => self::STATUS_FAILED,
				'last_error' => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment the attempt counter, record the error, and park the row
	 * for a future retry by stamping next_retry_at = now + $retry_in_seconds
	 * (computed in SQL against UTC_TIMESTAMP() so it stays timezone-correct
	 * without a PHP clock read). The flush job picks the backoff value via
	 * exponential policy and flips the row to STATUS_FAILED once attempts
	 * reaches max_attempts.
	 */
	public function record_attempt( int $id, string $error, int $retry_in_seconds = 60 ): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
					SET attempts = attempts + 1,
						last_error = %s,
						next_retry_at = ( UTC_TIMESTAMP() + INTERVAL %d SECOND )
					WHERE id = %d",
				$error,
				$retry_in_seconds,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Persist the send-time exchange for a row: the exact request body POSTed
	 * (`sent_payload`, null when nothing was sent — e.g. a terminal skip) and a
	 * small JSON summary of the engine reply (`last_response`). Written by the
	 * flusher when a row reaches a terminal/retry state so the Event Log
	 * "Details" can show what we sent and what came back (F3-44). NEVER carries
	 * the Authorization header. Separate from mark_*() so those signatures (and
	 * the test doubles overriding them) stay unchanged.
	 */
	public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'sent_payload'  => $sent_payload,
				'last_response' => $last_response,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Revive terminally-`failed` rows back to `pending` so the recurring flushers
	 * re-attempt them (3.10.1, the recovery half of the Event Log). Resets the
	 * attempt counter + clears the retry-park + last_error so the row starts
	 * fresh. `$ids` null = every failed row; otherwise only the given ids.
	 *
	 * This is the merchant-driven "Retry failed" path — manual by design (3.10.1):
	 * a deterministic 4xx (validation) failure would loop forever under blind
	 * auto-retry, so re-driving is an explicit action. Returns the row count.
	 *
	 * @param int[]|null $ids
	 */
	public function reset_failed( ?array $ids = null ): int {
		global $wpdb;
		$table = $this->table_name();

		$set = 'SET status = %s, attempts = 0, next_retry_at = NULL, last_error = NULL';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $ids === null ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table} {$set} WHERE status = %s",
				self::STATUS_PENDING,
				self::STATUS_FAILED
			);
		} else {
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $i ): bool => $i > 0 ) );
			if ( $ids === array() ) {
				return 0;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = $wpdb->prepare(
				"UPDATE {$table} {$set} WHERE status = %s AND id IN ( {$placeholders} )",
				array_merge( array( self::STATUS_PENDING, self::STATUS_FAILED ), $ids )
			);
		}

		return (int) $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Schedule this queue's recurring flush hooks immediately so a reset row
	 * re-sends promptly instead of waiting for the next 60s tick. Public so the
	 * /events/retry endpoint can kick a re-drive right after reset_failed().
	 *
	 * @param array<int, array{0: string, 1: string}> $hook_groups [hook, group] pairs.
	 */
	public function schedule_flushes( array $hook_groups ): void {
		foreach ( $hook_groups as $pair ) {
			$this->maybe_schedule_flush( $pair[0], $pair[1] );
		}
	}

	/**
	 * Ensure an async flush is queued for the given endpoint hook. Deduplicated
	 * so multiple enqueues in one request collapse to a single AS row. The hook
	 * + group are passed in because the shared queue serves several endpoints
	 * (catalog, customers, …), each drained by its own flusher on its own hook.
	 */
	private function maybe_schedule_flush( string $flush_hook, string $flush_group ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( $flush_hook, array(), $flush_group ) !== false
		) {
			return;
		}

		as_enqueue_async_action( $flush_hook, array(), $flush_group );
	}

	/**
	 * UUID v4 for the wire idempotency key. Protected so tests can stub a
	 * deterministic value without mocking the global.
	 */
	protected function generate_uuid(): string {
		return wp_generate_uuid4();
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
