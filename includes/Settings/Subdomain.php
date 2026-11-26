<?php
/**
 * The one rule for a Smaily subdomain a merchant types.
 *
 * @package Smaily\Connect\Settings
 */

declare(strict_types=1);

namespace Smaily\Connect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A Smaily account lives at https://{subdomain}.sendsmaily.net, so the
 * subdomain is one DNS label. A pasted account address
 * ("https://demo.sendsmaily.net/…" or "demo.sendsmaily.net") is reduced to
 * that label; anything else that is not one plain label is refused when it
 * is saved or tested (CON-431, the PrestaShop module's rule).
 */
final class Subdomain {

	/** One DNS label: lower-case letters, digits and inner hyphens, 1–63 characters. */
	private const PLAIN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

	/**
	 * Reduces "https://demo.sendsmaily.net/…", "demo.sendsmaily.net" or
	 * " Demo " to "demo". Any other value comes back trimmed and lower case.
	 */
	public static function normalize( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( is_string( $host ) && $host !== '' ) {
			$value = $host;
		}
		$value = (string) preg_replace( '/\.sendsmaily\.net.*$/i', '', $value );

		return strtolower( trim( $value, " \t\n\r\0\x0B/." ) );
	}

	/** Whether a normalized value is one plain subdomain label. */
	public static function is_plain( string $subdomain ): bool {
		return preg_match( self::PLAIN, $subdomain ) === 1;
	}

	/** The refusal the Connection fields show next to the subdomain. */
	public static function refusal(): string {
		return __( 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.', 'smaily-connect' );
	}
}
