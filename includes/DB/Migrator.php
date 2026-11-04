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

		return $applied;
	}

	/**
	 * Whether the stored schema has reached REQUIRED_VERSION — what the
	 * background import work checks before it touches the backfill table
	 * (CON-282). After a background auto-update the new code runs before
	 * any upgrade did; until this is true it waits.
	 */
	public static function schema_ready(): bool {
		return (int) get_option( Constants::OPTION_SCHEMA_VERSION, 0 ) >= self::REQUIRED_VERSION;
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
