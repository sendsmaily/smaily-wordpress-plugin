<?php
/**
 * Defines the encryption and decryption functionality.
 *
 * Format history (FABLE_AUDIT §4#2):
 *
 * - v2 (current): `smy2:` . base64( nonce(12) || tag(16) || ciphertext ),
 *   AES-256-GCM with a random per-message nonce; key = raw-binary
 *   sha256(SECURE_AUTH_KEY). encrypt() always writes this format.
 *
 * - legacy (pre-GCM dev builds; GCM first ships in 2.1.0-beta.1, the
 *   release formerly numbered 2.0.0-beta.1 — DECISIONS F3-35):
 *   base64( iv(16) || hmac(32) || ciphertext ),
 *   AES-256-CBC + HMAC-SHA256 — but the IV was a STATIC prefix of AUTH_KEY
 *   and was stored inside the persisted blob, so every DB dump leaked an
 *   AUTH_KEY prefix and equal plaintexts produced equal ciphertexts.
 *   decrypt() still reads this format so stored secrets survive the
 *   upgrade; Activation::reencrypt_legacy_secrets() migrates them to v2
 *   on upgrade-detect, and encrypt() never writes legacy again.
 */

namespace Smaily_Connect\Includes;

defined( 'ABSPATH' ) || exit;

class Cypher {
	/**
	 * Legacy cypher — decrypt-only (see format history above).
	 */
	const CYPHER = 'AES-256-CBC';

	/**
	 * Current cypher (v2 format).
	 */
	const CYPHER_GCM = 'aes-256-gcm';

	/**
	 * Version prefix marking a v2 (GCM) blob.
	 */
	const V2_PREFIX = 'smy2:';

	/**
	 * GCM nonce length in bytes (the GCM-recommended 96 bits).
	 */
	const NONCE_LENGTH = 12;

	/**
	 * GCM authentication-tag length in bytes.
	 */
	const TAG_LENGTH = 16;

	/**
	 * Encrypt a string (always v2 / AES-256-GCM).
	 *
	 * @param string $password
	 * @return string `smy2:`-prefixed base64 blob; empty string on failure.
	 */
	public static function encrypt( $password ) {
		$key   = hash( 'sha256', SECURE_AUTH_KEY, true );
		$nonce = random_bytes( self::NONCE_LENGTH );
		$tag   = '';
		$raw   = openssl_encrypt( (string) $password, self::CYPHER_GCM, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LENGTH );
		if ( false === $raw ) {
			return '';
		}

		return self::V2_PREFIX . base64_encode( $nonce . $tag . $raw );
	}

	/**
	 * Decrypts the cyphertext. Returns empty string when decryption is not
	 * possible. Reads both formats: v2 (GCM, by prefix) and legacy (CBC).
	 *
	 * @param string $cyphertext
	 * @return string decrypted original password.
	 */
	public static function decrypt( $cyphertext ) {
		$cyphertext = (string) $cyphertext;
		if ( $cyphertext === '' ) {
			return '';
		}

		if ( self::is_v2( $cyphertext ) ) {
			return self::decrypt_v2( $cyphertext );
		}

		return self::decrypt_legacy( $cyphertext );
	}

	/**
	 * Whether a stored blob is already in the v2 (GCM) format. Used by the
	 * upgrade re-encryption pass to skip already-migrated values.
	 *
	 * @param string $cyphertext
	 */
	public static function is_v2( $cyphertext ): bool {
		return str_starts_with( (string) $cyphertext, self::V2_PREFIX );
	}

	/**
	 * v2 path: AES-256-GCM. The auth tag check is built into the mode —
	 * openssl_decrypt() returns false on any tamper.
	 */
	private static function decrypt_v2( string $cyphertext ): string {
		$decoded = base64_decode( substr( $cyphertext, strlen( self::V2_PREFIX ) ), true );
		if ( false === $decoded || strlen( $decoded ) < self::NONCE_LENGTH + self::TAG_LENGTH ) {
			return '';
		}

		$key   = hash( 'sha256', SECURE_AUTH_KEY, true );
		$nonce = substr( $decoded, 0, self::NONCE_LENGTH );
		$tag   = substr( $decoded, self::NONCE_LENGTH, self::TAG_LENGTH );
		$raw   = substr( $decoded, self::NONCE_LENGTH + self::TAG_LENGTH );

		$pw = openssl_decrypt( $raw, self::CYPHER_GCM, $key, OPENSSL_RAW_DATA, $nonce, $tag );

		return false === $pw ? '' : $pw;
	}

	/**
	 * Legacy path: AES-256-CBC + HMAC. Kept behaviour-identical to the
	 * original implementation so pre-upgrade blobs keep decrypting; never
	 * written.
	 */
	private static function decrypt_legacy( string $cyphertext ): string {
		$salt    = hash( 'sha256', SECURE_AUTH_KEY );
		$iv_len  = openssl_cipher_iv_length( self::CYPHER );
		$sha_len = 32;

		$decoded = base64_decode( $cyphertext );
		if ( false === $decoded ) {
			return '';
		}
		$iv   = substr( AUTH_KEY, 0, $iv_len );
		$hmac = substr( $decoded, $iv_len, $sha_len );
		$raw  = substr( $decoded, $iv_len + $sha_len );
		$pw   = openssl_decrypt( $raw, self::CYPHER, $salt, OPENSSL_RAW_DATA, $iv );
		if ( $pw === false ) {
			return '';
		}

		$calc = hash_hmac( 'sha256', $raw, $salt, true );
		if ( hash_equals( $hmac, $calc ) ) {
			return $pw;
		}

		return '';
	}
}
