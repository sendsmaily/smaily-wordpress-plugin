<?php
/**
 * Backfill existing WooCommerce orders (in a sale state) into the rec-engine.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Automattic\WooCommerce\Utilities\OrderUtil;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

/**
 * Two things make orders different from catalog/customers:
 *
 *   1. HPOS. Orders live in `wp_posts` (legacy) OR the `wc_orders` custom table
 *      (HPOS), depending on the store's order-storage mode. `wc_get_orders()`
 *      is storage-agnostic but offers only offset/paged pagination, which
 *      SHIFTS under concurrent inserts and would break the resumable cursor —
 *      so this enumerates with a direct `WHERE id > cursor` query against
 *      whichever table is active (detected via OrderUtil, like EnvDetector).
 *      Pilot stores (WC 6.9.4) are legacy; the HPOS path is forward-compat.
 *
 *   2. Status filter. The live OrderHookHandler enqueues every status EXCEPT the
 *      non-sale ones (map_status() returns '' only for NON_SALE_STATUSES; custom
 *      statuses default THROUGH as a sale — DECISIONS F3-42). The backfill
 *      enumerates the SAME cohort: it filters `status NOT IN (non-sale)` at the
 *      SQL level, using OrderPayloadBuilder::non_sale_wc_statuses() as the single
 *      source so the denylist can't drift from map_status(). The flusher's own
 *      map_status==='' skip stays as the safety net for any non-sale status the
 *      SQL prefixing doesn't catch.
 */
class OrderBackfillJob extends AbstractBackfillJob {

	public function __construct( IngestQueue $queue, OrderFlusher $flusher ) {
		parent::__construct( $queue, $flusher );
	}

	public function job_type(): string {
		return 'orders';
	}

	protected function batch_size(): int {
		return 50; // Orders carry nested line items — the engine's lower cap.
	}

	protected function count_total(): int {
		global $wpdb;
		$spec         = self::table_spec( $this->is_hpos(), $wpdb->prefix );
		$statuses     = $this->non_sale_status_slugs();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql  = "SELECT COUNT(*) FROM {$spec['table']} WHERE {$spec['type_col']} = %s AND {$spec['status_col']} NOT IN ( {$placeholders} )";
		$args = array_merge( array( 'shop_order' ), $statuses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return int[]
	 */
	protected function fetch_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		$spec         = self::table_spec( $this->is_hpos(), $wpdb->prefix );
		$statuses     = $this->non_sale_status_slugs();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql  = "SELECT {$spec['id_col']} FROM {$spec['table']} WHERE {$spec['type_col']} = %s AND {$spec['status_col']} NOT IN ( {$placeholders} ) AND {$spec['id_col']} > %d ORDER BY {$spec['id_col']} ASC LIMIT %d";
		$args = array_merge( array( 'shop_order' ), $statuses, array( $after_id, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * The order table + its id/type/status columns for the active storage mode.
	 * PURE (no DB) so the HPOS path is unit-testable without a wc_orders table —
	 * the pilot runs legacy, so the HPOS path is forward-compat and verified by
	 * its column mapping here, not by an integration query (STATUS/CLAUDE).
	 *
	 * @return array{table: string, id_col: string, type_col: string, status_col: string}
	 */
	public static function table_spec( bool $hpos, string $prefix ): array {
		if ( $hpos ) {
			return array(
				'table'      => $prefix . 'wc_orders',
				'id_col'     => 'id',
				'type_col'   => 'type',
				'status_col' => 'status',
			);
		}
		return array(
			'table'      => $prefix . 'posts',
			'id_col'     => 'ID',
			'type_col'   => 'post_type',
			'status_col' => 'post_status',
		);
	}

	protected function enqueue_record( int $entity_id ): void {
		$this->queue->enqueue(
			OrderFlusher::EVENT_ORDER_UPSERT,
			(string) $entity_id,
			array(),
			null,
			OrderFlusher::FLUSH_HOOK,
			OrderFlusher::AS_GROUP
		);
	}

	/**
	 * Whether the store uses HPOS (the wc_orders custom table). Mirrors
	 * EnvDetector — the OrderUtil helper handles the "enabled but not yet
	 * synced" transition correctly.
	 */
	protected function is_hpos(): bool {
		return class_exists( OrderUtil::class )
			&& OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The stored (post_status / wc_orders.status) forms of the NON-sale statuses
	 * for the SQL `NOT IN` filter, derived from the single source
	 * (OrderPayloadBuilder::non_sale_wc_statuses). WC order statuses are stored
	 * WITH the `wc-` prefix; WP core post statuses (draft / auto-draft / trash)
	 * are stored WITHOUT it — prefix only the WC ones so both forms match.
	 *
	 * @return string[]
	 */
	private function non_sale_status_slugs(): array {
		$wp_core = array( 'draft', 'auto-draft', 'trash' );
		return array_map(
			static function ( string $status ) use ( $wp_core ): string {
				return in_array( $status, $wp_core, true ) ? $status : 'wc-' . $status;
			},
			OrderPayloadBuilder::non_sale_wc_statuses()
		);
	}
}
