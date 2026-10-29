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
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

/**
 * Three things make orders different from catalog/customers:
 *
 *   1. HPOS. Orders live in `wp_posts` (legacy) OR the `wc_orders` custom table
 *      (HPOS), depending on the store's order-storage mode. `wc_get_orders()`
 *      is storage-agnostic but offers only offset/paged pagination, which
 *      SHIFTS under concurrent inserts and would break the resumable cursor —
 *      so this enumerates with a direct keyset query against whichever table
 *      is active (table_spec(), detected via OrderUtil, like EnvDetector).
 *
 *   2. Status filter. The live OrderHookHandler enqueues every status EXCEPT the
 *      non-sale ones (map_status() returns '' only for NON_SALE_STATUSES; custom
 *      statuses default THROUGH as a sale — DECISIONS F3-42). The backfill
 *      enumerates the SAME cohort: it filters `status NOT IN (non-sale)` at the
 *      SQL level, using OrderPayloadBuilder::non_sale_wc_statuses() as the single
 *      source so the denylist can't drift from map_status(). The flusher's own
 *      map_status==='' skip stays as the safety net for any non-sale status the
 *      SQL prefixing doesn't catch.
 *
 *   3. History window (contract v1.20.0 §5, §25 — the Magento extension's
 *      rule, CON-245). start() reads the engine's `config.history`
 *      (HistoryConfig) and fixes the window: `until` = now (an order placed
 *      later goes through the live sync), `start_from` = start_months back,
 *      `horizon` = horizon_months back. Orders go newest first by order date
 *      (table_spec()'s `created_col`, then id): the start part down to
 *      `start_from`, then the background part down to `horizon`; no older
 *      order is read. The window and the `date|id` position of the last order
 *      read are the job's cursor (JSON in `cursor_value`), so a resume goes on
 *      at the next order. When the start part is read, `start_part_queued_to`
 *      keeps the newest order.upsert queue row id, for the history progress
 *      report.
 */
class OrderBackfillJob extends AbstractBackfillJob {

	private HistoryConfig $history;

	/**
	 * The window initial_cursor() fixed, for count_total() in the same start().
	 *
	 * @var array{until: string, start_from: string, horizon: string, after: string, start_part_queued_to: int|null}|null
	 */
	private ?array $window = null;

	public function __construct( IngestQueue $queue, OrderFlusher $flusher, HistoryConfig $history ) {
		parent::__construct( $queue, $flusher );
		$this->history = $history;
	}

	public function job_type(): string {
		return 'orders';
	}

	protected function batch_size(): int {
		return 50; // Orders carry nested line items — the engine's lower cap.
	}

	protected function initial_cursor(): ?string {
		$this->window = $this->new_window();
		return (string) wp_json_encode( $this->window );
	}

	/** The sale orders inside the window — the orders the walk reads. */
	protected function count_total(): int {
		global $wpdb;
		$window               = $this->window ?? $this->new_window();
		$spec                 = self::table_spec( self::uses_hpos(), $wpdb->prefix );
		list( $where, $args ) = $this->sale_orders_where( $spec, $window['horizon'], $window['until'] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$spec['table']} WHERE {$where}", $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The start part first, then the background part, newest first. A cursor
	 * of an earlier version (a bare order id) gets a window now and starts
	 * from the newest order again; the engine updates an order it already has.
	 *
	 * @return array{ids: int[], cursor: string, completed: bool}
	 */
	protected function next_page( string $cursor, int $limit ): array {
		$window = self::decode_window( $cursor ) ?? $this->new_window();

		if ( $window['start_part_queued_to'] === null ) {
			$rows = $this->page( $window['start_from'], $window, $limit );
			if ( $rows !== array() ) {
				return self::after_page( $window, $rows, false );
			}
			$window['start_part_queued_to'] = $this->queue->last_id( OrderFlusher::EVENT_ORDER_UPSERT );
		}

		$rows = $this->page( $window['horizon'], $window, $limit );
		return self::after_page( $window, $rows, count( $rows ) < $limit );
	}

	/**
	 * The window of an import that starts at $now (UTC). A day the earlier
	 * month lacks rolls forward (31 August − 6 months is 3 March).
	 *
	 * @param array{start_months: int, horizon_months: int, order: string} $history
	 * @return array{until: string, start_from: string, horizon: string, after: string, start_part_queued_to: int|null}
	 */
	public static function window( array $history, int $now ): array {
		$at = new \DateTimeImmutable( '@' . $now );
		return array(
			'until'                => $at->format( 'Y-m-d H:i:s' ),
			'start_from'           => $at->sub( new \DateInterval( 'P' . $history['start_months'] . 'M' ) )->format( 'Y-m-d H:i:s' ),
			'horizon'              => $at->sub( new \DateInterval( 'P' . $history['horizon_months'] . 'M' ) )->format( 'Y-m-d H:i:s' ),
			'after'                => '',
			'start_part_queued_to' => null,
		);
	}

	/**
	 * The order table + its id/type/status columns for the active storage mode.
	 * PURE (no DB) so both mappings are unit-pinned; CI also runs the
	 * integration suite on both storages (PRO-3435).
	 *
	 * `modified_col` is the order's last-changed time in UTC — read by the
	 * transactional status line's recent-orders lookup (PRO-4091).
	 * `created_col` is the order date in UTC — the order the import walks in
	 * and its history window (CON-245).
	 *
	 * @return array{table: string, id_col: string, type_col: string, status_col: string, modified_col: string, created_col: string}
	 */
	public static function table_spec( bool $hpos, string $prefix ): array {
		if ( $hpos ) {
			return array(
				'table'        => $prefix . 'wc_orders',
				'id_col'       => 'id',
				'type_col'     => 'type',
				'status_col'   => 'status',
				'modified_col' => 'date_updated_gmt',
				'created_col'  => 'date_created_gmt',
			);
		}
		return array(
			'table'        => $prefix . 'posts',
			'id_col'       => 'ID',
			'type_col'     => 'post_type',
			'status_col'   => 'post_status',
			'modified_col' => 'post_modified_gmt',
			'created_col'  => 'post_date_gmt',
		);
	}

	/**
	 * Whether the store uses HPOS (the wc_orders custom table) — the `$hpos`
	 * argument table_spec() takes. The OrderUtil helper handles the "enabled
	 * but not yet synced" transition correctly.
	 */
	public static function uses_hpos(): bool {
		return class_exists( OrderUtil::class )
			&& OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * A WC order status without its stored 'wc-' prefix ('wc-completed' →
	 * 'completed'); a status without the prefix comes back unchanged.
	 */
	public static function bare_status( string $status ): string {
		return strpos( $status, 'wc-' ) === 0 ? substr( $status, 3 ) : $status;
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
	 * @return array{until: string, start_from: string, horizon: string, after: string, start_part_queued_to: int|null}
	 */
	private function new_window(): array {
		return self::window( $this->history->for_new_import(), time() );
	}

	/**
	 * A cursor this class wrote, or null.
	 *
	 * @return array{until: string, start_from: string, horizon: string, after: string, start_part_queued_to: int|null}|null
	 */
	private static function decode_window( string $cursor ): ?array {
		$window = json_decode( $cursor, true );
		if ( ! is_array( $window ) ) {
			return null;
		}
		foreach ( array( 'until', 'start_from', 'horizon', 'after' ) as $key ) {
			if ( ! is_string( $window[ $key ] ?? null ) ) {
				return null;
			}
		}
		$queued_to = $window['start_part_queued_to'] ?? null;

		return array(
			'until'                => $window['until'],
			'start_from'           => $window['start_from'],
			'horizon'              => $window['horizon'],
			'after'                => $window['after'],
			'start_part_queued_to' => is_int( $queued_to ) ? $queued_to : null,
		);
	}

	/**
	 * The page's ids, and the cursor at its last order.
	 *
	 * @param array{until: string, start_from: string, horizon: string, after: string, start_part_queued_to: int|null} $window
	 * @param array<int, array{id: string, created: string}>                                                           $rows
	 * @return array{ids: int[], cursor: string, completed: bool}
	 */
	private static function after_page( array $window, array $rows, bool $completed ): array {
		$last = end( $rows );
		if ( $last !== false ) {
			$window['after'] = $last['created'] . '|' . $last['id'];
		}

		return array(
			'ids'       => array_map( 'intval', array_column( $rows, 'id' ) ),
			'cursor'    => (string) wp_json_encode( $window ),
			'completed' => $completed,
		);
	}

	/**
	 * Up to $limit sale orders placed from $from to the window's `until` and
	 * after its cursor position (`date|id`), newest first.
	 *
	 * @param array{until: string, start_from: string, horizon: string, after: string, start_part_queued_to: int|null} $window
	 * @return array<int, array{id: string, created: string}>
	 */
	private function page( string $from, array $window, int $limit ): array {
		global $wpdb;
		$spec                 = self::table_spec( self::uses_hpos(), $wpdb->prefix );
		list( $where, $args ) = $this->sale_orders_where( $spec, $from, $window['until'] );

		$position = explode( '|', $window['after'] );
		if ( count( $position ) === 2 ) {
			$where .= " AND ( {$spec['created_col']} < %s OR ( {$spec['created_col']} = %s AND {$spec['id_col']} < %d ) )";
			$args   = array_merge( $args, array( $position[0], $position[0], (int) $position[1] ) );
		}
		$args[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$spec['id_col']} AS id, {$spec['created_col']} AS created FROM {$spec['table']} WHERE {$where} ORDER BY {$spec['created_col']} DESC, {$spec['id_col']} DESC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The WHERE clause of the sale orders placed from $from to $until, and its
	 * arguments.
	 *
	 * @param array{table: string, id_col: string, type_col: string, status_col: string, modified_col: string, created_col: string} $spec
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	private function sale_orders_where( array $spec, string $from, string $until ): array {
		$statuses     = $this->non_sale_status_slugs();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		return array(
			"{$spec['type_col']} = %s AND {$spec['status_col']} NOT IN ( {$placeholders} ) AND {$spec['created_col']} >= %s AND {$spec['created_col']} <= %s",
			array_merge( array( 'shop_order' ), $statuses, array( $from, $until ) ),
		);
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
