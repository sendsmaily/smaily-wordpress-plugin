<?php
/**
 * Whether a marketing automation may go to a contact under the
 * contact-sync mode (CON-196, CON-386).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Smaily creates an address an automation is triggered for as a subscribed
 * contact, so an automation that can reach a shopper who never subscribed —
 * the abandoned-cart reminder (CON-196) and the first-order email (CON-386)
 * — goes out only where that is the shopper's consent or the store's lawful
 * basis:
 *
 *   - Smaily has the contact as unsubscribed → never, in any mode;
 *   - legitimate interest ("All customers") → otherwise always;
 *   - consent / checkout opt-in → only with the shopper's consent: the
 *     store's record (`user_newsletter`, consent mode only — checkout-only
 *     keeps none for an account), the newsletter box ticked at the checkout
 *     the row came from, or a subscribed Smaily contact.
 *
 * The consent inputs ride the queue payload at the top level (`user_id`,
 * `newsletter_optin`); the store record and Smaily are read at send time.
 * The contact is read from the account contact sync keeps it in —
 * ContactAccount, the account of its language in the account-per-language
 * mode, else "default" (CON-446). When that account does not hold the
 * contact, an unsubscribe in another account of the store counts too
 * (UnsubscribeCarryOver, CON-482). Independent of the "Sync contacts to
 * Smaily" switch (PRO-1742).
 *
 * The one rule both flushers ask — never copy it.
 */
final class AutomationConsent {

	/** Skip reason: a consent mode, and the shopper consented nowhere. */
	public const REASON_NO_MARKETING_CONSENT = 'no_marketing_consent';

	/** Skip reason: Smaily has the contact as unsubscribed — any mode. */
	public const REASON_UNSUBSCRIBED = 'unsubscribed';

	/**
	 * Why the automation may not go out, or '' when it may.
	 *
	 * @param Client                    $client      The client of the account the contact is kept in.
	 * @param array<string, mixed>      $payload     The queue row's decoded payload.
	 * @param UnsubscribeCarryOver|null $carry_over  Reads the store's other accounts (CON-482); null: none.
	 * @param string                    $account_key The account $client belongs to.
	 *
	 * @throws ApiException When Smaily cannot be read — the caller retries the
	 *                      row, so nothing is sent blind.
	 */
	public static function refusal( Client $client, string $email, array $payload, ContactSyncMode $mode, ?UnsubscribeCarryOver $carry_over = null, string $account_key = 'default' ): string {
		$contact = $client->get_contact_consent( $email );

		if ( $contact['found'] && $contact['is_unsubscribed'] === '1' ) {
			return self::REASON_UNSUBSCRIBED;
		}

		// A contact its account does not hold can be kept, unsubscribed, in
		// another account of the store (CON-482).
		if ( ! $contact['found'] && $carry_over !== null && $carry_over->unsubscribed_in_other_accounts( $account_key, $email ) ) {
			return self::REASON_UNSUBSCRIBED;
		}

		if ( ! $mode->requires_optin() ) {
			return '';
		}

		if ( $contact['found'] && $contact['is_unsubscribed'] === '0' ) {
			return '';
		}

		if ( ( $payload['newsletter_optin'] ?? false ) === true ) {
			return '';
		}

		$user_id = isset( $payload['user_id'] ) ? (int) $payload['user_id'] : 0;
		if ( $mode->mode() === ContactSyncMode::MODE_CONSENT && $user_id > 0
			&& (int) get_user_meta( $user_id, ContactAudience::OPTIN_META, true ) === 1
		) {
			return '';
		}

		return self::REASON_NO_MARKETING_CONSENT;
	}
}
