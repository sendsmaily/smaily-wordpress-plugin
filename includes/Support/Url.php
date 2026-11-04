<?php
/**
 * URL helpers.
 *
 * @package Smaily\Connect\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one origin rule: the engine's, the site's and the admin's origin are
 * compared and shown in the form a browser writes them.
 */
final class Url {

	/**
	 * The origin of `$url` as a browser writes it (`scheme://host[:port]`,
	 * lower case, the scheme's own port left out), or '' when it has none.
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$origin = $scheme . '://' . strtolower( $parts['host'] );
		$port   = $parts['port'] ?? null;
		if ( $port === null || ( $scheme === 'https' && $port === 443 ) || ( $scheme === 'http' && $port === 80 ) ) {
			return $origin;
		}

		return $origin . ':' . $port;
	}
}
