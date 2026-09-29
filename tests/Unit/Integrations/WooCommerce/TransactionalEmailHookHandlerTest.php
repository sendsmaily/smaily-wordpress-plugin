<?php
/**
 * TransactionalEmailHookHandler tests (PRO-1504 Stage 2) — the
 * once-per-order-per-type meta guard, the shipped-status membership check,
 * and that a closed gate is a total no-op.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\TransactionalEmailHookHandler;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\TransactionalPayloadBuilder;
use Smaily\Connect\Smaily\WorkflowMatch;

final class TransactionalEmailHookHandlerTest extends TestCase {

	/** @var array<int, \WC_Order> */
	private array $orders = array();

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->orders  = array();
		$this->options = array( 'smly_plus_shipped_order_statuses' => array( 'completed' ) );

		$orders = &$this->orders;
		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( &$orders ) {
				return $orders[ $id ] ?? false;
			}
		);
		$opts = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$opts ) {
				return $opts[ $key ] ?? $fallback;
			}
		);
		// The real builder's WC formatting helpers (tests that use it).
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'wc_format_decimal' )->alias(
			static function ( $number, $dp ) {
				return number_format( (float) $number, (int) $dp, '.', '' );
			}
		);
		Functions\when( 'wc_get_order_status_name' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_order_processed_calls_the_flusher_when_the_gate_is_open(): void {
		$order              = $this->fake_order( 1, '' );
		$this->orders[1]    = $order;
		$builder            = $this->builder_returning( array( 'order_number' => '1' ) );
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ),
			$builder,
			$flusher
		);
		$handler->on_order_processed( 1 );

		self::assertCount( 1, $flusher->calls );
		self::assertSame( TransactionalGate::TRIGGER_ORDER_CONFIRMATION, $flusher->calls[0]['trigger_type'] );
		self::assertSame( array( 'order_number' => '1' ), $flusher->calls[0]['context'] );
	}

	public function test_order_processed_does_nothing_when_the_gate_is_closed(): void {
		$order           = $this->fake_order( 2, '' );
		$this->orders[2] = $order;
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( '' ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_processed( 2 );

		self::assertCount( 0, $flusher->calls, 'Everything off → zero behavior change: nothing enqueued, no meta written.' );
		self::assertSame( '', $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
	}

	public function test_order_processed_is_a_noop_when_the_meta_guard_is_already_set(): void {
		$order = $this->fake_order( 3, '' );
		$order->update_meta_data( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), TransactionalFlusher::META_STATUS_SENT );
		$this->orders[3] = $order;
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_processed( 3 );

		self::assertCount( 0, $flusher->calls, 'Once-per-order-per-type: already sent, never re-attempted.' );
	}

	public function test_block_checkout_order_processed_calls_the_flusher_when_the_gate_is_open(): void {
		// PRO-1518: the Store-API twin of on_order_processed() — block
		// checkout never fires woocommerce_checkout_order_processed.
		$order = $this->fake_order( 20, '' );
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ),
			$this->builder_returning( array( 'order_number' => '20' ) ),
			$flusher
		);
		$handler->on_block_checkout_order_processed( $order );

		self::assertCount( 1, $flusher->calls );
		self::assertSame( TransactionalGate::TRIGGER_ORDER_CONFIRMATION, $flusher->calls[0]['trigger_type'] );
	}

	public function test_order_confirmation_is_idempotent_across_both_checkout_hooks(): void {
		// PRO-1518: if a store somehow fired BOTH the classic and Store-API
		// hooks for the same order, the once-per-order-per-type meta guard
		// must still cap it at exactly one send. The real TransactionalFlusher
		// stamps the meta guard as part of send_now() (see TransactionalFlusherTest);
		// this double mimics that so the guard is actually exercised here,
		// not just re-asserting the double got called once.
		$order = $this->fake_order( 21, '' );
		$meta_key = TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION );
		$flusher = new class( $meta_key ) extends TransactionalFlusher {
			public array $calls = array();
			private string $meta_key;
			public function __construct( string $meta_key ) {
				$this->meta_key = $meta_key;
			}
			public function send_now( string $trigger_type, \WC_Order $order, WorkflowMatch $match, array $context, string $to_status = '' ): void {
				$this->calls[] = compact( 'trigger_type', 'context', 'to_status' );
				$order->update_meta_data( $this->meta_key, TransactionalFlusher::META_STATUS_SENT );
			}
		};

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_processed( 21, array(), $order );
		$handler->on_block_checkout_order_processed( $order );

		self::assertCount( 1, $flusher->calls, 'The meta guard blocks the second hook once the first has attempted.' );
	}

	public function test_status_changed_into_a_shipped_status_calls_the_flusher(): void {
		$order           = $this->fake_order( 4, '' );
		$this->orders[4] = $order;
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_status_changed( 4, 'processing', 'completed' );

		self::assertCount( 1, $flusher->calls );
		self::assertSame( 'completed', $flusher->calls[0]['to_status'] );
	}

	public function test_status_changed_into_a_wc_prefixed_shipped_status_is_normalised(): void {
		$order           = $this->fake_order( 5, '' );
		$this->orders[5] = $order;
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_status_changed( 5, 'processing', 'wc-completed' );

		self::assertCount( 1, $flusher->calls, 'The wc- prefix is stripped before the shipped-status membership check.' );
	}

	public function test_status_changed_into_a_non_shipped_status_does_nothing(): void {
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_status_changed( 6, 'processing', 'on-hold' );

		self::assertCount( 0, $flusher->calls );
	}

	public function test_repeated_flips_into_the_shipped_set_do_not_resend(): void {
		$order = $this->fake_order( 7, '' );
		$order->update_meta_data( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ), TransactionalFlusher::META_STATUS_SENT );
		$this->orders[7] = $order;
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_status_changed( 7, 'completed', 'shipped-again' );
		$this->options['smly_plus_shipped_order_statuses'] = array( 'completed', 'shipped-again' );
		$handler->on_order_status_changed( 7, 'completed', 'shipped-again' );

		self::assertCount( 0, $flusher->calls, 'The meta guard blocks a repeated flip into the shipped set.' );
	}

	public function test_custom_shipped_status_set_by_the_merchant_is_respected(): void {
		$order           = $this->fake_order( 8, '' );
		$this->orders[8] = $order;
		$this->options['smly_plus_shipped_order_statuses'] = array( 'label-printed' );
		$flusher = $this->recording_flusher();

		$handler = new TransactionalEmailHookHandler(
			$this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ),
			$this->builder_returning( array() ),
			$flusher
		);
		$handler->on_order_status_changed( 8, 'processing', 'label-printed' );
		self::assertCount( 1, $flusher->calls );

		$handler->on_order_status_changed( 8, 'label-printed', 'completed' );
		self::assertCount( 1, $flusher->calls, "'completed' isn't in this merchant's shipped set, so it doesn't fire." );
	}

	public function test_order_and_shipping_confirmation_both_carry_the_product_link(): void {
		// PRO-3335: both emails build their merge tags through the SAME real
		// builder (only its WC price/image seams stubbed), so both carry it.
		$order           = $this->fake_order_with_product( 9, 'https://shop.example.test/product/dog-food/' );
		$this->orders[9] = $order;
		$flusher         = $this->recording_flusher();
		$builder         = $this->real_builder();

		( new TransactionalEmailHookHandler( $this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), $builder, $flusher ) )
			->on_order_processed( 9 );
		( new TransactionalEmailHookHandler( $this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ), $builder, $flusher ) )
			->on_order_status_changed( 9, 'processing', 'completed' );

		self::assertCount( 2, $flusher->calls );
		self::assertSame( TransactionalGate::TRIGGER_ORDER_CONFIRMATION, $flusher->calls[0]['trigger_type'] );
		self::assertSame( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION, $flusher->calls[1]['trigger_type'] );
		foreach ( $flusher->calls as $call ) {
			self::assertSame( 'https://shop.example.test/product/dog-food/', $call['context']['product_url_1'] );
			self::assertSame( '', $call['context']['product_url_2'] );
		}
	}

	public function test_a_broken_extra_fields_filter_does_not_stop_the_confirmation(): void {
		// PRO-3190: a merchant snippet that throws must not break checkout
		// or the send — the real builder drops its entries and goes on.
		$seen_trigger = null;
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_FIELDS )->andReturnUsing(
			static function ( $fields, $order, $trigger ) use ( &$seen_trigger ) {
				unset( $fields, $order );
				$seen_trigger = $trigger;
				throw new \RuntimeException( 'broken snippet' );
			}
		);
		Filters\expectApplied( TransactionalPayloadBuilder::FILTER_PRODUCT_FIELDS )->andReturnUsing(
			static function () {
				throw new \Error( 'fatal in a snippet' );
			}
		);
		$this->orders[10] = $this->fake_order_with_product( 10, 'https://shop.example.test/product/dog-food/' );
		$flusher          = $this->recording_flusher();

		( new TransactionalEmailHookHandler( $this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), $this->real_builder(), $flusher ) )
			->on_order_processed( 10 );

		self::assertSame( TransactionalGate::TRIGGER_ORDER_CONFIRMATION, $seen_trigger, 'The filter is told which email it is building.' );
		self::assertCount( 1, $flusher->calls, 'The confirmation is still sent.' );
		self::assertSame( 'Dog food', $flusher->calls[0]['context']['product_name_1'] );
	}

	// --- helpers -------------------------------------------------------------

	private function gate_open_for( string $open_trigger_type ): TransactionalGate {
		return new class( $open_trigger_type ) extends TransactionalGate {
			private string $open_trigger_type;
			public function __construct( string $open_trigger_type ) {
				$this->open_trigger_type = $open_trigger_type;
			}
			public function resolve_if_open( string $trigger_type, ?\WC_Order $order = null ): ?WorkflowMatch {
				return $trigger_type === $this->open_trigger_type ? new WorkflowMatch( 1, 'transactional' ) : null;
			}
		};
	}

	/** The real builder, only its WC price/image seams stubbed. */
	private function real_builder(): TransactionalPayloadBuilder {
		return new class() extends TransactionalPayloadBuilder {
			protected function price_display( float $amount ): string {
				return (string) $amount;
			}

			protected function product_image_url( \WC_Product $product ): string {
				return '';
			}
		};
	}

	private function builder_returning( array $context ): TransactionalPayloadBuilder {
		return new class( $context ) extends TransactionalPayloadBuilder {
			private array $context;
			public function __construct( array $context ) {
				$this->context = $context;
			}
			public function build( \WC_Order $order, string $trigger = '' ): array {
				return $this->context;
			}
		};
	}

	/**
	 * A TransactionalFlusher double that just records send_now() calls onto
	 * its public ->calls property (read directly by the caller — no
	 * PHP-reference indirection needed).
	 */
	private function recording_flusher(): TransactionalFlusher {
		return new class() extends TransactionalFlusher {
			public array $calls = array();
			public function __construct() {}
			public function send_now( string $trigger_type, \WC_Order $order, WorkflowMatch $match, array $context, string $to_status = '' ): void {
				$this->calls[] = compact( 'trigger_type', 'context', 'to_status' );
			}
		};
	}

	private function fake_order( int $id, string $email ): \WC_Order {
		return new class( $id, $email ) extends \WC_Order {
			private int $id;
			private string $email;
			private array $meta = array();

			public function __construct( int $id, string $email ) {
				$this->id    = $id;
				$this->email = $email;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_billing_email( $context = 'view' ): string {
				return $this->email;
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function save() {
				return $this->id;
			}
		};
	}

	/**
	 * An order with one published product line — enough surface for the real
	 * TransactionalPayloadBuilder plus the handler's meta guard.
	 */
	private function fake_order_with_product( int $id, string $product_url ): \WC_Order {
		$product = new class( $product_url ) extends \WC_Product {
			private string $url;

			public function __construct( string $url ) {
				$this->url = $url;
			}

			public function get_sku( $context = 'view' ) {
				return 'DOG-1';
			}

			public function get_description( $context = 'view' ) {
				return '';
			}

			public function get_status( $context = 'view' ) {
				return 'publish';
			}

			public function get_permalink() {
				return $this->url;
			}
		};

		$item = new class( $product ) extends \WC_Order_Item_Product {
			private \WC_Product $product;

			public function __construct( \WC_Product $product ) {
				$this->product = $product;
			}

			public function get_name( $context = 'view' ) {
				return 'Dog food';
			}

			public function get_product() {
				return $this->product;
			}

			public function get_quantity( $context = 'view' ) {
				return 1;
			}

			public function get_subtotal( $context = 'view' ) {
				return '5';
			}

			public function get_subtotal_tax( $context = 'view' ) {
				return '0';
			}

			public function get_total( $context = 'view' ) {
				return '5';
			}

			public function get_total_tax( $context = 'view' ) {
				return '0';
			}
		};

		return new class( $id, $item ) extends \WC_Order {
			private int $id;
			private \WC_Order_Item_Product $item;
			private array $meta = array();

			public function __construct( int $id, \WC_Order_Item_Product $item ) {
				$this->id   = $id;
				$this->item = $item;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_order_number() {
				return (string) $this->id;
			}

			public function get_total( $context = 'view' ): string {
				return '5';
			}

			public function get_currency( $context = 'view' ): string {
				return 'EUR';
			}

			public function get_payment_method_title( $context = 'view' ) {
				return '';
			}

			public function get_shipping_method() {
				return '';
			}

			public function get_billing_first_name( $context = 'view' ): string {
				return '';
			}

			public function get_billing_last_name( $context = 'view' ): string {
				return '';
			}

			public function get_items( $types = 'line_item' ): array {
				return array( $this->item );
			}

			public function get_total_tax( $context = 'view' ) {
				return '0';
			}

			public function get_shipping_total( $context = 'view' ) {
				return '0';
			}

			public function get_shipping_tax( $context = 'view' ) {
				return '0';
			}

			public function get_status( $context = 'view' ): string {
				return 'processing';
			}

			public function get_payment_method( $context = 'view' ) {
				return '';
			}

			public function get_shipping_methods() {
				return array();
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function save() {
				return $this->id;
			}
		};
	}
}
