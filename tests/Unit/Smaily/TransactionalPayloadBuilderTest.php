<?php
/**
 * TransactionalPayloadBuilder tests (PRO-1504 Stage 2) — the message/send
 * `context` merge-tag shape, gross pricing (PRO-1241), and the
 * product_<field>_1..10 template-parity matrix.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\TransactionalPayloadBuilder;

final class TransactionalPayloadBuilderTest extends TestCase {

	public function test_order_level_fields(): void {
		$order = $this->fake_order(
			array(
				'order_number'    => '1001',
				'total'           => 55.80,
				'currency'        => 'EUR',
				'payment_method'  => 'Bank transfer',
				'shipping_method' => 'DPD',
				'first_name'      => 'Mari',
				'last_name'       => 'Maasikas',
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( '1001', $context['order_number'] );
		self::assertSame( 'display:55.8', $context['order_total'], 'order_total is already GROSS (get_total) — no extra tax added.' );
		self::assertSame( 'EUR', $context['currency'] );
		self::assertSame( 'Bank transfer', $context['payment_method'] );
		self::assertSame( 'DPD', $context['shipping_method'] );
		self::assertSame( 'Mari', $context['first_name'] );
		self::assertSame( 'Maasikas', $context['last_name'] );
	}

	public function test_order_level_text_fields_are_htmlspecialchars_escaped(): void {
		// PRO-1537: first_name/last_name are checkout-attacker-controlled —
		// a plausible content-injection path into a transactional email.
		$order = $this->fake_order(
			array(
				'order_number'    => '<script>alert(1)</script>',
				'payment_method'  => '<b onmouseover="alert(1)">Card</b>',
				'shipping_method' => 'Click & Collect',
				'first_name'      => '<script>alert(1)</script>',
				'last_name'       => 'O\'Brien & "Sons"',
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( '&lt;script&gt;alert(1)&lt;/script&gt;', $context['order_number'] );
		self::assertSame( '&lt;b onmouseover=&quot;alert(1)&quot;&gt;Card&lt;/b&gt;', $context['payment_method'] );
		self::assertSame( 'Click &amp; Collect', $context['shipping_method'] );
		self::assertSame( '&lt;script&gt;alert(1)&lt;/script&gt;', $context['first_name'] );
		self::assertSame( 'O&#039;Brien &amp; &quot;Sons&quot;', $context['last_name'] );
	}

	public function test_product_line_text_fields_are_htmlspecialchars_escaped(): void {
		$item  = $this->fake_item(
			array(
				'name' => '<script>alert(1)</script>',
				'qty'  => 1,
				'total' => 1.0,
				'product' => $this->fake_product_with_description( '<b onmouseover="alert(1)">desc</b>' ),
			)
		);
		$order = $this->fake_order( array( 'items' => array( $item ) ) );

		$context = $this->builder()->build( $order );

		self::assertSame( '&lt;script&gt;alert(1)&lt;/script&gt;', $context['product_name_1'] );
		self::assertSame( '&lt;b onmouseover=&quot;alert(1)&quot;&gt;desc&lt;/b&gt;', $context['product_description_1'] );
	}

	public function test_every_product_slot_is_prefilled_empty_for_template_parity(): void {
		$order = $this->fake_order( array( 'items' => array() ) );

		$context = $this->builder()->build( $order );

		foreach ( array( 'product_name', 'product_sku', 'product_quantity', 'product_price', 'product_base_price', 'product_description', 'product_image_url', 'product_url' ) as $key ) {
			for ( $i = 1; $i <= 10; $i++ ) {
				self::assertArrayHasKey( $key . '_' . $i, $context );
				self::assertSame( '', $context[ $key . '_' . $i ] );
			}
		}
		self::assertArrayNotHasKey( 'over_10_products', $context );
	}

	public function test_product_line_uses_gross_paid_price_not_live_product_price(): void {
		// 2 units, net subtotal 20.00 (+4.80 tax) discounted to net total
		// 18.00 (+4.32 tax): base_price (pre-discount unit) = (20+4.80)/2 =
		// 12.40; price (paid unit) = (18+4.32)/2 = 11.16.
		$item  = $this->fake_item(
			array(
				'name'         => 'Dog food',
				'sku'          => 'DOG-1',
				'qty'          => 2,
				'subtotal'     => 20.00,
				'subtotal_tax' => 4.80,
				'total'        => 18.00,
				'total_tax'    => 4.32,
			)
		);
		$order = $this->fake_order( array( 'items' => array( $item ) ) );

		$context = $this->builder()->build( $order );

		self::assertSame( 'Dog food', $context['product_name_1'] );
		self::assertSame( 'DOG-1', $context['product_sku_1'] );
		self::assertSame( '2', $context['product_quantity_1'] );
		self::assertSame( 'display:11.16', $context['product_price_1'] );
		self::assertSame( 'display:12.4', $context['product_base_price_1'] );
	}

	public function test_deleted_product_line_still_fills_from_the_frozen_item_snapshot(): void {
		$item  = $this->fake_item(
			array(
				'name'    => 'Gone product',
				'qty'     => 1,
				'total'   => 5.00,
				'product' => null, // simulate wc_get_product() returning nothing.
			)
		);
		$order = $this->fake_order( array( 'items' => array( $item ) ) );

		$context = $this->builder()->build( $order );

		self::assertSame( 'Gone product', $context['product_name_1'], 'The order-item name is frozen — survives a deleted product.' );
		self::assertSame( '', $context['product_sku_1'], 'No live product → sku/description/image stay empty rather than fatal.' );
		self::assertSame( '', $context['product_description_1'] );
	}

	public function test_each_product_line_links_to_its_plain_product_page(): void {
		// PRO-3335: a template can link each ordered product to its page.
		$order = $this->fake_order(
			array(
				'items' => array(
					$this->fake_item( array( 'name' => 'Dog food', 'qty' => 1, 'total' => 5.0, 'product' => $this->fake_product( 'DOG-1', 'https://shop.example.test/product/dog-food/' ) ) ),
					$this->fake_item( array( 'name' => 'Cat food', 'qty' => 2, 'total' => 8.0, 'product' => $this->fake_product( 'CAT-1', 'https://shop.example.test/product/cat-food/' ) ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( 'https://shop.example.test/product/dog-food/', $context['product_url_1'], 'The plain permalink — no tracking or campaign parameters added.' );
		self::assertSame( 'https://shop.example.test/product/cat-food/', $context['product_url_2'] );
		for ( $i = 3; $i <= 10; $i++ ) {
			self::assertSame( '', $context[ 'product_url_' . $i ], 'Unused slots carry no link, like every other product field.' );
		}
		self::assertSame( 'Dog food', $context['product_name_1'], 'The existing fields keep their names and values.' );
		self::assertSame( 'DOG-1', $context['product_sku_1'] );
	}

	public function test_a_variation_line_links_to_the_variation_that_was_bought(): void {
		$variation = $this->fake_variation( 'https://shop.example.test/product/shirt/' );
		$item      = $this->fake_item( array( 'name' => 'Shirt - Blue', 'qty' => 1, 'total' => 20.0, 'product' => $variation ) );
		$order     = $this->fake_order( array( 'items' => array( $item ) ) );

		$context = $this->builder()->build( $order );

		self::assertSame( 'https://shop.example.test/product/shirt/?attribute_pa_color=blue', $context['product_url_1'] );
		self::assertSame( $item, $variation->received_item, 'The order line is handed to WooCommerce, so the link carries the attributes the customer chose, not the defaults.' );
	}

	public function test_a_product_that_no_longer_exists_has_an_empty_link(): void {
		$order = $this->fake_order(
			array(
				'items' => array(
					$this->fake_item( array( 'name' => 'Deleted', 'qty' => 1, 'total' => 1.0, 'product' => null ) ),
					$this->fake_item( array( 'name' => 'Trashed', 'qty' => 1, 'total' => 1.0, 'product' => $this->fake_product( 'T-1', 'https://shop.example.test/?post_type=product&p=9', 'trash' ) ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( '', $context['product_url_1'], 'A deleted product: no link rather than a broken one.' );
		self::assertSame( '', $context['product_url_2'], 'A trashed product\'s page is gone for the customer too.' );
		self::assertSame( 'Deleted', $context['product_name_1'], 'The line itself still fills from the frozen snapshot.' );
	}

	public function test_over_10_products_flags_past_the_tenth_slot(): void {
		$items = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = $this->fake_item( array( 'name' => 'P' . $i, 'qty' => 1, 'total' => 1.0 ) );
		}
		$order = $this->fake_order( array( 'items' => $items ) );

		$context = $this->builder()->build( $order );

		self::assertSame( 'true', $context['over_10_products'] );
		self::assertSame( 'P10', $context['product_name_10'] );
	}

	public function test_non_product_line_items_are_skipped(): void {
		$order = $this->fake_order( array( 'items' => array( new \stdClass() ) ) );

		$context = $this->builder()->build( $order );

		self::assertSame( '', $context['product_name_1'], 'Only WC_Order_Item_Product lines fill a slot.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Builder with the WC-pricing/image seams stubbed (unit env has no WC
	 * pricing stack) — mirrors CartPayloadBuilderTest's approach.
	 */
	private function builder(): TransactionalPayloadBuilder {
		return new class extends TransactionalPayloadBuilder {
			protected function price_display( float $amount ): string {
				return 'display:' . round( $amount, 4 );
			}

			protected function product_image_url( \WC_Product $product ): string {
				return '';
			}
		};
	}

	/**
	 * @param array<string, mixed> $p
	 */
	private function fake_order( array $p ): \WC_Order {
		return new class( $p ) extends \WC_Order {
			private array $p;

			public function __construct( array $p ) {
				$this->p = $p;
			}

			public function get_order_number() {
				return (string) ( $this->p['order_number'] ?? '1' );
			}

			public function get_total( $context = 'view' ): string {
				return (string) ( $this->p['total'] ?? '0' );
			}

			public function get_currency( $context = 'view' ): string {
				return (string) ( $this->p['currency'] ?? 'EUR' );
			}

			public function get_payment_method_title( $context = 'view' ) {
				return (string) ( $this->p['payment_method'] ?? '' );
			}

			public function get_shipping_method() {
				return (string) ( $this->p['shipping_method'] ?? '' );
			}

			public function get_billing_first_name( $context = 'view' ): string {
				return (string) ( $this->p['first_name'] ?? '' );
			}

			public function get_billing_last_name( $context = 'view' ): string {
				return (string) ( $this->p['last_name'] ?? '' );
			}

			public function get_items( $types = 'line_item' ): array {
				return $this->p['items'] ?? array();
			}
		};
	}

	/**
	 * @param array<string, mixed> $p
	 */
	private function fake_item( array $p ): \WC_Order_Item_Product {
		$product = array_key_exists( 'product', $p )
			? $p['product']
			: $this->fake_product( (string) ( $p['sku'] ?? '' ) );

		return new class( $p, $product ) extends \WC_Order_Item_Product {
			private array $p;
			private $product;

			public function __construct( array $p, $product ) {
				$this->p       = $p;
				$this->product = $product;
			}

			public function get_name() {
				return (string) ( $this->p['name'] ?? '' );
			}

			public function get_product() {
				return $this->product;
			}

			public function get_quantity( $context = 'view' ) {
				return (int) ( $this->p['qty'] ?? 0 );
			}

			public function get_subtotal( $context = 'view' ) {
				return (string) ( $this->p['subtotal'] ?? $this->p['total'] ?? '0' );
			}

			public function get_subtotal_tax( $context = 'view' ) {
				return (string) ( $this->p['subtotal_tax'] ?? '0' );
			}

			public function get_total( $context = 'view' ) {
				return (string) ( $this->p['total'] ?? '0' );
			}

			public function get_total_tax( $context = 'view' ) {
				return (string) ( $this->p['total_tax'] ?? '0' );
			}
		};
	}

	private function fake_product( string $sku, string $url = '', string $status = 'publish' ): \WC_Product {
		return new class( $sku, $url, $status ) extends \WC_Product {
			private string $sku;
			private string $url;
			private string $status;

			public function __construct( string $sku, string $url, string $status ) {
				$this->sku    = $sku;
				$this->url    = $url;
				$this->status = $status;
			}

			public function get_sku( $context = 'view' ) {
				return $this->sku;
			}

			public function get_status( $context = 'view' ) {
				return $this->status;
			}

			public function get_permalink() {
				return $this->url;
			}

			public function get_description( $context = 'view' ) {
				return '';
			}

			public function get_gallery_image_ids() {
				return array();
			}
		};
	}

	private function fake_product_with_description( string $description ): \WC_Product {
		return new class( $description ) extends \WC_Product {
			private string $description;

			public function __construct( string $description ) {
				$this->description = $description;
			}

			public function get_sku( $context = 'view' ) {
				return '';
			}

			public function get_description( $context = 'view' ) {
				return $this->description;
			}

			public function get_status( $context = 'view' ) {
				return 'publish';
			}

			public function get_permalink() {
				return '';
			}

			public function get_gallery_image_ids() {
				return array();
			}
		};
	}

	/**
	 * A variation whose permalink, like WooCommerce's, turns the order line it
	 * is given into the chosen attributes; records the line it received.
	 */
	private function fake_variation( string $parent_url ): \WC_Product {
		if ( ! class_exists( \WC_Product_Variation::class ) ) {
			// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
			eval( 'class WC_Product_Variation extends WC_Product {}' );
		}

		return new class( $parent_url ) extends \WC_Product_Variation {
			/** @var mixed */
			public $received_item = null;
			private string $parent_url;

			public function __construct( string $parent_url ) {
				$this->parent_url = $parent_url;
			}

			public function get_sku( $context = 'view' ) {
				return '';
			}

			public function get_description( $context = 'view' ) {
				return '';
			}

			public function get_status( $context = 'view' ) {
				return 'publish';
			}

			public function get_permalink( $item_object = null ) {
				$this->received_item = $item_object;
				return $item_object instanceof \WC_Order_Item_Product
					? $this->parent_url . '?attribute_pa_color=blue'
					: $this->parent_url;
			}
		};
	}
}
