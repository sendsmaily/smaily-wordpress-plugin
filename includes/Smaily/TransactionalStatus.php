<?php
/**
 * What each transactional confirmation card tells the merchant (PRO-4091).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only admin-screen queries: table/column names come from $wpdb->prefix + class constants / OrderBackfillJob::table_spec(); values are prepare()d.

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
 *   - `lastSentAt` — the newest row Smaily accepted (UTC, the Event Log's
 *     format), '' when there is none. Sent rows are pruned after 30 days
 *     (QueueJanitor), so '' means "nothing in that window";
 *   - `untickedRecentStatuses` — shipping confirmation only, when nothing
 *     blocks it: the statuses recent orders reached when none of them is
 *     ticked under "Counts as shipped" (a store whose fulfilment uses a
 *     custom status). Empty = no warning.
 *
 * Not final: tests replace the two queries.
 */
class TransactionalStatus {

	/** How far back "recent" reaches, in days. */
	public const RECENT_DAYS = 30;

	/** At most this many of the newest orders are read — the lookup stays bounded on any store. */
	private const RECENT_ORDERS_LIMIT = 200;

	private TransactionalGate $gate;

	public function __construct( TransactionalGate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * @return array<string, array{reason: string, lastSentAt: string, untickedRecentStatuses: array<int, string>}>
	 */
	public function snapshot(): array {
		$out = array();

		foreach ( array_keys( TransactionalGate::TRIGGERS ) as $trigger_type ) {
			$reason = $this->gate->closed_reason( $trigger_type );

			$out[ $trigger_type ] = array(
				'reason'                 => $reason,
				'lastSentAt'             => $this->last_sent_at( TransactionalGate::event_type_for( $trigger_type ) ),
				'untickedRecentStatuses' => $trigger_type === TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION && $reason === ''
					? $this->unticked_recent_statuses()
					: array(),
			);
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private function unticked_recent_statuses(): array {
		$recent = $this->recent_order_statuses();

		if ( $recent === array() || array_intersect( $recent, TransactionalGate::shipped_statuses() ) !== array() ) {
			return array();
		}

		return $recent;
	}

	/**
	 * The newest row of $event_type that Smaily accepted. A skipped row
	 * (and any other terminal skip) is `sent` too, but has no sent_payload.
	 */
	protected function last_sent_at( string $event_type ): string {
		global $wpdb;

		$table = $wpdb->prefix . EventQueue::TABLE_SUFFIX;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( created_at ) FROM {$table} WHERE status = %s AND event_type = %s AND sent_payload IS NOT NULL AND sent_payload <> ''",
				EventQueue::STATUS_SENT,
				$event_type
			)
		);
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

		$hpos = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$spec = OrderBackfillJob::table_spec( $hpos, $wpdb->prefix );

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
			$statuses[ strpos( $status, 'wc-' ) === 0 ? substr( $status, 3 ) : $status ] = true;
		}

		return array_map( 'strval', array_keys( $statuses ) );
	}
}
