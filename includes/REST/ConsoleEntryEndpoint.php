<?php
/**
 * REST proxy for the engine's console entry (contract §24, CON-228).
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Backs the "Open Campaign Intelligence" button on the Settings engine
 * connection card:
 *
 *   POST /rec-engine/console-entry   (no body)
 *     → engine §24 with the SIGNED-IN admin's own email
 *     → 200 { url, expires_at } — a one-time link valid 5 minutes, which the
 *       admin's browser opens at once in the tab the click opened.
 *
 * The link and the email are never stored, logged or cached here: no option,
 * transient, queue row or F3-44 exchange; every answer carries
 * `Cache-Control: no-store, private`. One press = one engine call (the
 * Client's single-attempt mode, no retry).
 *
 * Errors (body `error`, never the api_key):
 *   - 404 `not_available` (+ `console_url` when the stored connection names
 *     the engine) — the engine answered 404: console entry is switched off,
 *     or the connection uses the store's legacy key.
 *   - 503 `not_available` — no engine call may be made (not connected).
 *   - 403 `tenant_inactive` — the store is no longer active (a refused
 *     account, or the engine's §2 answer; Client records the latter).
 *   - 502 `api_key_rejected` — the engine refused the key (401): reconnect.
 *   - 429 `rate_limited` (+ `retry_after` seconds when the engine sent one).
 *   - 400 `no_admin_email` — the WP user has no usable email.
 *   - 502 `engine_unavailable` / `invalid_engine_response` — anything else.
 *
 * Auth: a signed-in user, a valid `wp_rest` nonce in `X-WP-Nonce` (checked
 * here too, not left to cookie auth alone) and `manage_options`.
 */
class ConsoleEntryEndpoint {

	use RecEngineProxy;

	public const ROUTE = '/rec-engine/console-entry';

	/** No cache, shared or private, may keep a one-time link. */
	private const CACHE_CONTROL = 'no-store, private';

	/** §24 `email` limit. */
	private const EMAIL_MAX = 254;

	/**
	 * @param callable(string $api_key, string $base_url, array<string, string> $endpoints): Client $client_factory
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'open' ),
				'permission_callback' => array( $this, 'admin_check' ),
			)
		);
	}

	/**
	 * The admin's own session, the CSRF nonce and the capability.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_check( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! is_user_logged_in() || $nonce === '' || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'Your session has expired. Reload the page and try again.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}
		return $this->permission_check( $request );
	}

	public function open( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->settings->is_refused() ) {
			return self::answer( array( 'error' => 'tenant_inactive' ), 403 );
		}
		if ( ! $this->settings->sending_allowed()
			|| $this->settings->api_key() === ''
			|| $this->settings->base_url() === '' ) {
			return self::answer( array( 'error' => 'not_available' ), 503 );
		}

		$email = $this->admin_email();
		if ( $email === '' ) {
			return self::answer( array( 'error' => 'no_admin_email' ), 400 );
		}

		try {
			$link = $this->client()->console_entry( $email );
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		$url = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';
		if ( ! $this->is_engine_link( $url ) ) {
			return self::answer( array( 'error' => 'invalid_engine_response' ), 502 );
		}

		return self::answer(
			array(
				'url'        => $url,
				'expires_at' => isset( $link['expires_at'] ) && is_string( $link['expires_at'] ) ? $link['expires_at'] : '',
			),
			200
		);
	}

	/**
	 * The console sign-in address on the stored engine origin, or '' when the
	 * stored connection names no web origin. §24 names
	 * `https://intelligence.smaily.com/login`; the origin comes from the
	 * connection so a test engine is not sent to production.
	 */
	public static function console_url( string $base_url ): string {
		$parts = wp_parse_url( $base_url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port . '/login';
	}

	/** The signed-in admin's own email — never a customer's — or ''. */
	private function admin_email(): string {
		$user  = wp_get_current_user();
		$email = trim( (string) $user->user_email );
		return ( $email !== '' && strlen( $email ) <= self::EMAIL_MAX && is_email( $email ) !== false ) ? $email : '';
	}

	/** A web link the admin may open: https, or on the engine's own origin. */
	private function is_engine_link( string $url ): bool {
		return stripos( $url, 'https://' ) === 0
			|| strpos( $url, rtrim( $this->settings->base_url(), '/' ) . '/' ) === 0;
	}

	/**
	 * Map an engine failure onto the proxy answer — the engine's status only,
	 * never its message, the Authorization header or the api_key.
	 */
	private function engine_error_response( ApiException $e ): WP_REST_Response {
		$status = (int) $e->getCode();

		if ( $status === 404 ) {
			$body        = array( 'error' => 'not_available' );
			$console_url = self::console_url( $this->settings->base_url() );
			if ( $console_url !== '' ) {
				$body['console_url'] = $console_url;
			}
			return self::answer( $body, 404 );
		}

		if ( $status === 401 ) {
			return self::answer( array( 'error' => 'api_key_rejected' ), 502 );
		}

		if ( $status === 403 && $e->error_code() === Client::ERROR_TENANT_INACTIVE ) {
			return self::answer( array( 'error' => 'tenant_inactive' ), 403 );
		}

		if ( $status === 429 ) {
			return self::answer(
				array(
					'error'       => 'rate_limited',
					'retry_after' => $e->retry_after(),
				),
				429
			);
		}

		return self::answer( array( 'error' => 'engine_unavailable' ), 502 );
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
