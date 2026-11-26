<?php
/**
 * REST proxy for an automation's test send and test-mode switch (contract
 * §19–§20, CON-455).
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
use Smaily\Connect\Support\ContactLanguageResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Backs the "Send test email" button and the "Test mode" switch on each
 * Campaign Intelligence email card:
 *
 *   POST /rec-engine/automations/test-send  body: { trigger_key, email }
 *     → engine §19 with the typed address and the store's default language
 *     → 200 { ok: true, accepted_at }
 *   POST /rec-engine/automations/go-live    body: { trigger_key, live, confirmed? }
 *     → engine §20 `{live: true, confirmed: true, switched_by}` (test mode
 *       off; refused here unless `confirmed` is true) or
 *       `{live: false, switched_by}` (test mode back on). `switched_by` is
 *       the signed-in admin's WordPress user id, nothing else.
 *     → 200 { ok: true, changed, setup_state, daily_limit }
 *
 * The typed address is never stored or logged here: no option, transient,
 * queue row, exchange record or debug line. Each press is ONE engine call
 * (the client is built with one attempt): a retried §19 502 could send a
 * second email, and the contract tells the plugin not to retry it.
 *
 * Errors carry the engine's codes, never its message text (CON-301):
 *   - 503 `not_available` — no engine call may be made (`sending_allowed()`).
 *   - 403 `tenant_inactive` — a refused account, or the engine's §2 answer.
 *   - 400 `invalid_email` / `not_confirmed` — refused before the engine.
 *   - 404 `unknown_automation` — not a catalogue email (plugin and
 *     transactional emails have neither control); `not_available` for a
 *     route the engine does not offer.
 *   - 409 `not_ready` + `reasons`, `sender_refused` + `sender_state`,
 *     `go_live_blocked` + `blockers` — the engine's codes only.
 *   - 422 `validation_failed` + `fields` (the field names only).
 *   - 429 `rate_limited` + `retry_after`.
 *   - 502 `smaily_unavailable`, `api_key_rejected` or `engine_unavailable`.
 *
 * Auth: a signed-in user, a valid `wp_rest` nonce and `manage_options`.
 */
class AutomationSendsEndpoint {

	use RecEngineProxy;
	use AdminSession;

	public const ROUTE_TEST_SEND = '/rec-engine/automations/test-send';
	public const ROUTE_GO_LIVE   = '/rec-engine/automations/go-live';

	/** A §11 catalogue key; the engine answers 404 for anything it does not know. */
	private const KEY_PATTERN = '^[a-z][a-z0-9_.]{0,100}$';

	/** The shape of an engine code passed on to the admin. */
	private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

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
			self::ROUTE_TEST_SEND,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_send' ),
				'permission_callback' => array( $this, 'admin_check' ),
				'args'                => array(
					'trigger_key' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => self::KEY_PATTERN,
					),
					'email'       => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_GO_LIVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'go_live' ),
				'permission_callback' => array( $this, 'admin_check' ),
				'args'                => array(
					'trigger_key' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => self::KEY_PATTERN,
					),
					'live'        => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'confirmed'   => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);
	}

	public function test_send( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->gate();
		if ( $gate !== null ) {
			return $gate;
		}

		$email = trim( (string) $request->get_param( 'email' ) );
		if ( $email === '' || strlen( $email ) > 254 || is_email( $email ) === false ) {
			return new WP_REST_Response( array( 'error' => 'invalid_email' ), 400 );
		}

		$body     = array( 'email' => $email );
		$language = ( new ContactLanguageResolver() )->for_guest();
		if ( preg_match( '/^[a-z]{2}$/', $language ) === 1 ) {
			$body['language'] = $language;
		}

		try {
			$answer = $this->client()->automation_test_send( (string) $request->get_param( 'trigger_key' ), $body );
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'accepted_at' => isset( $answer['accepted_at'] ) && is_string( $answer['accepted_at'] ) ? $answer['accepted_at'] : '',
			),
			200
		);
	}

	public function go_live( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->gate();
		if ( $gate !== null ) {
			return $gate;
		}

		$live = (bool) $request->get_param( 'live' );
		if ( $live && $request->get_param( 'confirmed' ) !== true ) {
			return new WP_REST_Response( array( 'error' => 'not_confirmed' ), 400 );
		}

		$body = array( 'live' => $live );
		if ( $live ) {
			$body['confirmed'] = true;
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$body['switched_by'] = (string) $user_id;
		}

		try {
			$answer = $this->client()->automation_go_live( (string) $request->get_param( 'trigger_key' ), $body );
		} catch ( ApiException $e ) {
			return $this->engine_error_response( $e );
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'changed'     => ( $answer['changed'] ?? false ) === true,
				'setup_state' => isset( $answer['setup_state'] ) && is_string( $answer['setup_state'] ) ? $answer['setup_state'] : '',
				'daily_limit' => isset( $answer['daily_limit'] ) && is_int( $answer['daily_limit'] ) ? $answer['daily_limit'] : null,
			),
			200
		);
	}

	/** The answer to send before any engine call, or null when one may be made. */
	private function gate(): ?WP_REST_Response {
		if ( $this->settings->is_refused() ) {
			return new WP_REST_Response( array( 'error' => Client::ERROR_TENANT_INACTIVE ), 403 );
		}
		if ( ! $this->settings->sending_allowed()
			|| $this->settings->api_key() === ''
			|| $this->settings->base_url() === '' ) {
			return new WP_REST_Response( array( 'error' => 'not_available' ), 503 );
		}
		return null;
	}

	/**
	 * Map an engine failure onto the proxy answer: the engine's codes only,
	 * never its message, the Authorization header or the api_key.
	 */
	private function engine_error_response( ApiException $e ): WP_REST_Response {
		$status  = (int) $e->getCode();
		$code    = $e->error_code();
		$details = $e->details();

		if ( $status === 404 ) {
			return new WP_REST_Response( array( 'error' => $code === 'unknown_automation' ? $code : 'not_available' ), 404 );
		}

		if ( $status === 409 && $code === 'not_ready' ) {
			return new WP_REST_Response(
				array(
					'error'   => $code,
					'reasons' => self::codes( $details['reasons'] ?? array() ),
				),
				409
			);
		}

		if ( $status === 409 && $code === 'go_live_blocked' ) {
			return new WP_REST_Response(
				array(
					'error'    => $code,
					'blockers' => self::codes( $details['blockers'] ?? array() ),
				),
				409
			);
		}

		if ( $status === 409 && $code === 'sender_refused' ) {
			$state = self::codes( array( $details['sender_state'] ?? '' ) );
			return new WP_REST_Response(
				array(
					'error'        => $code,
					'sender_state' => $state[0] ?? '',
				),
				409
			);
		}

		if ( $status === 422 ) {
			$fields = array();
			foreach ( $e->errors() as $error ) {
				if ( is_array( $error ) && isset( $error['field'] ) && is_string( $error['field'] ) ) {
					$fields[] = $error['field'];
				}
			}
			return new WP_REST_Response(
				array(
					'error'  => 'validation_failed',
					'fields' => self::codes( $fields ),
				),
				422
			);
		}

		if ( $status === 429 ) {
			return new WP_REST_Response(
				array(
					'error'       => 'rate_limited',
					'retry_after' => $e->retry_after(),
				),
				429
			);
		}

		if ( $status === 403 && $code === Client::ERROR_TENANT_INACTIVE ) {
			return new WP_REST_Response( array( 'error' => $code ), 403 );
		}

		if ( $status === 401 ) {
			return new WP_REST_Response( array( 'error' => 'api_key_rejected' ), 502 );
		}

		if ( $status === 502 && $code === 'smaily_unavailable' ) {
			return new WP_REST_Response( array( 'error' => $code ), 502 );
		}

		return new WP_REST_Response( array( 'error' => 'engine_unavailable' ), 502 );
	}

	/**
	 * The engine codes in `$values` that have the shape of a code — the
	 * admin words each one itself and shows nothing for an unknown one.
	 *
	 * @param mixed $values
	 *
	 * @return array<int, string>
	 */
	private static function codes( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$codes = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) && preg_match( self::CODE_PATTERN, $value ) === 1 ) {
				$codes[] = $value;
			}
		}
		return array_values( array_unique( $codes ) );
	}
}
