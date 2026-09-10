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
 * Dispatch: enqueue() persists the row immediately and makes sure a
 * smly_plus_flush_event_queue pass is queued, deduplicated via
 * as_next_scheduled_action against whatever is already scheduled on that
 * hook — and the flusher's recurring action always is, so the row goes out
 * on the NEXT SCHEDULED PASS (PRO-2323 wording), not on an enqueue-time
 * run-now. The flush hook itself (which reads pending rows, calls the
 * appropriate API method, and updates status) is registered by sub-PR 5
 * once the WC hook layer lands.
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

	/**
	 * The statuses a row can still SEND from: pending, plus failed — which the
	 * Event Log's Retry revives. Only `sent` is truly over, and that is the
	 * line the Art 17 erasure's delete/redact split turns on (PRO-2383).
	 *
	 * @var string[]
	 */
	public const STATUSES_SENDABLE = array( self::STATUS_PENDING, self::STATUS_FAILED );

	public const FLUSH_HOOK = 'smly_plus_flush_event_queue';
	public const AS_GROUP   = 'smaily-connect';

	/**
	 * The `last_response` outcome a withdrawn row carries. The Event Log list
	 * reads it back to label the row cancelled (PRO-2372) — it is what tells a
	 * withdrawal apart from the flushers' other terminal skips, which record
	 * `skipped` and really are ordinary "nothing to send" rows.
	 */
	public const OUTCOME_CANCELLED = 'cancelled';

	/** The outcome a flusher records for an ordinary "nothing to send" terminal skip. */
	public const OUTCOME_SKIPPED = 'skipped';

	/** Why a row was withdrawn — the note the Event Log shows on a cancelled row. */
	private const NOTE_CANCELLED = 'the shopper completed a purchase before the reminder was sent';

	/**
	 * What a redacted field carries after an Art 17 erasure (PRO-2383). A
	 * fixed non-address string, so nothing downstream can read a recipient
	 * back out of a row the subject asked us to forget.
	 */
	public const ERASED_PLACEHOLDER = '[erased]';

	/**
	 * Keys whose values survive redaction: routing/diagnostic scalars that
	 * describe the SEND, never the person — the stored exchange's `http` /
	 * `outcome` / `note` (the Event Log labels a cancelled row from them,
	 * PRO-2372), and a transactional row's `workflow_id` / `account_key` /
	 * `to_status` (config ids and a WC status slug, which the "Send again"
	 * guard reads back). Everything else in the JSON is redacted, whatever
	 * its key — an allowlist, so a payload field added later cannot leak by
	 * simply not being on a denylist.
	 *
	 * @var string[]
	 */
	private const REDACTION_KEEP_KEYS = array(
		'http',
		'outcome',
		'note',
		'workflow_id',
		'account_key',
		'to_status',
	);

	/**
	 * Persist an event and ensure a flush is scheduled.
	 *
	 * @param string               $event_type e.g. "contact.sync", "automation.welcome".
	 * @param string               $entity_id  Free-form identifier (user_id, order_id, email).
	 * @param array<string, mixed> $payload    JSON-serialisable data the flush job will
	 *                                         hand off to the right API method. Its
	 *                                         `email`, when it has one, is stamped
	 *                                         onto the row as contact_key().
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

		$email = isset( $payload['email'] ) && is_string( $payload['email'] ) ? $payload['email'] : '';

		$inserted = $wpdb->insert(
			$this->table_name(),
			array(
				'event_type'  => $event_type,
				'entity_id'   => $entity_id,
				'payload'     => $json,
				'contact_key' => $email === '' ? null : self::contact_key( $email ),
				'created_at'  => current_time( 'mysql', true ),
				'attempts'    => 0,
				'status'      => self::STATUS_PENDING,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
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

	/**
	 * Withdraw every still-pending row of this event type addressed to this
	 * contact, and report whether one of their rows was ever actually
	 * DELIVERED (PRO-1723). The checkout path asks both questions about the
	 * same shopper, so they are one read of that contact's rows: a reminder
	 * must not go out behind a purchase already completed, and the purchase
	 * is marked on the contact only when a reminder really did go out.
	 *
	 * "Delivered" is stricter than `sent`: the terminal skips (no workflow
	 * mapped, missing email) also end as `sent`, and they POSTed nothing —
	 * they are told apart by `sent_payload`, which the flushers write only
	 * for a row that really reached Smaily (F3-44). A row withdrawn here
	 * takes that same skip shape, so it never reads as delivered.
	 *
	 * Rows are found by contact_key(), the indexed hash of the address
	 * (migration 011) — the queue does not search its own payload text and
	 * knows nothing about the JSON's shape. Rows enqueued before migration
	 * 011 carry no key and are invisible here.
	 *
	 * Bounded by the QueueJanitor's retention, deliberately: as long as the
	 * row that proves the send is still here, the shopper counts as reminded.
	 *
	 * @return bool True when a row of this type was delivered to this contact.
	 */
	public function withdraw_pending_for( string $event_type, string $email ): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, sent_payload FROM {$table} WHERE event_type = %s AND contact_key = %s",
				$event_type,
				self::contact_key( $email )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$delivered = false;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = (string) ( $row['status'] ?? '' );

			if ( $status === self::STATUS_SENT ) {
				$delivered = $delivered || (string) ( $row['sent_payload'] ?? '' ) !== '';
				continue;
			}

			if ( $status === self::STATUS_PENDING ) {
				$this->cancel( (int) $row['id'] );
			}
		}

		return $delivered;
	}

	/**
	 * The row key for a contact: a sha256 of the normalised address. A HASH,
	 * never the address itself — the queue keeps no second copy of a contact's
	 * email beyond the payload it already stores, and this is what the
	 * checkout path looks rows up by. Trimmed + lowercased so an address typed
	 * differently at checkout still finds the row it was queued under, which
	 * is what the old payload search got from the column's collation.
	 */
	public static function contact_key( string $email ): string {
		return hash( 'sha256', strtolower( trim( $email ) ) );
	}

	/**
	 * This contact's queue rows, for the WP Privacy exporter (Art 15,
	 * PRO-2383). Projection is deliberately narrow — what happened and when,
	 * never the stored payload: the queue row is a record that the plugin
	 * queued a message for this address, and that is the subject-access fact.
	 *
	 * @return array<int, array<string, mixed>> id, event_type, created_at.
	 */
	public function rows_for_privacy_request( string $email ): array {
		global $wpdb;

		$where = $this->privacy_request_where( $email );
		if ( $where === null ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, created_at FROM {$table} WHERE {$where[0]} ORDER BY created_at ASC, id ASC",
				$where[1]
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Erase this contact from the queue (Art 17, PRO-2383) — the asymmetric
	 * pair Erkki chose: a row that could still SEND is deleted, a row that is
	 * already `sent` is redacted in place.
	 *
	 * Deleting the sendable rows is the point of the erasure — a queued
	 * message must never leave for an address the subject asked us to forget,
	 * and a `failed` row is revivable by the Event Log's Retry, so it counts
	 * as sendable too (only `sent` is truly over). Redacting rather than
	 * deleting the rest keeps the Event Log's history of what the store did:
	 * the row keeps its event_type, timestamps and status, and loses its
	 * payload values, its stored exchange (F3-44 — `sent_payload` is the
	 * literal body POSTed to Smaily, address included) and its contact_key.
	 *
	 * Redaction is idempotent-by-construction: a redacted row no longer
	 * carries the key or the address it was matched on, so a second run
	 * finds nothing.
	 *
	 * @return array{removed: int, redacted: int}
	 */
	public function erase_for_privacy_request( string $email ): array {
		global $wpdb;

		$result = array(
			'removed'  => 0,
			'redacted' => 0,
		);

		$where = $this->privacy_request_where( $email );
		if ( $where === null ) {
			return $result;
		}

		$table    = $this->table_name();
		$sendable = implode( ', ', array_fill( 0, count( self::STATUSES_SENDABLE ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$result['removed'] = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ( {$sendable} ) AND {$where[0]}",
				array_merge( self::STATUSES_SENDABLE, $where[1] )
			)
		);

		/** @var array<int, array{id: int|string, payload: ?string, sent_payload: ?string, last_response: ?string}>|null $rows */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload, sent_payload, last_response FROM {$table} WHERE status = %s AND {$where[0]}",
				array_merge( array( self::STATUS_SENT ), $where[1] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$wpdb->update(
				$table,
				array(
					'payload'       => (string) self::redact_json( $row['payload'] ),
					'sent_payload'  => self::redact_json( $row['sent_payload'] ),
					'last_response' => self::redact_json( $row['last_response'] ),
					'contact_key'   => null,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			++$result['redacted'];
		}

		return $result;
	}

	/**
	 * Replace every value in a stored JSON blob with ERASED_PLACEHOLDER,
	 * keeping the KEYS and the structure (so the Event Log still shows the
	 * shape of what went out) and the REDACTION_KEEP_KEYS scalars.
	 *
	 * A blob that isn't decodable JSON is replaced wholesale — it may be
	 * anything, so nothing in it can be assumed impersonal.
	 */
	public static function redact_json( ?string $json ): ?string {
		if ( $json === null || $json === '' ) {
			return $json;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return self::ERASED_PLACEHOLDER;
		}

		$encoded = wp_json_encode( self::redact_value( $decoded ) );

		return $encoded === false ? self::ERASED_PLACEHOLDER : $encoded;
	}

	/**
	 * @param array<array-key, mixed> $value
	 *
	 * @return array<array-key, mixed>
	 */
	private static function redact_value( array $value ): array {
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$out[ $key ] = self::redact_value( $item );
				continue;
			}
			$out[ $key ] = in_array( $key, self::REDACTION_KEEP_KEYS, true )
				? $item
				: self::ERASED_PLACEHOLDER;
		}

		return $out;
	}

	/**
	 * The match condition both privacy-request methods share, so the export
	 * and the erasure can never disagree on what counts as this subject's
	 * rows (same discipline as CartSessionStore::privacy_request_where()).
	 *
	 * `contact_key` (migration 011) is the indexed answer, but it only exists
	 * for rows whose payload carries an `email` — rows enqueued before that
	 * migration have none, and a transactional row never does (its recipient
	 * is `to`). Those fall back to a payload text match on the two recipient
	 * keys, case-insensitively via the column's collation — unindexable, and
	 * that is why the checkout path refuses it (PRO-1723), but an erasure
	 * request is an admin-triggered one-off where completeness beats speed.
	 *
	 * @return array{0: string, 1: array<int, string>}|null
	 */
	private function privacy_request_where( string $email ): ?array {
		global $wpdb;

		$email = trim( $email );
		if ( $email === '' ) {
			return null;
		}

		return array(
			'( contact_key = %s OR ( contact_key IS NULL AND ( payload LIKE %s OR payload LIKE %s ) ) )',
			array(
				self::contact_key( $email ),
				'%' . $wpdb->esc_like( '"email":"' . $email . '"' ) . '%',
				'%' . $wpdb->esc_like( '"to":"' . $email . '"' ) . '%',
			),
		);
	}

	/**
	 * Terminally withdraw one row, through the established terminal-skip pair
	 * (mark_sent + a skip exchange, exactly as a flusher records one): the
	 * Event Log shows the `cancelled` outcome, nothing is retried, and with
	 * no sent_payload the row never counts as delivered.
	 */
	private function cancel( int $id ): void {
		$this->mark_sent( $id );
		$this->store_exchange(
			$id,
			null,
			(string) wp_json_encode(
				array(
					'outcome' => self::OUTCOME_CANCELLED,
					'note'    => self::NOTE_CANCELLED,
				)
			)
		);
	}

	/**
	 * Whether a stored `last_response` records a withdrawal rather than a send
	 * (PRO-2372). The one place that knows the shape cancel() writes, so the
	 * Event Log's read model doesn't have to.
	 */
	public static function is_cancelled_response( string $last_response ): bool {
		$decoded = json_decode( $last_response, true );

		return is_array( $decoded ) && ( $decoded['outcome'] ?? '' ) === self::OUTCOME_CANCELLED;
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
	 * `$exclude_event_types` holds event types a bulk revive must leave alone
	 * — the transactional ones, whose retry the guard decides row by row
	 * (PRO-1733); without it "Retry all failed" would revive exactly the rows
	 * the single-row route turns down, so the caller resets the few it may.
	 * Manual-only by design (a deterministic failure would loop under auto-retry).
	 * Returns the row count.
	 *
	 * @param int[]|null $ids
	 * @param string[]   $exclude_event_types
	 */
	public function reset_failed( ?array $ids = null, array $exclude_event_types = array() ): int {
		global $wpdb;
		$table = $this->table_name();

		$set = 'SET status = %s, attempts = 0, last_error = NULL, next_retry_at = NULL';

		$exclude_sql = '';
		if ( $exclude_event_types !== array() ) {
			$exclude_sql = ' AND event_type NOT IN ( ' . implode( ', ', array_fill( 0, count( $exclude_event_types ), '%s' ) ) . ' )';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $ids === null ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table} {$set} WHERE status = %s{$exclude_sql}",
				array_merge( array( self::STATUS_PENDING, self::STATUS_FAILED ), $exclude_event_types )
			);
		} else {
			$ids = self::clean_ids( $ids );
			if ( $ids === array() ) {
				return 0;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = $wpdb->prepare(
				"UPDATE {$table} {$set} WHERE status = %s AND id IN ( {$placeholders} ){$exclude_sql}",
				array_merge( array( self::STATUS_PENDING, self::STATUS_FAILED ), $ids, $exclude_event_types )
			);
		}

		return (int) $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Re-date the given rows' created_at to now (PRO-1733). The transactional
	 * flusher's one-hour ceiling (PRO-1519) is measured from created_at, so a
	 * revived transactional row would otherwise terminal-fail on the very next
	 * tick — the retry has to give it a fresh hour. Deliberately narrow: only
	 * /events/retry calls it, and only for the transactional rows it revived.
	 * Returns the row count.
	 *
	 * @param int[] $ids
	 */
	public function restart_age( array $ids ): int {
		global $wpdb;

		$ids = self::clean_ids( $ids );
		if ( $ids === array() ) {
			return 0;
		}

		$table        = $this->table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET created_at = %s WHERE id IN ( {$placeholders} )",
				array_merge( array( current_time( 'mysql', true ) ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int[]
	 */
	public static function clean_ids( array $ids ): array {
		return array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $i ): bool => $i > 0 ) );
	}

	/**
	 * Public entry point so /events/retry can make sure a flush pass is queued
	 * after reset_failed(). Deduplicated like every other caller, so the rows
	 * go out on the flusher's next scheduled pass (PRO-2323).
	 */
	public function schedule_flush(): void {
		$this->maybe_schedule_flush();
	}

	/**
	 * Ensure a flush pass is queued. Deduplicated so multiple enqueues in one
	 * request collapse to a single AS row — and because the flusher's
	 * recurring action is always scheduled, in practice this is a no-op and
	 * the rows go out at the next scheduled pass (PRO-2323). It is the safety
	 * net for a store whose recurring action has gone missing, not a run-now
	 * kick.
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
