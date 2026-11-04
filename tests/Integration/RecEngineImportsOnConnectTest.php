<?php
/**
 * Integration: connecting Campaign Intelligence starts the catalog, customers
 * and orders imports one after another (CON-247).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * Through the real REST routes, the real Action Scheduler tick callback
 * (`do_action( TICK_HOOK, <type> )`) and the real check callback
 * (`do_action( ImportsOnConnect::HOOK )`), against the mock engine:
 *   - a connection runs the three imports in order, each after the one
 *     before it has finished;
 *   - Hold back ends the sequence, and "Import now" on one card starts that
 *     import only;
 *   - a store connected before this release starts the sequence once after
 *     the update, from the first part that never completed — never after a
 *     held-back catalog import, and skipping a cancelled part;
 *   - a refused account moves nothing;
 *   - Import now and the two checks leave one pending copy each, also
 *     while a copy runs (CON-285).
 */
final class RecEngineImportsOnConnectTest extends TestCase {

	private const TYPES = array( 'products', 'customers', 'orders' );

	/** Safety cap on the ticks one import may take. */
	private const MAX_TICKS = 50;

	private static ?RecEngineMockServer $engine = null;

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
		foreach ( $this->running as $action_id ) {
			\ActionScheduler::store()->mark_complete( $action_id );
		}
		$this->running = array();
		$this->unschedule_all();
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		parent::tearDown();
	}

	/** @var int[] actions a test marked as running; completed in tearDown */
	private array $running = array();

	public function test_connecting_runs_the_catalog_then_the_customers_then_the_orders_import(): void {
		$this->connect( 'tok_sequence_order' );

		self::assertSame( self::TYPES, $this->stored_sequence() );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'products' ) );
		self::assertNull( $this->status( 'customers' ), 'The customers import waits for the catalog.' );
		self::assertNull( $this->status( 'orders' ), 'The orders import waits for the customers.' );
		self::assertTrue( $this->check_queued(), 'The stall check is armed.' );

		$this->run_to_end( 'products' );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'products' ) );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'customers' ), 'The customers import starts when the catalog has finished.' );
		self::assertTrue( $this->tick_queued( 'customers' ), 'Its first batch is queued.' );
		self::assertNull( $this->status( 'orders' ) );
		self::assertSame( array( 'customers', 'orders' ), $this->stored_sequence() );

		$this->run_to_end( 'customers' );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'customers' ) );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'orders' ), 'The orders import starts when the customers have finished.' );
		self::assertTrue( $this->tick_queued( 'orders' ) );
		self::assertSame( array( 'orders' ), $this->stored_sequence() );

		$this->run_to_end( 'orders' );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'orders' ) );
		self::assertSame( array(), $this->stored_sequence(), 'The sequence ends with the orders import.' );
	}

	public function test_hold_back_ends_the_sequence_and_import_now_starts_one_import_only(): void {
		$this->connect( 'tok_sequence_hold' );

		$cancel = RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => 'products' ) );
		self::assertSame( 200, $cancel->get_status() );
		self::assertSame( array(), $this->stored_sequence(), 'Hold back ends the sequence.' );

		// A tick Action Scheduler had already claimed starts nothing either.
		do_action( BackfillJobInterface::TICK_HOOK, 'products' );
		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $this->status( 'products' ) );
		self::assertNull( $this->status( 'customers' ) );

		$start = RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'customers' ) );
		self::assertSame( 200, $start->get_status() );
		$this->run_to_end( 'customers' );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'customers' ) );
		self::assertNull( $this->status( 'orders' ), 'Import now ran the customers import only.' );
		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $this->status( 'products' ), 'The held-back catalog stays held back.' );
		self::assertSame( array(), $this->stored_sequence() );
	}

	public function test_a_store_connected_before_the_update_starts_the_sequence_once(): void {
		$this->connect( 'tok_sequence_update' );
		// The store as it stood before this release: the catalog import ran on
		// connect, the customers import failed, the orders import never ran,
		// no sequence.
		$this->unschedule_all();
		ImportsOnConnect::forget();
		$this->set_status( 'products', BackfillJobInterface::STATUS_COMPLETED );
		RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'customers' ) );
		$this->unschedule_all();
		$this->set_status( 'customers', BackfillJobInterface::STATUS_FAILED );
		delete_option( ImportsOnConnect::OPTION_UPDATE_CHECKED );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'products' ), 'The completed catalog import is not run again.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'customers' ), 'The failed customers import runs again.' );
		self::assertTrue( $this->tick_queued( 'customers' ) );
		self::assertNull( $this->status( 'orders' ), 'The orders import follows the customers.' );
		self::assertSame( array( 'customers', 'orders' ), $this->stored_sequence() );
		self::assertSame( '1', get_option( ImportsOnConnect::OPTION_UPDATE_CHECKED ) );

		// Once only: with the sequence gone and the customers import failed,
		// a later check starts nothing again.
		$this->unschedule_all();
		ImportsOnConnect::forget();
		$this->set_status( 'customers', BackfillJobInterface::STATUS_FAILED );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_FAILED, $this->status( 'customers' ) );
		self::assertNull( $this->status( 'orders' ) );
		self::assertSame( array(), $this->stored_sequence() );
	}

	public function test_after_the_update_a_held_back_catalog_import_starts_nothing(): void {
		$this->connect( 'tok_sequence_update_held' );
		RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => 'products' ) );
		$this->unschedule_all();
		delete_option( ImportsOnConnect::OPTION_UPDATE_CHECKED );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $this->status( 'products' ), 'The held-back import is left untouched.' );
		self::assertNull( $this->status( 'customers' ) );
		self::assertNull( $this->status( 'orders' ) );
		self::assertSame( array(), $this->stored_sequence() );
		self::assertSame( '1', get_option( ImportsOnConnect::OPTION_UPDATE_CHECKED ), 'Decided once.' );
	}

	public function test_after_the_update_a_cancelled_customers_import_is_skipped_and_the_orders_import_starts(): void {
		$this->connect( 'tok_sequence_update_cancelled' );
		$this->unschedule_all();
		ImportsOnConnect::forget();
		$this->set_status( 'products', BackfillJobInterface::STATUS_COMPLETED );
		$start = RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'customers' ) );
		self::assertSame( 200, $start->get_status() );
		RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => 'customers' ) );
		$this->unschedule_all();
		delete_option( ImportsOnConnect::OPTION_UPDATE_CHECKED );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $this->status( 'customers' ), 'The cancelled import is left untouched.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'orders' ) );
		self::assertTrue( $this->tick_queued( 'orders' ) );
		self::assertSame( array( 'orders' ), $this->stored_sequence() );
	}

	public function test_a_refused_account_moves_nothing(): void {
		$this->connect( 'tok_sequence_refused' );
		$this->set_status( 'products', BackfillJobInterface::STATUS_COMPLETED );
		( new RecEngineSettings() )->mark_refused();

		do_action( ImportsOnConnect::HOOK );

		self::assertNull( $this->status( 'customers' ), 'No import starts while the engine refuses the account.' );
		self::assertSame( self::TYPES, $this->stored_sequence(), 'The sequence waits.' );
	}

	public function test_import_now_and_the_checks_leave_one_pending_copy_each(): void {
		$this->connect( 'tok_sequence_one_copy' );
		$products = array( 'job_type' => 'products' );
		self::assertSame( 1, $this->pending( BackfillJobInterface::TICK_HOOK, EventQueue::AS_GROUP, $products ), 'The held-back first batch.' );

		// Import now, pressed twice: the waiting batch is replaced, not doubled.
		RestRequestHelper::post( '/backfill/start', $products );
		RestRequestHelper::post( '/backfill/start', $products );
		self::assertSame( 1, $this->pending( BackfillJobInterface::TICK_HOOK, EventQueue::AS_GROUP, $products ) );

		// Import now while a batch runs: that batch walks the restarted
		// import and re-arms itself, so none is added beside it.
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, $products, EventQueue::AS_GROUP );
		$this->mark_running( BackfillJobInterface::TICK_HOOK, EventQueue::AS_GROUP, $products );
		RestRequestHelper::post( '/backfill/start', $products );
		self::assertSame( 0, $this->pending( BackfillJobInterface::TICK_HOOK, EventQueue::AS_GROUP, $products ), 'One chain: the running batch.' );

		// Each check runs while its next copy already waits.
		$this->mark_running( ImportsOnConnect::HOOK, EventQueue::AS_GROUP );
		$this->mark_running( HistoryProgress::HOOK, HistoryProgress::AS_GROUP );
		self::assertSame( 1, $this->pending( ImportsOnConnect::HOOK, EventQueue::AS_GROUP ) );
		self::assertSame( 1, $this->pending( HistoryProgress::HOOK, HistoryProgress::AS_GROUP ) );

		do_action( ImportsOnConnect::HOOK );
		do_action( HistoryProgress::HOOK );

		self::assertSame( 1, $this->pending( ImportsOnConnect::HOOK, EventQueue::AS_GROUP ), 'One pending sequence check.' );
		self::assertSame( 1, $this->pending( HistoryProgress::HOOK, HistoryProgress::AS_GROUP ), 'One pending progress check.' );
	}

	// --- helpers -----------------------------------------------------------

	/**
	 * Queue an action of $hook and mark it as running, as Action Scheduler
	 * does when it starts it.
	 *
	 * @param array<string, mixed> $args
	 */
	private function mark_running( string $hook, string $group, array $args = array() ): void {
		$action_id = as_schedule_single_action( time(), $hook, $args, $group );
		\ActionScheduler::store()->log_execution( $action_id );
		$this->running[] = $action_id;
	}

	/**
	 * @param array<string, mixed>|null $args
	 */
	private function pending( string $hook, string $group, ?array $args = null ): int {
		$query = array(
			'hook'     => $hook,
			'group'    => $group,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		);
		if ( $args !== null ) {
			$query['args'] = $args;
		}

		return count( as_get_scheduled_actions( $query, 'ids' ) );
	}

	private function connect( string $token ): void {
		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $response->get_status() );
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

	private function set_status( string $job_type, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . AbstractBackfillJob::TABLE_SUFFIX,
			array( 'status' => $status ),
			array(
				'job_type' => $job_type,
				'target'   => AbstractBackfillJob::TARGET,
			)
		);
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

	private function check_queued(): bool {
		return as_has_scheduled_action( ImportsOnConnect::HOOK, array(), EventQueue::AS_GROUP );
	}

	private function unschedule_all(): void {
		foreach ( self::TYPES as $job_type ) {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ), EventQueue::AS_GROUP );
		}
		as_unschedule_all_actions( ImportsOnConnect::HOOK, array(), EventQueue::AS_GROUP );
	}
}
