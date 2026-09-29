<?php
/**
 * Builds the Smaily message/send `context` merge-tag payload for an order.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Support\DebugLog;

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
 *   - image/description/url still need a live wc_get_product() lookup (order
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

	/**
	 * The merchant's switch for the personal-data fields — addresses, phone,
	 * delivery name, order note (PRO-3190). Off by default: none of those
	 * keys is sent until the merchant opts in on the WooCommerce tab.
	 */
	public const OPTION_PERSONAL_DATA = 'smly_plus_transactional_personal_data_enabled';

	/**
	 * The keys that switch governs. Reserved even while it is off, so a
	 * merchant's extra-fields filter can't send them behind its back.
	 */
	public const PERSONAL_DATA_KEYS = array(
		'shipping_first_name',
		'shipping_last_name',
		'billing_address_1',
		'billing_address_2',
		'billing_postcode',
		'billing_city',
		'billing_country',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_postcode',
		'shipping_city',
		'shipping_country',
		'billing_phone',
		'customer_note',
	);

	/**
	 * Merchant extras (PRO-3190). Order-level: ( array $fields = [],
	 * WC_Order $order, string $trigger ); per product line: ( array $fields
	 * = [], WC_Order_Item_Product $item, WC_Order $order ), keys starting
	 * with `product_`, sent as `<key>_1..10`. See clean_extras() for the
	 * rules an entry must meet.
	 */
	public const FILTER_FIELDS         = 'smaily_connect_transactional_email_fields';
	public const FILTER_PRODUCT_FIELDS = 'smaily_connect_transactional_email_product_fields';

	private const EXTRA_KEY_PATTERN  = '/^[a-z][a-z0-9_]{0,63}$/';
	private const EXTRA_MAX_KEYS     = 20;
	private const EXTRA_VALUE_LENGTH = 1000;

	/** Every product key is prefilled '' for slots 1..10 (legacy Smaily-template parity, cap: ProductMatrixBuilder::MAX_PRODUCTS). */
	private const PRODUCT_KEYS = array(
		'product_name',
		'product_sku',
		'product_quantity',
		'product_price',
		'product_base_price',
		'product_description',
		'product_image_url',
		'product_url',
		'product_discount_percent',
	);

	/**
	 * Build the `context` object for one order.
	 *
	 * @param string $trigger The email being built (TransactionalGate::TRIGGER_*),
	 *                        handed to the merchant's extra-fields filter.
	 *
	 * @return array<string, string>
	 */
	public function build( \WC_Order $order, string $trigger = '' ): array {
		$items = $this->product_items( $order );

		// Every order-level money field is GROSS, like order_total (PRO-1241):
		// the subtotal is the product lines after discounts, the shipping
		// carries its tax.
		$total    = (float) $order->get_total();
		$subtotal = 0.0;
		foreach ( $items as $item ) {
			$subtotal += (float) $item->get_total() + (float) $item->get_total_tax();
		}
		$tax      = (float) $order->get_total_tax();
		$shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		$status   = (string) $order->get_status();

		$context = array(
			'order_number'       => $this->escape( (string) $order->get_order_number() ),
			// Already gross (products + shipping + tax − discounts, as
			// charged) — do not add get_total_tax() on top (PRO-1241).
			'order_total'        => $this->price_display( $total ),
			'currency'           => $this->currency( $order ),
			'payment_method'     => $this->escape( (string) $order->get_payment_method_title() ),
			'shipping_method'    => $this->escape( (string) $order->get_shipping_method() ),
			'first_name'         => $this->escape( (string) $order->get_billing_first_name() ),
			'last_name'          => $this->escape( (string) $order->get_billing_last_name() ),
			// PRO-3190: the rest of what a real confirmation shows.
			'order_subtotal'     => $this->price_display( $subtotal ),
			'order_tax'          => $this->price_display( $tax ),
			'order_shipping'     => $this->price_display( $shipping ),
			'order_total_raw'    => $this->raw_amount( $total ),
			'order_subtotal_raw' => $this->raw_amount( $subtotal ),
			'order_tax_raw'      => $this->raw_amount( $tax ),
			'order_shipping_raw' => $this->raw_amount( $shipping ),
			'order_status'       => $this->escape( (string) wc_get_order_status_name( $status ) ),
			'order_status_id'    => $this->escape( $status ),
			'payment_method_id'  => $this->escape( (string) $order->get_payment_method() ),
			'shipping_method_id' => $this->escape( $this->shipping_method_ids( $order ) ),
		);

		if ( (bool) get_option( self::OPTION_PERSONAL_DATA, false ) ) {
			$context += $this->personal_data_fields( $order );
		}

		$context += $this->product_fields( $order, $items );

		// Built-in keys always win: an extra may use none of them, nor a
		// personal-data or over_10_products key that is absent right now.
		$reserved = $context + array_fill_keys( self::PERSONAL_DATA_KEYS, '' ) + array( 'over_10_products' => '' );
		$accepted = array();

		return $context + $this->clean_extras(
			self::FILTER_FIELDS,
			static function () use ( $order, $trigger ) {
				return apply_filters( self::FILTER_FIELDS, array(), $order, $trigger );
			},
			$reserved,
			'',
			$accepted
		);
	}

	/**
	 * Addresses, phone, delivery name and the customer's order note — sent
	 * only while the merchant's switch is on (PRO-3190). Every key is present
	 * then, an unknown value as '' (e.g. no delivery address on a pickup
	 * order), so a template can test it. The billing name is already
	 * first_name/last_name.
	 *
	 * @return array<string, string>
	 */
	private function personal_data_fields( \WC_Order $order ): array {
		return array(
			'shipping_first_name' => $this->escape( (string) $order->get_shipping_first_name() ),
			'shipping_last_name'  => $this->escape( (string) $order->get_shipping_last_name() ),
			'billing_address_1'   => $this->escape( (string) $order->get_billing_address_1() ),
			'billing_address_2'   => $this->escape( (string) $order->get_billing_address_2() ),
			'billing_postcode'    => $this->escape( (string) $order->get_billing_postcode() ),
			'billing_city'        => $this->escape( (string) $order->get_billing_city() ),
			'billing_country'     => $this->escape( $this->country_name( (string) $order->get_billing_country() ) ),
			'shipping_address_1'  => $this->escape( (string) $order->get_shipping_address_1() ),
			'shipping_address_2'  => $this->escape( (string) $order->get_shipping_address_2() ),
			'shipping_postcode'   => $this->escape( (string) $order->get_shipping_postcode() ),
			'shipping_city'       => $this->escape( (string) $order->get_shipping_city() ),
			'shipping_country'    => $this->escape( $this->country_name( (string) $order->get_shipping_country() ) ),
			'billing_phone'       => $this->escape( (string) $order->get_billing_phone() ),
			'customer_note'       => $this->escape( (string) $order->get_customer_note() ),
		);
	}

	/**
	 * The country's name as WooCommerce shows it, else the code as stored.
	 * Decoded, because WooCommerce writes some names with HTML entities
	 * (`R&eacute;union`) and the caller escapes the result once.
	 */
	private function country_name( string $code ): string {
		if ( $code === '' ) {
			return '';
		}
		$countries = WC()->countries->get_countries();
		return isset( $countries[ $code ] )
			? html_entity_decode( (string) $countries[ $code ], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8' )
			: $code;
	}

	/**
	 * The `product_<field>_1..10` matrix, mirroring CartPayloadBuilder's
	 * legacy-parity shape: every slot prefilled '', filled per order line,
	 * `over_10_products` flagged past slot 10.
	 *
	 * A merchant's per-line extras join the matrix: every slot prefilled ''
	 * for each extra key any line returned.
	 *
	 * @param array<int, \WC_Order_Item_Product> $valid_items
	 *
	 * @return array<string, string>
	 */
	private function product_fields( \WC_Order $order, array $valid_items ): array {
		$reserved   = array_fill_keys( self::PRODUCT_KEYS, '' );
		$extra_keys = array();
		$extras     = array();
		foreach ( array_slice( $valid_items, 0, ProductMatrixBuilder::MAX_PRODUCTS ) as $item ) {
			$extras[] = $this->clean_extras(
				self::FILTER_PRODUCT_FIELDS,
				static function () use ( $item, $order ) {
					return apply_filters( self::FILTER_PRODUCT_FIELDS, array(), $item, $order );
				},
				$reserved,
				'product_',
				$extra_keys
			);
		}

		$fields = ProductMatrixBuilder::prefill( array_merge( self::PRODUCT_KEYS, array_keys( $extra_keys ) ) );
		$slot   = 0;

		return ProductMatrixBuilder::fill(
			$fields,
			$valid_items,
			function ( \WC_Order_Item_Product $item ) use ( $extras, &$slot ): array {
				$line_extras = $extras[ $slot ] ?? array();
				++$slot;

				$qty = (int) $item->get_quantity();
				// GROSS (PRO-1241): the order item's get_total()/get_subtotal()
				// are NET — add the tax share, same basis as OrderPayloadBuilder.
				$total_gross    = (float) $item->get_total() + (float) $item->get_total_tax();
				$subtotal_gross = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();

				$product = $item->get_product();

				return array(
					'product_name'             => $this->escape( (string) $item->get_name() ),
					'product_sku'              => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
					'product_quantity'         => (string) $qty,
					'product_price'            => $qty > 0 ? $this->price_display( $total_gross / $qty ) : '',
					'product_base_price'       => $qty > 0 ? $this->price_display( $subtotal_gross / $qty ) : '',
					'product_description'      => $product instanceof \WC_Product ? $this->escape( (string) $product->get_description() ) : '',
					'product_image_url'        => $product instanceof \WC_Product ? $this->product_image_url( $product ) : '',
					'product_url'              => $product instanceof \WC_Product ? ProductMatrixBuilder::product_url( $product, $item ) : '',
					'product_discount_percent' => $this->discount_percent( $total_gross, $subtotal_gross ),
				) + $line_extras;
			}
		);
	}

	/**
	 * Run one merchant extra-fields filter and keep only what is safe to
	 * send (PRO-3190). An entry is dropped — and logged — when its key does
	 * not match EXTRA_KEY_PATTERN or lacks $prefix, is a built-in ($reserved),
	 * its value is not a scalar, or the filter has already given
	 * EXTRA_MAX_KEYS keys. A kept value is cut to EXTRA_VALUE_LENGTH
	 * characters and escaped like every other text field. A filter that
	 * throws adds nothing: a broken snippet must not break checkout or the
	 * send.
	 *
	 * @param callable(): mixed    $run      Applies the filter.
	 * @param array<string, mixed> $reserved Keys an extra may not use.
	 * @param array<string, true>  $accepted Keys this filter has given so far (across calls).
	 *
	 * @return array<string, string>
	 */
	private function clean_extras( string $hook, callable $run, array $reserved, string $prefix, array &$accepted ): array {
		try {
			$raw = $run();
		} catch ( \Throwable $e ) {
			DebugLog::write( sprintf( '[smaily-connect transactional] %s failed and was ignored: %s', $hook, $e->getMessage() ) );
			return array();
		}

		if ( ! is_array( $raw ) ) {
			DebugLog::write( sprintf( '[smaily-connect transactional] %s returned %s, not an array; ignored', $hook, gettype( $raw ) ) );
			return array();
		}

		$clean = array();
		foreach ( $raw as $key => $value ) {
			$key    = (string) $key;
			$reason = '';
			if ( preg_match( self::EXTRA_KEY_PATTERN, $key ) !== 1 || strpos( $key, $prefix ) !== 0 ) {
				$reason = 'invalid key';
			} elseif ( isset( $reserved[ $key ] ) ) {
				$reason = 'a built-in field';
			} elseif ( ! is_scalar( $value ) ) {
				$reason = 'value is not a scalar';
			} elseif ( ! isset( $accepted[ $key ] ) && count( $accepted ) >= self::EXTRA_MAX_KEYS ) {
				$reason = 'more than ' . self::EXTRA_MAX_KEYS . ' fields';
			}

			if ( $reason !== '' ) {
				DebugLog::write( sprintf( '[smaily-connect transactional] %s: dropped field "%s" (%s)', $hook, substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '?', $key ), 0, 64 ), $reason ) );
				continue;
			}

			$accepted[ $key ] = true;
			$clean[ $key ]    = $this->escape( mb_substr( (string) $value, 0, self::EXTRA_VALUE_LENGTH ) );
		}

		return $clean;
	}

	/**
	 * The line's coupon discount as a whole-number percent (`"10"`) — what
	 * product_price is below product_base_price; `"0"` without a discount
	 * (PRO-3190). Read from the order line only, so a sale price the product
	 * carried at the time is not a discount here.
	 */
	private function discount_percent( float $paid, float $before_discount ): string {
		if ( $before_discount <= 0.0 ) {
			return '0';
		}
		return (string) (int) round( ( 1 - $paid / $before_discount ) * 100 );
	}

	/**
	 * The order's product lines — the only lines that fill a slot or count
	 * toward order_subtotal.
	 *
	 * @return array<int, \WC_Order_Item_Product>
	 */
	private function product_items( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * The shipping lines' method codes (`flat_rate`, `local_pickup`, …),
	 * joined the way WooCommerce joins their titles into shipping_method,
	 * so the two fields list the lines in the same order.
	 */
	private function shipping_method_ids( \WC_Order $order ): string {
		$ids = array();
		foreach ( $order->get_shipping_methods() as $shipping ) {
			$ids[] = (string) $shipping->get_method_id();
		}
		return implode( ', ', $ids );
	}

	/**
	 * An unformatted amount (`24.90`, `0.00`) for a template that formats
	 * money itself — the store's price decimals, no currency sign.
	 */
	private function raw_amount( float $amount ): string {
		return (string) wc_format_decimal( $amount, wc_get_price_decimals() );
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
