<?php
/**
 * REST proxy for the engine's automations config API (contract §11–§13).
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
 * Three sub-routes under `/wp-json/smaily-connect/v1/rec-engine/automations/...`
 * backing the engine-triggered-automations settings UI (T2; the React layer
 * is the next sub-PR):
 *
 *   GET /rec-engine/automations/catalog   → engine §11 body as-is
 *     { triggers: [...], language_modes: [...], docs: "..." }
 *   GET /rec-engine/automations/config    → engine §12 body as-is
 *     { configs: [...] }  (rows carry read-only configured_via + updated_at)
 *   PUT /rec-engine/automations/config    body: { configs: [...] }
 *     → engine §13 body as-is: { ok: true, upserted: N }
 *     → 422 { error: validation_failed, errors: [{index?, trigger_key?,
 *       field, message}] } passed through UNCHANGED (all-or-nothing —
 *       nothing was saved; the UI binds each entry to its row/field and
 *       resubmits the whole corrected selection)
 *
 * Shared error mapping:
 *   → 503 { error: 'not_configured' | 'configuration_incomplete' } before
 *     any engine call when the rec-engine is not connected
 *   → 502 { error: 'api_key_rejected' } when the engine 401s the stored key
 *   → 502 { error: <engine code>, message } on other 4xx/5xx/network failure
 *
 * Design rules (DECISIONS F3-51):
 *   - The ENGINE is the source of truth. This proxy stores nothing — no
 *     wp_options copy, no transient/cache of the catalog or the config. The
 *     UI re-reads via GET on every open; a local copy would drift against
 *     engine-side (operator "admin") edits and against catalog changes that
 *     ship with engine deploys.
 *   - No duplicate validation. PUT forwards `configs` to the engine as-is
 *     (only a minimal is-array shape check) — the engine's Zod schema is the
 *     validator, and its indexed 422 comes back verbatim. A PHP-side copy of
 *     the rules would be a second source of truth that drifts.
 *   - The api_key never reaches the browser: this proxy decrypts the stored
 *     key server-side and forwards it as Bearer auth, mirroring
 *     RecEngineEndpoint::ping().
 *   - Fail-closed reminder for the UI layer: never send `enabled: true`
 *     without an explicit merchant action, and default `test_mode` to on
 *     (§11). Enforced engine-side per trigger; the proxy adds no hatch.
 *
 * Auth: wp_rest nonce + manage_options on all three routes. The client is
 * injected via a factory closure (api_key, base_url, endpoints) so the
 * integration tests can point it at the mock engine; note the factory takes
 * the ENDPOINTS MAP too — the automations URLs prefer the engine's
 * `automations_*` map keys and fall back to the PATH_* constants for
 * connections exchanged before contract v1.1.0 ("Map age", §1).
 */
class AutomationsEndpoint {

	public const ROUTE_CATALOG = '/rec-engine/automations/catalog';
	public const ROUTE_CONFIG  = '/rec-engine/automations/config';

	private RecEngineSettings $settings;

	/** @var callable(string $api_key, string $base_url, array<string, string> $endpoints): Client */
	private $client_factory;

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
			self::ROUTE_CATALOG,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'catalog' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_CONFIG,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put_config' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'configs' => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
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
				__( 'You do not have permission to manage Campaign Intelligence.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public function catalog( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->not_ready_response();
		if ( $gate !== null ) {
			return $gate;
		}

		try {
			$body = $this->client()->automations_catalog();
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		return new WP_REST_Response( $body, 200 );
	}

	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->not_ready_response();
		if ( $gate !== null ) {
			return $gate;
		}

		try {
			$body = $this->client()->automations_config();
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		return new WP_REST_Response( $body, 200 );
	}

	public function put_config( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->not_ready_response();
		if ( $gate !== null ) {
			return $gate;
		}

		// Minimal shape check ONLY — the engine's schema is the validator
		// (F3-51). Everything else (1..50 rows, the 8 required row fields,
		// enum/range/email rules) comes back as the engine's indexed 422.
		$configs = $request->get_param( 'configs' );
		if ( ! is_array( $configs ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_request',
					'message' => __( 'Request body must be a JSON object with a `configs` array.', 'smaily-connect' ),
				),
				400
			);
		}

		try {
			$body = $this->client()->put_automations_config( array_values( $configs ) );
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Connection gate shared by all three handlers — mirrors
	 * RecEngineEndpoint::ping(). Returns the 503 to send, or null when the
	 * connection is usable.
	 */
	private function not_ready_response(): ?WP_REST_Response {
		if ( ! $this->settings->is_connected() ) {
			return new WP_REST_Response(
				array(
					'error'   => 'not_configured',
					'message' => __( 'Smaily Campaign Intelligence is not configured. Finish setup first.', 'smaily-connect' ),
				),
				503
			);
		}

		if ( $this->settings->api_key() === '' || $this->settings->base_url() === '' ) {
			return new WP_REST_Response(
				array(
					'error'   => 'configuration_incomplete',
					'message' => __( 'Stored rec-engine configuration is incomplete. Re-run setup.', 'smaily-connect' ),
				),
				503
			);
		}

		return null;
	}

	private function client(): Client {
		return ( $this->client_factory )(
			$this->settings->api_key(),
			$this->settings->base_url(),
			$this->settings->endpoints()
		);
	}

	/**
	 * Map an engine-side failure onto the proxy response. Never includes the
	 * Authorization header / api_key — only the engine's own error surface.
	 */
	private function engine_error_response( ApiException $e ): WP_REST_Response {
		$status = (int) $e->getCode();

		if ( $status === 422 ) {
			// §13 validation reject — ALL-OR-NOTHING, nothing was written.
			// The indexed errors[] goes through unchanged so the UI can bind
			// every entry to its row/field.
			return new WP_REST_Response(
				array(
					'error'  => 'validation_failed',
					'errors' => $e->errors(),
				),
				422
			);
		}

		if ( $status === 401 ) {
			// The ENGINE rejected our stored key (revoked/rotated) — a clear
			// "key invalid" answer, distinct from WP-side auth (403) and from
			// engine-unreachable. 502 keeps the "upstream failed" semantics
			// the other proxies use.
			return new WP_REST_Response(
				array(
					'error'       => 'api_key_rejected',
					'engineError' => $e->error_code(),
					'message'     => __( 'Smaily Campaign Intelligence rejected the stored API key. Disconnect and re-run setup with a fresh setup link.', 'smaily-connect' ),
				),
				502
			);
		}

		return new WP_REST_Response(
			array(
				'error'     => $e->error_code(),
				'message'   => $e->getMessage(),
				'requestId' => $e->request_id(),
			),
			502
		);
	}
}
