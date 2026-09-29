<?php
/**
 * Shared `product_<field>_1..10` matrix control flow + image fallback.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * CartPayloadBuilder (abandoned-cart) and TransactionalPayloadBuilder (order
 * emails) both build a legacy-Smaily-template-parity `product_<field>_1..10`
 * matrix — every slot prefilled '', filled per source item up to
 * MAX_PRODUCTS, `over_10_products` flagged past the cap — and both fall back
 * to the same featured-image-else-first-gallery-image lookup. This class is
 * the one place that control flow + fallback live; each builder keeps its
 * OWN per-field value logic (which fields exist, how each is computed,
 * escaping) and calls in here only for the shared parts.
 */
final class ProductMatrixBuilder {

	/** Legacy/template parity: at most 10 product slots on the wire. */
	public const MAX_PRODUCTS = 10;

	/**
	 * Prefill product_<key>_1..10 = '' for every key — the legacy Smaily API
	 * requires all fields present on every send.
	 *
	 * @param array<int, string> $keys
	 *
	 * @return array<string, string>
	 */
	public static function prefill( array $keys ): array {
		$fields = array();
		foreach ( $keys as $key ) {
			for ( $i = 1; $i <= self::MAX_PRODUCTS; $i++ ) {
				$fields[ $key . '_' . $i ] = '';
			}
		}
		return $fields;
	}

	/**
	 * Fill a prefilled matrix from a list of already-validated source items:
	 * slot 1..MAX_PRODUCTS each get $compute()'s per-slot values merged in
	 * (base field name, no slot suffix — this appends it); anything past the
	 * cap flags `over_10_products` and stops. Item validity/lookup (e.g.
	 * wc_get_product(), an instanceof check) is the caller's job — $valid_items
	 * must already be the filtered, slot-eligible list.
	 *
	 * @param array<string, string>                       $fields
	 * @param iterable<mixed>                              $valid_items
	 * @param callable(mixed): array<string, string>       $compute
	 *
	 * @return array<string, string>
	 */
	public static function fill( array $fields, iterable $valid_items, callable $compute ): array {
		$slot = 1;
		foreach ( $valid_items as $item ) {
			if ( $slot > self::MAX_PRODUCTS ) {
				$fields['over_10_products'] = 'true';
				break;
			}

			foreach ( $compute( $item ) as $key => $value ) {
				$fields[ $key . '_' . $slot ] = $value;
			}

			++$slot;
		}
		return $fields;
	}

	/**
	 * Featured image, else first gallery image — shared fallback both
	 * builders use identically.
	 */
	public static function image_url( \WC_Product $product ): string {
		$image_id = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			$url = wp_get_attachment_url( $image_id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		$gallery = $product->get_gallery_image_ids();
		if ( is_array( $gallery ) && $gallery !== array() ) {
			$url = wp_get_attachment_url( (int) $gallery[0] );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * The product page link both builders send as `product_url` (PRO-3335):
	 * the plain permalink — no tracking or campaign parameters. Empty unless
	 * the product is published, so a trashed/draft/private product never puts
	 * a dead link into a customer's email. A variation links to its product
	 * page with its attributes preselected; given the order line, the
	 * attributes are the ones the customer actually chose (WooCommerce's own
	 * order-details template passes the order item the same way).
	 */
	public static function product_url( \WC_Product $product, ?\WC_Order_Item_Product $item = null ): string {
		if ( $product->get_status() !== 'publish' ) {
			return '';
		}

		if ( $product instanceof \WC_Product_Variation ) {
			// @phpstan-ignore-next-line -- WC_Order_Item is ArrayAccess; WC core passes an order item here (templates/order/order-details-item.php).
			return (string) $product->get_permalink( $item );
		}

		// (string): core get_permalink() can return false despite the stubs.
		return (string) $product->get_permalink();
	}
}
