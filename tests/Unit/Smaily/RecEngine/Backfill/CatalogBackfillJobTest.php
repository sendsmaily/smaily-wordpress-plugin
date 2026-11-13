<?php
/**
 * CatalogBackfillJob tests — the per-record enqueue branch: a PUBLISHED post
 * upserts (flusher loads fresh, real stock); a TRASHED post is kept as an
 * in_stock=false removal (catalog.delete with a captured object) so its
 * order-history join survives; the multilingual collapse holds on both, and
 * a removal is force-filled via CatalogPayloadBuilder::ensure_valid_removal()
 * so it always reaches the engine regardless of the source object's shape.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CatalogBackfillJobTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_published_product_enqueues_upsert(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-PUB' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'publish' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'Upsert payload is empty — the flusher loads fresh.' );
	}

	public function test_trashed_product_is_kept_as_catalog_delete_with_captured_object(): void {
		// A trashed product a customer once bought stays in the engine catalog as
		// in_stock=false (the flusher stamps it on the catalog.delete row at send
		// time) so the order-history join / training survives — never dropped.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-TRASH' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		$payload = $queue->enqueued[0]['payload'];
		self::assertArrayHasKey( 'object', $payload, 'A trashed post captures its object now (still loadable) — the flusher stamps in_stock=false.' );
		self::assertSame( 'SKU-TRASH', $payload['object']['sku'] );
	}

	public function test_trashed_product_with_blank_category_path_is_enqueued_with_fallback(): void {
		// PRO-1498: same fallback as the live delete hook — a removal object
		// must always reach the engine (it has no delete-by-key), so a blank
		// category_path is force-filled with a generic placeholder rather than
		// skipped.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-NOCAT', '', 'https://shop.test/p' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued, 'A removal is always enqueued, never silently dropped.' );
		self::assertSame( 'uncategorized', $queue->enqueued[0]['payload']['object']['category_path'] );
	}

	public function test_trashed_product_with_blank_product_url_is_enqueued_with_fallback(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'SKU-NOURL', 'food/dry', '' );
		$job     = $this->job( $queue, array( 100 => $product ), array( $product ), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( 'https://shop.test/?smaily_connect_removed_product=100', $queue->enqueued[0]['payload']['object']['product_url'] );
	}

	public function test_trashed_unresolvable_product_still_enqueues_minimal_tombstone(): void {
		// PRO-1498: the SQL cursor confirmed this id IS a product/trash row,
		// but wc_get_product() still failed (e.g. a since-deactivated
		// gift-card plugin's product_type) — it may already be synced in the
		// engine, so it still needs a tombstone built from the bare id.
		$queue = $this->fake_queue();
		$job   = $this->job( $queue, array(), array(), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		$object = $queue->enqueued[0]['payload']['object'];
		self::assertSame( 'woo-100', $object['sku'] );
		self::assertFalse( $object['in_stock'] );
	}

	public function test_published_unresolvable_product_enqueues_nothing(): void {
		// The publish-side load failure (CC.4's known gift-card-type gap) is a
		// separate, tracked upsert-side problem — out of scope here; only the
		// trashed branch gets the PRO-1498 tombstone fallback.
		$queue = $this->fake_queue();
		$job   = $this->job( $queue, array(), array(), array( 'status' => array( 100 => 'publish' ) ) );

		$job->run( 100 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_a_product_that_throws_while_it_loads_fails_alone_and_the_next_one_is_queued(): void {
		// CON-304: a product the import cannot load is queued already failed —
		// the error type and where it was thrown, never its text — and the
		// import goes on to the next product.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$queue = $this->fake_queue();
		$good  = $this->fake_product( 101, 'SKU-GOOD' );
		$job   = $this->job( $queue, array( 100 => new \RuntimeException( 'cannot read jane@example.com' ), 101 => $good ), array( $good ) );

		$job->run( 100 );
		$job->run( 101 );

		self::assertCount( 2, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'] );
		self::assertCount( 1, $queue->failed, 'Only the broken product fails.' );
		self::assertSame( 1, $queue->failed[0]['id'] );
		self::assertMatchesRegularExpression( '/^RuntimeException at CatalogBackfillJobTest\.php:\d+$/', $queue->failed[0]['error'] );
		self::assertSame( array( 1 => array( null, '{"outcome":"not_built"}' ) ), $queue->exchanges, 'Nothing was sent; the Event Log shows it was not built.' );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[1]['type'] );
		self::assertSame( '101', $queue->enqueued[1]['entity_id'], 'The next product is queued as usual.' );
	}

	public function test_a_trashed_product_that_throws_while_it_loads_fails_as_a_removal(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$queue = $this->fake_queue();
		$job   = $this->job( $queue, array( 100 => new \TypeError( 'bad' ) ), array(), array( 'status' => array( 100 => 'trash' ) ) );

		$job->run( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'No captured object: a Retry builds the tombstone from the id (PRO-1506).' );
		self::assertMatchesRegularExpression( '/^TypeError at CatalogBackfillJobTest\.php:\d+$/', $queue->failed[0]['error'] );
	}

	public function test_an_error_after_a_failed_database_query_is_thrown_on(): void {
		// PRO-3890 kept: the same error after a failed query is a database
		// error, not a broken product, so it stops the import.
		$GLOBALS['wpdb'] = (object) array( 'last_error' => "Table 'wp_postmeta' doesn't exist" );
		$queue           = $this->fake_queue();
		$job             = $this->job( $queue, array( 100 => new \RuntimeException( 'Invalid product.' ) ), array() );

		try {
			$job->run( 100 );
			self::fail( 'A database error must stop the import.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Invalid product.', $e->getMessage() );
		}

		self::assertSame( array(), $queue->enqueued, 'Nothing is queued for it.' );
		self::assertSame( array(), $queue->failed );
	}

	public function test_translation_of_published_canonical_is_collapsed_away(): void {
		// The collapse keys on a PUBLISHED canonical: post 200 → canonical 100,
		// which is itself enumerated-as-publish, so 200 is skipped (the canonical
		// enqueues itself on its own cursor step). Holds for trash too.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$job       = $this->job(
			$queue,
			array( 100 => $canonical ),
			array( $canonical ),
			array(
				'canonical'  => array( 200 => 100 ),
				'enumerated' => array( 100 ),
				'status'     => array( 200 => 'trash' ),
			)
		);

		$job->run( 200 );

		self::assertSame( array(), $queue->enqueued, 'A translation whose canonical is a published product is collapsed — no duplicate SKU.' );
	}

	public function test_variable_trashed_product_fans_out_each_variation_as_delete(): void {
		$queue  = $this->fake_queue();
		$parent = $this->fake_product( 50, '' ); // variable parent, skuless.
		$v1     = $this->fake_product( 101, 'V-1' );
		$v2     = $this->fake_product( 102, 'V-2' );
		$job    = $this->job( $queue, array( 50 => $parent ), array( $v1, $v2 ), array( 'status' => array( 50 => 'trash' ) ) );

		$job->run( 50 );

		self::assertCount( 2, $queue->enqueued, 'Each variation of a trashed variable product is kept as its own in_stock=false unit.' );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( '101', $queue->enqueued[0]['entity_id'] );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[1]['type'] );
		self::assertSame( '102', $queue->enqueued[1]['entity_id'] );
	}

	public function test_manifest_walk_flushes_the_runtime_cache_after_each_batch(): void {
		// PRO-3899: WordPress keeps every post it loads until the run ends, so
		// a walk over a 50,000-product catalog in one run can exhaust memory.
		// Each batch's posts are released before the next batch is read.
		$log = new \ArrayObject();
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_cache_supports' )->alias( static fn ( string $feature ): bool => $feature === 'flush_runtime' );
		Functions\expect( 'wp_cache_flush_runtime' )->twice()->andReturnUsing(
			static function () use ( $log ): bool {
				$log[] = 'flush';
				return true;
			}
		);
		Functions\expect( 'wp_cache_delete_multiple' )->never();

		$items = $this->manifest_job( $log )->manifest_items( 50000 );

		self::assertCount( 6, $items, 'Three parents, two variations each.' );
		self::assertSame( array( 'fetch', 'flush', 'fetch', 'flush' ), $log->getArrayCopy(), 'One flush after each batch, before the next one is read.' );
	}

	public function test_manifest_walk_without_runtime_flush_deletes_only_the_batch_posts(): void {
		// A drop-in without runtime-flush support: WordPress's fallback would
		// only report _doing_it_wrong, and a full flush would empty a shared
		// cache — so the batch's own posts and meta are deleted instead.
		$log = new \ArrayObject();
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\expect( 'wp_cache_flush_runtime' )->never();
		$deleted = array();
		Functions\expect( 'wp_cache_delete_multiple' )->times( 4 )->andReturnUsing(
			static function ( array $keys, string $group ) use ( &$deleted ): array {
				$deleted[] = array( $group, array_values( array_unique( $keys ) ) );
				return array();
			}
		);

		$this->manifest_job( $log )->manifest_items( 50000 );

		self::assertSame(
			array(
				array( 'posts', array( 1, 2, 11, 12 ) ),
				array( 'post_meta', array( 1, 2, 11, 12 ) ),
				array( 'posts', array( 3, 11, 12 ) ),
				array( 'post_meta', array( 3, 11, 12 ) ),
			),
			$deleted,
			'Each batch deletes its parents and their expanded variations — nothing else.'
		);
	}

	public function test_manifest_lists_a_product_that_throws_out_of_stock_and_the_rest_as_usual(): void {
		// CON-312: a product left out of the list would be tombstoned
		// (recommendable=false), so it is listed out of stock from its id.
		$items = $this->manifest_of(
			array(
				1 => $this->fake_product( 1, '' ),
				2 => new \RuntimeException( 'cannot read jane@example.com' ),
				3 => $this->fake_product( 3, '' ),
			)
		);

		self::assertSame(
			array(
				array(
					'sku'      => 'woo-1',
					'in_stock' => true,
				),
				array(
					'sku'      => 'woo-2',
					'in_stock' => false,
				),
				array(
					'sku'      => 'woo-3',
					'in_stock' => true,
				),
			),
			$items
		);
	}

	public function test_manifest_counts_products_and_the_ones_that_could_not_be_loaded(): void {
		// Products are parent posts, not skus: a variable product that throws
		// counts once however many variations it lists. A collapsed
		// translation is not counted; a variation that loads as nothing does
		// not make its product unloadable.
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );
		$job = $this->job(
			$this->fake_queue(),
			array(
				1 => $this->fake_product( 1, '' ),
				2 => new \TypeError( 'bad' ),
			),
			array(),
			array(
				'ids'        => array( 1, 2, 3, 4 ),
				'canonical'  => array( 4 => 1 ),
				'enumerated' => array( 1 ),
				'variations' => array( 2 => array( 21, 22, 23 ) ),
			)
		);

		$items = $job->manifest_items( 50000, $counts );

		self::assertCount( 5, $items, 'woo-1, three variations of 2, woo-3.' );
		self::assertSame(
			array(
				'products'   => 3,
				'unloadable' => 2,
			),
			$counts
		);
	}

	public function test_manifest_lists_a_published_product_that_loads_as_nothing_out_of_stock(): void {
		// The CC.4 gap: wc_get_product() returns false, nothing throws. The
		// import still sends nothing for it; the manifest no longer leaves it out.
		$items = $this->manifest_of( array( 1 => $this->fake_product( 1, '' ) ), array( 'ids' => array( 1, 2 ) ) );

		self::assertSame( array( true, false ), array_column( $items, 'in_stock' ) );
		self::assertSame( array( 'woo-1', 'woo-2' ), array_column( $items, 'sku' ) );
	}

	public function test_manifest_lists_the_variations_of_a_variable_product_that_throws_out_of_stock(): void {
		// A variable product sends its variations, not itself: the fallback
		// lists its published and private variations from the database.
		$items = $this->manifest_of(
			array(
				1 => $this->fake_product( 1, '' ),
				2 => new \TypeError( 'bad' ),
			),
			array( 'variations' => array( 2 => array( 21, 22 ) ) )
		);

		self::assertSame( array( 'woo-1', 'woo-21', 'woo-22' ), array_column( $items, 'sku' ), 'The variations are listed, not the parent.' );
		self::assertSame( array( true, false, false ), array_column( $items, 'in_stock' ) );
	}

	public function test_manifest_lists_a_variation_that_loads_as_nothing_out_of_stock(): void {
		$items = $this->manifest_of(
			array( 5 => $this->fake_product( 5, '' ) ),
			array(
				'units'         => array( $this->fake_product( 51, '' ) ),
				'missing_units' => array( 52 ),
			)
		);

		self::assertSame(
			array(
				array(
					'sku'      => 'woo-51',
					'in_stock' => true,
				),
				array(
					'sku'      => 'woo-52',
					'in_stock' => false,
				),
			),
			$items
		);
	}

	public function test_manifest_fails_when_the_detector_throws(): void {
		// Without the detector the canonical sku cannot be worked out, so the
		// list cannot hold the product: the night is not sent.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'translation table unreadable' );

		$this->manifest_of(
			array(
				1 => $this->fake_product( 1, '' ),
				2 => $this->fake_product( 2, '' ),
			),
			array( 'detector_throws' => array( 2 ) )
		);
	}

	public function test_manifest_fails_on_an_error_after_a_failed_database_query(): void {
		// CON-304's rule: a throw while $wpdb->last_error is set is a
		// database error, not a broken product.
		$GLOBALS['wpdb'] = (object) array( 'last_error' => "Table 'wp_postmeta' doesn't exist" );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid product.' );

		$this->manifest_of( array( 1 => new \RuntimeException( 'Invalid product.' ) ) );
	}

	public function test_manifest_fails_when_the_variations_of_a_product_that_throws_cannot_be_read(): void {
		// A list without them would tombstone the variations.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'A database read failed while a product that cannot be loaded was listed.' );

		$this->manifest_of(
			array( 2 => new \TypeError( 'bad' ) ),
			array(
				'variations'       => array( 2 => array( 21 ) ),
				'db_error_on_read' => true,
			)
		);
	}

	/**
	 * The debug line names the product and where the error was thrown, never
	 * its text (CON-301). Its own process: DebugLog writes only under
	 * WP_DEBUG, a constant that would stay on for the rest of the suite.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_manifest_logs_the_product_it_could_not_load_without_the_error_text(): void {
		define( 'WP_DEBUG', true );
		$log      = (string) tempnam( sys_get_temp_dir(), 'smly-con312-log' );
		$previous = (string) ini_set( 'error_log', $log );
		try {
			$this->manifest_of(
				array(
					2 => new \RuntimeException( 'cannot read jane@example.com' ),
					3 => $this->fake_product( 3, '' ),
				),
				array( 'ids' => array( 2, 3, 4 ) )
			);
			$written = (string) file_get_contents( $log );
		} finally {
			ini_set( 'error_log', $previous );
			unlink( $log );
		}

		self::assertMatchesRegularExpression( '/product 2 could not be loaded \(RuntimeException at CatalogBackfillJobTest\.php:\d+\); listed out of stock/', $written );
		self::assertStringContainsString( 'product 4 could not be loaded (it loaded as nothing); listed out of stock', $written );
		self::assertStringNotContainsString( 'jane@example.com', $written );
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * The manifest of the given parent posts, all published, in one batch.
	 *
	 * @param array<int, \WC_Product|\Throwable> $products get_product() lookup; a Throwable is thrown.
	 * @param array{ids?: array<int,int>, units?: array<int, \WC_Product>, missing_units?: int[], variations?: array<int, int[]>, detector_throws?: int[], db_error_on_read?: bool} $opts
	 * @return array<int, array{sku: string, in_stock: bool}>
	 */
	private function manifest_of( array $products, array $opts = array() ): array {
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );

		$opts['ids'] = $opts['ids'] ?? array_keys( $products );
		$units       = $opts['units'] ?? array();
		unset( $opts['units'] );

		return $this->job( $this->fake_queue(), $products, $units, $opts )->manifest_items( 50000 );
	}

	/**
	 * Three published variable products (ids 1-3, each expanding to variations
	 * 11 and 12) walked in batches of two.
	 */
	private function manifest_job( \ArrayObject $log ): CatalogBackfillJob {
		$products = array();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$products[ $id ] = $this->fake_product( $id, '' );
		}
		$units = array( $this->fake_product( 11, '' ), $this->fake_product( 12, '' ) );

		return $this->job(
			$this->fake_queue(),
			$products,
			$units,
			array(
				'ids'        => array( 1, 2, 3 ),
				'batch_size' => 2,
				'log'        => $log,
			)
		);
	}

	private function fake_queue(): IngestQueue {
		return new class() extends IngestQueue {
			/** @var array<int, array{type:string, entity_id:string, payload:array<string,mixed>}> */
			public array $enqueued = array();

			public function enqueue( string $event_type, string $entity_id, array $payload, ?string $event_uuid = null, ?string $flush_hook = null, ?string $flush_group = null ): ?int {
				$this->enqueued[] = array(
					'type'      => $event_type,
					'entity_id' => $entity_id,
					'payload'   => $payload,
				);
				return count( $this->enqueued );
			}

			/** @var array<int, array{id:int, error:string}> */
			public array $failed = array();

			/** @var array<int, array{0:string|null, 1:string|null}> */
			public array $exchanges = array();

			public function mark_failed( int $id, string $error ): void {
				$this->failed[] = array(
					'id'    => $id,
					'error' => $error,
				);
			}

			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( $sent_payload, $last_response );
			}
		};
	}

	/**
	 * @param array<int, \WC_Product> $units   builder->expand_by_id() result.
	 * @param int[]                   $missing Variation ids expand_by_id() reports as not loading.
	 */
	private function fake_builder( array $units, array $missing = array() ): CatalogPayloadBuilder {
		return new class( $units, $missing ) extends CatalogPayloadBuilder {
			/** @var array<int, \WC_Product|null> */
			private array $units = array();
			/**
			 * @param array<int, \WC_Product> $units
			 * @param int[]                   $missing
			 */
			public function __construct( array $units, array $missing ) {
				foreach ( $units as $unit ) {
					$this->units[ (int) $unit->get_id() ] = $unit;
				}
				foreach ( $missing as $id ) {
					$this->units[ $id ] = null;
				}
			}
			public function expand_by_id( \WC_Product $product ): array {
				// No units given: a simple product, its own unit.
				return $this->units === array() ? array( (int) $product->get_id() => $product ) : $this->units;
			}
			public function build( \WC_Product $product, string $event_uuid ): array {
				$object = array(
					'sku'         => (string) $product->get_sku(),
					'event_id'    => $event_uuid,
					'in_stock'    => true,
					'external_id' => (string) $product->get_id(),
				);
				// The real builder always carries category_path + product_url; the
				// removal fallback (ensure_valid_removal()) keys on them, so mirror
				// them here.
				if ( method_exists( $product, 'smly_category_path' ) ) {
					$object['category_path'] = $product->smly_category_path();
					$object['product_url']   = $product->smly_product_url();
				}
				return $object;
			}
			public function build_unresolvable( int $product_id, string $event_uuid ): array {
				return array(
					'event_id'      => $event_uuid,
					'sku'           => 'woo-' . $product_id,
					'name'          => 'Unavailable product #' . $product_id,
					'category_path' => 'uncategorized',
					'price'         => 0.0,
					'in_stock'      => false,
					'product_url'   => 'https://shop.test/?smaily_connect_removed_product=' . $product_id,
					'external_id'   => (string) $product_id,
				);
			}
			// Mirrors the real ensure_valid_removal() output shape without calling
			// the real home_url() — this raw (non-Brain\Monkey) test file has no
			// WordPress loaded; the real method's behaviour is covered directly in
			// CatalogPayloadBuilderTest.
			public function manifest_item( \WC_Product $unit, bool $trashed ): array {
				return array(
					'sku'      => 'woo-' . $unit->get_id(),
					'in_stock' => ! $trashed,
				);
			}
			public function manifest_item_unresolvable( int $product_id ): array {
				return array(
					'sku'      => 'woo-' . $product_id,
					'in_stock' => false,
				);
			}
			public function ensure_valid_removal( array $object ): array {
				if ( (string) ( $object['category_path'] ?? '' ) === '' ) {
					$object['category_path'] = 'uncategorized';
				}
				$product_url = $object['product_url'] ?? '';
				$has_url     = is_array( $product_url ) ? $product_url !== array() : (string) $product_url !== '';
				if ( ! $has_url ) {
					$object['product_url'] = 'https://shop.test/?smaily_connect_removed_product=' . ( $object['external_id'] ?? '0' );
				}
				return $object;
			}
		};
	}

	/**
	 * @param array<int, \WC_Product|\Throwable>             $products_by_id get_product() lookup; a Throwable is thrown.
	 * @param array<int, \WC_Product>                        $expand_units   builder->expand_by_id() result.
	 * @param array{canonical?: array<int,int>, status?: array<int,string>, enumerated?: array<int,int>, ids?: array<int,int>, batch_size?: int, log?: \ArrayObject<int,string>, missing_units?: int[], variations?: array<int, int[]>, detector_throws?: int[], db_error_on_read?: bool} $opts
	 */
	private function job( IngestQueue $queue, array $products_by_id, array $expand_units, array $opts = array() ): CatalogBackfillJob {
		$canonical_map = $opts['canonical'] ?? array();
		$status_map    = $opts['status'] ?? array();
		$enumerated    = $opts['enumerated'] ?? array();

		$detector_throws = $opts['detector_throws'] ?? array();

		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static function ( int $id ) use ( $canonical_map, $detector_throws ): int {
				if ( in_array( $id, $detector_throws, true ) ) {
					throw new \RuntimeException( 'translation table unreadable' );
				}
				return $canonical_map[ $id ] ?? $id;
			}
		);

		$builder = $this->fake_builder( $expand_units, $opts['missing_units'] ?? array() );
		$flusher = $this->createMock( IngestFlusher::class );

		return new class( $queue, $flusher, $builder, $detector, $products_by_id, $status_map, $enumerated, $opts ) extends CatalogBackfillJob {
			/** @var array<int, \WC_Product|\Throwable> */
			private array $products_by_id;
			/** @var array<int, string> */
			private array $status_map;
			/** @var array<int, int> */
			private array $enumerated;
			/** @var array<int, int> */
			private array $ids;
			private int $batch;
			/** @var \ArrayObject<int, string> */
			private \ArrayObject $log;
			/** @var array<int, int[]> */
			private array $variations;
			private bool $db_error_on_read;

			/**
			 * @param array<int, \WC_Product|\Throwable> $products_by_id
			 * @param array<int, string>      $status_map
			 * @param array<int, int>         $enumerated
			 * @param array{ids?: array<int,int>, batch_size?: int, log?: \ArrayObject<int,string>, variations?: array<int, int[]>, db_error_on_read?: bool} $opts
			 */
			public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder, DetectorInterface $detector, array $products_by_id, array $status_map, array $enumerated, array $opts ) {
				parent::__construct( $queue, $flusher, $builder, $detector );
				$this->products_by_id = $products_by_id;
				$this->status_map     = $status_map;
				$this->enumerated     = $enumerated;
				$this->ids            = $opts['ids'] ?? array();
				$this->batch          = $opts['batch_size'] ?? 100;
				$this->log              = $opts['log'] ?? new \ArrayObject();
				$this->variations       = $opts['variations'] ?? array();
				$this->db_error_on_read = $opts['db_error_on_read'] ?? false;
			}

			public function run( int $id ): void {
				$this->enqueue_record( $id );
			}

			protected function batch_size(): int {
				return $this->batch;
			}

			protected function fetch_ids_after( int $after_id, int $limit ): array {
				$this->log[] = 'fetch';
				$after       = array_filter( $this->ids, static fn ( int $id ): bool => $id > $after_id );
				return array_slice( array_values( $after ), 0, $limit );
			}

			protected function get_product( int $product_id ): ?\WC_Product {
				$product = $this->products_by_id[ $product_id ] ?? null;
				if ( $product instanceof \Throwable ) {
					throw $product;
				}
				return $product;
			}

			protected function post_status( int $post_id ): string {
				return $this->status_map[ $post_id ] ?? 'publish';
			}

			protected function canonical_is_enumerated( int $canonical_id ): bool {
				return in_array( $canonical_id, $this->enumerated, true );
			}

			protected function is_variable_post( int $post_id ): bool {
				return isset( $this->variations[ $post_id ] );
			}

			protected function variation_ids( int $parent_id ): array {
				if ( $this->db_error_on_read ) {
					$GLOBALS['wpdb'] = (object) array( 'last_error' => "Table 'wp_posts' is marked as crashed" );
					return array();
				}
				return $this->variations[ $parent_id ] ?? array();
			}
		};
	}

	private function fake_product( int $id, string $sku, string $category_path = 'food/dry', string $product_url = 'https://shop.test/p' ): \WC_Product {
		return new class( $id, $sku, $category_path, $product_url ) extends \WC_Product {
			private int $id;
			private string $sku;
			private string $category_path;
			private string $product_url;
			public function __construct( int $id, string $sku, string $category_path, string $product_url ) {
				$this->id            = $id;
				$this->sku           = $sku;
				$this->category_path = $category_path;
				$this->product_url   = $product_url;
			}
			public function get_id( $context = 'view' ) {
				return $this->id;
			}
			public function get_sku( $context = 'view' ) {
				return $this->sku;
			}
			public function smly_category_path(): string {
				return $this->category_path;
			}
			public function smly_product_url(): string {
				return $this->product_url;
			}
		};
	}
}

// Minimal WC_Product shim for the anonymous fakes to extend.
if ( ! class_exists( \WC_Product::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WC_Product {
			public function get_id( $context = 'view' ) { return 0; }
			public function get_parent_id( $context = 'view' ) { return 0; }
			public function get_sku( $context = 'view' ) { return ''; }
		}
PHP
	);
}
