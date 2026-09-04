<?php
/**
 * Contact-sync mode — the merchant's lawful-basis preset and the policy it implies.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the contact-sync mode preset (F3-48) and exposes the coherent policy
 * each preset implies. Single source of "what does this store's contact sync
 * do" — consumed by ContactAudience, the HookHandler live gate, BackfillJob,
 * the reconciler, and AutomationRouter. See docs/CONTACT_SYNC_MODES.md.
 *
 * Presets:
 *   - legitimate_interest — all registered customers; no opt-in filter; no
 *     reconcile.
 *   - consent (DEFAULT)   — only opted-in (user_newsletter=1); bidirectional
 *     Smaily<->WP reconcile.
 *   - checkout_optin      — no account sync; checkout checkbox only (guests).
 *
 * Automation triggers behave the same under every preset: they never
 * re-subscribe a contact who unsubscribed in Smaily (PRO-1716).
 *
 * Default is `consent`: lawful-safe AND back-compat (legacy's cron already
 * filtered user_newsletter=1), so an upgrade never silently broadens the
 * audience.
 */
final class ContactSyncMode {

	/**
	 * The master "Sync contacts to Smaily" switch (wizard step 2 / Settings →
	 * Subscribers). It keeps the legacy option name because that is the one
	 * every version of this plugin has ever written — the wizard's save route
	 * today, the pre-wizard settings page before it — so a store that switched
	 * contact sync off keeps its answer without any migration (PRO-1742).
	 */
	public const OPTION_SYNC_ENABLED = 'smaily_connect_subscriber_sync_enabled';

	public const OPTION_MODE           = 'smly_plus_contact_sync_mode';
	public const OPTION_INCLUDE_GUESTS = 'smly_plus_contact_sync_include_guests';

	public const MODE_LEGITIMATE_INTEREST = 'legitimate_interest';
	public const MODE_CONSENT             = 'consent';
	public const MODE_CHECKOUT_OPTIN      = 'checkout_optin';

	public const DEFAULT_MODE = self::MODE_CONSENT;

	/** @var array<int, string> */
	private const VALID_MODES = array(
		self::MODE_LEGITIMATE_INTEREST,
		self::MODE_CONSENT,
		self::MODE_CHECKOUT_OPTIN,
	);

	private ?string $mode = null;

	/**
	 * Is contact sync switched on at all? Read straight from the option on
	 * every call — the merchant's change lands on the next contact, not the
	 * next request — and never memoised, so this is the one answer the live
	 * hooks, the backfill audience and the wizard all get.
	 *
	 * Default on: that is the wizard's own default, and a store that never
	 * saved the setting is a fresh install rather than an opt-out. Off is
	 * whatever the switch stores for "off" — a bool false, or the empty string
	 * WordPress writes for it.
	 */
	public static function sync_enabled(): bool {
		$value = get_option( self::OPTION_SYNC_ENABLED, true );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return $value === 1 || $value === '1' || $value === 'yes' || $value === 'true';
	}

	/** True when $mode is one of the three presets — used to validate writes. */
	public static function is_valid_mode( string $mode ): bool {
		return in_array( $mode, self::VALID_MODES, true );
	}

	/**
	 * The active preset, validated — an unknown stored value falls back to the
	 * lawful-safe default rather than dragging a bogus mode through the policy.
	 */
	public function mode(): string {
		if ( $this->mode === null ) {
			$raw        = get_option( self::OPTION_MODE, self::DEFAULT_MODE );
			$raw        = is_string( $raw ) ? $raw : self::DEFAULT_MODE;
			$this->mode = in_array( $raw, self::VALID_MODES, true ) ? $raw : self::DEFAULT_MODE;
		}
		return $this->mode;
	}

	/** Registered customers are synced in every preset except checkout-only. */
	public function syncs_accounts(): bool {
		return $this->mode() !== self::MODE_CHECKOUT_OPTIN;
	}

	/** A contact must have opted in under consent + checkout; not under legitimate interest. */
	public function requires_optin(): bool {
		return $this->mode() !== self::MODE_LEGITIMATE_INTEREST;
	}

	/** Guest-order emails are synced — intrinsic to checkout-only, a toggle (default off) otherwise. */
	public function include_guests(): bool {
		if ( $this->mode() === self::MODE_CHECKOUT_OPTIN ) {
			return true;
		}
		return $this->bool_option( self::OPTION_INCLUDE_GUESTS );
	}

	/** The store mirrors Smaily's unsubscribes/returns back into WP (consent only). */
	public function reconciles(): bool {
		return $this->mode() === self::MODE_CONSENT;
	}

	private function bool_option( string $key ): bool {
		$value = get_option( $key, false );
		return $value === true || $value === 1 || $value === '1' || $value === 'yes' || $value === 'true';
	}
}
