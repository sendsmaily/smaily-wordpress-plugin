<?php
/**
 * Integration: GdprHandler (3.8) — the WP Privacy API exporter (Art 15) + eraser
 * (Art 17) for rec-engine personal data. Scope authority: docs/DATA_MODEL_GDPR.md.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\QueueRowFixture;

/**
 * The headline guarantee (the WC boundary): the rec-engine exporter surfaces
 * rec-specific personal data + the plugin's rec-meta, but NOT WooCommerce order
 * / purchase data — WooCommerce's own exporter owns that. The mock §8 body
 * deliberately includes orders + order_items + decision-logic customer fields so
 * the tests can assert the plugin drops them.
 */
final class RecEngineGdprTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_orders = array();
	/** @var int[] */
	private array $created_users = array();
	/** @var int[] */
	private array $created_requests = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'customer_export'  => $base . '/api/v1/customer/%s/export',
					'customer_delete'  => $base . '/api/v1/customer/%s',
					'customer_opt_out' => $base . '/api/v1/customer/%s/opt-out',
				),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $id ) {
			wp_delete_user( $id );
		}
		foreach ( $this->created_requests as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created_orders   = array();
		$this->created_users    = array();
		$this->created_requests = array();
		parent::tearDown();
	}

	public function test_export_surfaces_rec_data_and_plugin_meta_but_not_woo(): void {
		$email = 'gdpr-export@example.test';
		$this->make_order_with_rec_meta( $email );

		$names = $this->export_field_names( $this->handler()->export( $email ) );

		// Engine rec activity is exported.
		self::assertContains( 'event_type', $names, 'browse_events surfaced.' );
		// Plugin rec-meta off the order is exported.
		self::assertContains( '_smaily_rec_id', $names, 'plugin order rec-meta surfaced.' );
		// WooCommerce purchase data is NOT (Woo owns it).
		self::assertNotContains( 'total_amount', $names, 'engine order total must NOT be re-exported (Woo).' );
		self::assertNotContains( 'line_total', $names, 'engine order_items must NOT be re-exported (Woo).' );
	}

	public function test_export_strips_customer_decision_logic_fields(): void {
		$email = 'gdpr-strip@example.test';
		$names = $this->export_field_names( $this->handler()->export( $email ) );

		// Identity fields kept.
		self::assertContains( 'email', $names );
		self::assertContains( 'country', $names );
		// Decision-logic / profiling fields stripped (DATA_MODEL_GDPR.md).
		self::assertNotContains( 'segment', $names );
		self::assertNotContains( 'rfm_recency', $names );
		self::assertNotContains( 'engagement_score', $names );
		self::assertNotContains( 'inferred_species', $names );
	}

	public function test_export_404_still_returns_plugin_meta(): void {
		$email = 'notfound-gdpr@example.test'; // mock §8 → 404
		$this->make_order_with_rec_meta( $email );

		$names = $this->export_field_names( $this->handler()->export( $email ) );

		// No engine data (404), but the plugin's own meta is still exported.
		self::assertContains( '_smaily_rec_id', $names );
		self::assertNotContains( 'event_type', $names );
	}

	public function test_erase_deletes_engine_and_plugin_meta(): void {
		$email   = 'gdpr-erase@example.test';
		$order_id = $this->make_order_with_rec_meta( $email );
		$user     = $this->make_user( $email );
		update_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, 'anon-xyz' );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'] );
		// Plugin meta gone.
		$order = wc_get_order( $order_id );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( '', (string) $order->get_meta( '_smaily_rec_id' ) );
		self::assertSame( '', (string) get_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) );
	}

	public function test_newsletter_consent_marker_is_exported_and_erased(): void {
		// PRO-3426: the block-checkout consent evidence (PRO-3406) through the
		// real order storage (HPOS in this env; the eraser goes via the order API).
		$email     = 'shopper@example.test';
		$marked_id = $this->make_order_with_rec_meta( $email );
		$plain_id  = $this->make_order_with_rec_meta( $email );
		$marked    = wc_get_order( $marked_id );
		self::assertInstanceOf( \WC_Order::class, $marked );
		$marked->update_meta_data( HookHandler::ORDER_META_NEWSLETTER_OPTIN, '1' );
		$marked->save();

		$items = array_values(
			array_filter(
				$this->handler()->export( $email )['data'],
				static fn ( array $item ): bool => $item['group_label'] === 'Newsletter consent (order meta)'
			)
		);
		self::assertCount( 1, $items, 'One item, for the marked order only.' );
		self::assertSame( 'Order', $items[0]['data'][0]['name'] );
		self::assertSame( $marked->get_order_number(), $items[0]['data'][0]['value'] );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'] );
		$marked = wc_get_order( $marked_id );
		$plain  = wc_get_order( $plain_id );
		self::assertInstanceOf( \WC_Order::class, $marked );
		self::assertInstanceOf( \WC_Order::class, $plain );
		self::assertSame( '', (string) $marked->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) );
		self::assertSame( '', (string) $plain->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) );
	}

	public function test_erasure_drops_the_customers_waiting_updates_so_none_sends_them_again(): void {
		// PRO-3906: a customer or order row waiting in the queue holds no copy
		// yet (the flusher builds it at send time), so the PRO-2384 match on
		// the stored copy cannot see it. Left behind, it would send the erased
		// customer to the engine again right after the engine deleted them.
		$target    = 'erase-waiting@example.com';
		$bystander = 'keep-waiting@example.com';
		$user      = $this->make_user( $target );
		$other     = $this->make_user( $bystander );
		$order_id  = $this->make_sale_order( $target );
		$other_id  = $this->make_sale_order( $bystander );

		// Start from an empty queue: the user and order hooks above queued rows of their own.
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . QueueRowFixture::table( IngestQueue::TABLE_SUFFIX ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		$queue = new IngestQueue();
		// The customer's waiting rows: due now, parked for a retry, and failed
		// (the Event Log's Retry would send it again).
		$due    = $this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $user->ID );
		$order  = $this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $order_id );
		$parked = $this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $order_id );
		$queue->record_attempt( $parked, 'http_503 unavailable', 3600 );
		$failed = $this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $user->ID );
		$queue->mark_failed( $failed, 'http_503 unavailable' );
		// Rows that stay: a sent row (never sent again), a catalog row whose
		// product id happens to equal the user id, and the other customer's.
		$sent = $this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $user->ID );
		$queue->mark_sent( $sent );
		$catalog    = $this->enqueue( 'catalog.upsert', $user->ID );
		$other_rows = array(
			$this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $other->ID ),
			$this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $other_id ),
		);

		$result = $this->handler()->erase( $target );

		self::assertTrue( $result['items_removed'] );

		// The merchant presses Retry in the Event Log, then the flushers run.
		$queue->reset_failed();
		$this->customer_flusher()->flush();
		$this->order_flusher()->flush();

		$state = self::$engine->state();
		self::assertSame( array( $bystander ), array_column( $state['last_customers_payload'] ?? array(), 'email' ), 'Only the other customer reaches the engine.' );
		self::assertSame( array( $bystander ), array_column( $state['last_orders_payload'] ?? array(), 'customer_email' ), 'Only the other customer\'s order reaches the engine.' );

		foreach ( array( $due, $order, $parked, $failed ) as $id ) {
			self::assertFalse( QueueRowFixture::exists( IngestQueue::TABLE_SUFFIX, $id ), "Waiting row {$id} is dropped." );
		}
		foreach ( array_merge( array( $sent, $catalog ), $other_rows ) as $id ) {
			self::assertTrue( QueueRowFixture::exists( IngestQueue::TABLE_SUFFIX, $id ), "Row {$id} is not the customer's waiting update." );
		}
	}

	public function test_erasure_finds_the_customers_orders_in_every_status_and_any_letter_case(): void {
		// PRO-3908: the lookup through wc_get_orders() saw only the registered
		// statuses, so an order with a store's custom shipping status (here one
		// no plugin registers any more) or in the trash kept its data and its
		// waiting update. The address matches in any letter case.
		$email   = 'erase-any-status@example.com';
		$custom  = $this->make_order_with_rec_meta( $email );
		$trashed = $this->make_order_with_rec_meta( $email );
		$cased   = $this->make_order_with_rec_meta( 'Erase-Any-Status@Example.COM' );
		$other   = $this->make_order_with_rec_meta( 'keep-any-status@example.com' );
		$this->set_stored_status( $custom, 'wc-label-printed' );
		$order = wc_get_order( $trashed );
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->delete( false );

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . QueueRowFixture::table( IngestQueue::TABLE_SUFFIX ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$waiting = array(
			$this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $custom ),
			$this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $trashed ),
			$this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $cased ),
		);
		$kept    = $this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $other );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'] );
		foreach ( array( $custom, $trashed, $cased ) as $id ) {
			$order = wc_get_order( $id );
			self::assertInstanceOf( \WC_Order::class, $order );
			self::assertSame( '', (string) $order->get_meta( '_smaily_rec_id' ), "Order {$id} lost its rec meta." );
			self::assertSame( '', (string) $order->get_meta( '_smaily_visitor_token' ), "Order {$id} lost its visitor token." );
		}
		foreach ( $waiting as $id ) {
			self::assertFalse( QueueRowFixture::exists( IngestQueue::TABLE_SUFFIX, $id ), "Waiting row {$id} is dropped." );
		}
		self::assertTrue( QueueRowFixture::exists( IngestQueue::TABLE_SUFFIX, $kept ), 'The other customer\'s update stays.' );
		$order = wc_get_order( $other );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( 'rec-abc123', (string) $order->get_meta( '_smaily_rec_id' ), 'The other customer\'s order is untouched.' );

		// The erasure leaves each order's status as it was.
		self::assertSame( 'wc-label-printed', $this->stored_status( $custom ) );
		self::assertSame( 'trash', $this->stored_status( $trashed ) );

		// Remove the orders here and prove it from the table: a leaked order
		// with an unregistered status breaks other suites' counts (LESSONS §2.16).
		foreach ( array( $custom, $trashed, $cased, $other ) as $id ) {
			$order = wc_get_order( $id );
			self::assertInstanceOf( \WC_Order::class, $order );
			$order->delete( true );
			self::assertNull( $this->stored_status( $id ), "Order {$id} is gone from the order table." );
		}
	}

	public function test_no_customer_update_is_left_waiting_after_wordpress_runs_every_eraser(): void {
		// PRO-3986: our eraser runs before WooCommerce's. WooCommerce's
		// customer eraser then blanks the profile and saves it; the save fires
		// profile_update, which queued a new customer update that sent the
		// erased customer to the engine again. Driven the way WordPress runs an
		// erasure request: every registered eraser, page by page, each page its
		// own admin-ajax request, then the request is marked completed.
		$email = 'erase-every-eraser@example.com';
		$user  = $this->make_user( $email );
		update_user_meta( $user->ID, 'billing_first_name', 'Jane' );
		update_user_meta( $user->ID, 'billing_email', $email );

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . QueueRowFixture::table( IngestQueue::TABLE_SUFFIX ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $user->ID );

		$this->run_wordpress_erasure( $email );

		self::assertSame( 0, $this->waiting_rows( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $user->ID ), 'No customer update is left waiting after every eraser has run.' );
		$this->customer_flusher()->flush();
		self::assertSame( array(), array_column( self::$engine->state()['last_customers_payload'] ?? array(), 'email' ), 'The erased customer does not reach the engine again.' );
	}

	public function test_an_address_that_differs_only_by_an_accent_belongs_to_someone_else(): void {
		// PRO-3986: the database compares addresses accent-blind, so a lookup
		// for jane@ also found jäne@'s orders and WP user. WordPress and
		// WooCommerce refuse such an address on their forms, so it is written
		// straight into the tables, as an import would leave it.
		$email     = 'jane@example.com';
		$accented  = 'jäne@example.com';
		$own       = $this->make_order_with_rec_meta( $email );
		$other     = $this->make_order_with_rec_meta( 'jane-other@example.com' );
		$neighbour = $this->make_user( 'jane-other@example.com' );
		$this->set_stored_billing_email( $other, $accented );
		$this->set_stored_user_email( $neighbour->ID, $accented );
		update_user_meta( $neighbour->ID, IdentityHookHandler::MERGED_META_KEY, 'anon-neighbour' );

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . QueueRowFixture::table( IngestQueue::TABLE_SUFFIX ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$own_row   = $this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $own );
		$kept_rows = array(
			$this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, $other ),
			$this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $neighbour->ID ),
		);

		$export = $this->handler()->export( $email );
		$found  = array();
		foreach ( $export['data'] as $item ) {
			if ( $item['group_label'] === 'Recommendation attribution (order meta)' ) {
				$found[] = $item['item_id'];
			}
		}
		self::assertSame( array( 'smaily-connect-rec-engine-order-' . $own ), $found, 'The export lists the requester\'s own order only.' );
		self::assertNotContains( IdentityHookHandler::MERGED_META_KEY, $this->export_field_names( $export ), 'The other person\'s marker is not exported.' );

		$this->handler()->erase( $email );

		self::assertFalse( QueueRowFixture::exists( IngestQueue::TABLE_SUFFIX, $own_row ), 'The requester\'s waiting order update is dropped.' );
		foreach ( $kept_rows as $id ) {
			self::assertTrue( QueueRowFixture::exists( IngestQueue::TABLE_SUFFIX, $id ), "Row {$id} belongs to the other person." );
		}
		$order = wc_get_order( $own );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( '', (string) $order->get_meta( '_smaily_rec_id' ), 'The requester\'s order lost its rec meta.' );
		$order = wc_get_order( $other );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( 'rec-abc123', (string) $order->get_meta( '_smaily_rec_id' ), 'The other person\'s order is untouched.' );
		self::assertSame( 'anon-neighbour', (string) get_user_meta( $neighbour->ID, IdentityHookHandler::MERGED_META_KEY, true ), 'The other person\'s marker stays.' );
	}

	public function test_erase_is_idempotent_when_already_deleted(): void {
		$email = 'gdpr-idem@example.test';

		$first = $this->handler()->erase( $email );
		self::assertTrue( $first['items_removed'], 'First erase deletes the engine record.' );

		// Second erase → engine 404 (already deleted) → still a success, no throw.
		$second = $this->handler()->erase( $email );
		self::assertTrue( $second['done'] );
	}

	public function test_export_surfaces_cart_session_row(): void {
		$email = 'gdpr-cart-export@example.test';
		$store = new CartSessionStore();
		$store->upsert(
			'tok-' . wp_generate_uuid4(),
			0,
			$email,
			'Jane',
			'Doe',
			array( array( 'product_id' => 123, 'variation_id' => 0, 'quantity' => 2 ) )
		);

		$names = $this->export_field_names( $this->handler()->export( $email ) );

		self::assertContains( 'cart_content', $names, 'cart-session row surfaced (PRO-1343).' );
		self::assertContains( 'cart_token', $names );
	}

	public function test_erase_deletes_cart_session_row(): void {
		$email = 'gdpr-cart-erase@example.test';
		$store = new CartSessionStore();
		$store->upsert( 'tok-' . wp_generate_uuid4(), 0, $email, 'Jane', 'Doe', array() );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'] );
		self::assertSame( array(), $store->rows_for_privacy_request( $email ) );
	}

	public function test_erase_matches_cart_session_by_user_id_when_email_column_drifted(): void {
		$email = 'gdpr-cart-drift@example.test';
		$user  = $this->make_user( $email );
		$store = new CartSessionStore();
		// Defensive case the acceptance criteria names: a row keyed to the WP
		// user but whose email column wasn't populated. Current write paths
		// never produce this (email is always set alongside user_id), but the
		// erase must still find the row via the user's account email.
		$store->upsert( 'tok-' . wp_generate_uuid4(), $user->ID, '', '', '', array() );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'], 'Row matched via user_id despite an empty email column.' );
		self::assertSame( array(), $store->rows_for_privacy_request( '', $user->ID ) );
	}

	public function test_a_cart_session_of_an_accented_address_belongs_to_someone_else(): void {
		// PRO-3993: the cart-session match compared `email = %s` under the
		// column's accent-blind collation, so a request for jane@ also found
		// jäne@'s cart. Letter case still does not matter.
		$store     = new CartSessionStore();
		$own_token = 'tok-' . wp_generate_uuid4();
		$neighbour = 'tok-' . wp_generate_uuid4();
		$store->upsert( $own_token, 0, 'Jane@Example.com', 'Jane', 'Doe', array() );
		$store->upsert( $neighbour, 0, 'jäne@example.com', 'Jäne', 'Doe', array() );

		$tokens = array();
		foreach ( $this->handler()->export( 'jane@example.com' )['data'] as $item ) {
			if ( $item['group_label'] === 'Abandoned-cart session' ) {
				foreach ( $item['data'] as $pair ) {
					if ( $pair['name'] === 'cart_token' ) {
						$tokens[] = $pair['value'];
					}
				}
			}
		}
		self::assertSame( array( $own_token ), $tokens, 'The export lists the requester\'s own cart only, whatever its letter case.' );

		$this->handler()->erase( 'jane@example.com' );

		self::assertSame( array(), $store->rows_for_privacy_request( 'Jane@Example.com' ), 'The requester\'s cart is deleted.' );
		self::assertSame( array( $neighbour ), array_column( $store->rows_for_privacy_request( 'jäne@example.com' ), 'cart_token' ), 'The other person\'s cart stays.' );
	}

	// --- helpers --------------------------------------------------------

	/**
	 * Run an erasure request the way WordPress does: wp_ajax_wp_privacy_erase_personal_data()
	 * calls each registered eraser page by page, one admin-ajax request per
	 * page, and passes every answer to wp_privacy_process_personal_data_erasure_page(),
	 * which marks the request completed and fires wp_privacy_personal_data_erased
	 * after the last eraser's last page.
	 */
	private function run_wordpress_erasure( string $email ): void {
		require_once ABSPATH . 'wp-admin/includes/privacy-tools.php';

		$request_id = wp_create_user_request( $email, 'remove_personal_data', array(), 'confirmed' );
		self::assertIsInt( $request_id );
		$this->created_requests[] = $request_id;

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		self::assertArrayHasKey( 'woocommerce-customer-data', $erasers, 'WooCommerce\'s own customer eraser takes part.' );

		$index = 0;
		foreach ( $erasers as $eraser ) {
			++$index;
			$page = 1;
			do {
				// A new admin-ajax request starts with an empty per-request dedupe.
				CustomerHookHandler::reset_seen();
				$response = call_user_func( $eraser['callback'], $email, $page );
				$response = wp_privacy_process_personal_data_erasure_page( $response, $index, $email, $page, $request_id );
				++$page;
			} while ( empty( $response['done'] ) );
		}

		$request = wp_get_user_request( $request_id );
		self::assertInstanceOf( \WP_User_Request::class, $request );
		self::assertSame( 'request-completed', $request->status, 'WordPress marked the request completed.' );
	}

	/** Rows of one event type for one entity that can still be sent. */
	private function waiting_rows( string $event_type, int $entity_id ): int {
		global $wpdb;
		$table = QueueRowFixture::table( IngestQueue::TABLE_SUFFIX );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND entity_id = %s AND status IN ( 'pending', 'failed' )", $event_type, (string) $entity_id ) );
	}

	/** Write a billing address straight into the active order table. */
	private function set_stored_billing_email( int $order_id, string $email ): void {
		global $wpdb;
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'billing_email' => $email ), array( 'id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $email ), array( 'post_id' => $order_id, 'meta_key' => '_billing_email' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}
		wp_cache_flush();
	}

	/** Write a user's address straight into the users table. */
	private function set_stored_user_email( int $user_id, string $email ): void {
		global $wpdb;
		clean_user_cache( $user_id );
		$wpdb->update( $wpdb->users, array( 'user_email' => $email ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();
	}

	private function handler(): GdprHandler {
		$settings = new RecEngineSettings();
		return new GdprHandler(
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			},
			new CartSessionStore(),
			new EventQueue(),
			new IngestQueue()
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function export_field_names( array $result ): array {
		$names = array();
		foreach ( $result['data'] as $item ) {
			foreach ( $item['data'] as $pair ) {
				$names[] = (string) $pair['name'];
			}
		}
		return $names;
	}

	private function make_order_with_rec_meta( string $email ): int {
		$product = new \WC_Product_Simple();
		$product->set_sku( 'GDPR-' . wp_generate_uuid4() );
		$product->set_regular_price( '67.50' );
		$product->set_price( '67.50' );
		$product->save();

		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( (int) $product->get_id() ), 1 );
		$order->calculate_totals();
		$order->update_meta_data( '_smaily_rec_id', 'rec-abc123' );
		$order->update_meta_data( '_smaily_visitor_token', 'vt_xyz' );
		$id                     = (int) $order->save();
		$this->created_orders[] = $id;
		return $id;
	}

	/** An order the order flusher sends: a completed sale billed to $email. */
	private function make_sale_order( string $email ): int {
		$id    = $this->make_order_with_rec_meta( $email );
		$order = wc_get_order( $id );
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->set_status( 'completed' );
		$order->save();
		return $id;
	}

	/**
	 * Write a status straight into the active order table, the way a store's
	 * own shipping plugin leaves it — WC_Order::set_status() turns a status it
	 * does not know into `pending`.
	 */
	private function set_stored_status( int $order_id, string $status ): void {
		global $wpdb;
		$spec = $this->order_table();
		$wpdb->update( $spec['table'], array( $spec['status_col'] => $status ), array( $spec['id_col'] => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush(); // The cached order object still holds the old status.
	}

	/** The status in the active order table, or null when the row is gone. */
	private function stored_status( int $order_id ): ?string {
		global $wpdb;
		$spec = $this->order_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT {$spec['status_col']} FROM {$spec['table']} WHERE {$spec['id_col']} = %d", $order_id ) );
		return $status === null ? null : (string) $status;
	}

	/**
	 * @return array{table: string, id_col: string, type_col: string, status_col: string}
	 */
	private function order_table(): array {
		global $wpdb;
		return OrderBackfillJob::table_spec( OrderUtil::custom_orders_table_usage_is_enabled(), $wpdb->prefix );
	}

	private function enqueue( string $event_type, int $entity_id ): int {
		$id = ( new IngestQueue() )->enqueue( $event_type, (string) $entity_id, array() );
		self::assertIsInt( $id );
		return $id;
	}

	private function customer_flusher(): CustomerFlusher {
		$settings = new RecEngineSettings();
		return new CustomerFlusher(
			new IngestQueue(),
			new CustomerPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
	}

	private function order_flusher(): OrderFlusher {
		$settings = new RecEngineSettings();
		return new OrderFlusher(
			new IngestQueue(),
			new OrderPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
	}

	private function make_user( string $email ): \WP_User {
		$id = wp_insert_user(
			array(
				'user_login' => 'gdpr_' . md5( $email ),
				'user_pass'  => 'x' . wp_generate_password( 12, false ),
				'user_email' => $email,
				'role'       => 'customer',
			)
		);
		self::assertIsInt( $id );
		$this->created_users[] = (int) $id;
		$user = get_userdata( (int) $id );
		self::assertInstanceOf( \WP_User::class, $user );
		return $user;
	}
}
