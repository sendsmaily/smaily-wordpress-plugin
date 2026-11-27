<?php
/**
 * Integration: a guest who ticks the newsletter box at checkout reaches
 * Smaily in every contact sync mode, whatever the guest switch says
 * (CON-439).
 *
 * The "Sync guest-order contacts too" switch used to sit in front of the
 * tick, so with it off a guest's tick was dropped. These cases fire the REAL
 * WooCommerce checkout hooks (classic and block) with the arguments
 * WooCommerce passes, drain the REAL Smaily queue through the REAL flusher,
 * and assert which contact writes reach the Smaily transport — mocked at the
 * pre_http_request seam.
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

final class GuestCheckoutTickTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var callable */
	private $no_http;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the tick hangs on its checkout hooks.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		wp_set_current_user( 0 );

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
	public function provide_mode_and_checkout(): array {
		$cases = array();
		foreach ( array( ContactSyncMode::MODE_CONSENT, ContactSyncMode::MODE_LEGITIMATE_INTEREST, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			foreach ( array( 'classic', 'block' ) as $checkout ) {
				$cases[ "{$mode} {$checkout}" ] = array( $mode, $checkout );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider provide_mode_and_checkout
	 */
	public function test_a_guest_tick_reaches_smaily_with_the_guest_switch_off( string $mode, string $checkout ): void {
		$this->set_mode( $mode, false );
		$email = $this->guest_email();

		$this->guest_checkout( $checkout, $email, true );

		$writes = $this->contact_writes( $this->flush(), $email );
		self::assertCount( 1, $writes, 'The tick reaches Smaily once.' );
		// Sent as subscribed in every mode, legitimate interest too (CON-442).
		self::assertSame( 0, $writes[0]['is_unsubscribed'], 'Sent as subscribed.' );
	}

	/**
	 * @dataProvider provide_checkout
	 */
	public function test_legitimate_interest_sends_a_guest_who_did_not_tick_only_with_the_switch_on( string $checkout ): void {
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST, false );
		$off = $this->guest_email();
		$this->guest_checkout( $checkout, $off, false );
		self::assertSame( array(), $this->contact_writes( $this->flush(), $off ), 'Switch off: a guest who did not tick stays out.' );

		HookHandler::reset_seen();
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST, true );
		$on = $this->guest_email();
		$this->guest_checkout( $checkout, $on, false );

		$writes = $this->contact_writes( $this->flush(), $on );
		self::assertCount( 1, $writes, 'Switch on: sent as today.' );
		self::assertArrayNotHasKey( 'is_unsubscribed', $writes[0] );
	}

	/**
	 * @dataProvider provide_checkout
	 */
	public function test_consent_and_checkout_only_never_send_a_guest_who_did_not_tick( string $checkout ): void {
		foreach ( array( ContactSyncMode::MODE_CONSENT, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			HookHandler::reset_seen();
			// The switch stored on from an earlier legitimate-interest setup is ignored.
			$this->set_mode( $mode, true );
			$email = $this->guest_email();

			$this->guest_checkout( $checkout, $email, false );

			self::assertSame( array(), $this->contact_writes( $this->flush(), $email ), "{$mode}: nothing reaches Smaily." );
		}
	}

	public function test_an_account_created_at_a_block_checkout_is_subscribed_once_as_the_account(): void {
		$this->set_mode( ContactSyncMode::MODE_CONSENT, false );
		$email = $this->guest_email();
		$order = $this->make_order( 0, $email );

		// The Store API runs the request hook while the order is still a guest's …
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $this->block_request( true ) );

		// … creates the account and sets the customer id …
		$user_id = wc_create_new_customer( $email, '', wp_generate_password( 20 ) );
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		$order->set_customer_id( $user_id );
		$order->save();

		// … and only then fires the processed hook.
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$writes = $this->contact_writes( $this->flush(), $email );
		self::assertCount( 1, $writes, 'The account is subscribed once — not a second time as a guest.' );
		self::assertSame( 0, $writes[0]['is_unsubscribed'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_checkout(): array {
		return array(
			'classic' => array( 'classic' ),
			'block'   => array( 'block' ),
		);
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

	/** A guest's checkout, classic or block, with the box ticked or not. */
	private function guest_checkout( string $checkout, string $email, bool $ticked ): void {
		$order = $this->make_order( 0, $email );

		if ( $checkout === 'classic' ) {
			$posted = array(
				'billing_email'   => $email,
				'user_newsletter' => $ticked ? 1 : '',
			);
			do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted, $order );
			return;
		}

		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $this->block_request( $ticked ) );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	}

	private function block_request( bool $ticked ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( 'smaily-checkout-optin' => array( 'user_newsletter' => $ticked ) ) );
		return $request;
	}

	private function guest_email(): string {
		return 'smly-guest-tick-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
	}

	private function make_order( int $customer_id, string $email ): \WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( $email );
		$order->save();
		$this->created_orders[] = $order->get_id();

		return $order;
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
			if ( strpos( $request['url'], '/api/contact.php' ) === false || ! is_array( $request['body'] ) ) {
				continue;
			}
			foreach ( $request['body'] as $row ) {
				if ( is_array( $row ) && ( $row['email'] ?? '' ) === $email ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	/**
	 * Drain the Smaily queue through a mocked transport, collecting every POST.
	 *
	 * @return array<int, array{url: string, body: mixed}>
	 */
	private function flush(): array {
		$requests = array();
		$fake     = static function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = array(
				'url'  => (string) $url,
				'body' => $args['body'] ?? null,
			);
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

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $requests;
	}
}
