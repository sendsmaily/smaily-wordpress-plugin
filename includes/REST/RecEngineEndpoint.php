<?php
/**
 * REST endpoints for the rec-engine connect / disconnect / ping cycle.
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
use Smaily\Connect\Smaily\RecEngine\ExchangeResult;
use Smaily\Connect\Smaily\RecEngine\SetupExchange;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Three sub-routes under `/wp-json/smaily-connect/v1/rec-engine/...`:
 *
 *   POST /rec-engine/setup-exchange  body: { setup_url, [base_url] }
 *     → 200 { connected: true, tenantName, tenantId, engineVersion }
 *     → 400 { error: 'token_expired_or_used', regenerateUrl }
 *     → 400 { error: 'token_not_found' }
 *     → 502 { error: 'engine_unreachable', reason }
 *
 *   POST /rec-engine/ping            body: empty
 *     → 200 { ok: true, engineVersion, tenantStatus }
 *     → 503 { error: 'not_configured' } if not yet connected
 *     → 502 { error: 'engine_unreachable' } / propagated 4xx
 *
 *   POST /rec-engine/disconnect      body: empty
 *     → 200 { disconnected: true }
 *
 * Auth: wp_rest nonce + manage_options on all three. The api_key
 * stays server-side: setup-exchange writes the encrypted blob to
 * wp_options; ping reads it back and proxies to the engine; no
 * Authorization header is ever exposed to the React layer.
 *
 * The exchange + client classes are injected via closures so the
 * integration tests can stand up a mock engine without monkey-
 * patching wp_remote_post. Bootstrap wires production factories.
 */
class RecEngineEndpoint {

	public const ROUTE_PREFIX = '/rec-engine';

	private RecEngineSettings $settings;

	/** @var callable(): SetupExchange */
	private $exchange_factory;

	/** @var callable(string $api_key, string $base_url): Client */
	private $client_factory;

	/**
	 * @param callable(): SetupExchange                              $exchange_factory
	 * @param callable(string $api_key, string $base_url): Client    $client_factory
	 */
	public function __construct(
		RecEngineSettings $settings,
		callable $exchange_factory,
		callable $client_factory
	) {
		$this->settings         = $settings;
		$this->exchange_factory = $exchange_factory;
		$this->client_factory   = $client_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/setup-exchange',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setup_exchange' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'setup_url' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/ping',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disconnect' ),
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
				__( 'You do not have permission to manage Campaign Intelligence.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public function setup_exchange( WP_REST_Request $request ): WP_REST_Response {
		$setup_url = (string) $request->get_param( 'setup_url' );
		$parsed    = SetupExchange::parse_setup_url( $setup_url );

		if ( $parsed['token'] === '' ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'error'     => 'invalid_setup_url',
					'message'   => __( 'Paste the full setup URL from your Smaily admin (it ends in /setup/<token>).', 'smaily-connect' ),
				),
				400
			);
		}

		// If the merchant pasted only a token (no URL), they MUST
		// also have a base_url stored from a previous attempt OR send
		// one explicitly. We don't ship a default in production —
		// hard-coding the staging URL would break the day Erkki rotates
		// the rec-engine deploy.
		$base = $parsed['base'] !== ''
			? $parsed['base']
			: (string) $request->get_param( 'base_url' );

		if ( $base === '' ) {
			return new WP_REST_Response(
				array(
					'connected' => false,
					'error'     => 'invalid_setup_url',
					'message'   => __( 'Setup URL must include the engine host (e.g. https://<host>/setup/<token>).', 'smaily-connect' ),
				),
				400
			);
		}

		$result = ( $this->exchange_factory )()->exchange( $parsed['token'], $base );

		switch ( $result->kind ) {
			case ExchangeResult::KIND_SUCCESS:
				$this->settings->store( $result );
				return new WP_REST_Response(
					array(
						'connected'     => true,
						'tenantName'    => $result->tenant_name,
						'tenantId'      => $result->tenant_id,
						'engineVersion' => $result->engine_version,
						'baseUrl'       => $result->engine_base_url,
						'issuedAt'      => $result->issued_at,
					),
					200
				);

			case ExchangeResult::KIND_TOKEN_EXPIRED:
				return new WP_REST_Response(
					array(
						'connected'     => false,
						'error'         => 'token_expired_or_used',
						'message'       => __( 'This setup link has already been used. Ask the engine administrator to generate a new one.', 'smaily-connect' ),
						'regenerateUrl' => $result->regenerate_url,
					),
					400
				);

			case ExchangeResult::KIND_TOKEN_NOT_FOUND:
				return new WP_REST_Response(
					array(
						'connected' => false,
						'error'     => 'token_not_found',
						'message'   => __( 'Setup token not recognised. Verify the URL is correct.', 'smaily-connect' ),
					),
					400
				);

			case ExchangeResult::KIND_ENGINE_UNREACHABLE:
			default:
				return new WP_REST_Response(
					array(
						'connected' => false,
						'error'     => 'engine_unreachable',
						'message'   => $result->reason !== ''
							? $result->reason
							: __( 'Smaily Campaign Intelligence did not respond. Try again in a few minutes.', 'smaily-connect' ),
					),
					502
				);
		}
	}

	public function ping( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->settings->is_connected() ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => 'not_configured',
					'message' => __( 'Smaily Campaign Intelligence is not configured. Finish setup first.', 'smaily-connect' ),
				),
				503
			);
		}

		$api_key  = $this->settings->api_key();
		$base_url = $this->settings->base_url();
		if ( $api_key === '' || $base_url === '' ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => 'configuration_incomplete',
					'message' => __( 'Stored rec-engine configuration is incomplete. Re-run setup.', 'smaily-connect' ),
				),
				503
			);
		}

		$client = ( $this->client_factory )( $api_key, $base_url );
		try {
			$result = $client->ping();
		} catch ( ApiException $e ) {
			return new WP_REST_Response(
				array(
					'ok'        => false,
					'error'     => $e->error_code(),
					'message'   => $e->getMessage(),
					'requestId' => $e->request_id(),
				),
				502
			);
		}

		return new WP_REST_Response(
			array(
				'ok'            => isset( $result['ok'] ) ? (bool) $result['ok'] : true,
				'engineVersion' => isset( $result['engine_version'] ) ? (string) $result['engine_version'] : '',
				'tenantStatus'  => isset( $result['tenant_status'] ) ? (string) $result['tenant_status'] : '',
				'serverTime'    => isset( $result['server_time'] ) ? (string) $result['server_time'] : '',
			),
			200
		);
	}

	public function disconnect( WP_REST_Request $request ): WP_REST_Response {
		$this->settings->disconnect();
		return new WP_REST_Response(
			array( 'disconnected' => true ),
			200
		);
	}
}
