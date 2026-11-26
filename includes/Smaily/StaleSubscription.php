<?php
/**
 * Finds the contact syncs that would put a stale subscription into Smaily
 * (CON-395).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\HookHandler;

/**
 * A `contact.sync` row stores the subscription status the store saw when the
 * row was queued, and rows do not always go in the order they were queued: a
 * row that failed waits for its retry while a later row of the same contact
 * is delivered. A row that marks the contact subscribed (`is_unsubscribed`
 * 0) is stale once a later row of the same contact that carries a
 * subscription status of its own (0 or 1) was delivered: that later row is
 * the contact's newer choice, and sending the older one would undo it.
 *
 * Rules that keep everything else as it was:
 *  - a row that unsubscribes (`is_unsubscribed` 1) is never stale — sent
 *    late, it can only narrow who is subscribed;
 *  - a row without a status (a data sync, the abandoned-cart purchase
 *    marker) is never stale, and a later one of those makes nothing stale;
 *  - a later row that is not delivered (it waits, failed, or was skipped and
 *    sent nothing) makes nothing stale.
 *
 * "Later" is queue order (the row id), "the same contact" is the queue's own
 * contact key, and "delivered" is the queue's (EventQueue::
 * later_delivered_payloads()). No call to Smaily is made.
 *
 * The one answer for the Flusher, which closes a stale row as skipped at every
 * attempt, the automatic retry included, and for the Event Log's Retry, which
 * refuses it.
 */
final class StaleSubscription {

	/** The skip reason a stale row records, and the Retry refusal's reason. */
	public const REASON = 'stale_subscription';

	/**
	 * Whether this queue row would put a stale subscription into Smaily.
	 *
	 * @param array<string, mixed>|null $payload The row's decoded payload; null
	 *                                           when it does not decode.
	 */
	public static function is_stale( EventQueue $queue, int $id, string $event_type, ?array $payload ): bool {
		if ( $event_type !== HookHandler::EVENT_CONTACT_SYNC || ! self::subscribes( $payload ) ) {
			return false;
		}

		$email = is_string( $payload['email'] ?? null ) ? $payload['email'] : '';
		if ( $email === '' ) {
			return false;
		}

		foreach ( $queue->later_delivered_payloads( $event_type, $email, $id ) as $later ) {
			$decoded = json_decode( $later, true );
			if ( is_array( $decoded ) && isset( $decoded['is_unsubscribed'] ) ) {
				return true;
			}
		}

		return false;
	}

	/** The plain sentence a refused Retry of a stale row answers with. */
	public static function message(): string {
		return __( 'The shopper has since changed their newsletter subscription, and the newer choice already reached Smaily. This older change is not sent again.', 'smaily-connect' );
	}

	/**
	 * @param array<string, mixed>|null $payload
	 */
	private static function subscribes( ?array $payload ): bool {
		// Read the way the Flusher sends it: a status is sent when set, as an int.
		return isset( $payload['is_unsubscribed'] ) && (int) $payload['is_unsubscribed'] === 0;
	}
}
