<?php
/**
 * Versioned schema migrator for the BETA fork's new tables.
 *
 * @package Smaily\Connect\DB
 */

declare(strict_types=1);

namespace Smaily\Connect\DB;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;

/**
 * Runs forward-only migrations from the migrations/ directory against
 * the current site's database.
 *
 * Migration files follow the pattern `NNN-{slug}.sql` (e.g.
 * `001-create-smly-plus-event-queue.sql`) or `NNN-{slug}.php`. A `.php`
 * migration returns a callable; it is for a step dbDelta() cannot express
 * (dbDelta silently ignores DROP TABLE — CON-182). The callable returns
 * false when the step could not finish: the runner then stops WITHOUT
 * recording that version, so the step is retried on the next upgrade and no
 * later migration runs ahead of it. A step no later migration depends on
 * may answer DEFERRED instead (CON-282): the runner records it in
 * OPTION_DEFERRED, goes on with the later migrations, and runs it again
 * first on every later call until it answers true. The numeric prefix is the
 * schema version applied; files are sorted by integer (so 010 sorts
 * after 009, not lexicographically). The runner persists the highest
 * applied version in the Constants::OPTION_SCHEMA_VERSION option and
 * only runs files whose version exceeds it on each call, which keeps
 * activation idempotent.
 *
 * Coexistence with the legacy lifecycle:
 *   - The legacy Smaily_Connect\Includes\Lifecycle has its own migration
 *     map keyed by plugin version (1.3.0 → upgrade-1-3-0.php) and stores
 *     its own version pointer. Both systems live in migrations/ but
 *     don't read each other's files — pattern matching keeps them
 *     disjoint: legacy uses `upgrade-*.php`, the new system uses
 *     `NNN-*.sql`.
 *
 * SQL placeholder substitution:
 *   - `{prefix}` → the WordPress table prefix ($wpdb->prefix)
 *   - `{charset_collate}` → $wpdb->get_charset_collate(), the
 *     "DEFAULT CHARACTER SET ... COLLATE ..." clause WP recommends for
 *     all plugin tables
 *
 * dbDelta() is the WordPress-blessed way to run CREATE TABLE statements
 * — it handles re-running the same SQL safely (DESCRIBE first, then ALTER
 * for missing columns) and is the only path that satisfies the
 * Plugin Check tool's expectations.
 */
final class Migrator {

	/**
	 * The schema version this code reads and writes (CON-282). Background
	 * import work waits until it is applied (schema_ready()). Keep it at the
	 * highest migration file — MigratorTest pins that.
	 */
	public const REQUIRED_VERSION = 13;

	/** What a `.php` migration answers when it is not done but must not hold back the later ones. */
	public const DEFERRED = 'deferred';

	/** The versions of deferred `.php` migrations, retried first on every later call. */
	public const OPTION_DEFERRED = 'smly_plus_schema_deferred';

	/** The table and column REQUIRED_VERSION adds (migration 013) — what schema_ready() checks (CON-291). */
	private const REQUIRED_TABLE = 'smly_plus_backfill_job';

	private const REQUIRED_COLUMN = 'sent_count';

	/** Whether REQUIRED_COLUMN exists, read once a request; null = not read yet. */
	private static ?bool $column_present = null;

	private string $migrations_dir;

	public function __construct( ?string $migrations_dir = null ) {
		$this->migrations_dir = $migrations_dir ?? $this->default_migrations_dir();
	}

	/**
	 * Apply all migrations whose version exceeds the currently stored one.
	 *
	 * @return int[] List of versions that were applied during this call.
	 */
	public function migrate(): array {
		$current  = (int) get_option( Constants::OPTION_SCHEMA_VERSION, 0 );
		$applied  = array();
		$found    = $this->discover();
		$deferred = $this->deferred();

		// A deferred step runs again first; it leaves the list when it is done.
		$still = array();
		foreach ( $deferred as $version ) {
			if ( ! isset( $found[ $version ] ) ) {
				continue;
			}
			if ( $this->apply( $found[ $version ] ) === true ) {
				$applied[] = $version;
			} else {
				$still[] = $version;
			}
		}
		if ( $still !== $deferred ) {
			$this->save_deferred( $still );
		}

		foreach ( $found as $version => $file ) {
			if ( $version <= $current ) {
				continue;
			}

			$result = $this->apply( $file );
			if ( $result === false ) {
				break;
			}
			if ( $result === self::DEFERRED ) {
				$still[] = $version;
				$this->save_deferred( $still );
			} else {
				$applied[] = $version;
			}
			update_option( Constants::OPTION_SCHEMA_VERSION, $version, false );
		}

		// A step of this call may have added the column.
		self::forget_schema_check();

		return $applied;
	}

	/**
	 * Whether the stored schema has reached REQUIRED_VERSION and the column
	 * it adds exists — what the background import work checks before it
	 * touches the backfill table (CON-282). After a background auto-update
	 * the new code runs before any upgrade did; until this is true it waits.
	 * The version alone is not enough (CON-291): dbDelta() reports nothing,
	 * so a `.sql` step the database refused is recorded as applied.
	 */
	public static function schema_ready(): bool {
		return self::version_reached() && self::column_present() === true;
	}

	/**
	 * Whether the update recorded REQUIRED_VERSION but the column is not
	 * there (CON-291): no later upgrade adds it, so the imports cannot start
	 * and their background checks stop waiting for it. False while the
	 * column cannot be read (a database error decides nothing).
	 */
	public static function schema_failed(): bool {
		return self::version_reached() && self::column_present() === false;
	}

	/**
	 * Read the column again on the next check — after a migration run, and
	 * in tests that change the table.
	 */
	public static function forget_schema_check(): void {
		self::$column_present = null;
	}

	private static function version_reached(): bool {
		return (int) get_option( Constants::OPTION_SCHEMA_VERSION, 0 ) >= self::REQUIRED_VERSION;
	}

	/**
	 * Whether REQUIRED_COLUMN exists, read once a request; null when the
	 * database could not answer (not remembered — the next call asks again).
	 */
	private static function column_present(): ?bool {
		if ( self::$column_present !== null ) {
			return self::$column_present;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::REQUIRED_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; remembered for the request above.
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", self::REQUIRED_COLUMN ) );
		if ( $found === null && (string) ( $wpdb->last_error ?? '' ) !== '' ) {
			return null;
		}
		self::$column_present = $found !== null;

		return self::$column_present;
	}

	/**
	 * @return int[]
	 */
	private function deferred(): array {
		$value = get_option( self::OPTION_DEFERRED, array() );

		return is_array( $value ) ? array_values( array_map( 'intval', $value ) ) : array();
	}

	/**
	 * @param int[] $versions
	 */
	private function save_deferred( array $versions ): void {
		if ( $versions === array() ) {
			delete_option( self::OPTION_DEFERRED );
			return;
		}
		update_option( self::OPTION_DEFERRED, array_values( $versions ), false );
	}

	/**
	 * Discover migration files in the migrations directory.
	 *
	 * Returns an array keyed by integer version, sorted ascending. Only
	 * files matching `NNN-*.sql` or `NNN-*.php` participate — anything else
	 * (legacy upgrade-*.php, index.php, README, …) is ignored.
	 *
	 * Public to allow unit tests to introspect discovery output without
	 * having to mock the filesystem.
	 *
	 * @return array<int, string>
	 */
	public function discover(): array {
		if ( ! is_dir( $this->migrations_dir ) ) {
			return array();
		}

		$found = array();

		// Plain '/*' + the regex below, not GLOB_BRACE: that flag does not
		// exist on every libc (musl / Alpine images).
		foreach ( (array) glob( $this->migrations_dir . '/*' ) as $path ) {
			$basename = basename( (string) $path );
			if ( preg_match( '/^(\d+)-.+\.(sql|php)$/', $basename, $matches ) !== 1 ) {
				continue;
			}

			$version = (int) $matches[1];
			if ( $version <= 0 ) {
				continue;
			}

			$found[ $version ] = $path;
		}

		ksort( $found, SORT_NUMERIC );

		return $found;
	}

	/**
	 * Run a single migration file: a `.php` one by calling the callable it
	 * returns, a `.sql` one through dbDelta().
	 *
	 * @return bool|string False when a `.php` migration reports it could not
	 *                     finish — the caller then stops without recording
	 *                     it; DEFERRED when it is not done but later ones may
	 *                     run; true when it is done.
	 */
	private function apply( string $file ): bool|string {
		if ( substr( $file, -4 ) === '.php' ) {
			// `require`, not `require_once`: the file only returns a closure,
			// and a second run in the same process (tests) needs it again.
			$migration = require $file;
			if ( ! is_callable( $migration ) ) {
				return true;
			}
			$result = $migration();

			return $result === false || $result === self::DEFERRED ? $result : true;
		}

		global $wpdb;

		$sql = (string) file_get_contents( $file );
		if ( $sql === '' ) {
			return true;
		}

		// Strip SQL line comments BEFORE handing the buffer to dbDelta.
		//
		// dbDelta's parser splits the input on `;` and then runs each
		// segment through its CREATE-TABLE/ALTER-TABLE regex. SQL `--`
		// comments are not whitespace to it — comment text that contains
		// a semicolon or that wraps across lines bleeds into the next
		// statement, producing nonsense queries like
		// `ALTER TABLE … ADD COLUMN PLUGIN.md §3`. wp-env first-run hit
		// this on automation_mapping and rec_visitor migrations; the
		// CREATE TABLE never landed, dbDelta swallowed the syntax errors,
		// and schema_version still ticked forward — silent data loss.
		//
		// Stripping `-- …` comments + blank lines flattens the file to
		// the actual DDL dbDelta knows how to parse. The leading file
		// header comments are still readable in source.
		$sql = (string) preg_replace( '/^[ \t]*--.*$/m', '', $sql );

		$sql = strtr(
			$sql,
			array(
				'{prefix}'          => $wpdb->prefix,
				'{charset_collate}' => $wpdb->get_charset_collate(),
			)
		);

		// dbDelta() ships in wp-admin/includes/upgrade.php and only loads
		// on demand from WP-Admin requests + activation. We require_once
		// it here on first use; tests that stub dbDelta() with Brain\Monkey
		// can skip the require because the function is already declared.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		return true;
	}

	private function default_migrations_dir(): string {
		if ( defined( 'SMAILY_CONNECT_PLUGIN_PATH' ) ) {
			return rtrim( SMAILY_CONNECT_PLUGIN_PATH, '/\\' ) . '/migrations';
		}

		return dirname( __DIR__, 2 ) . '/migrations';
	}
}
