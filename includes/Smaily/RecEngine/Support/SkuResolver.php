<?php
/**
 * Single source of truth for the rec-engine product key (wire `sku`).
 *
 * @package Smaily\Connect\Smaily\RecEngine\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Support;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;

/**
 * Resolves the engine-side product key (`sku`) for a WC product or order line:
 * ALWAYS the platform id namespaced by source — `woo-{canonical_id}` — never
 * the merchant-entered WC SKU field.
 *
 * Why the key is the platform id and NOT the merchant SKU (PRO-1224, reverses
 * F3-36): the engine's `sku` is a **join/identity key, not a human-facing code**
 * (RECENGINE_API_CONTRACT.md §3, identity rule sharpened 2026-07-09). The
 * merchant WC "SKU" field is optional, frequently blank, reused, or garbage
 * (real cases seen: a price `"63.00"`, a sequence number `"12"` shared by dozens
 * of products, an EAN barcode) — keying on it collapses DISTINCT products onto
 * one `(tenant_id, sku)` row and silently destroys history (Urban Green's
 * 605→330 catalog collapse, PRO-1223). So the merchant SKU is NEVER emitted as
 * `sku`, not even as a fallback; the engine is adding fail-loud namespace
 * validation (PRO-1223/PRO-1225) that rejects off-scheme keys. The prior scheme
 * ("real SKU when set, else synthetic `wc-{id}`", F3-36) is superseded — see
 * DECISIONS. The raw platform id still rides in `external_id` (the engine's
 * collision-detection + hard-delete-by-source key); the merchant SKU is dropped
 * entirely (engine consumes neither — engine answer on PRO-1225, 2026-07-10).
 *
 * Why this exists at all: the engine keys the ENTIRE pipeline on `sku` — catalog
 * natural key `(tenant_id, sku)`, order `items[].sku` (required), browse
 * `product_view`/`cart_*` (required) — but WooCommerce does not require SKUs and
 * the pilot store has none. One resolver used by ALL THREE surfaces keeps the
 * key consistent — the same product yields the same key in catalog, order items,
 * and browse events, which is what makes the engine's joins work.
 *
 * Multilingual canonicalization (catalog-correctness CC.2): on a WPML/Polylang
 * store each translation is its own product post with its own id, so a raw
 * platform key would differ per language (`woo-{et_id}`, `woo-{lv_id}`, …) and
 * the engine would see the same product N times — duplicate SKUs it cannot
 * dedupe (RECENGINE_API_CONTRACT.md §3). Because this resolver is the single
 * chokepoint for all three surfaces, canonicalizing HERE makes catalog, order
 * items, AND browse all key the same product to the same canonical `woo-{id}`.
 * The id is mapped to its default-language post via the active DetectorInterface
 * (`get_canonical_post_id`). Single-language sites resolve every id to itself
 * (passthrough), so this is a no-op there. The detector defaults to the active
 * one (DetectorFactory) — callers may inject one (tests, determinism).
 *
 * Key properties: `woo-{canonical_id}` is stable for the product's lifetime and
 * fits the engine's 64-char cap (ids are ints). Deleted products: current WC
 * ZEROES the order items' product/variation reference on permanent deletion
 * (verified empirically on WC 10.7 — `_product_id` becomes 0). When the id
 * survives, the line keys `woo-{canonical_id}` and ingests; when it's zeroed,
 * resolve_order_item() keys the line on the order-item id (`woo-oi-{item_id}`) so
 * the line is NEVER dropped — an order must never silently vanish for one
 * unkeyable line (F3-43). The fallback key won't match a catalog row (no
 * item-level inference), but the order still ingests.
 *
 * Migration note (PRO-1224): a store already synced under the old scheme (real
 * SKU or `wc-{id}`) keeps those rows in the engine (UPSERT-only, no delete-by-
 * absence) — the new `woo-{id}` keys create fresh rows and the old ones linger.
 * Orphan removal is a one-time manual purge on the engine side, coordinated per
 * store (contract §3 "Consequence when changing the SKU scheme").
 */
final class SkuResolver {

	/** Source-namespaced key prefix (contract §3: `sku` is `woo-<id>`). */
	public const KEY_PREFIX = 'woo-';

	/**
	 * Engine `sku` for a loadable product/variation: ALWAYS `woo-{canonical_id}`
	 * (the id collapsed to its default-language post). The merchant WC SKU field
	 * is never read — see the class docblock (PRO-1224).
	 *
	 * @param DetectorInterface|null $detector Multilingual detector; defaults to
	 *        the active one. Inject for tests / call-site determinism.
	 */
	public static function resolve( \WC_Product $product, ?DetectorInterface $detector = null ): string {
		return self::KEY_PREFIX . (string) self::canonical_id( (int) $product->get_id(), $detector );
	}

	/**
	 * Engine `sku` for a bare product/variation id with no loadable
	 * WC_Product (PRO-1498): a product whose data is already gone by the
	 * time a delete-time tombstone is built. Canonicalization only needs the
	 * id, so this still collapses a translated product's id to the same key
	 * `resolve()` would have produced while the product was loadable.
	 *
	 * @param DetectorInterface|null $detector Multilingual detector; defaults to
	 *        the active one. Inject for tests / call-site determinism.
	 */
	public static function resolve_id( int $product_id, ?DetectorInterface $detector = null ): string {
		return self::KEY_PREFIX . (string) self::canonical_id( $product_id, $detector );
	}

	/**
	 * Engine key for an order line whose product no longer loads (deleted):
	 * synthesised from the id WC stored on the line item at purchase time.
	 * Prefers the variation id (catalog ingests variations as the units) and
	 * falls back to the parent product id. The id is canonicalized so an order
	 * placed against a translated product keys the SAME `wc-{canonical_id}` the
	 * catalog ingested (engine-side join).
	 *
	 * NEVER returns '' (F3-43): when current WC has zeroed BOTH ids on permanent
	 * deletion, this keys the line on the order-item id (`woo-oi-{item_id}`) — a
	 * unique, non-empty fallback so the line is never dropped and the whole order
	 * never silently vanishes for one unkeyable line (engine brief 2026-06-19).
	 * That key won't match a catalog row, so item-level inference can't apply,
	 * but the order still ingests (RFM / tier) — the accepted trade-off, because
	 * the order surviving is what matters. The flusher's empty-items skip only
	 * guards a genuinely product-less order (only shipping/fee lines).
	 *
	 * @param DetectorInterface|null $detector Multilingual detector; defaults to
	 *        the active one. Inject for tests / call-site determinism.
	 */
	public static function resolve_order_item( \WC_Order_Item_Product $item, ?DetectorInterface $detector = null ): string {
		$id = (int) $item->get_variation_id();
		if ( $id <= 0 ) {
			$id = (int) $item->get_product_id();
		}
		if ( $id <= 0 ) {
			// Deleted product, ids zeroed — key on the order-item id (always > 0
			// on a saved line) so the order is never dropped. (F3-43.)
			return self::KEY_PREFIX . 'oi-' . (string) $item->get_id();
		}
		return self::KEY_PREFIX . (string) self::canonical_id( $id, $detector );
	}

	/**
	 * The engine `tags.product_id` value for a catalog unit: the RAW (un-prefixed)
	 * canonical PARENT product id, as a string.
	 *
	 * This is the grouping/removal key — every variation of one product shares it,
	 * so the engine groups the per-variation `woo-<variation_id>` rows back to
	 * their product (cross-variant cadence / sample→full, PRO-1227) and removes a
	 * whole product's SKUs at once on hard-delete (`catalog/remove` §3b, PRO-1230).
	 *
	 * RAW, no `woo-` prefix — deliberate parity with Shopify Connect, which emits
	 * the raw parent legacyResourceId (`tags.product_id = product.id`), and with
	 * the contract's §3b example (`product_ids: ["7620134"]`, not `"woo-7620134"`).
	 * Canonicalized (default-language) so a translated product groups to the same
	 * id its catalog row was ingested under. A variation keys on its parent
	 * (`get_parent_id()`); a simple product is its own parent.
	 *
	 * @param DetectorInterface|null $detector Multilingual detector; defaults to
	 *        the active one. Inject for tests / call-site determinism.
	 */
	public static function product_group_id( \WC_Product $product, ?DetectorInterface $detector = null ): string {
		$parent_id = ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 )
			? (int) $product->get_parent_id()
			: (int) $product->get_id();
		return (string) self::canonical_id( $parent_id, $detector );
	}

	/**
	 * Collapse a product/variation id to its canonical (default-language) post
	 * id via the detector. Falls back to the input id when the detector can't
	 * resolve one (single-language site, unlinked variation, deleted post) — a
	 * degraded per-language key beats dropping the surface.
	 */
	private static function canonical_id( int $id, ?DetectorInterface $detector ): int {
		$detector  = $detector ?? DetectorFactory::create();
		$canonical = $detector->get_canonical_post_id( $id );
		return $canonical > 0 ? $canonical : $id;
	}
}
