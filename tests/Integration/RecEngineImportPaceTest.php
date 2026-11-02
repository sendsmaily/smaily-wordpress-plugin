<?php
/**
 * Integration: one run of a history import (CON-236) — batch after batch at
 * the import pace until the import ends, against the mock engine; a 429
 * ends the run and the next tick waits what the engine asked; a live order
 * placed during an orders import goes out within the same run.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CustomerBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportPace;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

final class RecEngineImportPaceTest extends TestCase {

	private const DAY = 86400;

	private const USERS = 24;

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			self::markTestSkipped( 'WooCommerce / Action Scheduler not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->delete_non_admin_users();
		$this->delete_all_orders();
		$this->truncate_queue();
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK );

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_customers' => $base . '/api/v1/ingest/customers',
					'ingest_orders'    => $base . '/api/v1/ingest/orders',
				),
			)
		);
		delete_option( ContactSyncMode::OPTION_SYNC_ENABLED );
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_LEGITIMATE_INTEREST );
	}

	protected function tearDown(): void {
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK );
		$this->delete_all_orders();
		$this->delete_non_admin_users();
		delete_option( ContactSyncMode::OPTION_MODE );
		parent::tearDown();
	}

	public function test_one_run_sends_a_whole_import_page_after_page_within_its_budget(): void {
		$this->make_users( self::USERS );
		$this->truncate_queue(); // The live hook's rows for the new users.

		$job = $this->customer_job( 3 );
		$job->start();
		$total = (int) $this->read_backfill_row( 'customers' )['total_count'];

		$started = microtime( true );
		$result  = $job->process_run();
		$elapsed = microtime( true ) - $started;

		self::assertTrue( $result['completed'], 'One run read the whole import.' );
		self::assertSame( $total, $result['processed'] );
		self::assertSame( $total, $result['sent'] );
		self::assertLessThan( ImportPace::RUN_BUDGET_SECONDS, $elapsed );
		$pages = (int) ceil( $total / 3 );
		self::assertGreaterThanOrEqual(
			( $pages - 1 ) / ImportPace::REQUESTS_PER_SECOND,
			$elapsed,
			'The requests were spaced to the pace.'
		);
		self::assertSame( $pages, self::$engine->request_count(), 'One request a page.' );
		self::assertSame( 0, ( new IngestQueue() )->pending_count( array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ) );
		self::assertSame( 'completed', $this->read_backfill_row( 'customers' )['status'] );
	}

	public function test_a_429_ends_the_run_and_the_next_tick_waits_the_engines_wait(): void {
		$this->make_users( 4 );
		$this->truncate_queue();

		$job = $this->customer_job( 2 );
		$job->start();
		self::$engine->set_ingest_throttle( 1, 9 );

		$result = $job->process_run();

		self::assertFalse( $result['completed'] );
		self::assertSame( 9, $result['next_run_in'], 'The wait is the body\'s retry_after_seconds.' );
		self::assertSame( 2, self::$engine->request_count(), 'Nothing is sent after the 429.' );
		self::assertSame( 4, $result['processed'], 'The throttled page is read; its rows stay queued.' );
		self::assertSame( 2, ( new IngestQueue() )->pending_count( array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ) );
		self::assertSame( 'running', $this->read_backfill_row( 'customers' )['status'] );
	}

	public function test_the_tick_reschedules_a_throttled_import_after_the_engines_wait(): void {
		$this->make_users( 2 );
		$this->truncate_queue();

		$bootstrap = Bootstrap::instance();
		$job       = $bootstrap->make_backfill_job( 'customers' );
		self::assertInstanceOf( AbstractBackfillJob::class, $job );
		$job->start();
		self::$engine->set_ingest_throttle( 0, 45 );

		$before = time();
		$bootstrap->on_backfill_tick( 'customers' );

		$next = as_next_scheduled_action( BackfillJobInterface::TICK_HOOK, array( 'job_type' => 'customers' ), EventQueue::AS_GROUP );
		self::assertIsInt( $next );
		self::assertGreaterThanOrEqual( $before + 45, $next );
		self::assertLessThanOrEqual( time() + 45, $next );
		self::assertSame( 1, self::$engine->request_count(), 'One import request, one attempt.' );
	}

	/**
	 * A live order placed while an orders import runs is not held behind the
	 * import: the run's own drain sends it, before the run ends.
	 */
	public function test_a_live_order_placed_during_an_orders_import_goes_out_in_the_same_run(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->make_order( time() - $i * self::DAY );
		}
		$this->truncate_queue();

		$live = 0;
		$job  = $this->order_job(
			2,
			function () use ( &$live ): void {
				if ( $live === 0 ) {
					$live = $this->make_order( time() + 60 );
				}
			}
		);
		$job->start();

		$result = $job->process_run();

		self::assertTrue( $result['completed'] );
		self::assertGreaterThan( 0, $live );
		self::assertContains( $live, $this->sent_order_ids(), 'The live order went out in the run.' );
		self::assertCount( 6, array_unique( $this->sent_order_ids() ), 'Five imported orders and the live one.' );
		self::assertNotContains( $live, $this->imported_order_ids(), 'The import itself never read the live order.' );
	}

	// --- helpers --------------------------------------------------------

	/** The real customer import with a tunable batch size. */
	private function customer_job( int $batch_size ): CustomerBackfillJob {
		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$flusher  = new CustomerFlusher(
			$queue,
			new CustomerPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1, null, ImportPace::REQUEST_TIMEOUT_SECONDS );
			}
		);

		return new class( $queue, $flusher, new CustomerScope( $settings ), $batch_size ) extends CustomerBackfillJob {
			private int $bs;

			public function __construct( IngestQueue $queue, CustomerFlusher $flusher, CustomerScope $scope, int $bs ) {
				parent::__construct( $queue, $flusher, $scope );
				$this->bs = $bs;
			}

			protected function batch_size(): int {
				return $this->bs;
			}
		};
	}

	/** The real orders import with a tunable batch size and a hook before each record is queued. */
	private function order_job( int $batch_size, callable $before_enqueue ): OrderBackfillJob {
		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$client   = static function () use ( $settings ): Client {
			return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1, null, ImportPace::REQUEST_TIMEOUT_SECONDS );
		};
		$flusher  = new OrderFlusher( $queue, new OrderPayloadBuilder(), $settings, $client );

		return new class( $queue, $flusher, new HistoryConfig( $settings, $client ), $batch_size, $before_enqueue ) extends OrderBackfillJob {
			private int $bs;

			/** @var callable */
			private $before_enqueue;

			public function __construct( IngestQueue $queue, OrderFlusher $flusher, HistoryConfig $history, int $bs, callable $before_enqueue ) {
				parent::__construct( $queue, $flusher, $history );
				$this->bs             = $bs;
				$this->before_enqueue = $before_enqueue;
			}

			protected function batch_size(): int {
				return $this->bs;
			}

			protected function enqueue_record( int $entity_id ): void {
				( $this->before_enqueue )();
				parent::enqueue_record( $entity_id );
			}
		};
	}

	private function make_users( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$id = wp_insert_user(
				array(
					'user_login' => 'pace_' . $i . '_' . wp_generate_password( 6, false ),
					'user_pass'  => 'x' . wp_generate_password( 12, false ),
					'user_email' => 'pace-' . $i . '-' . wp_generate_password( 6, false ) . '@example.test',
					'role'       => 'customer',
				)
			);
			self::assertIsInt( $id );
			$this->created_users[] = (int) $id;
		}
	}

	private function make_order( int $created ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Import Pace Item' );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->set_stock_status( 'instock' );
		$pid = (int) $product->save();

		$order = wc_create_order();
		$order->set_billing_email( 'pace-' . wp_generate_uuid4() . '@example.test' );
		$order->add_product( wc_get_product( $pid ), 1 );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->set_date_created( $created );
		return (int) $order->save();
	}

	/** @return int[] The orders whose order.upsert row was sent, in queue order. */
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

	/** @return int[] The orders the import queued (their rows carry the run's mark). */
	private function imported_order_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT entity_id FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type = %s AND payload LIKE %s",
				OrderFlusher::EVENT_ORDER_UPSERT,
				'%"import":"orders"%'
			)
		);
		return array_map( 'intval', $ids );
	}

	private function delete_all_orders(): void {
		// Status-blind, off the active order table (LESSONS §2.16).
		global $wpdb;
		$spec = OrderBackfillJob::table_spec( OrderBackfillJob::uses_hpos(), $wpdb->prefix );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT {$spec['id_col']} FROM {$spec['table']} WHERE {$spec['type_col']} = %s", 'shop_order' ) );
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			} else {
				$wpdb->delete( $spec['table'], array( $spec['id_col'] => $id ) );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function delete_non_admin_users(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$ids = get_users(
			array(
				'fields'  => 'ID',
				'exclude' => array( 1 ),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_user( (int) $id );
		}
		$this->created_users = array();
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}

	/** @return array<string, mixed> */
	private function read_backfill_row( string $job_type ): array {
		return AbstractBackfillJob::read_state( $job_type ) ?? array();
	}
}
