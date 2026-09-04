<?php
/**
 * REST endpoint for the Event Log (PLUGIN.md §13) — a read-only diagnostic view
 * over BOTH durable queues so a pilot operator can answer "did X sync? why not?"
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Smaily\Connect\Constants;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Two sub-routes under `/wp-json/smaily-connect/v1/events/...`:
 *
 *   GET /events          query: ?page=1&per_page=50&source=&status=&type=
 *                        → { events: [...], total, page, per_page, failed_24h }
 *
 *   GET /events/detail   query: ?source=rec_engine|smaily&id=123
 *                        → { ...row, payload }   (the full payload for drill-down)
 *
 * This is the visibility half of the 3.10 pilot-hardening work (Layer 1). It is
 * strictly READ-ONLY — recovery (reset-failed / retry) is 3.10.1. The data is a
 * UNION over `smly_rec_event_queue` (source=rec_engine) and
 * `smly_plus_event_queue` (source=smaily); both already carry every column the
 * §13 view needs (status / attempts / last_error / created_at), so there is no
 * schema change. `max_attempts` only exists on the rec queue — the Smaily queue
 * projects NULL for it.
 *
 * Auth: manage_options on both routes (WP cookie-nonce handles CSRF).
 */
class EventsEndpoint {

	public const ROUTE_PREFIX = '/events';

	private const DEFAULT_PER_PAGE = 50;
	private const MAX_PER_PAGE     = 200;

	/** Sources the UNION exposes; the value doubles as the wire `source`. */
	private const SOURCE_REC    = 'rec_engine';
	private const SOURCE_SMAILY = 'smaily';

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/detail',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'detail' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// Recovery (3.10.1): re-drive failed rows. Write route — manage_options +
		// the WP cookie-nonce gate the same as every other admin POST here.
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'You do not have permission to view the event log.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function list_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( self::MAX_PER_PAGE, $per_page ) : self::DEFAULT_PER_PAGE;
		$offset   = ( $page - 1 ) * $per_page;

		$source = $this->sanitize_source( (string) $request->get_param( 'source' ) );
		$status = $this->sanitize_token( (string) $request->get_param( 'status' ) );
		$type   = $this->sanitize_token( (string) $request->get_param( 'type' ) );

		[ $union, $params ] = $this->build_union( $source, $status, $type );

		// The union carries %s placeholders only when status/type filters are set;
		// with no filters it's just trusted table names + escaped source literals,
		// so prepare() with an empty arg list (which WP warns on + can null out) is
		// avoided by running the COUNT directly in that case.
		$count_sql = "SELECT COUNT(*) FROM ( {$union} ) AS e";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) ( $params === array()
			? $wpdb->get_var( $count_sql )
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );

		// The list query always carries LIMIT/OFFSET placeholders, so prepare()
		// is always valid here regardless of filters.
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM ( {$union} ) AS e ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$list_params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response(
			array(
				'events'     => array_map( array( $this, 'shape_row' ), is_array( $rows ) ? $rows : array() ),
				'total'      => $total,
				'page'       => $page,
				'per_page'   => $per_page,
				'failed_24h' => $this->failed_last_24h(),
			),
			200
		);
	}

	/**
	 * Full payload for a single row (drill-down). Source + id select the table.
	 */
	public function detail( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$source = $this->sanitize_source( (string) $request->get_param( 'source' ) );
		$id     = (int) $request->get_param( 'id' );

		if ( $id <= 0 || ( $source !== self::SOURCE_REC && $source !== self::SOURCE_SMAILY ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_event_ref' ), 400 );
		}

		$table = $source === self::SOURCE_REC ? $this->rec_table() : $this->smaily_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $row ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$row['source'] = $source;

		return new WP_REST_Response(
			array(
				'event'         => $this->shape_row( $row ),
				'payload'       => isset( $row['payload'] ) ? (string) $row['payload'] : '',
				// The send-time exchange (F3-44): exactly what was POSTed + the
				// engine reply. Empty for rows enqueued before this shipped, or
				// not-yet-flushed rows. sent_payload is null/empty when nothing
				// was POSTed (a terminal skip) — see last_response.outcome.
				'sent_payload'  => isset( $row['sent_payload'] ) ? (string) $row['sent_payload'] : '',
				'last_response' => isset( $row['last_response'] ) ? (string) $row['last_response'] : '',
			),
			200
		);
	}

	/**
	 * Re-drive failed rows (3.10.1, manual recovery). Body:
	 *   { source?, id? }
	 *   - id + source        → revive that single failed row in that queue.
	 *   - source (no id)      → revive ALL failed rows in that queue.
	 *   - neither             → revive ALL failed rows in BOTH queues.
	 * reset_failed() flips FAILED→PENDING; this then kicks the recurring flushes
	 * so the rows re-send promptly instead of waiting for the next 60s tick.
	 */
	public function retry( WP_REST_Request $request ): WP_REST_Response {
		$source = $this->sanitize_source( (string) $request->get_param( 'source' ) );
		$id     = (int) $request->get_param( 'id' );

		if ( $id > 0 && $source === '' ) {
			return new WP_REST_Response( array( 'error' => 'source_required_for_single_retry' ), 400 );
		}

		$ids   = $id > 0 ? array( $id ) : null;
		$rec   = new IngestQueue();
		$plus  = new EventQueue();
		$reset = 0;

		if ( $source !== self::SOURCE_SMAILY ) {
			$n = $rec->reset_failed( $ids );
			if ( $n > 0 ) {
				$rec->schedule_flushes( $this->rec_flush_hooks() );
			}
			$reset += $n;
		}
		if ( $source !== self::SOURCE_REC ) {
			$n = $plus->reset_failed( $ids );
			if ( $n > 0 ) {
				$plus->schedule_flush();
				// A revived automation.abandoned_cart row is drained by the
				// CartFlusher, not the main flush hook — kick it too so cart
				// retries re-send promptly (PRO-1195).
				$this->kick_flush( CartFlusher::FLUSH_HOOK, CartFlusher::AS_GROUP );
			}
			$reset += $n;
		}

		return new WP_REST_Response( array( 'reset' => $reset ), 200 );
	}

	/**
	 * Deduplicated async kick for a single flush hook.
	 */
	private function kick_flush( string $hook, string $group ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( $hook, array(), $group ) !== false
		) {
			return;
		}
		as_enqueue_async_action( $hook, array(), $group );
	}

	/**
	 * The rec queue is drained by four flushers, each on its own hook/group;
	 * kick all so a reset row of any event type re-sends promptly.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function rec_flush_hooks(): array {
		return array(
			array( IngestQueue::FLUSH_HOOK, IngestQueue::AS_GROUP ),
			array( CatalogRemoveFlusher::FLUSH_HOOK, CatalogRemoveFlusher::AS_GROUP ),
			array( CustomerFlusher::FLUSH_HOOK, CustomerFlusher::AS_GROUP ),
			array( OrderFlusher::FLUSH_HOOK, OrderFlusher::AS_GROUP ),
		);
	}

	/**
	 * Build the filtered UNION subquery + its ordered parameter list. When a
	 * source filter is set, only that table's SELECT participates; otherwise both
	 * are UNION ALL'd. Each branch carries the same projection so the outer query
	 * can ORDER/LIMIT across both. status/type are applied per branch.
	 *
	 * @return array{0: string, 1: array<int, scalar>}
	 */
	private function build_union( string $source, string $status, string $type ): array {
		$branches = array();
		$params   = array();

		if ( $source !== self::SOURCE_SMAILY ) {
			[ $sql, $p ] = $this->branch( $this->rec_table(), self::SOURCE_REC, 'max_attempts', $status, $type );
			$branches[]  = $sql;
			$params      = array_merge( $params, $p );
		}
		if ( $source !== self::SOURCE_REC ) {
			[ $sql, $p ] = $this->branch( $this->smaily_table(), self::SOURCE_SMAILY, 'NULL', $status, $type );
			$branches[]  = $sql;
			$params      = array_merge( $params, $p );
		}

		return array( implode( ' UNION ALL ', $branches ), $params );
	}

	/**
	 * One SELECT branch with a fixed projection. `$max_attempts_expr` is the
	 * column name (rec queue) or the literal `NULL` (Smaily queue, which has no
	 * such column).
	 *
	 * @return array{0: string, 1: array<int, scalar>}
	 */
	private function branch( string $table, string $source, string $max_attempts_expr, string $status, string $type ): array {
		$where  = array();
		$params = array();

		if ( $status !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( $type !== '' ) {
			$where[]  = 'event_type = %s';
			$params[] = $type;
		}

		$where_sql = $where === array() ? '' : ' WHERE ' . implode( ' AND ', $where );

		$sql = sprintf(
			'SELECT id, %s AS source, event_type, entity_id, status, attempts, %s AS max_attempts, last_error, created_at FROM %s%s',
			$this->quote( $source ),
			$max_attempts_expr,
			$table,
			$where_sql
		);

		return array( $sql, $params );
	}

	private function failed_last_24h(): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rec    = $this->rec_table();
		$smaily = $this->smaily_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT
					( SELECT COUNT(*) FROM {$rec} WHERE status = 'failed' AND created_at >= %s )
					+ ( SELECT COUNT(*) FROM {$smaily} WHERE status = 'failed' AND created_at >= %s )",
				$cutoff,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function shape_row( array $row ): array {
		return array(
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : '',
			'event_type'   => isset( $row['event_type'] ) ? (string) $row['event_type'] : '',
			'entity_id'    => isset( $row['entity_id'] ) ? (string) $row['entity_id'] : '',
			'status'       => isset( $row['status'] ) ? (string) $row['status'] : '',
			'attempts'     => isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
			'max_attempts' => isset( $row['max_attempts'] ) && $row['max_attempts'] !== null ? (int) $row['max_attempts'] : null,
			'last_error'   => isset( $row['last_error'] ) ? (string) $row['last_error'] : '',
			'created_at'   => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
		);
	}

	private function sanitize_source( string $source ): string {
		return in_array( $source, array( self::SOURCE_REC, self::SOURCE_SMAILY ), true ) ? $source : '';
	}

	/** Allow only queue tokens (status / event_type) — alnum + dot/underscore/dash. */
	private function sanitize_token( string $value ): string {
		return (string) preg_replace( '/[^a-zA-Z0-9._-]/', '', $value );
	}

	private function quote( string $literal ): string {
		global $wpdb;
		return "'" . $wpdb->_escape( $literal ) . "'";
	}

	private function rec_table(): string {
		global $wpdb;
		return $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
	}

	private function smaily_table(): string {
		global $wpdb;
		return $wpdb->prefix . EventQueue::TABLE_SUFFIX;
	}
}
