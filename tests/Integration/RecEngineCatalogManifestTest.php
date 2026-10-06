<?php
/**
 * Integration: the nightly §3c catalog manifest (PRO-3859) against real
 * WooCommerce products and the mock engine.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Deactivation;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogImportOnConnect;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * Every night runs through the registered Action Scheduler callback
 * (`do_action( CatalogManifest::HOOK )`), the products import is the real
 * CatalogBackfillJob, and the mock engine answers with the §3c counts it
 * computes against what the catalog sync sent it — so "the same keys and
 * stock" is checked against what the engine actually holds.
 */
final class RecEngineCatalogManifestTest extends TestCase {

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
	}

	protected function tearDown(): void {
		$this->delete_all_products();
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => 'products' ), EventQueue::AS_GROUP );
		parent::tearDown();
	}

	public function test_the_manifest_lists_every_product_the_catalog_sync_sends_and_heals_a_lost_delete(): void {
		$in_stock  = $this->simple( 'in', 'instock' );
		$out       = $this->simple( 'out', 'outofstock' );
		$spare     = $this->simple( 'spare', 'instock' );
		$lost      = $this->simple( 'lost', 'instock' );
		$trashed   = $this->simple( 'trashed', 'instock' );
		$draft     = $this->simple( 'draft', 'instock', 'draft' );
		$private   = $this->simple( 'private', 'instock', 'private' );
		$pending   = $this->simple( 'pending', 'instock', 'pending' );
		$variable  = new \WC_Product_Variable();
		$variable->set_name( 'Manifest variable' );
		$parent_id = (int) $variable->save();
		$var_in    = $this->variation( $parent_id, 'instock' );
		$var_out   = $this->variation( $parent_id, 'outofstock' );
		wp_trash_post( $trashed );

		// The engine holds exactly what the products import sends.
		$this->forget_live_events();
		$this->run_import();
		$synced = self::$engine->state()['catalog_in_stock_by_sku'] ?? array();

		do_action( CatalogManifest::HOOK );

		$state = self::$engine->state();
		self::assertSame( 1, $state['manifest_count'] ?? 0, 'One manifest, in one request.' );
		$items = $state['last_manifest_items'];
		foreach ( $items as $item ) {
			self::assertSame( array( 'sku', 'in_stock' ), array_keys( $item ), 'An item carries sku + in_stock and nothing else.' );
		}
		$manifest = array_column( $items, 'in_stock', 'sku' );
		ksort( $manifest );
		ksort( $synced );
		self::assertSame( $synced, $manifest, 'Every product the catalog sync sent, with its key and its stock.' );
		self::assertSame(
			array(
				'woo-' . $in_stock => true,
				'woo-' . $out      => false,
				'woo-' . $spare    => true,
				'woo-' . $lost     => true,
				'woo-' . $trashed  => false,
				'woo-' . $var_in   => true,
				'woo-' . $var_out  => false,
			),
			array_intersect_key( $manifest, array_flip( array( 'woo-' . $in_stock, 'woo-' . $out, 'woo-' . $spare, 'woo-' . $lost, 'woo-' . $trashed, 'woo-' . $var_in, 'woo-' . $var_out ) ) ),
			'A trashed product is in the list, out of stock; variations are listed, not their parent.'
		);
		self::assertCount( 7, $manifest );
		foreach ( array( $draft, $private, $pending, $parent_id ) as $left_out ) {
			self::assertArrayNotHasKey( 'woo-' . $left_out, $manifest, 'Draft, private and pending products, and a variable parent, are not in the list.' );
		}

		$rows = $this->manifest_rows();
		self::assertCount( 1, $rows, 'One Event Log row per night.' );
		self::assertSame( 'sent', $rows[0]['status'] );
		$detail = $this->detail( (int) $rows[0]['id'] );
		self::assertStringContainsString( '"sku":"woo-' . $in_stock . '"', $detail['sent_payload'], 'Details shows the request.' );
		$answer = json_decode( $detail['last_response'], true );
		self::assertSame( 'accepted', $answer['outcome'] );
		self::assertSame( 7, $answer['products_in_manifest'] );
		self::assertSame( 0, $answer['removed'] );
		self::assertSame( 0, $answer['missing_in_engine'] );
		self::assertFalse( $answer['guard_tripped'] );

		// A permanent delete whose event never reached the engine.
		wc_get_product( $lost )->delete( true );
		$this->forget_live_events();

		do_action( CatalogManifest::HOOK );

		$rows = $this->manifest_rows();
		self::assertCount( 2, $rows, 'The second night is its own row.' );
		$answer = json_decode( $this->detail( (int) $rows[0]['id'] )['last_response'], true );
		self::assertSame( 1, $answer['removed'], 'The engine tombstoned the product the store no longer has.' );
		self::assertArrayHasKey( 'woo-' . $lost, self::$engine->state()['catalog_tombstoned'] );
	}

	public function test_a_store_over_the_limit_sends_nothing_and_the_event_log_says_why(): void {
		$this->simple( 'a', 'instock' );
		$this->simple( 'b', 'instock' );
		$this->simple( 'c', 'instock' );
		$this->forget_live_events();

		$this->manifest_with_limit( 2 )->run();

		self::assertArrayNotHasKey( 'manifest_count', self::$engine->state(), 'No list was sent.' );
		$rows = $this->manifest_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'failed', $rows[0]['status'] );
		self::assertSame( 'Not sent: the store has more than 2 products, the most the nightly product list can hold.', $rows[0]['last_error'], 'The merchant reads the reason on the row.' );
	}

	public function test_nothing_is_sent_while_the_engine_refuses_the_store(): void {
		$this->simple( 'a', 'instock' );
		$this->forget_live_events();
		self::$engine->set_tenant_inactive( true );

		do_action( CatalogManifest::HOOK );

		$rows = $this->manifest_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'failed', $rows[0]['status'] );
		self::assertSame( 'http_403 tenant_inactive', $rows[0]['last_error'] );
		self::assertFalse( ( new RecEngineSettings() )->sending_allowed(), 'The 403 was recorded.' );

		$before = self::$engine->request_count();
		do_action( CatalogManifest::HOOK );

		self::assertSame( $before, self::$engine->request_count(), 'The refused store sends nothing the next night.' );
		self::assertCount( 1, $this->manifest_rows(), 'And writes no new row.' );
	}

	public function test_nothing_is_sent_while_the_import_waits_to_start_or_catalog_changes_wait(): void {
		$this->simple( 'a', 'instock' );
		$this->forget_live_events();

		// Connecting queues the import; its first batch waits three minutes.
		self::assertTrue( Bootstrap::instance()->catalog_import_on_connect()->start() );
		do_action( CatalogManifest::HOOK );
		self::assertSame( 0, self::$engine->request_count(), 'No manifest while the import waits to start.' );

		$this->run_import();
		$this->simple( 'b', 'instock' ); // The live hook queues a catalog change.
		self::assertTrue( ( new IngestQueue() )->has_pending( array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) ) );
		$before = self::$engine->request_count();

		do_action( CatalogManifest::HOOK );

		self::assertSame( $before, self::$engine->request_count(), 'No manifest while catalog changes wait in the queue.' );
		self::assertSame( array(), $this->manifest_rows(), 'A skipped night writes no row.' );
	}

	public function test_a_list_that_fails_to_build_is_never_sent(): void {
		$this->simple( 'a', 'instock' );
		$this->simple( 'b', 'instock' );
		$this->forget_live_events();
		$fail = static function () {
			throw new \RuntimeException( 'stock lookup failed' );
		};
		add_filter( 'woocommerce_product_is_in_stock', $fail );

		try {
			do_action( CatalogManifest::HOOK );
		} finally {
			remove_filter( 'woocommerce_product_is_in_stock', $fail );
		}

		self::assertSame( 0, self::$engine->request_count() );
		self::assertSame( array(), $this->manifest_rows() );
	}

	public function test_the_job_runs_daily_at_three_store_time_and_deactivation_cancels_it(): void {
		$timezone = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Tallinn' );

		try {
			as_unschedule_all_actions( CatalogManifest::HOOK, array(), CatalogManifest::AS_GROUP );
			delete_option( Bootstrap::OPTION_AS_JOBS_VERIFIED );
			Bootstrap::instance()->register_action_scheduler_jobs();

			$next = as_next_scheduled_action( CatalogManifest::HOOK, array(), CatalogManifest::AS_GROUP );
			self::assertIsInt( $next );
			self::assertSame( '03:00', ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() )->format( 'H:i' ) );
			self::assertGreaterThan( time(), $next );
			self::assertLessThanOrEqual( time() + DAY_IN_SECONDS, $next );

			Deactivation::run();

			self::assertFalse( as_has_scheduled_action( CatalogManifest::HOOK, array(), CatalogManifest::AS_GROUP ), 'Deactivation stops the nightly job.' );
		} finally {
			update_option( 'timezone_string', $timezone );
			// Leave the suite the recurring set it expects.
			delete_option( Bootstrap::OPTION_AS_JOBS_VERIFIED );
			Bootstrap::instance()->register_action_scheduler_jobs();
		}
	}

	// --- helpers -------------------------------------------------------------

	private function manifest_with_limit( int $limit ): CatalogManifest {
		$bootstrap = Bootstrap::instance();
		$catalog   = $bootstrap->make_backfill_job( 'products' );
		self::assertInstanceOf( CatalogBackfillJob::class, $catalog );

		return new class( $bootstrap->ingest_queue(), $bootstrap->rec_engine_settings(), static fn (): Client => $bootstrap->rec_client( 2, CatalogManifest::TIMEOUT_SECONDS ), $catalog, $limit ) extends CatalogManifest {
			private int $limit;

			public function __construct( IngestQueue $queue, RecEngineSettings $settings, callable $client_factory, CatalogBackfillJob $catalog, int $limit ) {
				parent::__construct( $queue, $settings, $client_factory, $catalog );
				$this->limit = $limit;
			}

			protected function max_products(): int {
				return $this->limit;
			}
		};
	}

	private function run_import(): void {
		$job = Bootstrap::instance()->make_backfill_job( CatalogImportOnConnect::JOB_TYPE );
		self::assertNotNull( $job );
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => 'products' ), EventQueue::AS_GROUP );
		$job->start();
		$guard = 0;
		do {
			$result = $job->process_batch();
		} while ( empty( $result['completed'] ) && ++$guard < 20 );
		self::assertTrue( $result['completed'] );
	}

	/** Drop what the live hooks queued while the test built its products. */
	private function forget_live_events(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type <> %s", CatalogManifest::EVENT_TYPE ) );
		CatalogHookHandler::reset_seen();
	}

	/**
	 * The Event Log's catalog.manifest rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function manifest_rows(): array {
		RestRequestHelper::login_as_admin();
		$response = RestRequestHelper::get(
			'/events',
			array(
				'source' => 'rec_engine',
				'type'   => CatalogManifest::EVENT_TYPE,
			)
		);
		self::assertSame( 200, $response->get_status() );
		return $response->get_data()['events'];
	}

	/**
	 * @return array{sent_payload: string, last_response: string}
	 */
	private function detail( int $id ): array {
		$response = RestRequestHelper::get(
			'/events/detail',
			array(
				'source' => 'rec_engine',
				'id'     => $id,
			)
		);
		self::assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function simple( string $name, string $stock_status, string $status = 'publish' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Manifest ' . $name );
		$product->set_regular_price( '5.00' );
		$product->set_stock_status( $stock_status );
		$product->set_status( $status );
		return (int) $product->save();
	}

	private function variation( int $parent_id, string $stock_status ): int {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '4.00' );
		$variation->set_stock_status( $stock_status );
		return (int) $variation->save();
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
