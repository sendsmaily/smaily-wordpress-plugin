<?php
/**
 * Mirrors Smaily's marketing-consent state back into WP (consent mode only).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Smaily → WP marketing-consent sync-back (F3-48, preset "Subscribers only").
 * Keeps the WP `user_newsletter` opt-in flag a faithful mirror of Smaily, so a
 * customer who unsubscribes (or re-subscribes) in Smaily leaves (or re-enters)
 * the consent-mode audience and the checkout/account checkbox reflects reality.
 *
 * Marketing only — NEVER touches profiling consent (`smaily_rec_profiling`); a
 * marketing unsubscribe is not an Art-21 profiling objection
 * (docs/DATA_MODEL_GDPR.md, docs/CONSENT_STRATEGY_COMPARISON.md).
 *
 * Delta-first (Erkki, shared-hosting resource concern): the standing reconcile
 * polls the Smaily **action log** (`history.php`, `since_seq_id` cursor) for
 * only `optin`/`optout`/`delete`/`complaint` events — O(changes), a handful of
 * requests even for a large base. The full `list=1` pull (`rebaseline()`) is the
 * occasional re-baseline only (onboarding / manual / a poller dormant past the
 * action-log's ~30-day retention). Pull-only: Smaily has no webhooks
 * (re/docs/smaily-api/reference/action-log.md).
 *
 * Only runs in consent mode (`ContactSyncMode::reconciles()`); legitimate
 * interest doesn't filter by consent and checkout-only has no accounts.
 */
final class ContactReconciler {

	/** Durable action-log cursor (max processed seq_id). */
	public const OPTION_CURSOR = 'smly_plus_contact_reconcile_seq';

	public const OPTIN_META = ContactAudience::OPTIN_META;

	/** Action types that move a contact's marketing-consent state. */
	private const RECONCILE_ACTIONS = array( 'optin', 'optout', 'delete', 'complaint' );

	/** Backstop on the paginating loops so a runaway never hangs a cron tick. */
	private const MAX_PAGES = 50;

	/**
	 * Reentrancy guard: true while apply() writes `user_newsletter`. The
	 * HookHandler's WP→Smaily meta-transition handler checks this and skips —
	 * otherwise a Smaily→WP reconcile write would echo straight back to Smaily
	 * (and a Smaily `delete` mirrored to WP would re-CREATE the deleted contact,
	 * fighting GDPR erasure — security re-audit 2026-06-30).
	 */
	private static bool $applying = false;

	private Client $client;

	private ContactSyncMode $mode;

	public function __construct( Client $client, ?ContactSyncMode $mode = null ) {
		$this->client = $client;
		$this->mode   = $mode ?? new ContactSyncMode();
	}

	/**
	 * Standing reconcile — apply the action-log deltas since the cursor. The
	 * first run (no cursor) drains up to the action log's 30-day window, which
	 * baselines recent state; `rebaseline()` covers anything older.
	 *
	 * @return int Number of WP users whose opt-in flag changed.
	 */
	public function reconcile(): int {
		if ( ! $this->mode->reconciles() ) {
			return 0;
		}

		$cursor  = $this->cursor();
		$changed = 0;
		$pages   = 0;

		do {
			$rows = $this->client->get_action_log( $cursor, self::RECONCILE_ACTIONS );
			if ( $rows === array() ) {
				break;
			}

			foreach ( $rows as $row ) {
				$seq = isset( $row['seq_id'] ) ? (int) $row['seq_id'] : 0;
				if ( $seq > $cursor ) {
					$cursor = $seq;
				}

				$email  = isset( $row['email'] ) ? (string) $row['email'] : '';
				$action = isset( $row['action'] ) ? (string) $row['action'] : '';
				if ( $email !== '' && $action !== '' ) {
					$changed += $this->apply( $email, $action );
				}
			}

			++$pages;
			$full_page = count( $rows ) >= 10000;
		} while ( $full_page && $pages < self::MAX_PAGES );

		$this->save_cursor( $cursor );

		return $changed;
	}

	/**
	 * Occasional full re-baseline — page the whole subscriber list and set
	 * `user_newsletter` from each contact's current `is_unsubscribed`. Run on
	 * onboarding / manually / after a dormant period, NOT every tick. Does not
	 * touch the delta cursor (the action-log poll keeps that independently).
	 *
	 * @return int Number of WP users whose opt-in flag changed.
	 */
	public function rebaseline(): int {
		if ( ! $this->mode->reconciles() ) {
			return 0;
		}

		$changed = 0;
		$offset  = 0;

		do {
			$rows = $this->client->list_contacts( $offset );
			if ( $rows === array() ) {
				break;
			}

			foreach ( $rows as $row ) {
				$email = isset( $row['email'] ) ? (string) $row['email'] : '';
				if ( $email === '' ) {
					continue;
				}

				$unsubscribed = isset( $row['is_unsubscribed'] ) ? (int) $row['is_unsubscribed'] : 0;
				$changed     += $this->apply( $email, $unsubscribed === 1 ? 'optout' : 'optin' );
			}

			++$offset;
			$full_page = count( $rows ) >= 25000;
		} while ( $full_page && $offset < self::MAX_PAGES );

		return $changed;
	}

	/**
	 * Mirror one Smaily action onto a matching WP user's opt-in flag. `optin` →
	 * subscribed (1); everything else (optout/delete/complaint) → 0. Returns 1
	 * when the flag actually changed, 0 otherwise (idempotent — no write when
	 * already correct, no write for a non-WP / engine-only contact).
	 */
	private function apply( string $email, string $action ): int {
		if ( ! function_exists( 'get_user_by' ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof \WP_User ) {
			return 0;
		}

		$desired = ( $action === 'optin' ) ? 1 : 0;
		$current = (int) get_user_meta( (int) $user->ID, self::OPTIN_META, true );
		if ( $current === $desired ) {
			return 0;
		}

		// Suppress the WP→Smaily echo: this write is the Smaily→WP direction.
		self::run_suppressed(
			static function () use ( $user, $desired ): void {
				update_user_meta( (int) $user->ID, self::OPTIN_META, $desired );
			}
		);

		return 1;
	}

	/** True while a reconcile write is in flight — the meta-transition handler skips. */
	public static function is_applying(): bool {
		return self::$applying;
	}

	/**
	 * Run $fn with the reconcile guard set, restoring the previous value after
	 * (nesting-safe). Public so the meta-transition handler's suppression is
	 * unit-testable without a live reconcile.
	 *
	 * @param callable():void $fn
	 */
	public static function run_suppressed( callable $fn ): void {
		$previous       = self::$applying;
		self::$applying = true;
		try {
			$fn();
		} finally {
			self::$applying = $previous;
		}
	}

	private function cursor(): int {
		$raw = get_option( self::OPTION_CURSOR, 0 );

		return is_numeric( $raw ) ? (int) $raw : 0;
	}

	private function save_cursor( int $seq ): void {
		update_option( self::OPTION_CURSOR, $seq, false );
	}
}
