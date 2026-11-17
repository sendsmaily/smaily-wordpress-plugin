<?php
/**
 * Integration: the connection's code prefix (contract v1.33.0, CON-323)
 * against real WooCommerce products and orders and the mock engine, which
 * refuses a key outside the calling connection the way the engine does
 * (`connection_prefix_mismatch`).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class RecEngineConnectionPrefixTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> */
	private array $created_products = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			self::markTestSkipped( 'WooCommerce / Action Scheduler not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		CatalogHookHandler::reset_seen();
		OrderHookHandler::reset_seen();
		$this->truncate_queue();
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );
		unset( $_COOKIE['smaily_anon_sid'] );
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_orders   = array();
		$this->created_products = array();
		// A connect through the setup-exchange route starts the imports.
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		foreach ( array( 'products', 'customers', 'orders' ) as $job_type ) {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array( 'job_type' => $job_type ), EventQueue::AS_GROUP );
		}
		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false );
		$this->truncate_queue();
		parent::tearDown();
	}

	/**
	 * §1: the exchange declares the capability and stores the prefix; a
	 * reconnect after Disconnect reports it as `previous_prefix`, and the
	 * engine (the mock plays its reuse rule) gives the same prefix back.
	 */
	public function test_a_reconnect_keeps_the_prefix_it_had(): void {
		self::$engine->set_setup_connection_prefix( 'c2-' );

		$this->connect_through_the_route( 'tok_prefix_first' );

		self::assertSame( 'c2-', ( new RecEngineSettings() )->connection_prefix() );
		$requests = self::$engine->setup_requests();
		self::assertSame( array( 'connection_prefix' ), $requests[0]['capabilities'] );
		self::assertNull( $requests[0]['connection'], 'A first connection sends no previous prefix.' );

		self::assertSame( 200, RestRequestHelper::post( '/rec-engine/disconnect' )->get_status() );
		self::assertSame( '', ( new RecEngineSettings() )->connection_prefix(), 'No connection, no prefix.' );

		// The engine would give a NEW connection c7-; the reconnect reports c2-.
		self::$engine->set_setup_connection_prefix( 'c7-' );
		$this->connect_through_the_route( 'tok_prefix_again' );

		$requests = self::$engine->setup_requests();
		self::assertSame( array( 'previous_prefix' => 'c2-' ), $requests[1]['connection'] );
		self::assertSame( 'c2-', ( new RecEngineSettings() )->connection_prefix() );
	}

	public function test_an_exchange_answering_a_malformed_prefix_stores_nothing(): void {
		self::$engine->set_prefix_answer( 'setup', 'C2' );

		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( 'tok_prefix_bad' ) )
		);

		self::assertSame( 502, $response->get_status() );
		self::assertFalse( ( new RecEngineSettings() )->is_connected() );
		self::assertFalse( get_option( RecEngineSettings::OPTION_API_KEY, false ) );
		self::assertFalse( get_option( RecEngineSettings::OPTION_CONNECTION_PREFIX, false ) );
	}

	/**
	 * Every key the connection sends starts with its prefix: catalog `sku` and
	 * `tags.product_id`, a trashed product's tombstone and a hard-deleted
	 * product's §3b id that were queued BEFORE the prefix (sent under it now,
	 * once), the order id and its lines, the §3c list and a browse `sku` from
	 * a page cached before the prefix. The mock refuses any key outside the
	 * connection, so "accepted" proves the prefix.
	 */
	public function test_every_key_the_connection_sends_carries_its_prefix(): void {
		$this->connect_with_mock_endpoints();
		$kept    = $this->product( 'kept' );
		$trashed = $this->product( 'trashed' );
		$deleted = $this->product( 'deleted' );
		$this->truncate_queue();
		CatalogHookHandler::reset_seen();

		// Queued without a prefix …
		wp_trash_post( $trashed );
		wp_delete_post( $deleted, true );
		( new IngestQueue() )->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $kept, array() );
		$stored = $this->stored_payload( CatalogHookHandler::EVENT_CATALOG_DELETE );
		self::assertSame( 'woo-' . $trashed, $stored['object']['sku'], 'Precondition: captured before the prefix.' );

		// … then the engine moves the connection to c2- and the ping says so.
		self::$engine->set_connection_prefix( 'c2-' );
		Bootstrap::instance()->rec_client()->ping();
		self::assertSame( 'c2-', ( new RecEngineSettings() )->connection_prefix() );

		$catalog = $this->flush_catalog();
		self::assertSame( 2, $catalog['sent'] );
		self::assertSame( 0, $catalog['failed'] );
		$state = self::$engine->state();
		self::assertEqualsCanonicalizing( array( 'c2-woo-' . $kept, 'c2-woo-' . $trashed ), $state['last_catalog_skus'] );
		self::assertSame( 'c2-' . $kept, $state['last_catalog_tags'][ 'c2-woo-' . $kept ]['product_id'] );
		self::assertSame( 'c2-' . $trashed, $state['last_catalog_tags'][ 'c2-woo-' . $trashed ]['product_id'] );

		$remove = $this->flush_catalog_remove();
		self::assertSame( 1, $remove['sent'] );
		self::assertSame( array( 'c2-' . $deleted ), self::$engine->state()['last_catalog_removed'] );

		$order_id = $this->order( $kept );
		$orders   = $this->flush_orders();
		self::assertSame( 1, $orders['sent'] );
		self::assertSame( 0, $orders['failed'] );
		$sent_order = self::$engine->state()['last_orders_payload'][0];
		self::assertSame( 'c2-' . $order_id, $sent_order['external_order_id'] );
		self::assertSame( 'c2-woo-' . $kept, $sent_order['items'][0]['sku'] );

		$this->truncate_queue();
		do_action( CatalogManifest::HOOK );
		$state = self::$engine->state();
		self::assertSame( 1, $state['manifest_count'] ?? 0, 'The list was accepted: no item outside the connection.' );
		foreach ( $state['last_manifest_items'] as $item ) {
			self::assertStringStartsWith( 'c2-woo-', $item['sku'] );
		}

		update_option( BeaconEndpoint::OPTION_TRACK_BROWSING, true );
		$relay = RestRequestHelper::post(
			'/relay',
			array(
				'events' => array(
					array( 'event_id' => 'pfx-pv-1', 'event_type' => 'product_view', 'sku' => 'woo-' . $kept, 'session_id' => 's1', 'event_ts' => '2026-11-17T10:00:00Z' ),
					array( 'event_id' => 'pfx-pv-2', 'event_type' => 'product_view', 'sku' => 'c2-woo-' . $kept, 'session_id' => 's1', 'event_ts' => '2026-11-17T10:00:01Z' ),
				),
			)
		);
		self::assertSame( 200, $relay->get_status() );
		self::assertSame( 2, $relay->get_data()['processed'] );
		self::assertSame( array( 'c2-woo-' . $kept, 'c2-woo-' . $kept ), array_column( self::$engine->state()['last_browse_events'], 'sku' ) );
	}

	/**
	 * Acceptance: two shops in one engine store, numbering products and
	 * orders the same way, never overwrite or delete each other's rows. The
	 * second shop is played by moving this site's key to c3- (its products
	 * and orders have the same ids as the first shop's — the collision the
	 * prefix exists for). What the mock models: separate rows per key, the
	 * §3c list and §3b scoped to the caller. What only the live engine can
	 * prove: its stored order rows, import errors and per-connection state.
	 */
	public function test_two_shops_in_one_store_keep_each_others_rows(): void {
		$this->connect_with_mock_endpoints();
		$ids = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$ids[] = $this->product( 'shop-' . $i );
		}
		$order_id = $this->order( $ids[0] );

		foreach ( array( 'c2-', 'c3-' ) as $prefix ) {
			self::$engine->set_connection_prefix( $prefix );
			Bootstrap::instance()->rec_client()->ping();
			$this->truncate_queue();
			foreach ( $ids as $id ) {
				( new IngestQueue() )->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $id, array() );
			}
			( new IngestQueue() )->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, (string) $order_id, array() );
			self::assertSame( 6, $this->flush_catalog()['sent'] );
			self::assertSame( 1, $this->flush_orders()['sent'] );
			self::assertSame( $prefix . $order_id, self::$engine->state()['last_orders_payload'][0]['external_order_id'] );
		}

		$state = self::$engine->state();
		foreach ( $ids as $id ) {
			self::assertArrayHasKey( 'c2-woo-' . $id, $state['catalog_in_stock_by_sku'], 'Shop 1\'s row survives shop 2\'s sync of the same id.' );
			self::assertArrayHasKey( 'c3-woo-' . $id, $state['catalog_in_stock_by_sku'] );
		}

		// Shop 2 loses a product; its nightly list leaves it out.
		wp_delete_post( $ids[0], true );
		$this->created_products = array_values( array_diff( $this->created_products, array( $ids[0] ) ) );
		$this->truncate_queue();
		do_action( CatalogManifest::HOOK );

		$tombstoned = self::$engine->state()['catalog_tombstoned'] ?? array();
		self::assertArrayHasKey( 'c3-woo-' . $ids[0], $tombstoned, 'Shop 2\'s own missing product is removed.' );
		foreach ( $ids as $id ) {
			self::assertArrayNotHasKey( 'c2-woo-' . $id, $tombstoned, 'Shop 2\'s list never removes shop 1\'s products.' );
		}

		// Shop 2 cannot remove or list shop 1's products, not even by sending its keys.
		$client = Bootstrap::instance()->rec_client( 1 );
		$answer = $client->catalog_remove( array( 'c2-' . $ids[1] ) );
		self::assertSame( 'connection_prefix_mismatch', $answer['errors'][0]['error'] );
		self::assertArrayNotHasKey( 'c2-woo-' . $ids[1], self::$engine->state()['catalog_tombstoned'] ?? array() );
		try {
			$client->catalog_manifest( array( array( 'sku' => 'c2-woo-' . $ids[1], 'in_stock' => false ) ) );
			self::fail( 'A list holding another connection\'s product is refused as a whole.' );
		} catch ( ApiException $e ) {
			self::assertSame( 400, $e->getCode() );
			self::assertSame( 'connection_prefix_mismatch', $e->error_code() );
		}
	}

	// --- helpers -----------------------------------------------------------

	private function connect_through_the_route( string $token ): void {
		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $response->get_status() );
	}

	private function connect_with_mock_endpoints(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_ping'             => $base . '/api/v1/ingest/ping',
					'ingest_catalog'          => $base . '/api/v1/ingest/catalog',
					'ingest_catalog_remove'   => $base . '/api/v1/ingest/catalog/remove',
					'ingest_catalog_manifest' => $base . '/api/v1/ingest/catalog/manifest',
					'ingest_orders'           => $base . '/api/v1/ingest/orders',
					'ingest_browse'           => $base . '/api/v1/ingest/browse',
				),
			)
		);
	}

	private function product( string $name ): int {
		$cat = term_exists( 'rec-prefix-cat', 'product_cat' );
		if ( ! $cat ) {
			$cat = wp_insert_term( 'Rec Prefix Cat', 'product_cat', array( 'slug' => 'rec-prefix-cat' ) );
		}
		$product = new \WC_Product_Simple();
		$product->set_name( 'Prefix ' . $name );
		$product->set_regular_price( '9.00' );
		$product->set_stock_status( 'instock' );
		$product->set_category_ids( array( (int) ( is_array( $cat ) ? $cat['term_id'] : $cat ) ) );
		$id                       = (int) $product->save();
		$this->created_products[] = $id;
		return $id;
	}

	private function order( int $product_id ): int {
		$order = wc_create_order();
		$order->set_billing_email( 'prefix-buyer@example.test' );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order_id               = (int) $order->save();
		$this->created_orders[] = $order_id;
		return $order_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored_payload( string $event_type ): array {
		$rows = ( new IngestQueue() )->pending( 10, array( $event_type ) );
		self::assertCount( 1, $rows );
		return (array) json_decode( (string) $rows[0]['payload'], true );
	}

	/**
	 * @return array<string, int>
	 */
	private function flush_catalog(): array {
		$settings = new RecEngineSettings();
		return ( new IngestFlusher( new IngestQueue(), new CatalogPayloadBuilder(), $settings, $this->client_factory( $settings ) ) )->flush();
	}

	/**
	 * @return array<string, int>
	 */
	private function flush_catalog_remove(): array {
		$settings = new RecEngineSettings();
		return ( new CatalogRemoveFlusher( new IngestQueue(), $settings, $this->client_factory( $settings ) ) )->flush();
	}

	/**
	 * @return array<string, int>
	 */
	private function flush_orders(): array {
		$settings = new RecEngineSettings();
		return ( new OrderFlusher( new IngestQueue(), new OrderPayloadBuilder(), $settings, $this->client_factory( $settings ) ) )->flush();
	}

	private function client_factory( RecEngineSettings $settings ): callable {
		return static function () use ( $settings ): Client {
			return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
		};
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}
}
