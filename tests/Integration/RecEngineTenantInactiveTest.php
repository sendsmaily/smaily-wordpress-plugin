<?php
/**
 * Integration: a deactivated Campaign Intelligence account (contract §2 —
 * `403 tenant_inactive`) stops scheduled traffic and is reported truthfully.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What this catches that the unit tests can't:
 *
 *   - "No request reaches the engine" is only provable from the RECEIVING
 *     end. The mock counts every request that arrives; after the refusal the
 *     test zeroes that counter, then runs a scheduled flush, the health probe
 *     and a browse `/relay` POST and asserts the counter is still zero. A
 *     mocked client would prove nothing here — a real HTTP call either
 *     happens or it doesn't.
 *
 *   - The refusal is recorded through the REAL wp_options write, read back by
 *     a fresh RecEngineSettings, and survives across the objects involved.
 *
 *   - The way back is the real one: a setup exchange through the REST
 *     endpoint against the mock, which is what a merchant does after Smaily
 *     reactivates the account.
 *
 * NOT covered here (and deliberately): the live engine's own 403. The sandbox
 * tenant cannot be deactivated, so the real-engine half of this is human
 * acceptance with the engine team, not a live-walk.
 */
final class RecEngineTenantInactiveTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> */
	private array $created_products = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the catalog flush needs WC_Product.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );
	}

	protected function tearDown(): void {
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_products = array();
		self::$engine->set_tenant_inactive( false );
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );
		parent::tearDown();
	}

	public function test_a_403_tenant_inactive_stops_every_scheduled_engine_call(): void {
		$this->connect_to_mock();
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, true );

		$queue = new IngestQueue();
		$ids   = array( $this->make_product( 'TI-1' ), $this->make_product( 'TI-2' ) );
		$this->seed_queue( $queue, $ids );
		$flusher = $this->catalog_flusher( $queue );

		// The account is deactivated. The first scheduled flush finds out the
		// only way anyone can: by asking.
		self::$engine->set_tenant_inactive( true );
		$stats = $flusher->flush();

		self::assertSame( 2, $stats['failed'], 'The batch that met the 403 is terminal — no per-row retry.' );
		self::assertGreaterThan( 0, self::$engine->request_count(), 'That first batch did reach the engine.' );

		$settings = new RecEngineSettings();
		self::assertTrue( $settings->is_refused(), 'The refusal is recorded locally, read back from wp_options.' );
		self::assertTrue( $settings->is_connected(), 'The connection is kept — only sending stops.' );
		self::assertFalse( $settings->sending_allowed() );

		// From here on, nothing may reach the engine.
		self::$engine->reset_request_count();

		$this->seed_queue( $queue, array( $this->make_product( 'TI-3' ) ) );
		$again = $this->catalog_flusher( $queue )->flush();
		self::assertSame( 0, $again['processed'], 'The next scheduled flush short-circuits before the request.' );

		$this->health_manager()->run_health_check();

		$relay = RestRequestHelper::post(
			'/relay',
			array(
				'events' => array(
					array(
						'event_id'   => 'ti-relay-1',
						'event_type' => 'page_view',
						'session_id' => 's-ti',
						'event_ts'   => '2026-09-04T10:00:00Z',
					),
				),
			)
		);
		self::assertSame( 404, $relay->get_status(), 'The /relay proxy answers the browser without forwarding.' );

		self::assertSame(
			0,
			self::$engine->request_count(),
			'No scheduled flush, health probe or browse relay may reach a deactivated account.'
		);

		// The rows are kept, not dropped: the TI-3 row was never touched.
		$pending = $queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) );
		self::assertCount( 1, $pending, 'A row enqueued while refused waits in the queue.' );
	}

	public function test_the_notice_says_deactivated_and_replaces_the_unreachable_one(): void {
		$this->connect_to_mock();
		RestRequestHelper::login_as_admin();

		// A stale "engine unreachable" verdict from before the deactivation.
		update_option(
			NotificationManager::OPTION_NOTICES,
			array( 'engine_down' => array( 'severity' => 'error', 'down_since' => time() - 7200 ) ),
			false
		);
		( new RecEngineSettings() )->mark_refused();

		ob_start();
		$this->health_manager()->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'has been deactivated', $html );
		self::assertStringContainsString( 'Contact Smaily to reactivate it', $html );
		self::assertStringNotContainsString(
			'unreachable for over an hour',
			$html,
			'The generic engine-unreachable notice must not show while the account is deactivated.'
		);
	}

	public function test_the_health_check_clears_a_stale_unreachable_verdict(): void {
		$this->connect_to_mock();
		RestRequestHelper::login_as_admin();
		update_option( NotificationManager::OPTION_DOWN_SINCE, time() - 7200, false );
		( new RecEngineSettings() )->mark_refused();

		self::$engine->reset_request_count();
		$manager = $this->health_manager();
		$manager->run_health_check();

		ob_start();
		$manager->render();
		$html = (string) ob_get_clean();

		self::assertSame( 0, self::$engine->request_count(), 'The probe does not run against a refused account.' );
		self::assertStringNotContainsString( 'unreachable for over an hour', $html );
		self::assertFalse( get_option( NotificationManager::OPTION_DOWN_SINCE ) );
	}

	public function test_a_new_setup_exchange_clears_the_refusal_and_traffic_resumes(): void {
		$this->connect_to_mock();
		( new RecEngineSettings() )->mark_refused();

		// Smaily reactivates the account; the merchant connects it with a
		// fresh setup link. This is the REAL exchange path, through the same
		// REST endpoint the wizard posts to.
		RestRequestHelper::login_as_admin();
		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( 'tok_reactivated' ) )
		);
		self::assertSame( 200, $response->get_status() );

		$settings = new RecEngineSettings();
		self::assertFalse( $settings->is_refused(), 'A successful exchange is the way back.' );
		self::assertTrue( $settings->sending_allowed() );

		// And sending really does resume — the mock is asked again.
		$queue = new IngestQueue();
		$this->seed_queue( $queue, array( $this->make_product( 'TI-BACK-1' ) ) );
		self::$engine->reset_request_count();

		$stats = $this->catalog_flusher( $queue )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertGreaterThan( 0, self::$engine->request_count() );
	}

	// --- helpers -----------------------------------------------------------

	private function connect_to_mock(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => $this->mock_endpoints( $base ),
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function mock_endpoints( string $base ): array {
		return array(
			'ingest_ping'    => $base . '/api/v1/ingest/ping',
			'ingest_catalog' => $base . '/api/v1/ingest/catalog',
			'ingest_browse'  => $base . '/api/v1/ingest/browse',
		);
	}

	private function catalog_flusher( IngestQueue $queue ): IngestFlusher {
		$settings = new RecEngineSettings();
		return new IngestFlusher(
			$queue,
			new CatalogPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1 );
			}
		);
	}

	private function health_manager(): NotificationManager {
		$settings = new RecEngineSettings();
		return new NotificationManager(
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1 );
			},
			static fn () => null
		);
	}

	private function make_product( string $sku ): int {
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		$product = new \WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'Tenant Inactive ' . $sku );
		$product->set_regular_price( '5.00' );
		$product->set_price( '5.00' );
		$product->set_stock_status( 'instock' );
		$id = (int) $product->save();

		$this->created_products[] = $id;

		return $id;
	}

	/**
	 * Put exactly the given products in the queue. Saving a product also fires
	 * the live catalog hook (which enqueues on its own while connected), so
	 * the table is truncated first — the point of these tests is what the
	 * flusher does with a known set of rows, not how many the hook made.
	 *
	 * @param array<int, int> $product_ids
	 */
	private function seed_queue( IngestQueue $queue, array $product_ids ): void {
		global $wpdb;
		$table = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		foreach ( $product_ids as $product_id ) {
			$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $product_id, array() );
		}
	}
}
