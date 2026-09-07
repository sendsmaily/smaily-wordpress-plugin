<?php
/**
 * Test-support helper — raw row access to the two durable queue tables.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Why a helper: several integration tests need to plant a queue row the
 * plugin's own enqueue() would never write (a controlled created_at for the
 * janitor's retention window, a pre-migration-011 row carrying no
 * contact_key) and to read one back by id. That is the same three moves —
 * table name, raw insert, read by id — in every one of them.
 */
final class QueueRowFixture {

	/** The prefixed table name for one of the queue TABLE_SUFFIX constants. */
	public static function table( string $table_suffix ): string {
		global $wpdb;
		return $wpdb->prefix . $table_suffix;
	}

	/**
	 * Insert a row exactly as given — no defaults, no key derivation — and
	 * return its id.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function insert( string $table_suffix, array $row ): int {
		global $wpdb;

		$wpdb->insert( self::table( $table_suffix ), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function row( string $table_suffix, int $id ): ?array {
		global $wpdb;
		$table = self::table( $table_suffix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	public static function exists( string $table_suffix, int $id ): bool {
		return self::row( $table_suffix, $id ) !== null;
	}
}
