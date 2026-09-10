<?php
/**
 * Tests for UpgradeLock — one runner at a time for the inline upgrade (PRO-2434).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Support\UpgradeLock;

/**
 * The lock rides on add_option()'s INSERT IGNORE atomicity. These tests
 * drive it against an in-memory options table: a second acquire while the
 * first holder is alive fails; an abandoned holder (older than TTL) is taken
 * over; release clears the row.
 */
final class UpgradeLockTest extends TestCase {

	/** @var array<string, string> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$options =& $this->options;
		Functions\when( 'add_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				if ( array_key_exists( $key, $options ) ) {
					return false;
				}
				$options[ $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$options ): bool {
				unset( $options[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_first_caller_acquires_and_second_is_refused_while_held(): void {
		$lock = new UpgradeLock();

		self::assertTrue( $lock->acquire() );
		self::assertFalse( ( new UpgradeLock() )->acquire(), 'A live lock must refuse a concurrent caller.' );
	}

	public function test_release_lets_the_next_caller_in(): void {
		$lock = new UpgradeLock();
		$lock->acquire();
		$lock->release();

		self::assertArrayNotHasKey( UpgradeLock::OPTION, $this->options );
		self::assertTrue( ( new UpgradeLock() )->acquire() );
	}

	public function test_an_abandoned_lock_older_than_ttl_is_taken_over(): void {
		$this->options[ UpgradeLock::OPTION ] = (string) ( time() - UpgradeLock::TTL - 1 );

		self::assertTrue( ( new UpgradeLock() )->acquire(), 'A holder that died mid-migration must not block upgrades forever.' );
		self::assertGreaterThan( time() - 5, (int) $this->options[ UpgradeLock::OPTION ], 'The take-over restamps the lock time.' );
	}

	public function test_a_lock_younger_than_ttl_is_respected(): void {
		$this->options[ UpgradeLock::OPTION ] = (string) ( time() - UpgradeLock::TTL + 60 );

		self::assertFalse( ( new UpgradeLock() )->acquire() );
	}
}
