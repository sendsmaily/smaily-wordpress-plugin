<?php
/**
 * Smaily-side durable event queue (backed by smly_plus_event_queue + AS).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

/**
 * Persistent queue for events headed to the Smaily marketing API:
 * contact.sync, automation.welcome, automation.first_order,
 * automation.abandoned_cart (PLUGIN.md §9).
 *
 * Storage layout: rows in {prefix}smly_plus_event_queue with status
 * pending → sent (terminal happy path) or pending → failed (terminal:
 * a refusal that can never succeed, or the retry ceiling reached — see
 * RetryPolicy). A retry parks the row with next_retry_at in the future;
 * pending() skips rows that aren't due yet (PRO-1685).
 *
 * Dispatch: enqueue() persists the row immediately and asks Action
 * Scheduler to fire smly_plus_flush_event_queue ASAP, deduplicated via
 * as_next_scheduled_action so multiple enqueues within one PHP request
 * collapse into a single flush job. The flush hook itself (which reads
 * pending rows, calls the appropriate API method, and updates status)
 * is registered by sub-PR 5 once the WC hook layer lands.
 *
 * This class deliberately does NOT call the Smaily API itself. That keeps
 * enqueue() cheap (it's invoked from hot paths like user_register and
 * woocommerce_checkout_order_processed) and concentrates retry policy in
 * one place — RetryPolicy, which the flush jobs apply.
 *
 * Not final: tests subclass with an anonymous double to record enqueue()
 * calls without standing up $wpdb + Action Scheduler. Same rationale as
 * Smaily\Client.
 */
class EventQueue {

	public const TABLE_SUFFIX = 'smly_plus_event_queue';

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';

	public const FLUSH_HOOK = 'smly_plus_flush_event_queue';
	public const AS_GROUP   = 'smaily-connect';

	/**
	 * Persist an event and ensure a flush is scheduled.
	 *
	 * @param string               $event_type e.g. "contact.sync", "automation.welcome".
	 * @param string               $entity_id  Free-form identifier (user_id, order_id, email).
	 * @param array<string, mixed> $payload    JSON-serialisable data the flush job will
	 *                                         hand off to the right API method.
	 *
	 * @return int|null Inserted row id on success, or null if the insert failed.
	 *                  Insert failures are intentionally silent — the caller is
	 *                  usually a hot WP hook and shouldn't bail because the
	 *                  queue is momentarily unreachable.
	 */
	public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
		global $wpdb;

		$json = wp_json_encode( $payload );
		if ( $json === false ) {
			return null;
		}

		$inserted = $wpdb->insert(
			$this->table_name(),
			array(
				'event_type' => $event_type,
				'entity_id'  => $entity_id,
				'payload'    => $json,
				'created_at' => current_time( 'mysql', true ),
				'attempts'   => 0,
				'status'     => self::STATUS_PENDING,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( $inserted !== 1 ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;
		$this->maybe_schedule_flush();

		return $id;
	}

	/**
	 * Fetch the next batch of due pending events for processing.
	 *
	 * "Due" = status pending AND (never retried OR next_retry_at has passed)
	 * — a row parked by record_attempt()'s backoff stays out of the drain
	 * until its wait has elapsed, so a repeatedly-failing row can neither be
	 * hammered every 60s nor hold a FIFO batch slot against fresher work
	 * (PRO-1685). The flush hook calls this, processes the rows, and uses
	 * mark_sent() / mark_failed() / record_attempt() to advance their state.
	 * Selecting in created_at order keeps the queue FIFO so hooks like
	 * contact.sync don't end up arbitrarily reordered relative to subsequent
	 * automation.* events for the same user.
	 *
	 * Event-type scoping (PRO-1195): the queue is drained by TWO flushers —
	 * the main Flusher (contact.sync + welcome/first_order automations) and
	 * the CartFlusher (`automation.abandoned_cart` on its own AS action).
	 * `$only_types` restricts a drain to its own rows; `$exclude_types` lets
	 * the main flusher skip rows another flusher owns. Same discipline as
	 * IngestQueue::pending()'s $event_types.
	 *
	 * @param array<int, string>|null $only_types    Restrict to these event types;
	 *                                               null/empty = no restriction.
	 * @param array<int, string>      $exclude_types Event types to skip.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pending( int $limit = 50, ?array $only_types = null, array $exclude_types = array() ): array {
		global $wpdb;

		$table = $this->table_name();

		$where = 'status = %s AND ( next_retry_at IS NULL OR next_retry_at <= %s )';
		$args  = array( self::STATUS_PENDING, current_time( 'mysql', true ) );

		if ( is_array( $only_types ) && $only_types !== array() ) {
			$placeholders = implode( ', ', array_fill( 0, count( $only_types ), '%s' ) );
			$where       .= " AND event_type IN ( {$placeholders} )";
			$args         = array_merge( $args, array_values( array_map( 'strval', $only_types ) ) );
		}

		if ( $exclude_types !== array() ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_types ), '%s' ) );
			$where       .= " AND event_type NOT IN ( {$placeholders} )";
			$args         = array_merge( $args, array_values( array_map( 'strval', $exclude_types ) ) );
		}

		$args[] = $limit;

		// Table name interpolation is unavoidable — MySQL forbids
		// parameterising the FROM clause. $table is controlled.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, entity_id, payload, created_at, attempts FROM {$table} WHERE {$where} ORDER BY created_at ASC LIMIT %d",
				...$args
			),
			ARRAY_A
		);
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
	 * Increment attempt counter, persist last error, and park the row for a
	 * future retry by stamping next_retry_at = now + $retry_in_seconds
	 * (computed in SQL against UTC_TIMESTAMP() so it stays timezone-correct
	 * without a PHP clock read — same mechanism as IngestQueue). Used by the
	 * flush jobs between retries; when attempts reaches the policy ceiling the
	 * caller flips the row to STATUS_FAILED via mark_failed() instead —
	 * RetryPolicy::apply() makes that choice.
	 *
	 * The backoff is opt-in (default 0 = due again immediately) so a caller
	 * that bounds its retries some other way keeps the behaviour it was
	 * designed around — TransactionalFlusher retries on every tick and stops
	 * on elapsed time instead (PRO-1519), because a pending transactional row
	 * suppresses the customer's native WooCommerce email while it waits.
	 */
	public function record_attempt( int $id, string $error, int $retry_in_seconds = 0 ): void {
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
	 * Persist the send-time exchange for a row: the exact request body sent
	 * (`sent_payload`, null when nothing was sent) and a small JSON summary of
	 * the Smaily reply (`last_response`). Written by the Flusher after dispatch
	 * so the Event Log "Details" shows what we sent and what came back (F3-44).
	 * NEVER carries the Authorization header.
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
	 * Revive terminally-`failed` rows back to `pending` so the recurring flush
	 * re-attempts them (3.10.1 Event Log recovery). Resets the attempt counter +
	 * clears the retry-park + last_error, so a row that hit the RetryPolicy
	 * ceiling (or a refusal classified permanent) starts fresh and is due
	 * immediately. `$ids` null = every failed row; otherwise only the given ids.
	 * Manual-only by design (a deterministic failure would loop under auto-retry).
	 * Returns the row count.
	 *
	 * @param int[]|null $ids
	 */
	public function reset_failed( ?array $ids = null ): int {
		global $wpdb;
		$table = $this->table_name();

		$set = 'SET status = %s, attempts = 0, last_error = NULL, next_retry_at = NULL';

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

	/** Public kick so /events/retry can re-drive promptly after reset_failed(). */
	public function schedule_flush(): void {
		$this->maybe_schedule_flush();
	}

	/**
	 * Ensure an async flush is queued. Deduplicated so multiple enqueues
	 * in one request collapse to a single AS row.
	 */
	private function maybe_schedule_flush(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( self::FLUSH_HOOK, array(), self::AS_GROUP ) !== false
		) {
			return;
		}

		as_enqueue_async_action( self::FLUSH_HOOK, array(), self::AS_GROUP );
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
