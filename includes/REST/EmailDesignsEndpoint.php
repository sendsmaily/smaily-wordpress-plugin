<?php
/**
 * REST proxy for the engine's email designs (contract §22–§23).
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\EmailDesignWorkflows;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Backs the "Edit design" button on every email card (CON-173):
 *
 *   GET  /rec-engine/emails/designs      → engine §23 body as-is
 *     { emails: [...] }; the Smaily workflow ids in it are stored for the
 *     send paths (EmailDesignWorkflows). The admin reads it when the email
 *     settings open and when the store returns from the design page.
 *   POST /rec-engine/emails/design-link  body: { email_key, return_url? }
 *     → engine §22 { url, expires_at } — a one-time link the admin opens at
 *     once in a new tab. Never stored or logged here.
 *
 * Errors: 503 { error: 'not_available' } before any engine call unless the
 * engine may be called (`sending_allowed()`); a 404 from the engine is
 * 404 { error: 'unknown_email' } or, for a route the engine does not offer
 * yet, 404 { error: 'not_available' }; the rest maps as in
 * AutomationsEndpoint. The admin shows no button when the list cannot be
 * read. The api_key never reaches the browser.
 *
 * Auth: wp_rest nonce + manage_options, as AutomationsEndpoint.
 */
class EmailDesignsEndpoint {

	use RecEngineProxy;

	public const ROUTE_DESIGNS     = '/rec-engine/emails/designs';
	public const ROUTE_DESIGN_LINK = '/rec-engine/emails/design-link';

	/** §17 `return_url` limit, which §22 takes over. */
	private const RETURN_URL_MAX = 2048;

	private EmailDesignWorkflows $workflows;

	/**
	 * @param callable(string $api_key, string $base_url, array<string, string> $endpoints): Client $client_factory
	 */
	public function __construct( RecEngineSettings $settings, EmailDesignWorkflows $workflows, callable $client_factory ) {
		$this->settings       = $settings;
		$this->workflows      = $workflows;
		$this->client_factory = $client_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_DESIGNS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'designs' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_DESIGN_LINK,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'design_link' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'email_key'  => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z][a-z0-9_.]{0,100}$',
					),
					'return_url' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	public function designs( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->available() ) {
			return $this->not_available( 503 );
		}

		try {
			$body = $this->client()->email_designs();
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		if ( isset( $body['emails'] ) && is_array( $body['emails'] ) ) {
			$this->workflows->remember( $body['emails'] );
		}

		return new WP_REST_Response( $body, 200 );
	}

	public function design_link( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->available() ) {
			return $this->not_available( 503 );
		}

		$body       = array();
		$return_url = (string) $request->get_param( 'return_url' );
		if ( self::allowed_return_url( $return_url, (string) get_site_url() ) ) {
			$body['return_url'] = $return_url;
		}
		$language = $this->admin_language();
		if ( $language !== '' ) {
			$body['language'] = $language;
		}

		try {
			$link = $this->client()->email_design_link( (string) $request->get_param( 'email_key' ), $body );
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		$url = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';
		if ( ! $this->is_engine_link( $url ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_engine_response' ), 502 );
		}

		$response = new WP_REST_Response(
			array(
				'url'        => $url,
				'expires_at' => isset( $link['expires_at'] ) ? (string) $link['expires_at'] : '',
			),
			200
		);
		// A one-time link: no cache may keep it.
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Whether the engine would take `$url` as the design page's way back
	 * (§17): https, at most 2048 characters, on the host of the site URL the
	 * plugin sent at connection time (`plugin_info.site_url`). Anything else
	 * is left out of the request rather than refused by the engine.
	 */
	public static function allowed_return_url( string $url, string $site_url ): bool {
		if ( $url === '' || strlen( $url ) > self::RETURN_URL_MAX ) {
			return false;
		}
		$parts     = wp_parse_url( $url );
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

		return is_array( $parts )
			&& ( $parts['scheme'] ?? '' ) === 'https'
			&& isset( $parts['host'] )
			&& is_string( $site_host )
			&& strtolower( $parts['host'] ) === strtolower( $site_host );
	}

	private function available(): bool {
		return $this->settings->sending_allowed()
			&& $this->settings->api_key() !== ''
			&& $this->settings->base_url() !== '';
	}

	private function not_available( int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'error' => 'not_available' ), $status );
	}

	/** The admin's language as an ISO 639-1 code, or '' when it has none. */
	private function admin_language(): string {
		$code = strtolower( (string) strtok( (string) get_user_locale(), '_' ) );
		return preg_match( '/^[a-z]{2}$/', $code ) === 1 ? $code : '';
	}

	/** A web link the admin may open: https, or on the engine's own origin. */
	private function is_engine_link( string $url ): bool {
		return stripos( $url, 'https://' ) === 0
			|| strpos( $url, rtrim( $this->settings->base_url(), '/' ) . '/' ) === 0;
	}

	/**
	 * Map an engine failure onto the proxy answer — the engine's own error
	 * code only, never the Authorization header or the api_key.
	 */
	private function engine_error_response( ApiException $e ): WP_REST_Response {
		$status = (int) $e->getCode();

		if ( $status === 404 ) {
			// `unknown_email` names a key the store cannot design; any other
			// 404 is a route the engine does not offer yet (§1).
			return $e->error_code() === 'unknown_email'
				? new WP_REST_Response( array( 'error' => 'unknown_email' ), 404 )
				: $this->not_available( 404 );
		}

		if ( $status === 422 ) {
			return new WP_REST_Response(
				array(
					'error'  => 'validation_failed',
					'errors' => $e->errors(),
				),
				422
			);
		}

		if ( $status === 401 ) {
			return new WP_REST_Response( array( 'error' => 'api_key_rejected' ), 502 );
		}

		return new WP_REST_Response(
			array(
				'error'   => $e->error_code(),
				'message' => $e->getMessage(),
			),
			502
		);
	}
}
