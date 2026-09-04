<?php
/**
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Support;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;

/**
 * PRO-1224: one resolver supplies the engine product key on every surface
 * (catalog, order items, browse) — ALWAYS the platform id `woo-{id}`, never
 * the merchant WC SKU field (reverses F3-36's "real SKU when set"). The engine's
 * `sku` is a join key, not a human code; the merchant SKU is blank/reused/garbage
 * on real stores and collapses distinct products (PRO-1223).
 *
 * CC.2: the id is collapsed to its canonical (default-language) post via the
 * detector, so a translated product keys ONE canonical `woo-{id}` across catalog
 * + orders + browse (no per-language duplication). product_group_id() returns the
 * RAW canonical PARENT id for tags.product_id (grouping / §3b removal key).
 */
final class SkuResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Drop any cached detector so the no-detector cases resolve through the
		// single-language SiteLocale fallback (passthrough) deterministically,
		// independent of other tests in the process.
		DetectorFactory::reset();
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		parent::tearDown();
	}

	public function test_merchant_sku_is_ignored_key_is_always_woo_id(): void {
		// Even a product WITH a merchant SKU keys on the platform id (PRO-1224) —
		// the merchant SKU field is never read for the wire `sku`.
		$product = $this->product( 7, 'REAL-1' );

		self::assertSame( 'woo-7', SkuResolver::resolve( $product ) );
	}

	public function test_skuless_product_gets_woo_id_key(): void {
		$product = $this->product( 7, '' );

		// No multilingual plugin → SiteLocale passthrough → id unchanged.
		self::assertSame( 'woo-7', SkuResolver::resolve( $product ) );
	}

	public function test_order_item_prefers_variation_id(): void {
		self::assertSame( 'woo-433', SkuResolver::resolve_order_item( $this->item( 432, 433 ) ) );
	}

	public function test_order_item_falls_back_to_product_id(): void {
		self::assertSame( 'woo-432', SkuResolver::resolve_order_item( $this->item( 432, 0 ) ) );
	}

	public function test_order_item_without_ids_keys_from_order_item_id(): void {
		// Deleted product, both ids zeroed (current WC). NEVER '' (F3-43) — that
		// would drop the line and silently lose the whole order (#58922). Keys on
		// the order-item id so the order still ingests.
		self::assertSame( 'woo-oi-8001', SkuResolver::resolve_order_item( $this->item( 0, 0 ) ) );
		self::assertSame( 'woo-oi-4242', SkuResolver::resolve_order_item( $this->item( 0, 0, 4242 ) ) );
	}

	public function test_skuless_product_canonicalizes_via_detector(): void {
		// The LV translation (id 59221) collapses to the ET canonical (59199) —
		// the real MiuMjau shampoo case. One SKU across languages.
		$detector = $this->detector( array( 59221 => 59199 ) );

		self::assertSame( 'woo-59199', SkuResolver::resolve( $this->product( 59221, '' ), $detector ) );
	}

	public function test_product_with_merchant_sku_still_canonicalizes(): void {
		// The merchant SKU is ignored, so the detector IS consulted even when a
		// SKU is set — the product keys woo-{canonical_id}, not the SKU (PRO-1224,
		// reversing F3-36 where a real SKU short-circuited canonicalization).
		$detector = $this->detector( array( 59221 => 59199 ) );

		self::assertSame( 'woo-59199', SkuResolver::resolve( $this->product( 59221, 'REAL-LV' ), $detector ) );
	}

	public function test_order_item_variation_canonicalizes(): void {
		// A variation bought on the LV store (201) keys the canonical variation
		// (101) — WCML links product_variation across languages, so the engine
		// joins the order line to the catalog's canonical variation row.
		$detector = $this->detector( array( 201 => 101 ) );

		self::assertSame( 'woo-101', SkuResolver::resolve_order_item( $this->item( 200, 201 ), $detector ) );
	}

	public function test_canonical_falls_back_to_input_when_unresolved(): void {
		// Detector returns the input unchanged (single-language / unlinked) →
		// degraded per-language key, never a dropped surface.
		$detector = $this->detector( array() );

		self::assertSame( 'woo-432', SkuResolver::resolve_order_item( $this->item( 432, 0 ), $detector ) );
	}

	public function test_product_group_id_simple_is_own_canonical_id_raw(): void {
		// A simple product (no parent) groups on its own canonical id, RAW —
		// no `woo-` prefix (Shopify parity + §3b remove-by-id shape, PRO-1224).
		$detector = $this->detector( array( 59221 => 59199 ) );

		self::assertSame( '59199', SkuResolver::product_group_id( $this->product( 59221, '' ), $detector ) );
	}

	public function test_product_group_id_variation_is_canonical_parent_raw(): void {
		// A variation groups on its canonical PARENT id (raw) — all variations of
		// one product share it, so the engine can group and remove by product.
		$detector = $this->detector( array( 900 => 800 ) );

		self::assertSame( '800', SkuResolver::product_group_id( $this->variation( 101, 900 ), $detector ) );
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param array<int, int> $map id → canonical id (missing keys pass through).
	 */
	private function detector( array $map ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_canonical_post_id' )->willReturnCallback(
			static fn ( int $id ): int => $map[ $id ] ?? $id
		);
		return $detector;
	}

	private function product( int $id, string $sku ): \WC_Product {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_id' )->willReturn( $id );
		$product->method( 'get_sku' )->willReturn( $sku );
		return $product;
	}

	/** A variation whose get_parent_id() returns the parent product id. */
	private function variation( int $id, int $parent_id ): \WC_Product {
		$variation = $this->createMock( \WC_Product::class );
		$variation->method( 'get_id' )->willReturn( $id );
		$variation->method( 'get_sku' )->willReturn( '' );
		$variation->method( 'get_parent_id' )->willReturn( $parent_id );
		return $variation;
	}

	private function item( int $product_id, int $variation_id, int $item_id = 8001 ): \WC_Order_Item_Product {
		$item = $this->createMock( \WC_Order_Item_Product::class );
		$item->method( 'get_id' )->willReturn( $item_id );
		$item->method( 'get_product_id' )->willReturn( $product_id );
		$item->method( 'get_variation_id' )->willReturn( $variation_id );
		return $item;
	}
}
