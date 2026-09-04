<?php
/**
 * Builds the Smaily message/send `context` merge-tag payload for an order.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Order → the `context` object POSTed to `message/send.php` (PRO-1504
 * Stage 2). Follows the SAME template-parity shape CartPayloadBuilder
 * established for abandoned-cart merge tags — a `product_<field>_1..10`
 * matrix (prefilled '' per slot) plus `over_10_products`, so a merchant
 * reusing similar Smaily template blocks gets consistent tag names.
 *
 * Differs from CartPayloadBuilder because the source is a placed ORDER, not
 * a live cart:
 *   - product name/quantity come from the frozen WC_Order_Item_Product
 *     snapshot (survives the product being edited/deleted later), not a
 *     live wc_get_product() read;
 *   - prices are what the customer actually PAID (the order line, gross —
 *     PRO-1241), not the product's current live price;
 *   - image/description still need a live wc_get_product() lookup (order
 *     items don't snapshot those) and are omitted when the product is gone.
 *
 * Money fields are GROSS (PRO-1241): $order->get_total() is already gross
 * (products + shipping + tax − discounts, as charged) — do NOT add tax
 * again here, unlike an order ITEM's get_total() which is net and needs
 * + get_total_tax() (see product_fields()).
 *
 * Not final: unit tests subclass to stub the price-display seam (needs a
 * real WC pricing stack), mirroring CartPayloadBuilder.
 */
class TransactionalPayloadBuilder {

	/** Every product key is prefilled '' for slots 1..10 (legacy Smaily-template parity, cap: ProductMatrixBuilder::MAX_PRODUCTS). */
	private const PRODUCT_KEYS = array(
		'product_name',
		'product_sku',
		'product_quantity',
		'product_price',
		'product_base_price',
		'product_description',
		'product_image_url',
	);

	/**
	 * Build the `context` object for one order.
	 *
	 * @return array<string, string>
	 */
	public function build( \WC_Order $order ): array {
		$context = array(
			'order_number'    => $this->escape( (string) $order->get_order_number() ),
			// Already gross (products + shipping + tax − discounts, as
			// charged) — do not add get_total_tax() on top (PRO-1241).
			'order_total'     => $this->price_display( (float) $order->get_total() ),
			'currency'        => $this->currency( $order ),
			'payment_method'  => $this->escape( (string) $order->get_payment_method_title() ),
			'shipping_method' => $this->escape( (string) $order->get_shipping_method() ),
			'first_name'      => $this->escape( (string) $order->get_billing_first_name() ),
			'last_name'       => $this->escape( (string) $order->get_billing_last_name() ),
		);

		return $context + $this->product_fields( $order );
	}

	/**
	 * The `product_<field>_1..10` matrix, mirroring CartPayloadBuilder's
	 * legacy-parity shape: every slot prefilled '', filled per order line,
	 * `over_10_products` flagged past slot 10.
	 *
	 * @return array<string, string>
	 */
	private function product_fields( \WC_Order $order ): array {
		$fields = ProductMatrixBuilder::prefill( self::PRODUCT_KEYS );

		$valid_items = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$valid_items[] = $item;
			}
		}

		return ProductMatrixBuilder::fill(
			$fields,
			$valid_items,
			function ( \WC_Order_Item_Product $item ): array {
				$qty = (int) $item->get_quantity();
				// GROSS (PRO-1241): the order item's get_total()/get_subtotal()
				// are NET — add the tax share, same basis as OrderPayloadBuilder.
				$total_gross    = (float) $item->get_total() + (float) $item->get_total_tax();
				$subtotal_gross = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();

				$product = $item->get_product();

				return array(
					'product_name'        => $this->escape( (string) $item->get_name() ),
					'product_sku'         => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
					'product_quantity'    => (string) $qty,
					'product_price'       => $qty > 0 ? $this->price_display( $total_gross / $qty ) : '',
					'product_base_price'  => $qty > 0 ? $this->price_display( $subtotal_gross / $qty ) : '',
					'product_description' => $product instanceof \WC_Product ? $this->escape( (string) $product->get_description() ) : '',
					'product_image_url'   => $product instanceof \WC_Product ? $this->product_image_url( $product ) : '',
				);
			}
		);
	}

	/**
	 * Display-formatted price (currency-symboled, HTML-tag-stripped) — ready
	 * to drop straight into an email merge tag. Protected seam: needs a real
	 * WC pricing stack, so unit tests stub it (mirrors CartPayloadBuilder).
	 */
	protected function price_display( float $amount ): string {
		$price = wc_price( $amount );

		return wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Featured image, else first gallery image (CartPayloadBuilder parity).
	 * Thin wrapper over the shared ProductMatrixBuilder fallback — kept as
	 * its own protected method so unit tests can still stub this exact seam.
	 */
	protected function product_image_url( \WC_Product $product ): string {
		return ProductMatrixBuilder::image_url( $product );
	}

	private function currency( \WC_Order $order ): string {
		$currency = trim( (string) $order->get_currency() );
		return $currency !== '' ? $currency : 'EUR';
	}

	/**
	 * HTML-escape a merge-tag text value before it goes into the `context`
	 * object — same treatment CartPayloadBuilder::product_fields() applies
	 * to its product-derived values (PRO-1537: checkout-attacker-controlled
	 * fields like first_name/last_name reached message/send.php raw).
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	}
}
