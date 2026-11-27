<?php
/**
 * Proactive health notifications (PLUGIN.md §13a) — Layer 3 base (3.10.2).
 *
 * A recurring health-check surfaces sync trouble as a WP admin notice so a pilot
 * operator learns "something broke" without watching the Event Log. This is the
 * admin-notice level only — the email level (§13a) is 3.10.3, post-pilot.
 *
 * @package Smaily\Connect\Notifications
 */

declare(strict_types=1);

namespace Smaily\Connect\Notifications;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Integrations\WooCommerce\StorefrontBeacon;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Report\ConnectionHealth;
use Smaily\Connect\Report\ReportBuilder;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RefusalReason;
use Smaily\Connect\Smaily\SubscriberPayloadBuilder;

/**
 * `error`-severity signals (§13a), all from the health-check cron:
 *   - failed events > threshold in 24h (across both durable queues);
 *   - rec-engine unreachable for > 1h (time-based, via a periodic ping);
 *   - the Smaily API refusing, under the cause it actually gave (PRO-1686):
 *     the account's package, the credentials, or genuinely unreachable. Only
 *     the last one waits out the hour's grace — the other two are Smaily's own
 *     verdict and will read the same in an hour.
 *
 * Each maps to a sticky-until-resolved admin notice: the health-check sets it
 * while the condition holds and clears it the moment the condition resolves
 * (a successful ping / the failed count dropping back under threshold). To stay
 * non-spammy the notice is dismissible with a 24h cooldown — a plain nonce'd
 * admin-post link, no per-page nag, no JS.
 */
final class NotificationManager {

	public const HEALTH_HOOK = 'smly_plus_health_check';
	public const AS_GROUP    = 'smaily-connect';

	/** Default "too many failures" threshold (§13a: >50 in 24h); filterable. */
	public const FAILED_THRESHOLD_DEFAULT = 50;

	/** How long the engine must be unreachable before we notify (§13a: >1h). */
	public const ENGINE_DOWN_GRACE = HOUR_IN_SECONDS;

	/** Dismiss cooldown — re-show a still-active notice after this (§13a's 24h window). */
	public const DISMISS_COOLDOWN = DAY_IN_SECONDS;

	public const OPTION_NOTICES           = 'smly_active_notices';
	public const OPTION_DOWN_SINCE        = 'smly_rec_health_down_since';
	public const OPTION_SMAILY_DOWN_SINCE = 'smly_smaily_health_down_since';
	public const OPTION_DISMISSED         = 'smly_notice_dismissed';

	public const DISMISS_ACTION = 'smly_dismiss_notice';

	/** Advisory key: browse tracking on + connected, but no WP Consent API present. */
	public const CONSENT_ADVISORY_KEY = 'consent_api_missing';

	/**
	 * Notice key: the engine refused this account outright (contract §2
	 * `403 tenant_inactive`). Rendered live off the persisted refusal rather
	 * than from the health-check's notice set — the refusal is recorded the
	 * instant a call meets it, and making the merchant wait up to an hour for
	 * the next tick to be told why sync stopped would be the wrong answer.
	 */
	public const TENANT_INACTIVE_KEY = 'engine_tenant_inactive';

	/** Advisory key: the saved contact-field selection is in a shape we can't read. */
	public const SYNC_FIELDS_ADVISORY_KEY = 'sync_fields_unreadable';

	/** Invitation key: no Smaily marketing account is connected (CON-321). */
	public const INVITE_KEY = 'smaily_not_connected';

	/** A dismissed invitation stays away this long (CON-321, Erkki 2026-11-17). */
	public const INVITE_DISMISS_COOLDOWN = 90 * DAY_IN_SECONDS;

	/**
	 * Banner key: the Campaign Intelligence invitation on top of Settings
	 * (CON-350). The admin closes it through the REST route
	 * `NoticesEndpoint`; the wizard step and the Settings tab keep their
	 * own copy of the banner, which cannot be closed.
	 */
	public const CI_BANNER_KEY = 'ci_invitation';

	/** A closed Campaign Intelligence banner returns after this (CON-350). */
	public const CI_BANNER_DISMISS_COOLDOWN = 30 * DAY_IN_SECONDS;

	/** The stylesheet of the Smaily invitation banner on the Dashboard (CON-351). */
	public const BANNER_STYLE_HANDLE = 'smaily-connect-banner';

	/**
	 * What the banner's static SVG markup may carry through wp_kses() — the
	 * elements and attributes icon_svg() and smaily_logo_svg() use, nothing
	 * more (attribute names lower case: kses compares them that way).
	 */
	private const SVG_ALLOWED = array(
		'svg'    => array(
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'focusable'       => true,
		),
		'path'   => array(
			'd'    => true,
			'fill' => true,
		),
		'rect'   => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
		),
		'circle' => array(
			'cx' => true,
			'cy' => true,
			'r'  => true,
		),
	);

	private RecEngineSettings $settings;

	/** @var callable():RecEngineClient */
	private $client_factory;

	/** @var callable():?SmailyClient */
	private $smaily_client_factory;

	private ?ReportBuilder $report_builder;

	/** invitation_due()'s answer for this request: the enqueue and the render both ask (null = not asked yet). */
	private ?bool $invitation_due = null;

	/**
	 * @param callable():RecEngineClient $client_factory Lazily builds a connected
	 *        rec-engine client (so we don't construct one when disconnected).
	 * @param callable():?SmailyClient   $smaily_client_factory Builds the default
	 *        Smaily client, or null when the email side isn't configured yet
	 *        (so an un-set-up store isn't reported as "Smaily down").
	 * @param ReportBuilder|null         $report_builder Answers whether a Smaily
	 *        marketing account is connected; built on first use when null.
	 */
	public function __construct(
		RecEngineSettings $settings,
		callable $client_factory,
		callable $smaily_client_factory,
		?ReportBuilder $report_builder = null
	) {
		$this->settings              = $settings;
		$this->client_factory        = $client_factory;
		$this->smaily_client_factory = $smaily_client_factory;
		$this->report_builder        = $report_builder;
	}

	public function register(): void {
		add_action( self::HEALTH_HOOK, array( $this, 'run_health_check' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_banner_style' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	// --- the health check (cron callback) ----------------------------------

	/**
	 * Recompute both signals and persist the active-notice set. Called by the
	 * recurring Action Scheduler tick (Bootstrap schedules HEALTH_HOOK).
	 */
	public function run_health_check(): void {
		$now        = time();
		$failed     = self::failed_last_24h();
		$down_since = $this->probe_engine( $now );
		$smaily     = $this->probe_smaily( $now );

		$notices = $this->evaluate_signals(
			$failed,
			$down_since,
			$now,
			$this->failed_threshold(),
			$smaily['down_since'],
			$smaily['reason']
		);

		update_option( self::OPTION_NOTICES, $notices, false );
	}

	/**
	 * Pure signal evaluation — given the inputs, return the active-notice map.
	 * Split out so the threshold + grace logic is unit-testable without a DB,
	 * network, or clock. Covers the pilot's BOTH sync paths: the rec-engine
	 * (`engine_down`) and Smaily email — the latter under the cause Smaily gave
	 * (`smaily_plan_blocked` / `smaily_credentials_rejected` / `smaily_down`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function evaluate_signals(
		int $failed_24h,
		?int $down_since,
		int $now,
		int $threshold,
		?int $smaily_down_since = null,
		string $smaily_reason = RefusalReason::UNREACHABLE
	): array {
		$notices = array();

		if ( $failed_24h > $threshold ) {
			$notices['failed_events'] = array(
				'severity' => 'error',
				'count'    => $failed_24h,
				'since'    => $now,
			);
		}

		if ( $down_since !== null && ( $now - $down_since ) >= self::ENGINE_DOWN_GRACE ) {
			$notices['engine_down'] = array(
				'severity'   => 'error',
				'down_since' => $down_since,
			);
		}

		if ( $smaily_down_since !== null ) {
			// An explicit refusal — the package, or the credentials — is
			// definitive: Smaily already told us the answer, and it is the same
			// answer in an hour (PRO-1686). Only the "might be a blip" case
			// keeps the grace period.
			$waited = ( $now - $smaily_down_since ) >= self::ENGINE_DOWN_GRACE;

			if ( $waited || $smaily_reason !== RefusalReason::UNREACHABLE ) {
				$notices[ self::smaily_notice_key( $smaily_reason ) ] = array(
					'severity'   => 'error',
					'down_since' => $smaily_down_since,
				);
			}
		}

		return $notices;
	}

	/** The notice key each refusal cause maps to (one message per cause). */
	private static function smaily_notice_key( string $reason ): string {
		if ( $reason === RefusalReason::PLAN_BLOCKED ) {
			return 'smaily_plan_blocked';
		}

		if ( $reason === RefusalReason::CREDENTIALS_REJECTED ) {
			return 'smaily_credentials_rejected';
		}

		return 'smaily_down';
	}

	/**
	 * Config advisory: browse tracking is enabled AND the engine is connected, but
	 * no WP Consent API is present — so the beacon is fail-closed and collects
	 * nothing (the MiuMjau/CookieYes 0-events trap, F3-50). CookieYes/Complianz/etc.
	 * register consent INTO the free "WP Consent API" plugin; without it there is no
	 * `wp_has_consent` signal. Pure so the condition is unit-testable without a DB.
	 */
	public function needs_consent_api_notice( bool $browse_enabled, bool $connected, bool $consent_api_present ): bool {
		return $browse_enabled && $connected && ! $consent_api_present;
	}

	/**
	 * Ping the engine and maintain the persisted `down_since` stamp: cleared on a
	 * successful ping (or when disconnected — no engine to be "down"), set to now
	 * on the first failed ping, kept across subsequent failures. Returns the
	 * current down_since (or null when up / disconnected).
	 *
	 * A REFUSED connection (contract §2 `403 tenant_inactive`) is not probed at
	 * all and clears the stamp: the engine is answering perfectly well, it is
	 * the account that is deactivated. Re-probing would burn a request an hour
	 * to be told the same thing, and "unreachable" is the wrong story to tell
	 * the merchant — the deactivation notice below is the true one (PRO-1893).
	 */
	private function probe_engine( int $now ): ?int {
		if ( ! $this->settings->sending_allowed() ) {
			delete_option( self::OPTION_DOWN_SINCE );
			return null;
		}

		$up = true;
		try {
			( $this->client_factory )()->ping();
		} catch ( \Throwable $e ) {
			$up = false;
		}

		if ( $up ) {
			delete_option( self::OPTION_DOWN_SINCE );
			return null;
		}

		$down_since = (int) get_option( self::OPTION_DOWN_SINCE, 0 );
		if ( $down_since <= 0 ) {
			$down_since = $now;
			update_option( self::OPTION_DOWN_SINCE, $down_since, false );
		}

		return $down_since;
	}

	/**
	 * Same down_since contract as probe_engine(), but for the Smaily email API —
	 * the pilot's OTHER sync path (contacts + welcome/abandoned-cart automations).
	 * The factory returns null when the email side isn't configured (setup wizard
	 * not finished), so an un-set-up store is never reported as "Smaily down".
	 *
	 * Also returns WHY Smaily refused (PRO-1686), so the notice can name the
	 * cause. The reason is recomputed every run and never stored: it is only
	 * true of the probe that just happened, and the moment Smaily answers again
	 * — the package restored, the credentials fixed — down_since is deleted and
	 * the notice goes with it, with nothing for the merchant to re-enter.
	 *
	 * @return array{down_since: ?int, reason: string}
	 */
	private function probe_smaily( int $now ): array {
		$client = ( $this->smaily_client_factory )();
		if ( ! $client instanceof SmailyClient ) {
			delete_option( self::OPTION_SMAILY_DOWN_SINCE );
			return array(
				'down_since' => null,
				'reason'     => RefusalReason::OK,
			);
		}

		try {
			$reason = $client->check_connection();
		} catch ( \Throwable $e ) {
			$reason = RefusalReason::UNREACHABLE;
		}

		if ( $reason === RefusalReason::OK ) {
			delete_option( self::OPTION_SMAILY_DOWN_SINCE );
			return array(
				'down_since' => null,
				'reason'     => RefusalReason::OK,
			);
		}

		$down_since = (int) get_option( self::OPTION_SMAILY_DOWN_SINCE, 0 );
		if ( $down_since <= 0 ) {
			$down_since = $now;
			update_option( self::OPTION_SMAILY_DOWN_SINCE, $down_since, false );
		}

		return array(
			'down_since' => $down_since,
			'reason'     => $reason,
		);
	}

	/**
	 * Failed rows across both durable queues — the Smaily queue and the
	 * Campaign Intelligence queue — queued in the last 24h. The one
	 * definition: the health check's `failed_events` notice, the Log page's
	 * banner (EventsEndpoint) and the Dashboard (CON-464) all read it.
	 */
	public static function failed_last_24h(): int {
		global $wpdb;

		$rec    = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		$smaily = $wpdb->prefix . EventQueue::TABLE_SUFFIX;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

	private function failed_threshold(): int {
		return (int) apply_filters( 'smaily_connect_failed_notice_threshold', self::FAILED_THRESHOLD_DEFAULT );
	}

	// --- rendering + dismissal ---------------------------------------------

	/**
	 * admin_notices callback. Renders each active, not-currently-dismissed notice
	 * as a sticky `notice-error` with a "View Event Log" link + a Dismiss link.
	 */
	public function render(): void {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return;
		}

		$notices   = $this->active_notices();
		$dismissed = (array) get_option( self::OPTION_DISMISSED, array() );
		$now       = time();
		$refused   = $this->settings->is_refused();

		if ( $refused ) {
			$this->render_tenant_inactive_notice( $dismissed, $now );
		}

		foreach ( $notices as $key => $notice ) {
			if ( $this->is_dismissed( $key, $dismissed, $now ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-error"><p>%1$s %2$s %3$s</p></div>',
				esc_html( $this->message_for( $key, $notice ) ),
				wp_kses_post( $this->log_link( __( 'View Event Log', 'smaily-connect' ) ) ),
				wp_kses_post( $this->dismiss_link( $key ) )
			);
		}

		// Config advisory (live, not cron-driven): browse tracking on + connected but
		// no WP Consent API ⇒ the beacon is fail-closed and sends nothing. Not an
		// error — a setup advisory (notice-warning), same 24h dismiss cooldown.
		$this->render_consent_advisory( $dismissed, $now, ! $refused );

		// Same kind of advisory: the saved contact-field selection is in a shape
		// this version can't read, so which fields the merchant chose is unknown.
		$this->render_sync_fields_advisory( $dismissed, $now );

		// No Smaily account connected: invite the store to start or come back.
		$this->render_invite_notice( $dismissed, $now );
	}

	/**
	 * Invitation (CON-321): no Smaily marketing account is connected, so the
	 * plugin sends nothing to Smaily and the store would otherwise hear
	 * nothing from it. "Connected" is the installation report's rule — a main
	 * marketing account, a Transactional account alone does not count — read
	 * through `ReportBuilder::main_credentials()`, never a copy of it.
	 *
	 * Where it shows (CON-351): on the Dashboard as the full banner, on the
	 * plugin's own screens not at all — the setup wizard's step 1 carries the
	 * React copy of the banner there — and on every other admin screen as
	 * the one-line notice. The banner and the notice share one dismissal:
	 * closing either hides both for 90 days; connecting an account removes
	 * them at once.
	 *
	 * @param array<string, int> $dismissed
	 */
	private function render_invite_notice( array $dismissed, int $now ): void {
		$screen = self::screen_id();
		if ( strpos( $screen, 'smaily-connect' ) !== false ) {
			return;
		}
		if ( ! $this->invitation_due( $dismissed, $now ) ) {
			return;
		}

		if ( $screen === 'dashboard' ) {
			$this->render_invite_banner();
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s %2$s | %3$s | %4$s %5$s</p></div>',
			esc_html__( 'Connect your store to Smaily to sync contacts and set up automated emails.', 'smaily-connect' ),
			wp_kses_post( $this->settings_link( 'connection', __( 'Connect your Smaily account', 'smaily-connect' ) ) ),
			wp_kses_post( $this->external_link( Constants::SIGNUP_URL, __( 'Create a Smaily account', 'smaily-connect' ) ) ),
			wp_kses_post( $this->external_link( Constants::docs_url() . '#step1', __( 'User guide to create API credentials', 'smaily-connect' ) ) ),
			wp_kses_post( $this->dismiss_link( self::INVITE_KEY ) )
		);
	}

	/**
	 * Whether the invitation is shown at all: not dismissed in the last 90
	 * days, and no Smaily marketing account connected. Decided once per
	 * request — the Dashboard's enqueue and render both ask; dismiss()
	 * forgets the answer.
	 *
	 * @param array<string, int> $dismissed
	 */
	private function invitation_due( array $dismissed, int $now ): bool {
		if ( $this->invitation_due !== null ) {
			return $this->invitation_due;
		}
		if ( $this->is_dismissed( self::INVITE_KEY, $dismissed, $now, self::INVITE_DISMISS_COOLDOWN ) ) {
			$this->invitation_due = false;
			return false;
		}

		$this->report_builder = $this->report_builder ?? new ReportBuilder();
		$this->invitation_due = $this->report_builder->main_credentials() === null;

		return $this->invitation_due;
	}

	/**
	 * The Smaily invitation banner on the Dashboard (CON-351). Printed on
	 * `admin_notices` and classed `notice`, so core's script moves it under
	 * the page heading like any notice. Its look is the stylesheet
	 * enqueue_banner_style() loads; the right-hand panel is decoration only.
	 */
	private function render_invite_banner(): void {
		$rows = array(
			array( 'mail', __( 'Welcome email', 'smaily-connect' ) ),
			array( 'cart', __( 'Abandoned cart reminder', 'smaily-connect' ) ),
			array( 'card', __( 'Payment reminder', 'smaily-connect' ) ),
		);
		?>
		<div class="notice smaily-connect-banner" role="region" aria-label="Smaily">
			<div class="smaily-connect-banner__main">
				<div class="smaily-connect-banner__head">
					<span class="smaily-connect-banner__logo" aria-hidden="true"><?php echo wp_kses( self::smaily_logo_svg(), self::SVG_ALLOWED ); ?></span>
					<h2 class="smaily-connect-banner__title">Smaily</h2>
				</div>
				<p class="smaily-connect-banner__body"><?php echo esc_html__( 'Connect your store to Smaily to sync contacts and set up automated emails.', 'smaily-connect' ); ?></p>
				<p class="smaily-connect-banner__actions">
					<a class="smaily-connect-banner__cta" href="<?php echo esc_url( admin_url( 'admin.php?page=smaily-connect-settings#connection' ) ); ?>"><?php echo esc_html__( 'Connect your Smaily account', 'smaily-connect' ); ?></a>
					<a class="smaily-connect-banner__link" href="<?php echo esc_url( Constants::SIGNUP_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Create a Smaily account', 'smaily-connect' ); ?></a>
				</p>
				<p class="smaily-connect-banner__note"><?php echo esc_html__( 'Log in to Smaily: gear icon → Integrations → Create a new user (API username and password).', 'smaily-connect' ); ?></p>
			</div>
			<div class="smaily-connect-banner__panel" aria-hidden="true">
				<p class="smaily-connect-banner__panel-label"><?php echo esc_html__( 'Smaily in action', 'smaily-connect' ); ?></p>
				<?php foreach ( $rows as $index => $row ) : ?>
					<div class="smaily-connect-banner__row smaily-connect-banner__row--<?php echo (int) $index; ?>">
						<span class="smaily-connect-banner__icon smaily-connect-banner__icon--<?php echo esc_attr( $row[0] ); ?>"><?php echo wp_kses( self::icon_svg( $row[0] ), self::SVG_ALLOWED ); ?></span>
						<span class="smaily-connect-banner__row-text"><?php echo esc_html( $row[1] ); ?></span>
						<span class="smaily-connect-banner__chip"><?php echo esc_html__( 'Automated', 'smaily-connect' ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<a class="smaily-connect-banner__dismiss" href="<?php echo esc_url( $this->dismiss_url( self::INVITE_KEY ) ); ?>"><?php echo esc_html__( 'Dismiss', 'smaily-connect' ); ?></a>
		</div>
		<?php
	}

	/**
	 * admin_enqueue_scripts: the banner's stylesheet, on the Dashboard and
	 * only while the banner shows there.
	 */
	public function enqueue_banner_style( string $hook_suffix ): void {
		if ( $hook_suffix !== 'index.php' || ! current_user_can( Constants::CAPABILITY ) ) {
			return;
		}
		if ( ! $this->invitation_due( (array) get_option( self::OPTION_DISMISSED, array() ), time() ) ) {
			return;
		}

		$relative = 'admin/css/smaily-connect-banner.css';
		$path     = SMAILY_CONNECT_PLUGIN_PATH . $relative;
		wp_enqueue_style(
			self::BANNER_STYLE_HANDLE,
			plugins_url( $relative, SMAILY_CONNECT_PLUGIN_FILE ),
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : SMAILY_CONNECT_VERSION
		);
	}

	/** The current admin screen's id, '' when WordPress has none (yet). */
	private static function screen_id(): string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return '';
		}
		$screen = get_current_screen();

		return $screen instanceof \WP_Screen ? $screen->id : '';
	}

	/** A row icon of the banner's decorative panel (static markup). */
	private static function icon_svg( string $name ): string {
		$paths = array(
			'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
			'cart' => '<path d="M3 4h2l2.4 10h10.2L20 7H6.2"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
			'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
		);

		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/** The Smaily mark — the admin menu's `gfx/icon.svg` — as the banner's logo tile. */
	private static function smaily_logo_svg(): string {
		return '<svg viewBox="0 0 30 29" width="40" height="40" focusable="false"><path fill="currentColor" d="M24.2563 0H4.8576C2.17536 0 0 2.16606 0 4.83684V24.1632C0 26.8339 2.17536 29 4.8576 29H4.88928C7.13856 28.9895 9.0288 27.4543 9.56736 25.3724C9.98976 25.7614 10.465 26.0874 10.993 26.3503C11.8589 26.7709 12.7565 26.9811 13.6858 26.9811C14.1398 26.9811 14.5939 26.9286 15.0586 26.8129C15.5232 26.7078 15.935 26.5395 16.3046 26.3187C16.6742 26.0979 16.9699 25.8245 17.2022 25.488C17.424 25.1516 17.5402 24.752 17.5402 24.2893C17.5402 23.5112 17.2445 22.9014 16.6637 22.4598C16.0829 22.0076 15.3437 21.6291 14.4672 21.3136C13.5907 20.9982 12.6298 20.6827 11.6054 20.3673C10.5811 20.0519 9.63072 19.6313 8.74368 19.095C7.8672 18.5587 7.128 17.8648 6.5472 16.992C5.9664 16.1193 5.67072 14.9732 5.67072 13.5431C5.67072 12.1657 5.93472 10.9565 6.47328 9.91552C7.01184 8.87455 7.71936 8.00181 8.61696 7.30783C9.51456 6.60334 10.5389 6.07759 11.711 5.7306C12.8726 5.38361 14.0765 5.20486 15.3226 5.20486C16.7482 5.20486 18.1315 5.41516 19.4621 5.82524C20.3914 6.10914 21.2573 6.54025 22.0704 7.09753L22.081 7.10805C22.4189 7.34989 22.7568 7.61276 23.0736 7.89666L19.2826 13.2698C19.2826 13.2698 18.2688 9.78934 13.9603 10.578C13.5274 10.6726 13.1261 10.8303 12.7459 11.0301C12.3658 11.2404 12.0595 11.5033 11.8166 11.8503C11.5738 12.1867 11.4576 12.5968 11.4576 13.0805C11.4576 13.8586 11.7427 14.4474 12.313 14.8575C12.8832 15.2676 13.6013 15.6146 14.4778 15.909C15.3437 16.2034 16.2835 16.4873 17.2867 16.7817C18.2899 17.0761 19.2192 17.4862 20.0957 18.012C20.9616 18.5482 21.6902 19.2527 22.2605 20.157C22.8307 21.0508 23.1158 22.2495 23.1158 23.7531C23.1158 25.1831 22.8518 26.4239 22.3344 27.4859C22.0598 28.0326 21.743 28.5373 21.3734 28.9895H24.2669C26.9491 28.9895 29.1245 26.8234 29.1245 24.1526V4.83684C29.1139 2.16606 26.9386 0 24.2563 0Z"/></svg>';
	}

	/**
	 * What the admin bundle needs to choose its banners (CON-350, CON-351),
	 * emitted into the boot payload as `banners`:
	 *
	 *   - smailyAccountConnected: a Smaily marketing account is connected
	 *     (the invitation's own rule, ReportBuilder::main_credentials());
	 *   - smailyPlanBlocked: that account's last Smaily answer was 227 —
	 *     its package has no API access (ConnectionHealth's stored code);
	 *   - smailyLoginUrl: the account's own Smaily address, '' without one;
	 *   - ciBannerDismissed: the Settings-top Campaign Intelligence banner
	 *     was closed in the last 30 days.
	 *
	 * @return array{smailyAccountConnected: bool, smailyPlanBlocked: bool, smailyLoginUrl: string, ciBannerDismissed: bool}
	 */
	public static function banner_boot( ?ReportBuilder $report_builder = null, ?ConnectionHealth $health = null ): array {
		$main      = ( $report_builder ?? new ReportBuilder() )->main_credentials();
		// main_credentials() returns only a set whose lower-cased, trimmed
		// subdomain matches ReportBuilder's subdomain pattern.
		$subdomain = $main !== null ? strtolower( trim( $main->subdomain ) ) : '';
		$code      = $subdomain !== '' ? ( $health ?? new ConnectionHealth() )->smaily( $subdomain )['last_error_code'] : null;
		$dismissed = (array) get_option( self::OPTION_DISMISSED, array() );

		return array(
			'smailyAccountConnected' => $main !== null,
			'smailyPlanBlocked'      => $code === RefusalReason::PLAN_BLOCKED,
			'smailyLoginUrl'         => $subdomain !== '' ? 'https://' . $subdomain . '.sendsmaily.net/' : '',
			'ciBannerDismissed'      => self::was_dismissed( self::CI_BANNER_KEY, $dismissed, time(), self::CI_BANNER_DISMISS_COOLDOWN ),
		);
	}

	private function external_link( string $url, string $label ): string {
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/** A link to the Log page, the Event Log's own page since CON-464. */
	private function log_link( string $label ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=smaily-connect-log' ) ),
			esc_html( $label )
		);
	}

	/**
	 * A link to one Settings section. Settings → Connection sends a store
	 * whose setup is unfinished to the wizard.
	 */
	private function settings_link( string $anchor, string $label ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=smaily-connect-settings#' . $anchor ) ),
			esc_html( $label )
		);
	}

	/**
	 * The engine has refused this account outright (contract §2). Says so
	 * plainly and names the only action that helps — contact Smaily — instead
	 * of the generic "unreachable, it will resume" story, which is false here:
	 * no retry, key, setup token or regenerate flow revives a deactivated
	 * tenant. Same dismissal semantics as the health-check notices.
	 *
	 * @param array<string, int> $dismissed
	 */
	private function render_tenant_inactive_notice( array $dismissed, int $now ): void {
		$key = self::TENANT_INACTIVE_KEY;
		if ( $this->is_dismissed( $key, $dismissed, $now ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%1$s %2$s</p></div>',
			esc_html__(
				'Smaily Connect: your Smaily Campaign Intelligence account has been deactivated, so no product, customer or order data is being sent to it. Contact Smaily to reactivate it. Nothing is lost in the meantime — queued data waits, and syncing resumes once you connect the reactivated account.',
				'smaily-connect'
			),
			wp_kses_post( $this->dismiss_link( $key ) )
		);
	}

	/**
	 * @param array<string, int> $dismissed
	 * @param bool               $sending_allowed The gate render() already computed —
	 *        a refused account collects nothing for a reason installing a consent
	 *        plugin cannot fix, and the deactivation notice is the one to act on
	 *        (PRO-1893).
	 */
	private function render_consent_advisory( array $dismissed, int $now, bool $sending_allowed ): void {
		$active = $this->needs_consent_api_notice(
			(bool) get_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false ),
			$sending_allowed,
			StorefrontBeacon::consent_api_present()
		);
		if ( ! $active ) {
			return;
		}

		$key = self::CONSENT_ADVISORY_KEY;
		if ( $this->is_dismissed( $key, $dismissed, $now ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s %2$s %3$s</p></div>',
			esc_html__(
				'Smaily Connect: browse tracking is on, but the WP Consent API plugin is not installed, so no browse data is being collected. Install the free plugin and connect your consent banner (CookieYes, Complianz, Real Cookie Banner, …) to it: only a visitor\'s yes in that banner counts as consent.',
				'smaily-connect'
			),
			wp_kses_post( $this->consent_api_install_link() ),
			wp_kses_post( $this->dismiss_link( $key ) )
		);
	}

	/**
	 * Config advisory: the saved contact-field selection is in a shape this
	 * version can't read (PRO-1684), so the sync falls back to every supported
	 * field and the merchant's real choice is unknown. Telling them beats both
	 * silent alternatives — syncing the bare minimum (the upgrade bug) or
	 * guessing at a selection they can't see.
	 *
	 * @param array<string, int> $dismissed
	 */
	private function render_sync_fields_advisory( array $dismissed, int $now ): void {
		if ( ! SubscriberPayloadBuilder::selection_unreadable() ) {
			return;
		}

		$key = self::SYNC_FIELDS_ADVISORY_KEY;
		if ( $this->is_dismissed( $key, $dismissed, $now ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s %2$s %3$s</p></div>',
			esc_html__(
				'Smaily Connect: your saved list of contact fields to sync could not be read, so every supported field is being synced. Open the Subscribers settings, tick the fields you want and save to fix it.',
				'smaily-connect'
			),
			wp_kses_post( $this->settings_link( 'subscribers', __( 'Open Subscribers settings', 'smaily-connect' ) ) ),
			wp_kses_post( $this->dismiss_link( $key ) )
		);
	}

	private function consent_api_install_link(): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'plugin-install.php?s=WP+Consent+API&tab=search&type=term' ) ),
			esc_html__( 'Install WP Consent API', 'smaily-connect' )
		);
	}

	/**
	 * Is this notice inside its dismiss cooldown (24h unless the notice sets its own)?
	 *
	 * @param array<string, int> $dismissed
	 */
	private function is_dismissed( string $key, array $dismissed, int $now, int $cooldown = self::DISMISS_COOLDOWN ): bool {
		return self::was_dismissed( $key, $dismissed, $now, $cooldown );
	}

	/**
	 * @param array<string, int> $dismissed
	 */
	private static function was_dismissed( string $key, array $dismissed, int $now, int $cooldown ): bool {
		$dismissed_at = isset( $dismissed[ $key ] ) ? (int) $dismissed[ $key ] : 0;
		return $dismissed_at > 0 && ( $now - $dismissed_at ) < $cooldown;
	}

	/**
	 * The health check's persisted notice set, corrected for what is true now.
	 *
	 * A deactivated account is not an outage: the probe stops running while
	 * refused, so an `engine_down` verdict can only survive from before the
	 * refusal, and telling the merchant to wait for a recovery that will never
	 * come would bury the one thing they can act on. Dropped here rather than
	 * at the point of rendering so every reader of the set sees the same
	 * corrected answer (PRO-1893).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function active_notices(): array {
		$notices = get_option( self::OPTION_NOTICES, array() );
		$notices = is_array( $notices ) ? $notices : array();

		if ( $this->settings->is_refused() ) {
			unset( $notices['engine_down'] );
		}

		return $notices;
	}

	/**
	 * admin_post handler for the Dismiss link. Stamps the dismissal (the notice's cooldown)
	 * and bounces back to the referring admin page.
	 */
	public function handle_dismiss(): void {
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';

		if ( ! current_user_can( Constants::CAPABILITY )
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::DISMISS_ACTION . '_' . $key )
		) {
			wp_die( esc_html__( 'Invalid request.', 'smaily-connect' ), '', array( 'response' => 403 ) );
		}

		$this->dismiss( $key );

		$back = wp_get_referer();
		wp_safe_redirect( $back !== false ? $back : admin_url() );
		exit;
	}

	public function dismiss( string $key ): void {
		self::record_dismissal( $key );
		$this->invitation_due = null;
	}

	/** Stamp a dismissal now — the admin-post link and the REST route both land here. */
	public static function record_dismissal( string $key ): void {
		if ( $key === '' ) {
			return;
		}
		$dismissed         = (array) get_option( self::OPTION_DISMISSED, array() );
		$dismissed[ $key ] = time();
		update_option( self::OPTION_DISMISSED, $dismissed, false );
	}

	/** @param array<string, mixed> $notice */
	private function message_for( string $key, array $notice ): string {
		if ( $key === 'failed_events' ) {
			$count = isset( $notice['count'] ) ? (int) $notice['count'] : 0;
			return sprintf(
				/* translators: %d: number of failed sync events in the last 24 hours. */
				_n(
					'Smaily Connect: %d sync event failed in the last 24 hours.',
					'Smaily Connect: %d sync events failed in the last 24 hours.',
					$count,
					'smaily-connect'
				),
				$count
			);
		}

		if ( $key === 'engine_down' ) {
			return __(
				'Smaily Connect: Smaily Campaign Intelligence has been unreachable for over an hour — sync is queued and will resume automatically when it recovers.',
				'smaily-connect'
			);
		}

		if ( $key === 'smaily_down' ) {
			return __(
				'Smaily Connect: the Smaily API has been unreachable for over an hour — contact sync and email automations are paused until the connection recovers.',
				'smaily-connect'
			);
		}

		if ( $key === 'smaily_plan_blocked' ) {
			return __(
				'Smaily Connect: Smaily is refusing every request because this account\'s package does not include API access. Contact sync and email automations stay stopped until the account is on a package that includes it — waiting will not restore them, and the credentials cannot be checked until then.',
				'smaily-connect'
			);
		}

		if ( $key === 'smaily_credentials_rejected' ) {
			return __(
				'Smaily Connect: Smaily is rejecting the saved API credentials, so contact sync and email automations have stopped. Check the subdomain, API username and password under Settings → Connection.',
				'smaily-connect'
			);
		}

		return __( 'Smaily Connect: a sync health check needs your attention.', 'smaily-connect' );
	}

	private function dismiss_link( string $key ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->dismiss_url( $key ) ),
			esc_html__( 'Dismiss', 'smaily-connect' )
		);
	}

	private function dismiss_url( string $key ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'key'    => $key,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . '_' . $key
		);
	}
}
