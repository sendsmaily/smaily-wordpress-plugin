<?php
/**
 * StorefrontBeacon::product_context() — the product-page sku resolution PRO-1445
 * carves out of page_context() so it's testable without WooCommerce's
 * is_product() conditional tag (see CLAUDE.md "Browse browser-timing is NOT
 * live-walk-covered").
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\StorefrontBeacon;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Unit\Support\StubsConnectionPrefix;

/**
 * PRO-1390 was a browse surface (cart_add/cart_remove) reading WooCommerce's
 * merchant SKU field instead of going through Support\SkuResolver — the same
 * class of bug this class's product_context() is exposed to (it feeds the
 * product-page browse event's `sku`). These tests pin that it always resolves
 * through SkuResolver (`woo-{id}`), even when the product carries a merchant
 * SKU that would produce a different, wrong string.
 */
final class StorefrontBeaconTest extends TestCase {

	use StubsConnectionPrefix;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_connection_prefix( '' );
		DetectorFactory::reset();
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function beacon(): StorefrontBeacon {
		return new StorefrontBeacon( new RecEngineSettings() );
	}

	public function test_product_context_keys_on_canonical_id_not_merchant_sku(): void {
		// A merchant SKU that looks nothing like the canonical key — exactly
		// the PRO-1390 shape (an EAN/price/reused string). If product_context()
		// ever regressed to reading get_sku(), this would catch it.
		$product = $this->fake_product( 7620134, '4022858617724' );

		$context = $this->beacon()->product_context( $product );

		self::assertSame( 'woo-7620134', $context['sku'] );
	}

	public function test_product_context_ignores_a_blank_merchant_sku(): void {
		// SkuResolver never returns '' — a skuless product still gets a key.
		$product = $this->fake_product( 42, '' );

		self::assertSame( 'woo-42', $this->beacon()->product_context( $product )['sku'] );
	}

	public function test_product_context_omits_category_path_when_none_resolves(): void {
		// No WordPress term machinery loaded in the unit suite (get_the_terms
		// doesn't exist) — primary_category_path() short-circuits to ''.
		$product = $this->fake_product( 42, '' );

		self::assertArrayNotHasKey( 'categoryPath', $this->beacon()->product_context( $product ) );
	}

	public function test_a_variable_product_view_keys_on_its_default_variation(): void {
		// CON-427: the catalog has a row per variation and none for the
		// parent, so the parent's own key would join nothing.
		$product = $this->fake_variable_product( 500, array( 501, 502 ) );
		Monkey\Functions\when( 'get_post_status' )->justReturn( 'publish' );

		self::assertSame( 'woo-502', $this->beacon_with_default( 502 )->product_context( $product )['sku'] );
	}

	public function test_a_variable_product_view_without_a_default_keys_on_the_first_published_variation(): void {
		// 501 is disabled (private): never chosen, though the catalog sends it.
		$product = $this->fake_variable_product( 500, array( 501, 502, 503 ) );
		Monkey\Functions\when( 'get_post_status' )->alias(
			static function ( int $id ): string {
				return 501 === $id ? 'private' : 'publish';
			}
		);

		self::assertSame( 'woo-502', $this->beacon_with_default( 0 )->product_context( $product )['sku'] );
	}

	public function test_a_variable_product_view_with_no_published_variation_keeps_the_parent_key(): void {
		$product = $this->fake_variable_product( 500, array( 501 ) );
		Monkey\Functions\when( 'get_post_status' )->justReturn( 'private' );

		self::assertSame( 'woo-500', $this->beacon_with_default( 0 )->product_context( $product )['sku'] );
	}

	public function test_a_variation_page_context_keeps_its_own_key(): void {
		// A simple product or a chosen variation: the key is unchanged.
		$variation = $this->fake_product( 502, '' );

		self::assertSame( 'woo-502', $this->beacon_with_default( 999 )->product_context( $variation )['sku'] );
	}

	/** A beacon whose default-variation lookup answers $default_id. */
	private function beacon_with_default( int $default_id ): StorefrontBeacon {
		return new class( new RecEngineSettings(), $default_id ) extends StorefrontBeacon {
			private int $default_id;

			public function __construct( RecEngineSettings $settings, int $default_id ) {
				parent::__construct( $settings );
				$this->default_id = $default_id;
			}

			protected function default_variation_id( \WC_Product $product ): int {
				return $this->default_id;
			}
		};
	}

	/**
	 * @param int[] $children Variation ids in the catalog's order.
	 */
	private function fake_variable_product( int $id, array $children ): \WC_Product {
		return new class( $id, $children ) extends \WC_Product {
			private int $id;
			/** @var int[] */
			private array $children;

			/**
			 * @param int[] $children
			 */
			public function __construct( int $id, array $children ) {
				$this->id       = $id;
				$this->children = $children;
			}

			public function get_id( $context = 'view' ) {
				return $this->id;
			}
			public function get_parent_id( $context = 'view' ) {
				return 0;
			}
			public function get_sku( $context = 'view' ) {
				return 'MERCHANT-SKU';
			}
			public function is_type( $type ) {
				return 'variable' === $type;
			}
			public function get_children( $context = 'view' ) {
				return $this->children;
			}
		};
	}

	private function fake_product( int $id, string $sku ): \WC_Product {
		return new class( $id, $sku ) extends \WC_Product {
			private int $id;
			private string $sku;

			public function __construct( int $id, string $sku ) {
				$this->id  = $id;
				$this->sku = $sku;
			}

			public function get_id( $context = 'view' ) {
				return $this->id;
			}
			public function get_parent_id( $context = 'view' ) {
				return 0;
			}
			public function get_sku( $context = 'view' ) {
				return $this->sku;
			}
			public function is_type( $type ) {
				return 'simple' === $type;
			}
		};
	}
}

// Minimal WC_Product shim for the anonymous fake to extend — Brain Monkey
// doesn't load WooCommerce (mirrors CatalogPayloadBuilderTest's shim).
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
