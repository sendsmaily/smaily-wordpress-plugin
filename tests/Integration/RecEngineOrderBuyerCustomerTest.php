<?php
/**
 * Integration: a registered buyer's order queues the buyer's `customer`
 * record once per order (contract v1.20.0 §4, CON-246), through the real
 * Bootstrap-registered OrderHookHandler on the active order storage.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * The consent mode (the default) with a buyer who never subscribed: before
 * the order the account is out of scope, so only the order puts it into the
 * engine. The status hook fires on each change; the customer row must not.
 */
final class RecEngineOrderBuyerCustomerTest extends TestCase {

	/** @var int[] */
	private array $created_orders = array();

	/** @var int[] */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		EnvSeed::connect();
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		CustomerHookHandler::reset_seen();
		OrderHookHandler::reset_seen();
		$this->truncate_queue();
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true ); // Not wp_delete_post: a no-op under HPOS.
			}
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_orders = array();
		$this->created_users  = array();
		delete_option( ContactSyncMode::OPTION_MODE );
		$this->truncate_queue();
		OrderHookHandler::reset_seen();
		parent::tearDown();
	}

	public function test_a_registered_buyers_order_queues_the_customer_once_per_order(): void {
		$buyer = $this->make_user( 'buyer-once@example.test' );
		self::assertSame( 0, $this->rows( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $buyer ), 'Out of scope before the order.' );

		$order_id = $this->make_order( $buyer, 'pending' );
		$this->change_status( $order_id, 'processing' );

		self::assertSame( 1, $this->rows( OrderFlusher::EVENT_ORDER_UPSERT, $order_id ) );
		self::assertSame( 1, $this->rows( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $buyer ), 'The order queued its buyer.' );
		self::assertSame( '1', (string) wc_get_order( $order_id )->get_meta( OrderHookHandler::BUYER_QUEUED_META ) );

		// The customer row went out; a later status change of the same order
		// in a later request must not queue the buyer again.
		$this->mark_all_sent();
		$this->change_status( $order_id, 'completed' );

		self::assertSame( 2, $this->rows( OrderFlusher::EVENT_ORDER_UPSERT, $order_id ), 'The order itself is sent again.' );
		self::assertSame( 1, $this->rows( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $buyer ), 'Once per order, not per status change.' );
	}

	public function test_a_waiting_customer_row_is_not_duplicated(): void {
		$buyer = $this->make_user( 'buyer-waiting@example.test' );
		$first = $this->make_order( $buyer, 'pending' );
		$this->change_status( $first, 'processing' );
		$second = $this->make_order( $buyer, 'pending' );
		$this->change_status( $second, 'processing' );

		self::assertSame( 1, $this->rows( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $buyer ), 'The pending row covers the second order.' );
	}

	public function test_a_guest_order_queues_no_customer_row(): void {
		$order_id = $this->make_order( 0, 'pending' );
		$this->change_status( $order_id, 'processing' );

		self::assertSame( 1, $this->rows( OrderFlusher::EVENT_ORDER_UPSERT, $order_id ) );
		self::assertSame( 0, $this->all_rows( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) );
	}

	// --- helpers --------------------------------------------------------

	private function make_user( string $email ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'ob_' . md5( $email ),
				'user_pass'  => 'x' . wp_generate_password( 12, false ),
				'user_email' => $email,
				'role'       => 'customer',
			)
		);
		self::assertIsInt( $id );
		$this->created_users[] = (int) $id;
		return (int) $id;
	}

	private function make_order( int $customer_id, string $status ): int {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->set_billing_email( 'ob-' . wp_generate_uuid4() . '@example.test' );
		$order->set_status( $status );
		$id                     = (int) $order->save();
		$this->created_orders[] = $id;
		return $id;
	}

	/** A status change in a new request: the per-request dedupe starts empty. */
	private function change_status( int $order_id, string $status ): void {
		OrderHookHandler::reset_seen();
		$order = wc_get_order( $order_id );
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->update_status( $status );
	}

	private function rows( string $event_type, int $entity_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type = %s AND entity_id = %s", $event_type, (string) $entity_id ) );
	}

	private function all_rows( string $event_type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type = %s", $event_type ) );
	}

	private function mark_all_sent(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$wpdb->prefix}smly_rec_event_queue SET status = 'sent'" );
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}
}
