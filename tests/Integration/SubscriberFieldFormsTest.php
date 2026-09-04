<?php
/**
 * Integration: the store can COLLECT the contact fields the merchant ticked —
 * whichever plugin version wrote the selection (PRO-1743).
 *
 * The sync reads the selection through one interpreter (PRO-1683/PRO-1684),
 * but the two legacy readers that act on it still read the option as a map of
 * name => on/off. On a wizard-configured store — where the selection is a list
 * of names — that map read matched nothing, so:
 *   - the Phone / Gender / Birthday inputs were never printed on the WordPress
 *     profile, the WooCommerce account form or the checkout, leaving the store
 *     unable to collect the very meta the sync now sends;
 *   - a guest ticking the checkout newsletter box was sent to Smaily without
 *     even an email address;
 *   - a registered customer's contact update — profile save, account details,
 *     customer created, or the registered branch of the same checkout opt-in —
 *     was sent with no email address and no fields at all (PRO-1772).
 *
 * These cases drive the real render/opt-in paths on a real WooCommerce install
 * with only the Smaily transport faked, against BOTH stored shapes.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\SubscriberPayloadBuilder;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\LegacySettingsPage;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;
use Smaily_Connect\Integrations\WooCommerce\Subscriber_Synchronization;

final class SubscriberFieldFormsTest extends TestCase {

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();

		// The plugin registers its OWN Profile_Settings at load when credentials
		// happen to be saved, and that instance caches its field list for the
		// life of the process — it would answer for a selection some earlier
		// test saved. Each case below registers a fresh one instead.
		$this->forget_hooks( Profile_Settings::class );

		// The forms are live as soon as WooCommerce is active and credentials
		// are saved — the wizard being finished or not never gated them.
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	protected function tearDown(): void {
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

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_a_wizard_configured_store_prints_the_inputs_that_collect_the_ticked_fields(): void {
		$this->save_wizard_selection( array( 'first_name', 'user_phone', 'user_gender', 'birthday' ) );

		$profile = $this->render_hook( 'show_user_profile' );
		self::assertStringContainsString( 'name="user_phone"', $profile, 'A ticked Phone field needs an input on the profile, or the store can never collect it.' );
		self::assertStringContainsString( 'name="user_gender"', $profile );
		self::assertStringContainsString( 'name="user_dob"', $profile, 'The form field is `user_dob`; the contact field is `birthday`.' );

		$account = $this->render_hook( 'woocommerce_edit_account_form' );
		self::assertStringContainsString( 'name="user_phone"', $account );
		self::assertStringContainsString( 'name="user_gender"', $account );
		self::assertStringContainsString( 'name="user_dob"', $account );

		$billing = $this->checkout_fields()['billing'];
		self::assertArrayHasKey( 'user_gender', $billing );
		self::assertArrayHasKey( 'user_dob', $billing );
		self::assertArrayNotHasKey( 'user_phone', $billing, 'Phone is hidden at checkout by design — WooCommerce collects its own billing phone.' );
	}

	public function test_a_field_the_merchant_left_unticked_is_not_asked_for(): void {
		$this->save_wizard_selection( array( 'first_name', 'last_name' ) );

		$profile = $this->render_hook( 'show_user_profile' );
		self::assertStringNotContainsString( 'name="user_phone"', $profile, 'Nothing is being synced from it, so the customer is not asked for it.' );
		self::assertStringNotContainsString( 'name="user_gender"', $profile );
		self::assertStringNotContainsString( 'name="user_dob"', $profile );

		self::assertSame( array(), $this->checkout_fields()['billing'] );
	}

	public function test_a_store_configured_before_the_wizard_asks_for_exactly_what_it_always_did(): void {
		LegacySettingsPage::save_subscriber_sync_fields(
			array(
				'user_phone'  => 'on',
				'user_gender' => 'on',
			)
		);

		$profile = $this->render_hook( 'show_user_profile' );
		self::assertStringContainsString( 'name="user_phone"', $profile );
		self::assertStringContainsString( 'name="user_gender"', $profile );
		self::assertStringNotContainsString( 'name="user_dob"', $profile, 'A box the merchant left unticked stays unticked.' );

		$billing = $this->checkout_fields()['billing'];
		self::assertArrayHasKey( 'user_gender', $billing );
		self::assertArrayNotHasKey( 'user_dob', $billing );
	}

	public function test_a_guest_checkout_optin_sends_the_wizard_selection(): void {
		$this->save_wizard_selection( array( 'first_name', 'last_name' ) );

		$posted = $this->guest_checkout_optin();

		self::assertSame( 'guest@example.test', $posted['email'], 'Without an email address there is no contact to create at all.' );
		self::assertArrayHasKey( 'store', $posted );
		self::assertSame( 'Mari', $posted['first_name'] );
		self::assertSame( 'Maasikas', $posted['last_name'] );
		self::assertArrayNotHasKey( 'site_title', $posted, 'An unticked field is not sent.' );
	}

	public function test_a_guest_checkout_optin_on_a_store_configured_before_the_wizard_is_unchanged(): void {
		LegacySettingsPage::save_subscriber_sync_fields(
			array(
				'first_name' => 'on',
				'site_title' => 'on',
			)
		);

		$posted = $this->guest_checkout_optin();

		self::assertSame( 'guest@example.test', $posted['email'] );
		self::assertArrayHasKey( 'store', $posted );
		self::assertSame( 'Mari', $posted['first_name'] );
		self::assertArrayHasKey( 'site_title', $posted );
		self::assertArrayNotHasKey( 'last_name', $posted, 'A legacy false is a real "do not send this".' );
	}

	public function test_a_registered_customers_update_sends_the_wizard_selection(): void {
		$this->save_wizard_selection( array( 'first_name', 'user_phone', 'birthday' ) );

		$user_id = $this->make_customer(
			array(
				'user_phone' => '+372 5555 1234',
				'user_dob'   => '1985-03-28',
				'nickname'   => 'Mari M',
			)
		);

		$posted = $this->registered_checkout_optin( $user_id );

		$user = get_userdata( $user_id );
		self::assertSame( $user->user_email, $posted['email'], 'Without an email address there is no contact to update at all.' );
		self::assertArrayHasKey( 'store', $posted );
		self::assertSame( 'Mari', $posted['first_name'] );
		self::assertSame( '+372 5555 1234', $posted['user_phone'] );
		self::assertSame( '1985-03-28', $posted['birthday'], 'The form field is `user_dob`; the contact field is `birthday`.' );
		self::assertArrayNotHasKey( 'nickname', $posted, 'An unticked field is not sent, even when the store holds a value for it.' );
	}

	public function test_a_registered_customers_update_on_a_store_configured_before_the_wizard_is_unchanged(): void {
		LegacySettingsPage::save_subscriber_sync_fields(
			array(
				'user_phone' => 'on',
				'nickname'   => 'on',
			)
		);

		$user_id = $this->make_customer(
			array(
				'user_phone' => '+372 5555 1234',
				'user_dob'   => '1985-03-28',
				'nickname'   => 'Mari M',
			)
		);

		$posted = $this->registered_checkout_optin( $user_id );

		$user = get_userdata( $user_id );
		self::assertSame( $user->user_email, $posted['email'] );
		self::assertArrayHasKey( 'store', $posted );
		self::assertSame( '+372 5555 1234', $posted['user_phone'] );
		self::assertSame( 'Mari M', $posted['nickname'] );
		self::assertArrayNotHasKey( 'birthday', $posted, 'A legacy false is a real "do not send this".' );
		self::assertArrayNotHasKey( 'first_name', $posted );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Save the subscribers tab through the REAL wizard/settings route — the
	 * option shape under test is whatever that route writes.
	 *
	 * @param array<int, string> $fields
	 */
	private function save_wizard_selection( array $fields ): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled' => true,
					'syncFields'            => $fields,
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/**
	 * Whatever the plugin prints on a real form hook — fired as WordPress and
	 * WooCommerce fire it, not by calling the callback by hand.
	 */
	private function render_hook( string $hook ): string {
		$settings = new Profile_Settings();
		$settings->register_hooks();

		try {
			ob_start();
			if ( $hook === 'show_user_profile' ) {
				do_action( $hook, wp_get_current_user() );
			} else {
				do_action( $hook );
			}

			return (string) ob_get_clean();
		} finally {
			$this->forget_hooks( $settings );
		}
	}

	/**
	 * The checkout fields WooCommerce ends up with.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function checkout_fields(): array {
		$settings = new Profile_Settings();
		$settings->register_hooks();

		try {
			return (array) apply_filters(
				'woocommerce_checkout_fields',
				array(
					'billing'  => array(),
					'shipping' => array(),
					'account'  => array(),
					'order'    => array(),
				)
			);
		} finally {
			$this->forget_hooks( $settings );
		}
	}

	/**
	 * Remove every hook a service registered — the instances here are local to
	 * one test and must not outlive it on the global hook registry.
	 *
	 * @param object|class-string $service An instance, or a class whose every
	 *                                     registered instance is forgotten.
	 */
	private function forget_hooks( $service ): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( ! is_array( $function ) || ! isset( $function[0] ) || ! is_object( $function[0] ) ) {
						continue;
					}

					$matches = is_string( $service )
						? $function[0] instanceof $service
						: $function[0] === $service;

					if ( $matches ) {
						remove_action( (string) $hook_name, $function, (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * A guest ticking the newsletter box at checkout, driven through the real
	 * legacy opt-in path (the one still in charge until the wizard finishes).
	 *
	 * @return array<string, mixed> What the Smaily transport was handed.
	 */
	private function guest_checkout_optin(): array {
		$order = wc_create_order();
		$order->set_billing_email( 'guest@example.test' );
		$order->set_billing_first_name( 'Mari' );
		$order->set_billing_last_name( 'Maasikas' );
		$order->save();

		return $this->checkout_optin( $order );
	}

	/**
	 * A registered customer ticking the same box, which takes the other branch
	 * of the same opt-in: the contact is built from the user's profile
	 * (`Data_Handler::get_user_data()`) rather than the billing address.
	 *
	 * @return array<string, mixed> What the Smaily transport was handed.
	 */
	private function registered_checkout_optin( int $user_id ): array {
		$order = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'billing@example.test' );
		$order->save();

		return $this->checkout_optin( $order );
	}

	/**
	 * A registered, newsletter-ticking customer whose profile the store filled.
	 *
	 * @param array<string, string> $meta Profile meta the store collected.
	 */
	private function make_customer( array $meta ): int {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_forms_' . $slug,
				'user_email' => 'forms-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'first_name' => 'Mari',
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}

		return $user_id;
	}

	/**
	 * Drive the real checkout opt-in for an order, with only the Smaily
	 * transport faked.
	 *
	 * @return array<string, mixed> What the Smaily transport was handed.
	 */
	private function checkout_optin( \WC_Order $order ): array {
		update_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION, true );
		$this->created_orders[] = $order->get_id();

		$posted = array();
		$fake   = static function ( $pre, $args ) use ( &$posted ) {
			$posted = isset( $args['body'] ) ? $args['body'] : array();
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 101,
						'message' => 'OK',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			$sync = new Subscriber_Synchronization( new Options() );
			$sync->smaily_checkout_subscribe_customer(
				$order->get_id(),
				array( 'user_newsletter' => 1 ),
				$order
			);
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertIsArray( $posted, 'The opt-in must reach the Smaily transport.' );

		return $posted;
	}

	/**
	 * Guards the assumption the selection is read through one interpreter: if
	 * the canonical name for the birthday field ever stops being reachable
	 * under the legacy `user_dob` key, the forms above stop matching silently.
	 */
	public function test_the_form_readers_and_the_sync_read_one_selection(): void {
		$this->save_wizard_selection( array( 'birthday' ) );

		self::assertContains( 'user_dob', SubscriberPayloadBuilder::effective_selection_legacy_keys() );
	}
}
