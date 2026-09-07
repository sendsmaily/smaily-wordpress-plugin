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

use Automattic\WooCommerce\Utilities\OrderUtil;
use Smaily\Connect\Constants;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalResend;
use Smaily\Connect\Smaily\TransactionalRetryGuard;
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
 * This is the visibility half of the 3.10 pilot-hardening work (Layer 1), plus
 * the 3.10.1 recovery write route (`POST /events/retry`) and the deliberate
 * second confirmation (`POST /events/resend`, PRO-2324) over the same rows.
 * The data is a
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

	/** @var callable(): TransactionalResend */
	private $resend_factory;

	/**
	 * @param callable(): TransactionalResend $resend_factory Built on demand —
	 *                                                        only `resend()`
	 *                                                        needs it.
	 */
	public function __construct( callable $resend_factory ) {
		$this->resend_factory = $resend_factory;
	}

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

		// A deliberate second confirmation (PRO-2324). Same capability +
		// nonce gate as Retry; it is a different question about a different
		// row, so it is a different route.
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend' ),
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
				'events'     => $this->shape_rows( is_array( $rows ) ? $rows : array() ),
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
		$source = $this->sanitize_source( (string) $request->get_param( 'source' ) );
		$id     = (int) $request->get_param( 'id' );

		if ( $id <= 0 || ( $source !== self::SOURCE_REC && $source !== self::SOURCE_SMAILY ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_event_ref' ), 400 );
		}

		$table = $source === self::SOURCE_REC ? $this->rec_table() : $this->smaily_table();
		$row   = $this->fetch_row( $table, $id );

		if ( $row === null ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$row['source'] = $source;

		return new WP_REST_Response(
			array(
				// A drill-down never renders the "Send again" button, so it
				// skips that answer rather than paying for the order lookup
				// behind it (the retry refusal it DOES render still costs one).
				'event'         => $this->shape_rows( array( $row ), false )[0],
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
	 * reset_failed() flips FAILED→PENDING; this then makes sure a flush pass is
	 * scheduled for every hook that drains them. The re-send is NOT immediate
	 * (PRO-2323): the one-offs dedupe against the flushers' recurring actions,
	 * which are always scheduled, so a revived row goes out on its flusher's
	 * next scheduled pass — within about a minute.
	 *
	 * Which failed transactional rows may be revived at all is not this
	 * route's rule to state — see TransactionalRetryGuard (PRO-1733).
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
			[ $refused, $retryable_transactional ] = $this->classify_failed_transactional( $ids );

			if ( $id > 0 && isset( $refused[ $id ] ) ) {
				return new WP_REST_Response(
					array(
						'error'   => 'transactional_retry_refused',
						'reason'  => $refused[ $id ],
						'message' => TransactionalRetryGuard::message( $refused[ $id ] ),
					),
					409
				);
			}

			// A bulk revive leaves EVERY transactional row alone and then
			// resets the few the guard cleared, so "Retry all failed" can
			// never revive what the single-row route turns down (PRO-1733).
			$n = $ids === null
				? $plus->reset_failed( null, TransactionalFlusher::EVENT_TYPES ) + $plus->reset_failed( $retryable_transactional )
				: $plus->reset_failed( $ids );

			if ( $n > 0 ) {
				$plus->schedule_flush();
				// A revived automation.abandoned_cart row is drained by the
				// CartFlusher, not the main flush hook — so its hook is
				// covered separately (PRO-1195). Same next-scheduled-pass
				// timing as every other revived row.
				$this->ensure_flush_scheduled( CartFlusher::FLUSH_HOOK, CartFlusher::AS_GROUP );
			}
			TransactionalFlusher::revive( $plus, $retryable_transactional );
			$reset += $n;
		}

		return new WP_REST_Response( array( 'reset' => $reset ), 200 );
	}

	/**
	 * Send one already-sent confirmation to the shopper a SECOND time, on
	 * explicit merchant request (PRO-2324). Body: { source, id }.
	 *
	 * This is NOT a retry: the row named here stays exactly as it is, and a
	 * NEW row is enqueued for the same order + type, so the Event Log shows
	 * both sends. It goes out on the transactional flusher's next scheduled
	 * pass, within about a minute.
	 *
	 * Which rows may be sent again is TransactionalRetryGuard's rule, the
	 * same one the list projection answers with `can_send_again` — the
	 * button and the route can't drift apart.
	 */
	public function resend( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		if ( $id <= 0 || (string) $request->get_param( 'source' ) !== self::SOURCE_SMAILY ) {
			return new WP_REST_Response( array( 'error' => 'invalid_event_ref' ), 400 );
		}

		$row = $this->fetch_row( $this->smaily_table(), $id );

		if ( $row === null ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$event_type = (string) $row['event_type'];

		// Whether the order still exists is the service's answer, not this
		// route's: it loads the order anyway and says so with
		// ERROR_ORDER_MISSING, which lands on the same refusal below.
		if ( ! TransactionalRetryGuard::resendable( $event_type, (string) $row['status'], true ) ) {
			return $this->resend_refused( 'resend_not_available' );
		}

		$resend = ( $this->resend_factory )();
		$result = $resend->resend(
			(int) $row['entity_id'],
			$event_type,
			TransactionalRetryGuard::to_status( (string) $row['payload'] )
		);

		if ( $result['error'] === TransactionalResend::ERROR_ORDER_MISSING ) {
			// A row whose order is gone is simply not resendable — the same
			// answer the list projection gives it.
			return $this->resend_refused( 'resend_not_available' );
		}

		if ( $result['error'] !== '' ) {
			return $this->resend_refused( $result['error'] );
		}

		return new WP_REST_Response(
			array(
				'queued' => 1,
				'id'     => $result['id'],
			),
			200
		);
	}

	/**
	 * A refused "Send again", worded for the merchant (PRO-2369). The wording
	 * belongs to TransactionalResend, beside the ERROR_* codes it maps — the
	 * retry route's refusal is worded by TransactionalRetryGuard for the same
	 * reason.
	 */
	private function resend_refused( string $error ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'   => $error,
				'message' => TransactionalResend::message( $error ),
			),
			409
		);
	}

	/**
	 * Split the failed transactional rows in the Smaily queue into the ones a
	 * retry must refuse and the ones it may re-drive (PRO-1733).
	 *
	 * @param int[]|null $ids Restrict to these row ids; null = every failed row.
	 *
	 * @return array{0: array<int, string>, 1: int[]} [ id => refusal reason ], retryable ids.
	 */
	private function classify_failed_transactional( ?array $ids ): array {
		global $wpdb;

		$table  = $this->smaily_table();
		$params = array_merge( array( EventQueue::STATUS_FAILED ), TransactionalFlusher::EVENT_TYPES );
		$where  = 'status = %s AND event_type IN ( ' . implode( ', ', array_fill( 0, count( TransactionalFlusher::EVENT_TYPES ), '%s' ) ) . ' )';

		if ( $ids !== null ) {
			$ids = EventQueue::clean_ids( $ids );
			if ( $ids === array() ) {
				return array( array(), array() );
			}
			$where .= ' AND id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )';
			$params = array_merge( $params, $ids );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, event_type, entity_id, payload FROM {$table} WHERE {$where}", $params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$rows      = is_array( $rows ) ? $rows : array();
		$existing  = $this->existing_orders( array_column( $rows, 'entity_id' ) );
		$refused   = array();
		$retryable = array();

		foreach ( $rows as $row ) {
			$reason = TransactionalRetryGuard::refusal_reason(
				(string) ( $row['event_type'] ?? '' ),
				(string) ( $row['payload'] ?? '' ),
				$this->order_exists( (string) ( $row['entity_id'] ?? '' ), $existing )
			);

			if ( $reason === '' ) {
				$retryable[] = (int) $row['id'];
				continue;
			}

			$refused[ (int) $row['id'] ] = $reason;
		}

		return array( $refused, $retryable );
	}

	/**
	 * Make sure a flush pass is queued for a single hook. Deduplicated against
	 * whatever is already scheduled on it — and the flusher's recurring action
	 * always is, so in practice this is a no-op and the rows go out on the next
	 * scheduled pass (PRO-2323). It is the safety net for a store whose
	 * recurring action has gone missing, not a run-now kick.
	 */
	private function ensure_flush_scheduled( string $hook, string $group ): void {
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
	 * cover all four so a reset row of any event type is picked up by its own
	 * flusher's next scheduled pass.
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
			'SELECT id, %s AS source, event_type, entity_id, status, attempts, %s AS max_attempts, last_error, created_at, %s AS retry_payload, %s AS last_response FROM %s%s',
			$this->quote( $source ),
			$max_attempts_expr,
			$this->conditional_column_expr(
				$source,
				'payload',
				EventQueue::STATUS_FAILED,
				' AND event_type IN ( ' . implode( ', ', array_map( array( $this, 'quote' ), TransactionalFlusher::EVENT_TYPES ) ) . ' )'
			),
			$this->conditional_column_expr( $source, 'last_response', EventQueue::STATUS_SENT ),
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
	 * Shape a set of rows for the wire, resolving order existence for the
	 * transactional rows among them in ONE batched lookup (PRO-1733) — the
	 * guard takes existence as an input and does no I/O itself.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param bool                             $with_send_again Whether
	 *        `can_send_again` needs a real answer. False for a single-row
	 *        drill-down, which renders no such button: a sent row then
	 *        reports false without an order lookup.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function shape_rows( array $rows, bool $with_send_again = true ): array {
		$entity_ids = array();
		foreach ( $rows as $row ) {
			if ( ! $this->is_transactional( $row ) ) {
				continue;
			}
			// Both transactional actions need to know whether the order is
			// still there: Retry to refuse a row it can't rebuild (PRO-1733),
			// "Send again" to offer itself at all (PRO-2324).
			$status = (string) ( $row['status'] ?? '' );
			if ( $status === EventQueue::STATUS_FAILED
				|| ( $with_send_again && $status === EventQueue::STATUS_SENT )
			) {
				$entity_ids[] = (string) ( $row['entity_id'] ?? '' );
			}
		}

		$existing = $this->existing_orders( $entity_ids );
		$shaped   = array();
		foreach ( $rows as $row ) {
			$shaped[] = $this->shape_row( $row, $existing );
		}

		return $shaped;
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param array<int, true>      $existing_orders
	 *
	 * @return array<string, mixed>
	 */
	private function shape_row( array $row, array $existing_orders ): array {
		// PRO-1733: '' when the row may be retried; otherwise why not, plus
		// the sentence the Details panel shows. Only a FAILED transactional
		// row can be refused, and only for those does the list query carry a
		// payload (retry_payload); detail() reads the row's own payload.
		$refusal = $this->is_transactional( $row ) && (string) ( $row['status'] ?? '' ) === EventQueue::STATUS_FAILED
			? TransactionalRetryGuard::refusal_reason(
				(string) ( $row['event_type'] ?? '' ),
				(string) ( $row['retry_payload'] ?? $row['payload'] ?? '' ),
				$this->order_exists( (string) ( $row['entity_id'] ?? '' ), $existing_orders )
			)
			: '';

		return array(
			'id'                    => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'source'                => isset( $row['source'] ) ? (string) $row['source'] : '',
			'event_type'            => isset( $row['event_type'] ) ? (string) $row['event_type'] : '',
			'entity_id'             => isset( $row['entity_id'] ) ? (string) $row['entity_id'] : '',
			'status'                => isset( $row['status'] ) ? (string) $row['status'] : '',
			'attempts'              => isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
			'max_attempts'          => isset( $row['max_attempts'] ) && $row['max_attempts'] !== null ? (int) $row['max_attempts'] : null,
			'last_error'            => isset( $row['last_error'] ) ? (string) $row['last_error'] : '',
			'created_at'            => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'retry_refusal'         => $refusal,
			'retry_refusal_message' => $refusal === '' ? '' : TransactionalRetryGuard::message( $refusal ),
			// PRO-2372: a reminder withdrawn because the shopper bought first
			// is terminal-marked `sent` like any other skip; the merchant
			// must read "cancelled" on the row without opening Details.
			'cancelled'             => EventQueue::is_cancelled_response( (string) ( $row['last_response'] ?? '' ) ),
			// PRO-2324: may the merchant deliberately send this confirmation
			// a second time? Only a transactional row Smaily itself sent, on
			// an order that still exists.
			'can_send_again'        => TransactionalRetryGuard::resendable(
				(string) ( $row['event_type'] ?? '' ),
				(string) ( $row['status'] ?? '' ),
				$this->order_exists( (string) ( $row['entity_id'] ?? '' ), $existing_orders )
			),
		);
	}

	/**
	 * Whether the row is one of the two transactional-email types — the only
	 * rows either Event Log action applies to. Which action, and to which
	 * status, is the caller's own compare.
	 *
	 * @param array<string, mixed> $row
	 */
	private function is_transactional( array $row ): bool {
		return in_array( (string) ( $row['event_type'] ?? '' ), TransactionalFlusher::EVENT_TYPES, true );
	}

	/**
	 * One row of either queue by id, or null when there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetch_row( string $table, int $id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The order ids among $entity_ids that still exist, in ONE lookup against
	 * whichever order storage is active (HPOS or the legacy posts table).
	 * Either way the lookup must be status-blind, because an order on a
	 * merchant-defined shipped status whose plugin has since been deactivated
	 * is no longer on a registered status — and that is exactly the PRO-1733
	 * retry case, so reporting it missing would refuse the one retry that
	 * should be allowed.
	 *
	 * HPOS gets that from `wc_get_orders()` asked for status `all` (`post__in`
	 * is the id filter it honours — `include` is silently ignored and would
	 * report every order as present). WP_Query has no `all` literal, so the
	 * legacy store is read directly instead (PRO-2326), on the same
	 * table/column shape the order backfill already uses for that path.
	 *
	 * @param array<int, mixed> $entity_ids
	 *
	 * @return array<int, true>
	 */
	private function existing_orders( array $entity_ids ): array {
		$ids = EventQueue::clean_ids( array_map( 'intval', $entity_ids ) );

		if ( $ids === array() || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$ids = array_values( array_unique( $ids ) );

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			/** @var int[] $found `return => ids` yields ids — the stub types wc_get_orders() as WC_Order[]. */
			$found = wc_get_orders(
				array(
					'post__in' => $ids,
					'limit'    => -1,
					'return'   => 'ids',
					'status'   => 'all',
				)
			);
		} else {
			$found = $this->existing_legacy_order_ids( $ids );
		}

		$existing = array();
		foreach ( $found as $found_id ) {
			$existing[ (int) $found_id ] = true;
		}

		return $existing;
	}

	/**
	 * The ids that are still order posts, read straight from the legacy orders
	 * table so no status filter can hide one (PRO-2326).
	 *
	 * @param int[] $ids
	 *
	 * @return int[]
	 */
	private function existing_legacy_order_ids( array $ids ): array {
		global $wpdb;

		$spec         = OrderBackfillJob::table_spec( false, $wpdb->prefix );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$spec['id_col']} FROM {$spec['table']} WHERE {$spec['type_col']} = %s AND {$spec['id_col']} IN ( {$placeholders} )",
				array_merge( array( 'shop_order' ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', $found );
	}

	/**
	 * WooCommerce absent (the row is read on a store where WC is deactivated)
	 * means "can't tell" — not "gone"; the guard's event-type rules still
	 * decide, which keeps the safe side (refuse) in reach.
	 *
	 * @param array<int, true> $existing
	 */
	private function order_exists( string $entity_id, array $existing ): bool {
		$order_id = (int) $entity_id;

		if ( $order_id <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}

		return isset( $existing[ $order_id ] );
	}

	/**
	 * One heavy column carried only for the rows that actually need it, the
	 * empty literal for the rest — so a page of ordinary events doesn't drag
	 * it out of the database. The rec queue needs neither of them, so it gets
	 * the literal outright.
	 *
	 * `payload` is carried for FAILED transactional rows: the list reads it
	 * to tell a re-fired-native-email failure from one the shopper never got
	 * (PRO-1733). `last_response` is carried for SENT rows, where a withdrawn
	 * abandoned-cart reminder records `cancelled` (PRO-1723) and the list must
	 * label it as such (PRO-2372); a failed row's response is the big one
	 * nobody needs here.
	 *
	 * @param string $extra_condition Already-quoted SQL appended to the status
	 *                                test, or '' for none.
	 */
	private function conditional_column_expr( string $source, string $column, string $status, string $extra_condition = '' ): string {
		if ( $source !== self::SOURCE_SMAILY ) {
			return "''";
		}

		return sprintf(
			"CASE WHEN status = %s%s THEN %s ELSE '' END",
			$this->quote( $status ),
			$extra_condition,
			$column
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
