<?php
/**
 * Integration: OrderBackfillJob (3.5.2) — cursor traversal of existing orders
 * (legacy posts storage, the pilot's mode) with the mapped-status filter that
 * matches OrderHookHandler.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * Beyond the shared backfill properties (resumability + bounded queue), orders
 * add the STATUS FILTER: the backfill must enumerate only orders in a sale
 * state (mapped status), the same cohort OrderHookHandler enqueues — and the
 * progress denominator is mapped orders, not all orders. The HPOS path is
 * unit-tested (OrderBackfillJobTest); this exercises the active legacy path.
 */
final class RecEngineOrderBackfillTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

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
		$this->truncate_queue();
		$this->delete_all_orders();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array( 'ingest_orders' => $base . '/api/v1/ingest/orders' ),
			)
		);
	}

	protected function tearDown(): void {
		$this->delete_all_orders();
		parent::tearDown();
	}

	public function test_status_filter_excludes_unmapped_orders(): void {
		$completed = $this->make_order( 'completed' );
		$pending   = $this->make_order( 'pending' ); // unmapped → must be excluded
		$refunded  = $this->make_order( 'refunded' );

		$job = $this->job();
		$ids = $job->ids_after( 0, 10000 );

		self::assertContains( $completed, $ids );
		self::assertContains( $refunded, $ids );
		self::assertNotContains( $pending, $ids, 'A pending (unmapped) order is NOT backfilled — matches the hook.' );

		$job->start();
		self::assertSame(
			2,
			(int) $this->read_backfill_row()['total_count'],
			'Progress denominator is mapped orders (2), not all orders (3).'
		);
	}

	public function test_resumes_from_cursor_and_does_not_restart(): void {
		$this->make_order( 'completed' );
		$this->make_order( 'completed' );
		$this->make_order( 'processing' );

		$job = $this->job( 2 ); // batch_size 2; 3 mapped orders.
		$job->start();

		$b1      = $job->process_batch();
		$cursor1 = (int) $this->read_backfill_row()['cursor_value'];
		self::assertSame( 2, $b1['processed'] );
		self::assertFalse( $b1['completed'] );

		$b2      = $job->process_batch();
		$cursor2 = (int) $this->read_backfill_row()['cursor_value'];
		self::assertSame( 1, $b2['processed'], 'Resumed past the cursor — 1 order, not a restart.' );
		self::assertGreaterThan( $cursor1, $cursor2 );
		self::assertSame( 3, (int) $this->read_backfill_row()['processed_count'] );
		self::assertTrue( $b2['completed'] );
	}

	public function test_queue_is_bounded_after_each_batch(): void {
		$this->make_order( 'completed' );
		$this->make_order( 'completed' );
		$this->make_order( 'completed' );

		$queue = new IngestQueue();
		$job   = $this->job( 2, $queue );
		$job->start();

		$job->process_batch();
		self::assertSame(
			array(),
			$queue->pending( 1000, array( OrderFlusher::EVENT_ORDER_UPSERT ) ),
			'Inline flush drained the batch — bounded.'
		);
	}

	public function test_full_backfill_completes_over_mapped_orders(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->make_order( 'completed' );
		}
		$this->make_order( 'failed' ); // unmapped — not counted, not sent.

		$job = $this->job( 2 );
		$job->start();

		$guard = 0;
		do {
			$result = $job->process_batch();
		} while ( empty( $result['completed'] ) && ++$guard < 20 );

		$row = $this->read_backfill_row();
		self::assertSame( 'completed', $row['status'] );
		self::assertSame( 4, (int) $row['total_count'], 'Only the 4 mapped orders count.' );
		self::assertSame( 4, (int) $row['processed_count'] );
	}

	// --- helpers --------------------------------------------------------

	/**
	 * Test double: tunable batch size + a public window onto fetch_ids_after.
	 */
	private function job( int $batch_size = 100, ?IngestQueue $queue = null ) {
		$settings = new RecEngineSettings();
		$queue    = $queue ?? new IngestQueue();
		$flusher  = new OrderFlusher(
			$queue,
			new OrderPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);

		return new class( $queue, $flusher, $batch_size ) extends OrderBackfillJob {
			private int $bs;

			public function __construct( IngestQueue $queue, OrderFlusher $flusher, int $bs ) {
				parent::__construct( $queue, $flusher );
				$this->bs = $bs;
			}

			protected function batch_size(): int {
				return $this->bs;
			}

			/**
			 * @return int[]
			 */
			public function ids_after( int $after_id, int $limit ): array {
				return $this->fetch_ids_after( $after_id, $limit );
			}
		};
	}

	private function make_order( string $status ): int {
		$product = new \WC_Product_Simple();
		$product->set_sku( 'OBF-' . wp_generate_uuid4() );
		$product->set_name( 'Order Backfill Item' );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->set_stock_status( 'instock' );
		$pid = (int) $product->save();

		$order = wc_create_order();
		$order->set_billing_email( 'obf-' . wp_generate_uuid4() . '@example.test' );
		$order->add_product( wc_get_product( $pid ), 1 );
		$order->calculate_totals();
		$order->set_status( $status );
		return (int) $order->save();
	}

	private function delete_all_orders(): void {
		// STATUS-BLIND sweep straight off the ACTIVE order table (the same
		// table_spec the job under test queries). A registered-status filter
		// (wc_get_orders + wc_get_order_statuses()) cannot see an order whose
		// custom status is no longer registered — e.g. the `wc-label-printed`
		// residue a 2026-06-19 F3-43 live-walk left in the dev wp-env DB —
		// while the backfill's denylist SQL counts exactly such a row as a
		// sale (F3-42), so one invisible row broke every count assert here.
		// The counts assert over the WHOLE table; the sweep must be exhaustive.
		global $wpdb;
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$spec = OrderBackfillJob::table_spec( $hpos, $wpdb->prefix );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$spec['id_col']} FROM {$spec['table']} WHERE {$spec['type_col']} = %s",
				'shop_order'
			)
		);
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			} else {
				// A row wc_get_order can't hydrate must still not poison the
				// count — remove the raw row.
				$wpdb->delete( $spec['table'], array( $spec['id_col'] => $id ) );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_backfill_row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				'orders',
				AbstractBackfillJob::TARGET
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}
}
