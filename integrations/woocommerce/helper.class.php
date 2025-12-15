<?php

namespace Smaily_Connect\Integrations\WooCommerce;

class Helper {
	/**
	 * Get the current price of the product including tax, considering discount rules if active.
	 *
	 * @param \WC_Product $product
	 * @param float|null $tax_rate Tax rate as percentage.
	 * @return float
	 */
	public static function get_current_price_with_tax( $product, $tax_rate = null ) {
		if ( $tax_rate !== null ) {
			$price_excl_tax = wc_get_price_excluding_tax( $product, array( 'price' => $product->get_price() ) );
			return self::calculate_price_on_tax_rate( $price_excl_tax, $tax_rate );
		}

		$current_price_with_tax = wc_get_price_to_display( $product, array( 'price' => $product->get_price() ) );

		if ( self::is_discount_rules_for_woocommerce_active() ) {
			$discounted_price       = apply_filters( 'advanced_woo_discount_rules_get_product_discount_price', $product->get_price(), $product );
			$current_price_with_tax = wc_get_price_to_display( $product, array( 'price' => $discounted_price ) );
		}

		return $current_price_with_tax;
	}

	/**
	 * Get the regular price of the product including tax.
	 *
	 * @param \WC_Product $product
	 * @param float|null $tax_rate Tax rate as percentage.
	 * @return float
	 */
	public static function get_regular_price_with_tax( $product, $tax_rate = null ) {
		if ( $tax_rate !== null ) {
			$price_excl_tax = wc_get_price_excluding_tax( $product, array( 'price' => $product->get_regular_price() ) );
			return self::calculate_price_on_tax_rate( $price_excl_tax, $tax_rate );
		}

		return wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
	}

	/**
	 * Calculates discount percentage between the current price and the regular price.
	 *
	 * @param float $current_price
	 * @param float $regular_price
	 * @return float
	 */
	public static function calculate_discount( $current_price, $regular_price ) {
		if ( $current_price >= $regular_price ) {
			return 0.0;
		}

		if ( $regular_price > 0 ) {
			return round( 100 - ( $current_price / $regular_price * 100 ), 2 );
		}

		return 0.0;
	}

	/**
	 * Check if Discount Rules for WooCommerce is active.
	 *
	 * @return bool True if Discount Rules for WooCommerce is active, false otherwise.
	 */
	private static function is_discount_rules_for_woocommerce_active() {
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'woo-discount-rules/woo-discount-rules.php' );
		} else {
			return false;
		}
	}

	/**
	 * Calculates the price price based on the given tax rate.
	 * This is used when the tax rate is provided externally rather than relying on WooCommerce's tax settings.
	 *
	 * @param float $base_price
	 * @param float $tax_rate
	 * @return float
	 */
	private static function calculate_price_on_tax_rate( $base_price, $tax_rate ) {
		$tax_multiplier = 1 + ( $tax_rate / 100 );

		return round( $base_price * $tax_multiplier, wc_get_price_decimals() );
	}
}
