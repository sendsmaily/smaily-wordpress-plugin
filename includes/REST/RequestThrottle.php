<?php
/**
 * Fixed-window request counters for the public storefront routes.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

/**
 * The throttle the two public routes share — the browse relay
 * (BeaconEndpoint) and the storefront recommendations
 * (RecommendationsEndpoint). Each caller keeps its own counter keys and
 * ceilings; this holds the counting and the one address a client cannot
 * choose.
 */
final class RequestThrottle {

	/**
	 * Increment a fixed-window counter; true once it exceeds $max. Transients
	 * aren't atomic, so the count is approximate — fine for rate-limiting.
	 */
	public static function exceeded( string $key, int $max, int $window_seconds ): bool {
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, $window_seconds );
		return $count > $max;
	}

	/**
	 * REMOTE_ADDR only — X-Forwarded-For (and X-Real-IP, Forwarded, Client-IP)
	 * is attacker-spoofable, so trusting it would let one client masquerade as
	 * many IPs and defeat the throttle. A forwarding header counts only where
	 * the web server itself is configured to trust it and rewrites REMOTE_ADDR
	 * from it (e.g. nginx `real_ip`, Apache `mod_remoteip`). '' when the
	 * address is missing or not an IP — callers then use one shared bucket.
	 */
	public static function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return is_string( $ip ) ? $ip : '';
	}

	private function __construct() {
		// Static-only.
	}
}
