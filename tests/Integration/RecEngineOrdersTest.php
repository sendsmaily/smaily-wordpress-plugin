<?php
/**
 * Integration: OrderFlusher → Client::ingest_orders against the mock engine —
 * the D6 per-item contract end-to-end (partial success splits the batch),
 * email-keyed orders wire format, and the terminal-4xx path.
 *
 * The OrderHookHandler lands in 3.3-orders.3, so rows are enqueued directly
 * here; the flusher loads each WC_Order fresh and ships it.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

final class RecEngineOrdersTest extends TestCase {

	/** A genuine engine-issued rec id shape (the engine validates it as a uuid). */
	private const REC_UUID = '11111111-2222-4333-8444-555555555555';

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> Order ids created by a test, torn down after. */
	private array $created_orders = array();

	/** @var array<int, int> Product ids created by a test, torn down after. */
	private array $created_products = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — orders ingest needs WC_Order.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		OrderHookHandler::reset_seen();
		$this->connect();
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			// NOT wp_delete_post: under HPOS orders live in wc_orders, so a
			// post-delete is a silent no-op and the order leaks across runs.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_orders   = array();
		$this->created_products = array();
		parent::tearDown();
	}

	public function test_d6_partial_success_marks_errored_row_failed_and_rest_sent(): void {
		$queue   = new IngestQueue();
		$product = $this->make_product( 'ORD-SKU-1', '10.00' );

		// Two valid orders + one whose customer_email triggers a per-item error
		// in the mock (`d6err-` prefix). Creating a completed order fires
		// woocommerce_order_status_changed; the registered OrderHookHandler
		// enqueues the order.upsert row — the real wiring, no manual enqueue.
		$this->make_order( 'valid-a@example.test', 'completed', $product );
		$this->make_order( 'valid-b@example.test', 'completed', $product );
		$this->make_order( 'd6err-bad@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'], 'The two valid orders are processed → sent.' );
		self::assertSame( 1, $stats['failed'], 'The errors[] order is marked failed, not the whole batch.' );
		self::assertSame(
			array(),
			$queue->pending( 10, array( OrderFlusher::EVENT_ORDER_UPSERT ) ),
			'Every order row reached a terminal state.'
		);

		$received = self::$engine->state()['last_orders_received'] ?? null;
		self::assertIsArray( $received );
		self::assertCount( 3, $received, 'All three orders were sent in one batch.' );
	}

	public function test_all_valid_orders_are_sent(): void {
		$product = $this->make_product( 'ORD-SKU-2', '5.00' );
		$this->make_order( 'all-good-1@example.test', 'processing', $product );
		$this->make_order( 'all-good-2@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
	}

	public function test_flush_records_the_send_exchange_on_the_row(): void {
		// F3-44 end-to-end: after a real flush the row carries the exact JSON
		// POSTed + the engine reply (migration 007 columns, store_exchange write).
		global $wpdb;
		$product = $this->make_product( 'ORD-SKU-EX', '7.00' );
		$oid     = $this->make_order( 'exchange@example.test', 'completed', $product );

		$this->flusher()->flush();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sent_payload, last_response, status FROM {$wpdb->prefix}smly_rec_event_queue WHERE entity_id = %s AND event_type = %s",
				(string) $oid,
				OrderFlusher::EVENT_ORDER_UPSERT
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::assertIsArray( $row );
		self::assertSame( 'sent', $row['status'] );
		self::assertStringContainsString( '"external_order_id":"' . $oid . '"', (string) $row['sent_payload'], 'The exact wire object is stored.' );
		self::assertStringContainsString( '"outcome":"accepted"', (string) $row['last_response'] );
	}

	public function test_skuless_product_order_ingests_with_woo_key(): void {
		// PRO-1224 (pilot find): the pilot store has no SKUs at all. The order
		// must still ingest, keyed woo-{product id} by SkuResolver (the platform
		// id, never the merchant SKU) — before this, every line dropped and the
		// empty items[] D6-failed the order on every retry.
		$product = $this->make_skuless_product( '7.00' );
		$this->make_order( 'skuless@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['failed'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertSame( 'woo-' . $product->get_id(), $payloads[0]['items'][0]['sku'] );
	}

	public function test_deleted_product_order_is_kept_and_sent_not_dropped(): void {
		// F3-43 (engine brief #58922): the pilot's failed orders referenced
		// already-deleted products. Empirical (this env, WC 10.7): permanent
		// product deletion ZEROES the order items' _product_id reference. The
		// order must NOT be dropped — that empties items[] and the whole order is
		// silently lost (marked "sent" with no POST). The line keys on the
		// order-item id (woo-oi-{id}) so the order ingests; the engine accepts it.
		$product = $this->make_skuless_product( '9.00' );
		$pid     = $product->get_id();
		$this->make_order( 'deleted-product@example.test', 'completed', $product );

		wp_delete_post( $pid, true );
		self::assertFalse( wc_get_product( $pid ), 'Precondition: the product row is gone.' );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'A deleted-product order is kept + sent, never dropped. stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['skipped'] );
		self::assertSame( 0, $stats['failed'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertNotEmpty( $payloads[0]['items'], 'items[] must be non-empty — the line is kept from the snapshot.' );
		self::assertStringStartsWith( 'woo-oi-', (string) $payloads[0]['items'][0]['sku'], 'A zeroed-id deleted line keys on the order-item id.' );
	}

	public function test_taxed_discounted_order_wires_gross_amounts_and_the_sender_invariant_holds(): void {
		// PRO-1241 / contract v1.4.0 §5 "Amount semantics": ALL money fields
		// are GROSS (tax-inclusive). Real WC tax engine (24% VAT, prices
		// entered ex-tax), two product lines, a fixed-cart coupon and taxed
		// shipping — the wire must carry line_total = get_total() +
		// get_total_tax() (bare get_total() is the pre-1.4.0 net bug, the
		// pilot's ~24% per-SKU revenue understatement) and satisfy
		// Σ items[].line_total + shipping ≈ total_amount.
		$this->enable_24_percent_vat();

		$product_a = $this->make_product( 'GROSS-A', '10.00' );
		$product_b = $this->make_product( 'GROSS-B', '20.00' );

		$coupon = new \WC_Coupon();
		$coupon->set_code( 'gross-5-off' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '5.00' );
		$coupon_id = (int) $coupon->save();

		try {
			$order = wc_create_order();
			$order->set_billing_email( 'gross-invariant@example.test' );
			$order->add_product( $product_a, 1 );
			$order->add_product( $product_b, 2 );

			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_title( 'Flat rate' );
			$shipping->set_total( '5.00' );
			$order->add_item( $shipping );

			$order->apply_coupon( 'gross-5-off' );
			$order->calculate_totals( true );
			$order->set_status( 'completed' );
			$order_id               = (int) $order->save();
			$this->created_orders[] = $order_id;

			$order = wc_get_order( $order_id );
			self::assertGreaterThan( 0.0, (float) $order->get_total_tax(), 'Precondition: the WC tax engine really taxed this order.' );

			$stats = $this->flusher()->flush();
			self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
			self::assertSame( 0, $stats['failed'] );

			$payloads = self::$engine->state()['last_orders_payload'] ?? null;
			self::assertIsArray( $payloads );
			$payload = $payloads[0];

			// Each wire line is the GROSS charged amount from the real order.
			$wc_items = array_values( $order->get_items() );
			self::assertCount( count( $wc_items ), $payload['items'] );
			foreach ( $wc_items as $i => $wc_item ) {
				$expected_gross = (float) $wc_item->get_total() + (float) $wc_item->get_total_tax();
				self::assertGreaterThan(
					(float) $wc_item->get_total(),
					(float) $payload['items'][ $i ]['line_total'],
					'line_total must exceed the NET get_total() — i.e. it includes tax.'
				);
				self::assertEqualsWithDelta( $expected_gross, (float) $payload['items'][ $i ]['line_total'], 0.0001 );
				$qty = (int) $wc_item->get_quantity();
				self::assertEqualsWithDelta( $expected_gross / $qty, (float) $payload['items'][ $i ]['unit_price'], 0.0001, 'unit_price = gross ÷ qty.' );
				self::assertGreaterThan( 0.0, (float) $payload['items'][ $i ]['discount_amount'], 'The cart coupon discounts every line — gross delta present.' );
			}

			// §5 sender invariant against the REAL order totals.
			$line_sum       = array_sum( array_column( $payload['items'], 'line_total' ) );
			$gross_shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			self::assertEqualsWithDelta(
				(float) $payload['total_amount'],
				$line_sum + $gross_shipping,
				0.01,
				'Σ items[].line_total + shipping ≈ total_amount (± rounding).'
			);

			// The order-level discount is gross too (discount + its tax share).
			self::assertEqualsWithDelta(
				(float) $order->get_total_discount( false ),
				(float) $payload['discount_amount'],
				0.0001
			);
			self::assertGreaterThan(
				(float) $order->get_total_discount( true ),
				(float) $payload['discount_amount'],
				'Gross discount must exceed the ex-tax discount on a taxed order.'
			);
		} finally {
			wp_delete_post( $coupon_id, true );
			$this->disable_taxes();
		}
	}

	public function test_zero_tax_order_wires_amounts_equal_to_net_and_invariant_holds(): void {
		// Taxes off (a tax-exempt store): gross == net; the §5 invariant must
		// hold on the same code path with a zero tax share.
		$product = $this->make_product( 'GROSS-ZERO', '12.50' );
		$this->make_order( 'gross-zero-tax@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();
		self::assertSame( 1, $stats['sent'] );

		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		$payload = $payloads[0];

		self::assertEqualsWithDelta( 12.5, (float) $payload['items'][0]['line_total'], 0.0001 );
		self::assertEqualsWithDelta( 12.5, (float) $payload['items'][0]['unit_price'], 0.0001 );
		$line_sum = array_sum( array_column( $payload['items'], 'line_total' ) );
		self::assertEqualsWithDelta( (float) $payload['total_amount'], $line_sum, 0.01, 'No shipping, no tax: Σ line_total == total_amount.' );
	}

	public function test_order_with_no_product_lines_is_terminal_skipped(): void {
		// Only non-product lines (a fee) → items[] builds empty → the engine
		// would D6-reject on items min 1 forever. The flusher terminal-skips
		// instead of sending (F3-36).
		$order = wc_create_order();
		$order->set_billing_email( 'fee-only@example.test' );
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Handling' );
		$fee->set_total( '5.00' );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order_id               = (int) $order->save();
		$this->created_orders[] = $order_id;

		$before = self::$engine->state()['last_orders_received'] ?? null;
		$stats  = $this->flusher()->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
		self::assertSame(
			$before,
			self::$engine->state()['last_orders_received'] ?? null,
			'No engine call was made for the empty-items order.'
		);
	}

	public function test_uuid_rec_landing_rides_the_order_to_the_engine(): void {
		// PRO-1710 control case: a GENUINE engine-issued rec id behaves exactly
		// as before — captured on the landing, stamped at checkout, on the wire.
		$product  = $this->make_product( 'ORD-REC-OK', '15.00' );
		$order_id = $this->landing_then_order( self::REC_UUID, 'rec-uuid@example.test', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertSame( self::REC_UUID, $payloads[0]['smaily_rec_id'] ?? null, 'Real attribution still reaches the engine.' );
		self::assertSame( self::REC_UUID, (string) wc_get_order( $order_id )->get_meta( '_smaily_rec_id' ) );
	}

	public function test_junk_rec_landing_never_reaches_the_order_and_it_ingests_unattributed(): void {
		// PRO-1710, the whole chain: a visitor lands with a junk ?smaily_rec=,
		// buys, and the order must ingest — un-attributed, not D6-rejected.
		// Before the fix the value was cookied → stamped → the order failed
		// permanently against the (now uuid-validating) engine.
		$product  = $this->make_product( 'ORD-REC-JUNK', '15.00' );
		$order_id = $this->landing_then_order( 'not-a-uuid', 'rec-junk@example.test', $product );

		self::assertSame( '', (string) wc_get_order( $order_id )->get_meta( '_smaily_rec_id' ), 'Nothing was captured, so nothing was stamped.' );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'The order ingests normally. stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['failed'] );
		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertArrayNotHasKey( 'smaily_rec_id', $payloads[0] );
	}

	public function test_a_junk_rec_id_already_on_order_meta_is_dropped_at_send(): void {
		// The send-side half of PRO-1710: a store that cookied junk BEFORE this
		// release still carries it on the order meta (the capture fix cannot
		// reach a cookie already in a browser). The builder drops the field so
		// the order ships un-attributed instead of failing forever.
		$product  = $this->make_product( 'ORD-REC-STALE', '15.00' );
		$order_id = $this->make_order( 'rec-stale@example.test', 'completed', $product );
		$order    = wc_get_order( $order_id );
		$order->update_meta_data( '_smaily_rec_id', 'stale-junk-cookie' );
		$order->save();

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
		self::assertSame( 0, $stats['failed'] );
		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertArrayNotHasKey( 'smaily_rec_id', $payloads[0] );
		self::assertSame( 'stale-junk-cookie', (string) wc_get_order( $order_id )->get_meta( '_smaily_rec_id' ), 'The stored meta is left alone — only the wire object drops it.' );
	}

	public function test_an_oversized_attribution_cookie_is_not_stamped_and_the_order_ingests_without_it(): void {
		// PRO-1896: the pre-fix browser writer cookied any `?smaily_ctx=` value,
		// and that cookie outlives the fixed bundle by its 30-day TTL. The
		// checkout stamping caps it, so the planted value never reaches the §5
		// wire — while the order itself ingests normally.
		$product  = $this->make_product( 'ORD-CTX-OVERSIZE', '15.00' );
		$order_id = $this->make_order( 'ctx-oversize@example.test', 'completed', $product );

		$_COOKIE = array( 'smaily_rec_ctx' => str_repeat( 'x', 200 ) );
		( new HookHandler( new EventQueue() ) )->on_checkout_order_processed( $order_id, array() );
		$_COOKIE = array();

		self::assertSame( '', (string) wc_get_order( $order_id )->get_meta( '_smaily_rec_ctx' ) );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'], 'stats: ' . wp_json_encode( $stats ) );
		$payloads = self::$engine->state()['last_orders_payload'] ?? null;
		self::assertIsArray( $payloads );
		self::assertArrayNotHasKey( 'smaily_rec_ctx', $payloads[0] );
	}

	public function test_mock_rejects_a_non_uuid_smaily_rec_id_like_the_live_engine(): void {
		// PRO-1710 mock fidelity: the live route validates smaily_rec_id as
		// `z.string().uuid()` PER ORDER (D6). The plugin can no longer produce
		// such a payload (capture + send both validate), so this posts the raw
		// wire shape through the Client — the same pattern the catalog blank
		// product_url check uses — to keep the mock honest about the engine.
		$settings = new RecEngineSettings();
		$client   = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );

		$response = $client->ingest_orders(
			array(
				array(
					'event_id'          => 'rec-id-shape-test',
					'external_order_id' => 'WC-REC-SHAPE',
					'customer_email'    => 'rec-shape@example.test',
					'ordered_at'        => '2026-08-04T10:00:00Z',
					'total_amount'      => 10.0,
					'currency'          => 'EUR',
					'status'            => 'completed',
					'smaily_rec_id'     => 'not-a-uuid',
					'items'             => array(
						array(
							'sku'        => 'woo-1',
							'qty'        => 1,
							'unit_price' => 10.0,
							'line_total' => 10.0,
						),
					),
				),
			)
		);

		self::assertSame( 0, $response['processed'] ?? null, 'A non-uuid smaily_rec_id is never processed.' );
		self::assertCount( 1, $response['errors'] ?? array() );
		self::assertSame( 'smaily_rec_id', $response['errors'][0]['field'] ?? null );
	}

	public function test_a_partial_refund_returns_the_line_and_every_later_sync_keeps_it(): void {
		// PRO-1633 end-to-end on the real chain: a real WC partial refund
		// (wc_create_refund) → the real `woocommerce_order_partially_refunded`
		// hook → the real flusher → the mock engine. A partial refund changes
		// NO order status, so without the hook the return would never be sent.
		$returned = $this->make_product( 'ORD-RET-BACK', '10.00' );
		$kept     = $this->make_product( 'ORD-RET-KEPT', '5.00' );
		$order_id = $this->make_order_with_lines(
			'returns@example.test',
			'processing',
			array( array( $returned, 2 ), array( $kept, 1 ) )
		);

		// First send: nothing has come back yet.
		self::assertSame( 1, $this->flusher()->flush()['sent'] );
		$before = $this->last_items_by_sku();
		self::assertArrayNotHasKey( 'returned_at', $before[ 'woo-' . $returned->get_id() ] );

		// The merchant refunds the whole first line, with a reason. New request.
		OrderHookHandler::reset_seen();
		$this->refund_line( $order_id, $returned->get_id(), 2, 20.0, 'Ei sobinud' );

		self::assertSame(
			'processing',
			wc_get_order( $order_id )->get_status(),
			'Precondition: a PARTIAL refund leaves the order status untouched — no other path would resync it.'
		);
		self::assertSame( 1, $this->flusher()->flush()['sent'], 'The refund hook enqueued a resync of its own.' );

		$items = $this->last_items_by_sku();
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$items[ 'woo-' . $returned->get_id() ]['returned_at'],
			'IsoDate Z form — the mock rejects anything else, like the live engine.'
		);
		self::assertSame( 'Ei sobinud', $items[ 'woo-' . $returned->get_id() ]['return_reason_raw'] );
		self::assertArrayNotHasKey(
			'return_reason_standardised',
			$items[ 'woo-' . $returned->get_id() ],
			'WooCommerce has no return taxonomy — the enum is never guessed.'
		);
		self::assertArrayNotHasKey( 'returned_at', $items[ 'woo-' . $kept->get_id() ], 'The other line stays kept.' );

		// The sender obligation (§5): items are fully REPLACED on re-ingest, so
		// an unrelated later sync that omitted the return would erase it. This
		// one is driven by a real status change, not by the refund.
		$returned_at = $items[ 'woo-' . $returned->get_id() ]['returned_at'];
		OrderHookHandler::reset_seen();
		wc_get_order( $order_id )->update_status( 'completed' );

		self::assertSame( 1, $this->flusher()->flush()['sent'] );
		$after = $this->last_items_by_sku();
		self::assertSame(
			$returned_at,
			$after[ 'woo-' . $returned->get_id() ]['returned_at'] ?? null,
			'A later sync re-derives the return from the order refunds — same date, never erased.'
		);
	}

	public function test_a_partly_refunded_quantity_leaves_the_line_kept(): void {
		// 1 of 3 back: the contract has no per-quantity return mechanism, so a
		// line is returned only when the whole quantity has come back.
		$product  = $this->make_product( 'ORD-RET-PARTQTY', '10.00' );
		$order_id = $this->make_order_with_lines( 'partqty@example.test', 'processing', array( array( $product, 3 ) ) );

		self::assertSame( 1, $this->flusher()->flush()['sent'] );

		OrderHookHandler::reset_seen();
		$this->refund_line( $order_id, $product->get_id(), 1, 10.0, 'One of three' );

		self::assertSame( 1, $this->flusher()->flush()['sent'] );
		$item = $this->last_items_by_sku()[ 'woo-' . $product->get_id() ];
		self::assertArrayNotHasKey( 'returned_at', $item );
		self::assertArrayNotHasKey( 'return_reason_raw', $item, 'No return, no reason.' );
	}

	public function test_revoked_key_401_fails_batch_without_retry(): void {
		$product = $this->make_product( 'ORD-SKU-3', '5.00' );
		$this->make_order( 'auth-401@example.test', 'completed', $product );

		$stats = $this->flusher()->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 1, $stats['failed'], 'A revoked key is terminal — mark failed, no retry.' );
		self::assertSame( 0, $stats['retried'] );
	}

	/** @var int Tax rate id created by enable_24_percent_vat(), 0 = none. */
	private int $tax_rate_id = 0;

	/**
	 * Turn the REAL WC tax engine on: 24% standard VAT (matches the pilot
	 * market), prices entered ex-tax, taxed shipping, tax by shop base.
	 * Callers pair with disable_taxes() in a finally block.
	 */
	private function enable_24_percent_vat(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		$this->tax_rate_id = (int) \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '', // every country — location-independent.
				'tax_rate_state'    => '',
				'tax_rate'          => '24.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
	}

	private function disable_taxes(): void {
		if ( $this->tax_rate_id > 0 ) {
			\WC_Tax::_delete_tax_rate( $this->tax_rate_id );
			$this->tax_rate_id = 0;
		}
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	private function flusher(): OrderFlusher {
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

	private function make_product( string $sku, string $price ): \WC_Product {
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}
		$product = new \WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'Order Test ' . $sku );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$id                       = (int) $product->save();
		$this->created_products[] = $id;

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}

	private function make_skuless_product( string $price ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Order Test (no sku)' );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$id                       = (int) $product->save();
		$this->created_products[] = $id;

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		self::assertSame( '', $loaded->get_sku(), 'Precondition: the product really has no SKU.' );
		return $loaded;
	}

	/**
	 * Drive the REAL landing → checkout chain for one `?smaily_rec=` value: the
	 * server-side LandingCapture (only the header write is stubbed — PHPUnit's
	 * own progress output makes headers_sent() true; set_cookie() still updates
	 * $_COOKIE, which is exactly what the checkout stamping reads), then the
	 * real classic-checkout attribution stamping on a real order.
	 *
	 * @return int The created order id.
	 */
	private function landing_then_order( string $rec_param, string $email, \WC_Product $product ): int {
		$_GET    = array( 'smaily_rec' => $rec_param );
		$_COOKIE = array();

		$capture = new class( new RecEngineSettings() ) extends LandingCapture {
			protected function headers_already_sent(): bool {
				return false;
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				// No real Set-Cookie header in a CLI test run; $_COOKIE is what
				// the checkout stamping reads on the following request anyway.
			}
		};
		$capture->capture();

		$order_id = $this->make_order( $email, 'completed', $product );
		// The wizard gate is closed (EnvScrub), so this only runs the
		// attribution stamping — the same call the classic checkout makes.
		( new HookHandler( new EventQueue() ) )->on_checkout_order_processed( $order_id, array() );

		$_GET    = array();
		$_COOKIE = array();

		return $order_id;
	}

	/**
	 * The wire items of the LAST order the mock received, keyed by sku.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function last_items_by_sku(): array {
		$orders = self::$engine->state()['last_orders_payload'] ?? array();
		self::assertIsArray( $orders );
		self::assertNotSame( array(), $orders, 'The mock recorded no order payload.' );

		$items = array();
		foreach ( ( end( $orders )['items'] ?? array() ) as $item ) {
			$items[ (string) $item['sku'] ] = $item;
		}
		return $items;
	}

	/**
	 * A REAL WooCommerce refund of one line — the same call the admin refund
	 * screen makes, so the real refund hooks fire.
	 */
	private function refund_line( int $order_id, int $product_id, int $qty, float $amount, string $reason ): void {
		$order = wc_get_order( $order_id );
		self::assertInstanceOf( \WC_Order::class, $order );

		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() === $product_id ) {
				$line_items[ $item_id ] = array(
					'qty'          => $qty,
					'refund_total' => $amount,
				);
			}
		}
		self::assertNotSame( array(), $line_items, 'Precondition: the order carries the line being refunded.' );

		$refund = wc_create_refund(
			array(
				'order_id'       => $order_id,
				'amount'         => $amount,
				'reason'         => $reason,
				'line_items'     => $line_items,
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		self::assertInstanceOf( \WC_Order_Refund::class, $refund );
	}

	/**
	 * @param array<int, array{0:\WC_Product, 1:int}> $lines product + quantity.
	 */
	private function make_order_with_lines( string $email, string $status, array $lines ): int {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->calculate_totals();
		$order->set_status( $status );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return $order_id;
	}

	private function make_order( string $email, string $status, \WC_Product $product ): int {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_status( $status );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return $order_id;
	}

	private function connect(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_ping'      => $base . '/api/v1/ingest/ping',
					'ingest_catalog'   => $base . '/api/v1/ingest/catalog',
					'ingest_customers' => $base . '/api/v1/ingest/customers',
					'ingest_orders'    => $base . '/api/v1/ingest/orders',
				),
			)
		);
	}
}
