<?php
/**
 * Storage for the namespaced abandoned-cart tracker (smly_plus_cart_session).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Privacy\AddressMatch;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table: the interpolated name is $wpdb->prefix + a class constant; every value goes through $wpdb->prepare(). Object-cache is N/A for a write-through tracker.

/**
 * CRUD for the cart tracker rows behind the PRO-1195 abandoned-cart rewrite.
 *
 * One row per WC session (`cart_token` = WC session customer id — the user id
 * for logged-in customers, an opaque hash for guests). `cart_content` is OUR
 * OWN minimal JSON shape ([{product_id, variation_id, quantity}], wrapped
 * with the classic checkout's newsletter tick while it is ticked — see
 * encode_content()), never a serialized WC object — the F3-53 poison class
 * was `serialize(get_cart())` rows deserializing to something a later plugin
 * version couldn't read.
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
	 *
	 * @return bool Whether the row is stored. False when the database refused
	 *              the write, and when there was nothing to store (an empty
	 *              token, content that does not encode). The legacy drain reads
	 *              it (CON-207); the live tracker does not need it.
	 */
	public function upsert(
		string $cart_token,
		int $user_id,
		string $email,
		string $first_name,
		string $last_name,
		array $items,
		?string $cart_updated = null
	): bool {
		global $wpdb;

		if ( $cart_token === '' ) {
			return false;
		}

		$now     = current_time( 'mysql', true );
		$updated = $cart_updated ?? $now;
		$table   = $this->table_name();

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$table} is $this->table_name(), a hardcoded internal constant, not user input; the only real value goes through $wpdb->prepare().
		$existing    = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, cart_content FROM {$table} WHERE cart_token = %s", $cart_token ),
			ARRAY_A
		);
		$existing_id = is_array( $existing ) ? $existing['id'] : null;

		// A cart change keeps the newsletter tick the checkout reported for
		// this session (CON-196): the classic order review saves the cart
		// right after it reports the box.
		$ticked = is_array( $existing ) && ( self::decode_content( (string) $existing['cart_content'] )['newsletter_optin'] ?? false );

		$json = self::encode_content( $items, $ticked );
		if ( $json === null ) {
			return false;
		}

		if ( $existing_id === null ) {
			return $wpdb->insert(
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
			) !== false;
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

		// 0 rows changed is a stored row too: the same cart saved again.
		return $wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) ) !== false;
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

	/**
	 * Record whether the shopper has the newsletter box ticked in this
	 * session's unfinished classic checkout (CON-196) — the last report
	 * wins, so unticking clears it. No-op when the session has no tracked
	 * cart.
	 */
	public function set_newsletter_optin( string $cart_token, bool $ticked ): void {
		global $wpdb;

		if ( $cart_token === '' ) {
			return;
		}

		$table = $this->table_name();
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$table} is $this->table_name(), a hardcoded internal constant, not user input; the only real value goes through $wpdb->prepare().
		$content = $wpdb->get_var( $wpdb->prepare( "SELECT cart_content FROM {$table} WHERE cart_token = %s", $cart_token ) );
		if ( $content === null ) {
			return;
		}

		$decoded = self::decode_content( (string) $content );
		if ( $decoded === null || $decoded['newsletter_optin'] === $ticked ) {
			return;
		}

		$json = self::encode_content( $decoded['items'], $ticked );
		if ( $json === null ) {
			return;
		}

		$wpdb->update( $table, array( 'cart_content' => $json ), array( 'cart_token' => $cart_token ), array( '%s' ), array( '%s' ) );
	}

	/**
	 * `cart_content` as written: the item list, or — only while the shopper
	 * has the newsletter box ticked in the classic checkout (CON-196) —
	 * `{"items": [...], "newsletter_optin": true}`. An unticked cart keeps
	 * the plain list every earlier version wrote and reads.
	 *
	 * @param array<int, array<string, mixed>> $items
	 */
	public static function encode_content( array $items, bool $newsletter_optin ): ?string {
		$items = array_values( $items );
		$json  = wp_json_encode(
			$newsletter_optin
				? array(
					'items'            => $items,
					'newsletter_optin' => true,
				)
				: $items
		);

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Read either `cart_content` shape (see encode_content()). Null when the
	 * value is not ours — treat stored rows as wire input (F3-53).
	 *
	 * @return array{items: array<int, mixed>, newsletter_optin: bool}|null
	 */
	public static function decode_content( string $json ): ?array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		if ( array_key_exists( 'items', $decoded ) ) {
			return array(
				'items'            => is_array( $decoded['items'] ) ? array_values( $decoded['items'] ) : array(),
				'newsletter_optin' => ( $decoded['newsletter_optin'] ?? false ) === true,
			);
		}

		return array(
			'items'            => array_values( $decoded ),
			'newsletter_optin' => false,
		);
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

	/**
	 * Drop every session row of this address. Letter case does not matter;
	 * an accent does (`jäne@…` is another shopper — PRO-3996, AddressMatch).
	 * `idx_email` narrows the rows first, so the DELETE reads and locks only
	 * this address's rows, not the whole table (PRO-4004).
	 */
	public function delete_by_email( string $email ): void {
		global $wpdb;
		$match = AddressMatch::column_equals_indexed( 'email', $email );
		if ( $match === null ) {
			return;
		}
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The %s placeholders come from AddressMatch::column_equals_indexed(), with its arguments.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE {$match[0]}",
				...$match[1]
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Drop other session rows that carry the same email (the guest-remnant
	 * case: a guest entered their email at checkout, then logged in — the
	 * login migrates the cart to a new session token and the old guest row
	 * would double-remind the same address). Same address match as
	 * delete_by_email() (PRO-3996), through `idx_email` (PRO-4004): this
	 * runs on every tracked cart update with a known address.
	 */
	public function delete_other_rows_for_email( string $email, string $keep_token ): void {
		global $wpdb;
		$match = AddressMatch::column_equals_indexed( 'email', $email );
		if ( $match === null || $keep_token === '' ) {
			return;
		}
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The address %s placeholders come from AddressMatch::column_equals_indexed(), with its arguments.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE {$match[0]} AND cart_token != %s",
				...array_merge( $match[1], array( $keep_token ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
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
	 * subject's rows". The address is matched by the shared
	 * AddressMatch::column_equals() (PRO-3993, PRO-3909): letter case does
	 * not matter, an accent does — `jäne@` is someone else's mailbox than
	 * `jane@`.
	 *
	 * @return array{0: string, 1: array<int, string|int>}|null
	 */
	private function privacy_request_where( string $email, int $user_id ): ?array {
		if ( $email !== '' && $user_id > 0 ) {
			return array( AddressMatch::column_equals( 'email' ) . ' OR user_id = %d', array( $email, $user_id ) );
		}
		if ( $email !== '' ) {
			return array( AddressMatch::column_equals( 'email' ), array( $email ) );
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
