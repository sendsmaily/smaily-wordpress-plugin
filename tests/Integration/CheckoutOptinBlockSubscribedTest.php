<?php
/**
 * Integration: the block checkout tells its opt-in box whether the shopper is
 * already subscribed (PRO-4097).
 *
 * The classic checkout leaves the newsletter box out for a logged-in customer
 * whose `user_newsletter` is set; the block checkout showed it anyway. The
 * block reads the data its WooCommerce Blocks integration prints, so these
 * cases build that data for a subscribed customer, an unsubscribed one and a
 * guest.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Blocks\Checkout_Optin\Integration;
use Smaily_Connect\Includes\Options;

final class CheckoutOptinBlockSubscribedTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		update_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION, true );
	}

	protected function tearDown(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		delete_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_a_subscribed_customer_is_reported_as_subscribed(): void {
		wp_set_current_user( $this->make_customer( '1' ) );

		self::assertTrue( $this->script_data()['customerSubscribed'] );
	}

	public function test_a_customer_who_is_not_subscribed_is_not_reported_as_subscribed(): void {
		wp_set_current_user( $this->make_customer( '' ) );

		$data = $this->script_data();
		self::assertFalse( $data['customerSubscribed'] );
		self::assertTrue( (bool) $data['smailyCheckoutOptinActive'] );
	}

	public function test_a_guest_is_never_reported_as_subscribed(): void {
		self::assertFalse( $this->script_data()['customerSubscribed'] );
	}

	/**
	 * The data the block checkout reads as `smaily-checkout-optin_data`.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data(): array {
		self::assertTrue( class_exists( Integration::class ), 'The checkout opt-in integration loads on woocommerce_blocks_loaded.' );

		return ( new Integration() )->get_script_data();
	}

	private function make_customer( string $user_newsletter ): int {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_optin_block_' . $slug,
				'user_email' => 'optin-block-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		if ( '' !== $user_newsletter ) {
			update_user_meta( $user_id, 'user_newsletter', $user_newsletter );
		}

		return $user_id;
	}
}
