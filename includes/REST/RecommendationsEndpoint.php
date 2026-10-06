<?php
/**
 * Public storefront recommendations: GET /wp-json/smaily-connect/v1/recommendations.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Integrations\WooCommerce\StorefrontRecommendations;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Support\MarketingConsent;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The route `sc-recs.js` asks after the page has loaded, for the cards the
 * `smaily/recommendations` block and shortcode leave an empty container for
 * (PRO-3835). It answers `{html}` — the cards StorefrontRecommendations
 * renders server-side and escapes, or '' — so the cards look the same as
 * the server-rendered ones did and the browser builds no markup.
 *
 * Public, because shoppers call it. Who the answer is for comes from server
 * state only, never from the request: the logged-in shopper from the
 * WordPress `logged_in` auth cookie (validated directly, as
 * BeaconEndpoint::resolve_logged_in_email() does — a page-embedded REST nonce
 * would be shared under full-page caching), a guest from the engine's visitor
 * cookie. The engine key stays on the server (§15).
 *
 * Guards, in order:
 *   1. a store that may not call the engine (`sending_allowed()`) answers a
 *      bare 404 before any work;
 *   2. a per-address rate limit (the RequestThrottle the browse relay uses),
 *      because every miss in the per-shopper cache spends an engine call;
 *   3. a request another site makes (`Sec-Fetch-Site: cross-site` /
 *      `same-site`) gets an empty answer: WordPress sends credentialed CORS
 *      headers for any origin, so without this a page elsewhere could read a
 *      shopper's recommendations with the shopper's own cookies;
 *   4. a guest's visitor token is passed on only with the guest's marketing
 *      consent (MarketingConsent), the same rule `sc-recs.js` applies.
 * Every answer carries `Cache-Control: no-store, private`: it belongs to one
 * shopper and must never be kept by a shared cache.
 */
class RecommendationsEndpoint {

	/** Neutral name — no tracker word a blocker list matches (F3-41). */
	public const ROUTE = '/recommendations';

	/** Rate-limit fixed window, seconds. */
	public const RL_WINDOW_SECONDS = 60;

	/** Requests per connection address per window — one per page view with the block. */
	public const RL_MAX_PER_IP = 120;

	private const CACHE_CONTROL = 'no-store, private';

	private RecEngineSettings $settings;

	/** @var callable():StorefrontRecommendations */
	private $recommendations;

	/**
	 * @param callable():StorefrontRecommendations $recommendations Built on demand, so the
	 *        route costs nothing on the requests it refuses.
	 */
	public function __construct( RecEngineSettings $settings, callable $recommendations ) {
		$this->settings        = $settings;
		$this->recommendations = $recommendations;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->settings->sending_allowed() ) {
			return $this->respond(
				array(
					'ok'    => false,
					'error' => 'not_found',
				),
				404
			);
		}

		if ( RequestThrottle::exceeded( 'smly_recs_rl_ip_' . md5( RequestThrottle::client_ip() ), self::RL_MAX_PER_IP, self::RL_WINDOW_SECONDS ) ) {
			return $this->respond(
				array(
					'ok'    => false,
					'error' => 'rate_limited',
				),
				429
			);
		}

		$site = (string) $request->get_header( 'sec_fetch_site' );
		if ( 'cross-site' === $site || 'same-site' === $site ) {
			return $this->respond( array( 'html' => '' ), 200 );
		}

		// A guest is asked about only with their marketing yes, checked here on
		// the server too — not only in `sc-recs.js` (PRO-3857). A logged-in
		// shopper is named by the account and gated by the profiling check.
		$user_id = $this->logged_in_user_id();
		$token   = ( $user_id > 0 || $this->marketing_consent_given() ) ? $this->visitor_token() : '';

		$html = ( $this->recommendations )()->cards( $user_id, $token );

		return $this->respond( array( 'html' => $html ), 200 );
	}

	/**
	 * Whether the shopper gave an explicit marketing yes. A seam so tests can
	 * answer it: defining the WP Consent API functions in a test would leak
	 * into every later test of the process.
	 */
	protected function marketing_consent_given(): bool {
		return MarketingConsent::given();
	}

	/**
	 * The logged-in shopper from the `logged_in` auth cookie, 0 for a guest.
	 * The REST dispatch never authenticates this public route, so the
	 * current-user state would always be 0. Protected so tests can double it.
	 */
	protected function logged_in_user_id(): int {
		return (int) wp_validate_auth_cookie( '', 'logged_in' );
	}

	/**
	 * The engine-issued visitor token from its first-party cookie (name from
	 * the engine config, default `smaily_rec_uid`); its shape is checked by
	 * StorefrontRecommendations before anything is sent.
	 */
	private function visitor_token(): string {
		$config = $this->settings->config();
		$name   = isset( $config['tracking_cookie_name'] ) && '' !== (string) $config['tracking_cookie_name']
			? (string) $config['tracking_cookie_name']
			: 'smaily_rec_uid';
		if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function respond( array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );
		return $response;
	}
}
