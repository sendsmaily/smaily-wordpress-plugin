<?php
/**
 * Which queue rows an import queued, and how many of them the engine accepted.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table: an atomic counter increment; object-cache is N/A for a write-through path.

/**
 * An import's rows drain through the same queue and flushers as the live
 * sync's, so a flusher cannot tell them apart by type or entity. The import
 * marks each row it queues: the payload carries `import` (the job type) and
 * `run` (the run's `started_at`). Customer, order and catalog upsert rows are
 * built fresh at send and never read their payload, and a catalog removal
 * keeps its captured `object` beside the mark, so the mark changes nothing
 * that is sent.
 *
 * When the engine accepts a marked row — not rejected per item (D6
 * errors[]), not skipped as older than the history horizon (§5
 * skipped_orders[]) — the flusher adds it to that run's `sent_count`
 * (migration 013, CON-248). A row of an earlier run (a Retry from the Event
 * Log after the import was started again) matches no run and counts nowhere.
 */
final class ImportRows {

	public const KEY_IMPORT = 'import';

	public const KEY_RUN = 'run';

	/**
	 * The mark an import's row carries.
	 *
	 * @return array{import: string, run: string}
	 */
	public static function mark( string $job_type, string $run ): array {
		return array(
			self::KEY_IMPORT => $job_type,
			self::KEY_RUN    => $run,
		);
	}

	/**
	 * The import and run a queue row was queued by, or null for a live row.
	 *
	 * @param array<string, mixed> $row A queue row as IngestQueue::pending() reads it.
	 * @return array{import: string, run: string}|null
	 */
	public static function of( array $row ): ?array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		$import = $payload[ self::KEY_IMPORT ] ?? null;
		$run    = $payload[ self::KEY_RUN ] ?? null;
		if ( ! is_string( $import ) || $import === '' || ! is_string( $run ) || $run === '' ) {
			return null;
		}

		return self::mark( $import, $run );
	}

	/**
	 * Add the accepted rows to their runs' `sent_count`. A run that started
	 * before the column existed (NULL) stays uncounted.
	 *
	 * @param array<int, array{import: string, run: string}> $accepted One mark per accepted row.
	 */
	public static function record_accepted( array $accepted ): void {
		$tally = array();
		foreach ( $accepted as $mark ) {
			$key = $mark[ self::KEY_IMPORT ] . "\0" . $mark[ self::KEY_RUN ];
			if ( ! isset( $tally[ $key ] ) ) {
				$tally[ $key ] = array( $mark, 0 );
			}
			++$tally[ $key ][1];
		}

		if ( $tally === array() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . AbstractBackfillJob::TABLE_SUFFIX;
		foreach ( $tally as list( $mark, $count ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET sent_count = sent_count + %d WHERE job_type = %s AND target = %s AND started_at = %s AND sent_count IS NOT NULL",
					$count,
					$mark[ self::KEY_IMPORT ],
					AbstractBackfillJob::TARGET,
					$mark[ self::KEY_RUN ]
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	private function __construct() {
		// Static-only helper.
	}
}
