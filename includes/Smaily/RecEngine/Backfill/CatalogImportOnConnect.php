<?php
/**
 * Start the full catalog import when Campaign Intelligence is connected.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\REST\BackfillEndpoint;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;

/**
 * Contract §3: the full catalog reaches the engine once, at setup; after that
 * only changes are sent. So a successful setup exchange starts the same
 * products import the "Import now" button starts — same job row, same AS tick
 * hook and group — with one difference: the first tick waits DELAY_SECONDS, so
 * the merchant can hold the import back (the existing /backfill/cancel, which
 * unschedules the tick) before anything is sent (PRO-3743).
 *
 * A products import that is already running (queued or mid-walk) is left alone:
 * reconnecting starts no second one, and the running one resumes from its
 * cursor. Customers and orders stay a merchant choice — nothing here starts them.
 */
final class CatalogImportOnConnect {

	public const JOB_TYPE = 'products';

	/** Hold-back window between connecting and the first batch being sent. */
	public const DELAY_SECONDS = 180;

	private ?BackfillJobInterface $job;

	public function __construct( ?BackfillJobInterface $job ) {
		$this->job = $job;
	}

	/**
	 * @return bool True when a new import was started, false when one was
	 *              already running (or there is no products job to start).
	 */
	public function start(): bool {
		if ( $this->job === null || ! function_exists( 'as_schedule_single_action' ) || $this->is_running() ) {
			return false;
		}

		$this->job->start();

		as_schedule_single_action(
			time() + self::DELAY_SECONDS,
			BackfillEndpoint::TICK_HOOK,
			array( 'job_type' => self::JOB_TYPE ),
			EventQueue::AS_GROUP
		);

		return true;
	}

	private function is_running(): bool {
		global $wpdb;
		$table = $wpdb->prefix . AbstractBackfillJob::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom backfill-state table, read-through.
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE job_type = %s AND target = %s",
				self::JOB_TYPE,
				AbstractBackfillJob::TARGET
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $status === BackfillEndpoint::STATUS_RUNNING;
	}
}
