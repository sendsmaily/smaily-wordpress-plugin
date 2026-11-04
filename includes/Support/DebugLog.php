<?php
/**
 * WP_DEBUG-gated debug logging.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around error_log().
 *
 * error_log() in shipping code is discouraged by the WordPress.org plugin
 * review (it writes to the server log unconditionally). Centralizing it here
 * keeps the single (intentional) error_log call behind one phpcs:ignore and
 * gates it on WP_DEBUG, so production builds stay quiet while a merchant
 * debugging an issue can surface the diagnostics by enabling WP_DEBUG. The
 * load-bearing failures are also recorded in the Event Log / health notices;
 * these messages are supplementary diagnostics.
 */
final class DebugLog {

	/**
	 * Write a diagnostic line to the PHP error log when WP_DEBUG is on.
	 *
	 * @param string $message Pre-formatted message (callers already prefix it, e.g. "[smaily-connect …]").
	 */
	public static function write( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional, WP_DEBUG-gated diagnostic; the single error_log chokepoint.
			error_log( $message );
		}
	}

	/**
	 * Where a local error happened, without its message (which can carry
	 * personal data): `<Class> at <file>:<line>`.
	 */
	public static function where( \Throwable $e ): string {
		return sprintf( '%s at %s:%d', get_class( $e ), basename( $e->getFile() ), $e->getLine() );
	}

	/**
	 * The same as where(), with the error's code: `<Class> code=<n> at <file>:<line>`.
	 */
	public static function where_with_code( \Throwable $e ): string {
		return sprintf( '%s code=%d at %s:%d', get_class( $e ), (int) $e->getCode(), basename( $e->getFile() ), $e->getLine() );
	}
}
