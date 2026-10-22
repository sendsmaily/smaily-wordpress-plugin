<?php
/**
 * One-time drain of in-flight legacy abandoned-cart rows (PRO-1195).
 *
 * @package Smaily\Connect\Migration
 */

declare(strict_types=1);

namespace Smaily\Connect\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- one-time read of the legacy plugin table on upgrade; names are prefix + constants, values prepared.

use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Support\DebugLog;

/**
 * Upgrade continuity for the abandoned-cart rewrite: carts the LEGACY
 * pipeline was still tracking when the merchant updates the plugin must not
 * be lost. This copies every un-sent legacy row (`mail_sent IS NULL` —
 * 'open' AND 'abandoned' alike) into the new `smly_plus_cart_session`
 * tracker, preserving the ORIGINAL `cart_updated` so the new sweeper applies
 * the same cutoff + F3-37 backlog-guard semantics the legacy pass would have
 * (a recent cart gets its reminder; a stale one expires without emailing).
 *
 * Runs only from schema migration 012 (`RetiredTables`, CON-182), right
 * before that migration drops the legacy table — a store can still update
 * straight from 1.x / 2.0.0, which never ran this drain.
 *
 * Safety properties (each one a scar):
 *   - READ-ONLY on the legacy table — no row is mutated or deleted; dropping
 *     the table is migration 012's step, after this one.
 *   - One-time via the `smly_plus_cart_legacy_drained` option stamp — a store
 *     that drained on an earlier version is not drained twice.
 *   - A failed read of the legacy table THROWS (never stamps), so migration
 *     012 keeps the table and retries on the next update instead of dropping
 *     carts that were never copied.
 *   - Legacy `cart_content` is UNTRUSTED wire input (F3-53: old-writer rows
 *     deserialize to string items; an offset read on them is a PHP 8 fatal):
 *     non-array content is counted + logged and skipped; non-array/keyless
 *     ITEMS are skipped item-level; a per-row Throwable backstop keeps one
 *     poison row from aborting the whole drain.
 *   - Schedules NOTHING — F3-53's hard rule: an upgrade-time migration must
 *     never (re-)arm a legacy WP-Cron schedule. Scheduling stays owned by
 *     the AS `smly_plus_*` recurring actions.
 *
 * Token choice: `(string) customer_id` — exactly what the live tracker uses
 * for a logged-in session (WC session customer id = the user id), so when
 * the shopper returns the live upsert lands on the SAME row instead of
 * creating a duplicate.
 */
class LegacyCartDrain {

	public const DRAINED_OPTION = 'smly_plus_cart_legacy_drained';

	private const LEGACY_TABLE_SUFFIX = RetiredTables::LEGACY_CART_TABLE;

	private CartSessionStore $store;

	public function __construct( ?CartSessionStore $store = null ) {
		$this->store = $store ?? new CartSessionStore();
	}

	/**
	 * Run the drain exactly once per install.
	 *
	 * @return array{drained: int, poison: int, skipped: int}|null Stats, or
	 *         null when the drain already ran.
	 *
	 * @throws \RuntimeException When the legacy table exists but cannot be read.
	 */
	public function maybe_run(): ?array {
		if ( get_option( self::DRAINED_OPTION, false ) !== false ) {
			return null;
		}

		$stats = $this->drain();

		update_option( self::DRAINED_OPTION, gmdate( 'Y-m-d H:i:s' ), false );

		if ( $stats['drained'] > 0 || $stats['poison'] > 0 || $stats['skipped'] > 0 ) {
			DebugLog::write(
				sprintf(
					'[smaily-connect cart.drain] Legacy abandoned-cart drain: %d cart(s) migrated to the new tracker, %d poison row(s) skipped, %d row(s) without a usable identity skipped. Legacy table dropped next (migration 012).',
					$stats['drained'],
					$stats['poison'],
					$stats['skipped']
				)
			);
		}

		return $stats;
	}

	/**
	 * @return array{drained: int, poison: int, skipped: int}
	 */
	private function drain(): array {
		global $wpdb;

		$stats = array(
			'drained' => 0,
			'poison'  => 0,
			'skipped' => 0,
		);

		$table  = $wpdb->prefix . self::LEGACY_TABLE_SUFFIX;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( self::db_error() ) {
			// "Could not look" is not "no table" — never stamp on it.
			throw new \RuntimeException( 'Could not check for the legacy abandoned-cart table.' );
		}
		if ( $exists !== $table ) {
			return $stats;
		}

		$rows = $wpdb->get_results(
			"SELECT customer_id, cart_updated, cart_content FROM {$table} WHERE mail_sent IS NULL",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || self::db_error() ) {
			// The table exists but could not be read: migration 012 must not
			// drop it with carts still uncopied (CON-182).
			throw new \RuntimeException( 'The legacy abandoned-cart table could not be read.' );
		}

		foreach ( $rows as $row ) {
			try {
				$customer_id = isset( $row['customer_id'] ) ? (int) $row['customer_id'] : 0;

				$user = $customer_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $customer_id ) : false;
				if ( ! $user instanceof \WP_User || (string) $user->user_email === '' ) {
					// The legacy tracker only stored logged-in users; a row
					// whose user is gone can never be emailed.
					++$stats['skipped'];
					continue;
				}

				$content = maybe_unserialize( isset( $row['cart_content'] ) ? $row['cart_content'] : '' );
				if ( ! is_array( $content ) ) {
					++$stats['poison'];
					DebugLog::write(
						sprintf(
							'[smaily-connect cart.drain] Legacy cart for customer %d has malformed cart_content (%s) - skipped.',
							$customer_id,
							gettype( $content )
						)
					);
					continue;
				}

				$items = array();
				foreach ( $content as $cart_item ) {
					// F3-53: old-writer rows carry bare STRING items — skip
					// item-level, keep the cart (the legacy hardening's exact
					// semantics: a structurally-sound cart still sends).
					if ( ! is_array( $cart_item ) || ! isset( $cart_item['product_id'] ) || ! is_scalar( $cart_item['product_id'] ) ) {
						continue;
					}
					$items[] = array(
						'product_id'   => (int) $cart_item['product_id'],
						'variation_id' => isset( $cart_item['variation_id'] ) && is_scalar( $cart_item['variation_id'] )
							? (int) $cart_item['variation_id']
							: 0,
						'quantity'     => isset( $cart_item['quantity'] ) && is_scalar( $cart_item['quantity'] )
							? (int) $cart_item['quantity']
							: 1,
					);
				}

				$this->store->upsert(
					(string) $customer_id,
					$customer_id,
					(string) $user->user_email,
					(string) $user->first_name,
					(string) $user->last_name,
					$items,
					$this->normalize_timestamp( isset( $row['cart_updated'] ) ? (string) $row['cart_updated'] : '' )
				);
				++$stats['drained'];
			} catch ( \Throwable $e ) {
				// One poison row must never abort the drain (F3-53 backstop).
				++$stats['poison'];
				DebugLog::write(
					sprintf(
						'[smaily-connect cart.drain] Legacy cart row failed with %s: %s - skipped.',
						get_class( $e ),
						$e->getMessage()
					)
				);
			}
		}

		return $stats;
	}

	/**
	 * Whether the last $wpdb query failed. A method, not an inline read: a
	 * failed query returns an empty result, so the error text is the only
	 * signal (and static analysis cannot see a query reset it).
	 *
	 * @phpstan-impure
	 */
	private static function db_error(): bool {
		global $wpdb;

		return (string) $wpdb->last_error !== '';
	}

	/**
	 * Legacy `cart_updated` values were WRITTEN in the Z-form but read back
	 * from MySQL as 'Y-m-d H:i:s' (both UTC). Normalize to the tracker's
	 * 'Y-m-d H:i:s' so the sweeper's string comparisons hold. An unparsable
	 * value maps to the epoch — a cart of UNKNOWN age must expire under the
	 * F3-37 backlog guard, never masquerade as fresh and mass-mail.
	 */
	private function normalize_timestamp( string $value ): string {
		$ts = $value !== '' ? strtotime( $value ) : false;
		if ( $ts === false ) {
			$ts = 0;
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
