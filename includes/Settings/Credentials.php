<?php
/**
 * Reads Smaily credentials for a given account key.
 *
 * @package Smaily\Connect\Settings
 */

declare(strict_types=1);

namespace Smaily\Connect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Credential resolver — gives Smaily\Connect\* callers a single place to ask
 * "what credentials should account X use?" without each call site having to
 * know about the multilingual-mode storage shape or the legacy decryption
 * pipeline.
 *
 * Storage layout (backward-compatible upgrade strategy ratified with Erkki
 * for sub-PR 5.A):
 *
 *   - Account 'default' lives in the legacy `smaily_connect_api_credentials`
 *     option, the same row the upstream Smaily_Connect\Includes\Options
 *     class has populated since 1.x. Reading from that option keeps Mode B
 *     and Mode C (one credential set per site) running unchanged after
 *     a BETA upgrade, with no Settings UI changes required.
 *
 *   - Mode A account keys (e.g. 'et', 'en') live in per-language options
 *     `smly_plus_credentials_{key}`. These are populated by the wizard /
 *     Settings UI in sub-PR 6 when the user picks Mode A. The shape mirrors
 *     the legacy row (subdomain + username + encrypted password) so the
 *     decrypt path is shared.
 *
 * Password decryption goes through Smaily_Connect\Includes\Cypher::decrypt
 * — the same routine that encrypted on save. We deliberately do NOT copy
 * the algorithm into the new namespace; if upstream rotates encryption,
 * we ride that change without a fork.
 *
 * Note: deliberately NOT declared final so PHPUnit can mock it for the
 * Bootstrap dependency-injection tests. Same rationale as Smaily\Client.
 */
class Credentials {

	public const DEFAULT_ACCOUNT_KEY  = 'default';
	public const LEGACY_OPTION_KEY    = 'smaily_connect_api_credentials';
	public const PHASE2_OPTION_PREFIX = 'smly_plus_credentials_';

	/**
	 * Fetch the credentials for a given account.
	 *
	 * Returns null when the option is empty or when decryption produced an
	 * empty password — both indicate "credentials not yet configured" and
	 * the caller should not attempt an API request.
	 */
	public function get( string $account_key = self::DEFAULT_ACCOUNT_KEY ): ?CredentialSet {
		$option_key = $this->resolve_option_key( $account_key );
		$row        = get_option( $option_key, array() );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$subdomain = isset( $row['subdomain'] ) ? (string) $row['subdomain'] : '';
		$username  = isset( $row['username'] ) ? (string) $row['username'] : '';
		$password  = $this->decrypt_password( isset( $row['password'] ) ? (string) $row['password'] : '' );

		if ( $subdomain === '' && $username === '' && $password === '' ) {
			return null;
		}

		return new CredentialSet( $subdomain, $username, $password );
	}

	/**
	 * True when at least one usable credential set exists. Used by the
	 * REST test-connection endpoint and the Settings UI to gate the
	 * "Connected" indicator.
	 */
	public function has_default(): bool {
		$set = $this->get( self::DEFAULT_ACCOUNT_KEY );

		return $set !== null && $set->is_complete();
	}

	private function resolve_option_key( string $account_key ): string {
		if ( $account_key === self::DEFAULT_ACCOUNT_KEY || $account_key === '' ) {
			return self::LEGACY_OPTION_KEY;
		}

		return self::PHASE2_OPTION_PREFIX . sanitize_key( $account_key );
	}

	private function decrypt_password( string $stored ): string {
		if ( $stored === '' ) {
			return '';
		}

		if ( ! class_exists( '\\Smaily_Connect\\Includes\\Cypher' ) ) {
			// Legacy bootstrap hasn't loaded — shouldn't happen in a normal
			// WP request since smaily-connect.php requires the legacy
			// lifecycle before Bootstrap::boot() runs, but guard so we never
			// hand back a still-encrypted string.
			return '';
		}

		return (string) \Smaily_Connect\Includes\Cypher::decrypt( $stored );
	}
}
