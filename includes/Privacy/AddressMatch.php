<?php
/**
 * The address match of the personal-data exporter and eraser (PRO-3909).
 *
 * @package Smaily\Connect\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * One rule for "this row belongs to the requester's address", built as SQL.
 * Letter case does not matter; an accent does — `jäne@…` is another mailbox
 * than `jane@…`. The usual collations (`utf8mb4_unicode_520_ci` and the
 * like) ignore accents, so every comparison here is binary after lowering
 * (PRO-3986, PRO-2448, PRO-2384, PRO-3993).
 *
 * Two shapes, because the stores keep the address in two ways:
 * - column_equals(): a column that holds the address alone (an order's
 *   billing address, the cart tracker's `email`);
 * - json_string(): a stored JSON blob that holds the address as one string
 *   value (the Smaily queue's `payload`, the Campaign Intelligence queue's
 *   `sent_payload`).
 */
final class AddressMatch {

	/**
	 * `$column` equals the address (one `%s`, the address as given): the
	 * database lowercases both sides, then compares bytes.
	 *
	 * @param string $column A column name from code, never from a request.
	 */
	public static function column_equals( string $column ): string {
		return "CAST( LOWER( {$column} ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY )";
	}

	/**
	 * `$column` holds the address as a whole JSON string, in either form it
	 * can be stored in: as typed, and as wp_json_encode() writes it (`\uXXXX`
	 * for a non-ASCII character, `\/` for a slash — PRO-2448). The quotes on
	 * both sides keep a longer address (`xjane@…`) out. The address is
	 * trimmed and lowercased with strtolower(); the LIKE is binary, so no
	 * accent folding. A row matches when any pattern matches.
	 *
	 * @param string             $column   A column, or an SQL expression over
	 *                                     one (`LOWER( payload )`), from code.
	 * @param string             $email    The requester's address.
	 * @param array<int, string> $prefixes Text that must come right before
	 *                                     the address (`'"email":'`); `''`
	 *                                     for none.
	 *
	 * @return array{0: string, 1: array<int, string>}|null The parenthesised
	 *         condition and its arguments; null for an empty address.
	 */
	public static function json_string( string $column, string $email, array $prefixes = array( '' ) ): ?array {
		global $wpdb;

		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return null;
		}

		$patterns = array();
		foreach ( $prefixes as $prefix ) {
			foreach ( array_unique( array( '"' . $email . '"', (string) wp_json_encode( $email ) ) ) as $form ) {
				$patterns[] = '%' . $wpdb->esc_like( $prefix . $form ) . '%';
			}
		}

		return array(
			'( ' . implode( ' OR ', array_fill( 0, count( $patterns ), "{$column} LIKE CAST( %s AS BINARY )" ) ) . ' )',
			$patterns,
		);
	}
}
