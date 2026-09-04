<?php
/**
 * WC hooks → the transactional-email sender (PRO-1504 Stage 2).
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\TransactionalPayloadBuilder;

/**
 * Two triggers, both once-per-order-per-email-type (an order-meta guard —
 * design point 1):
 *
 *   - order_confirmation: `woocommerce_checkout_order_processed` — a
 *     one-shot hook (deliberately NOT `woocommerce_thank_you`, which
 *     re-fires on every thank-you-page revisit). This NEVER fires for a
 *     WooCommerce Blocks / Store-API checkout (WC default since 8.3), so
 *     `on_block_checkout_order_processed()` is its Store-API twin on
 *     `woocommerce_store_api_checkout_order_processed` — same F3-46 gap
 *     shape (HookHandler::on_block_checkout_order_processed), fixed here
 *     for PRO-1518. The once-per-order-per-type meta guard in `attempt()`
 *     makes it safe if both hooks somehow fired for one order.
 *   - shipping_confirmation: `woocommerce_order_status_changed`, firing
 *     when the NEW status (bare slug) is in the merchant's
 *     `smly_plus_shipped_order_statuses` set. Repeated flips into the
 *     shipped set after the first one are no-ops — the meta guard is
 *     already set.
 *
 * Gating (design point 2) is delegated whole to TransactionalGate — no
 * consent/opt-out check here (transactional sends override marketing
 * opt-out, platform answer Q7 / PRO-1380).
 *
 * Not final: tests inject gate/builder/flusher doubles.
 */
class TransactionalEmailHookHandler {

	private TransactionalGate $gate;
	private TransactionalPayloadBuilder $builder;
	private TransactionalFlusher $flusher;

	public function __construct( TransactionalGate $gate, TransactionalPayloadBuilder $builder, TransactionalFlusher $flusher ) {
		$this->gate    = $gate;
		$this->builder = $builder;
		$this->flusher = $flusher;
	}

	/**
	 * `woocommerce_checkout_order_processed` ($order_id, $posted_data, $order)
	 * — WC_Checkout::process_checkout() always passes a real WC_Order (it
	 * throws before firing the action if order creation failed); the
	 * wc_get_order() fallback covers only a third party re-firing this
	 * action manually with fewer args.
	 *
	 * @param int|string           $order_id
	 * @param array<string, mixed> $posted_data
	 */
	public function on_order_processed( $order_id, $posted_data = array(), ?\WC_Order $order = null ): void {
		unset( $posted_data );

		$order = $order ?? $this->load_order( $order_id );
		if ( $order === null ) {
			return;
		}

		$this->attempt( TransactionalGate::TRIGGER_ORDER_CONFIRMATION, $order );
	}

	/**
	 * `woocommerce_store_api_checkout_order_processed` ($order) — the
	 * Store-API twin of on_order_processed() (PRO-1518). Block checkout
	 * never fires `woocommerce_checkout_order_processed`, so without this a
	 * block-checkout order got no order-confirmation send at all. The
	 * once-per-order-per-type meta guard in `attempt()` keeps this safe even
	 * if a third party's setup somehow fires both hooks for one order.
	 */
	public function on_block_checkout_order_processed( \WC_Order $order ): void {
		$this->attempt( TransactionalGate::TRIGGER_ORDER_CONFIRMATION, $order );
	}

	/**
	 * `woocommerce_order_status_changed` ($order_id, $from_status, $to_status,
	 * $order) — WC_Order::status_transition() always passes $this; the
	 * wc_get_order() fallback covers only a third party re-firing this
	 * action manually with fewer args.
	 *
	 * @param int|string $order_id
	 * @param string     $from_status
	 * @param string     $to_status
	 */
	public function on_order_status_changed( $order_id, $from_status = '', $to_status = '', ?\WC_Order $order = null ): void {
		unset( $from_status );

		$to = $this->bare_status( (string) $to_status );
		if ( ! in_array( $to, TransactionalGate::shipped_statuses(), true ) ) {
			return;
		}

		$order = $order ?? $this->load_order( $order_id );
		if ( $order === null ) {
			return;
		}

		$this->attempt( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION, $order, $to );
	}

	private function attempt( string $trigger_type, \WC_Order $order, string $to_status = '' ): void {
		$meta_key = TransactionalFlusher::meta_key_for( $trigger_type );
		if ( (string) $order->get_meta( $meta_key ) !== '' ) {
			// Already attempted/sent/queued/failed-open for this order+type —
			// once-per-order-per-email-type (design point 1).
			return;
		}

		$match = $this->gate->resolve_if_open( $trigger_type );
		if ( $match === null ) {
			// Gate closed (toggle off, no mapping, or credentials incomplete)
			// — zero behavior change, nothing enqueued, no meta written.
			return;
		}

		$context = $this->builder->build( $order );
		$this->flusher->send_now( $trigger_type, $order, $match, $context, $to_status );
	}

	/**
	 * @param int|string $order_id
	 */
	private function load_order( $order_id ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( (int) $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	private function bare_status( string $status ): string {
		return strpos( $status, 'wc-' ) === 0 ? substr( $status, 3 ) : $status;
	}
}
