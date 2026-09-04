<?php
/**
 * Tests for ContactSyncMode — the lawful-basis preset → policy mapping (F3-48).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ContactSyncMode;

final class ContactSyncModeTest extends TestCase {

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

	public function test_contact_sync_is_on_until_the_merchant_switches_it_off(): void {
		self::assertTrue( ContactSyncMode::sync_enabled(), 'Never saved is a fresh install, not an opt-out.' );

		// Every shape the switch has been stored in — the wizard's save route,
		// and the pre-wizard settings page's boolean before it.
		foreach ( array( '1', 1, true, 'yes', 'true' ) as $on ) {
			$this->options[ ContactSyncMode::OPTION_SYNC_ENABLED ] = $on;
			self::assertTrue( ContactSyncMode::sync_enabled(), 'Stored on: ' . var_export( $on, true ) );
		}

		foreach ( array( '', '0', 0, false ) as $off ) {
			$this->options[ ContactSyncMode::OPTION_SYNC_ENABLED ] = $off;
			self::assertFalse( ContactSyncMode::sync_enabled(), 'Stored off: ' . var_export( $off, true ) );
		}
	}

	public function test_default_mode_is_consent(): void {
		self::assertSame( ContactSyncMode::MODE_CONSENT, ( new ContactSyncMode() )->mode() );
	}

	public function test_unknown_stored_mode_falls_back_to_default(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = 'garbage';

		self::assertSame( ContactSyncMode::MODE_CONSENT, ( new ContactSyncMode() )->mode() );
	}

	public function test_legitimate_interest_policy(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		$mode = new ContactSyncMode();

		self::assertTrue( $mode->syncs_accounts() );
		self::assertFalse( $mode->requires_optin() );
		self::assertFalse( $mode->reconciles() );
	}

	public function test_consent_policy(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;
		$mode = new ContactSyncMode();

		self::assertTrue( $mode->syncs_accounts() );
		self::assertTrue( $mode->requires_optin() );
		self::assertTrue( $mode->reconciles() );
	}

	public function test_checkout_optin_policy(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CHECKOUT_OPTIN;
		$mode = new ContactSyncMode();

		self::assertFalse( $mode->syncs_accounts() );
		self::assertTrue( $mode->requires_optin() );
		self::assertFalse( $mode->reconciles() );
		self::assertTrue( $mode->include_guests(), 'Checkout-only is intrinsically guest-driven.' );
	}

	public function test_include_guests_defaults_off(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;

		self::assertFalse( ( new ContactSyncMode() )->include_guests() );
	}

	public function test_include_guests_toggle(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ]           = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		$this->options[ ContactSyncMode::OPTION_INCLUDE_GUESTS ] = '1';

		self::assertTrue( ( new ContactSyncMode() )->include_guests() );
	}
}
