<?php
/**
 * Tests for ContactAudience — the mode-aware "is this person a contact?" gate (F3-48).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;

final class ContactAudienceTest extends TestCase {

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	/** @var array<string, string> get_user_meta fixtures keyed "<user_id>:<meta_key>". */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);

		$meta =& $this->meta;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$meta ) {
				return $meta[ $user_id . ':' . $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$this->options = array();
		$this->meta    = array();
	}

	public function test_legitimate_interest_syncs_every_user(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_LEGITIMATE_INTEREST;

		// Not opted in — still synced under legitimate interest.
		self::assertTrue( $this->audience()->should_sync_user( $this->user( 5 ) ) );
	}

	public function test_consent_syncs_only_opted_in_users(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;
		$this->opt_in( 5 );

		self::assertTrue( $this->audience()->should_sync_user( $this->user( 5 ) ) );
		self::assertFalse( $this->audience()->should_sync_user( $this->user( 6 ) ) );
	}

	public function test_checkout_optin_never_syncs_accounts(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CHECKOUT_OPTIN;
		$this->opt_in( 5 );

		// Even an opted-in registered user: checkout-only mode does no account sync.
		self::assertFalse( $this->audience()->should_sync_user( $this->user( 5 ) ) );
	}

	public function test_should_sync_order_email_checkout_optin_is_opt_in_gated(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CHECKOUT_OPTIN;

		// Guest AND account: synced iff opted in.
		self::assertTrue( $this->audience()->should_sync_order_email( 0, true ) );
		self::assertTrue( $this->audience()->should_sync_order_email( 9, true ) );
		self::assertFalse( $this->audience()->should_sync_order_email( 0, false ) );
	}

	public function test_should_sync_order_email_consent_guests_need_optin_and_toggle(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;

		// Registered customers go through the account hooks, not the order path.
		self::assertFalse( $this->audience()->should_sync_order_email( 9, true ) );
		// Guests: only with include_guests AND opted in.
		self::assertFalse( $this->audience()->should_sync_order_email( 0, true ), 'include_guests off by default.' );

		$this->options[ ContactSyncMode::OPTION_INCLUDE_GUESTS ] = '1';
		self::assertTrue( $this->audience()->should_sync_order_email( 0, true ) );
		self::assertFalse( $this->audience()->should_sync_order_email( 0, false ), 'Consent guests need the opt-in.' );
	}

	public function test_should_sync_order_email_legitimate_interest_guests_need_only_the_toggle(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ]           = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		$this->options[ ContactSyncMode::OPTION_INCLUDE_GUESTS ] = '1';

		// Guest synced without an opt-in; registered still via account hooks.
		self::assertTrue( $this->audience()->should_sync_order_email( 0, false ) );
		self::assertFalse( $this->audience()->should_sync_order_email( 9, false ) );
	}

	public function test_the_switch_off_empties_the_audience_whatever_the_mode_says(): void {
		$this->options[ ContactSyncMode::OPTION_SYNC_ENABLED ]   = '';
		$this->options[ ContactSyncMode::OPTION_MODE ]           = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		$this->options[ ContactSyncMode::OPTION_INCLUDE_GUESTS ] = '1';

		self::assertFalse( $this->audience()->should_sync_user( $this->user( 5 ) ), 'Contact sync is switched off — nobody is a contact we sync.' );
		self::assertFalse( $this->audience()->should_sync_order_email( 0, true ) );
		self::assertSame( 0, $this->audience()->count_audience(), 'And the backfill estimate must say so rather than promising a sync that will not happen.' );
	}

	private function audience(): ContactAudience {
		return new ContactAudience( new ContactSyncMode() );
	}

	private function opt_in( int $user_id ): void {
		$this->meta[ $user_id . ':' . ContactAudience::OPTIN_META ] = '1';
	}

	private function user( int $id ): \WP_User {
		return new class( $id ) extends \WP_User {
			public function __construct( int $id ) {
				$this->ID = $id;
			}
		};
	}
}

if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}
