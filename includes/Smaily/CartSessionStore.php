<?php
/**
 * Storage for the namespaced abandoned-cart tracker (smly_plus_cart_session).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table: the interpolated name is $wpdb->prefix + a class constant; every value goes through $wpdb->prepare(). Object-cache is N/A for a write-through tracker.

/**
 * CRUD for the cart tracker rows behind the PRO-1195 abandoned-cart rewrite.
 *
 * One row per WC session (`cart_token` = WC session customer id — the user id
 * for logged-in customers, an opaque hash for guests). `cart_content` is OUR
 * OWN minimal JSON shape ([{product_id, variation_id, quantity}]), never a
 * serialized WC object — the F3-53 poison class was `serialize(get_cart())`
 * rows deserializing to something a later plugin version couldn't read.
 *
 * Lifecycle: upsert on cart activity → (email captured when known) → the
 * sweeper (CartAbandonmentSweeper) enqueues one reminder per row past the
 * merchant's cutoff and stamps `reminder_enqueued_at` (the legacy `mail_sent`
 * semantics: never a second reminder for the same row) → an order or an
 * emptied cart deletes the row. Rows older than the F3-37 backlog window are
 * pruned by the sweeper without emailing.
 *
 * Not final: tests subclass with an anonymous double (same rationale as
 * EventQueue).
 */
class CartSessionStore {

	public const TABLE_SUFFIX = 'smly_plus_cart_session';

	/**
	 * Insert or refresh the row for a cart session.
	 *
	 * Identity fields (user_id/email/names) only overwrite when non-empty, so
	 * a later anonymous touch never erases a captured checkout email. The
	 * `reminder_enqueued_at` marker is preserved on update — continued
	 * shopping after a reminder must not re-arm a second one (legacy
	 * `mail_sent` parity).
	 *
	 * @param string                            $cart_token   WC session customer id.
	 * @param int                               $user_id      WP user id, 0 for guests.
	 * @param string                            $email        Known email or ''.
	 * @param string                            $first_name   Known first name or ''.
	 * @param string                            $last_name    Known last name or ''.
	 * @param array<int, array<string, mixed>>  $items        Own-shape cart items.
	 * @param string|null                       $cart_updated UTC 'Y-m-d H:i:s'; null = now.
	 *                                                        (The legacy drain passes the
	 *                                                        original timestamp through so
	 *                                                        the backlog guard still applies.)
	 */
	public function upsert(
		string $cart_token,
		int $user_id,
		string $email,
		string $first_name,
		string $last_name,
		array $items,
		?string $cart_updated = null
	): void {
		global $wpdb;

		if ( $cart_token === '' ) {
			return;
		}

		$json = wp_json_encode( array_values( $items ) );
		if ( $json === false ) {
			return;
		}

		$now     = current_time( 'mysql', true );
		$updated = $cart_updated ?? $now;
		$table   = $this->table_name();

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$table} is $this->table_name(), a hardcoded internal constant, not user input; the only real value goes through $wpdb->prepare().
		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE cart_token = %s", $cart_token )
		);

		if ( $existing_id === null ) {
			$wpdb->insert(
				$table,
				array(
					'cart_token'   => $cart_token,
					'user_id'      => $user_id,
					'email'        => $email,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'cart_content' => $json,
					'cart_updated' => $updated,
					'created_at'   => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			return;
		}

		$data    = array(
			'cart_content' => $json,
			'cart_updated' => $updated,
		);
		$formats = array( '%s', '%s' );

		if ( $user_id > 0 ) {
			$data['user_id'] = $user_id;
			$formats[]       = '%d';
		}
		foreach ( array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		) as $column => $value ) {
			if ( $value !== '' ) {
				$data[ $column ] = $value;
				$formats[]       = '%s';
			}
		}

		$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) );
	}

	/**
	 * Attach a checkout-entered identity to an existing session row (guest
	 * flow). No-op when the session has no tracked cart. Empty values never
	 * overwrite known ones.
	 */
	public function set_identity( string $cart_token, string $email, string $first_name, string $last_name ): void {
		global $wpdb;

		if ( $cart_token === '' ) {
			return;
		}

		$data    = array();
		$formats = array();
		foreach ( array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		) as $column => $value ) {
			if ( $value !== '' ) {
				$data[ $column ] = $value;
				$formats[]       = '%s';
			}
		}

		if ( $data === array() ) {
			return;
		}

		$wpdb->update( $this->table_name(), $data, array( 'cart_token' => $cart_token ), $formats, array( '%s' ) );
	}

	public function delete_by_token( string $cart_token ): void {
		global $wpdb;
		if ( $cart_token === '' ) {
			return;
		}
		$wpdb->delete( $this->table_name(), array( 'cart_token' => $cart_token ), array( '%s' ) );
	}

	public function delete_by_user( int $user_id ): void {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return;
		}
		$wpdb->delete( $this->table_name(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	public function delete_by_email( string $email ): void {
		global $wpdb;
		if ( $email === '' ) {
			return;
		}
		$wpdb->delete( $this->table_name(), array( 'email' => $email ), array( '%s' ) );
	}

	/**
	 * Drop other session rows that carry the same email (the guest-remnant
	 * case: a guest entered their email at checkout, then logged in — the
	 * login migrates the cart to a new session token and the old guest row
	 * would double-remind the same address).
	 */
	public function delete_other_rows_for_email( string $email, string $keep_token ): void {
		global $wpdb;
		if ( $email === '' || $keep_token === '' ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE email = %s AND cart_token != %s",
				$email,
				$keep_token
			)
		);
	}

	/**
	 * Rows due a reminder: past the merchant's cutoff, inside the F3-37
	 * backlog window, no reminder yet, and an email identity known.
	 *
	 * @param string $cutoff_threshold UTC 'Y-m-d H:i:s' — cart_updated must be OLDER.
	 * @param string $min_updated      UTC 'Y-m-d H:i:s' — cart_updated must be NEWER
	 *                                 (the backlog-guard floor).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due_rows( string $cutoff_threshold, string $min_updated, int $limit = 200 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, cart_token, user_id, email, first_name, last_name, cart_content, cart_updated
				 FROM {$this->table_name()}
				 WHERE reminder_enqueued_at IS NULL
				   AND email != ''
				   AND cart_updated < %s
				   AND cart_updated >= %s
				 ORDER BY cart_updated ASC
				 LIMIT %d",
				$cutoff_threshold,
				$min_updated,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Backlog guard (F3-37): delete un-reminded rows older than the window —
	 * a stale reminder is worthless and a re-armed scheduler must never
	 * mass-mail history. Returns the expired count so the sweeper can log it.
	 */
	public function delete_expired( string $min_updated ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE reminder_enqueued_at IS NULL AND cart_updated < %s",
				$min_updated
			)
		);
	}

	/**
	 * Prune already-reminded rows whose cart went stale — dead weight (the
	 * row only exists to suppress a second reminder while the cart is live).
	 */
	public function prune_notified( string $older_than ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE reminder_enqueued_at IS NOT NULL AND cart_updated < %s",
				$older_than
			)
		);
	}

	/**
	 * Stamp the one-reminder-per-cart marker (legacy `mail_sent` semantics).
	 */
	public function mark_reminder_enqueued( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array( 'reminder_enqueued_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rows relevant to a WP Privacy subject-access request (PRO-1343): matched
	 * by the email column, plus (belt-and-suspenders) any row keyed to the WP
	 * user id whose account email is this address. Current write paths
	 * (`CartHookHandler::current_identity()`, `LegacyCartDrain`) always
	 * populate `email` whenever `user_id` is set, so the user_id branch is
	 * defensive, not the primary match.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rows_for_privacy_request( string $email, int $user_id = 0 ): array {
		global $wpdb;

		$where = $this->privacy_request_where( $email, $user_id );
		if ( $where === null ) {
			return array();
		}

		$sql = "SELECT id, cart_token, user_id, email, first_name, last_name, cart_content, cart_updated, reminder_enqueued_at, created_at FROM {$this->table_name()} WHERE {$where[0]}";
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$this->table_name()} is a hardcoded internal constant, not user input; the only real values go through $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$where[1] ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete the same row set as {@see rows_for_privacy_request()} (Art 17
	 * erasure). Returns the number of rows removed so the caller can report
	 * `items_removed` truthfully.
	 */
	public function delete_rows_for_privacy_request( string $email, int $user_id = 0 ): int {
		global $wpdb;

		$where = $this->privacy_request_where( $email, $user_id );
		if ( $where === null ) {
			return 0;
		}

		$sql = "DELETE FROM {$this->table_name()} WHERE {$where[0]}";
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$this->table_name()} is a hardcoded internal constant, not user input; the only real values go through $wpdb->prepare().
		return (int) $wpdb->query( $wpdb->prepare( $sql, ...$where[1] ) );
	}

	/**
	 * The shared match condition for both privacy-request methods above, so
	 * the lookup and the delete can never drift apart on what counts as "this
	 * subject's rows".
	 *
	 * @return array{0: string, 1: array<int, string|int>}|null
	 */
	private function privacy_request_where( string $email, int $user_id ): ?array {
		if ( $email !== '' && $user_id > 0 ) {
			return array( 'email = %s OR user_id = %d', array( $email, $user_id ) );
		}
		if ( $email !== '' ) {
			return array( 'email = %s', array( $email ) );
		}
		if ( $user_id > 0 ) {
			return array( 'user_id = %d', array( $user_id ) );
		}
		return null;
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
