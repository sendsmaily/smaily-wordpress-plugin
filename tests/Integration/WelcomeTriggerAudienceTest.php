<?php
/**
 * Integration: only a shopper's account triggers the welcome automation (PRO-1682).
 *
 * The welcome trigger used to fire on any `user_register`, so a staff account
 * created in wp-admin — or an account an unrelated plugin created — became an
 * opted-in marketing contact with no customer relationship behind it. The
 * trigger now fires from `woocommerce_created_customer`, WooCommerce's own "a
 * shopper got an account" signal.
 *
 * These cases create REAL users through the REAL paths on the running store and
 * assert which of them reach the Smaily transport as a welcome automation, with
 * only the transport mocked at the pre_http_request seam (the established
 * pattern — AutomationMarkerPipelineTest).
 *
 * `wc_create_new_customer()` IS the account creation of every WooCommerce
 * shopper flow — classic checkout (`WC_Checkout::process_customer`), block
 * checkout (`StoreApi\Routes\V1\Checkout::create_customer_account`), My Account
 * registration (`WC_Form_Handler::process_registration`) and the
 * order-confirmation "create an account" block all call it, and it is what
 * fires `woocommerce_created_customer`.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class WelcomeTriggerAudienceTest extends TestCase {

	/** The workflow the welcome trigger is mapped to for these cases. */
	private const WELCOME_WORKFLOW = '4242';

	/** A store's own shopper role (wholesale / VIP), not WooCommerce's `customer`. */
	private const SHOPPER_ROLE = 'smly_test_wholesale';

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the welcome trigger hangs on its customer-creation hook.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		$this->seed_credentials();
		$this->enable_welcome();

		add_role( self::SHOPPER_ROLE, 'Wholesale customer', array( 'read' => true ) );
		// WooCommerce mails the new account its password; nothing here is about
		// email delivery, so keep the store quiet.
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
	}

	protected function tearDown(): void {
		remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		remove_all_filters( HookHandler::FILTER_WELCOME_ELIGIBLE );
		remove_all_filters( 'woocommerce_new_customer_data' );
		remove_role( self::SHOPPER_ROLE );

		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_shopper_account_created_by_woocommerce_gets_the_welcome(): void {
		$user_id = $this->create_woocommerce_customer( 'shopper' );

		$addresses = $this->welcome_addresses( $this->flush() );

		self::assertCount( 1, $addresses, 'A WooCommerce-created account must be enrolled exactly once.' );
		self::assertSame( $this->email_of( $user_id ), $addresses[0]['email'] );
	}

	public function test_an_account_created_during_checkout_still_gets_the_welcome(): void {
		// The exact call classic and block checkout make when the shopper ticks
		// "create an account" (WC_Checkout::process_customer / StoreApi Checkout).
		$user_id = $this->register_user(
			wc_create_new_customer(
				'smly-welcome-checkout-' . wp_generate_password( 6, false ) . '@example.test',
				'',
				'',
				array(
					'first_name' => 'Mari',
					'last_name'  => 'Ostja',
					'source'     => 'checkout',
				)
			)
		);

		$addresses = $this->welcome_addresses( $this->flush() );

		self::assertCount( 1, $addresses );
		self::assertSame( $this->email_of( $user_id ), $addresses[0]['email'] );
	}

	public function test_a_custom_shopper_role_still_gets_the_welcome(): void {
		// Wholesale / VIP plugins swap the role through this WooCommerce filter;
		// the account is still created by a WooCommerce flow, so the trigger —
		// which never looks at the role — must still fire.
		add_filter(
			'woocommerce_new_customer_data',
			static function ( array $data ): array {
				$data['role'] = self::SHOPPER_ROLE;
				return $data;
			}
		);

		$user_id = $this->create_woocommerce_customer( 'wholesale' );

		self::assertContains(
			self::SHOPPER_ROLE,
			(array) get_userdata( $user_id )->roles,
			'The custom role must really be on the account, else this proves nothing.'
		);

		$addresses = $this->welcome_addresses( $this->flush() );
		self::assertCount( 1, $addresses );
		self::assertSame( $this->email_of( $user_id ), $addresses[0]['email'] );
	}

	public function test_a_staff_account_created_in_the_admin_gets_no_welcome(): void {
		$this->create_wp_user( 'staff', 'administrator' );
		$this->create_wp_user( 'editor', 'editor' );

		self::assertSame(
			array(),
			$this->welcome_addresses( $this->flush() ),
			'Adding staff in wp-admin must never enrol them in a marketing automation.'
		);
	}

	public function test_an_account_created_by_an_unrelated_plugin_gets_no_welcome(): void {
		// A membership / forum plugin calling wp_insert_user() directly: the WP
		// default role, no WooCommerce flow, no customer relationship.
		$this->create_wp_user( 'plugin', '' );

		self::assertSame(
			array(),
			$this->welcome_addresses( $this->flush() ),
			'A bare wp_insert_user() is not a shopper signal.'
		);
	}

	public function test_a_store_can_widen_the_trigger_back_with_the_filter(): void {
		add_filter(
			HookHandler::FILTER_WELCOME_ELIGIBLE,
			static function ( bool $eligible, int $user_id, string $source ): bool {
				return $source === 'user_register' ? true : $eligible;
			},
			10,
			3
		);

		$user_id = $this->create_wp_user( 'widened', '' );

		$addresses = $this->welcome_addresses( $this->flush() );
		self::assertCount( 1, $addresses, 'The documented filter must be able to admit a non-WooCommerce registration.' );
		self::assertSame( $this->email_of( $user_id ), $addresses[0]['email'] );
	}

	// --- helpers -------------------------------------------------------------

	/** Real WooCommerce customer creation — fires user_register AND created_customer. */
	private function create_woocommerce_customer( string $slug ): int {
		return $this->register_user(
			wc_create_new_customer( 'smly-welcome-' . $slug . '-' . wp_generate_password( 6, false ) . '@example.test' )
		);
	}

	/** Plain WordPress user creation — the wp-admin / unrelated-plugin path. */
	private function create_wp_user( string $slug, string $role ): int {
		$data = array(
			'user_login' => 'smly_welcome_' . $slug . '_' . wp_generate_password( 6, false ),
			'user_email' => 'smly-welcome-' . $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
			'user_pass'  => wp_generate_password( 20 ),
		);
		if ( $role !== '' ) {
			$data['role'] = $role;
		}

		return $this->register_user( wp_insert_user( $data ) );
	}

	/**
	 * @param int|\WP_Error $user_id
	 */
	private function register_user( $user_id ): int {
		self::assertIsInt( $user_id, 'User creation failed: ' . ( is_wp_error( $user_id ) ? $user_id->get_error_message() : '' ) );
		$this->created_users[] = $user_id;
		return $user_id;
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
			if ( ! is_array( $body ) || ! isset( $body['autoresponder'] ) ) {
				continue;
			}
			if ( (string) $body['autoresponder'] !== self::WELCOME_WORKFLOW ) {
				continue;
			}
			foreach ( (array) $body['addresses'] as $address ) {
				$rows[] = $address;
			}
		}
		return $rows;
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

	private function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}
}
