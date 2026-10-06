<?php
/**
 * Integration: connecting Campaign Intelligence starts the catalog import
 * (PRO-3743).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogImportOnConnect;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * The three rules, through the real REST routes the admin UI calls:
 *   - a successful connection starts the products import without a further
 *     click, its first batch held DELAY_SECONDS out;
 *   - holding it back (the existing /backfill/cancel) before that leaves
 *     nothing to send, and the manual "Import now" still works afterwards;
 *   - connecting again while a catalog import is queued or running starts no
 *     second one.
 */
final class RecEngineCatalogImportOnConnectTest extends TestCase {

	private const TICK_ARGS = array( 'job_type' => CatalogImportOnConnect::JOB_TYPE );

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->unschedule_ticks();
		$this->truncate_ingest_queue();
		RestRequestHelper::login_as_admin();

		// Created while NOT connected, so no live catalog hook enqueues them:
		// anything that reaches the queue or the engine came from the import.
		$this->created[] = $this->make_product( 'ONCONNECT-1' );
		$this->created[] = $this->make_product( 'ONCONNECT-2' );
	}

	protected function tearDown(): void {
		$this->unschedule_ticks();
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();
		parent::tearDown();
	}

	public function test_connecting_starts_the_catalog_import_with_a_hold_back_delay(): void {
		$before   = time();
		$response = $this->connect( 'tok_onconnect_start' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'started', $response->get_data()['catalogImport'] );
		self::assertSame( CatalogImportOnConnect::DELAY_SECONDS, $response->get_data()['catalogImportDelaySeconds'] );

		$row = $this->read_products_row();
		self::assertIsArray( $row, 'Connecting seeded the products import row.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $row['status'] );
		self::assertGreaterThanOrEqual( 2, (int) $row['total_count'] );
		self::assertSame( 0, (int) $row['processed_count'] );

		$next = as_next_scheduled_action( BackfillJobInterface::TICK_HOOK, self::TICK_ARGS, EventQueue::AS_GROUP );
		self::assertIsInt( $next, 'The first import batch is scheduled.' );
		self::assertGreaterThanOrEqual(
			$before + CatalogImportOnConnect::DELAY_SECONDS,
			$next,
			'The first batch waits for the hold-back window.'
		);

		// Nothing is sent before the first batch runs.
		self::assertSame( 0, $this->ingest_queue_rows() );
		self::assertSame( array(), self::$engine->state()['last_catalog_received'] ?? array() );
	}

	public function test_holding_back_before_the_first_batch_sends_nothing(): void {
		$this->connect( 'tok_onconnect_hold' );

		$cancel = RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => CatalogImportOnConnect::JOB_TYPE ) );
		self::assertSame( 200, $cancel->get_status() );
		self::assertTrue( $cancel->get_data()['cancelled'] );

		self::assertFalse(
			as_has_scheduled_action( BackfillJobInterface::TICK_HOOK, self::TICK_ARGS, EventQueue::AS_GROUP ),
			'Hold back removed the pending first batch.'
		);
		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $this->read_products_row()['status'] ?? null );
		self::assertSame( 0, $this->ingest_queue_rows() );
		self::assertSame( array(), self::$engine->state()['last_catalog_received'] ?? array() );

		// The manual "Import now" works as before after a hold-back.
		$start = RestRequestHelper::post( '/backfill/start', array( 'job_type' => CatalogImportOnConnect::JOB_TYPE ) );
		self::assertSame( 200, $start->get_status() );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->read_products_row()['status'] ?? null );
	}

	public function test_reconnecting_while_the_import_is_queued_starts_no_second_import(): void {
		$this->connect( 'tok_onconnect_queued_1' );
		$first = $this->read_products_row();

		$response = $this->connect( 'tok_onconnect_queued_2' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'unchanged', $response->get_data()['catalogImport'] );
		self::assertSame( $first, $this->read_products_row(), 'The queued import row is untouched.' );
		self::assertCount( 1, $this->pending_tick_ids(), 'Still exactly one pending first batch.' );
	}

	public function test_reconnecting_while_the_import_is_running_keeps_its_progress(): void {
		$this->connect( 'tok_onconnect_running_1' );

		// Mid-walk: some products already processed, the cursor advanced.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . AbstractBackfillJob::TABLE_SUFFIX,
			array(
				'processed_count' => 1,
				'cursor_value'    => (string) $this->created[0],
			),
			array(
				'job_type' => CatalogImportOnConnect::JOB_TYPE,
				'target'   => AbstractBackfillJob::TARGET,
			)
		);

		$response = $this->connect( 'tok_onconnect_running_2' );

		self::assertSame( 'unchanged', $response->get_data()['catalogImport'] );
		$row = $this->read_products_row();
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $row['status'] ?? null );
		self::assertSame( 1, (int) ( $row['processed_count'] ?? 0 ), 'Not restarted from zero.' );
		self::assertSame( (string) $this->created[0], $row['cursor_value'] ?? null, 'The cursor is kept.' );
		self::assertCount( 1, $this->pending_tick_ids() );
	}

	public function test_a_failed_exchange_starts_nothing(): void {
		$response = $this->connect( 'notfound_onconnect' );

		self::assertSame( 400, $response->get_status() );
		self::assertNull( $this->read_products_row() );
		self::assertSame( array(), $this->pending_tick_ids() );
	}

	// --- helpers -----------------------------------------------------------

	private function connect( string $token ): \WP_REST_Response {
		return RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_products_row(): ?array {
		global $wpdb;
		$table = $wpdb->prefix . AbstractBackfillJob::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, total_count, processed_count, cursor_value, started_at FROM {$table} WHERE job_type = %s AND target = %s",
				CatalogImportOnConnect::JOB_TYPE,
				AbstractBackfillJob::TARGET
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return int[]
	 */
	private function pending_tick_ids(): array {
		return array_values(
			as_get_scheduled_actions(
				array(
					'hook'   => BackfillJobInterface::TICK_HOOK,
					'args'   => self::TICK_ARGS,
					'group'  => EventQueue::AS_GROUP,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}

	private function unschedule_ticks(): void {
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, self::TICK_ARGS, EventQueue::AS_GROUP );
	}

	private function ingest_queue_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . IngestQueue::TABLE_SUFFIX );
	}

	private function truncate_ingest_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . IngestQueue::TABLE_SUFFIX );
	}

	private function make_product( string $sku ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'On-connect ' . $sku );
		$product->set_sku( $sku );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );
		return (int) $product->save();
	}
}
