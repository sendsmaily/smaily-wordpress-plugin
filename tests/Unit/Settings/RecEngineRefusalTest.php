<?php
/**
 * The local "connection refused" state (PRO-1893) — what it takes to set it,
 * what it takes to clear it, and which gate reads it.
 *
 * The distinction that matters: `is_connected()` stays TRUE while refused
 * (the credentials are stored and valid, the account is not active), so
 * everything that only enqueues, reads or displays keeps working and the
 * queue is preserved. `sending_allowed()` is what every path that actually
 * talks to the engine consults.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;

final class RecEngineRefusalTest extends TestCase {

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$opts ): bool {
				$opts[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$opts ): bool {
				unset( $opts[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$this->options = array();
	}

	public function test_a_fresh_connection_is_allowed_to_send(): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTED ] = true;

		$settings = new RecEngineSettings();

		self::assertFalse( $settings->is_refused() );
		self::assertTrue( $settings->sending_allowed() );
	}

	public function test_a_refusal_stops_sending_without_disconnecting(): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTED ] = true;
		$settings = new RecEngineSettings();

		$settings->mark_refused();

		self::assertTrue( $settings->is_refused() );
		self::assertFalse( $settings->sending_allowed(), 'Nothing may be sent to a deactivated account.' );
		self::assertTrue(
			$settings->is_connected(),
			'The connection itself survives — the queue, the Event Log and the Settings card all still read it.'
		);
		self::assertGreaterThan( 0, $settings->refused_at() );
	}

	public function test_the_first_refusal_timestamp_is_the_one_kept(): void {
		// The merchant wants to know when sending STOPPED, not when the last
		// scheduled job re-confirmed it.
		$settings = new RecEngineSettings();
		$settings->mark_refused();
		$first = $settings->refused_at();

		$this->options[ RecEngineSettings::OPTION_REFUSED_AT ] = $first - 3600;
		$settings->mark_refused();

		self::assertSame( $first - 3600, $settings->refused_at() );
	}

	public function test_disconnecting_clears_the_refusal(): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTED ] = true;
		$settings = new RecEngineSettings();
		$settings->mark_refused();

		$settings->disconnect();

		self::assertFalse( $settings->is_refused() );
		self::assertSame( 0, $settings->refused_at() );
	}

	public function test_a_disconnected_store_is_not_allowed_to_send_either(): void {
		$settings = new RecEngineSettings();

		self::assertFalse( $settings->sending_allowed() );
	}
}
