<?php
/**
 * The payment reminder's wait, held in Action Scheduler (CON-328).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Support\DebugLog;

/**
 * An order placed at the store's checkout may wait for its payment, but most
 * are paid within minutes. So the checkout hook writes nothing to the Event
 * Log: it schedules ONE Action Scheduler action (HOOK, group AS_GROUP) at
 * now + the merchant's delay, carrying the order id only.
 *
 * When the action runs it reads the order again. Only an order that still
 * waits for payment (TransactionalGate::awaits_payment_reason()) and was
 * placed at the checkout gets a `transactional.payment_reminder` queue row,
 * due now, which TransactionalFlusher sends as it sends a due follow-up —
 * re-checking the order once more. A closed gate leaves the Event Log row
 * the other transactional emails leave (TransactionalGate::
 * is_loggable_reason()). Any other order — paid, on hold, processing,
 * cancelled, gone, or anonymized by a personal-data erasure — leaves no row,
 * only a debug line with the order id and the reason. A personal-data erasure
 * cancels the order's waiting action (forget_orders()).
 *
 * Not final: tests replace the flusher and the gate.
 */
class PaymentReminderCheck {

	/** The Action Scheduler hook of the action. */
	public const HOOK = 'smly_plus_payment_reminder_check';

	/** Its Action Scheduler group (Deactivation::AS_GROUPS). */
	public const AS_GROUP = 'smaily-connect-payment-reminder';

	/** `created_via` of an order placed at the store's classic or block checkout. */
	public const CHECKOUT_CREATED_VIA = array( 'checkout', 'store-api' );

	/** Payment methods whose orders never wait as pending: bank transfer, cheque, cash on delivery (CON-328). */
	public const OFFLINE_PAYMENT_METHODS = array( 'bacs', 'cheque', 'cod' );

	private TransactionalGate $gate;
	private TransactionalFlusher $flusher;

	public function __construct( TransactionalGate $gate, TransactionalFlusher $flusher ) {
		$this->gate    = $gate;
		$this->flusher = $flusher;
	}

	/**
	 * Whether an order may get the reminder's wait at all: placed at the
	 * store's own checkout and not paid with an offline method.
	 */
	public static function is_candidate( \WC_Order $order ): bool {
		return self::placed_at_checkout( $order )
			&& ! in_array( (string) $order->get_payment_method(), self::OFFLINE_PAYMENT_METHODS, true );
	}

	/**
	 * Schedule the order's check after the merchant's delay. Returns whether
	 * an action was scheduled.
	 */
	public static function schedule( int $order_id ): bool {
		if ( $order_id <= 0 || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$delay = TransactionalGate::payment_reminder_delay_minutes() * MINUTE_IN_SECONDS;

		return (int) as_schedule_single_action( time() + $delay, self::HOOK, array( $order_id ), self::AS_GROUP ) > 0;
	}

	/**
	 * Cancel the waiting action of each order (audit L2): the personal-data
	 * eraser calls it for the customer's orders, because WooCommerce keeps an
	 * order's address on erasure by default and the action would then remind
	 * the erased address. Returns how many actions were cancelled.
	 *
	 * @param int[] $order_ids
	 */
	public static function forget_orders( array $order_ids ): int {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return 0;
		}

		$cancelled = 0;
		foreach ( $order_ids as $order_id ) {
			if ( (int) as_unschedule_action( self::HOOK, array( (int) $order_id ), self::AS_GROUP ) > 0 ) {
				++$cancelled;
			}
		}

		return $cancelled;
	}

	/**
	 * The action: queue the reminder, now, if the order still waits for
	 * payment.
	 *
	 * @param mixed $order_id
	 */
	public function run( $order_id = 0 ): void {
		$order_id = (int) $order_id;
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			self::log( $order_id, TransactionalFlusher::REASON_ORDER_MISSING );
			return;
		}

		$reason = self::not_waiting_reason( $order );
		if ( $reason !== '' ) {
			self::log( $order_id, $reason );
			return;
		}

		list( $reason, $match ) = $this->gate->evaluate( TransactionalGate::TRIGGER_PAYMENT_REMINDER, $order );
		if ( $match === null ) {
			if ( TransactionalGate::is_loggable_reason( $reason ) ) {
				$this->flusher->record_skip( TransactionalGate::TRIGGER_PAYMENT_REMINDER, $order_id, $reason );
			}
			return;
		}

		$this->flusher->schedule( TransactionalGate::TRIGGER_PAYMENT_REMINDER, $order, $match, (string) $order->get_status(), 0 );
	}

	/**
	 * Why the order gets no reminder now, '' when it still waits for payment.
	 */
	private static function not_waiting_reason( \WC_Order $order ): string {
		if ( ! self::placed_at_checkout( $order ) ) {
			return 'not_checkout';
		}
		if ( (string) $order->get_meta( '_anonymized' ) === 'yes' ) {
			// WooCommerce's personal-data eraser replaced the address.
			return 'anonymized';
		}

		return TransactionalGate::awaits_payment_reason( $order );
	}

	private static function placed_at_checkout( \WC_Order $order ): bool {
		return in_array( (string) $order->get_created_via(), self::CHECKOUT_CREATED_VIA, true );
	}

	private static function log( int $order_id, string $reason ): void {
		DebugLog::write( sprintf( '[smaily-connect] payment reminder not queued for order %d: %s', $order_id, $reason ) );
	}
}
