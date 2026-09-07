<?php
/**
 * Decides which Event Log actions a transactional-email row offers
 * (PRO-1733 retry, PRO-2324 send-again).
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
 *
 * One case is neither: a deliberate second confirmation (PRO-2324) that
 * failed. Nothing was sent for it — a re-send never fails open — so it is
 * refused like the rest, but with its own sentence (PRO-2368).
 *
 * The same class also answers the opposite question — may a row Smaily
 * already sent be sent AGAIN, deliberately (resendable(), PRO-2324) — so
 * both Event Log actions read one set of rules about the same rows.
 */
final class TransactionalRetryGuard {

	/** Fail-open re-fired the native WooCommerce email — retrying would double-send. */
	public const REASON_WC_EMAIL_SENT = 'wc_email_sent';

	/** The order behind the row is gone — there is nothing left to send. */
	public const REASON_ORDER_MISSING = 'order_missing';

	/**
	 * A deliberate second confirmation (PRO-2324) that failed. It never fails
	 * open — the shopper already has the first confirmation — so the
	 * WooCommerce-email sentence below would be false on it.
	 */
	public const REASON_RESEND_FAILED = 'resend_failed';

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

		$payload = TransactionalFlusher::read_payload( $payload_json );

		if ( $event_type !== TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION ) {
			return self::wc_email_reason( $payload );
		}

		// A shipping confirmation into `completed` replaced — and, on
		// failure, re-fired — WC_Email_Customer_Completed_Order. Any other
		// (merchant-defined) shipped status has no native email at all, so
		// nothing reached the shopper. An absent to_status (a row from
		// before it was stored, or an undecodable payload) is treated as the
		// `completed` case: never risk a second confirmation.
		$to_status = self::to_status_of( $payload );

		return ( $to_status !== '' && $to_status !== 'completed' ) ? '' : self::wc_email_reason( $payload );
	}

	/**
	 * Which "the shopper already has a confirmation" sentence a refused row
	 * gets. A re-send row that failed sent nothing at all (PRO-2368):
	 * fail-open is deliberately off for it, so the reason it would otherwise
	 * be refused with — "WooCommerce sent its own email instead" — never
	 * happened. The refusal itself is unchanged, and so is the case where a
	 * retry is the shopper's only route to a confirmation; only the sentence
	 * differs.
	 *
	 * @param array<string, mixed>|null $payload The row's decoded payload,
	 *                                           null when it doesn't decode.
	 */
	private static function wc_email_reason( ?array $payload ): string {
		return empty( $payload[ TransactionalFlusher::PAYLOAD_KEY_RESEND ] )
			? self::REASON_WC_EMAIL_SENT
			: self::REASON_RESEND_FAILED;
	}

	/**
	 * Whether a row may be sent to the shopper a SECOND time on explicit
	 * merchant request — the Event Log's "Send again" action (PRO-2324).
	 *
	 * Only a transactional row Smaily itself sent qualifies. `sent` is
	 * written by TransactionalFlusher only after Smaily replied
	 * {code:101}, so the "WooCommerce sent its own email instead" case
	 * (fail-open) can never reach it: that row is `failed`, and which of
	 * those may be RE-driven is refusal_reason()'s question, not this one.
	 * The order must still exist — a re-send rebuilds its content from the
	 * live order.
	 *
	 * @param bool $order_exists Resolved by the caller, never here — same
	 *                           batched lookup refusal_reason() takes.
	 */
	public static function resendable( string $event_type, string $status, bool $order_exists ): bool {
		return self::is_transactional( $event_type )
			&& $status === EventQueue::STATUS_SENT
			&& $order_exists;
	}

	/** The merchant-readable reason a retry was refused. */
	public static function message( string $reason ): string {
		if ( $reason === self::REASON_ORDER_MISSING ) {
			return __( 'This order no longer exists, so this event cannot be re-sent.', 'smaily-connect' );
		}

		if ( $reason === self::REASON_RESEND_FAILED ) {
			return __( 'This second confirmation could not be sent. The confirmation the customer already received still stands.', 'smaily-connect' );
		}

		return __( 'This confirmation was already sent to the shopper as the standard WooCommerce email; it cannot be re-sent.', 'smaily-connect' );
	}

	private static function is_transactional( string $event_type ): bool {
		return in_array( $event_type, TransactionalFlusher::EVENT_TYPES, true );
	}

	/**
	 * The order status a transactional row was enqueued for, read out of the
	 * stored payload this class already owns the shape of. '' when the row
	 * predates the field or its JSON doesn't decode.
	 */
	public static function to_status( string $payload_json ): string {
		return self::to_status_of( TransactionalFlusher::read_payload( $payload_json ) );
	}

	/**
	 * The same answer for a payload already decoded — refusal_reason() reads
	 * the payload once and asks both questions of it.
	 *
	 * @param array<string, mixed>|null $payload
	 */
	private static function to_status_of( ?array $payload ): string {
		return isset( $payload['to_status'] ) ? (string) $payload['to_status'] : '';
	}
}
