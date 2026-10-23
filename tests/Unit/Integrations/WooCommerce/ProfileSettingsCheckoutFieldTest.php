<?php
/**
 * Unit: the classic checkout's newsletter box refreshes the order review when
 * it changes (CON-202).
 *
 * The abandoned-cart reminder learns about the tick from the order review the
 * classic checkout posts (CON-196). WooCommerce posts one after an address or
 * shipping change, and after a change of any input inside an
 * `update_totals_on_change` wrapper — so without that class a shopper who ticks
 * the box last and leaves was never recorded. The class goes on the checkout
 * copy of the field only; its label, type, place and default stay as they were.
 *
 * @package Smaily\Connect\Tests\Unit
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

// The legacy tree is not autoloaded (smaily.class.php require_once's it).
require_once __DIR__ . '/../../../../includes/smaily-options.class.php';
require_once __DIR__ . '/../../../../integrations/woocommerce/profile-settings.class.php';

final class ProfileSettingsCheckoutFieldTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->options = array( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION => '1' );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_change_of_the_checkout_box_refreshes_the_order_review(): void {
		$field = $this->checkout_fields()['order']['user_newsletter'];

		self::assertContains( 'update_totals_on_change', (array) ( $field['class'] ?? array() ), 'WooCommerce posts an order review on a change of a checkbox inside this wrapper — the only way a tick made last is recorded.' );
	}

	public function test_the_checkout_box_is_otherwise_unchanged(): void {
		$order = $this->checkout_fields()['order'];

		self::assertSame( 'user_newsletter', array_key_first( $order ), 'Default place: before the order notes.' );
		self::assertSame( 'checkbox', $order['user_newsletter']['type'] );
		self::assertSame( 'Subscribe to newsletter', $order['user_newsletter']['label'] );
		self::assertFalse( $order['user_newsletter']['required'] );
		self::assertArrayNotHasKey( 'default', $order['user_newsletter'], 'The box stays unticked by default.' );
	}

	public function test_a_class_the_merchant_added_is_kept(): void {
		Filters\expectApplied( 'smaily_connect_account_fields' )->andReturnUsing(
			static function ( array $fields ): array {
				$fields['user_newsletter']['class'] = array( 'merchant-class' );
				return $fields;
			}
		);

		$field = $this->checkout_fields()['order']['user_newsletter'];

		self::assertSame( array( 'merchant-class', 'update_totals_on_change' ), $field['class'] );
	}

	public function test_the_account_and_registration_box_does_not_get_the_class(): void {
		$printed = array();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'woocommerce_form_field' )->alias(
			static function ( string $key, array $args ) use ( &$printed ): void {
				$printed[ $key ] = $args;
			}
		);

		$settings = new Profile_Settings();
		$settings->smaily_checkout_fields( $this->empty_checkout() );
		$settings->smaily_print_user_frontend_fields();

		self::assertArrayHasKey( 'user_newsletter', $printed );
		self::assertNotContains( 'update_totals_on_change', (array) ( $printed['user_newsletter']['class'] ?? array() ), 'Only the checkout has an order review to refresh.' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function checkout_fields(): array {
		return ( new Profile_Settings() )->smaily_checkout_fields( $this->empty_checkout() );
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
