<?php
/**
 * Integration: OrderBackfillJob — the orders import walks the sale orders
 * (the status filter that matches OrderHookHandler) newest first inside the
 * engine's history window (CON-245), on whichever order storage is active.
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
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * Beyond the shared backfill properties (resumability + bounded queue), orders
 * add the STATUS FILTER (only sale states, the same cohort OrderHookHandler
 * enqueues) and the HISTORY WINDOW (contract v1.20.0 §5, §25): the window
 * comes from the ping, else the setup exchange's stored block, else 18 / 60
 * months; orders go newest first — the start part, then the background part
 * down to the horizon — and nothing older or placed after the start is read.
 * The progress denominator is the sale orders inside the window. It runs on
 * whichever order storage is active; CI runs the suite on both (PRO-3435).
 */
final class RecEngineOrderBackfillTest extends TestCase {

	private const DAY = 86400;

	/** Start part 2 months, horizon 6 months — small enough to seed by days. */
	private const SHORT_WINDOW = array(
		'start_months'   => 2,
		'horizon_months' => 6,
		'order'          => 'newest_first',
	);

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
		$this->connect();
	}

	protected function tearDown(): void {
		$this->delete_all_orders();
		parent::tearDown();
	}

	public function test_status_filter_excludes_unmapped_orders(): void {
		$completed = $this->make_order( 'completed' );
		$pending   = $this->make_order( 'pending' ); // unmapped → must be excluded
		$refunded  = $this->make_order( 'refunded' );
		$this->truncate_queue(); // The live sync's rows: only the import's are asserted.

		$job = $this->job();
		$job->start();
		self::assertSame(
			2,
			(int) $this->read_backfill_row()['total_count'],
			'Progress denominator is mapped orders (2), not all orders (3).'
		);

		$this->run_to_end( $job );
		$sent = $this->sent_order_ids();
		self::assertContains( $completed, $sent );
		self::assertContains( $refunded, $sent );
		self::assertNotContains( $pending, $sent, 'A pending (unmapped) order is NOT backfilled — matches the hook.' );
	}

	public function test_orders_go_newest_first_start_part_then_background_and_none_past_the_horizon(): void {
		self::$engine->set_ping_history( self::SHORT_WINDOW );
		$now        = time();
		$outside    = $this->make_order( 'completed', $now - 200 * self::DAY ); // ~6.6 months: past the horizon.
		$background = $this->make_order( 'completed', $now - 150 * self::DAY );
		$start_old  = $this->make_order( 'completed', $now - 40 * self::DAY );
		$newest     = $this->make_order( 'completed', $now - 1 * self::DAY );
		$older_bg   = $this->make_order( 'completed', $now - 170 * self::DAY );
		$start_mid  = $this->make_order( 'processing', $now - 10 * self::DAY );
		$this->make_order( 'pending', $now - 5 * self::DAY ); // Not a sale.
		$this->truncate_queue();

		$job = $this->job( 2 );
		$job->start();
		self::assertSame( 5, (int) $this->read_backfill_row()['total_count'], 'Only the sale orders inside the horizon count.' );

		// Batch 1 and 2 read the start part; the second is short but not the end.
		$job->process_batch();
		$second = $job->process_batch();
		self::assertFalse( $second['completed'], 'A short start-part page still leaves the background part.' );
		self::assertNull( $this->window()['start_part_queued_to'], 'The start part is marked read only once a page past it is asked for.' );

		$this->run_to_end( $job );

		self::assertSame(
			array( $newest, $start_mid, $start_old, $background, $older_bg ),
			$this->sent_order_ids(),
			'Newest first: the start part, then the background part down to the horizon.'
		);
		self::assertNotContains( $outside, $this->sent_order_ids() );

		$row = $this->read_backfill_row();
		self::assertSame( 'completed', $row['status'] );
		self::assertSame( 5, (int) $row['processed_count'] );
		self::assertSame(
			$this->queue_row_id( $start_old ),
			$this->window()['start_part_queued_to'],
			'The start part ends at the queue row of its oldest order.'
		);
	}

	public function test_an_order_placed_after_the_import_started_is_left_to_the_live_sync(): void {
		$before = $this->make_order( 'completed', time() - self::DAY );

		$job = $this->job();
		$job->start();
		$after = $this->make_order( 'completed', time() + 60 );
		$this->truncate_queue();

		$this->run_to_end( $job );

		self::assertSame( array( $before ), $this->sent_order_ids() );
		self::assertNotContains( $after, $this->sent_order_ids() );
		$row = $this->read_backfill_row();
		self::assertSame( 1, (int) $row['total_count'] );
		self::assertSame( 1, (int) $row['processed_count'], 'The import read only the order placed before it started.' );
	}

	public function test_a_new_tick_resumes_at_the_next_order_even_with_equal_order_dates(): void {
		$same  = time() - 3 * self::DAY;
		$first = $this->make_order( 'completed', $same );
		$this->make_order( 'completed', $same );
		$this->make_order( 'completed', $same );
		$older = $this->make_order( 'completed', $same - self::DAY );
		$this->truncate_queue();

		$this->job( 2 )->start();
		$b1 = $this->job( 2 )->process_batch();
		self::assertSame( 2, $b1['processed'] );
		self::assertFalse( $b1['completed'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\|\d+$/', $this->window()['after'], 'The cursor is the last order read: date|id.' );

		// Each tick builds a new job, as the Action Scheduler tick does.
		$guard = 0;
		do {
			$result = $this->job( 2 )->process_batch();
		} while ( empty( $result['completed'] ) && ++$guard < 20 );

		$sent = $this->sent_order_ids();
		self::assertCount( 4, $sent, 'Every order once — none read twice, none skipped at the tie.' );
		self::assertSame( 4, count( array_unique( $sent ) ) );
		self::assertSame( $older, end( $sent ), 'The older order comes last.' );
		self::assertContains( $first, $sent );
		self::assertSame( 4, (int) $this->read_backfill_row()['processed_count'] );
	}

	public function test_without_a_ping_window_the_import_uses_the_setup_exchange_window(): void {
		self::$engine->set_ping_history( null );
		$this->connect( self::SHORT_WINDOW );
		$now = time();
		$in  = $this->make_order( 'completed', $now - 10 * self::DAY );
		$bg  = $this->make_order( 'completed', $now - 100 * self::DAY );
		$this->make_order( 'completed', $now - 200 * self::DAY ); // Past the stored horizon (6 months).
		$this->truncate_queue();

		$job = $this->job();
		$job->start();
		$this->run_to_end( $job );

		self::assertSame( 2, (int) $this->read_backfill_row()['total_count'] );
		self::assertSame( array( $in, $bg ), $this->sent_order_ids() );
	}

	public function test_with_no_window_anywhere_the_import_uses_18_and_60_months_and_logs_it(): void {
		self::$engine->set_ping_history( null );
		$recent = $this->make_order( 'completed', time() - 30 * self::DAY );
		$this->make_order( 'completed', (int) strtotime( '-61 months' ) );
		$this->truncate_queue();

		// DebugLog writes through error_log() under WP_DEBUG; where that goes
		// depends on the environment (CI's wp-env has no debug.log), so the
		// test points it at its own file for the start.
		self::assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'The suite runs with WP_DEBUG on (.wp-env.json).' );
		$log_file = (string) tempnam( sys_get_temp_dir(), 'smaily-history-log' );
		$previous = (string) ini_set( 'error_log', $log_file );
		try {
			$job = $this->job();
			$job->start();
		} finally {
			ini_set( 'error_log', $previous );
		}
		$this->run_to_end( $job );

		$logged = (string) file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		self::assertStringContainsString( 'the import uses the default 18 / 60 months', $logged );
		self::assertSame( 1, (int) $this->read_backfill_row()['total_count'] );
		self::assertSame( array( $recent ), $this->sent_order_ids(), 'An order older than 60 months is not sent.' );
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
		$this->run_to_end( $job );

		$row = $this->read_backfill_row();
		self::assertSame( 'completed', $row['status'] );
		self::assertSame( 4, (int) $row['total_count'], 'Only the 4 mapped orders count.' );
		self::assertSame( 4, (int) $row['processed_count'] );
	}

	// --- helpers --------------------------------------------------------

	/**
	 * Connect to the mock engine, with a stored setup-exchange history block.
	 *
	 * @param array<string, mixed>|null $history
	 */
	private function connect( ?array $history = null ): void {
		$base   = (string) self::$engine->base_url();
		$config = EnvSeed::fixture_config();
		if ( $history !== null ) {
			$config['history'] = $history;
		}
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array( 'ingest_orders' => $base . '/api/v1/ingest/orders' ),
				'config'          => $config,
			)
		);
	}

	/**
	 * The real job with a tunable batch size.
	 */
	private function job( int $batch_size = 100, ?IngestQueue $queue = null ): OrderBackfillJob {
		$settings = new RecEngineSettings();
		$queue    = $queue ?? new IngestQueue();
		$client   = static function () use ( $settings ): Client {
			return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1 );
		};
		$flusher  = new OrderFlusher( $queue, new OrderPayloadBuilder(), $settings, $client );

		return new class( $queue, $flusher, new HistoryConfig( $settings, $client ), $batch_size ) extends OrderBackfillJob {
			private int $bs;

			public function __construct( IngestQueue $queue, OrderFlusher $flusher, HistoryConfig $history, int $bs ) {
				parent::__construct( $queue, $flusher, $history );
				$this->bs = $bs;
			}

			protected function batch_size(): int {
				return $this->bs;
			}
		};
	}

	private function run_to_end( OrderBackfillJob $job ): void {
		$guard = 0;
		do {
			$result = $job->process_batch();
		} while ( empty( $result['completed'] ) && ++$guard < 20 );
		self::assertTrue( $result['completed'], 'The import reached its end.' );
	}

	private function make_order( string $status, ?int $created = null ): int {
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
		if ( $created !== null ) {
			$order->set_date_created( $created );
		}
		return (int) $order->save();
	}

	/**
	 * The orders the import queued and the mock engine accepted, in the order
	 * the import read them (queue rows are written in walk order).
	 *
	 * @return int[]
	 */
	private function sent_order_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT entity_id FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type = %s AND status = %s ORDER BY id ASC",
				OrderFlusher::EVENT_ORDER_UPSERT,
				IngestQueue::STATUS_SENT
			)
		);
		return array_map( 'intval', $ids );
	}

	private function queue_row_id( int $order_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type = %s AND entity_id = %s",
				OrderFlusher::EVENT_ORDER_UPSERT,
				(string) $order_id
			)
		);
	}

	/**
	 * The import's stored window and position (its cursor).
	 *
	 * @return array<string, mixed>
	 */
	private function window(): array {
		$window = json_decode( (string) ( $this->read_backfill_row()['cursor_value'] ?? '' ), true );
		self::assertIsArray( $window );
		return $window;
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
