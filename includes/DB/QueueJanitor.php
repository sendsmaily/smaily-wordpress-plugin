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
 *
 * The same tick also prunes the plugin's OWN finished Action Scheduler rows
 * (PRO-2438) — see `prune_scheduler_history()`.
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
	 * Days a finished Action Scheduler row of ours is kept (PRO-2438).
	 */
	public const ACTION_RETENTION_DAYS = 7;

	/**
	 * Action Scheduler's own tables + the hook prefixes that are ours.
	 */
	public const AS_ACTIONS_TABLE   = 'actionscheduler_actions';
	public const AS_LOGS_TABLE      = 'actionscheduler_logs';
	private const OUR_HOOK_PREFIXES = array( 'smly_plus_', 'smly_rec_' );

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
		$this->prune_scheduler_history();
	}

	/**
	 * One janitor pass over both queues. The Action Scheduler pass keeps its
	 * own entry point + count (`prune_scheduler_history()`) so a queue-row
	 * count can't drift with scheduler rows the run happens to find.
	 *
	 * @return int Total queue rows deleted (for tests / logging).
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

	/**
	 * Prune the plugin's OWN finished Action Scheduler rows, with their logs.
	 *
	 * Action Scheduler's own cleaner only purges `complete` and `canceled`
	 * actions — `failed` ones are kept forever. With seven recurring actions
	 * on a 60-second cadence this plugin is typically the store's heaviest
	 * scheduler producer, so the residue is ours to clear: the pilot store
	 * carried 466 148 action rows, thousands of them failed abandoned-cart
	 * actions from three months earlier, each with its own log rows.
	 *
	 * Scope, deliberately narrow: only hooks starting `smly_plus_` /
	 * `smly_rec_`, only the terminal statuses, only rows whose
	 * `scheduled_date_gmt` (the column Action Scheduler's own cleaner and its
	 * `hook_status_scheduled_date_gmt` index use) is past the retention
	 * window. `pending` and `in-progress` are never touched, and neither is
	 * any other plugin's hook.
	 *
	 * Raw SQL because the scheduler's store API has no bulk delete by hook +
	 * age: it can only fetch ids page by page and delete one action at a
	 * time, which is exactly the per-row load this is meant to avoid.
	 *
	 * @return int Action rows deleted (for tests / logging).
	 */
	public function prune_scheduler_history(): int {
		global $wpdb;

		$actions = $this->scheduler_table( self::AS_ACTIONS_TABLE );
		$logs    = $this->scheduler_table( self::AS_LOGS_TABLE );

		// A store that has never run Action Scheduler has no tables: no-op.
		if ( ! $this->table_exists( $actions ) ) {
			return 0;
		}
		$prune_logs = $this->table_exists( $logs );

		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$hook_where = implode( ' OR ', array_fill( 0, count( self::OUR_HOOK_PREFIXES ), 'hook LIKE %s' ) );
		$hook_likes = array();
		foreach ( self::OUR_HOOK_PREFIXES as $prefix ) {
			$hook_likes[] = $wpdb->esc_like( $prefix ) . '%';
		}

		$batch_size = $this->scheduler_batch_size();
		$deleted    = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the hook LIKE list and the action_id IN() list build their own placeholder strings; every value is passed through $wpdb->prepare().
		for ( $batch = 0; $batch < $this->scheduler_max_batches(); $batch++ ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM {$actions}
					WHERE ( {$hook_where} )
					AND status IN ( 'complete', 'failed', 'canceled' )
					AND scheduled_date_gmt < %s
					LIMIT %d",
					array_merge( $hook_likes, array( $cutoff, $batch_size ) )
				)
			);
			$ids = array_map( 'intval', (array) $ids );
			if ( empty( $ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			if ( $prune_logs ) {
				$wpdb->query(
					$wpdb->prepare( "DELETE FROM {$logs} WHERE action_id IN ( {$placeholders} )", $ids )
				);
			}
			$rows = $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$actions} WHERE action_id IN ( {$placeholders} )", $ids )
			);

			$deleted += is_numeric( $rows ) ? (int) $rows : 0;
			if ( count( $ids ) < $batch_size ) {
				break;
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return $deleted;
	}

	/**
	 * Prefixed name of an Action Scheduler table (test seam: a subclass
	 * points it at a table that doesn't exist to exercise the guard).
	 */
	protected function scheduler_table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Action rows per statement (test seam — see `scheduler_max_batches()`).
	 */
	protected function scheduler_batch_size(): int {
		return self::BATCH_SIZE;
	}

	/**
	 * Statements per run: the per-run ceiling is this times the batch size,
	 * and the next daily tick takes the remainder (test seam — a subclass
	 * shrinks both so the ceiling is reachable with a small fixture).
	 */
	protected function scheduler_max_batches(): int {
		return self::MAX_BATCHES_PER_RUN;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		// Plugin Check does not honour a line-level phpcs:ignore on this
		// construct (the repo's own PHPCS does) — disable/enable instead.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
