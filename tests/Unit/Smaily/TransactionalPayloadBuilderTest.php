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

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\TransactionalPayloadBuilder;

final class TransactionalPayloadBuilderTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		$opts = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$opts ) {
				return $opts[ $key ] ?? $fallback;
			}
		);
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'wc_format_decimal' )->alias(
			static function ( $number, $dp ) {
				return number_format( (float) $number, (int) $dp, '.', '' );
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ) {
				$names = array(
					'processing' => 'Processing',
					'completed'  => 'Completed',
				);
				return $names[ $status ] ?? $status;
			}
		);
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'countries' => new class() {
					/** @return array<string, string> */
					public function get_countries(): array {
						return array(
							'EE' => 'Estonia',
							'RE' => 'R&eacute;union',
						);
					}
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

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

	public function test_subtotal_tax_and_shipping_are_formatted_like_the_total(): void {
		// PRO-3190. Two lines: one discounted by a coupon (net 18.00 + 4.32
		// tax after the discount, 24.80 before), one not (net 5.00 + 1.20).
		// Shipping 3.00 + 0.72 tax. All gross, all through the same display
		// formatting as order_total.
		$order = $this->fake_order(
			array(
				'total'          => 32.24,
				'total_tax'      => 6.24,
				'shipping_total' => 3.00,
				'shipping_tax'   => 0.72,
				'items'          => array(
					$this->fake_item( array( 'name' => 'A', 'qty' => 2, 'subtotal' => 20.00, 'subtotal_tax' => 4.80, 'total' => 18.00, 'total_tax' => 4.32 ) ),
					$this->fake_item( array( 'name' => 'B', 'qty' => 1, 'total' => 5.00, 'total_tax' => 1.20 ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( 'display:32.24', $context['order_total'] );
		self::assertSame( 'display:28.52', $context['order_subtotal'], 'After discounts, tax included: 18.00 + 4.32 + 5.00 + 1.20.' );
		self::assertSame( 'display:6.24', $context['order_tax'] );
		self::assertSame( 'display:3.72', $context['order_shipping'], 'Shipping carries its tax, like every other amount.' );
	}

	public function test_unformatted_amounts_are_sent_and_free_shipping_reads_as_zero(): void {
		$order = $this->fake_order(
			array(
				'total'     => 24.9,
				'total_tax' => 4.82,
				'items'     => array(
					$this->fake_item( array( 'name' => 'A', 'qty' => 1, 'total' => 20.08, 'total_tax' => 4.82 ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( '24.90', $context['order_total_raw'] );
		self::assertSame( '24.90', $context['order_subtotal_raw'] );
		self::assertSame( '4.82', $context['order_tax_raw'] );
		self::assertSame( '0.00', $context['order_shipping_raw'], 'Free shipping is a zero, not an empty field.' );
		self::assertSame( 'display:0', $context['order_shipping'] );
	}

	public function test_status_is_sent_as_the_shown_name_and_as_the_code(): void {
		$context = $this->builder()->build( $this->fake_order( array( 'status' => 'processing' ) ) );

		self::assertSame( 'Processing', $context['order_status'] );
		self::assertSame( 'processing', $context['order_status_id'] );
	}

	public function test_payment_and_shipping_method_codes_sit_next_to_the_titles(): void {
		$order = $this->fake_order(
			array(
				'payment_method'      => 'Bank transfer',
				'payment_method_id'   => 'bacs',
				'shipping_method'     => 'Flat rate, Local pickup',
				'shipping_method_ids' => array( 'flat_rate', 'local_pickup' ),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( 'Bank transfer', $context['payment_method'] );
		self::assertSame( 'bacs', $context['payment_method_id'] );
		self::assertSame( 'Flat rate, Local pickup', $context['shipping_method'] );
		self::assertSame( 'flat_rate, local_pickup', $context['shipping_method_id'], 'Joined like the titles, so the two list the lines in the same order.' );
	}

	public function test_existing_fields_keep_their_names_and_formats(): void {
		// PRO-3190 adds fields only: a template written against the fields
		// sent before it keeps working unchanged.
		$order = $this->fake_order(
			array(
				'order_number'    => '1001',
				'total'           => 24.9,
				'payment_method'  => 'Card',
				'shipping_method' => 'Courier',
				'first_name'      => 'Test',
				'last_name'       => 'Shopper',
				'items'           => array(
					$this->fake_item( array( 'name' => 'A', 'sku' => 'A-1', 'qty' => 2, 'total' => 10.0 ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame(
			array(
				'order_number'    => '1001',
				'order_total'     => 'display:24.9',
				'currency'        => 'EUR',
				'payment_method'  => 'Card',
				'shipping_method' => 'Courier',
				'first_name'      => 'Test',
				'last_name'       => 'Shopper',
			),
			array_slice( $context, 0, 7, true )
		);
		self::assertSame( 'A', $context['product_name_1'] );
		self::assertSame( 'A-1', $context['product_sku_1'] );
		self::assertSame( '2', $context['product_quantity_1'] );
		self::assertSame( 'display:5', $context['product_price_1'] );
		self::assertSame( 'display:5', $context['product_base_price_1'] );
		self::assertSame( '', $context['product_name_2'], 'An unused slot is still sent empty.' );
		self::assertArrayNotHasKey( 'over_10_products', $context, 'Still absent at 10 products or fewer.' );
	}

	public function test_by_default_no_address_phone_or_note_is_sent(): void {
		// PRO-3190: the personal-data switch is off until the merchant opts in.
		$context = $this->builder()->build( $this->fake_order( $this->order_with_personal_data() ) );

		foreach ( TransactionalPayloadBuilder::PERSONAL_DATA_KEYS as $key ) {
			self::assertArrayNotHasKey( $key, $context );
		}
		foreach ( $context as $value ) {
			self::assertStringNotContainsString( 'Test Street', $value );
			self::assertStringNotContainsString( '+000', $value );
			self::assertStringNotContainsString( 'Leave at the door', $value );
		}
	}

	public function test_with_the_switch_on_the_delivery_name_both_addresses_phone_and_note_are_sent(): void {
		$this->options[ TransactionalPayloadBuilder::OPTION_PERSONAL_DATA ] = true;

		$context = $this->builder()->build( $this->fake_order( $this->order_with_personal_data() ) );

		self::assertSame( 'Delivery', $context['shipping_first_name'] );
		self::assertSame( 'Receiver', $context['shipping_last_name'] );
		self::assertSame( 'Test Street 1', $context['billing_address_1'] );
		self::assertSame( 'Apt 2', $context['billing_address_2'] );
		self::assertSame( '00000', $context['billing_postcode'] );
		self::assertSame( 'Testville', $context['billing_city'] );
		self::assertSame( 'Estonia', $context['billing_country'], 'The country as its shown name, not the code.' );
		self::assertSame( 'Test Street 9 &amp; Co', $context['shipping_address_1'], 'Escaped like every other text field.' );
		self::assertSame( '', $context['shipping_address_2'] );
		self::assertSame( '11111', $context['shipping_postcode'] );
		self::assertSame( 'Othertown', $context['shipping_city'] );
		self::assertSame( 'XX', $context['shipping_country'], 'A code WooCommerce has no name for is sent as the code.' );
		$order_data                        = $this->order_with_personal_data();
		$order_data['shipping']['country'] = 'RE';
		self::assertSame( 'Réunion', $this->builder()->build( $this->fake_order( $order_data ) )['shipping_country'], 'WooCommerce\'s entity-encoded name is decoded, not double-escaped.' );
		self::assertSame( '+000 0000000', $context['billing_phone'] );
		self::assertSame( 'Leave at the door &lt;b&gt;please&lt;/b&gt;', $context['customer_note'], 'Free text, escaped.' );
		self::assertSame( 'Test', $context['first_name'], 'The billing name stays in first_name/last_name.' );

		unset( $this->options[ TransactionalPayloadBuilder::OPTION_PERSONAL_DATA ] );
		$off = $this->builder()->build( $this->fake_order( $this->order_with_personal_data() ) );
		self::assertSame( TransactionalPayloadBuilder::PERSONAL_DATA_KEYS, array_keys( array_diff_key( $context, $off ) ), 'The switch governs exactly the reserved keys.' );
	}

	public function test_with_the_switch_on_a_pickup_order_sends_the_delivery_fields_empty(): void {
		$this->options[ TransactionalPayloadBuilder::OPTION_PERSONAL_DATA ] = true;
		$order_data = $this->order_with_personal_data();
		unset( $order_data['shipping'], $order_data['customer_note'] );

		$context = $this->builder()->build( $this->fake_order( $order_data ) );

		foreach ( TransactionalPayloadBuilder::PERSONAL_DATA_KEYS as $key ) {
			self::assertArrayHasKey( $key, $context, 'Every personal-data key is present while the switch is on.' );
		}
		foreach ( array( 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2', 'shipping_postcode', 'shipping_city', 'shipping_country', 'customer_note' ) as $key ) {
			self::assertSame( '', $context[ $key ] );
		}
		self::assertSame( 'Test Street 1', $context['billing_address_1'] );
	}

	public function test_a_merchant_filter_adds_order_level_fields_but_cannot_overwrite_a_built_in_one(): void {
		$seen = array();
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_FIELDS )
			->once()
			->andReturnUsing(
				static function ( $fields, $order, $trigger ) use ( &$seen ) {
					$seen = array( $fields, $order, $trigger );
					return array(
						'loyalty_points' => 120,
						'gift_message'   => 'Happy <b>birthday</b>',
						'order_total'    => 'FREE',
						'product_name_1' => 'Hijacked',
						'billing_phone'  => '+000 0000000',
					);
				}
			);
		$order = $this->fake_order( array( 'total' => 10, 'items' => array( $this->fake_item( array( 'name' => 'A', 'qty' => 1, 'total' => 10 ) ) ) ) );

		$context = $this->builder()->build( $order, 'order_confirmation' );

		self::assertSame( array( array(), $order, 'order_confirmation' ), $seen, 'The filter gets an empty list, the order and which email is being built.' );
		self::assertSame( '120', $context['loyalty_points'] );
		self::assertSame( 'Happy &lt;b&gt;birthday&lt;/b&gt;', $context['gift_message'], 'Escaped like every built-in text field.' );
		self::assertSame( 'display:10', $context['order_total'], 'A built-in field always wins.' );
		self::assertSame( 'A', $context['product_name_1'] );
		self::assertArrayNotHasKey( 'billing_phone', $context, 'A personal-data key stays out while the switch is off, even from a filter.' );
	}

	public function test_a_merchant_filter_adds_per_product_fields_to_every_slot(): void {
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_PRODUCT_FIELDS )
			->twice()
			->andReturnUsing(
				static function ( $fields, $item, $order ) {
					unset( $fields, $order );
					return 'A' === $item->get_name()
						? array( 'product_brand' => 'Acme', 'product_colour' => 'Blue' )
						: array( 'product_brand' => 'Other' );
				}
			);
		$order = $this->fake_order(
			array(
				'items' => array(
					$this->fake_item( array( 'name' => 'A', 'qty' => 1, 'total' => 1.0 ) ),
					$this->fake_item( array( 'name' => 'B', 'qty' => 1, 'total' => 1.0 ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( 'Acme', $context['product_brand_1'] );
		self::assertSame( 'Blue', $context['product_colour_1'] );
		self::assertSame( 'Other', $context['product_brand_2'] );
		self::assertSame( '', $context['product_colour_2'], 'Every slot is prefilled for every extra key.' );
		for ( $i = 3; $i <= 10; $i++ ) {
			self::assertSame( '', $context[ 'product_brand_' . $i ] );
			self::assertSame( '', $context[ 'product_colour_' . $i ] );
		}
	}

	public function test_invalid_extra_entries_are_dropped_and_the_rest_is_kept(): void {
		$many = array();
		for ( $i = 1; $i <= 22; $i++ ) {
			$many[ 'extra_' . $i ] = 'v' . $i;
		}
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_FIELDS )->andReturn(
			array(
				'Bad-Key'      => 'x',
				'9starts'      => 'x',
				'has_array'    => array( 'x' ),
				'has_null'     => null,
				'has_object'   => new \stdClass(),
				'is_true'      => true,
				'long_text'    => str_repeat( 'a', 1200 ),
			) + $many
		);
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_PRODUCT_FIELDS )->andReturn(
			array(
				'brand'        => 'no product_ prefix',
				'product_name' => 'a built-in product field',
				'product_ok'   => 'kept',
			)
		);
		$order = $this->fake_order( array( 'items' => array( $this->fake_item( array( 'name' => 'A', 'qty' => 1, 'total' => 1.0 ) ) ) ) );

		$context = $this->builder()->build( $order );

		foreach ( array( 'Bad-Key', '9starts', 'has_array', 'has_null', 'has_object', 'brand_1', 'product_name_1_1' ) as $dropped ) {
			self::assertArrayNotHasKey( $dropped, $context );
		}
		self::assertSame( '1', $context['is_true'], 'A scalar is sent as a string.' );
		self::assertSame( 1000, strlen( $context['long_text'] ), 'Cut at 1000 characters.' );
		self::assertSame( 'v18', $context['extra_18'] );
		self::assertArrayNotHasKey( 'extra_19', $context, 'At most 20 keys per filter (is_true, long_text + 18 more).' );
		self::assertSame( 'A', $context['product_name_1'], 'The built-in product field is untouched.' );
		self::assertSame( 'kept', $context['product_ok_1'] );
	}

	public function test_a_failing_or_malformed_filter_adds_nothing_and_the_confirmation_is_still_built(): void {
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_FIELDS )->andReturnUsing(
			static function () {
				throw new \RuntimeException( 'broken snippet' );
			}
		);
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_PRODUCT_FIELDS )->andReturn( 'not an array' );
		$order = $this->fake_order( array( 'order_number' => '77', 'items' => array( $this->fake_item( array( 'name' => 'A', 'qty' => 1, 'total' => 1.0 ) ) ) ) );

		$context = $this->builder()->build( $order );

		self::assertSame( '77', $context['order_number'] );
		self::assertSame( 'A', $context['product_name_1'] );
		self::assertSame( $this->builder()->build( $this->fake_order( array( 'order_number' => '77', 'items' => array( $this->fake_item( array( 'name' => 'A', 'qty' => 1, 'total' => 1.0 ) ) ) ) ) ), $context, 'Exactly the fields a store without the filters gets.' );
	}

	public function test_an_erasure_request_blanks_the_new_personal_data_in_the_stored_send_history(): void {
		// PRO-3190 + PRO-2383: a transactional row stores its merge tags in
		// `payload.context` and again in the exchange's sent body. Redaction
		// keeps only routing keys, so every new personal-data value is
		// blanked in both — the keys stay, so the row still reads as a
		// confirmation.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->options[ TransactionalPayloadBuilder::OPTION_PERSONAL_DATA ] = true;
		$context = $this->builder()->build( $this->fake_order( $this->order_with_personal_data() ) );

		$stored = array(
			'payload'      => (string) wp_json_encode(
				array(
					'to'          => 'shopper@example.test',
					'workflow_id' => 7,
					'account_key' => 'transactional',
					'context'     => $context,
					'to_status'   => '',
				)
			),
			// The request Client::request() records for message/send.
			'sent_payload' => (string) wp_json_encode(
				array(
					'method'   => 'POST',
					'endpoint' => 'message/send',
					'body'     => array(
						'autoresponder_id' => 7,
						'to'               => array( 'shopper@example.test' ),
						'context'          => $context,
					),
				)
			),
		);

		foreach ( $stored as $column => $json ) {
			$redacted = (string) EventQueue::redact_json( $json );
			foreach ( array( 'Test Street', 'Apt 2', '00000', '11111', 'Testville', 'Othertown', 'Estonia', 'Delivery', 'Receiver', '+000', 'Leave at the door', 'shopper@example.test' ) as $value ) {
				self::assertStringNotContainsString( $value, $redacted, $column . ' still holds ' . $value );
			}
			$decoded = json_decode( $redacted, true );
			$fields  = 'payload' === $column ? $decoded['context'] : $decoded['body']['context'];
			foreach ( TransactionalPayloadBuilder::PERSONAL_DATA_KEYS as $key ) {
				self::assertSame( EventQueue::ERASED_PLACEHOLDER, $fields[ $key ], $column . '.' . $key );
			}
		}
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

		foreach ( array( 'product_name', 'product_sku', 'product_quantity', 'product_price', 'product_base_price', 'product_description', 'product_image_url', 'product_url', 'product_discount_percent' ) as $key ) {
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

	public function test_a_coupon_discounted_line_carries_its_whole_number_discount_percent(): void {
		// PRO-3190. Line 1: 24.80 before the coupon, 22.32 paid → 10 %.
		// Line 2: a third off (30.00 → 20.00) rounds to 33. Line 3: no
		// discount → "0". Slots 4..10 are unused → ''.
		$order = $this->fake_order(
			array(
				'items' => array(
					$this->fake_item( array( 'name' => 'A', 'qty' => 2, 'subtotal' => 20.00, 'subtotal_tax' => 4.80, 'total' => 18.00, 'total_tax' => 4.32 ) ),
					$this->fake_item( array( 'name' => 'B', 'qty' => 3, 'subtotal' => 30.00, 'total' => 20.00 ) ),
					$this->fake_item( array( 'name' => 'C', 'qty' => 1, 'total' => 7.50 ) ),
				),
			)
		);

		$context = $this->builder()->build( $order );

		self::assertSame( '10', $context['product_discount_percent_1'] );
		self::assertSame( '33', $context['product_discount_percent_2'] );
		self::assertSame( '0', $context['product_discount_percent_3'], 'A filled slot without a discount says 0.' );
		for ( $i = 4; $i <= 10; $i++ ) {
			self::assertSame( '', $context[ 'product_discount_percent_' . $i ], 'An unused slot stays empty.' );
		}
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
	 * Placeholder personal data only — nothing here resembles a real person.
	 *
	 * @return array<string, mixed>
	 */
	private function order_with_personal_data(): array {
		return array(
			'first_name'    => 'Test',
			'last_name'     => 'Shopper',
			'customer_note' => 'Leave at the door <b>please</b>',
			'billing'       => array(
				'address_1' => 'Test Street 1',
				'address_2' => 'Apt 2',
				'postcode'  => '00000',
				'city'      => 'Testville',
				'country'   => 'EE',
				'phone'     => '+000 0000000',
			),
			'shipping'      => array(
				'first_name' => 'Delivery',
				'last_name'  => 'Receiver',
				'address_1'  => 'Test Street 9 & Co',
				'postcode'   => '11111',
				'city'       => 'Othertown',
				'country'    => 'XX',
			),
		);
	}

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

			public function get_total_tax( $context = 'view' ) {
				return (string) ( $this->p['total_tax'] ?? '0' );
			}

			public function get_shipping_total( $context = 'view' ) {
				return (string) ( $this->p['shipping_total'] ?? '0' );
			}

			public function get_shipping_tax( $context = 'view' ) {
				return (string) ( $this->p['shipping_tax'] ?? '0' );
			}

			public function get_status( $context = 'view' ): string {
				return (string) ( $this->p['status'] ?? 'processing' );
			}

			public function get_payment_method( $context = 'view' ) {
				return (string) ( $this->p['payment_method_id'] ?? '' );
			}

			public function get_customer_note( $context = 'view' ) {
				return (string) ( $this->p['customer_note'] ?? '' );
			}

			public function get_billing_phone( $context = 'view' ) {
				return (string) ( $this->p['billing']['phone'] ?? '' );
			}

			public function get_billing_address_1( $context = 'view' ) {
				return (string) ( $this->p['billing']['address_1'] ?? '' );
			}

			public function get_billing_address_2( $context = 'view' ) {
				return (string) ( $this->p['billing']['address_2'] ?? '' );
			}

			public function get_billing_postcode( $context = 'view' ) {
				return (string) ( $this->p['billing']['postcode'] ?? '' );
			}

			public function get_billing_city( $context = 'view' ) {
				return (string) ( $this->p['billing']['city'] ?? '' );
			}

			public function get_billing_country( $context = 'view' ) {
				return (string) ( $this->p['billing']['country'] ?? '' );
			}

			public function get_shipping_first_name( $context = 'view' ) {
				return (string) ( $this->p['shipping']['first_name'] ?? '' );
			}

			public function get_shipping_last_name( $context = 'view' ) {
				return (string) ( $this->p['shipping']['last_name'] ?? '' );
			}

			public function get_shipping_address_1( $context = 'view' ) {
				return (string) ( $this->p['shipping']['address_1'] ?? '' );
			}

			public function get_shipping_address_2( $context = 'view' ) {
				return (string) ( $this->p['shipping']['address_2'] ?? '' );
			}

			public function get_shipping_postcode( $context = 'view' ) {
				return (string) ( $this->p['shipping']['postcode'] ?? '' );
			}

			public function get_shipping_city( $context = 'view' ) {
				return (string) ( $this->p['shipping']['city'] ?? '' );
			}

			public function get_shipping_country( $context = 'view' ) {
				return (string) ( $this->p['shipping']['country'] ?? '' );
			}

			public function get_shipping_methods() {
				$lines = array();
				foreach ( $this->p['shipping_method_ids'] ?? array() as $id ) {
					$lines[] = new class( $id ) {
						private string $id;

						public function __construct( string $id ) {
							$this->id = $id;
						}

						public function get_method_id() {
							return $this->id;
						}
					};
				}
				return $lines;
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
