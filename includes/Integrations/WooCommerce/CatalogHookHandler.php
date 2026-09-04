<?php
/**
 * WC product hooks → rec-engine catalog ingest queue.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;

/**
 * Fans WooCommerce product changes into the rec-engine ingest queue
 * (PLUGIN_IMPLEMENTATION_WP.md §452): save / stock-change → catalog.upsert,
 * delete → catalog.delete. The IngestFlusher later turns each queued row
 * into a /api/v1/ingest/catalog object.
 *
 * Variable products fan out: each variation is its own ingest unit with
 * its own queue row and event_uuid (CatalogPayloadBuilder::expand), so the
 * engine dedups variations independently. SKU-less units are NOT dropped —
 * SkuResolver keys every unit `woo-{id}` (the platform id, never the merchant
 * SKU field) in the builder (PRO-1224).
 *
 * Multilingual collapse (catalog-correctness P1): Polylang/WPML store each
 * translation as its own product post, so a save/stock/delete arriving for a
 * translation is first mapped to its CANONICAL (default-language) product via
 * the DetectorInterface — we always enqueue the canonical so its
 * `woo-{canonical_id}` key is stable across languages (one engine row per real
 * product, RECENGINE_API_CONTRACT.md §3). Single-language sites resolve every
 * post to itself (passthrough), so this is a no-op there.
 *
 * Gate: enqueue only while a tenant is connected (RecEngineSettings::
 * is_connected). Unlike the email HookHandler's setup_completed gate (which
 * exists to avoid double-sync with the legacy plugin), catalog ingest has
 * no legacy counterpart — the only question is whether there's a tenant to
 * send to.
 *
 * Per-request dedupe: save_post_product can fire repeatedly within one
 * request; a static $seen set collapses repeats to a single row per unit.
 * Because every save resolves to the canonical product first, repeated saves
 * of different translations collapse to the same canonical units too.
 *
 * Removal capture (trash + permanent delete): the product is still loadable
 * in both wp_trash_post and before_delete_post, so the full catalog object is
 * built and stored in the row now; the flusher stamps in_stock=false + event_id
 * at send time (it can't load a gone product). This is NOT a hard removal — the
 * engine has no delete-by-key, so a catalog.delete row is sent as an UPSERT with
 * in_stock=false (RECENGINE_API_CONTRACT.md §3, "Removal is explicit: re-send
 * with in_stock=false"). The SKU row therefore SURVIVES in the engine catalog so
 * its order-history join (and model training) is preserved — the product is just
 * marked unavailable, so it can't be recommended. Trashing and untrashing share
 * this path: trash → on_delete_product (in_stock=false), untrash → on_save_product
 * (re-sync real stock). Removing a TRANSLATION must NOT remove the canonical SKU
 * (P4) — see on_delete_product.
 *
 * Always sendable (PRO-1498): a captured removal is force-filled via
 * CatalogPayloadBuilder::ensure_valid_removal() if category_path/product_url
 * still come back blank, and a product that no longer resolves to a
 * WC_Product AT ALL still gets a minimal tombstone (build_unresolvable()) —
 * a catalog.delete row is NEVER silently skipped, because the engine has no
 * delete-by-key and a skipped removal would leave a synced product stuck
 * `in_stock=true` forever (extends F3-43's order-item never-drop principle).
 *
 * HARD delete (PRO-1230, contract v1.3.0 §3b): a permanently deleted PARENT
 * product additionally stops being recommendable engine-side via one
 * catalog.remove row (POST /ingest/catalog/remove) carrying the RAW canonical
 * parent id — `tags.product_id`, un-prefixed — which tombstones ALL of the
 * product's SKUs (in_stock=false + recommendable=false, rows kept). Only
 * before_delete_post routes here (on_hard_delete_product); trash stays on the
 * F3-40 soft path above. A single VARIATION's hard-delete keeps the per-SKU
 * soft path — §3b is product-level and would wrongly tombstone its siblings.
 *
 * Not final: tests subclass to stub get_product() while recording enqueues
 * through a doubled IngestQueue.
 */
class CatalogHookHandler {

	public const EVENT_CATALOG_UPSERT = 'catalog.upsert';
	public const EVENT_CATALOG_DELETE = 'catalog.delete';
	public const EVENT_CATALOG_REMOVE = 'catalog.remove';

	/** @var array<string, bool> per-request dedupe keyed by "{event}:{product_id}". */
	private static array $seen = array();

	private IngestQueue $queue;
	private CatalogPayloadBuilder $builder;
	private RecEngineSettings $settings;
	private DetectorInterface $detector;

	public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings, DetectorInterface $detector ) {
		$this->queue    = $queue;
		$this->builder  = $builder;
		$this->settings = $settings;
		$this->detector = $detector;
	}

	public function on_save_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		// Trashing a product fires save_post (wp_trash_post → wp_update_post) AFTER
		// wp_trash_post already enqueued the in_stock=false removal — re-syncing
		// here would clobber it back to in_stock=true. The trash/delete path owns a
		// trashed post; skip it. (Untrash restores the status FIRST, then fires
		// untrashed_post → here, so a restored product is correctly NOT skipped.)
		if ( $this->post_status( $post_id ) === 'trash' ) {
			return;
		}
		// A brand-new "Add product" screen creates an AUTO-DRAFT placeholder
		// post (empty title, no category, no price) before the merchant has
		// entered anything; save_post fires for it like any other save.
		// Without this guard every "Add product" click enqueued a doomed
		// catalog row — empty category_path/product_url — that the engine
		// would reject (PRO-1491 fix B). The auto-draft is re-saved as a real
		// draft/publish later, which fires save_post again and is enqueued
		// normally then.
		if ( $this->post_status( $post_id ) === 'auto-draft' ) {
			return;
		}
		// Saving any translation re-syncs the CANONICAL product, so its
		// `wc-{canonical_id}` row stays single and current across languages (P1).
		$product = $this->canonical_product( $post_id );
		if ( $product === null ) {
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->enqueue_upsert( $unit );
		}
	}

	/**
	 * woocommerce_product_set_stock_status fires ($product_id, $stock_status,
	 * $product). A stock flip is just a re-sync of the product.
	 *
	 * @param int|string            $product_id
	 * @param string                $stock_status
	 * @param \WC_Product|null|mixed $product
	 */
	public function on_stock_change( $product_id, $stock_status = '', $product = null ): void {
		if ( ! $this->gate_open() ) {
			return;
		}
		$id     = ( $product instanceof \WC_Product ) ? (int) $product->get_id() : (int) $product_id;
		$loaded = $this->canonical_product( $id );
		if ( $loaded === null ) {
			return;
		}
		foreach ( $this->builder->expand( $loaded ) as $unit ) {
			$this->enqueue_upsert( $unit );
		}
	}

	/**
	 * A product left the catalog — trashed (wp_trash_post) or permanently
	 * deleted (before_delete_post). Both routes land here because the engine
	 * treats removal identically: an in_stock=false UPSERT that keeps the SKU
	 * row (so order-history joins / training survive), never a hard delete. The
	 * product is still loadable in both hooks, so enqueue_delete captures it.
	 */
	public function on_delete_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}

		// P4: removing a TRANSLATION must NOT remove the canonical SKU — the
		// product still exists in other languages. Re-sync the canonical
		// (upsert) so its content drops the removed language instead of marking
		// the whole product unavailable. Only the canonical's own removal (or
		// a single-language product) marks the row in_stock=false.
		$canonical_id = $this->detector->get_canonical_post_id( $post_id );
		if ( $canonical_id > 0 && $canonical_id !== $post_id ) {
			$canonical = $this->get_product( $canonical_id );
			if ( $canonical !== null ) {
				foreach ( $this->builder->expand( $canonical ) as $unit ) {
					$this->enqueue_upsert( $unit );
				}
				return;
			}
			// Canonical gone too → fall through and mark this post's units gone.
		}

		$product = $this->get_product( $post_id );
		if ( $product === null ) {
			// wp_trash_post fires for EVERY post type; a non-product post
			// (page, blog post, …) has nothing to mark unavailable. But when
			// the post type IS (or was) a product/variation and wc_get_product()
			// still failed — its WC data already stripped, or its product_type
			// came from a since-deactivated plugin (PRO-1498) — the SKU may
			// already be synced in the engine, so it still needs a tombstone;
			// never silently drop the removal (F3-43 principle).
			$this->enqueue_delete_unresolvable( $post_id );
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->enqueue_delete( $unit );
		}
	}

	/**
	 * A product post is being PERMANENTLY deleted (before_delete_post — fires
	 * for a direct hard-delete AND for an empty-trash purge; never for a mere
	 * trashing). The WC data is still loadable here, so this is the moment to
	 * capture the removal key before it is gone (PRO-1230).
	 *
	 * Routing:
	 *   - PARENT product → one catalog.remove row carrying the RAW canonical
	 *     parent id (`tags.product_id`); the engine tombstones every SKU of the
	 *     product (§3b: in_stock=false + recommendable=false). The per-SKU
	 *     catalog.delete rows are NOT also enqueued — §3b is strictly stronger,
	 *     and a later in_stock=false UPSERT racing the tombstone across two
	 *     flush cycles is avoidable wire noise.
	 *   - Single VARIATION (parent lives on) → the existing per-SKU soft path
	 *     (on_delete_product → in_stock=false). §3b is product-level; firing it
	 *     here would wrongly tombstone the surviving sibling variations.
	 *   - TRANSLATION of a surviving canonical → re-sync the canonical (P4),
	 *     exactly like trash — the product still exists.
	 *   - auto-draft → skip: WordPress's daily GC hard-deletes piles of
	 *     never-published artifacts that were never ingested; a §3b call for
	 *     each is a not_found round-trip for nothing.
	 *   - any other post type → not ours (before_delete_post fires for all).
	 */
	public function on_hard_delete_product( int $post_id ): void {
		if ( ! $this->gate_open() ) {
			return;
		}

		$post_type = $this->post_type( $post_id );

		if ( 'product_variation' === $post_type ) {
			$this->on_delete_product( $post_id );
			return;
		}

		if ( 'product' !== $post_type ) {
			return;
		}

		if ( 'auto-draft' === $this->post_status( $post_id ) ) {
			return;
		}

		// P4: hard-deleting a TRANSLATION must NOT tombstone the product — it
		// still exists in other languages. Re-sync the canonical instead.
		$canonical_id = $this->detector->get_canonical_post_id( $post_id );
		if ( $canonical_id > 0 && $canonical_id !== $post_id ) {
			$canonical = $this->get_product( $canonical_id );
			if ( $canonical !== null ) {
				foreach ( $this->builder->expand( $canonical ) as $unit ) {
					$this->enqueue_upsert( $unit );
				}
				return;
			}
			// Canonical gone too → fall through; product_group_id() collapses
			// to the canonical id, so the removal still keys the engine rows.
		}

		$product = $this->get_product( $post_id );
		if ( $product === null ) {
			// Already unloadable — nothing to key the removal from. The engine
			// row (if any) stays until the coordinated purge; the periodic full
			// re-sync is the contract's reconciler.
			return;
		}

		$this->enqueue_remove( $product );
	}

	/**
	 * Resolve a (possibly translated) product post to its canonical
	 * default-language product, loading it. Falls back to the saved post itself
	 * when the canonical can't be loaded — never drops a save silently.
	 */
	private function canonical_product( int $post_id ): ?\WC_Product {
		$canonical_id = $this->detector->get_canonical_post_id( $post_id );
		if ( $canonical_id <= 0 ) {
			$canonical_id = $post_id;
		}
		$product = $this->get_product( $canonical_id );
		if ( $product === null && $canonical_id !== $post_id ) {
			$product = $this->get_product( $post_id );
		}
		return $product;
	}

	/**
	 * Reset the per-request dedupe set. Tests use it between cases; production
	 * never calls it (the static is request-scoped).
	 */
	public static function reset_seen(): void {
		self::$seen = array();
	}

	private function enqueue_upsert( \WC_Product $unit ): void {
		// No SKU guard here (F3-36): SkuResolver keys SKU-less units
		// synthetically in the builder, so every expanded unit is ingestable.
		if ( $this->already_seen( self::EVENT_CATALOG_UPSERT, (int) $unit->get_id() ) ) {
			return;
		}
		// Empty payload — the flusher loads the product fresh by entity_id so
		// the engine gets current state at send time.
		$this->queue->enqueue( self::EVENT_CATALOG_UPSERT, (string) $unit->get_id(), array() );
	}

	private function enqueue_delete( \WC_Product $unit ): void {
		// No SKU guard here either (F3-36) — see enqueue_upsert().
		if ( $this->already_seen( self::EVENT_CATALOG_DELETE, (int) $unit->get_id() ) ) {
			return;
		}
		// Capture the full object now (still loadable); event_uuid is generated
		// at enqueue, so the flusher stamps event_id + in_stock=false at send.
		// ensure_valid_removal() force-fills category_path/product_url with a
		// sane fallback if they still come back blank (PRO-1498) — a tombstone
		// must always reach the engine, never be silently skipped: the engine
		// has no delete-by-key, so a skipped removal leaves a synced product
		// stuck in_stock=true forever (extends F3-43's never-drop principle).
		// NOT applied to the upsert path: an empty category_path on a PUBLISHED
		// product is an intended merchant-data-gap signal the engine surfaces via
		// the Event Log — see CatalogPayloadBuilder::primary_category_path().
		$object = $this->builder->ensure_valid_removal( $this->builder->build( $unit, '' ) );

		$this->queue->enqueue( self::EVENT_CATALOG_DELETE, (string) $unit->get_id(), array( 'object' => $object ) );
	}

	/**
	 * Tombstone a product/variation id that no longer resolves to a
	 * WC_Product at all (PRO-1498) — e.g. wc_get_product() fails because the
	 * product's type came from a since-deactivated plugin, or its WC data is
	 * already stripped. wp_trash_post fires for EVERY post type, so this only
	 * fires for a post whose type is (or was) product/variation — an
	 * unrelated trashed post (page, blog post, …) is correctly left alone.
	 */
	private function enqueue_delete_unresolvable( int $post_id ): void {
		$post_type = $this->post_type( $post_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}
		if ( $this->already_seen( self::EVENT_CATALOG_DELETE, $post_id ) ) {
			return;
		}
		$object = $this->builder->build_unresolvable( $post_id, '' );
		$this->queue->enqueue( self::EVENT_CATALOG_DELETE, (string) $post_id, array( 'object' => $object ) );
	}

	/**
	 * Enqueue the §3b product-level removal for a hard-deleted PARENT product.
	 *
	 * The payload's `product_id` is SkuResolver::product_group_id() — the RAW
	 * (un-prefixed) canonical parent id, byte-identical to the `tags.product_id`
	 * the catalog sync stamped on every row of this product; §3b matches on
	 * exactly that string. NEVER the `woo-`-prefixed `sku`.
	 *
	 * Also pre-claims the per-request catalog.delete dedupe slots of the
	 * product's units: deleting a variable product makes WC hard-delete each
	 * variation right after (each firing before_delete_post → the variation
	 * soft path in on_hard_delete_product). §3b already tombstones every SKU of
	 * the product, so those per-variation in_stock=false rows would be pure
	 * noise — claiming their slots collapses them within this request.
	 */
	private function enqueue_remove( \WC_Product $product ): void {
		$group_id = SkuResolver::product_group_id( $product, $this->detector );
		if ( $group_id === '' ) {
			return;
		}
		if ( $this->already_seen( self::EVENT_CATALOG_REMOVE, (int) $group_id ) ) {
			return;
		}
		foreach ( $this->builder->expand( $product ) as $unit ) {
			$this->already_seen( self::EVENT_CATALOG_DELETE, (int) $unit->get_id() );
		}
		$this->queue->enqueue(
			self::EVENT_CATALOG_REMOVE,
			(string) $product->get_id(),
			array( 'product_id' => $group_id ),
			null,
			CatalogRemoveFlusher::FLUSH_HOOK,
			CatalogRemoveFlusher::AS_GROUP
		);
	}

	private function already_seen( string $event_type, int $product_id ): bool {
		$key = $event_type . ':' . $product_id;
		if ( isset( self::$seen[ $key ] ) ) {
			return true;
		}
		self::$seen[ $key ] = true;
		return false;
	}

	private function gate_open(): bool {
		return $this->settings->is_connected();
	}

	/**
	 * Load a product by id. Protected so tests can stub the lookup.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * The post's status slug ('publish' / 'trash' / …). Protected so tests can
	 * drive the save-during-trashing guard without WordPress.
	 */
	protected function post_status( int $post_id ): string {
		return (string) get_post_status( $post_id );
	}

	/**
	 * The post's type slug ('product' / 'product_variation' / …). Protected so
	 * tests can drive the hard-delete routing without WordPress.
	 */
	protected function post_type( int $post_id ): string {
		return (string) get_post_type( $post_id );
	}
}
