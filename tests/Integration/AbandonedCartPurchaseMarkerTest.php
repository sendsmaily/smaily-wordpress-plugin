<?php
/**
 * Integration: a reminded shopper who buys is marked, and stops being reminded (PRO-1723).
 *
 * The merchant's Smaily workflow sends the abandoned-cart follow-up letters,
 * and until now nothing told it the shopper had bought — so the series ran to
 * the end regardless. The plugin now writes `abandoned_cart_purchased_at` onto
 * the contact at the order-placed moment, which the workflow uses as its exit
 * condition, and withdraws a reminder still sitting in the queue.
 *
 * These cases drive the REAL pipeline on the running store — cart tracker →
 * sweeper → CartFlusher → order hook → Flusher — with only the Smaily API
 * mocked at the pre_http_request seam (the established pattern,
 * AutomationMarkerPipelineTest / CartPipelineTest).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CartHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class AbandonedCartPurchaseMarkerTest extends TestCase {

	/** The marker's wire shape — UTC `Y-m-d H:i:s`, the same as PRO-1681's. */
	private const MARKER_FORMAT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

	private const MARKER_FIELD = 'abandoned_cart_purchased_at';

	private const WORKFLOW_ID = '7272';

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var array<int, int> */
	private array $created_products = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the cart pipeline needs WC.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		CartHookHandler::reset_request_guard();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		$this->seed_credentials();
		$this->configure_abandoned_cart();
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		foreach ( $this->created_orders as $order_id ) {
			// NOT wp_delete_post: under HPOS orders live in wc_orders.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $this->created_products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		$this->created_orders   = array();
		$this->created_users    = array();
		$this->created_products = array();

		HookHandler::reset_seen();
		CartHookHandler::reset_request_guard();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_reminded_shopper_who_buys_is_marked_and_never_reminded_again(): void {
		$user_id = $this->make_user( 'purchased' );
		$email   = $this->email_of( $user_id );

		$this->track_cart_for( $user_id );
		$reminders = $this->sweep_and_flush_reminders();
		self::assertNotSame( array(), $reminders, 'The reminder itself must go out first — it is what the marker is scoped to.' );

		$order_id = $this->make_order( $user_id );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );

		$marker = $this->marker_row( $this->flush( EventQueue::FLUSH_HOOK ) );

		self::assertNotNull( $marker, 'The purchase must reach the shopper\'s Smaily contact.' );
		self::assertSame( $email, $marker['email'] );
		self::assertMatchesRegularExpression( self::MARKER_FORMAT, $marker[ self::MARKER_FIELD ] );
		self::assertArrayNotHasKey( 'is_abandoned_cart', $marker, 'The marker is a contact update, not a second reminder.' );
		self::assertArrayNotHasKey( 'product_name_1', $marker, 'The reminder\'s product fields belong to the reminder send (PRO-1680).' );

		// …and the cart earns no further reminder afterwards.
		self::assertSame(
			array(),
			$this->sweep_and_flush_reminders(),
			'A cart the shopper has paid for must never produce another reminder.'
		);
	}

	public function test_a_shopper_the_plugin_never_reminded_gets_no_marker(): void {
		// An ordinary purchase: nothing was ever sent to this shopper, so this
		// feature must write nothing — and must not create a contact.
		$user_id  = $this->make_user( 'unreminded' );
		$order_id = $this->make_order( $user_id );

		do_action( 'woocommerce_checkout_order_processed', $order_id, array(), wc_get_order( $order_id ) );

		self::assertNull( $this->marker_row( $this->flush( EventQueue::FLUSH_HOOK ) ) );
	}

	public function test_a_reminder_still_queued_is_withdrawn_by_the_purchase(): void {
		// The sweeper enqueues up to a minute before the CartFlusher drains it;
		// a shopper who buys inside that window used to be reminded anyway.
		$user_id = $this->make_user( 'raced' );

		$this->track_cart_for( $user_id );
		$this->rewind_tracker_row( 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 'pending', $this->cart_row_state()['status'], 'The reminder must be queued for this case to mean anything.' );

		$order_id = $this->make_order( $user_id );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );

		self::assertSame( array(), $this->flush( CartFlusher::FLUSH_HOOK ), 'The queued reminder must never be POSTed after the purchase.' );

		$row = $this->cart_row_state();
		self::assertSame( 'sent', $row['status'], 'The withdrawn row is terminal — it must not be retried.' );
		self::assertStringContainsString( 'cancelled', (string) $row['last_response'] );
		self::assertNull( $row['sent_payload'], 'Nothing was POSTed, so the row must not claim a request.' );

		self::assertNull(
			$this->marker_row( $this->flush( EventQueue::FLUSH_HOOK ) ),
			'No reminder ever reached this shopper, so there is nothing to mark.'
		);
	}

	// --- helpers -------------------------------------------------------------

	/** Track a live cart for a logged-in shopper through the real hook handler. */
	private function track_cart_for( int $user_id ): void {
		wp_set_current_user( $user_id );

		$product_id = $this->make_product( 'Purchase Marker Product' );
		if ( ! WC()->cart instanceof \WC_Cart || WC()->session === null ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		CartHookHandler::reset_request_guard();
		WC()->cart->add_to_cart( $product_id, 1 );

		( new CartHookHandler( new CartSessionStore() ) )->on_cart_updated();
	}

	/**
	 * Age the tracker row past the cutoff, sweep, and drain the cart queue.
	 *
	 * @return array<int, mixed> The reminder POST bodies that reached Smaily.
	 */
	private function sweep_and_flush_reminders(): array {
		$this->rewind_tracker_row( 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );

		return $this->flush( CartFlusher::FLUSH_HOOK );
	}

	/**
	 * The contact row carrying the purchase marker, if one reached Smaily.
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<string, mixed>|null
	 */
	private function marker_row( array $bodies ): ?array {
		foreach ( $bodies as $body ) {
			// The contact endpoint posts a bare list of subscriber rows.
			if ( ! is_array( $body ) || ! isset( $body[0] ) || ! is_array( $body[0] ) ) {
				continue;
			}
			foreach ( $body as $row ) {
				if ( is_array( $row ) && isset( $row[ self::MARKER_FIELD ] ) ) {
					return $row;
				}
			}
		}

		return null;
	}

	/**
	 * The single abandoned-cart queue row's state.
	 *
	 * @return array<string, mixed>
	 */
	private function cart_row_state(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, sent_payload, last_response FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s ORDER BY id DESC LIMIT 1",
				CartFlusher::EVENT_TYPE
			),
			ARRAY_A
		);

		self::assertIsArray( $row, 'The sweeper must have enqueued a reminder row.' );

		return $row;
	}

	/**
	 * Drain a queue through a mocked Smaily transport, collecting every POST
	 * body (one flush can send several rows).
	 *
	 * @return array<int, mixed>
	 */
	private function flush( string $hook ): array {
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
			do_action( $hook );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $bodies;
	}

	/** Abandoned cart on, mapped to a workflow, with a 10-minute cutoff. */
	private function configure_abandoned_cart(): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'abandonedCartEnabled'       => true,
					'abandonedCartCutoffMinutes' => 10,
					'automationMappings'         => array(
						array(
							'triggerType'       => 'abandoned_cart',
							'language'          => 'default',
							'accountKey'        => 'default',
							'workflowId'        => self::WORKFLOW_ID,
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

	private function rewind_tracker_row( int $seconds ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smly_plus_cart_session SET cart_updated = %s",
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	private function make_user( string $slug ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_cartbuy_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	private function make_product( string $name ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '12.00' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();
		self::assertGreaterThan( 0, $product_id );
		$this->created_products[] = $product_id;
		return $product_id;
	}

	private function make_order( int $customer_id ): int {
		$product_id = $this->make_product( 'Purchase Marker Order Product' );

		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( $this->email_of( $customer_id ) );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return $order_id;
	}
}
