<?php
/**
 * REST routes behind "Try Intelligence free" (contract §21, CON-175).
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\SetupExchange;
use Smaily\Connect\Support\Url;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A store with no engine connection starts Campaign Intelligence from the
 * plugin, without pasting a setup link:
 *
 *   GET  /rec-engine/onboarding/status
 *     → 200 { start_open, frame } — `start_open` is true only while the store
 *       has no engine connection, a Smaily marketing account is connected and
 *       the engine answers §21 "Is the start open?" with `start_open: true`.
 *       The engine's answer is kept STATUS_TTL seconds (its own max-age);
 *       any other answer, an error or a timeout counts as false. `frame`:
 *       the start window may open in a frame over this admin.
 *
 *   POST /rec-engine/onboarding/start   { display: 'frame' | 'window' }
 *     → engine §21 with the MAIN Smaily account's API login
 *     → 200 { start_url, display, engine_origin } — the admin's browser opens
 *       the one-time link at once; it is never stored or logged here.
 *     → the engine's refusals, by code only (never its message):
 *       401 `smaily_login_refused`, 403 `smaily_paid_package_required`,
 *       404 `not_found` (the start is closed), 409 `store_exists`
 *       (+ `console_url` when it is on the engine host), 400
 *       `frame_origin_not_allowed`, 429 `rate_limited`, 502 `unavailable`
 *       (Smaily or the engine did not answer), 502 `start_failed`.
 *     → 409 `already_connected`, 401 `smaily_login_refused` without a
 *       Smaily account — no engine call.
 *
 * The Smaily password goes only into the server-to-server request body: it
 * never reaches the browser, an option, a queue row or a log line. The
 * setup token the start window posts to the admin goes to the existing
 * setup-exchange route (RecEngineEndpoint) — there is no second exchange.
 *
 * Auth: a signed-in user, a valid `wp_rest` nonce in `X-WP-Nonce` (checked
 * here too, not left to cookie auth alone) and `manage_options`.
 */
class OnboardingEndpoint {

	use AdminSession;

	public const ROUTE_STATUS = '/rec-engine/onboarding/status';

	public const ROUTE_START = '/rec-engine/onboarding/start';

	/** The engine a new store starts on: the one host a connection may use (PRO-3623). */
	public const ENGINE_BASE_URL = 'https://' . SetupExchange::ENGINE_HOST;

	/** The start checks the Smaily login with one Smaily call before it answers. */
	public const START_TIMEOUT_SECONDS = 10;

	/** The engine's last status answer, '1' or '0'. */
	public const STATUS_TRANSIENT = 'smly_rec_onboarding_open';

	/** §21: the status answer carries `Cache-Control: public, max-age=300`. */
	public const STATUS_TTL = 300;

	/** A fixed-window counter: the engine takes 10 starts per 60 s per IP. */
	public const THROTTLE_TRANSIENT = 'smly_rec_onboarding_starts';

	private const THROTTLE_MAX = 10;

	private const THROTTLE_WINDOW = 60;

	/** No cache, shared or private, may keep a one-time link. */
	private const CACHE_CONTROL = 'no-store, private';

	private RecEngineSettings $settings;

	/** @var callable(): ?CredentialSet */
	private $credentials;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): ?CredentialSet $credentials    The main Smaily marketing account, or null.
	 * @param callable(): Client         $client_factory A key-less client on the engine, one attempt.
	 */
	public function __construct( RecEngineSettings $settings, callable $credentials, callable $client_factory ) {
		$this->settings       = $settings;
		$this->credentials    = $credentials;
		$this->client_factory = $client_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_STATUS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'admin_check' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_START,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'admin_check' ),
				'args'                => array(
					'display' => array(
						'type'     => 'string',
						'enum'     => array( 'frame', 'window' ),
						'required' => false,
					),
				),
			)
		);
	}

	public function status( WP_REST_Request $request ): WP_REST_Response {
		$open = ! $this->settings->is_connected()
			&& ( $this->credentials )() !== null
			&& $this->start_open();

		return new WP_REST_Response(
			array(
				'start_open' => $open,
				// The same rule as the design window (CON-288): https, and the
				// shop on the admin's origin — §21's default frame origin.
				'frame'      => EmailDesignsEndpoint::frameable( (string) home_url(), (string) admin_url() ),
			),
			200
		);
	}

	public function start( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->settings->is_connected() ) {
			return self::answer( array( 'error' => 'already_connected' ), 409 );
		}

		$account = ( $this->credentials )();
		if ( $account === null ) {
			return self::answer( array( 'error' => 'smaily_login_refused' ), 401 );
		}

		if ( RequestThrottle::exceeded( self::THROTTLE_TRANSIENT, self::THROTTLE_MAX, self::THROTTLE_WINDOW ) ) {
			return self::answer( array( 'error' => 'rate_limited' ), 429 );
		}

		$frame = $request->get_param( 'display' ) === 'frame';
		$body  = array(
			'shop_url'         => (string) home_url(),
			'platform'         => 'woocommerce',
			'smaily_subdomain' => strtolower( trim( $account->subdomain ) ),
			'smaily_username'  => $account->username,
			'smaily_password'  => $account->password,
			'language'         => self::admin_language(),
		);
		if ( $frame ) {
			$body['display'] = 'frame';
		}
		$email = self::admin_email();
		if ( $email !== '' ) {
			$body['admin_email'] = $email;
		}

		try {
			$answer = ( $this->client_factory )()->onboarding_start( $body );
		} catch ( ApiException $e ) {
			return $this->refusal( $e );
		}

		$start_url = isset( $answer['start_url'] ) && is_string( $answer['start_url'] ) ? $answer['start_url'] : '';
		if ( ! SetupExchange::is_allowed_engine_url( $start_url ) ) {
			return self::answer( array( 'error' => 'start_failed' ), 502 );
		}

		return self::answer(
			array(
				'start_url'     => $start_url,
				// A frame only when asked for and the engine says so (§21).
				'display'       => $frame && ( $answer['display'] ?? '' ) === 'frame' ? 'frame' : 'window',
				'engine_origin' => Url::origin( $start_url ),
			),
			200
		);
	}

	/**
	 * The engine's §21 status answer, kept STATUS_TTL seconds. Anything but
	 * `200 {"start_open": true}` is false.
	 */
	private function start_open(): bool {
		$cached = get_transient( self::STATUS_TRANSIENT );
		if ( $cached === '1' || $cached === '0' ) {
			return $cached === '1';
		}

		try {
			$open = ( ( $this->client_factory )()->onboarding_status()['start_open'] ?? null ) === true;
		} catch ( ApiException $e ) {
			$open = false;
		}

		set_transient( self::STATUS_TRANSIENT, $open ? '1' : '0', self::STATUS_TTL );
		return $open;
	}

	/**
	 * Map a §21 refusal onto the answer — the engine's status and code only,
	 * never its message.
	 */
	private function refusal( ApiException $e ): WP_REST_Response {
		$status = (int) $e->getCode();
		$code   = $e->error_code();

		if ( $status === 400 && $code === 'frame_origin_not_allowed' ) {
			return self::answer( array( 'error' => $code ), 400 );
		}
		if ( $status === 401 ) {
			return self::answer( array( 'error' => 'smaily_login_refused' ), 401 );
		}
		if ( $status === 403 && $code === 'smaily_paid_package_required' ) {
			return self::answer( array( 'error' => $code ), 403 );
		}
		if ( $status === 404 ) {
			// The start closed after the status was read: the button goes
			// away on the next page load too.
			set_transient( self::STATUS_TRANSIENT, '0', self::STATUS_TTL );
			return self::answer( array( 'error' => 'not_found' ), 404 );
		}
		if ( $status === 409 ) {
			$body = array( 'error' => 'store_exists' );
			if ( SetupExchange::is_allowed_engine_url( $e->console_url() ) ) {
				$body['console_url'] = $e->console_url();
			}
			return self::answer( $body, 409 );
		}
		if ( $status === 429 ) {
			return self::answer( array( 'error' => 'rate_limited' ), 429 );
		}
		if ( $status === 0 || $status >= 500 ) {
			return self::answer( array( 'error' => 'unavailable' ), 502 );
		}

		return self::answer( array( 'error' => 'start_failed' ), 502 );
	}

	/** §21 `language`: the start window in Estonian for an Estonian admin, else English. */
	private static function admin_language(): string {
		return strtolower( substr( (string) get_user_locale(), 0, 2 ) ) === 'et' ? 'et' : 'en';
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private static function answer( array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );
		return $response;
	}
}
