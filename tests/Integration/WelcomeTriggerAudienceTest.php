<?php
/**
 * Integration: the welcome starts on the shopper's own marketing opt-in in the
 * store, within what contact sync sends (PRO-4093).
 *
 * The welcome used to start when WooCommerce created an account (PRO-1682), so
 * every account became a welcomed marketing contact whether or not the shopper
 * asked for marketing. It now follows the opt-in: the checkout box (classic and
 * block), the My Account registration form and My Account → Account details.
 * An account alone, a staff edit in wp-admin and an import start nothing.
 *
 * These cases fire the REAL WordPress / WooCommerce hooks with the argument
 * lists WooCommerce itself passes, drain the REAL queue through the REAL
 * flusher, and assert what reaches the Smaily transport — mocked at the
 * pre_http_request seam (the established pattern — AutomationMarkerPipelineTest).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

final class WelcomeTriggerAudienceTest extends TestCase {

	/** The workflow the welcome trigger is mapped to for these cases. */
	private const WELCOME_WORKFLOW = '4242';

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	private ?Profile_Settings $settings = null;

	/** @var callable */
	private $no_http;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the opt-ins hang on its checkout and account hooks.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '1' );
		$this->set_mode( ContactSyncMode::MODE_CONSENT );
		update_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION, true );
		$this->seed_credentials();
		$this->enable_welcome();
		wp_set_current_user( 0 );

		// The store's newsletter fields: the checkout / registration / account
		// callbacks that record the tick.
		$this->settings = new Profile_Settings();
		$this->settings->register_hooks();

		// WooCommerce mails the new account its password; nothing here is about
		// email delivery, so keep the store quiet.
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );

		// Nothing may leave the test site — the legacy sync still listens on
		// some of these hooks in this process. flush() records its requests at
		// an earlier priority.
		$this->no_http = static function ( $pre ) {
			return $pre !== false ? $pre : array(
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
		add_filter( 'pre_http_request', $this->no_http, 20, 1 );
	}

	protected function tearDown(): void {
		remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		remove_filter( 'pre_http_request', $this->no_http, 20 );
		remove_all_filters( HookHandler::FILTER_WELCOME_ELIGIBLE );
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

		$_POST                     = array();
		$_REQUEST                  = array();
		$GLOBALS['current_screen'] = null;
		HookHandler::reset_seen();
		$this->forget_mode_memo();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_an_account_created_without_opting_in_gets_no_welcome(): void {
		foreach ( array( ContactSyncMode::MODE_CONSENT, ContactSyncMode::MODE_LEGITIMATE_INTEREST, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			$this->set_mode( $mode );
			HookHandler::reset_seen();

			// WooCommerce's own account creation (checkout, My Account) and wp-admin's.
			$shopper = $this->create_wc_customer();
			$staff   = $this->register_user(
				wp_insert_user(
					array(
						'user_login' => 'smly_welcome_staff_' . wp_generate_password( 6, false ),
						'user_email' => 'smly-welcome-staff-' . wp_generate_password( 6, false ) . '@example.test',
						'user_pass'  => wp_generate_password( 20 ),
						'role'       => 'administrator',
					)
				)
			);

			$bodies = $this->flush();

			self::assertSame( array(), $this->welcome_addresses( $bodies ), "No opt-in, no welcome ({$mode})." );
			if ( $mode === ContactSyncMode::MODE_CONSENT ) {
				foreach ( array( $shopper, $staff ) as $user_id ) {
					self::assertFalse( $this->reached_smaily( $bodies, $this->email_of( $user_id ) ), 'The welcome does not add them to Smaily either.' );
				}
			}
		}
	}

	public function test_my_account_registration_tick_gets_the_welcome_after_the_subscribe(): void {
		$_POST = array(
			'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ),
			'register'                   => 'Register',
			'user_newsletter'            => '1',
		);

		// WC_Form_Handler::process_registration() → wc_create_new_customer().
		$user_id = $this->create_wc_customer();

		$bodies = $this->flush();
		$this->assert_welcomed_once( $bodies, $this->email_of( $user_id ) );
		self::assertLessThan(
			$this->first_index( $bodies, $this->email_of( $user_id ), true ),
			$this->first_index( $bodies, $this->email_of( $user_id ), false ),
			'Smaily gets the subscribe before the welcome.'
		);
	}

	public function test_classic_checkout_account_creation_tick_gets_one_welcome(): void {
		$_POST = array(
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
			'user_newsletter'                    => '1',
		);

		// WC_Checkout::process_customer(): wc_create_new_customer(), then
		// woocommerce_checkout_update_user_meta; then the order is processed.
		$user_id = $this->create_wc_customer();
		$posted  = $this->posted_checkout( $this->email_of( $user_id ), 1 );
		do_action( 'woocommerce_checkout_update_user_meta', $user_id, $posted );
		$order = $this->make_order( $user_id, $this->email_of( $user_id ) );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted, $order );

		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );
	}

	public function test_block_checkout_tick_gets_the_welcome(): void {
		$user_id = $this->make_customer();
		$this->block_checkout( $user_id, $this->email_of( $user_id ) );

		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );
	}

	public function test_my_account_details_tick_gets_the_welcome(): void {
		$user_id = $this->make_customer();
		wp_set_current_user( $user_id );
		$_REQUEST = array( 'save-account-details-nonce' => wp_create_nonce( 'save_account_details' ) );
		$_POST    = array(
			'action'          => 'save_account_details',
			'user_newsletter' => '1',
		);

		// WC_Form_Handler::save_account_details().
		do_action( 'woocommerce_save_account_details', $user_id );

		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );
	}

	public function test_a_guest_checkout_tick_gets_the_welcome_where_contact_sync_sends_guests(): void {
		$this->set_mode( ContactSyncMode::MODE_CHECKOUT_OPTIN );
		$email = 'smly-welcome-guest-' . wp_generate_password( 6, false ) . '@example.test';

		$order  = $this->make_order( 0, $email );
		$posted = $this->posted_checkout( $email, 1 );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted, $order );

		$this->assert_welcomed_once( $this->flush(), $email );
	}

	public function test_legitimate_interest_checkout_tick_gets_the_welcome_each_time(): void {
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		$user_id = $this->make_customer();

		$this->classic_checkout( $user_id );
		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );

		// A later order, ticked again: this mode keeps no consent record, so the
		// store cannot tell it from a first opt-in.
		HookHandler::reset_seen();
		$this->classic_checkout( $user_id );
		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );
	}

	public function test_no_welcome_where_contact_sync_does_not_send_the_contact(): void {
		// Consent mode does not sync guests unless the merchant includes them.
		$guest = 'smly-welcome-guest-' . wp_generate_password( 6, false ) . '@example.test';
		$order = $this->make_order( 0, $guest );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $this->posted_checkout( $guest, 1 ), $order );

		// Contact sync switched off: a registration tick sends nothing.
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '' );
		$_POST   = array(
			'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ),
			'register'                   => 'Register',
			'user_newsletter'            => '1',
		);
		$user_id = $this->create_wc_customer();

		$bodies = $this->flush();
		self::assertSame( array(), $this->welcome_addresses( $bodies ) );
		self::assertFalse( $this->reached_smaily( $bodies, $guest ) );
		self::assertFalse( $this->reached_smaily( $bodies, $this->email_of( $user_id ) ) );
	}

	public function test_an_opt_in_recorded_by_staff_or_an_import_gets_no_welcome(): void {
		$staff_edited = $this->make_customer();
		$imported     = $this->make_customer();

		// Users → Edit user in wp-admin, the box ticked by staff.
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'user-edit' );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'update-user_' . $staff_edited ) );
		$_POST    = array( 'user_newsletter' => '1' );
		do_action( 'edit_user_profile_update', $staff_edited );
		$GLOBALS['current_screen'] = null;

		// An import writes the consent record directly.
		update_user_meta( $imported, ContactAudience::OPTIN_META, '1' );

		self::assertSame( '1', get_user_meta( $staff_edited, ContactAudience::OPTIN_META, true ), 'The staff edit must really record the opt-in, else this proves nothing.' );

		$bodies = $this->flush();
		self::assertSame( array(), $this->welcome_addresses( $bodies ) );
		self::assertTrue( $this->reached_smaily( $bodies, $this->email_of( $staff_edited ) ), 'The opt-in itself still reaches Smaily.' );
		self::assertTrue( $this->reached_smaily( $bodies, $this->email_of( $imported ) ) );
	}

	public function test_a_resubscription_in_the_store_gets_the_welcome_again(): void {
		$user_id = $this->make_customer();
		$this->classic_checkout( $user_id );
		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );

		// They unsubscribe in Smaily; the reconciler mirrors it into the store.
		ContactReconciler::run_suppressed(
			static function () use ( $user_id ): void {
				update_user_meta( $user_id, ContactAudience::OPTIN_META, 0 );
			}
		);

		// A later checkout: the box is offered again, and they tick it.
		HookHandler::reset_seen();
		$this->classic_checkout( $user_id );

		$this->assert_welcomed_once( $this->flush(), $this->email_of( $user_id ) );
	}

	public function test_the_filter_can_veto_a_storefront_act(): void {
		add_filter(
			HookHandler::FILTER_WELCOME_ELIGIBLE,
			static function ( bool $eligible, int $user_id, string $source ): bool {
				return $source === HookHandler::SOURCE_REGISTRATION ? false : $eligible;
			},
			10,
			3
		);
		$_POST = array(
			'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ),
			'register'                   => 'Register',
			'user_newsletter'            => '1',
		);

		$this->create_wc_customer();

		self::assertSame( array(), $this->welcome_addresses( $this->flush() ) );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Switch the contact-sync mode. The plugin's registered HookHandler lives
	 * for the whole suite process and reads the mode once (a request never
	 * changes it), so its memo is cleared too.
	 */
	private function set_mode( string $mode ): void {
		update_option( ContactSyncMode::OPTION_MODE, $mode );
		$this->forget_mode_memo();
	}

	/** Clear the registered HookHandler's mode memo, so no later test inherits this one's mode. */
	private function forget_mode_memo(): void {
		global $wp_filter;

		foreach ( $wp_filter['woocommerce_created_customer']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof HookHandler ) {
					foreach ( array( 'mode', 'audience' ) as $memo ) {
						$prop = new \ReflectionProperty( HookHandler::class, $memo );
						$prop->setAccessible( true );
						$prop->setValue( $function[0], null );
					}
				}
			}
		}
	}

	/** A logged-in buyer's classic checkout with the box ticked. */
	private function classic_checkout( int $user_id ): void {
		$posted = $this->posted_checkout( $this->email_of( $user_id ), 1 );
		do_action( 'woocommerce_checkout_update_user_meta', $user_id, $posted );
		$order = $this->make_order( $user_id, $this->email_of( $user_id ) );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted, $order );
	}

	/** A block checkout with the box ticked: the request hook, then the processed hook. */
	private function block_checkout( int $user_id, string $email ): void {
		$order   = $this->make_order( $user_id, $email );
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( 'smaily-checkout-optin' => array( 'user_newsletter' => true ) ) );

		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $request );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	}

	/**
	 * @param int|string $newsletter What WooCommerce posts for the checkbox: 1 ticked, '' not.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_checkout( string $email, $newsletter ): array {
		return array(
			'billing_email'   => $email,
			'user_newsletter' => $newsletter,
		);
	}

	/**
	 * @param array<int, mixed> $bodies
	 */
	private function assert_welcomed_once( array $bodies, string $email ): void {
		self::assertSame( array( $email ), array_column( $this->welcome_addresses( $bodies ), 'email' ), 'Exactly one welcome, to this shopper.' );
	}

	/**
	 * Every address row that reached the transport on the welcome workflow.
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function welcome_addresses( array $bodies ): array {
		$rows = array();
		foreach ( $bodies as $body ) {
			if ( ! is_array( $body ) || ! isset( $body['autoresponder'] ) || (string) $body['autoresponder'] !== self::WELCOME_WORKFLOW ) {
				continue;
			}
			foreach ( (array) $body['addresses'] as $address ) {
				$rows[] = $address;
			}
		}
		return $rows;
	}

	/**
	 * @param array<int, mixed> $bodies
	 */
	private function reached_smaily( array $bodies, string $email ): bool {
		return $this->first_index( $bodies, $email, false ) !== null || $this->first_index( $bodies, $email, true ) !== null;
	}

	/**
	 * Position of the first request carrying $email: a welcome (autoresponder)
	 * call when $welcome, else a contact write.
	 *
	 * @param array<int, mixed> $bodies
	 */
	private function first_index( array $bodies, string $email, bool $welcome ): ?int {
		foreach ( $bodies as $index => $body ) {
			$is_welcome = is_array( $body ) && isset( $body['autoresponder'] );
			if ( $is_welcome !== $welcome ) {
				continue;
			}
			$encoded = is_array( $body ) ? (string) wp_json_encode( $body ) : (string) $body;
			if ( strpos( $encoded, $email ) !== false ) {
				return (int) $index;
			}
		}
		return null;
	}

	/**
	 * Drain the Smaily queue through a mocked transport, collecting every POST.
	 *
	 * @return array<int, mixed>
	 */
	private function flush(): array {
		$bodies = array();
		$fake   = static function ( $pre, $args ) use ( &$bodies ) {
			$bodies[] = isset( $args['body'] ) ? $args['body'] : null;
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
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $bodies;
	}

	/** Welcome on, mapped to one workflow — through the real settings route. */
	private function enable_welcome(): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'welcomeEnabled'     => true,
					'automationMappings' => array(
						array(
							'triggerType'       => 'welcome',
							'language'          => 'default',
							'accountKey'        => 'default',
							'workflowId'        => self::WELCOME_WORKFLOW,
							'isDefaultFallback' => true,
						),
					),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/** LEGACY_OPTION_KEY / "default" account credentials — mirrors CartPipelineTest. */
	private function seed_credentials(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	/** Real WooCommerce customer creation — fires user_register AND woocommerce_created_customer. */
	private function create_wc_customer(): int {
		return $this->register_user(
			wc_create_new_customer( 'smly-welcome-' . wp_generate_password( 6, false ) . '@example.test', '', wp_generate_password( 20 ) )
		);
	}

	/** An existing customer account (created outside this case's opt-in). */
	private function make_customer(): int {
		$user_id = $this->create_wc_customer();
		HookHandler::reset_seen();
		return $user_id;
	}

	/**
	 * @param int|\WP_Error $user_id
	 */
	private function register_user( $user_id ): int {
		self::assertIsInt( $user_id, 'User creation failed: ' . ( is_wp_error( $user_id ) ? $user_id->get_error_message() : '' ) );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function make_order( int $customer_id, string $email ): \WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( $email );
		$order->save();
		$this->created_orders[] = $order->get_id();

		return $order;
	}

	private function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
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
