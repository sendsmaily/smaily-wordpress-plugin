<?php
/**
 * Integration: every migration runs cleanly on plugin activation.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Constants;

/**
 * What Faas-2 bug this catches:
 *
 *   Sub-PR 2.H.7 — dbDelta silently dropped tables.
 *
 *   One migration had a comment that contained a semicolon, and
 *   dbDelta's parser split SQL on every `;` including comment ones,
 *   so the second half of the CREATE TABLE statement vanished. The
 *   migrator wrote schema_version=N successfully, then later code
 *   tried to INSERT into a table that was never created and crashed
 *   at runtime — only Erkki's staging surfaced it.
 *
 *   This test asserts that AFTER a clean install + activation, every
 *   expected table is present in the live MariaDB, and that
 *   smly_plus_schema_version matches the highest migration version
 *   in the migrations/ directory.
 */
final class SchemaMigrationTest extends TestCase {

	/**
	 * @return array<int, string>
	 */
	public static function expected_tables(): array {
		// Suffixes only — the test prefixes with $wpdb->prefix per-run
		// so this list stays portable across wp-env / production /
		// multisite scenarios that vary the prefix.
		return array(
			'smly_plus_event_queue',
			'smly_plus_backfill_job',
			'smly_plus_automation_mapping',
			'smly_plus_cart_session',
			'smly_rec_event_queue',
			'smly_rec_visitor',
		);
	}

	public function test_every_expected_table_exists_after_activation(): void {
		global $wpdb;

		foreach ( self::expected_tables() as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			self::assertSame(
				$table,
				$exists,
				sprintf(
					"Migration for {$suffix} did not produce the expected table. SHOW TABLES LIKE '%s' returned: %s",
					$table,
					var_export( $exists, true )
				)
			);
		}
	}

	public function test_schema_version_option_matches_highest_migration_file(): void {
		$migration_dir = SMAILY_CONNECT_PLUGIN_PATH . 'migrations';
		$files         = glob( $migration_dir . '/[0-9]*-*.sql' );
		self::assertNotEmpty( $files, 'No migration files found in migrations/. The Migrator has nothing to apply.' );

		$highest = 0;
		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( preg_match( '/^(\d+)/', $basename, $m ) ) {
				$version = (int) $m[1];
				$highest = max( $highest, $version );
			}
		}

		$stored = (int) get_option( Constants::OPTION_SCHEMA_VERSION, 0 );
		self::assertSame(
			$highest,
			$stored,
			sprintf(
				'smly_plus_schema_version=%d but highest migration in migrations/ is %d. The activation migrator may have stopped midway — check error logs for dbDelta failures.',
				$stored,
				$highest
			)
		);
	}

	public function test_event_queue_table_has_unique_event_uuid_constraint(): void {
		// Tighter assertion: dbDelta failures sometimes succeed at the
		// CREATE but quietly drop indexes / UNIQUE clauses. The Faas-2
		// regression came from exactly this — table existed, columns
		// existed, but a key was missing so duplicate-insert protection
		// silently failed at runtime. Probe one canonical UNIQUE per
		// table that the application logic depends on.
		global $wpdb;
		$table = $wpdb->prefix . 'smly_rec_event_queue';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_event_uuid'", ARRAY_A );
		self::assertNotEmpty(
			$rows,
			"Migration 004 created smly_rec_event_queue but the uniq_event_uuid index is missing. \nidempotency would silently break for duplicate retries."
		);
	}
}
