<?php
/**
 * Defines the encryption and decryption functionality.
 */

namespace Smaily_Connect\Includes;

class Cypher {
	/**
	 * The cypher used for encryption and decryption.
	 */
	const CYPHER = 'AES-256-CBC';

	/**
	 * Encrypt a string.
	 * @param string $password
	 * @return string base64 encoded and encrypted original value.
	 */
	public static function encrypt( $password ) {
		$salt = hash( 'sha256', SECURE_AUTH_KEY );
		$iv   = substr( AUTH_KEY, 0, openssl_cipher_iv_length( self::CYPHER ) );
		$raw  = openssl_encrypt( $password, self::CYPHER, $salt, OPENSSL_RAW_DATA, $iv );
		$hmac = hash_hmac( 'sha256', $raw, $salt, true );

		return base64_encode( $iv . $hmac . $raw );
	}

	/**
	 * Decrypts the cyphertext. Returns empty string when decryption is not possible.
	 *
	 * @param string $cyphertext
	 * @return string decrypted original password.
	 */
	public static function decrypt( $cyphertext ) {
		if ( $cyphertext === '' ) {
			return '';
		}

		$salt    = hash( 'sha256', SECURE_AUTH_KEY );
		$iv_len  = openssl_cipher_iv_length( self::CYPHER );
		$sha_len = 32;

		$decoded = base64_decode( $cyphertext );
		$iv      = substr( AUTH_KEY, 0, $iv_len );
		$hmac    = substr( $decoded, $iv_len, $sha_len );
		$raw     = substr( $decoded, $iv_len + $sha_len );
		$pw      = openssl_decrypt( $raw, self::CYPHER, $salt, OPENSSL_RAW_DATA, $iv );
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
