<?php
/**
 * Public campaign-click landing: POST /wp-json/smaily-connect/v1/land.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Settings\RecEngineSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The route the storefront scripts (`sc-landing.js`, `sc-runtime.js`) post a
 * campaign landing's values to, so the store's server sets the attribution
 * cookies with `Set-Cookie` (CON-250). Behind a full-page cache the landing
 * page never runs PHP, so the scripts used to write the cookies themselves —
 * and Safari keeps a cookie page JavaScript sets for 7 days at most (24 hours
 * after a link with tracking parameters), so the 365-day visitor token was
 * gone by the next visit. A cookie from a `Set-Cookie` header keeps its
 * lifetime. The scripts write the cookies themselves only when this request
 * fails. Same design as the Magento extension's `smaily/attribution/land`.
 *
 * The rules are LandingCapture's — one implementation: `store()` runs
 * `resolve()` (UUID rec id, the engine's `vt_` token only, the context
 * cookie rule) and writes the cookies with its names, lifetimes and
 * attributes, behind its gates (connection, the
 * `smaily_connect_capture_attribution` switch). Not gated on consent, as the
 * landing capture is not (F3-46).
 *
 * Guards, in order:
 *   1. a disconnected store answers a bare 404 before any work;
 *   2. a per-address rate limit (RequestThrottle, REMOTE_ADDR only);
 *   3. only the store's own pages: a request whose `Sec-Fetch-Site` is not
 *      `same-origin` (another site, or no browser fetch at all) gets a 403
 *      and no cookie, so a page elsewhere cannot plant a visitor token in a
 *      shopper's browser.
 * 400 when nothing posted has its shape; 204 when the cookies are set. Every
 * answer carries `Cache-Control: no-store, private`.
 */
class LandEndpoint {

	/** Neutral name — no tracker word a blocker list matches (F3-41). */
	public const ROUTE = '/land';

	/** Rate-limit fixed window, seconds. */
	public const RL_WINDOW_SECONDS = 60;

	/** Requests per connection address per window — one per campaign landing (Magento parity). */
	public const RL_MAX_PER_IP = 30;

	/** The fields the scripts post: the link's own param names, which LandingCapture::resolve() reads. */
	private const FIELDS = array(
		LandingCapture::URL_PARAM_REC_ID,
		LandingCapture::URL_PARAM_VISITOR,
		LandingCapture::URL_PARAM_CONTEXT,
	);

	private const CACHE_CONTROL = 'no-store, private';

	private RecEngineSettings $settings;

	private LandingCapture $capture;

	public function __construct( RecEngineSettings $settings, LandingCapture $capture ) {
		$this->settings = $settings;
		$this->capture  = $capture;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->settings->is_connected() ) {
			return $this->refuse( 'not_found', 404 );
		}

		if ( RequestThrottle::exceeded( 'smly_land_rl_ip_' . md5( RequestThrottle::client_ip() ), self::RL_MAX_PER_IP, self::RL_WINDOW_SECONDS ) ) {
			return $this->refuse( 'rate_limited', 429 );
		}

		if ( 'same-origin' !== (string) $request->get_header( 'sec_fetch_site' ) ) {
			return $this->refuse( 'forbidden', 403 );
		}

		$body   = $request->get_body_params();
		$params = array();
		foreach ( self::FIELDS as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$params[ $field ] = $body[ $field ];
			}
		}

		if ( array() === $this->capture->store( $params ) ) {
			return $this->refuse( 'nothing_to_store', 400 );
		}

		return $this->respond( null, 204 );
	}

	private function refuse( string $error, int $status ): WP_REST_Response {
		return $this->respond(
			array(
				'ok'    => false,
				'error' => $error,
			),
			$status
		);
	}

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function respond( ?array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );
		return $response;
	}
}
