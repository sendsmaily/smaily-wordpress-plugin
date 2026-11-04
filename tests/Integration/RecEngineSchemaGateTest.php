<?php
/**
 * Integration: the history imports on a schema the update has not reached,
 * and the one-time re-run of the history import (CON-282).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Constants;
use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- test fixtures on the plugin's own table.

/**
 * Through the real tick, sequence-check and progress-check callbacks and the
 * real Action Scheduler upgrade hook, against the mock engine:
 *   - before migration 013 is applied (no `sent_count` column, schema 12) the
 *     background imports send nothing, loop on nothing and forget nothing;
 *     once a queue run applies the update, the sequence goes on;
 *   - a completed orders run with a 3.20 bare-id cursor gets a stray tick:
 *     nothing reaches the engine;
 *   - a store 3.21.0 left without a finished history import gets the
 *     sequence once more; one whose history import completed does not.
 */
final class RecEngineSchemaGateTest extends TestCase {

	private const TYPES = array( 'products', 'customers', 'orders' );

	/** Safety cap on the ticks one import may take. */
	private const MAX_TICKS = 50;

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			self::markTestSkipped( 'WooCommerce / Action Scheduler not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->unschedule_all();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		global $wpdb;
		$this->unschedule_all();
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();
		// Leave the schema as the rest of the suite expects it: migration 013
		// re-adds the column when a test dropped it (a no-op otherwise).
		$suppressed = $wpdb->suppress_errors( true );
		update_option( Constants::OPTION_SCHEMA_VERSION, Migrator::REQUIRED_VERSION - 1, false );
		( new Migrator() )->migrate();
		$wpdb->suppress_errors( $suppressed );
		parent::tearDown();
	}

	public function test_on_the_old_schema_the_imports_wait_and_go_on_after_the_update(): void {
		$this->make_product();
		$this->connect( 'tok_schema_gate' );
		// The connection's catalog import is running with its sequence stored;
		// then the store runs the new code on the schema of 3.20.
		$this->drop_sent_count();
		update_option( Constants::OPTION_SCHEMA_VERSION, Migrator::REQUIRED_VERSION - 1, false );
		delete_option( ImportsOnConnect::OPTION_UPDATE_CHECKED );
		self::$engine->reset_request_count();

		do_action( ImportsOnConnect::HOOK );
		for ( $i = 0; $i < 3; $i++ ) {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => 'products' ), EventQueue::AS_GROUP );
			do_action( BackfillJobInterface::TICK_HOOK, 'products' );
		}
		do_action( HistoryProgress::HOOK );

		self::assertSame( array(), self::$engine->request_paths(), 'Nothing reaches the engine on the old schema.' );
		self::assertSame( self::TYPES, $this->stored_sequence(), 'The sequence is not forgotten.' );
		self::assertTrue( $this->tick_queued( 'products' ), 'The tick waits; it does not end.' );
		self::assertSame( '', (string) get_option( ImportsOnConnect::OPTION_UPDATE_CHECKED, '' ), 'The after-update start is not spent.' );
		$history = get_option( HistoryProgress::OPTION );
		self::assertIsArray( $history );
		self::assertFalse( $history['ended'], 'The history import is not ended.' );

		// A queue run applies the update — no wp-admin visit.
		update_option( Activation::OPTION_PLUGIN_VERSION, '0.0.0-pre' );
		do_action( 'action_scheduler_before_process_queue' );

		self::assertTrue( Migrator::schema_ready() );
		self::assertTrue( $this->has_sent_count(), 'Migration 013 ran.' );

		$this->run_to_end( 'products' );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'products' ) );
		self::assertContains( 'POST /api/v1/ingest/catalog', self::$engine->request_paths(), 'The catalog reached the engine after the update.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'customers' ), 'The sequence goes on.' );
		self::assertSame( array( 'customers', 'orders' ), $this->stored_sequence() );
	}

	public function test_a_stray_tick_on_a_completed_orders_run_of_3_20_sends_nothing(): void {
		$this->connect( 'tok_schema_stray_tick' );
		$this->unschedule_all();
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		$this->seed_row( 'orders', BackfillJobInterface::STATUS_COMPLETED, '12345', null );
		self::$engine->reset_request_count();

		do_action( BackfillJobInterface::TICK_HOOK, 'orders' );

		self::assertSame( array(), self::$engine->request_paths(), 'No batch, and no new window (no ping).' );
		self::assertFalse( $this->tick_queued( 'orders' ), 'The tick chain ends.' );
		$row = AbstractBackfillJob::read_state( 'orders' );
		self::assertIsArray( $row );
		self::assertSame( '12345', $row['cursor_value'], 'The row is left as it is.' );
		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $row['status'] );
	}

	public function test_a_store_left_without_a_finished_history_import_gets_the_sequence_once_more(): void {
		$this->connect( 'tok_schema_rerun' );
		$this->arrange_store_after_3_21_0( null );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'products' ), 'The catalog import starts again.' );
		self::assertTrue( $this->tick_queued( 'products' ) );
		self::assertSame( self::TYPES, $this->stored_sequence() );
		self::assertSame( '1', get_option( ImportsOnConnect::OPTION_RERUN_CHECKED ) );

		// Once only: the re-run's sequence gone, a later check starts nothing.
		$this->unschedule_all();
		ImportsOnConnect::forget();
		$this->seed_row( 'products', BackfillJobInterface::STATUS_COMPLETED, '0', null );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'products' ) );
		self::assertSame( array(), $this->stored_sequence() );
		self::assertFalse( $this->tick_queued( 'products' ) );
	}

	public function test_a_store_whose_history_import_completed_is_not_re_run(): void {
		$this->connect( 'tok_schema_no_rerun' );
		$this->arrange_store_after_3_21_0( 120 );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'products' ) );
		self::assertSame( array(), $this->stored_sequence() );
		self::assertFalse( $this->tick_queued( 'products' ) );
		self::assertSame( '1', get_option( ImportsOnConnect::OPTION_RERUN_CHECKED ), 'Decided once.' );
	}

	// --- helpers -----------------------------------------------------------

	/**
	 * A connected store after 3.21.0: the after-update start spent, no
	 * sequence, every import completed. $orders_sent null = the orders run is
	 * one of 3.20 (no `sent_count`); a number = a 3.21.0 run that completed.
	 */
	private function arrange_store_after_3_21_0( ?int $orders_sent ): void {
		$this->unschedule_all();
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		foreach ( self::TYPES as $type ) {
			$this->seed_row( $type, BackfillJobInterface::STATUS_COMPLETED, '0', $type === 'orders' ? $orders_sent : null );
		}
		update_option( ImportsOnConnect::OPTION_UPDATE_CHECKED, '1', false );
		delete_option( ImportsOnConnect::OPTION_RERUN_CHECKED );
	}

	private function connect( string $token ): void {
		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $response->get_status() );
	}

	private function make_product(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Schema gate' );
		$product->set_regular_price( '5.00' );
		$product->set_price( '5.00' );
		$product->set_stock_status( 'instock' );
		$this->created[] = (int) $product->save();
	}

	private function seed_row( string $job_type, string $status, string $cursor, ?int $sent ): void {
		global $wpdb;
		$table = $wpdb->prefix . AbstractBackfillJob::TABLE_SUFFIX;
		$wpdb->delete(
			$table,
			array(
				'job_type' => $job_type,
				'target'   => AbstractBackfillJob::TARGET,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'job_type'        => $job_type,
				'target'          => AbstractBackfillJob::TARGET,
				'status'          => $status,
				'total_count'     => 10,
				'processed_count' => 10,
				'sent_count'      => $sent,
				'cursor_value'    => $cursor,
				'started_at'      => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				'completed_at'    => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			)
		);
	}

	private function drop_sent_count(): void {
		global $wpdb;
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}" . AbstractBackfillJob::TABLE_SUFFIX . ' DROP COLUMN sent_count' );
		self::assertFalse( $this->has_sent_count() );
	}

	private function has_sent_count(): bool {
		global $wpdb;
		return $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}" . AbstractBackfillJob::TABLE_SUFFIX . " LIKE 'sent_count'" ) === 'sent_count';
	}

	/**
	 * Run one import's batches through the real tick callback until it ends.
	 */
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
	 * @return string[]
	 */
	private function stored_sequence(): array {
		wp_cache_delete( ImportsOnConnect::OPTION, 'options' );
		$value = get_option( ImportsOnConnect::OPTION, array() );

		return is_array( $value ) ? array_values( $value ) : array();
	}

	private function tick_queued( string $job_type ): bool {
		return as_has_scheduled_action( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ), EventQueue::AS_GROUP );
	}

	private function unschedule_all(): void {
		foreach ( self::TYPES as $job_type ) {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ), EventQueue::AS_GROUP );
		}
		as_unschedule_all_actions( ImportsOnConnect::HOOK, array(), EventQueue::AS_GROUP );
	}
}
