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
 *     the update, from the first part that never completed;
 *   - a refused account moves nothing.
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
		$this->unschedule_all();
		ImportsOnConnect::forget();
		parent::tearDown();
	}

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
		// connect, the customers and orders imports never ran, no sequence.
		$this->unschedule_all();
		ImportsOnConnect::forget();
		$this->set_status( 'products', BackfillJobInterface::STATUS_COMPLETED );
		delete_option( ImportsOnConnect::OPTION_UPDATE_CHECKED );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->status( 'products' ), 'The completed catalog import is not run again.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status( 'customers' ) );
		self::assertTrue( $this->tick_queued( 'customers' ) );
		self::assertNull( $this->status( 'orders' ), 'The orders import follows the customers.' );
		self::assertSame( array( 'customers', 'orders' ), $this->stored_sequence() );
		self::assertSame( '1', get_option( ImportsOnConnect::OPTION_UPDATE_CHECKED ) );

		// Once only: with the sequence gone and the customers import stopped,
		// a later check starts nothing again.
		$this->unschedule_all();
		ImportsOnConnect::forget();
		$this->set_status( 'customers', BackfillJobInterface::STATUS_CANCELLED );

		do_action( ImportsOnConnect::HOOK );

		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $this->status( 'customers' ) );
		self::assertNull( $this->status( 'orders' ) );
		self::assertSame( array(), $this->stored_sequence() );
	}

	public function test_a_refused_account_moves_nothing(): void {
		$this->connect( 'tok_sequence_refused' );
		$this->set_status( 'products', BackfillJobInterface::STATUS_COMPLETED );
		( new RecEngineSettings() )->mark_refused();

		do_action( ImportsOnConnect::HOOK );

		self::assertNull( $this->status( 'customers' ), 'No import starts while the engine refuses the account.' );
		self::assertSame( self::TYPES, $this->stored_sequence(), 'The sequence waits.' );
	}

	// --- helpers -----------------------------------------------------------

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
