<?php
/**
 * Retention janitor for the durable event queues.
 *
 * @package Smaily\Connect\DB
 */

declare(strict_types=1);

namespace Smaily\Connect\DB;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * Prunes terminal rows (`sent` / `failed`) past their retention window from
 * BOTH durable queues (`smly_plus_event_queue` + `smly_rec_event_queue`), so
 * the tables don't grow without bound in production (BACKLOG "Queue janitor";
 * FABLE_AUDIT §5/§7#9 pulled it forward pre-pilot).
 *
 * Retention defaults: `sent` rows after 30 days (only useful as a short audit
 * trail — the engine has confirmed them), `failed` rows after 90 days (kept
 * much longer: they are the Event Log's diagnostic evidence and stay
 * retryable until pruned). Both are filterable.
 *
 * `pending` rows are NEVER touched, whatever their age — they are work, not
 * history; an old parked retry must survive until it terminally resolves.
 *
 * Deletes run in LIMIT-ed batches with a per-run cap so a long-neglected
 * table can't produce one giant table-locking DELETE; the daily tick drains
 * any remainder on subsequent runs. The `idx_created_at` index (migration
 * 006) keeps the cutoff scan cheap on both tables.
 */
class QueueJanitor {

	/**
	 * Action Scheduler hook + group for the recurring daily tick.
	 */
	public const HOOK     = 'smly_plus_queue_janitor';
	public const AS_GROUP = 'smaily-connect';

	public const DEFAULT_SENT_RETENTION_DAYS   = 30;
	public const DEFAULT_FAILED_RETENTION_DAYS = 90;

	/**
	 * Rows per DELETE statement / max statements per status per run.
	 */
	private const BATCH_SIZE          = 1000;
	private const MAX_BATCHES_PER_RUN = 20;

	/**
	 * Wire the AS callback. Bootstrap schedules the recurring daily tick.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'on_tick' ) );
	}

	/**
	 * Action Scheduler callback (void per action-hook contract; run()
	 * keeps the deleted-count return for tests).
	 */
	public function on_tick(): void {
		$this->run();
	}

	/**
	 * One janitor pass over both queues.
	 *
	 * @return int Total rows deleted (for tests / logging).
	 */
	public function run(): int {
		$deleted  = $this->prune_table( EventQueue::TABLE_SUFFIX );
		$deleted += $this->prune_table( IngestQueue::TABLE_SUFFIX );

		return $deleted;
	}

	/**
	 * Days a `sent` row is kept before pruning.
	 */
	public function sent_retention_days(): int {
		return max( 1, (int) apply_filters( 'smaily_connect_janitor_sent_retention_days', self::DEFAULT_SENT_RETENTION_DAYS ) );
	}

	/**
	 * Days a `failed` row is kept before pruning.
	 */
	public function failed_retention_days(): int {
		return max( 1, (int) apply_filters( 'smaily_connect_janitor_failed_retention_days', self::DEFAULT_FAILED_RETENTION_DAYS ) );
	}

	private function prune_table( string $table_suffix ): int {
		global $wpdb;

		// $table is composed from $wpdb->prefix + a class-constant suffix —
		// controlled values, no user input.
		$table   = $wpdb->prefix . $table_suffix;
		$deleted = 0;

		$retention = array(
			EventQueue::STATUS_SENT   => $this->sent_retention_days(),
			EventQueue::STATUS_FAILED => $this->failed_retention_days(),
		);

		foreach ( $retention as $status => $days ) {
			// created_at is written as UTC (current_time('mysql', true) /
			// gmdate) — compare against a UTC cutoff.
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			for ( $batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++ ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE status = %s AND created_at < %s LIMIT %d",
						$status,
						$cutoff,
						self::BATCH_SIZE
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

				$rows = is_numeric( $rows ) ? (int) $rows : 0;
				$deleted += $rows;
				if ( $rows < self::BATCH_SIZE ) {
					break;
				}
			}
		}

		return $deleted;
	}
}
