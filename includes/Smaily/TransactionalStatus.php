<?php
/**
 * What each transactional confirmation card tells the merchant (PRO-4091).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only admin-screen queries: table/column names come from $wpdb->prefix + class constants / OrderBackfillJob::table_spec(); values are prepare()d.

/**
 * The facts behind the status line on the order- and shipping-confirmation
 * cards. A support case showed why it is needed: a working shipping
 * confirmation looked broken, because these sends go through Smaily's
 * transactional sending — the Smaily workflow shows no runs — and every
 * reason the plugin did NOT send left no trace at all.
 *
 * Per trigger:
 *   - `reason` — TransactionalGate::closed_reason(), the send gate's own
 *     answer, so the card can never state a rule the send doesn't follow;
 *   - `workflowResolved` — TransactionalGate::workflow_resolved(): the
 *     gate got past the workflow lookup, so the card checks that the
 *     mapped workflow is active in Smaily (only the admin screen holds
 *     the workflow list);
 *   - `lastSentAt` — the newest row Smaily accepted (UTC, the Event Log's
 *     format), '' when there is none. Sent rows are pruned after 30 days
 *     (QueueJanitor), so '' means "nothing in that window";
 *   - `untickedRecentStatuses` — the shipping confirmation and the
 *     follow-up email (PRO-4095), when nothing blocks them: the statuses
 *     recent orders reached when none of them is ticked for that email
 *     (a store whose fulfilment uses a custom status). Empty = no warning.
 *
 * Not final: tests replace the two queries.
 */
class TransactionalStatus {

	/** How far back "recent" reaches, in days. */
	public const RECENT_DAYS = 30;

	/** At most this many of the newest orders are read — the lookup stays bounded on any store. */
	private const RECENT_ORDERS_LIMIT = 200;

	private TransactionalGate $gate;

	/**
	 * recent_order_statuses() for this snapshot — read once, shared by the cards.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $recent = null;

	public function __construct( TransactionalGate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * @return array<string, array{reason: string, workflowResolved: bool, lastSentAt: string, untickedRecentStatuses: array<int, string>}>
	 */
	public function snapshot(): array {
		$this->recent = null;
		$out          = array();
		$last_sent    = $this->last_sent_at( array_column( TransactionalGate::TRIGGERS, 'event_type' ) );

		foreach ( array_keys( TransactionalGate::TRIGGERS ) as $trigger_type ) {
			$reason = $this->gate->closed_reason( $trigger_type );

			$counting = TransactionalGate::counting_statuses( $trigger_type );

			$out[ $trigger_type ] = array(
				'reason'                 => $reason,
				'workflowResolved'       => TransactionalGate::workflow_resolved( $reason ),
				'lastSentAt'             => $last_sent[ TransactionalGate::event_type_for( $trigger_type ) ] ?? '',
				'untickedRecentStatuses' => $counting !== array() && $reason === ''
					? $this->unticked_recent_statuses( $counting )
					: array(),
			);
		}

		return $out;
	}

	/**
	 * @param array<int, string> $ticked The statuses the card's email counts.
	 *
	 * @return array<int, string>
	 */
	private function unticked_recent_statuses( array $ticked ): array {
		$this->recent ??= $this->recent_order_statuses();
		$recent         = $this->recent;

		if ( $recent === array() || array_intersect( $recent, $ticked ) !== array() ) {
			return array();
		}

		return $recent;
	}

	/**
	 * The newest row Smaily accepted, per event type — event types without
	 * one are absent. A skipped row (and any other terminal skip) is `sent`
	 * too, but has no sent_payload.
	 *
	 * One `LIMIT 1` query per event type (CON-170): with the
	 * `idx_status_created` index the database reads the sent rows newest
	 * first and stops at the type's newest send, so a settings load or save
	 * no longer aggregates every sent row of the queue.
	 *
	 * @param array<int, string> $event_types
	 * @return array<string, string> event type → UTC created_at
	 */
	protected function last_sent_at( array $event_types ): array {
		global $wpdb;

		$table = $wpdb->prefix . EventQueue::TABLE_SUFFIX;
		$out   = array();

		foreach ( array_unique( $event_types ) as $event_type ) {
			$last = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT created_at FROM {$table} WHERE status = %s AND event_type = %s AND sent_payload IS NOT NULL AND sent_payload <> '' ORDER BY created_at DESC LIMIT 1",
					EventQueue::STATUS_SENT,
					$event_type
				)
			);
			if ( is_string( $last ) && $last !== '' ) {
				$out[ $event_type ] = $last;
			}
		}

		return $out;
	}

	/**
	 * The distinct bare statuses (no `wc-`) of the newest orders changed in
	 * the last RECENT_DAYS days, from whichever order table is active —
	 * the same table/column mapping the order backfill uses. Only
	 * registered WooCommerce statuses count, so a checkout draft or a
	 * trashed order isn't reported as where orders go.
	 *
	 * @return array<int, string>
	 */
	protected function recent_order_statuses(): array {
		global $wpdb;

		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$spec = OrderBackfillJob::table_spec( OrderBackfillJob::uses_hpos(), $wpdb->prefix );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$spec['status_col']} AS status, {$spec['modified_col']} AS modified FROM {$spec['table']} WHERE {$spec['type_col']} = %s ORDER BY {$spec['id_col']} DESC LIMIT %d",
				'shop_order',
				self::RECENT_ORDERS_LIMIT
			),
			ARRAY_A
		);

		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( self::RECENT_DAYS * DAY_IN_SECONDS ) );
		$registered = wc_get_order_statuses();
		$statuses   = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( (string) ( $row['modified'] ?? '' ) < $cutoff || ! isset( $registered[ $status ] ) ) {
				continue;
			}
			$statuses[ OrderBackfillJob::bare_status( $status ) ] = true;
		}

		return array_map( 'strval', array_keys( $statuses ) );
	}
}
