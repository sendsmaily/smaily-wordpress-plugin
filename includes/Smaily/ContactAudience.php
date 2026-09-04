<?php
/**
 * Decides whether a given user / guest email is in the contact-sync audience.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * The mode-aware "is this person a contact we sync?" gate (F3-48). One source
 * used by the live HookHandler, the BackfillJob mass walk, and the reconciler,
 * so live-sync and backfill never disagree on the audience.
 *
 * With the "Sync contacts to Smaily" switch off the audience is empty, whatever
 * the mode says (PRO-1742) — so the backfill walk and the "about N will be
 * synced" estimate honour the switch exactly like the live hooks do.
 *
 * Opt-in is read from the legacy `user_newsletter` user meta — the WP-side
 * consent record the registration / account / checkout checkboxes write
 * (profile-settings.class.php).
 */
final class ContactAudience {

	public const OPTIN_META = 'user_newsletter';

	private ContactSyncMode $mode;

	public function __construct( ?ContactSyncMode $mode = null ) {
		$this->mode = $mode ?? new ContactSyncMode();
	}

	/**
	 * Should this registered user be synced as a Smaily contact?
	 *
	 *   - sync switched off   → no-one (PRO-1742)
	 *   - checkout_optin      → no (no account-based sync)
	 *   - legitimate_interest → yes (all registered customers)
	 *   - consent             → only when opted in (user_newsletter=1)
	 */
	public function should_sync_user( \WP_User $user ): bool {
		if ( ! ContactSyncMode::sync_enabled() ) {
			return false;
		}

		if ( ! $this->mode->syncs_accounts() ) {
			return false;
		}

		if ( ! $this->mode->requires_optin() ) {
			return true;
		}

		return $this->opted_in( (int) $user->ID );
	}

	/**
	 * Should an ORDER's billing email be synced as a contact (the checkout
	 * path, F3-48)? Registered customers are covered by the account hooks, so in
	 * consent / legitimate-interest this only adds GUESTS:
	 *
	 *   - checkout_optin      → the checkbox is the only source: sync (guest OR
	 *                           account) iff opted in;
	 *   - legitimate_interest → guests synced iff include_guests (no opt-in needed);
	 *   - consent             → guests synced iff include_guests AND opted in.
	 *
	 * @param int  $customer_id Order's WP customer id (0 = guest).
	 * @param bool $opted_in    Whether the checkout subscription checkbox was ticked.
	 */
	public function should_sync_order_email( int $customer_id, bool $opted_in ): bool {
		if ( ! ContactSyncMode::sync_enabled() ) {
			return false;
		}

		if ( $this->mode->mode() === ContactSyncMode::MODE_CHECKOUT_OPTIN ) {
			return $opted_in;
		}

		if ( $customer_id > 0 ) {
			return false;
		}

		if ( ! $this->mode->include_guests() ) {
			return false;
		}

		return $this->mode->requires_optin() ? $opted_in : true;
	}

	/**
	 * How many registered users are in the sync audience (F3-55) — the
	 * mode-aware count behind the backfill's "N contacts will sync" number.
	 * MUST agree with should_sync_user(): both live in this class and the
	 * integration test seeds users and asserts SQL count == per-user filter.
	 *
	 *   - checkout_optin      → 0 (no account-based sync)
	 *   - legitimate_interest → every registered user
	 *   - consent             → users with user_newsletter=1
	 */
	public function count_audience(): int {
		if ( ! ContactSyncMode::sync_enabled() ) {
			return 0;
		}

		if ( ! $this->mode->syncs_accounts() ) {
			return 0;
		}

		if ( ! $this->mode->requires_optin() ) {
			$counts = count_users();
			return (int) $counts['total_users'];
		}

		global $wpdb;

		// The SQL twin of opted_in(): meta_value compares as the string '1'
		// because opted_in() casts to int and requires === 1 — any other
		// stored value ('0', '', 'yes') is not opted in there either.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'",
				self::OPTIN_META
			)
		);
	}

	private function opted_in( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}
		return (int) get_user_meta( $user_id, self::OPTIN_META, true ) === 1;
	}
}
