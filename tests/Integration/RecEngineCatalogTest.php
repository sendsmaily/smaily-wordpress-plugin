<?php
/**
 * Integration: Client::ingest_catalog against the mock engine — wire
 * format, event_id read/write symmetry, deduplicated-response handling,
 * and the retry policy (429 / 5xx transient, 4xx terminal).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that the unit tests can't:
 *
 *   - The full chain CatalogPayloadBuilder → Client → real HTTP → engine.
 *     A doubled request_url() can't catch header serialisation, status
 *     parsing, or the endpoints-map URL actually resolving to a live
 *     route.
 *
 *   - Read/write symmetry on the WIRE: the event_uuid written to the
 *     queue must arrive at the engine as products[].event_id, byte for
 *     byte. The mock records what it received; the test asserts it equals
 *     the queue row's uuid.
 *
 *   - The deduplicated short-circuit: a resent event_id must come back
 *     200 {"deduplicated": true} and be treated as success (the flush job
 *     marks the row sent, never retries — the most likely future
 *     regression).
 *
 *   - The retry policy end-to-end: 429 + Retry-After and 5xx are retried
 *     to success; a 4xx (revoked key) throws ApiException with no retry.
 *
 * Also exercises EnvSeed (deferred from 3.2.0) — it points a "connected"
 * tenant at the mock so no setup-exchange is needed; the mock accepts any
 * sk_* bearer.
 */
final class RecEngineCatalogTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> Product ids created by a test, torn down after. */
	private array $created_products = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — catalog ingest needs WC_Product.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_products = array();
		parent::tearDown();
	}

	public function test_catalog_success_and_event_id_matches_queue_uuid(): void {
		$client = $this->connected_client();

		// A real queue row: its event_uuid is the idempotency key that must
		// travel to the engine unchanged as products[].event_id.
		$queue = new IngestQueue();
		$queue->enqueue( 'catalog.upsert', '0', array( 'sku' => 'CAT-SYM-1' ) );
		$row  = $queue->pending( 1 )[0];
		$uuid = (string) $row['event_uuid'];

		$product = $this->make_product( 'CAT-SYM-1', '22.99' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, $uuid );

		$result = $client->ingest_catalog( array( $payload ) );

		self::assertTrue( (bool) ( $result['ok'] ?? false ), 'Catalog ingest should succeed (200 ok).' );
		self::assertSame( 1, $result['processed'] ?? null, 'Catalog is D6 now — one product processed.' );

		$received = self::$engine->state()['last_catalog_received'] ?? null;
		self::assertSame(
			array( $uuid ),
			$received,
			'Read/write symmetry: the engine must receive products[].event_id == queue.event_uuid.'
		);
	}

	public function test_resent_event_id_returns_deduplicated_and_is_not_an_error(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'CAT-DUP-1', '10.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'dup-event-uuid-1234' );

		$first = $client->ingest_catalog( array( $payload ) );
		self::assertTrue( (bool) ( $first['ok'] ?? false ) );
		self::assertSame( 1, $first['processed'] ?? null, 'First send is a fresh process.' );
		self::assertSame( 0, $first['deduplicated'] ?? null, 'Nothing deduplicated on the first send.' );

		// Identical event_id resent (what a flush-job retry does).
		$second = $client->ingest_catalog( array( $payload ) );
		self::assertSame(
			1,
			$second['deduplicated'] ?? null,
			'A resent event_id is counted in deduplicated (D6 integer count) — the flush job marks the row sent, NOT a retry.'
		);
		self::assertTrue( (bool) ( $second['deduplicated_all'] ?? false ), 'A pure no-op retry flags deduplicated_all.' );
	}

	public function test_transient_429_then_retry_succeeds(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'CAT-429', '5.00' );
		// Scenario trigger keys on the event_id prefix (the wire sku is now the
		// woo-<id> platform key, PRO-1224 — not test-controllable).
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'retry-429-cat' );

		$result = $client->ingest_catalog( array( $payload ) );

		self::assertTrue(
			(bool) ( $result['ok'] ?? false ),
			'Client must honour Retry-After on 429, back off, retry, and ultimately succeed.'
		);
	}

	public function test_transient_500_then_retry_succeeds(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'CAT-500', '5.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'retry-500-cat' );

		$result = $client->ingest_catalog( array( $payload ) );

		self::assertTrue(
			(bool) ( $result['ok'] ?? false ),
			'Client must back off on 5xx, retry, and ultimately succeed.'
		);
	}

	public function test_revoked_key_401_throws_without_retry(): void {
		$client  = $this->connected_client();
		$product = $this->make_product( 'CAT-401', '1.00' );
		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'auth-401-cat' );

		try {
			$client->ingest_catalog( array( $payload ) );
			self::fail( 'A 401 (revoked key) must throw ApiException, not return.' );
		} catch ( ApiException $e ) {
			self::assertSame( 401, $e->getCode(), 'ApiException carries the HTTP status as its code.' );
			self::assertSame( 'api_key_revoked', $e->error_code() );
		}
	}

	public function test_product_save_through_flusher_reaches_engine_end_to_end(): void {
		// The whole 3.2.3 chain with NO doubles: a real product save enqueues
		// a real row, the real flusher drains it to the mock engine.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder();

		$product    = $this->make_product( 'CAT-E2E-1', '9.99' );
		$product_id = (int) $product->get_id();

		// Hook layer.
		( new CatalogHookHandler( $queue, $builder, $settings, new SiteLocaleAdapter() ) )->on_save_product( $product_id );

		$pending = $queue->pending( 10 );
		self::assertCount( 1, $pending, 'save_post_product must enqueue exactly one catalog row.' );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $pending[0]['event_type'] );
		$uuid = (string) $pending[0]['event_uuid'];

		// Flush layer.
		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( array(), $queue->pending( 10 ), 'The row is sent and no longer pending.' );

		$received = self::$engine->state()['last_catalog_received'] ?? null;
		self::assertSame(
			array( $uuid ),
			$received,
			'End-to-end: the engine received products[].event_id == the queue row event_uuid.'
		);
	}

	public function test_multilingual_name_is_sent_as_a_lang_value_object(): void {
		// CC.3: with a multilingual detector, name/description/product_url go on
		// the wire as `{lang: value}` objects. Proves the object form survives
		// the real JSON round-trip to the engine (the mock accepts both forms;
		// this asserts which one was sent).
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnArgument( 0 );
		$detector->method( 'get_translations' )->willReturn(
			array(
				'name'        => array( 'et' => 'Eesti nimi', 'en' => 'English name' ),
				'description' => array( 'et' => 'Eesti kirjeldus', 'en' => 'English description' ),
				'product_url' => array( 'et' => 'https://shop.test/et/p', 'en' => 'https://shop.test/en/p' ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder( $detector );
		$product  = $this->make_product( 'CAT-ML-1', '9.99' );

		// Creating the product fired the live hook (connected) and enqueued a
		// row via Bootstrap's builder — clear it so only this test's row (built
		// with the multilingual detector) reaches the engine.
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );

		$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $product->get_id(), array() );

		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		self::assertSame( 1, $flusher->flush()['sent'] );

		$names = self::$engine->state()['last_catalog_names'] ?? array();
		self::assertSame(
			array( array( 'et' => 'Eesti nimi', 'en' => 'English name' ) ),
			$names,
			'name reached the engine as a {lang:value} object, not a flattened string.'
		);
	}

	public function test_catalog_d6_partial_success_marks_errored_product_failed(): void {
		// N-7 lock fix: catalog is D6 now. A batch with one rejected product
		// (mock `d6err` EVENT_ID trigger — the wire sku is the woo-<id> platform
		// key now, PRO-1224, so the bad row is enqueued with a known event_uuid)
		// must mark exactly that row FAILED and the rest sent — before N-7 the
		// catalog flusher marked the whole batch sent on any 2xx, silently losing
		// the rejected product.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder();

		CatalogHookHandler::reset_seen();
		RecEngineMockServer::reset();
		$good = $this->make_product( 'CAT-D6-OK', '9.99' );
		$bad  = $this->make_product( 'CAT-D6-BAD', '9.99' );
		// Deterministic: drop any live-save-hook rows, then enqueue exactly the two
		// rows under test with KNOWN event_uuids. The wire sku is now the woo-<id>
		// platform key (PRO-1224), so the mock's per-item error trigger keys on the
		// event_id — `d6err-cat-bad` flags exactly the bad row (the flusher rebuilds
		// each payload from its product at flush time, carrying the event_uuid
		// through as event_id).
		$this->truncate_queue();
		$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $good->get_id(), array(), 'ok-cat-good' );
		$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $bad->get_id(), array(), 'd6err-cat-bad' );

		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'], 'The valid product is processed → sent.' );
		self::assertSame( 1, $stats['failed'], 'The D6ERR product is marked failed — not silently sent (the lock fix).' );
		self::assertSame( array(), $queue->pending( 10 ), 'Both rows reached a terminal state.' );
	}

	public function test_uncategorized_product_upsert_uses_store_default_category_and_is_sent(): void {
		// F3-39 REVISION (PRO-1491, 2026-07-21): a PUBLISHED product with
		// literally NO product_cat term (not even "Uncategorized" — that
		// would yield a non-empty "uncategorized" path) used to build an
		// empty category_path that the engine's REQUIRED, non-empty Zod
		// field rejected per-item — exactly the gap MiuMjau hit 253 times
		// (`d6_item_error field=category_path`), silently excluding real
		// published products from the catalog. CatalogPayloadBuilder now
		// falls back to the store's OWN default_product_cat term name
		// (WooCommerce's real "uncategorized" semantics, resolved at build
		// time — the wp-env test site's default is "Uncategorized"), so the
		// row is a genuine catalog entry, not a rejection.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder();

		CatalogHookHandler::reset_seen();
		RecEngineMockServer::reset();
		$bare = $this->make_uncategorized_product( 'CAT-NOCAT-1', '9.99' );
		self::assertSame(
			'Uncategorized',
			$builder->primary_category_path( $bare ),
			'Sanity: the fixture truly has no category term, but the store default term name resolves.'
		);

		$this->truncate_queue();
		$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $bare->get_id(), array(), 'ok-cat-nocat' );

		$flusher = new IngestFlusher(
			$queue,
			$builder,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'], 'The default-category fallback makes the row a valid catalog entry — sent, not rejected.' );
		self::assertSame( 0, $stats['failed'] );

		// PRO-1499: the substituted category_path is flagged on the wire so
		// the engine skips category-slug-keyed derivation for this row.
		$tags = self::$engine->state()['last_catalog_tags'] ?? array();
		self::assertSame(
			'true',
			$tags[ 'woo-' . $bare->get_id() ]['category_defaulted'] ?? null,
			'tags.category_defaulted reached the engine for the store-default-fallback row.'
		);
	}

	public function test_uncategorized_product_upsert_is_rejected_when_store_default_category_is_unresolvable(): void {
		// The fail-loud path F3-39 originally established is NOT removed —
		// it moves one level down: if a store's OWN default_product_cat
		// option is itself broken (points at a deleted/never-existing term),
		// there is no WooCommerce semantics left to borrow, and the
		// connector still does not invent a value. The engine's
		// REQUIRED-field rejection surfaces that genuinely broken state.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();
		$builder  = new CatalogPayloadBuilder();

		CatalogHookHandler::reset_seen();
		RecEngineMockServer::reset();
		$bare = $this->make_uncategorized_product( 'CAT-NOCAT-BROKEN-1', '9.99' );

		$original_default = get_option( 'default_product_cat' );
		update_option( 'default_product_cat', 999999999 ); // a term_id that cannot exist.
		try {
			self::assertSame(
				'',
				$builder->primary_category_path( $bare ),
				'An unresolvable store default falls back to the bare empty string, never an invented value.'
			);

			$this->truncate_queue();
			$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $bare->get_id(), array(), 'd6err-cat-broken-default' );

			$flusher = new IngestFlusher(
				$queue,
				$builder,
				$settings,
				static function () use ( $settings ): Client {
					return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
				}
			);
			$stats = $flusher->flush();

			self::assertSame( 0, $stats['sent'], 'An empty category_path never reaches "sent" — the engine rejects it.' );
			self::assertSame( 1, $stats['failed'], 'The row is marked failed, not silently dropped nor silently sent.' );
		} finally {
			update_option( 'default_product_cat', $original_default );
		}
	}

	public function test_uncategorized_product_removal_is_force_filled_and_sent_not_dropped(): void {
		// PRO-1498: unlike the upsert path above (which stays fail-loud on a
		// genuinely broken store), a catalog.delete tombstone must always
		// reach the engine — it has no delete-by-key, so a skipped/rejected
		// removal would leave a synced product stuck in_stock=true forever.
		// Reproduce the SAME genuinely-broken-store shape (no product_cat
		// term, unresolvable store default) via trash — on_delete_product's
		// enqueue_delete()/ensure_valid_removal() is the exact same code path
		// a hard-deleted variation's soft removal also runs, and trash is the
		// reliable fixture (a real WC_Product::save() self-heals onto the
		// default term, per make_uncategorized_product()'s docblock).
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		CatalogHookHandler::reset_seen();
		RecEngineMockServer::reset();
		$bare = $this->make_uncategorized_product( 'CAT-REMOVE-NOCAT-1', '9.99' );
		$pid  = (int) $bare->get_id();

		$original_default = get_option( 'default_product_cat' );
		update_option( 'default_product_cat', 999999999 ); // Unresolvable — a genuinely broken store.
		try {
			$queue = new IngestQueue();
			$this->truncate_queue();

			wp_trash_post( $pid ); // Live hook → catalog.delete, force-filled by ensure_valid_removal().

			$delete_rows = $queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) );
			self::assertCount( 1, $delete_rows, 'The removal is always enqueued — never silently dropped.' );
			$captured = json_decode( (string) $delete_rows[0]['payload'], true );
			self::assertSame(
				'uncategorized',
				$captured['object']['category_path'],
				'Force-filled with the last-resort placeholder — the store default is itself unresolvable.'
			);
			// PRO-1499: the force-fill IS the substitution the flag marks.
			self::assertSame( 'true', $captured['object']['tags']['category_defaulted'] );

			$settings = new RecEngineSettings();
			$flusher  = new IngestFlusher(
				$queue,
				new CatalogPayloadBuilder(),
				$settings,
				static function () use ( $settings ): Client {
					return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
				}
			);
			$stats = $flusher->flush();

			self::assertSame( 1, $stats['sent'], 'The force-filled removal reaches the engine — sent, not rejected nor dropped.' );
			self::assertSame( 0, $stats['failed'] );

			// PRO-1499: the flag reached the wire too, not just the captured object.
			$tags = self::$engine->state()['last_catalog_tags'] ?? array();
			self::assertSame(
				'true',
				$tags[ 'woo-' . $pid ]['category_defaulted'] ?? null,
				'tags.category_defaulted reached the engine for the force-filled removal.'
			);
		} finally {
			update_option( 'default_product_cat', $original_default );
		}
	}

	public function test_pre_3_8_1_stored_blank_delete_row_is_repaired_and_sent_on_retry(): void {
		// PRO-1506: PRO-1498's ensure_valid_removal() force-fill ran only at
		// ENQUEUE time, so a row captured BEFORE that fix shipped kept its
		// stored blank category_path/product_url verbatim — a merchant
		// Retrying it from the Event Log resent the identical blank the
		// engine already rejected, forever. Simulate that OLD (pre-3.8.1)
		// stored shape directly (bypassing enqueue_delete()) and prove the
		// flusher itself repairs it now.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		$queue    = new IngestQueue();

		RecEngineMockServer::reset();
		$this->truncate_queue();
		$queue->enqueue(
			CatalogHookHandler::EVENT_CATALOG_DELETE,
			'999998',
			array(
				'object' => array(
					'sku'           => 'woo-999998',
					'name'          => 'Legacy Stuck Removal',
					'category_path' => '',
					'price'         => 1.0,
					'in_stock'      => true,
					'product_url'   => '',
					'external_id'   => '999998',
				),
			)
		);

		$flusher = new IngestFlusher(
			$queue,
			new CatalogPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'], 'A retried pre-3.8.1 stuck row is repaired and reaches the engine — sent, not rejected.' );
		self::assertSame( 0, $stats['failed'] );

		$tags = self::$engine->state()['last_catalog_tags'] ?? array();
		self::assertSame(
			'true',
			$tags['woo-999998']['category_defaulted'] ?? null,
			'The flush-time force-fill is flagged per PRO-1499, same as the enqueue-time one.'
		);
	}

	public function test_mock_rejects_empty_product_url_on_a_delete_row_like_the_live_engine(): void {
		// PRO-1498, folds in PRO-1492: the mock must reject an empty product_url
		// with the same d6_item_error shape the real engine returns — mirrors the
		// existing category_path check (PRO-1491/e98e092). PRO-1506 made
		// IngestFlusher itself repair a stored catalog.delete row's blank
		// product_url before sending (ensure_valid_removal() at flush time), so
		// that path can no longer reach the mock with a blank value — this test
		// now posts directly through the Client to keep proving mock/live
		// parity on the raw wire shape, independent of the plugin's own
		// force-fill.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();

		RecEngineMockServer::reset();
		$client = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );

		$response = $client->ingest_catalog(
			array(
				array(
					'event_id'      => 'blank-url-test',
					'sku'           => 'woo-999999',
					'name'          => 'Blank URL Test',
					'category_path' => 'food/dry',
					'price'         => 1.0,
					'in_stock'      => false,
					'product_url'   => '',
				),
			)
		);

		self::assertSame( 0, $response['processed'] ?? null, 'An empty product_url is never processed — the mock rejects it like the live engine.' );
		self::assertCount( 1, $response['errors'] ?? array() );
		self::assertSame( 'product_url', $response['errors'][0]['field'] ?? null );
	}

	public function test_trash_keeps_product_in_stock_false_and_untrash_restores(): void {
		// Trashing is NOT a delete (before_delete_post never fires for it), so
		// Bootstrap routes wp_trash_post → on_delete_product: the product stays in
		// the engine catalog as an in_stock=false UPSERT (kept for the order-history
		// join), not dropped. Untrash re-syncs it back to in_stock=true.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		self::assertNotFalse( has_action( 'wp_trash_post' ), 'Bootstrap must route product trashing through the catalog handler.' );
		self::assertNotFalse( has_action( 'untrashed_post' ), 'Bootstrap must re-sync a product on untrash.' );

		// A category is required for the removal (engine needs category_path).
		$product = $this->make_categorized_product( 'CAT-TRASH-1', '7.50' );
		$pid     = (int) $product->get_id();

		CatalogHookHandler::reset_seen();
		$this->truncate_queue(); // ignore the create-time save-hook row.

		// --- Trash → catalog.delete (flusher stamps in_stock=false) ---
		wp_trash_post( $pid );
		self::assertSame( 'trash', get_post_status( $pid ), 'Precondition: the product is trashed, not permanently deleted.' );

		$queue = new IngestQueue();
		self::assertCount(
			1,
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) ),
			'Trashing enqueues a catalog.delete (kept as in_stock=false), not nothing.'
		);

		$this->flush_catalog( $queue );
		$in_stock = self::$engine->state()['last_catalog_in_stock'] ?? array();
		// Keyed by the wire sku woo-<id>, not the merchant SKU (PRO-1224).
		self::assertFalse( $in_stock[ 'woo-' . $pid ] ?? null, 'A trashed product reaches the engine in_stock=false — kept for the join, not recommended.' );

		// --- Untrash → catalog.upsert (real stock = in_stock=true) ---
		RecEngineMockServer::reset();
		CatalogHookHandler::reset_seen();
		$this->truncate_queue();

		wp_untrash_post( $pid );
		// Some WP versions restore an untrashed post to 'draft'; force publish so
		// is_in_stock() reflects a live product (status doesn't gate in_stock, but
		// keep the fixture realistic).
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );

		self::assertNotEmpty(
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) ),
			'Untrashing re-syncs the product as a catalog.upsert.'
		);

		$this->flush_catalog( $queue );
		$in_stock = self::$engine->state()['last_catalog_in_stock'] ?? array();
		self::assertTrue( $in_stock[ 'woo-' . $pid ] ?? null, 'Untrash restores in_stock=true — the product is sellable again.' );
	}

	public function test_hard_delete_reaches_engine_as_catalog_remove_with_raw_parent_id(): void {
		// PRO-1230 end-to-end through the REAL Bootstrap wiring: a permanent
		// delete fires before_delete_post → on_hard_delete_product → ONE
		// catalog.remove row (no per-SKU catalog.delete) → CatalogRemoveFlusher
		// POSTs §3b {product_ids:[<RAW parent id>]} → the mock tombstones the
		// product's ingested SKUs (matched on tags.product_id, exact string).
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		self::assertNotFalse( has_action( 'before_delete_post' ), 'Bootstrap must route permanent deletes through the catalog handler.' );

		// Ingest the product first so the mock's catalog carries its
		// tags.product_id — removal must then find it (not not_found).
		$product = $this->make_categorized_product( 'CAT-REMOVE-1', '12.00' );
		$pid     = (int) $product->get_id();
		$this->truncate_queue();
		$queue = new IngestQueue();
		$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $pid, array() );
		$this->flush_catalog( $queue );
		$tags = self::$engine->state()['last_catalog_tags'] ?? array();
		self::assertSame( (string) $pid, $tags[ 'woo-' . $pid ]['product_id'] ?? null, 'Precondition: the ingested row carries tags.product_id (PRO-1224).' );

		// --- Hard delete (no trash) → catalog.remove, and ONLY that ---
		CatalogHookHandler::reset_seen();
		$this->truncate_queue();
		wp_delete_post( $pid, true );

		$remove_rows = $queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) );
		self::assertCount( 1, $remove_rows, 'A parent-product hard-delete enqueues exactly one catalog.remove row.' );
		$payload = json_decode( (string) $remove_rows[0]['payload'], true );
		self::assertSame(
			array( 'product_id' => (string) $pid ),
			$payload,
			'The removal key is the RAW un-prefixed parent id (= tags.product_id) — not woo-<id>, not the merchant SKU.'
		);
		self::assertSame(
			array(),
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) ),
			'No per-SKU catalog.delete rows ride along — §3b already tombstones every SKU of the product.'
		);

		// --- Flush → §3b on the wire, tombstone applied ---
		$stats = $this->flush_catalog_remove( $queue );
		self::assertSame( 1, $stats['sent'] );
		self::assertSame( array(), $queue->pending( 10 ), 'The remove row reached a terminal state.' );

		$state = self::$engine->state();
		self::assertSame( array( (string) $pid ), $state['last_catalog_removed'] ?? null, 'The engine received product_ids = [<raw parent id>].' );
		self::assertArrayHasKey( 'woo-' . $pid, $state['catalog_tombstoned'] ?? array(), 'The ingested SKU was tombstoned via its tags.product_id — not not_found.' );
	}

	public function test_trash_enqueues_no_catalog_remove_but_purge_from_trash_does(): void {
		// F3-40 stays untouched: trashing keeps the in_stock=false soft path
		// and NEVER fires §3b. Purging the trashed product (empty trash) DOES
		// fire before_delete_post — that is the intended §3b moment too.
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$product = $this->make_categorized_product( 'CAT-REMOVE-TRASH-1', '5.00' );
		$pid     = (int) $product->get_id();

		CatalogHookHandler::reset_seen();
		$this->truncate_queue();
		$queue = new IngestQueue();

		wp_trash_post( $pid );
		self::assertCount(
			1,
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) ),
			'Trash keeps the F3-40 soft path (in_stock=false).'
		);
		self::assertSame(
			array(),
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) ),
			'Trash is NOT a hard delete — no §3b removal.'
		);

		wp_delete_post( $pid, true ); // Empty-trash purge.
		$remove_rows = $queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) );
		self::assertCount( 1, $remove_rows, 'Purging from trash fires before_delete_post → the §3b removal.' );
		self::assertSame(
			array( 'product_id' => (string) $pid ),
			json_decode( (string) $remove_rows[0]['payload'], true )
		);
	}

	public function test_variation_hard_delete_keeps_per_sku_soft_path_not_product_remove(): void {
		// §3b is PRODUCT-level: deleting ONE variation of a surviving product
		// must not tombstone its siblings — it keeps the per-variation
		// in_stock=false path (the variation is its own ingest unit).
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Remove Var Parent' );
		$parent_id                = (int) $parent->save();
		$this->created_products[] = $parent_id;
		// Category on the parent → the variation's captured removal object
		// carries a non-empty category_path (a normal, real-world shape).
		$cat     = term_exists( 'rec-trash-cat', 'product_cat' );
		$cat     = $cat ? $cat : wp_insert_term( 'Rec Trash Cat', 'product_cat', array( 'slug' => 'rec-trash-cat' ) );
		$term_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );
		wp_set_object_terms( $parent_id, array( $term_id ), 'product_cat' );

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '4.00' );
		$variation_id = (int) $variation->save();

		CatalogHookHandler::reset_seen();
		$this->truncate_queue();
		$queue = new IngestQueue();

		wc_get_product( $variation_id )->delete( true );

		self::assertSame(
			array(),
			$queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) ),
			'A single variation delete must NOT tombstone the whole product family via §3b.'
		);
		$delete_rows = $queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) );
		$entity_ids  = array_map( static fn( array $row ): string => (string) $row['entity_id'], $delete_rows );
		self::assertContains( (string) $variation_id, $entity_ids, 'The variation keeps the per-SKU in_stock=false soft path.' );
	}

	public function test_variable_parent_hard_delete_enqueues_one_remove_for_the_family(): void {
		// Deleting the whole variable product → ONE §3b removal keyed on the
		// parent id; the per-variation soft-path rows fired by WC's cascade
		// delete collapse into it (pre-claimed dedupe slots).
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Remove Family Parent' );
		$parent_id = (int) $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '4.00' );
		$variation->save();

		CatalogHookHandler::reset_seen();
		$this->truncate_queue();
		$queue = new IngestQueue();

		wc_get_product( $parent_id )->delete( true ); // Cascades to the variations.

		$remove_rows = $queue->pending( 10, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) );
		self::assertCount( 1, $remove_rows, 'One product family → one catalog.remove, whatever the variation count.' );
		self::assertSame(
			array( 'product_id' => (string) $parent_id ),
			json_decode( (string) $remove_rows[0]['payload'], true ),
			'The family removal is keyed on the PARENT id — the tags.product_id every variation row shares.'
		);
	}

	/**
	 * Seed a connected tenant pointed at the mock and build a Client from
	 * the stored settings (api_key + base_url + endpoints map).
	 */
	private function connected_client(): Client {
		$base = (string) self::$engine->base_url();

		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => self::mock_endpoints( $base ),
			)
		);

		$settings = new RecEngineSettings();
		return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints() );
	}

	public function test_variation_stock_change_enqueues_catalog_upsert(): void {
		// Found 2026-06-12: variations fire woocommerce_variation_set_stock_status,
		// NOT the parent-product hook — only the parent hook was registered, so
		// a variation selling out never refreshed its catalog in_stock and the
		// engine kept recommending it. Assert the REAL registration (Bootstrap
		// wiring, not a hand-called handler) and the queue row it produces.
		$this->connected_client(); // seeds is_connected so the gate is open.

		self::assertNotFalse(
			has_action( 'woocommerce_variation_set_stock_status' ),
			'Bootstrap must register the catalog handler on the VARIATION stock hook.'
		);
		self::assertNotFalse( has_action( 'woocommerce_product_set_stock_status' ) );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Var Stock Parent' );
		$parent_id                = (int) $parent->save();
		$this->created_products[] = $parent_id;

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '4.00' );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
		$variation_id             = (int) $variation->save();
		$this->created_products[] = $variation_id;

		$queue  = new IngestQueue();
		$before = count( $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) ) );

		CatalogHookHandler::reset_seen();
		$loaded = wc_get_product( $variation_id );
		$loaded->set_stock_status( 'outofstock' );
		$loaded->save(); // fires woocommerce_variation_set_stock_status through real WC.

		$rows      = $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) );
		$entity_ids = array_map(
			static fn( array $row ): string => (string) $row['entity_id'],
			$rows
		);

		self::assertContains(
			(string) $variation_id,
			$entity_ids,
			'A variation stock flip must enqueue a catalog.upsert for THE VARIATION (the engine ingests variations as units).'
		);
		self::assertGreaterThan( $before, count( $rows ) );
	}

	public function test_taxonomy_attribute_wires_term_labels_not_ids(): void {
		// Engine ask 2026-06-12: a REAL WC taxonomy attribute's get_options()
		// returns term IDS (`pa_kaubamargid: ["398"]` was what the engine
		// received from the pilot) — the wire must carry term NAMES, or the
		// engine cannot derive brand / life_stage / pack_size rules. The unit
		// suite fakes the attribute object; only a real WC_Product_Attribute
		// exhibits the id behavior, hence this test (LESSONS §2.4).
		$attr_id = wc_create_attribute(
			array(
				'name'         => 'Testbrand',
				'slug'         => 'testbrand',
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		self::assertIsInt( $attr_id, 'Precondition: attribute taxonomy created.' );

		// WC registers attribute taxonomies on init — this request predates
		// the new one, so register it manually for the test's lifetime.
		register_taxonomy( 'pa_testbrand', array( 'product' ) );
		$term = wp_insert_term( 'Brit Care', 'pa_testbrand' );
		self::assertIsArray( $term );

		try {
			$product = $this->make_product( 'CAT-ATTR-1', '3.00' );
			$pid     = $product->get_id();
			wp_set_object_terms( $pid, array( (int) $term['term_id'] ), 'pa_testbrand' );

			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( (int) $attr_id );
			$attribute->set_name( 'pa_testbrand' );
			$attribute->set_options( array( (int) $term['term_id'] ) );
			$attribute->set_visible( true );
			$product->set_attributes( array( $attribute ) );
			$product->save();

			$payload = ( new CatalogPayloadBuilder() )->build( wc_get_product( $pid ), 'u-attr' );

			self::assertArrayHasKey( 'raw_attributes', $payload );
			self::assertSame(
				array( 'Brit Care' ),
				$payload['raw_attributes']['pa_testbrand'],
				'Wire must carry the term LABEL — a numeric term id here is the pilot bug regressing.'
			);
		} finally {
			wp_delete_term( (int) $term['term_id'], 'pa_testbrand' );
			wc_delete_attribute( (int) $attr_id );
			unregister_taxonomy( 'pa_testbrand' );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function mock_endpoints( string $base ): array {
		return array(
			'ingest_ping'      => $base . '/api/v1/ingest/ping',
			'ingest_catalog'   => $base . '/api/v1/ingest/catalog',
			'ingest_customers' => $base . '/api/v1/ingest/customers',
			'ingest_orders'    => $base . '/api/v1/ingest/orders',
		);
	}

	private function make_product( string $sku, string $price ): \WC_Product {
		// Idempotent across crashed runs that skipped tearDown: drop any
		// leftover product with this SKU before creating a fresh one.
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		$product = new \WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'Catalog Test ' . $sku );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$id = (int) $product->save();

		$this->created_products[] = $id;

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}

	/**
	 * A product with a real product_cat term so its catalog object carries a
	 * non-empty category_path — a normal, real-world removal shape (a
	 * genuinely blank category_path is now force-filled by
	 * CatalogPayloadBuilder::ensure_valid_removal(), PRO-1498, not skipped).
	 */
	private function make_categorized_product( string $sku, string $price ): \WC_Product {
		$cat = term_exists( 'rec-trash-cat', 'product_cat' );
		if ( ! $cat ) {
			$cat = wp_insert_term( 'Rec Trash Cat', 'product_cat', array( 'slug' => 'rec-trash-cat' ) );
		}
		$term_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );

		$product = $this->make_product( $sku, $price );
		wp_set_object_terms( (int) $product->get_id(), array( $term_id ), 'product_cat' );

		$loaded = wc_get_product( (int) $product->get_id() );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}

	/**
	 * PRO-1491: a product with NO product_cat term at all (not even
	 * "Uncategorized") — the real-world shape that produces an empty
	 * `category_path` and the engine's `d6_item_error field=category_path`.
	 *
	 * Bypasses `WC_Product::save()` on purpose: its data store
	 * (`WC_Product_Data_Store_CPT`) forces `default_product_cat` onto an
	 * empty category list, and `WC_Post_Data::force_default_term()` (hooked
	 * on the `set_object_terms` action) re-asserts it on ANY subsequent
	 * `wp_set_object_terms( …, array(), 'product_cat' )` clear attempt too —
	 * confirmed empirically (a `save()` + explicit clear still healed back to
	 * "uncategorized" here). The self-heal only runs INSIDE a
	 * `set_object_terms` call, so a raw `wp_insert_post()` + meta build that
	 * never touches the taxonomy at all reproduces the real shape: a bulk
	 * import / a WPML stand-in translation row whose `product_cat`
	 * relationship was simply never established.
	 */
	private function make_uncategorized_product( string $sku, string $price ): \WC_Product {
		$existing = wc_get_product_id_by_sku( $sku );
		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Catalog Test ' . $sku,
			)
		);
		self::assertGreaterThan( 0, $id );
		$this->created_products[] = $id;

		update_post_meta( $id, '_sku', $sku );
		update_post_meta( $id, '_regular_price', $price );
		update_post_meta( $id, '_price', $price );
		update_post_meta( $id, '_stock_status', 'instock' );
		update_post_meta( $id, '_manage_stock', 'no' );
		update_post_meta( $id, '_virtual', 'no' );
		update_post_meta( $id, '_downloadable', 'no' );

		$loaded = wc_get_product( $id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return $loaded;
	}

	/**
	 * Drain catalog.remove rows through the real §3b flusher against the mock.
	 *
	 * @return array{processed: int, sent: int, failed: int, retried: int, skipped: int}
	 */
	private function flush_catalog_remove( IngestQueue $queue ): array {
		$settings = new RecEngineSettings();
		$flusher  = new CatalogRemoveFlusher(
			$queue,
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		return $flusher->flush();
	}

	private function flush_catalog( IngestQueue $queue ): void {
		$settings = new RecEngineSettings();
		$flusher  = new IngestFlusher(
			$queue,
			new CatalogPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
		$flusher->flush();
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}
}
