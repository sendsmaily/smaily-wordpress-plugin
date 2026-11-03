<?php
/**
 * The index the orders import's page query needs on a legacy order table.
 *
 * @package Smaily\Connect\DB
 */

declare(strict_types=1);

namespace Smaily\Connect\DB;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Support\DebugLog;

/**
 * CON-259. On legacy order storage the orders import reads its pages from
 * `wp_posts`, newest first by `post_date_gmt`, then id (CON-245). WordPress
 * indexes `post_date`, not `post_date_gmt`, so every page sorted every order
 * in the window (CON-236: 105 ms a page at 100,000 orders, a filesort). This
 * class adds one index, INDEX on (post_type, post_date_gmt, ID), to the
 * posts table once, so a page is an index range read (about 1–4 ms).
 *
 *   - Only where it is used: a store connected to Campaign Intelligence
 *     (RecEngineSettings::is_connected() — Erkki, 2026-11-02: the core table
 *     is not altered on a store that never uses the import), with WooCommerce
 *     active and NOT on HPOS. An HPOS store reads `wc_orders`, whose
 *     `date_created` index already serves the query; the posts table is
 *     never touched there. Nothing is recorded for a store that does not
 *     qualify, so a store that connects or switches to legacy storage later
 *     still gets the index. A store that disconnects keeps it.
 *   - Never in a request: ensure_scheduled() only schedules one Action
 *     Scheduler action; run() is that action. It is called from the hourly
 *     recurring-jobs check (Bootstrap::register_action_scheduler_jobs()) and
 *     right after a successful connect, so the index is usually in before
 *     the orders import, which starts after the products and customers
 *     imports (CON-247). The plugin update and admin requests never run the
 *     ALTER.
 *   - Idempotent: SHOW INDEX first. This index, or any index whose first two
 *     columns are (post_type, post_date_gmt), counts as present.
 *   - Online DDL only: `ALGORITHM=INPLACE, LOCK=NONE` (reads and writes go
 *     on while it builds). There is no plain ADD INDEX: a server that refuses
 *     the clause could build it with a lock that blocks writes to the posts
 *     table, where a legacy-storage store writes its orders (CON-267, audit
 *     L1). Such a refusal is a permanent failure: the index is not added and
 *     never tried again. The metadata-lock wait is capped at
 *     LOCK_WAIT_SECONDS, so an open long transaction makes the attempt fail
 *     instead of queueing every query on the table behind it.
 *   - Bounded failure: a failed attempt is a debug-log line and a stored
 *     state (OPTION). It is tried again after RETRY_AFTER_SECONDS, at most
 *     MAX_ATTEMPTS times in all; then never again. A refused online clause
 *     is never tried again (`permanent` in the state). An attempt is counted
 *     before the ALTER, so a run the host kills counts too. The import works
 *     the same without the index, only slower.
 *   - Uninstall drops it (drop(), from uninstall.php); deactivation leaves
 *     it.
 */
class LegacyOrderDateIndex {

	/** Plugin-prefixed, so uninstall drops ours and never another index. */
	public const INDEX = 'smaily_connect_order_date';

	public const HOOK = 'smly_plus_order_date_index';

	public const AS_GROUP = 'smaily-connect-order-date-index';

	/** The state (autoload=false); a smly_plus_* option, so uninstall sweeps it. */
	public const OPTION = 'smly_plus_order_date_index';

	public const STATUS_RUNNING = 'running';

	public const STATUS_ADDED = 'added';

	public const STATUS_PRESENT = 'present';

	public const STATUS_FAILED = 'failed';

	public const MAX_ATTEMPTS = 3;

	public const RETRY_AFTER_SECONDS = 86400;

	/** A `running` state older than this is an attempt the host killed. */
	public const RUNNING_STALE_SECONDS = 3600;

	/** The session's metadata-lock wait for the ALTER. */
	public const LOCK_WAIT_SECONDS = 5;

	/** PHP time limit for the run, where the host allows raising it. */
	public const TIME_LIMIT_SECONDS = 900;

	public const STORAGE_LEGACY = 'legacy';

	public const STORAGE_HPOS = 'hpos';

	public const STORAGE_NONE = 'none';

	/** @var callable(): string */
	private $storage;

	/** @var callable(): bool */
	private $connected;

	/**
	 * @param (callable(): string)|null $storage   The order storage: STORAGE_LEGACY, STORAGE_HPOS or STORAGE_NONE (no WooCommerce).
	 * @param (callable(): bool)|null   $connected Whether the store is connected to Campaign Intelligence; default RecEngineSettings::is_connected().
	 */
	public function __construct( ?callable $storage = null, ?callable $connected = null ) {
		$this->connected = $connected ?? static function (): bool {
			return ( new RecEngineSettings() )->is_connected();
		};
		$this->storage   = $storage ?? static function (): string {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return self::STORAGE_NONE;
			}
			return OrderBackfillJob::uses_hpos() ? self::STORAGE_HPOS : self::STORAGE_LEGACY;
		};
	}

	/**
	 * Schedule the action when an attempt is due and none is scheduled.
	 * Cheap on a store that needs nothing: one or two get_option() reads and
	 * the storage check.
	 */
	public function ensure_scheduled(): void {
		if ( $this->skip_reason() !== '' ) {
			return;
		}
		$due = self::due_at( self::read_state(), time() );
		if ( $due === null || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::HOOK, array(), self::AS_GROUP ) ) {
			return;
		}
		as_schedule_single_action( max( time(), $due ), self::HOOK, array(), self::AS_GROUP );
	}

	/**
	 * The Action Scheduler action: add the index if it is due and missing.
	 *
	 * @return string What happened: 'not_connected', 'not_legacy', 'not_due', STATUS_PRESENT, STATUS_ADDED or STATUS_FAILED.
	 */
	public function run(): string {
		$skip = $this->skip_reason();
		if ( $skip !== '' ) {
			return $skip;
		}
		$state = self::read_state();
		$now   = time();
		$due   = self::due_at( $state, $now );
		if ( $due === null || $due > $now ) {
			return 'not_due';
		}

		$attempts = (int) ( $state['attempts'] ?? 0 );
		if ( self::present_index() !== null ) {
			self::write_state( self::STATUS_PRESENT, $attempts, $now, '' );
			return self::STATUS_PRESENT;
		}

		++$attempts;
		self::write_state( self::STATUS_RUNNING, $attempts, $now, '' );
		self::raise_time_limit();

		$error = self::add();
		if ( $error === '' || self::present_index() !== null ) {
			self::write_state( self::STATUS_ADDED, $attempts, time(), '' );
			return self::STATUS_ADDED;
		}

		// A server that cannot build the index online today cannot tomorrow.
		$permanent = self::is_clause_refused( $error );
		$error     = self::redact( $error );
		self::write_state( self::STATUS_FAILED, $attempts, time(), $error, $permanent );
		if ( $permanent ) {
			$next = ', not tried again: the server cannot add it without blocking writes';
		} else {
			$next = $attempts < self::MAX_ATTEMPTS ? ', tried again in a day' : ', not tried again';
		}
		DebugLog::write(
			sprintf(
				'[smaily-connect] Could not add the orders-import index %s to the posts table (attempt %d of %d%s): %s',
				self::INDEX,
				$attempts,
				self::MAX_ATTEMPTS,
				$next,
				$error
			)
		);
		return self::STATUS_FAILED;
	}

	/**
	 * Why this store gets no index now ('not_connected', 'not_legacy'), or ''
	 * when it qualifies. The connection is read first: it is one option.
	 */
	private function skip_reason(): string {
		if ( ! ( $this->connected )() ) {
			return 'not_connected';
		}
		if ( ( $this->storage )() !== self::STORAGE_LEGACY ) {
			return 'not_legacy';
		}
		return '';
	}

	/**
	 * When the next attempt is due, or null when none ever is (added,
	 * present, a permanent failure, or MAX_ATTEMPTS used). An empty state is
	 * due at once.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function due_at( array $state, int $now ): ?int {
		$status   = (string) ( $state['status'] ?? '' );
		$attempts = (int) ( $state['attempts'] ?? 0 );
		$at       = (int) ( $state['at'] ?? 0 );

		switch ( $status ) {
			case '':
				return $now;
			case self::STATUS_ADDED:
			case self::STATUS_PRESENT:
				return null;
			case self::STATUS_RUNNING:
				if ( $at > $now - self::RUNNING_STALE_SECONDS ) {
					return $at + self::RUNNING_STALE_SECONDS;
				}
				// The attempt never finished: it counts as a failed one.
				return $attempts >= self::MAX_ATTEMPTS ? null : $now;
			default:
				if ( ! empty( $state['permanent'] ) || $attempts >= self::MAX_ATTEMPTS ) {
					return null;
				}
				return $at + self::RETRY_AFTER_SECONDS;
		}
	}

	/**
	 * Whether an index serves the query: ours, or any whose first two
	 * columns are (post_type, post_date_gmt) — InnoDB appends the primary
	 * key ID to every secondary index.
	 *
	 * @param string[] $columns The index's columns in order.
	 */
	public static function covers( array $columns ): bool {
		$columns = array_map( 'strtolower', array_values( $columns ) );
		return count( $columns ) >= 2 && $columns[0] === 'post_type' && $columns[1] === 'post_date_gmt';
	}

	/**
	 * Whether a failed ALTER failed on the online-DDL clause (MySQL / MariaDB
	 * error 1845 / 1846, or a syntax error from a server that does not know
	 * the clause): the server cannot build the index without blocking writes,
	 * so the failure is permanent. A lock wait timeout, a denied ALTER or any
	 * other error is not — it is tried again a day later.
	 */
	public static function is_clause_refused( string $error ): bool {
		return (bool) preg_match( '/\b(ALGORITHM|LOCK)\s*=|SQL syntax/i', $error );
	}

	/**
	 * A database error without its quoted parts: a denied ALTER names the
	 * database user and host there.
	 */
	public static function redact( string $error ): string {
		$error = (string) preg_replace( "/'[^']*'/", "'?'", $error );
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 200 ) : substr( $error, 0, 200 );
	}

	/**
	 * The name of the index that serves the query, or null.
	 *
	 * @phpstan-impure It reads the live schema.
	 */
	public static function present_index(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema read; the table name is a $wpdb property.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$indexes = array();
		foreach ( $rows as $row ) {
			$indexes[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		if ( isset( $indexes[ self::INDEX ] ) ) {
			return self::INDEX;
		}
		foreach ( $indexes as $name => $columns ) {
			ksort( $columns );
			if ( self::covers( $columns ) ) {
				return (string) $name;
			}
		}
		return null;
	}

	/**
	 * Drop this index when it exists (uninstall.php). Needs only $wpdb: the
	 * plugin is not loaded during uninstall. Never drops another index.
	 */
	public static function drop(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- schema read + drop of the plugin's own index; the table name is a $wpdb property.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name = %s", self::INDEX ) );
		if ( $exists === null ) {
			return;
		}
		self::with_lock_wait(
			static function () use ( $wpdb ): bool {
				return $wpdb->query( "ALTER TABLE {$wpdb->posts} DROP INDEX " . self::INDEX ) !== false;
			}
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The stored state.
	 *
	 * @return array<string, mixed>
	 */
	public static function read_state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Add the index online. There is no plain ADD INDEX when the server
	 * refuses the clause (CON-267): it could block writes to the posts table.
	 *
	 * @return string '' on success, else the database error (not yet redacted).
	 */
	private static function add(): string {
		global $wpdb;
		$error = '';
		$added = self::with_lock_wait(
			static function () use ( $wpdb, &$error ): bool {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the approved index on the core posts table (CON-259); no input in the statement.
				if ( $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX " . self::INDEX . ' (post_type, post_date_gmt, ID), ALGORITHM=INPLACE, LOCK=NONE' ) !== false ) {
					return true;
				}
				$error = (string) $wpdb->last_error;
				return false;
			}
		);

		return $added ? '' : ( $error !== '' ? $error : 'unknown database error' );
	}

	/**
	 * Run a schema change with the session's metadata-lock wait capped and
	 * database errors kept off the page and the error log (the caller records
	 * them), restoring both afterwards.
	 *
	 * @param callable(): bool $change
	 */
	private static function with_lock_wait( callable $change ): bool {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- session variables only; integer values.
		$previous = $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
		$wpdb->query( 'SET SESSION lock_wait_timeout = ' . (int) self::LOCK_WAIT_SECONDS );
		try {
			return $change();
		} finally {
			if ( is_numeric( $previous ) ) {
				$wpdb->query( 'SET SESSION lock_wait_timeout = ' . (int) $previous );
			}
			$wpdb->suppress_errors( $suppressed );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @param bool $permanent A failure that is never tried again (the server refused online DDL).
	 */
	private static function write_state( string $status, int $attempts, int $at, string $error, bool $permanent = false ): void {
		$state = array(
			'status'   => $status,
			'attempts' => $attempts,
			'at'       => $at,
			'error'    => $error,
		);
		if ( $permanent ) {
			$state['permanent'] = true;
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * A large posts table can take minutes. Where the host allows it the run
	 * gets TIME_LIMIT_SECONDS; a limit that is off (0) or higher is left.
	 */
	private static function raise_time_limit(): void {
		$current = (int) ini_get( 'max_execution_time' );
		if ( $current === 0 || $current >= self::TIME_LIMIT_SECONDS ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A schema change in a background run; Bootstrap's upgrade does the same.
			set_time_limit( self::TIME_LIMIT_SECONDS );
		}
	}
}
