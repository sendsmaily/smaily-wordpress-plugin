<?php
/**
 * WC hooks → the transactional-email sender (PRO-1504 Stage 2).
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\PaymentReminderCheck;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\TransactionalPayloadBuilder;

/**
 * Four triggers, each once-per-order-per-email-type (an order-meta guard —
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
 *   - follow_up (PRO-4095): the same `woocommerce_order_status_changed`,
 *     when the new status is in `smly_plus_follow_up_order_statuses`.
 *     Nothing is sent then — the flusher queues the email to go out once
 *     the merchant's delay has passed. One status change can do both.
 *   - payment_reminder (CON-328): an order placed at the store's own
 *     checkout (`created_via` checkout or store-api — never one made in
 *     wp-admin), on the two checkout hooks above and when its status
 *     changes into `failed` — that change only for an order created in the
 *     last 24 hours whose status no person changed in wp-admin (audit L3,
 *     on_order_edit_status()). Nothing goes to the queue then: one Action
 *     Scheduler action (PaymentReminderCheck) waits the merchant's
 *     minutes and queues the reminder only if the order still waits for
 *     payment. A bank transfer, cheque or cash-on-delivery order is never
 *     scheduled.
 *
 * Gating (design point 2) is delegated whole to TransactionalGate — no
 * consent/opt-out check here (transactional sends override marketing
 * opt-out, platform answer Q7 / PRO-1380).
 *
 * Not final: tests inject gate/builder/flusher doubles.
 */
class TransactionalEmailHookHandler {

	/** The oldest order a change into `failed` starts the payment reminder's wait for (audit L3). */
	public const PAYMENT_REMINDER_FAILED_MAX_ORDER_AGE_SECONDS = DAY_IN_SECONDS;

	/** @var array<int, true> Orders whose status a person changed in this request (on_order_edit_status()). */
	private array $staff_status_changes = array();

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
		$this->maybe_payment_reminder( $order );
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
		$this->maybe_payment_reminder( $order );
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

		$to        = OrderBackfillJob::bare_status( (string) $to_status );
		$shipped   = in_array( $to, TransactionalGate::shipped_statuses(), true );
		$follow_up = in_array( $to, TransactionalGate::follow_up_statuses(), true );
		$failed    = $to === 'failed';
		if ( ! $shipped && ! $follow_up && ! $failed ) {
			return;
		}

		$order = $order ?? $this->load_order( $order_id );
		if ( $order === null ) {
			return;
		}

		if ( $shipped ) {
			$this->attempt( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION, $order, $to );
		}
		if ( $follow_up ) {
			$this->attempt( TransactionalGate::TRIGGER_FOLLOW_UP, $order, $to );
		}
		if ( $failed && $this->failed_without_staff_recently( $order ) ) {
			$this->maybe_payment_reminder( $order );
		}
	}

	/**
	 * `woocommerce_order_edit_status` ($order_id, $new_status) — WooCommerce
	 * fires it for a status change it marks as manual (a person made it: the
	 * order edit screen, the bulk edit and status buttons of both order
	 * lists), before the change reaches `woocommerce_order_status_changed`.
	 * A gateway's change is never manual. Remembered for this request only
	 * (audit L3).
	 *
	 * @param int|string $order_id
	 * @param string     $new_status
	 */
	public function on_order_edit_status( $order_id, $new_status = '' ): void {
		unset( $new_status );

		$this->staff_status_changes[ (int) $order_id ] = true;
	}

	/**
	 * Whether a change into `failed` may start the payment reminder's wait
	 * (audit L3): no person made it in wp-admin, and the order was created
	 * within PAYMENT_REMINDER_FAILED_MAX_ORDER_AGE_SECONDS — a merchant
	 * tidying up old unpaid orders reminds no one.
	 */
	private function failed_without_staff_recently( \WC_Order $order ): bool {
		if ( isset( $this->staff_status_changes[ $order->get_id() ] ) ) {
			return false;
		}

		$created = $order->get_date_created();

		return $created !== null && time() - $created->getTimestamp() <= self::PAYMENT_REMINDER_FAILED_MAX_ORDER_AGE_SECONDS;
	}

	/**
	 * Start the payment reminder's wait (CON-328) for an order placed at the
	 * store's own checkout: one Action Scheduler action, whatever the
	 * order's status is now — the gateway may not have set it yet. Whether
	 * the order still waits for payment is decided when the wait ends
	 * (PaymentReminderCheck::run()). Never for an order made in wp-admin or
	 * through the REST API, an order paid offline, or while the reminder is
	 * switched off; once per order.
	 */
	private function maybe_payment_reminder( \WC_Order $order ): void {
		if ( ! PaymentReminderCheck::is_candidate( $order ) ) {
			return;
		}

		$meta_key = TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_PAYMENT_REMINDER );
		if ( (string) $order->get_meta( $meta_key ) !== '' ) {
			return;
		}

		// A switched-off reminder schedules nothing. Any other closed reason
		// is decided — and logged — when the wait ends, as for a due follow-up.
		$reason = $this->gate->closed_reason( TransactionalGate::TRIGGER_PAYMENT_REMINDER, $order );
		if ( $reason !== '' && ! TransactionalGate::is_loggable_reason( $reason ) ) {
			return;
		}

		if ( PaymentReminderCheck::schedule( $order->get_id() ) ) {
			$order->update_meta_data( $meta_key, TransactionalFlusher::META_STATUS_QUEUED );
			$order->save();
		}
	}

	private function attempt( string $trigger_type, \WC_Order $order, string $to_status = '' ): void {
		$meta_key = TransactionalFlusher::meta_key_for( $trigger_type );
		if ( (string) $order->get_meta( $meta_key ) !== '' ) {
			// Already attempted/sent/queued/failed-open for this order+type —
			// once-per-order-per-email-type (design point 1).
			return;
		}

		list( $reason, $match ) = $this->gate->evaluate( $trigger_type, $order );
		if ( $match === null ) {
			// Gate closed — nothing enqueued, no meta written, so the order
			// keeps its one attempt. The gate decides which reasons leave an
			// Event Log skipped row (PRO-4091).
			if ( TransactionalGate::is_loggable_reason( $reason ) ) {
				$this->flusher->record_skip( $trigger_type, $order->get_id(), $reason );
			}
			return;
		}

		if ( $trigger_type === TransactionalGate::TRIGGER_FOLLOW_UP ) {
			$this->flusher->schedule( $trigger_type, $order, $match, $to_status, TransactionalGate::follow_up_delay_days() * DAY_IN_SECONDS );
			return;
		}

		$context = $this->builder->build( $order, $trigger_type );
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
}
