<?php
/**
 * Reads + writes rec-engine tenant configuration in wp_options.
 *
 * @package Smaily\Connect\Settings
 */

declare(strict_types=1);

namespace Smaily\Connect\Settings;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\ExchangeResult;

/**
 * Storage + accessor for the rec-engine `POST /setup/exchange` response.
 *
 * The exchange returns a bundle that the plugin proxies (api_key never
 * lands in the browser; cookie / endpoint config drives later
 * sub-PRs). This class is the canonical place to read those values
 * back out — RecEngineEndpoint::ping(), the Client constructor in
 * downstream sub-PRs, and EnvDetector::saved_settings() all flow
 * through here.
 *
 * Storage scheme:
 *
 *   smly_rec_connected         bool       autoload=true   gate flag
 *   smly_rec_api_key           string     autoload=false  Cypher-encrypted
 *   smly_rec_engine_base_url   string     autoload=false
 *   smly_rec_engine_version    string     autoload=false
 *   smly_rec_tenant_id         string     autoload=false
 *   smly_rec_tenant_name       string     autoload=false
 *   smly_rec_endpoints         json       autoload=false  endpoint url map
 *   smly_rec_config            json       autoload=false  cookie names + TTLs + rate limits
 *   smly_rec_issued_at         string     autoload=false  ISO 8601
 *
 * autoload=false on the api_key (and everything except the gate)
 * keeps the encrypted secret out of the alloptions cache that lands
 * in every page request — small perf win, small surface-reduction
 * win. The merchant-facing UI only ever reads `connected` + tenant
 * display info on first paint; the api_key is re-fetched only when
 * a Client request actually needs it.
 *
 * Non-final: keeps the class testable through subclass-as-double in
 * unit tests, mirroring Settings\Credentials and Smaily\Client.
 */
class RecEngineSettings {

	public const OPTION_CONNECTED      = 'smly_rec_connected';
	public const OPTION_API_KEY        = 'smly_rec_api_key';
	public const OPTION_BASE_URL       = 'smly_rec_engine_base_url';
	public const OPTION_ENGINE_VERSION = 'smly_rec_engine_version';
	public const OPTION_TENANT_ID      = 'smly_rec_tenant_id';
	public const OPTION_TENANT_NAME    = 'smly_rec_tenant_name';
	public const OPTION_ENDPOINTS      = 'smly_rec_endpoints';
	public const OPTION_CONFIG         = 'smly_rec_config';
	public const OPTION_ISSUED_AT      = 'smly_rec_issued_at';

	public function is_connected(): bool {
		return (bool) get_option( self::OPTION_CONNECTED, false );
	}

	public function api_key(): string {
		$encrypted = (string) get_option( self::OPTION_API_KEY, '' );
		if ( $encrypted === '' ) {
			return '';
		}
		if ( ! class_exists( '\\Smaily_Connect\\Includes\\Cypher' ) ) {
			// Legacy bootstrap missing — same defensive bail as Settings\Credentials.
			return '';
		}
		return (string) \Smaily_Connect\Includes\Cypher::decrypt( $encrypted );
	}

	public function base_url(): string {
		return (string) get_option( self::OPTION_BASE_URL, '' );
	}

	public function engine_version(): string {
		return (string) get_option( self::OPTION_ENGINE_VERSION, '' );
	}

	public function tenant_id(): string {
		return (string) get_option( self::OPTION_TENANT_ID, '' );
	}

	public function tenant_name(): string {
		return (string) get_option( self::OPTION_TENANT_NAME, '' );
	}

	/**
	 * @return array<string, string>
	 */
	public function endpoints(): array {
		$raw = (string) get_option( self::OPTION_ENDPOINTS, '' );
		if ( $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$raw = (string) get_option( self::OPTION_CONFIG, '' );
		if ( $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public function issued_at(): string {
		return (string) get_option( self::OPTION_ISSUED_AT, '' );
	}

	/**
	 * Persist a successful exchange result. Caller is expected to
	 * pass only ExchangeResult instances with kind=success — other
	 * kinds carry no api_key and would corrupt the stored state.
	 */
	public function store( ExchangeResult $result ): void {
		if ( $result->kind !== ExchangeResult::KIND_SUCCESS ) {
			return;
		}

		update_option( self::OPTION_API_KEY, $this->encrypt( $result->api_key ), false );
		update_option( self::OPTION_BASE_URL, $result->engine_base_url, false );
		update_option( self::OPTION_ENGINE_VERSION, $result->engine_version, false );
		update_option( self::OPTION_TENANT_ID, $result->tenant_id, false );
		update_option( self::OPTION_TENANT_NAME, $result->tenant_name, false );
		update_option( self::OPTION_ENDPOINTS, (string) wp_json_encode( $result->endpoints ), false );
		update_option( self::OPTION_CONFIG, (string) wp_json_encode( $result->config ), false );
		update_option( self::OPTION_ISSUED_AT, $result->issued_at, false );
		// connected is the only autoloaded flag — boot payload reads
		// it on every admin page, so the alloptions cache makes the
		// gate cheap.
		update_option( self::OPTION_CONNECTED, true, true );
	}

	/**
	 * Wipe every rec-engine option so the next boot reverts to
	 * not-connected. Called by the "Disconnect" button in Step 4.
	 *
	 * Does NOT call the engine's revoke endpoint (no such endpoint
	 * exists in v1.0 of the contract). The api_key stays valid on
	 * the engine side until manually rotated; the plugin just stops
	 * sending it.
	 */
	public function disconnect(): void {
		$keys = array(
			self::OPTION_CONNECTED,
			self::OPTION_API_KEY,
			self::OPTION_BASE_URL,
			self::OPTION_ENGINE_VERSION,
			self::OPTION_TENANT_ID,
			self::OPTION_TENANT_NAME,
			self::OPTION_ENDPOINTS,
			self::OPTION_CONFIG,
			self::OPTION_ISSUED_AT,
		);
		foreach ( $keys as $key ) {
			delete_option( $key );
		}
	}

	private function encrypt( string $plain ): string {
		if ( $plain === '' ) {
			return '';
		}
		if ( class_exists( '\\Smaily_Connect\\Includes\\Cypher' ) ) {
			return (string) \Smaily_Connect\Includes\Cypher::encrypt( $plain );
		}
		// Same defensive fallback as SettingsEndpoint::encrypt_password —
		// production callers always have Cypher available because
		// smaily-connect.php's require chain runs first.
		return $plain;
	}
}
