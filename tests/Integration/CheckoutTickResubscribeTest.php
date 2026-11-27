<?php
/**
 * Integration: under legitimate interest a shopper who ticks the newsletter
 * box at checkout becomes a subscriber again in Smaily (CON-442).
 *
 * The mode sent its contacts without a subscription status, and Smaily keeps
 * an existing contact's status when none is sent — so a shopper who had
 * unsubscribed stayed unsubscribed although they ticked the box again. These
 * cases fire the REAL WooCommerce checkout hooks (classic and block, guest
 * and registered) with the arguments WooCommerce passes, drain the REAL
 * Smaily queue through the REAL flusher, and apply each contact write to a
 * model of Smaily's contact store at the pre_http_request seam: a row with
 * `is_unsubscribed` sets the status, a row without one keeps an existing
 * contact's status and creates a new contact as subscribed.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

final class CheckoutTickResubscribeTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	/**
	 * The modelled Smaily contact store: lowercased email => is_unsubscribed.
	 *
	 * @var array<string, int>
	 */
	private array $smaily = array();

	/** @var callable */
	private $no_http;

	private ?Profile_Settings $settings = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the tick hangs on its checkout hooks.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		wp_set_current_user( 0 );
		$this->smaily = array();

		update_option( 'smly_plus_setup_completed', true );
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '1' );
		update_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION, true );
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );

		// Nothing may leave the test site — the legacy sync still listens on
		// some of these hooks in this process. flush() answers at an earlier
		// priority.
		$this->no_http = static function ( $pre ) {
			return $pre !== false ? $pre : self::ok();
		};
		add_filter( 'pre_http_request', $this->no_http, 20, 1 );

		// Records a registered buyer's classic-checkout tick (consent mode).
		$this->settings = new Profile_Settings();
		$this->settings->register_hooks();
	}

	protected function tearDown(): void {
		remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		remove_filter( 'pre_http_request', $this->no_http, 20 );
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

		HookHandler::reset_seen();
		$this->forget_mode_memo();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_buyer_and_checkout(): array {
		$cases = array();
		foreach ( array( 'guest', 'registered' ) as $buyer ) {
			foreach ( array( 'classic', 'block' ) as $checkout ) {
				$cases[ "{$buyer} {$checkout}" ] = array( $buyer, $checkout );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider provide_buyer_and_checkout
	 */
	public function test_legitimate_interest_tick_makes_an_unsubscribed_contact_a_subscriber( string $buyer, string $checkout ): void {
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST, false );
		$email                  = $this->email();
		$this->smaily[ $email ] = 1;

		$this->checkout( $buyer, $checkout, $email, true );
		$this->flush();

		self::assertSame( 0, $this->smaily[ $email ], 'The tick makes the contact a subscriber again.' );
	}

	/**
	 * @dataProvider provide_buyer_and_checkout
	 */
	public function test_legitimate_interest_without_a_tick_leaves_the_status_to_smaily( string $buyer, string $checkout ): void {
		// The guest switch on, so the guest who did not tick is sent too.
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST, true );
		$email                  = $this->email();
		$this->smaily[ $email ] = 1;

		$requests = $this->checkout_and_flush( $buyer, $checkout, $email, false );

		self::assertNotSame( array(), $this->contact_writes( $requests, $email ), 'The contact is still sent, as before.' );
		foreach ( $this->contact_writes( $requests, $email ) as $row ) {
			self::assertArrayNotHasKey( 'is_unsubscribed', $row, 'No status is sent.' );
		}
		self::assertSame( 1, $this->smaily[ $email ], 'An unsubscribed contact stays unsubscribed.' );
	}

	/**
	 * @dataProvider provide_buyer_and_checkout
	 */
	public function test_consent_and_checkout_only_tick_subscribes_as_before( string $buyer, string $checkout ): void {
		foreach ( array( ContactSyncMode::MODE_CONSENT, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			HookHandler::reset_seen();
			$this->set_mode( $mode, false );
			$email                  = $this->email();
			$this->smaily[ $email ] = 1;

			$this->checkout( $buyer, $checkout, $email, true );
			$this->flush();

			self::assertSame( 0, $this->smaily[ $email ], "{$mode}: the tick subscribes." );
		}
	}

	/**
	 * @dataProvider provide_buyer_and_checkout
	 */
	public function test_consent_and_checkout_only_without_a_tick_send_nothing( string $buyer, string $checkout ): void {
		foreach ( array( ContactSyncMode::MODE_CONSENT, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			HookHandler::reset_seen();
			$this->set_mode( $mode, true );
			$email                  = $this->email();
			$this->smaily[ $email ] = 1;

			$requests = $this->checkout_and_flush( $buyer, $checkout, $email, false );

			self::assertSame( array(), $this->contact_writes( $requests, $email ), "{$mode}: nothing reaches Smaily." );
			self::assertSame( 1, $this->smaily[ $email ] );
		}
	}

	// --- helpers -------------------------------------------------------------

	private function set_mode( string $mode, bool $include_guests ): void {
		update_option( ContactSyncMode::OPTION_MODE, $mode );
		update_option( ContactSyncMode::OPTION_INCLUDE_GUESTS, $include_guests ? '1' : '' );
		$this->forget_mode_memo();
	}

	/**
	 * The plugin's registered HookHandler lives for the whole suite process
	 * and memoises the mode (CLAUDE.md, PRO-4093) — clear it.
	 */
	private function forget_mode_memo(): void {
		global $wp_filter;

		foreach ( $wp_filter['woocommerce_checkout_order_processed']->callbacks as $callbacks ) {
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

	/**
	 * @return array<int, array{url: string, body: mixed}>
	 */
	private function checkout_and_flush( string $buyer, string $checkout, string $email, bool $ticked ): array {
		$this->checkout( $buyer, $checkout, $email, $ticked );
		return $this->flush();
	}

	/**
	 * A checkout, classic or block, by a guest or by a customer with an
	 * account, with the box ticked or not — the hooks WooCommerce fires, in
	 * its order.
	 */
	private function checkout( string $buyer, string $checkout, string $email, bool $ticked ): void {
		$customer_id = 0;
		if ( $buyer === 'registered' ) {
			$customer_id = wc_create_new_customer( $email, '', wp_generate_password( 20 ) );
			self::assertIsInt( $customer_id );
			$this->created_users[] = $customer_id;
		}

		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( $email );
		$order->save();
		$this->created_orders[] = $order->get_id();

		if ( $checkout === 'classic' ) {
			$posted = array(
				'billing_email'   => $email,
				'user_newsletter' => $ticked ? 1 : '',
			);
			do_action( 'woocommerce_checkout_update_user_meta', $customer_id, $posted );
			do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted, $order );
			return;
		}

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( 'smaily-checkout-optin' => array( 'user_newsletter' => $ticked ) ) );
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $request );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	}

	private function email(): string {
		return 'smly-resubscribe-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
	}

	/**
	 * The contact rows for $email that reached Smaily's contact endpoint.
	 *
	 * @param array<int, array{url: string, body: mixed}> $requests
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contact_writes( array $requests, string $email ): array {
		$rows = array();
		foreach ( $requests as $request ) {
			foreach ( $this->rows( $request ) as $row ) {
				if ( ( $row['email'] ?? '' ) === $email ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	/**
	 * @param array{url: string, body: mixed} $request
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( array $request ): array {
		if ( strpos( $request['url'], '/api/contact.php' ) === false || ! is_array( $request['body'] ) ) {
			return array();
		}
		return array_values( array_filter( $request['body'], 'is_array' ) );
	}

	/**
	 * Drain the Smaily queue, applying every contact write to the modelled
	 * contact store in the order Smaily receives it, and collect every request.
	 *
	 * @return array<int, array{url: string, body: mixed}>
	 */
	private function flush(): array {
		$requests = array();
		$fake     = function ( $pre, $args, $url ) use ( &$requests ) {
			$request    = array(
				'url'  => (string) $url,
				'body' => $args['body'] ?? null,
			);
			$requests[] = $request;

			foreach ( $this->rows( $request ) as $row ) {
				$email = strtolower( (string) ( $row['email'] ?? '' ) );
				if ( isset( $row['is_unsubscribed'] ) ) {
					$this->smaily[ $email ] = (int) $row['is_unsubscribed'];
				} elseif ( ! isset( $this->smaily[ $email ] ) ) {
					$this->smaily[ $email ] = 0;
				}
			}

			return self::ok();
		};

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $requests;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function ok(): array {
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
	}
}
