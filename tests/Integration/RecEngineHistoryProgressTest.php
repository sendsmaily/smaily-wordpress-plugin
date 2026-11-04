<?php
/**
 * Integration: the §25 history progress report (CON-248) — the phases reach
 * the engine in order with the counts of rows it accepted, a failed report is
 * tried again, the import finishes whatever the report does, and a cancelled
 * orders import ends with one final report (CON-284).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportRows;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * Through the real setup-exchange route, the real backfill tick
 * (`do_action( TICK_HOOK, <type> )`), the real progress check
 * (`do_action( HistoryProgress::HOOK )`) and the real order flush, against
 * the mock engine (which validates the §25 body and records every call). It
 * runs on whichever order storage is active; CI runs both (PRO-3435).
 */
final class RecEngineHistoryProgressTest extends TestCase {

	private const DAY = 86400;

	private const TYPES = array( 'products', 'customers', 'orders' );

	/** Safety cap on the ticks one import may take. */
	private const MAX_TICKS = 50;

	private static ?RecEngineMockServer $engine = null;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			self::markTestSkipped( 'WooCommerce / Action Scheduler not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->unschedule_all();
		$this->delete_all_orders();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		$this->unschedule_all();
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		$this->delete_all_orders();
		parent::tearDown();
	}

	public function test_the_phases_reach_the_engine_in_order_with_the_accepted_counts(): void {
		$now        = time();
		$newest     = $this->make_order( 'completed', $now - self::DAY );
		$this->make_order( 'processing', $now - 200 * self::DAY ); // The start part's oldest.
		$this->make_order( 'completed', $now - 900 * self::DAY ); // ~30 months: the background part.
		$this->make_order( 'completed', $now - 2000 * self::DAY ); // ~66 months: past the horizon, never sent.
		$this->make_order( 'pending', $now - 2 * self::DAY ); // Not a sale.

		$this->connect( 'tok_progress_phases' );
		do_action( HistoryProgress::HOOK );

		$reports = self::$engine->history_progress_requests();
		self::assertCount( 1, $reports, 'started goes out before the catalog.' );
		self::assertSame( 'started', $reports[0]['phase'] );
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9._:-]{1,128}$/', $reports[0]['import_id'] );
		self::assertSame( 0, $reports[0]['entities']['orders']['sent'] );
		self::assertTrue( $this->check_queued(), 'The next check is armed.' );

		foreach ( self::TYPES as $job_type ) {
			$this->run_to_end( $job_type );
			self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( $job_type ), $job_type );
		}

		// One order row of the run still waits (a retry parked before the
		// import ended): the start part is in, the whole history is not.
		$waiting = ( new IngestQueue() )->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, (string) $newest, array() );
		self::assertIsInt( $waiting );
		$this->backdate_queue_row( $waiting );

		do_action( HistoryProgress::HOOK );
		$reports = self::$engine->history_progress_requests();
		self::assertCount( 2, $reports, 'A phase change goes out at once.' );
		self::assertSame( 'start_part_complete', $reports[1]['phase'] );
		self::assertSame( $reports[0]['import_id'], $reports[1]['import_id'], 'One import id for the whole sequence.' );

		( new IngestQueue() )->mark_sent( $waiting );
		do_action( HistoryProgress::HOOK );

		$reports = self::$engine->history_progress_requests();
		self::assertCount( 3, $reports );
		$complete = $reports[2];
		self::assertSame( 'complete', $complete['phase'] );
		$window = OrderBackfillJob::decode_window( (string) $this->row( 'orders' )['cursor_value'] );
		self::assertIsArray( $window );
		self::assertSame( substr( $window['horizon'], 0, 10 ), $complete['horizon_start'], 'The window the orders run walked: 60 months back.' );
		self::assertSame(
			array(
				'expected'       => 3,
				'sent'           => 3,
				'oldest_sent_at' => gmdate( 'Y-m-d', $now - 900 * self::DAY ),
			),
			$complete['entities']['orders'],
			'Only the sale orders inside the window; the engine accepted each.'
		);
		self::assertSame( array( 'expected' => 3 ), $complete['entities']['buyers'], 'Three buyers with a purchase.' );
		self::assertSame( (int) $this->row( 'customers' )['total_count'], $complete['entities']['customers']['expected'] );
		self::assertSame( $complete['entities']['customers']['expected'], $complete['entities']['customers']['sent'] );
		self::assertNull( $complete['entities']['catalog']['expected'] );
		self::assertSame( (int) $this->row( 'products' )['sent_count'], $complete['entities']['catalog']['sent'] );
		self::assertGreaterThanOrEqual( 5, $complete['entities']['catalog']['sent'], 'Each seeded product.' );
		self::assertFalse( $this->check_queued(), 'No more checks once complete is in.' );

		do_action( HistoryProgress::HOOK );
		self::assertCount( 3, self::$engine->history_progress_requests(), 'Nothing more is reported.' );
	}

	public function test_a_failed_report_is_tried_again_and_the_import_still_finishes(): void {
		$this->make_order( 'completed', time() - self::DAY );
		$this->connect( 'tok_progress_failure' );
		self::$engine->set_history_progress_status( 500 );

		do_action( HistoryProgress::HOOK );

		self::assertCount( 1, self::$engine->history_progress_requests(), 'Tried once.' );
		self::assertGreaterThan( 0, $this->state()['failed_at'] );
		self::assertTrue( $this->check_queued(), 'The checks go on.' );

		foreach ( self::TYPES as $job_type ) {
			$this->run_to_end( $job_type );
			self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( $job_type ), 'The import finishes: ' . $job_type );
		}

		self::$engine->set_history_progress_status( 0 );
		do_action( HistoryProgress::HOOK );
		self::assertCount( 1, self::$engine->history_progress_requests(), 'Not again within five minutes.' );

		$state              = $this->state();
		$state['failed_at'] = (int) $state['failed_at'] - HistoryProgress::REPORT_EVERY_SECONDS;
		update_option( HistoryProgress::OPTION, $state, false );

		do_action( HistoryProgress::HOOK );

		$reports = self::$engine->history_progress_requests();
		self::assertCount( 2, $reports );
		self::assertSame( 'complete', $reports[1]['phase'], 'The retry carries the phase of its moment.' );
		self::assertSame( 1, $reports[1]['entities']['orders']['sent'] );
	}

	public function test_a_cancelled_orders_import_sends_one_final_report_with_the_phase_it_reached(): void {
		$now = time();
		$this->make_order( 'completed', $now - self::DAY );
		$this->make_order( 'completed', $now - 900 * self::DAY );
		$this->connect( 'tok_progress_cancel' );
		do_action( HistoryProgress::HOOK );

		foreach ( array( 'products', 'customers' ) as $job_type ) {
			$this->run_to_end( $job_type );
		}
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'orders' ), 'The sequence started the orders import.' );

		$response = RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => 'orders' ) );
		self::assertSame( 200, $response->get_status() );
		do_action( HistoryProgress::HOOK );

		$reports = self::$engine->history_progress_requests();
		self::assertCount( 2, $reports, 'One final report after the cancel.' );
		self::assertSame( $reports[0]['import_id'], $reports[1]['import_id'] );
		self::assertSame( 'started', $reports[1]['phase'], 'CON-284: stopped inside the start part — never complete.' );
		self::assertSame( 0, $reports[1]['entities']['orders']['sent'], 'Its final count: no order was sent.' );
		self::assertSame( 2, $reports[1]['entities']['orders']['expected'] );
		self::assertTrue( $this->state()['ended'] );
		self::assertFalse( $this->check_queued(), 'The check unschedules itself.' );

		do_action( HistoryProgress::HOOK );
		self::assertCount( 2, self::$engine->history_progress_requests(), 'No second final report.' );
	}

	public function test_import_now_on_the_orders_card_starts_a_new_history_import(): void {
		$this->make_order( 'completed', time() - self::DAY );
		$this->connect( 'tok_progress_import_now' );
		do_action( HistoryProgress::HOOK );
		$first = self::$engine->history_progress_requests()[0]['import_id'];

		$response = RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'orders' ) );
		self::assertSame( 200, $response->get_status() );
		do_action( HistoryProgress::HOOK );

		$reports = self::$engine->history_progress_requests();
		self::assertCount( 2, $reports );
		self::assertNotSame( $first, $reports[1]['import_id'], 'Send history again = a new import (§25 rule 5).' );
		self::assertSame( 'started', $reports[1]['phase'] );
		self::assertSame( 1, $reports[1]['entities']['orders']['expected'], 'The orders run is this import\'s orders part.' );

		$card = RestRequestHelper::get( '/backfill/status', array( 'job_type' => 'orders' ) )->get_data()['history'];
		self::assertSame( 'start', $card['part'], 'The Orders card shows the start part.' );
		self::assertSame( 18, $card['start_months'] );
		self::assertSame( 60, $card['horizon_months'] );
	}

	public function test_only_the_rows_the_engine_stored_count_on_the_run(): void {
		$this->connect( 'tok_progress_counts' );
		RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'orders' ) );
		$run = (string) $this->row( 'orders' )['started_at'];
		self::assertSame( '0', (string) $this->row( 'orders' )['sent_count'], 'A new run starts its count at zero.' );

		$stored   = $this->make_order( 'completed', time() - self::DAY );
		$rejected = $this->make_order( 'completed', time() - self::DAY, 'd6err-' );
		$too_old  = $this->make_order( 'completed', time() - 2000 * self::DAY );
		$queue    = new IngestQueue();
		foreach ( array( $stored, $rejected, $too_old ) as $order_id ) {
			$queue->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, (string) $order_id, ImportRows::mark( 'orders', $run ), null, OrderFlusher::FLUSH_HOOK, OrderFlusher::AS_GROUP );
		}

		do_action( OrderFlusher::FLUSH_HOOK );

		self::assertSame(
			'1',
			(string) $this->row( 'orders' )['sent_count'],
			'Not the per-item rejection, not the order older than the horizon, not the live sync\'s rows.'
		);
	}

	// --- helpers --------------------------------------------------------

	private function connect( string $token ): void {
		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $response->get_status() );
	}

	private function run_to_end( string $job_type ): void {
		for ( $i = 0; $i < self::MAX_TICKS && $this->status( $job_type ) === BackfillJobInterface::STATUS_RUNNING; $i++ ) {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ), EventQueue::AS_GROUP );
			do_action( BackfillJobInterface::TICK_HOOK, $job_type );
		}
	}

	private function status( string $job_type ): ?string {
		$row = AbstractBackfillJob::read_state( $job_type );
		return is_array( $row ) ? (string) $row['status'] : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( string $job_type ): array {
		$row = AbstractBackfillJob::read_state( $job_type );
		self::assertIsArray( $row, $job_type );
		return $row;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$state = get_option( HistoryProgress::OPTION );
		self::assertIsArray( $state );
		return $state;
	}

	private function check_queued(): bool {
		return is_int( as_next_scheduled_action( HistoryProgress::HOOK, array(), HistoryProgress::AS_GROUP ) );
	}

	private function backdate_queue_row( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . IngestQueue::TABLE_SUFFIX, array( 'created_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
	}

	private function make_order( string $status, int $created, string $email_prefix = 'hp-' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'History Progress Item' );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->set_stock_status( 'instock' );
		$pid = (int) $product->save();

		$order = wc_create_order();
		$order->set_billing_email( $email_prefix . wp_generate_uuid4() . '@example.test' );
		$order->add_product( wc_get_product( $pid ), 1 );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->set_date_created( $created );
		return (int) $order->save();
	}

	private function delete_all_orders(): void {
		// Status-blind off the active order table (CLAUDE.md: wp_delete_post is
		// an HPOS no-op, and a registered-status sweep misses a custom status).
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

	private function unschedule_all(): void {
		foreach ( self::TYPES as $job_type ) {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ), EventQueue::AS_GROUP );
		}
		as_unschedule_all_actions( ImportsOnConnect::HOOK, array(), EventQueue::AS_GROUP );
		as_unschedule_all_actions( HistoryProgress::HOOK, array(), HistoryProgress::AS_GROUP );
	}
}
