<?php
/**
 * Retention janitor for the durable event queues.
 *
 * @package Smaily\Connect\DB
 */

declare(strict_types=1);

namespace Smaily\Connect\DB;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportRows;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Support\DebugLog;

/**
 * Prunes terminal rows (`sent` / `failed`) past their retention window from
 * BOTH durable queues (`smly_plus_event_queue` + `smly_rec_event_queue`), so
 * the tables don't grow without bound in production (BACKLOG "Queue janitor";
 * FABLE_AUDIT §5/§7#9 pulled it forward pre-pilot).
 *
 * Retention defaults: `sent` rows after 30 days (only useful as a short audit
 * trail — the engine has confirmed them), `failed` rows after 90 days (kept
 * much longer: they are the Event Log's diagnostic evidence and stay
 * retryable until pruned). A `sent` row a Campaign Intelligence import queued
 * (its payload carries the ImportRows mark) goes after 7 days (CON-335): an
 * import queues a store's whole history at once. All are filterable.
 *
 * `pending` rows are NEVER touched, whatever their age — they are work, not
 * history; an old parked retry must survive until it terminally resolves.
 *
 * Deletes run in LIMIT-ed batches of BATCH_SIZE rows, so no statement holds
 * the table locked for long, with a short pause between batches. A run keeps
 * deleting until nothing past retention is left or its time budget
 * (RUN_BUDGET_SECONDS) is spent; a run that stops with rows left schedules
 * ONE follow-up run a minute later (CON-335 — the old 20-batch ceiling took
 * 100+ days to clear the pilot's 3.3-million-row backlog). The
 * `idx_created_at` index (migration 006) keeps the cutoff scan cheap on both
 * tables.
 *
 * The same tick also prunes the plugin's OWN finished Action Scheduler rows
 * (PRO-2438) — see `prune_scheduler_history()` — and deletes the discount
 * codes the automations made that were never used, 30 days after they
 * expired (CON-199, AutomationDiscount::delete_expired_unused()).
 */
class QueueJanitor {

	/**
	 * Action Scheduler hook + group for the recurring daily tick.
	 */
	public const HOOK     = 'smly_plus_queue_janitor';
	public const AS_GROUP = 'smaily-connect';

	public const DEFAULT_SENT_RETENTION_DAYS        = 30;
	public const DEFAULT_FAILED_RETENTION_DAYS      = 90;
	public const DEFAULT_IMPORT_SENT_RETENTION_DAYS = 7;

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
	 * Rows per DELETE statement.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Safety cap on statements per status per run. The time budget ends a
	 * run long before it (the pause alone allows at most 400 batches in the
	 * budget); it binds only if the clock misbehaves.
	 */
	private const MAX_BATCHES_PER_RUN = 500;

	/**
	 * Seconds one run keeps starting new batches (CON-335). Well under the
	 * 30-second time limit Action Scheduler gives a queue run.
	 */
	public const RUN_BUDGET_SECONDS = 20;

	/**
	 * Pause between two batches, so replication and other queries get room.
	 */
	private const BATCH_PAUSE_SECONDS = 0.05;

	/**
	 * A run that stops with rows left is followed by ONE single action of the
	 * same hook this many seconds later. Its own args tell it apart from the
	 * recurring daily action (args `[]`), so the pending check never mistakes
	 * the next daily run for a follow-up, and Bootstrap's recurring-action
	 * check never mistakes a follow-up for the daily run.
	 */
	public const FOLLOW_UP_DELAY_SECONDS = 60;
	public const FOLLOW_UP_ARGS          = array( 'follow_up' );

	/** When the current run stops starting batches (microtime seconds). */
	private float $deadline = 0.0;

	/** Whether the current run stopped with rows past retention left. */
	private bool $rows_left = false;

	/**
	 * Wire the AS callback. Bootstrap schedules the recurring daily tick.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'on_tick' ) );
	}

	/**
	 * Action Scheduler callback for the daily run and its follow-ups (a
	 * follow-up passes FOLLOW_UP_ARGS, which the run does not need). Void per
	 * action-hook contract; run() keeps the deleted-count return for tests.
	 * One time budget covers both queues and the scheduler history.
	 */
	public function on_tick(): void {
		$this->start_budget();
		$this->prune_queues();
		$this->prune_history();
		( new AutomationDiscount() )->delete_expired_unused();

		if ( $this->rows_left ) {
			$this->schedule_follow_up();
		}
	}

	/**
	 * One janitor pass over both queues, on its own time budget. The Action
	 * Scheduler pass keeps its own entry point + count
	 * (`prune_scheduler_history()`) so a queue-row count can't drift with
	 * scheduler rows the run happens to find.
	 *
	 * @return int Total queue rows deleted (for tests / logging).
	 */
	public function run(): int {
		$this->start_budget();
		return $this->prune_queues();
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
	 * Days a `sent` import row of the Campaign Intelligence queue is kept
	 * before pruning (CON-335).
	 */
	public function import_sent_retention_days(): int {
		return max( 1, (int) apply_filters( 'smaily_connect_janitor_import_sent_retention_days', self::DEFAULT_IMPORT_SENT_RETENTION_DAYS ) );
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
	 * Runs on its own time budget when called alone; on_tick() shares one.
	 *
	 * @return int Action rows deleted (for tests / logging).
	 */
	public function prune_scheduler_history(): int {
		$this->start_budget();
		return $this->prune_history();
	}

	private function prune_history(): int {
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
		for ( $batch = 0; $this->next_batch( $batch, $this->scheduler_max_batches() ); $batch++ ) {
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
			if ( false === $rows ) {
				// Left alone, the next batch would select the same ids again.
				$this->log_delete_failed( self::AS_ACTIONS_TABLE, 'complete,failed,canceled' );
				break;
			}

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
	 * Safety cap on statements per run: a run that reaches it stops with
	 * rows left, like one whose time budget ends (test seam — a subclass
	 * shrinks both so the cap is reachable with a small fixture).
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

	/**
	 * The current time in seconds (test seam: a unit test drives a fake clock).
	 */
	protected function now(): float {
		return microtime( true );
	}

	/**
	 * Wait between two batches (test seam: a fake clock moves instead).
	 */
	protected function pause( float $seconds ): void {
		usleep( (int) round( $seconds * 1000000 ) );
	}

	private function start_budget(): void {
		$this->deadline  = $this->now() + self::RUN_BUDGET_SECONDS;
		$this->rows_left = false;
	}

	/**
	 * Whether a loop may start its next batch. Called before every batch; a
	 * loop gets past its first batch only when that batch was full, so a
	 * refusal here means rows past retention may be left — the run then
	 * schedules a follow-up.
	 */
	private function next_batch( int $batch, int $max_batches ): bool {
		if ( $batch >= $max_batches ) {
			$this->rows_left = true;
			return false;
		}
		if ( $batch > 0 ) {
			$this->pause( self::BATCH_PAUSE_SECONDS );
		}
		if ( $this->now() >= $this->deadline ) {
			$this->rows_left = true;
			return false;
		}
		return true;
	}

	/**
	 * Schedule ONE follow-up run, unless one is already pending. Pending
	 * only (CON-285): a follow-up that is running right now is not pending,
	 * so it can schedule the next one.
	 */
	private function schedule_follow_up(): void {
		if ( ! function_exists( 'as_schedule_single_action' )
			|| AbstractBackfillJob::action_pending( self::HOOK, self::AS_GROUP, self::FOLLOW_UP_ARGS ) ) {
			return;
		}
		as_schedule_single_action( time() + self::FOLLOW_UP_DELAY_SECONDS, self::HOOK, self::FOLLOW_UP_ARGS, self::AS_GROUP );
	}

	/**
	 * A DELETE failed: this status stops for this run, the next run tries
	 * again. The code and where only — never the database's error text
	 * (CON-277).
	 */
	private function log_delete_failed( string $table, string $status ): void {
		DebugLog::write(
			sprintf( '[smaily-connect queue-janitor] delete_failed table=%s status=%s; stopped until the next run', $table, $status )
		);
	}

	private function prune_queues(): int {
		$deleted  = $this->prune_table( EventQueue::TABLE_SUFFIX );
		$deleted += $this->prune_table( IngestQueue::TABLE_SUFFIX );

		return $deleted;
	}

	/**
	 * The two payload shapes of an import row, as LIKE patterns, so SQL
	 * finds them without reading payloads into PHP. The mark is appended to
	 * what the import queues (AbstractBackfillJob::import_payload()), so it
	 * is the whole payload of a customer, order or catalog upsert row —
	 * `{"import":"orders","run":"…"}` — and follows the captured object of a
	 * catalog removal — `{"object":{…},"import":"products","run":"…"}`. No
	 * live row matches: the live sync queues `[]`, `{"object":{…}}` (which
	 * ends `}}`) or `{"product_id":"…"}`.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function import_payload_patterns(): array {
		global $wpdb;

		$import = '"' . ImportRows::KEY_IMPORT . '":"';
		$run    = '","' . ImportRows::KEY_RUN . '":"';

		return array(
			$wpdb->esc_like( '{' . $import ) . '%' . $wpdb->esc_like( $run ) . '%' . $wpdb->esc_like( '"}' ),
			$wpdb->esc_like( '{"object":' ) . '%' . $wpdb->esc_like( ',' . $import ) . '%' . $wpdb->esc_like( $run ) . '%' . $wpdb->esc_like( '"}' ),
		);
	}

	private function prune_table( string $table_suffix ): int {
		global $wpdb;

		// $table is composed from $wpdb->prefix + a class-constant suffix —
		// controlled values, no user input.
		$table   = $wpdb->prefix . $table_suffix;
		$deleted = 0;

		// [ status, days, extra condition, its values, log label ].
		$rules = array(
			array( EventQueue::STATUS_SENT, $this->sent_retention_days(), '', array(), EventQueue::STATUS_SENT ),
			array( EventQueue::STATUS_FAILED, $this->failed_retention_days(), '', array(), EventQueue::STATUS_FAILED ),
		);
		if ( IngestQueue::TABLE_SUFFIX === $table_suffix ) {
			array_unshift(
				$rules,
				array( EventQueue::STATUS_SENT, $this->import_sent_retention_days(), ' AND ( payload LIKE %s OR payload LIKE %s )', $this->import_payload_patterns(), 'sent_import' )
			);
		}

		foreach ( $rules as list( $status, $days, $only, $only_values, $label ) ) {
			// created_at is written as UTC (current_time('mysql', true) /
			// gmdate) — compare against a UTC cutoff.
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			for ( $batch = 0; $this->next_batch( $batch, self::MAX_BATCHES_PER_RUN ); $batch++ ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the values are one array: status, cutoff, the import rule's LIKE patterns, the limit.
				$rows = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE status = %s AND created_at < %s{$only} LIMIT %d",
						array_merge( array( $status, $cutoff ), $only_values, array( self::BATCH_SIZE ) )
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

				if ( false === $rows ) {
					$this->log_delete_failed( $table_suffix, $label );
					break;
				}
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
