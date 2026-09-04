<?php
/**
 * REST endpoints driving the backfill lifecycle.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Three sub-routes under `/wp-json/smaily-connect/v1/backfill/...`:
 *
 *   POST   /backfill/start   body: {"job_type": "contacts"}
 *                            → {"job_id": int, "status": "running", "total": int}
 *
 *   GET    /backfill/status  query: ?job_type=contacts
 *                            → {"status": "running|completed|failed|idle",
 *                               "processed": int, "total": int,
 *                               "percent": int, "eta_seconds": int|null}
 *
 *   POST   /backfill/cancel  body: {"job_type": "contacts"}
 *                            → {"cancelled": bool}
 *
 * Auth: nonce (wp_rest) + manage_options capability on all three.
 *
 * Job types accepted:
 *   - "contacts" — Smaily-side user backfill (Faas 1)
 *   - Phase 3 adds "orders", "customers", "products" against the rec-engine.
 *     The endpoint surface here is stable; only the JOB_TYPE_HANDLERS map
 *     grows. Cancelling an "orders" job in Phase 3 will hit the same route.
 */
class BackfillEndpoint {

	public const ROUTE_PREFIX = '/backfill';
	public const TABLE_SUFFIX = 'smly_plus_backfill_job';

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/** Hook the AS tick scheduler fires once /backfill/start enqueues it. */
	public const TICK_HOOK = 'smly_plus_backfill_tick';

	/**
	 * Job types this endpoint accepts. `contacts` is the legacy Smaily backfill;
	 * `products` / `customers` / `orders` are the 3.5 rec-engine backfills.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_JOB_TYPES = array( 'contacts', 'products', 'customers', 'orders' );

	/**
	 * Factory that constructs the right backfill job for a job_type on demand.
	 * Lazy because the dependency (Smaily credentials for contacts, the
	 * rec-engine connection for products) may not be configured yet — the route
	 * must still register so the UI doesn't 404 before setup. Returning null
	 * yields a 503 so the caller surfaces "not connected" instead of throwing.
	 *
	 * @var callable(string $job_type): ?BackfillJobInterface
	 */
	private $job_factory;

	/**
	 * @param callable(string $job_type): ?BackfillJobInterface $job_factory
	 *   Factory producing the job for a job_type, or null when unconfigured.
	 */
	public function __construct( callable $job_factory ) {
		$this->job_factory = $job_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->job_type_arg(),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->job_type_arg(),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->job_type_arg(),
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
				__( 'You do not have permission to manage backfill jobs.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function start( WP_REST_Request $request ): WP_REST_Response {
		// Diagnostic — sub-PR 2.H.6.
		\Smaily\Connect\Support\DebugLog::write(
			sprintf(
				'[smaily-connect backfill.endpoint.start] requested job_type=%s',
				(string) $request->get_param( 'job_type' )
			)
		);

		$job_type = $this->resolve_job_type( $request );
		if ( $job_type === null ) {
			return $this->unsupported_job_type_response();
		}

		$job = ( $this->job_factory )( $job_type );
		if ( ! $job instanceof BackfillJobInterface ) {
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect backfill.endpoint.start] factory returned null — not connected' );
			return new WP_REST_Response(
				array(
					'error'   => 'not_configured',
					'message' => __(
						'This connection is not configured yet. Finish setup first.',
						'smaily-connect'
					),
				),
				503
			);
		}

		$job_id = $job->start();
		\Smaily\Connect\Support\DebugLog::write( sprintf( '[smaily-connect backfill.endpoint.start] start() returned row_id=%d', $job_id ) );

		$row    = $this->read_state( $job_type );
		$status = is_array( $row ) ? (string) $row['status'] : self::STATUS_RUNNING;

		// Schedule the first AS tick so backfill processing begins
		// immediately. The tick handler reschedules itself until the job
		// reaches its terminal state. A job start() already finished has
		// nothing to tick for (PRO-1715: an empty contact audience) — and a
		// tick would flip its row back to 'running'.
		if ( $status === self::STATUS_RUNNING && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::TICK_HOOK,
				array( 'job_type' => $job_type ),
				EventQueue::AS_GROUP
			);
		}

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => $status,
				'total'  => is_array( $row ) ? (int) $row['total_count'] : 0,
			),
			200
		);
	}

	public function status( WP_REST_Request $request ): WP_REST_Response {
		$job_type = $this->resolve_job_type( $request );
		if ( $job_type === null ) {
			return $this->unsupported_job_type_response();
		}

		$row = $this->read_state( $job_type );
		if ( $row === null ) {
			return new WP_REST_Response(
				array(
					'status'            => 'idle',
					'processed'         => 0,
					'synced'            => 0,
					'total'             => 0,
					'percent'           => 0,
					'eta_seconds'       => null,
					'started_at'        => null,
					'completed_at'      => null,
					'audience_estimate' => $this->contact_audience_estimate( $job_type, 'idle' ),
				),
				200
			);
		}

		$processed = (int) $row['processed_count'];
		$total     = (int) $row['total_count'];
		$percent   = $total > 0 ? (int) round( ( $processed / $total ) * 100 ) : 0;

		// Engine-confirmed truth (3.10.0): processed_count tracks records WALKED,
		// which previously made the panel read "1400 / 1400" even when rows
		// silently failed in the queue. Count the actual queue outcomes for this
		// job's event types since the run started, so the UI can show "N synced,
		// M failed" instead of conflating walked with sent. Read-time + no schema
		// change; the per-row detail lives in the Event Log (/events).
		$counts = $this->engine_confirmed_counts(
			$job_type,
			isset( $row['started_at'] ) ? (string) $row['started_at'] : null
		);

		// Surface started_at + completed_at so the React UI can render
		// "Last run: 2026-05-21 10:42, 142 / 142" between runs. Previously
		// the only way to see a completed backfill was to be on the page
		// when it finished — reload blanked the panel because the boot
		// payload didn't include this state.
		return new WP_REST_Response(
			array(
				'status'            => (string) $row['status'],
				'processed'         => $processed,
				// F3-55: contacts job — cumulative AUDIENCE members handled
				// (POSTed + already-fresh). processed counts rows WALKED, so
				// on a consent-mode store the two differ by the opted-out
				// majority; the UI labels THIS number "contacts synced".
				// Engine jobs never write the column (stays 0) — their UI
				// keeps using processed/sent.
				'synced'            => isset( $row['synced_count'] ) ? (int) $row['synced_count'] : 0,
				'sent'              => $counts['sent'],
				'failed'            => $counts['failed'],
				'total'             => $total,
				'percent'           => min( 100, max( 0, $percent ) ),
				'eta_seconds'       => $this->estimate_eta( $row, $processed, $total ),
				'started_at'        => isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
				'completed_at'      => isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
				'audience_estimate' => $this->contact_audience_estimate( $job_type, (string) $row['status'] ),
			),
			200
		);
	}

	/**
	 * The contact-sync mode's audience size (F3-55) — what the panel shows as
	 * "N contacts will sync" BEFORE a run and can sanity-label the result
	 * after one. Contacts-only, and skipped while a run is live so the 2s
	 * status poll doesn't pay a usermeta COUNT per tick; null = not
	 * applicable, the UI hides the hint.
	 */
	private function contact_audience_estimate( string $job_type, string $status ): ?int {
		if ( $job_type !== 'contacts' || $status === 'running' ) {
			return null;
		}

		return ( new ContactAudience() )->count_audience();
	}

	/**
	 * Each backfill job_type drains its records through a specific queue +
	 * event-type set. Map it so status() can count the real outcomes.
	 *
	 * @var array<string, array{queue: string, types: string[]}>
	 */
	private const JOB_QUEUE_MAP = array(
		'products'  => array(
			'queue' => 'rec',
			'types' => array( 'catalog.upsert', 'catalog.delete' ),
		),
		'customers' => array(
			'queue' => 'rec',
			'types' => array( 'customer.upsert' ),
		),
		'orders'    => array(
			'queue' => 'rec',
			'types' => array( 'order.upsert' ),
		),
		'contacts'  => array(
			'queue' => 'smaily',
			'types' => array( 'contact.sync' ),
		),
	);

	/**
	 * Count engine-confirmed `sent` and terminal `failed` queue rows for this
	 * job's event types since the run started. `deduplicated` rows are marked
	 * `sent` by the flusher (already in the engine), so they count as synced.
	 * Bounded to created_at >= started_at so a re-run doesn't tally prior rows.
	 *
	 * @return array{sent: int, failed: int}
	 */
	private function engine_confirmed_counts( string $job_type, ?string $started_at ): array {
		$map = self::JOB_QUEUE_MAP[ $job_type ] ?? null;
		if ( $map === null || $started_at === null || $started_at === '' ) {
			return array(
				'sent'   => 0,
				'failed' => 0,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . ( $map['queue'] === 'rec' ? IngestQueue::TABLE_SUFFIX : EventQueue::TABLE_SUFFIX );

		$placeholders = implode( ', ', array_fill( 0, count( $map['types'] ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( status = 'sent' ) AS sent,
					SUM( status = 'failed' ) AS failed
				FROM {$table}
				WHERE event_type IN ( {$placeholders} ) AND created_at >= %s",
				array_merge( $map['types'], array( $started_at ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// SUM() returns NULL when no rows match; `?? 0` also keeps this null-safe
		// against a get_row result that doesn't carry the aggregate columns.
		return array(
			'sent'   => is_array( $row ) ? (int) ( $row['sent'] ?? 0 ) : 0,
			'failed' => is_array( $row ) ? (int) ( $row['failed'] ?? 0 ) : 0,
		);
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response {
		$job_type = $this->resolve_job_type( $request );
		if ( $job_type === null ) {
			return $this->unsupported_job_type_response();
		}

		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct status update on the custom backfill-state table; write-through, no cache.
		$updated = $wpdb->update(
			$table,
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql', true ),
			),
			array(
				'job_type' => $job_type,
				'target'   => $this->target_for( $job_type ),
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		// Drop any pending AS ticks so process_batch doesn't undo the cancel.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions(
				self::TICK_HOOK,
				array( 'job_type' => $job_type ),
				EventQueue::AS_GROUP
			);
		}

		return new WP_REST_Response(
			array( 'cancelled' => $updated !== false && $updated > 0 ),
			200
		);
	}

	/**
	 * @return array{job_type: array<string, mixed>}
	 */
	private function job_type_arg(): array {
		return array(
			'job_type' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	private function resolve_job_type( WP_REST_Request $request ): ?string {
		$value = (string) $request->get_param( 'job_type' );
		if ( ! in_array( $value, self::SUPPORTED_JOB_TYPES, true ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * The `target` column for a job_type — the legacy contacts backfill writes
	 * `smaily`, the rec-engine backfills write `rec_engine`. Lets both sets of
	 * rows coexist under the (job_type, target) UNIQUE key.
	 */
	private function target_for( string $job_type ): string {
		return $job_type === BackfillJob::BACKFILL_TYPE
			? BackfillJob::BACKFILL_TARGET
			: AbstractBackfillJob::TARGET;
	}

	private function unsupported_job_type_response(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'           => __( 'Unsupported job_type.', 'smaily-connect' ),
				'supported_types' => self::SUPPORTED_JOB_TYPES,
			),
			400
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_state( string $job_type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, processed_count, synced_count, total_count, started_at, completed_at FROM {$table} WHERE job_type = %s AND target = %s",
				$job_type,
				$this->target_for( $job_type )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rough ETA: assumes a steady processing rate of (processed) /
	 * (seconds since start). Returns null when there's no progress yet
	 * or when the job is already in a terminal state.
	 *
	 * @param array<string, mixed> $row
	 */
	private function estimate_eta( array $row, int $processed, int $total ): ?int {
		if ( $processed === 0 || $total === 0 || $processed >= $total ) {
			return null;
		}

		$started_at = isset( $row['started_at'] ) ? (string) $row['started_at'] : '';
		if ( $started_at === '' ) {
			return null;
		}

		$elapsed = max( 1, time() - (int) strtotime( $started_at . ' UTC' ) );
		$rate    = $processed / $elapsed; // items per second
		if ( $rate <= 0 ) {
			return null;
		}

		return (int) ceil( ( $total - $processed ) / $rate );
	}
}
