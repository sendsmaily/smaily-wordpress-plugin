<?php
/**
 * Decides whether a failed transactional-email row may be retried (PRO-1733).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * A failed transactional row is NOT like a failed marketing row. Every
 * terminal failure of a transactional send runs TransactionalFlusher's
 * fail-open path (PRO-1504 design point 7 / PRO-1519), and in most cases
 * that path already re-fired the native WooCommerce email — so the shopper
 * HAS their confirmation and an Event Log "Retry" would send a second one.
 *
 * The one case where fail-open sends nothing is a shipping confirmation
 * triggered by a merchant-defined shipped status (anything other than
 * `completed`): WooCommerce has no native email for such a status, so
 * nothing was suppressed and nothing was re-fired. There the shopper got
 * NO confirmation at all and a retry is the only way they ever get one.
 *
 * This class is the single place that tells those apart, used both by the
 * Event Log read model (hide the Retry action, explain why) and by the
 * retry route (refuse the request). It reads only what the queue row
 * already stores — the event type plus the enqueued payload's `to_status`
 * — so there is no new stored field, and it does no I/O of its own: the
 * caller resolves order existence (one batched lookup per request) and
 * hands the answer in.
 *
 * A transactional row whose order can no longer be loaded is refused too:
 * a retry would rebuild nothing and fail-open has no order to fall back on.
 */
final class TransactionalRetryGuard {

	/** Fail-open re-fired the native WooCommerce email — retrying would double-send. */
	public const REASON_WC_EMAIL_SENT = 'wc_email_sent';

	/** The order behind the row is gone — there is nothing left to send. */
	public const REASON_ORDER_MISSING = 'order_missing';

	/**
	 * @param bool $order_exists Whether the row's order still loads —
	 *                           resolved by the caller, never here.
	 *
	 * @return string '' when the row may be retried (including every
	 *                non-transactional row); otherwise a REASON_* code.
	 */
	public static function refusal_reason( string $event_type, string $payload_json, bool $order_exists ): string {
		if ( ! self::is_transactional( $event_type ) ) {
			return '';
		}

		if ( ! $order_exists ) {
			return self::REASON_ORDER_MISSING;
		}

		if ( $event_type !== TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION ) {
			return self::REASON_WC_EMAIL_SENT;
		}

		// A shipping confirmation into `completed` replaced — and, on
		// failure, re-fired — WC_Email_Customer_Completed_Order. Any other
		// (merchant-defined) shipped status has no native email at all, so
		// nothing reached the shopper. An absent to_status (a row from
		// before it was stored, or an undecodable payload) is treated as the
		// `completed` case: never risk a second confirmation.
		$to_status = self::to_status( $payload_json );

		return ( $to_status !== '' && $to_status !== 'completed' ) ? '' : self::REASON_WC_EMAIL_SENT;
	}

	/** The merchant-readable reason a retry was refused. */
	public static function message( string $reason ): string {
		if ( $reason === self::REASON_ORDER_MISSING ) {
			return __( 'This order no longer exists, so this event cannot be re-sent.', 'smaily-connect' );
		}

		return __( 'This confirmation was already sent to the shopper as the standard WooCommerce email; it cannot be re-sent.', 'smaily-connect' );
	}

	private static function is_transactional( string $event_type ): bool {
		return in_array( $event_type, TransactionalFlusher::EVENT_TYPES, true );
	}

	private static function to_status( string $payload_json ): string {
		$payload = TransactionalFlusher::read_payload( $payload_json );

		return isset( $payload['to_status'] ) ? (string) $payload['to_status'] : '';
	}
}
