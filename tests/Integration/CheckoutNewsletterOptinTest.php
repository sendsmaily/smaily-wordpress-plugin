<?php
/**
 * Integration: a registered buyer who ticks the newsletter box reaches Smaily
 * in consent mode (PRO-3406).
 *
 * Consent mode syncs a registered customer only when the store's consent
 * record `user_newsletter` is 1, and nothing wrote it at the checkout or at
 * registration — so the tick was lost and the buyer never became a
 * subscriber. These cases fire the real WooCommerce hooks with the argument
 * lists WooCommerce itself passes, and assert that the consent record is
 * written and a subscribe (`is_unsubscribed = 0`) is queued for Smaily — and
 * that an unticked box, or another contact-sync mode, writes nothing.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

final class CheckoutNewsletterOptinTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	private ?Profile_Settings $settings = null;

	/** @var callable */
	private $no_http;

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		update_option( 'smly_plus_setup_completed', true );
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '1' );
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		update_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION, true );

		// Nothing may leave the test site — the legacy sync still listens on
		// some of these hooks in this process.
		$this->no_http = static function () {
			return array(
				'headers'  => array(),
				'body'     => '{"code":101,"message":"OK"}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};
		add_filter( 'pre_http_request', $this->no_http, 10, 0 );

		$this->settings = new Profile_Settings();
		$this->settings->register_hooks();
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->no_http, 10 );
		$this->forget_hooks( $this->settings );

		foreach ( $this->created_orders as $order_id ) {
			// HPOS: wp_delete_post() is a silent no-op on an order.
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		$this->created_orders = array();

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		$_POST = array();
		HookHandler::reset_seen();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_logged_in_classic_checkout_tick_subscribes(): void {
		$user_id = $this->make_customer();

		// WC_Checkout::process_customer() fires this with the posted checkout data.
		do_action( 'woocommerce_checkout_update_user_meta', $user_id, $this->posted_checkout( $user_id, 1 ) );

		$this->assert_subscribed( $user_id );
	}

	public function test_classic_checkout_account_creation_tick_subscribes(): void {
		$_POST = array(
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
			'user_newsletter'                    => '1',
		);

		// wc_create_new_customer() fires user_register and woocommerce_created_customer.
		$user_id = $this->create_wc_customer();
		self::assertSame( '', get_user_meta( $user_id, ContactAudience::OPTIN_META, true ), 'The registration callback must not act on a checkout request.' );

		do_action( 'woocommerce_checkout_update_user_meta', $user_id, $this->posted_checkout( $user_id, 1 ) );

		$this->assert_subscribed( $user_id );
	}

	public function test_my_account_registration_tick_subscribes(): void {
		$_POST = array(
			'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ),
			'register'                   => 'Register',
			'user_newsletter'            => '1',
		);

		$user_id = $this->create_wc_customer();

		$this->assert_subscribed( $user_id );
	}

	public function test_logged_in_block_checkout_tick_subscribes(): void {
		$user_id = $this->make_customer();
		$order   = $this->make_order( $user_id );

		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $this->block_request( true ) );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		self::assertSame( '1', wc_get_order( $order->get_id() )->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ), 'The tick stays on the order as evidence.' );
		$this->assert_subscribed( $user_id );
	}

	public function test_block_checkout_account_creation_tick_subscribes(): void {
		$order = $this->make_order( 0 );

		// The Store API runs the request hook while the order is still a guest's …
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $this->block_request( true ) );

		// … creates the account and sets the customer id …
		$user_id = $this->create_wc_customer();
		$order->set_customer_id( $user_id );
		$order->save();

		// … and only then fires the processed hook.
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assert_subscribed( $user_id );
	}

	public function test_an_unticked_box_records_nothing(): void {
		$user_id = $this->make_customer();

		do_action( 'woocommerce_checkout_update_user_meta', $user_id, $this->posted_checkout( $user_id, '' ) );

		$_POST = array( 'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ) );
		do_action( 'woocommerce_created_customer', $user_id, array(), false );

		$order = $this->make_order( $user_id );
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $this->block_request( false ) );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		self::assertSame( '', get_user_meta( $user_id, ContactAudience::OPTIN_META, true ), 'Nothing is recorded — and never a 0.' );
		self::assertNull( $this->consent_row( $user_id ), 'No subscription or unsubscribe is queued.' );
	}

	public function test_other_modes_write_no_consent_record(): void {
		foreach ( array( ContactSyncMode::MODE_LEGITIMATE_INTEREST, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			update_option( ContactSyncMode::OPTION_MODE, $mode );
			$user_id = $this->make_customer();

			do_action( 'woocommerce_checkout_update_user_meta', $user_id, $this->posted_checkout( $user_id, 1 ) );

			$order = $this->make_order( $user_id );
			do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $this->block_request( true ) );
			$order->save();
			do_action( 'woocommerce_store_api_checkout_order_processed', $order );

			self::assertSame( '', get_user_meta( $user_id, ContactAudience::OPTIN_META, true ), "No consent record in {$mode} mode." );
			self::assertNull( $this->consent_row( $user_id ), "No consent event in {$mode} mode." );
		}
	}

	// --- helpers -------------------------------------------------------------

	private function assert_subscribed( int $user_id ): void {
		self::assertSame( '1', get_user_meta( $user_id, ContactAudience::OPTIN_META, true ), 'The tick is saved as the store\'s consent record.' );

		$row = $this->consent_row( $user_id );
		self::assertNotNull( $row, 'Writing the consent record queues the contact for Smaily.' );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $row['event_type'] );

		$payload = json_decode( (string) $row['payload'], true );
		self::assertSame( get_userdata( $user_id )->user_email, $payload['email'] );
		self::assertSame( 0, $payload['is_unsubscribed'], 'Sent to Smaily as subscribed.' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function consent_row( int $user_id ): ?array {
		foreach ( ( new EventQueue() )->pending( 100 ) as $row ) {
			if ( (string) $row['entity_id'] === $user_id . ':consent' ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param int|string $newsletter What WooCommerce posts for the checkbox: 1 ticked, '' not.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_checkout( int $user_id, $newsletter ): array {
		return array(
			'billing_email'   => get_userdata( $user_id )->user_email,
			'user_newsletter' => $newsletter,
		);
	}

	private function block_request( bool $ticked ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( 'smaily-checkout-optin' => array( 'user_newsletter' => $ticked ) ) );
		return $request;
	}

	private function make_customer(): int {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_optin_' . $slug,
				'user_email' => 'optin-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	private function create_wc_customer(): int {
		$user_id = wc_create_new_customer( 'optin-' . wp_generate_password( 6, false ) . '@example.test', '', wp_generate_password( 20 ) );
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	private function make_order( int $customer_id ): \WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();
		$this->created_orders[] = $order->get_id();

		return $order;
	}

	/**
	 * Remove every hook this test's Profile_Settings registered.
	 */
	private function forget_hooks( ?object $service ): void {
		global $wp_filter;

		if ( $service === null ) {
			return;
		}

		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( is_array( $function ) && isset( $function[0] ) && $function[0] === $service ) {
						remove_action( (string) $hook_name, $function, (int) $priority );
					}
				}
			}
		}
	}
}
