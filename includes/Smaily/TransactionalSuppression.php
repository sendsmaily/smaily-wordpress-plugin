<?php
/**
 * Suppresses WooCommerce's native transactional emails while Smaily sends them.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * PRO-1504 Stage 2, design point 6: suppress the native WC customer email
 * ONLY while TransactionalGate holds for that trigger — so toggling the
 * feature off (or an unresolved mapping/credentials) instantly restores
 * WooCommerce's own email, never leaving a customer with NO confirmation.
 *
 *   - order_confirmation replaces `customer_processing_order`;
 *   - shipping_confirmation replaces `customer_completed_order`, and ONLY
 *     when 'completed' is in the merchant's shipped-status set — a custom
 *     shipped status (e.g. "Shipped") has no native WC email to suppress.
 *
 * Admin emails are never touched.
 *
 * Fail-open (design point 7) needs to re-fire the very email this class
 * suppresses, bypassing the suppression for that one call —
 * fire_native_bypassing_suppression() flips a request-scoped static flag
 * the two filter callbacks check first.
 *
 * Not final: tests inject a gate double.
 */
class TransactionalSuppression {

	public const EMAIL_CLASS_ORDER_CONFIRMATION    = 'WC_Email_Customer_Processing_Order';
	public const EMAIL_CLASS_SHIPPING_CONFIRMATION = 'WC_Email_Customer_Completed_Order';

	/** Request-scoped: true only for the duration of a fail-open re-fire call. */
	private static bool $bypass = false;

	private TransactionalGate $gate;

	public function __construct( TransactionalGate $gate ) {
		$this->gate = $gate;
	}

	public function register(): void {
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( $this, 'filter_processing_order' ), 10, 1 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'filter_completed_order' ), 10, 1 );
	}

	/**
	 * @param mixed $enabled
	 *
	 * @return mixed
	 */
	public function filter_processing_order( $enabled ) {
		if ( self::$bypass ) {
			return $enabled;
		}

		return $this->gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) !== null ? false : $enabled;
	}

	/**
	 * @param mixed $enabled
	 *
	 * @return mixed
	 */
	public function filter_completed_order( $enabled ) {
		if ( self::$bypass ) {
			return $enabled;
		}

		if ( $this->gate->resolve_if_open( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) === null ) {
			return $enabled;
		}

		return in_array( 'completed', TransactionalGate::shipped_statuses(), true ) ? false : $enabled;
	}

	/**
	 * Fire a native WC customer email directly, bypassing this class's own
	 * suppression for that one call — the fail-open path (design point 7).
	 * A no-op when WooCommerce's mailer or the named email class isn't
	 * available (defensive; shouldn't happen once WC is fully loaded).
	 */
	public static function fire_native_bypassing_suppression( string $wc_email_class, int $order_id ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$mailer = WC()->mailer();
		if ( ! is_object( $mailer ) || ! isset( $mailer->emails[ $wc_email_class ] ) || ! is_callable( array( $mailer->emails[ $wc_email_class ], 'trigger' ) ) ) {
			return;
		}

		self::$bypass = true;
		try {
			$mailer->emails[ $wc_email_class ]->trigger( $order_id );
		} finally {
			self::$bypass = false;
		}
	}
}
