<?php
/**
 * REST route the Dashboard reads its state from (CON-464).
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and Action Scheduler's own table: every value is $wpdb->prepare()d; a live count must not be cached.

use Smaily\Connect\Constants;
use Smaily\Connect\DB\QueueJanitor;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Report\ConnectionHealth;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use WP_REST_Request;
use WP_REST_Response;

/**
 *   GET /dashboard
 *     → 200 {
 *         failed_24h, contact_syncs_30d, catalog_items_30d, queued_today,
 *         smaily_refusal: '' | 'plan_blocked' | 'credentials_rejected',
 *         engine_down: bool,
 *         engine_key_rejected: bool,
 *         unsent_product_list_nights: int (0 = the list went out),
 *         background: { running: bool, last_run: 'Y-m-d H:i:s' UTC | '', wp_cron_disabled: bool }
 *       }
 *
 * Every number is a query of the plugin's own queues, as on Magento's and
 * PrestaShop's Dashboard. The Smaily and Campaign Intelligence states come
 * from the health check's stored notices, so the Dashboard and the admin
 * notices tell the same story. Nothing here calls Smaily or the engine.
 *
 * Auth: a signed-in user, a valid `wp_rest` nonce and `manage_options`.
 */
class DashboardEndpoint {

	use AdminSession;

	public const ROUTE = '/dashboard';

	/** The tiles' "delivered, last 30 days". */
	public const DELIVERED_DAYS = 30;

	/** No nightly product list for this many nights shows the warning (Magento's figure). */
	public const UNSENT_PRODUCT_LIST_NIGHTS = 3;

	/** No run of our Action Scheduler actions for longer than this reads "Not running" (PrestaShop's figure). */
	public const BACKGROUND_STOPPED_SECONDS = 900;

	/** The engine's answers to a key it does not accept: `401 unauthorised` (contract "Authentication"), or a bare 401 / 403. */
	private const KEY_REJECTED_CODES = array( 'unauthorised', 'http_401', 'http_403' );

	/** The prefixes of the plugin's Action Scheduler hooks (QueueJanitor prunes the same ones). */
	private const OUR_HOOK_PREFIXES = array( 'smly_plus_', 'smly_rec_' );

	/** @var RecEngineSettings */
	private $settings;

	public function __construct( RecEngineSettings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'state' ),
				'permission_callback' => array( $this, 'admin_check' ),
			)
		);
	}

	/** This route shows the plugin's state; it manages no Campaign Intelligence. */
	protected function capability_error(): string {
		return __( 'You do not have permission to view this page.', 'smaily-connect' );
	}

	public function state( WP_REST_Request $request ): WP_REST_Response {
		$now     = $this->now();
		$notices = get_option( NotificationManager::OPTION_NOTICES, array() );
		$notices = is_array( $notices ) ? $notices : array();
		$last    = $this->last_run();

		return new WP_REST_Response(
			array(
				'failed_24h'                 => NotificationManager::failed_last_24h(),
				'contact_syncs_30d'          => $this->delivered( EventQueue::TABLE_SUFFIX, array( HookHandler::EVENT_CONTACT_SYNC ), $now ),
				'catalog_items_30d'          => $this->delivered(
					IngestQueue::TABLE_SUFFIX,
					array( CatalogHookHandler::EVENT_CATALOG_UPSERT, CatalogHookHandler::EVENT_CATALOG_DELETE, CatalogHookHandler::EVENT_CATALOG_REMOVE ),
					$now
				),
				'queued_today'               => $this->queued_since( self::start_of_day( $now ) ),
				'smaily_refusal'             => self::smaily_refusal( $notices ),
				// A refused account is not an outage (NotificationManager::active_notices()).
				'engine_down'                => $this->settings->sending_allowed() && isset( $notices['engine_down'] ),
				// The engine's latest answer to this connection, as the
				// installation report stores it; a deactivated account
				// (403 tenant_inactive) is is_refused(), told apart.
				'engine_key_rejected'        => $this->settings->sending_allowed()
					&& self::key_rejected( ( new ConnectionHealth() )->engine()['last_error_code'] ),
				'unsent_product_list_nights' => $this->settings->sending_allowed()
					? self::unsent_nights( $this->last_manifest_sent(), self::timestamp( $this->settings->issued_at() ), $now )
					: 0,
				'background'                 => array(
					'running'          => self::background_running( $last, $now ),
					'last_run'         => $last !== null ? gmdate( 'Y-m-d H:i:s', $last ) : '',
					'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				),
			),
			200
		);
	}

	/**
	 * Why the health check found Smaily refusing the saved account: its
	 * package has no API access, or it rejects the credentials. '' when it
	 * does neither — an unreachable Smaily is not a refusal.
	 *
	 * @param array<string, mixed> $notices NotificationManager::OPTION_NOTICES.
	 */
	public static function smaily_refusal( array $notices ): string {
		if ( isset( $notices['smaily_plan_blocked'] ) ) {
			return 'plan_blocked';
		}
		if ( isset( $notices['smaily_credentials_rejected'] ) ) {
			return 'credentials_rejected';
		}
		return '';
	}

	/** Whether the engine's latest answer says it does not accept the stored key. */
	public static function key_rejected( ?string $last_error_code ): bool {
		return $last_error_code !== null && in_array( $last_error_code, self::KEY_REJECTED_CODES, true );
	}

	/**
	 * How many nights no product list has gone to Campaign Intelligence, or 0
	 * while it is fewer than UNSENT_PRODUCT_LIST_NIGHTS. Counted from the
	 * last list sent, else from the connection — a connection younger than
	 * that many nights has nothing missing yet.
	 */
	public static function unsent_nights( ?int $last_sent, ?int $connected_at, int $now ): int {
		$since = $last_sent ?? $connected_at;
		if ( $since === null ) {
			return 0;
		}
		$nights = intdiv( max( 0, $now - $since ), DAY_IN_SECONDS );
		return $nights >= self::UNSENT_PRODUCT_LIST_NIGHTS ? $nights : 0;
	}

	/** Whether one of our Action Scheduler actions ran in the last BACKGROUND_STOPPED_SECONDS. */
	public static function background_running( ?int $last_run, int $now ): bool {
		return $last_run !== null && $last_run >= $now - self::BACKGROUND_STOPPED_SECONDS;
	}

	/** Test seam: the clock. */
	protected function now(): int {
		return time();
	}

	/**
	 * Rows of $types that went out in the last DELIVERED_DAYS — a row
	 * skipped or withdrawn is stored `sent` but sent nothing (EventsEndpoint),
	 * so it is left out.
	 *
	 * @param array<int, string> $types
	 */
	private function delivered( string $table_suffix, array $types, int $now ): int {
		global $wpdb;

		$table        = $wpdb->prefix . $table_suffix;
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge(
			$types,
			array(
				gmdate( 'Y-m-d H:i:s', $now - self::DELIVERED_DAYS * DAY_IN_SECONDS ),
				'%' . $wpdb->esc_like( '"outcome":"' . EventQueue::OUTCOME_SKIPPED . '"' ) . '%',
				'%' . $wpdb->esc_like( '"outcome":"' . EventQueue::OUTCOME_CANCELLED . '"' ) . '%',
			)
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the IN() list builds its own placeholder string.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE event_type IN ( {$placeholders} )
				AND status = 'sent'
				AND created_at >= %s
				AND COALESCE( last_response, '' ) NOT LIKE %s
				AND COALESCE( last_response, '' ) NOT LIKE %s",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/** Rows of both queues waiting to be sent that were queued since $since. */
	private function queued_since( int $since ): int {
		global $wpdb;

		$rec    = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		$smaily = $wpdb->prefix . EventQueue::TABLE_SUFFIX;
		$cutoff = gmdate( 'Y-m-d H:i:s', $since );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT
					( SELECT COUNT(*) FROM {$rec} WHERE status = 'pending' AND created_at >= %s )
					+ ( SELECT COUNT(*) FROM {$smaily} WHERE status = 'pending' AND created_at >= %s )",
				$cutoff,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** When the newest nightly product list that Campaign Intelligence took was queued, or null. */
	private function last_manifest_sent(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$created = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( created_at ) FROM {$table} WHERE event_type = %s AND status = 'sent'",
				CatalogManifest::EVENT_TYPE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_string( $created ) ? self::timestamp( $created ) : null;
	}

	/**
	 * When one of our Action Scheduler actions last ran, or null when none
	 * did — or Action Scheduler has no table on this store.
	 */
	private function last_run(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . QueueJanitor::AS_ACTIONS_TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		$hook_where = implode( ' OR ', array_fill( 0, count( self::OUR_HOOK_PREFIXES ), 'hook LIKE %s' ) );
		$hook_likes = array();
		foreach ( self::OUR_HOOK_PREFIXES as $prefix ) {
			$hook_likes[] = $wpdb->esc_like( $prefix ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the hook LIKE list builds its own placeholder string.
		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_attempt_gmt FROM {$table}
				WHERE ( {$hook_where} )
				AND status IN ( 'complete', 'failed' )
				ORDER BY last_attempt_gmt DESC
				LIMIT 1",
				$hook_likes
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_string( $last ) ? self::timestamp( $last ) : null;
	}

	/** The start of $now's day in the site's timezone. */
	private static function start_of_day( int $now ): int {
		return ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 0, 0 )->getTimestamp();
	}

	/** A stored UTC datetime as a timestamp; null for an empty or zero date. */
	private static function timestamp( string $utc ): ?int {
		if ( $utc === '' || strpos( $utc, '0000-00-00' ) === 0 ) {
			return null;
		}
		$time = strtotime( $utc . ' UTC' );
		return $time === false ? null : $time;
	}
}
