<?php
/**
 * CatalogHookHandler tests — product hooks → ingest queue, the
 * is_connected gate, variable-product fan-out, delete-object capture,
 * per-request dedupe, and multilingual canonical collapse (P1 + P4).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CatalogHookHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		CatalogHookHandler::reset_seen();
	}

	protected function tearDown(): void {
		CatalogHookHandler::reset_seen();
		parent::tearDown();
	}

	public function test_save_enqueues_catalog_upsert_when_connected(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'Upsert payload is empty — the flusher loads fresh.' );
	}

	public function test_no_enqueue_when_not_connected(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, false, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );

		self::assertSame( array(), $queue->enqueued, 'No tenant connected → the catalog gate is shut.' );
	}

	public function test_variable_product_fans_out_to_each_variation(): void {
		$queue   = $this->fake_queue();
		$parent  = $this->fake_product( 50, '' ); // variable parent (skuless)
		$v1      = $this->fake_product( 101, 'V-1' );
		$v2      = $this->fake_product( 102, 'V-2' );
		$handler = $this->handler( $queue, true, array( 50 => $parent ), array( $v1, $v2 ) );

		$handler->on_save_product( 50 );

		self::assertCount( 2, $queue->enqueued );
		self::assertSame( '101', $queue->enqueued[0]['entity_id'] );
		self::assertSame( '102', $queue->enqueued[1]['entity_id'] );
	}

	public function test_skuless_unit_is_enqueued_for_synthetic_keying(): void {
		// F3-36: SkuResolver keys SKU-less units wc-{id} in the builder, so
		// the handler no longer drops them (the old drop silently emptied a
		// SKU-less store's catalog — the pilot find).
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, '' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued, 'SKU-less unit is enqueued; build() supplies the synthetic key.' );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
	}

	public function test_repeat_save_in_one_request_is_deduped(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'ACA-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_save_product( 100 );
		$handler->on_save_product( 100 );

		self::assertCount( 1, $queue->enqueued, 'Repeated save_post_product in one request collapses to a single row.' );
	}

	public function test_save_during_trashing_is_skipped_so_it_does_not_clobber_the_removal(): void {
		// Trashing fires wp_trash_post (→ catalog.delete, in_stock=false) AND then
		// save_post (wp_update_post → status=trash). on_save_product must skip the
		// trash transition, else its upsert re-marks the product in_stock=true and
		// undoes the removal.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'TRASHING-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ), null, array( 100 => 'trash' ) );

		$handler->on_save_product( 100 );

		self::assertSame( array(), $queue->enqueued, 'A save fired by trashing must not upsert — the trash/delete path owns the in_stock=false removal.' );
	}

	public function test_save_of_auto_draft_is_skipped(): void {
		// A brand-new "Add product" screen creates an AUTO-DRAFT placeholder
		// post before the merchant enters anything; save_post fires for it
		// like any other save. Enqueuing it would only ever produce a doomed
		// catalog row (empty name/category/price) — skip it (PRO-1491 fix B).
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, '' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ), null, array( 100 => 'auto-draft' ) );

		$handler->on_save_product( 100 );

		self::assertSame( array(), $queue->enqueued, 'An auto-draft save must never enqueue a catalog row.' );
	}

	public function test_delete_enqueues_catalog_delete_with_captured_object(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'GONE-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		$payload = $queue->enqueued[0]['payload'];
		self::assertArrayHasKey( 'object', $payload, 'Delete captures the full object now (the product is still loadable).' );
		self::assertSame( 'GONE-1', $payload['object']['sku'] );
	}

	// --- multilingual canonical collapse (P1 + P4) ---------------------------

	public function test_save_of_a_translation_resyncs_the_canonical_product(): void {
		// Saving the LV translation (200) re-syncs the ET canonical (100), so
		// the engine row stays single and keyed on the canonical unit.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array( 200 => 100 ) )
		);

		$handler->on_save_product( 200 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'], 'The canonical product is enqueued, not the translation.' );
	}

	public function test_deleting_a_translation_resyncs_canonical_not_delete(): void {
		// P4: removing the LV translation must NOT mark the canonical SKU gone —
		// the product still exists in ET. It re-syncs (upsert) instead.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array( 200 => 100 ) )
		);

		$handler->on_delete_product( 200 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame(
			CatalogHookHandler::EVENT_CATALOG_UPSERT,
			$queue->enqueued[0]['type'],
			'Deleting a translation re-syncs the canonical (upsert), it does not delete the SKU.'
		);
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
	}

	public function test_deleting_the_canonical_enqueues_delete(): void {
		// The canonical (default-language) product itself is deleted → mark the
		// SKU unavailable (canonical maps to itself → the delete branch).
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, 'GONE-CANON' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array() ) // 100 → 100 passthrough.
		);

		$handler->on_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
	}

	// --- always-sendable removal fallback (PRO-1498) --------------------------

	public function test_delete_of_object_with_blank_category_path_is_enqueued_with_fallback(): void {
		// A removal object must always reach the engine — it has no delete-by-key,
		// so silently skipping a blank-field removal would leave a synced product
		// stuck in_stock=true forever (extends F3-43's never-drop principle).
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 60233, 'wc-60233', '', 'https://miumjau.test/?post_type=product&p=60233' );
		$handler = $this->handler( $queue, true, array( 60233 => $product ), array( $product ) );

		$handler->on_delete_product( 60233 );

		self::assertCount( 1, $queue->enqueued, 'A removal is always enqueued, never silently dropped.' );
		$object = $queue->enqueued[0]['payload']['object'];
		self::assertSame( 'uncategorized', $object['category_path'], 'A blank category_path is force-filled with a generic placeholder.' );
		self::assertSame( 'https://miumjau.test/?post_type=product&p=60233', $object['product_url'], 'A valid product_url is left untouched.' );
	}

	public function test_delete_of_object_with_blank_product_url_is_enqueued_with_fallback(): void {
		// product_url is the other REQUIRED non-empty field; a still-blank one
		// is force-filled with a synthetic placeholder rather than skipped.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 27695, 'wc-27695', 'food/dry', '' );
		$handler = $this->handler( $queue, true, array( 27695 => $product ), array( $product ) );

		$handler->on_delete_product( 27695 );

		self::assertCount( 1, $queue->enqueued, 'A removal is always enqueued, never silently dropped.' );
		$object = $queue->enqueued[0]['payload']['object'];
		self::assertSame( 'food/dry', $object['category_path'], 'A valid category_path is left untouched.' );
		self::assertSame( 'https://shop.test/?smaily_connect_removed_product=27695', $object['product_url'] );
	}

	public function test_delete_of_unresolvable_product_still_enqueues_minimal_tombstone(): void {
		// PRO-1498: wc_get_product() can fail even though the post IS (or was) a
		// product/variation — e.g. its type came from a since-deactivated
		// plugin. The SKU may already be synced in the engine, so it still
		// needs a tombstone built from the bare id — never silently dropped.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true, array(), array(), null, array(), array( 555 => 'product' ) );

		$handler->on_delete_product( 555 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'] );
		self::assertSame( '555', $queue->enqueued[0]['entity_id'] );
		$object = $queue->enqueued[0]['payload']['object'];
		self::assertSame( 'woo-555', $object['sku'] );
		self::assertFalse( $object['in_stock'] );
		self::assertNotSame( '', $object['category_path'] );
		self::assertNotSame( '', $object['product_url'] );
	}

	public function test_delete_of_unresolvable_non_product_post_enqueues_nothing(): void {
		// wp_trash_post fires for EVERY post type — a trashed page/blog post
		// that WooCommerce never had data for must not spawn a bogus catalog row.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true, array(), array(), null, array(), array( 777 => 'post' ) );

		$handler->on_delete_product( 777 );

		self::assertSame( array(), $queue->enqueued );
	}

	// --- hard delete → §3b catalog.remove (PRO-1230) --------------------------

	public function test_hard_delete_of_parent_product_enqueues_catalog_remove_with_raw_parent_id(): void {
		// A permanent delete of a parent product enqueues ONE catalog.remove
		// carrying the RAW un-prefixed canonical parent id (= tags.product_id;
		// §3b matches on that exact string) — and NO per-SKU catalog.delete
		// rows (§3b tombstones every SKU already).
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'GONE-1' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ) );

		$handler->on_hard_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_REMOVE, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
		self::assertSame(
			array( 'product_id' => '100' ),
			$queue->enqueued[0]['payload'],
			'The removal key is the RAW canonical parent id (tags.product_id) — never woo-100 and never the merchant SKU.'
		);
	}

	public function test_hard_delete_when_not_connected_enqueues_nothing(): void {
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, 'GONE-1' );
		$handler = $this->handler( $queue, false, array( 100 => $product ), array( $product ) );

		$handler->on_hard_delete_product( 100 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_hard_delete_of_a_variation_keeps_the_per_sku_soft_path(): void {
		// §3b is PRODUCT-level: removing a single variation of a still-existing
		// product via catalog/remove would wrongly tombstone all its siblings.
		// A variation hard-delete keeps the existing per-SKU in_stock=false path.
		$queue     = $this->fake_queue();
		$variation = $this->fake_product( 101, 'VAR-1' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 101 => $variation ),
			array( $variation ),
			null,
			array(),
			array( 101 => 'product_variation' )
		);

		$handler->on_hard_delete_product( 101 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_DELETE, $queue->enqueued[0]['type'], 'A variation delete is a per-SKU soft removal, never a §3b product tombstone.' );
	}

	public function test_hard_delete_of_a_translation_resyncs_canonical_not_remove(): void {
		// P4: hard-deleting the LV translation must not tombstone the product —
		// it still exists in ET. Re-sync the canonical instead.
		$queue     = $this->fake_queue();
		$canonical = $this->fake_product( 100, '' );
		$handler   = $this->handler(
			$queue,
			true,
			array( 100 => $canonical ),
			array( $canonical ),
			$this->detector( array( 200 => 100 ) )
		);

		$handler->on_hard_delete_product( 200 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '100', $queue->enqueued[0]['entity_id'] );
	}

	public function test_hard_delete_of_auto_draft_is_skipped(): void {
		// WordPress's daily auto-draft GC hard-deletes piles of never-published
		// artifacts — they were never ingested; a §3b call each would only burn
		// queue rows on not_found answers.
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, '' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ), null, array( 100 => 'auto-draft' ) );

		$handler->on_hard_delete_product( 100 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_hard_delete_of_trashed_product_still_enqueues_remove(): void {
		// Purging from trash IS a before_delete_post moment: the trash already
		// sent in_stock=false (F3-40); the purge adds the §3b tombstone
		// (recommendable=false across all SKUs).
		$queue   = $this->fake_queue();
		$product = $this->fake_product( 100, '' );
		$handler = $this->handler( $queue, true, array( 100 => $product ), array( $product ), null, array( 100 => 'trash' ) );

		$handler->on_hard_delete_product( 100 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_REMOVE, $queue->enqueued[0]['type'] );
	}

	public function test_hard_delete_of_non_product_post_is_ignored(): void {
		// before_delete_post fires for EVERY post type.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true, array(), array(), null, array(), array( 999 => 'post' ) );

		$handler->on_hard_delete_product( 999 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_hard_delete_of_variable_parent_preclaims_variation_delete_slots(): void {
		// Deleting a variable product makes WC hard-delete each variation right
		// after, each firing before_delete_post. §3b already tombstones every
		// SKU, so the per-variation soft-path rows in the same request must
		// collapse into the one catalog.remove.
		$queue   = $this->fake_queue();
		$parent  = $this->fake_product( 50, '' );
		$v1      = $this->fake_product( 101, 'V-1' );
		$handler = $this->handler(
			$queue,
			true,
			array(
				50  => $parent,
				101 => $v1,
			),
			array( $v1 ), // expand(parent) → its variations.
			null,
			array(),
			array(
				50  => 'product',
				101 => 'product_variation',
			)
		);

		$handler->on_hard_delete_product( 50 );  // parent → catalog.remove + pre-claim.
		$handler->on_hard_delete_product( 101 ); // WC deleting the variation next.

		self::assertCount( 1, $queue->enqueued, 'The variation soft-path row is pre-claimed by the parent removal — one catalog.remove, no noise.' );
		self::assertSame( CatalogHookHandler::EVENT_CATALOG_REMOVE, $queue->enqueued[0]['type'] );
	}

	// --- doubles -------------------------------------------------------------

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
		};
	}

	/**
	 * @param array<int, int> $map post id → canonical id (missing → passthrough).
	 */
	private function detector( array $map ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static fn ( int $id ): int => $map[ $id ] ?? $id
		);
		return $detector;
	}

	/**
	 * @param array<int, \WC_Product> $products_by_id get_product() lookup table.
	 * @param array<int, \WC_Product> $expand_units   builder->expand() result.
	 * @param array<int, string>      $status_map     post id → status ('publish' default).
	 * @param array<int, string>      $type_map       post id → post type ('product' default).
	 */
	private function handler( IngestQueue $queue, bool $connected, array $products_by_id, array $expand_units, ?DetectorInterface $detector = null, array $status_map = array(), array $type_map = array() ): CatalogHookHandler {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		$builder = new class( $expand_units ) extends CatalogPayloadBuilder {
			/** @var array<int, \WC_Product> */
			private array $units;
			/** @param array<int, \WC_Product> $units */
			public function __construct( array $units ) {
				$this->units = $units;
			}
			public function expand( \WC_Product $product ): array {
				return $this->units;
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
				// them here. A fake_product exposes them via smly_* accessors
				// (defaults non-empty, blank for the always-sendable-fallback cases).
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

		$detector = $detector ?? $this->detector( array() );

		return new class( $queue, $builder, $settings, $detector, $products_by_id, $status_map, $type_map ) extends CatalogHookHandler {
			/** @var array<int, \WC_Product> */
			private array $products_by_id;
			/** @var array<int, string> */
			private array $status_map;
			/** @var array<int, string> */
			private array $type_map;
			/**
			 * @param array<int, \WC_Product> $products_by_id
			 * @param array<int, string>      $status_map
			 * @param array<int, string>      $type_map
			 */
			public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings, DetectorInterface $detector, array $products_by_id, array $status_map, array $type_map ) {
				parent::__construct( $queue, $builder, $settings, $detector );
				$this->products_by_id = $products_by_id;
				$this->status_map     = $status_map;
				$this->type_map       = $type_map;
			}
			protected function get_product( int $product_id ): ?\WC_Product {
				return $this->products_by_id[ $product_id ] ?? null;
			}
			protected function post_status( int $post_id ): string {
				return $this->status_map[ $post_id ] ?? 'publish';
			}
			protected function post_type( int $post_id ): string {
				return $this->type_map[ $post_id ] ?? 'product';
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
