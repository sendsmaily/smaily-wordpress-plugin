<?php
/**
 * Tests for SetupState — the one accessor for the wizard-completion flag (PRO-2292).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\SetupState;

final class SetupStateTest extends TestCase {

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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$this->options = array();
	}

	public function test_an_unset_flag_means_the_wizard_was_never_finished(): void {
		self::assertFalse( SetupState::completed() );
	}

	public function test_the_flag_is_read_with_the_same_truthiness_the_gates_used(): void {
		foreach ( array( true, '1', 1 ) as $on ) {
			$this->options[ SetupState::OPTION_SETUP_COMPLETED ] = $on;
			self::assertTrue( SetupState::completed(), 'Stored on: ' . var_export( $on, true ) );
		}

		foreach ( array( false, '', '0', 0 ) as $off ) {
			$this->options[ SetupState::OPTION_SETUP_COMPLETED ] = $off;
			self::assertFalse( SetupState::completed(), 'Stored off: ' . var_export( $off, true ) );
		}
	}

	public function test_the_option_key_is_the_one_every_version_has_written(): void {
		self::assertSame( 'smly_plus_setup_completed', SetupState::OPTION_SETUP_COMPLETED );
	}
}
