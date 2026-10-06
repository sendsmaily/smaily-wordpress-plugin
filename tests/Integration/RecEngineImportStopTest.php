<?php
/**
 * Integration: a Campaign Intelligence import that hits an unexpected error,
 * or has nothing driving it any more, shows as stopped and can be started
 * again (PRO-3890, PRO-3886).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * The products import runs through the real Action Scheduler tick callback
 * (`do_action( TICK_HOOK, 'products' )`), the real REST status/start routes and
 * the real nightly manifest, against the mock engine.
 */
final class RecEngineImportStopTest extends TestCase {

	private const JOB_TYPE = 'products';

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
		$this->delete_all_products();
		$this->unschedule_ticks();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_catalog'          => $base . '/api/v1/ingest/catalog',
					'ingest_catalog_manifest' => $base . '/api/v1/ingest/catalog/manifest',
				),
			)
		);
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		$this->delete_all_products();
		$this->unschedule_ticks();
		parent::tearDown();
	}

	public function test_an_unexpected_error_in_a_batch_stops_the_import_and_start_import_runs_it_again(): void {
		$first  = $this->simple( 'a' );
		$second = $this->simple( 'b' );
		$this->forget_live_events();
		$this->job()->start();

		// A product that cannot be built — any error that is not the engine's.
		$fail = static function () {
			throw new \RuntimeException( 'database went away' );
		};
		add_filter( 'woocommerce_product_class', $fail );
		try {
			do_action( BackfillJobInterface::TICK_HOOK, self::JOB_TYPE );
		} finally {
			remove_filter( 'woocommerce_product_class', $fail );
		}

		$row = $this->job_row();
		self::assertSame( BackfillJobInterface::STATUS_FAILED, $row['status'], 'The import is stopped, not left running.' );
		self::assertStringStartsWith( 'RuntimeException at ', (string) $row['error_message'], 'The reason names the error class and where it was thrown.' );
		self::assertStringNotContainsString( 'database went away', (string) $row['error_message'], 'No message text, which could carry personal data.' );
		self::assertFalse( $this->tick_queued(), 'No further batch is scheduled.' );
		self::assertSame( BackfillJobInterface::STATUS_FAILED, $this->status_route()['status'], 'The Settings screen reads it as stopped.' );

		$start = RestRequestHelper::post( '/backfill/start', array( 'job_type' => self::JOB_TYPE ) );
		self::assertSame( 200, $start->get_status() );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $start->get_data()['status'] );
		self::assertTrue( $this->tick_queued(), 'Start import queues the first batch again.' );

		$this->unschedule_ticks();
		do_action( BackfillJobInterface::TICK_HOOK, self::JOB_TYPE );

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->job_row()['status'] );
		$synced = self::$engine->state()['catalog_in_stock_by_sku'] ?? array();
		self::assertArrayHasKey( 'woo-' . $first, $synced );
		self::assertArrayHasKey( 'woo-' . $second, $synced );
	}

	public function test_a_running_import_with_nothing_driving_it_stops_blocking_the_nightly_list(): void {
		$this->simple( 'a' );
		$this->forget_live_events();
		$job = $this->job();
		$job->start();

		// Just started, its first batch queued: running, and the nightly list waits.
		as_schedule_single_action( time() + 60, BackfillJobInterface::TICK_HOOK, array( 'job_type' => self::JOB_TYPE ), EventQueue::AS_GROUP );
		$this->backdate_start( HOUR_IN_SECONDS );
		self::assertTrue( $job->is_running(), 'A queued batch drives it.' );
		do_action( CatalogManifest::HOOK );
		self::assertSame( 0, self::$engine->request_count(), 'No manifest while a batch is queued.' );

		// Within the grace period after start, even with no batch queued yet.
		$this->unschedule_ticks();
		$this->backdate_start( 60 );
		self::assertTrue( $job->is_running(), 'A just-started import is not stalled yet.' );

		// Deactivation (or a crashed batch) took the queued batch away.
		$this->backdate_start( 20 * MINUTE_IN_SECONDS ); // Past the grace period.
		self::assertFalse( $job->is_running(), 'Nothing drives it any more.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->job_row()['status'], 'Nothing restarts or rewrites it.' );
		self::assertSame( BackfillJobInterface::STATUS_FAILED, $this->status_route()['status'], 'The Settings screen shows it stopped.' );
		self::assertFalse( $this->tick_queued(), 'It is not restarted automatically.' );

		do_action( CatalogManifest::HOOK );
		self::assertSame( 1, self::$engine->state()['manifest_count'] ?? 0, 'The nightly list is sent instead of skipped.' );

		$start = RestRequestHelper::post( '/backfill/start', array( 'job_type' => self::JOB_TYPE ) );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $start->get_data()['status'] );
		self::assertTrue( $job->is_running(), 'Start import runs it again.' );
		self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->status_route()['status'] );
	}

	// --- helpers -------------------------------------------------------------

	private function job(): AbstractBackfillJob {
		$job = Bootstrap::instance()->make_backfill_job( self::JOB_TYPE );
		self::assertInstanceOf( AbstractBackfillJob::class, $job );
		return $job;
	}

	/** @return array<string, mixed> */
	private function job_row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s", self::JOB_TYPE, AbstractBackfillJob::TARGET ), ARRAY_A );
		self::assertIsArray( $row );
		return $row;
	}

	private function backdate_start( int $seconds ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'smly_plus_backfill_job',
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array(
				'job_type' => self::JOB_TYPE,
				'target'   => AbstractBackfillJob::TARGET,
			)
		);
	}

	/** @return array<string, mixed> */
	private function status_route(): array {
		$response = RestRequestHelper::get( '/backfill/status', array( 'job_type' => self::JOB_TYPE ) );
		self::assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function tick_queued(): bool {
		return as_has_scheduled_action( BackfillJobInterface::TICK_HOOK, array( 'job_type' => self::JOB_TYPE ) );
	}

	private function unschedule_ticks(): void {
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => self::JOB_TYPE ) );
	}

	/** Drop what the live hooks queued while the test built its products. */
	private function forget_live_events(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smly_rec_event_queue" );
		CatalogHookHandler::reset_seen();
	}

	private function simple( string $name ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Import stop ' . $name );
		$product->set_regular_price( '5.00' );
		$product->set_stock_status( 'instock' );
		$product->set_status( 'publish' );
		return (int) $product->save();
	}

	private function delete_all_products(): void {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
