<?php
/**
 * Unit: the contact sync's field selection adds nothing to a storefront form
 * (PRO-4252).
 *
 * The plugin used to put Gender and Birthday into the classic checkout's
 * billing section, and Phone / Gender / Birthday on the My Account
 * registration and account-details forms, whenever the merchant ticked them
 * for the contact sync. Now the only field the plugin adds to a storefront
 * form is the newsletter box; the wp-admin user profile keeps the three. A
 * stored value is never overwritten by a storefront save that did not show the
 * field, and `smaily_connect_account_fields` still brings a field back.
 *
 * @package Smaily\Connect\Tests\Unit
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\SubscriberPayloadBuilder;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

// The legacy tree is not autoloaded (smaily.class.php require_once's it).
require_once __DIR__ . '/../../../../includes/smaily-options.class.php';
require_once __DIR__ . '/../../../../integrations/woocommerce/profile-settings.class.php';

final class ProfileSettingsStorefrontFieldsTest extends TestCase {

	private const PROFILE_FIELDS = array( 'user_gender', 'user_phone', 'user_dob' );

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, array<string, mixed>> Fields woocommerce_form_field() was asked to print. */
	private array $printed = array();

	/** @var array<string, mixed> User meta written. */
	private array $meta_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		// Phone, Gender and Birthday are ticked for the contact sync, as the
		// wizard saves them.
		$this->options = array(
			Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION => '1',
			SubscriberPayloadBuilder::OPTION_SYNC_FIELDS => array( 'first_name', 'user_phone', 'user_gender', 'birthday' ),
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'woocommerce_form_field' )->alias(
			function ( string $key, array $args ): void {
				$this->printed[ $key ] = $args;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): void {
				$this->meta_writes[ $key ] = $value;
			}
		);
		Functions\when( 'wp_update_user' )->justReturn( 7 );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_classic_checkout_gets_the_newsletter_box_and_nothing_else(): void {
		$before = $this->empty_checkout();
		$after  = ( new Profile_Settings() )->smaily_checkout_fields( $before );

		self::assertSame( array(), $after['billing'], 'Gender and Birthday no longer go into the billing section.' );
		unset( $after['order']['user_newsletter'] );
		self::assertSame( $before, $after, 'The newsletter box is the only field the plugin adds.' );
	}

	public function test_with_the_newsletter_box_switched_off_the_checkout_is_untouched(): void {
		$this->options[ Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION ] = '';

		$before = $this->empty_checkout();

		self::assertSame( $before, ( new Profile_Settings() )->smaily_checkout_fields( $before ) );
	}

	public function test_the_field_selection_does_not_change_the_checkout(): void {
		$all = ( new Profile_Settings() )->smaily_checkout_fields( $this->empty_checkout() );

		$this->options[ SubscriberPayloadBuilder::OPTION_SYNC_FIELDS ] = array( 'first_name' );
		$none = ( new Profile_Settings() )->smaily_checkout_fields( $this->empty_checkout() );

		self::assertSame( $none, $all );
	}

	public function test_the_registration_form_gets_only_the_newsletter_box(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		( new Profile_Settings() )->smaily_print_user_frontend_fields();

		self::assertSame( array( 'user_newsletter' ), array_keys( $this->printed ) );
	}

	public function test_the_account_details_form_gets_only_the_newsletter_box(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		( new Profile_Settings() )->smaily_print_user_frontend_fields();

		self::assertSame( array( 'user_newsletter' ), array_keys( $this->printed ) );
	}

	public function test_the_wp_admin_user_profile_keeps_the_selected_fields(): void {
		ob_start();
		( new Profile_Settings() )->smaily_print_user_admin_fields();
		ob_end_clean();

		foreach ( self::PROFILE_FIELDS as $key ) {
			self::assertArrayHasKey( $key, $this->printed, 'Staff still see and edit ' . $key . ' on the user profile.' );
		}
	}

	public function test_an_account_details_save_leaves_the_stored_values_alone(): void {
		$this->drive_account_details_save( array( 'user_newsletter' => '1' ) );

		self::assertSame( array( 'user_newsletter' => '1' ), $this->meta_writes, 'A field the form did not show is not written, so a stored value is kept.' );
	}

	public function test_the_field_filter_brings_the_fields_back_on_every_storefront_form(): void {
		$this->show_profile_fields_on_storefront_forms();

		$billing = ( new Profile_Settings() )->smaily_checkout_fields( $this->empty_checkout() )['billing'];
		self::assertSame( array( 'user_gender', 'user_phone', 'user_dob' ), array_keys( $billing ) );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		( new Profile_Settings() )->smaily_print_user_frontend_fields();
		self::assertSame( array( 'user_newsletter', 'user_gender', 'user_phone', 'user_dob' ), array_keys( $this->printed ), 'Registration form.' );

		$this->printed = array();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		( new Profile_Settings() )->smaily_print_user_frontend_fields();
		self::assertSame( array( 'user_newsletter', 'user_gender', 'user_phone', 'user_dob' ), array_keys( $this->printed ), 'Account details form.' );
	}

	public function test_a_field_brought_back_by_the_filter_is_saved_from_the_account_details_form(): void {
		$this->show_profile_fields_on_storefront_forms();

		$this->drive_account_details_save(
			array(
				'user_gender' => '2',
				'user_phone'  => '+372 5555 1234',
				'user_dob'    => '1985-03-28',
			)
		);

		self::assertSame( '2', $this->meta_writes['user_gender'] );
		self::assertSame( '+372 5555 1234', $this->meta_writes['user_phone'] );
		self::assertSame( '1985-03-28', $this->meta_writes['user_dob'] );
	}

	/**
	 * The developer path the merchant docs and PRO-4252 name.
	 */
	private function show_profile_fields_on_storefront_forms(): void {
		Filters\expectApplied( 'smaily_connect_account_fields' )->andReturnUsing(
			static function ( array $fields ): array {
				foreach ( self::PROFILE_FIELDS as $key ) {
					if ( isset( $fields[ $key ] ) ) {
						$fields[ $key ]['hide_in_checkout']     = false;
						$fields[ $key ]['hide_in_account']      = false;
						$fields[ $key ]['hide_in_registration'] = false;
					}
				}
				return $fields;
			}
		);
	}

	/**
	 * My Account → Account details saved by the logged-in shopper
	 * (woocommerce_save_account_details).
	 *
	 * @param array<string, string> $posted
	 */
	private function drive_account_details_save( array $posted ): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		$_REQUEST = array( 'save-account-details-nonce' => 'account-nonce' );
		$_POST    = array( 'action' => 'save_account_details' ) + $posted;

		( new Profile_Settings() )->smaily_save_wc_account_fields( 7 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function empty_checkout(): array {
		return array(
			'billing'  => array(),
			'shipping' => array(),
			'account'  => array(),
			'order'    => array( 'order_comments' => array( 'type' => 'textarea' ) ),
		);
	}
}
