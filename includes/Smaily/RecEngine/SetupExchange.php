<?php
/**
 * One-shot `POST /setup/exchange` round-trip against the rec-engine.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a freshly-pasted setup URL (or bare token) into a configured
 * tenant. Contract §7.1: the token is one-time use; on the engine
 * side a success-response marks it `used_at=NOW()` and any later
 * exchange returns 410. The plugin keeps the api_key encrypted in
 * wp_options and the token is dropped from memory immediately.
 *
 * URL parsing: the engine renders setup URLs as
 *   https://<engine_host>/setup/<token>
 * — the host doubles as the engine_base_url (no separate
 * `base_url` field exists in v1.0 of the contract because pilot
 * deploys share the engine + setup-page domain). parse_setup_url()
 * splits these so the React layer can just paste the whole URL.
 *
 * Why a dedicated class rather than inlining into the REST endpoint:
 * the exchange has enough branching (URL parse, HTTP error mapping,
 * engine-unreachable, body-not-JSON) that pulling it into a
 * single-responsibility unit keeps RecEngineEndpoint readable.
 * PHPUnit-wise we can also fake `do_exchange()` in the endpoint test
 * without standing up a mock server when we only care about the
 * REST layer.
 */
class SetupExchange {

	/**
	 * Plugin-info payload echoed back to the engine for the audit log
	 * (`tenant_setup_tokens.used_from_plugin` per contract §7.1).
	 * Builds at runtime so the WP / WC version numbers reflect the
	 * live install.
	 *
	 * @return array<string, string>
	 */
	public static function build_plugin_info(): array {
		global $wp_version;

		$info = array(
			'name'             => 'smaily-connect',
			'version'          => defined( 'SMAILY_CONNECT_VERSION' ) ? (string) SMAILY_CONNECT_VERSION : '0.0.0',
			'platform'         => 'wordpress',
			'platform_version' => is_string( $wp_version ?? null ) ? (string) $wp_version : '',
			'site_url'         => function_exists( 'get_site_url' ) ? (string) get_site_url() : '',
		);

		if ( class_exists( '\\WooCommerce' ) ) {
			$info['ecommerce_platform'] = 'woocommerce';
			if ( defined( '\\WC_VERSION' ) ) {
				$info['ecommerce_platform_version'] = (string) \WC_VERSION;
			}
		}

		return $info;
	}

	/**
	 * Split a pasted setup URL into (base_url, token).
	 *
	 * Accepts:
	 *   - full URL: "https://intelligence.smaily.com/setup/abc123"
	 *   - bare token: "abc123" → returns ['base' => '', 'token' => 'abc123']
	 *
	 * @return array{base: string, token: string}
	 */
	public static function parse_setup_url( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array(
				'base'  => '',
				'token' => '',
			);
		}

		// Bare token — no protocol, no slash.
		if ( strpos( $raw, '/' ) === false && strpos( $raw, '://' ) === false ) {
			return array(
				'base'  => '',
				'token' => $raw,
			);
		}

		$parsed = wp_parse_url( $raw );
		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
			return array(
				'base'  => '',
				'token' => '',
			);
		}

		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		// Expected form: /setup/<token>
		if ( ! preg_match( '#^/setup/([A-Za-z0-9_\-]+)/?$#', $path, $m ) ) {
			return array(
				'base'  => '',
				'token' => '',
			);
		}

		$base = $parsed['scheme'] . '://' . $parsed['host'];
		if ( isset( $parsed['port'] ) ) {
			$base .= ':' . $parsed['port'];
		}

		return array(
			'base'  => $base,
			'token' => $m[1],
		);
	}

	/**
	 * Run the exchange. Returns a discriminated ExchangeResult so
	 * the caller can branch on `kind` without re-parsing HTTP codes.
	 *
	 * Network timeout is set to 10 seconds — the contract advertises
	 * setup as a fast operation, and a long timeout here just blocks
	 * the merchant's "Connect" click waiting for an already-down
	 * engine.
	 */
	public function exchange( string $setup_token, string $engine_base_url ): ExchangeResult {
		if ( $setup_token === '' || $engine_base_url === '' ) {
			return ExchangeResult::engine_unreachable( 'Setup URL is incomplete (missing token or engine host).' );
		}

		$payload = array(
			'setup_token' => $setup_token,
			'plugin_info' => self::build_plugin_info(),
		);

		$response = wp_remote_post(
			rtrim( $engine_base_url, '/' ) . Client::PATH_SETUP_EXCHANGE,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => sprintf(
						'SmailyConnect-WooPlugin/%s',
						defined( 'SMAILY_CONNECT_VERSION' ) ? (string) SMAILY_CONNECT_VERSION : '0.0.0'
					),
				),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return ExchangeResult::engine_unreachable( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return ExchangeResult::engine_unreachable(
				sprintf( 'Engine returned HTTP %d with a non-JSON body.', $status )
			);
		}

		switch ( $status ) {
			case 200:
				return ExchangeResult::success( $json );

			case 410:
				$regen = isset( $json['regenerate_url'] ) ? (string) $json['regenerate_url'] : '';
				return ExchangeResult::token_expired( $regen );

			case 404:
				return ExchangeResult::token_not_found();

			default:
				return ExchangeResult::engine_unreachable(
					sprintf(
						'Engine returned HTTP %d (%s).',
						$status,
						isset( $json['error'] ) ? (string) $json['error'] : 'unknown'
					)
				);
		}
	}
}
