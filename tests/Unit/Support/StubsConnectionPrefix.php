<?php
/**
 * Answers the options RecEngineSettings::connection_prefix() reads, for unit
 * tests that build an engine key (CON-323). Needs Brain Monkey set up.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Smaily\Connect\Settings\RecEngineSettings;

trait StubsConnectionPrefix {

	/**
	 * Stub get_option(): a connection with this prefix ('' = a connection
	 * without one); every other option answers $others[ key ], else its
	 * default.
	 *
	 * @param array<string, mixed> $others
	 */
	private function stub_connection_prefix( string $prefix, array $others = array() ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) use ( $prefix, $others ) {
				if ( $key === RecEngineSettings::OPTION_CONNECTED ) {
					return true;
				}
				if ( $key === RecEngineSettings::OPTION_CONNECTION_PREFIX ) {
					return $prefix;
				}
				return array_key_exists( $key, $others ) ? $others[ $key ] : $fallback;
			}
		);
	}
}
