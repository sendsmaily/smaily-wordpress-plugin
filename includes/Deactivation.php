<?php
/**
 * Plugin deactivation handler for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;

/**
 * Runs on plugin deactivation.
 *
 *   - Action Scheduler actions in the plugin's groups are CANCELLED
 *     (PRO-2433). Action Scheduler re-arms a recurring action after every
 *     run whether or not the hook still has a listener, so left queued the
 *     seven 60-second flushers kept executing ~10 000 empty actions a day on
 *     a deactivated store, forever. Nothing is lost by cancelling: the
 *     pending EVENTS are rows in the plugin's own tables, and re-activation
 *     recreates the recurring actions (Bootstrap::register_action_scheduler_jobs
 *     on init, Activation::run) which drain those rows again.
 *   - DB tables and option values are preserved; uninstall.php is
 *     responsible for full data removal when the user opts in.
 *
 * Legacy WP-Cron schedules registered by Smaily_Connect\Includes\Lifecycle
 * (smaily_connect_cron_sync_subscribers, abandoned-cart cron) are cleared by
 * the legacy lifecycle's own deactivate() callback; we don't touch them here.
 */
final class Deactivation {

	/**
	 * Every Action Scheduler group the plugin schedules into. Cancelling by
	 * group catches recurring AND one-off actions (async flush kicks, backfill
	 * ticks) without enumerating hooks.
	 *
	 * @var string[]
	 */
	public const AS_GROUPS = array(
		EventQueue::AS_GROUP,
		IngestQueue::AS_GROUP,
		CustomerFlusher::AS_GROUP,
		OrderFlusher::AS_GROUP,
		CatalogRemoveFlusher::AS_GROUP,
	);

	public static function run(): void {
		self::cancel_action_scheduler_jobs();
		// The recurring set is gone, so the "verified" marker is a lie —
		// drop it so a re-activation re-arms on the next `init` instead of
		// waiting out the hour it is trusted for (PRO-2437).
		delete_option( Bootstrap::OPTION_AS_JOBS_VERIFIED );
	}

	private static function cancel_action_scheduler_jobs(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::AS_GROUPS as $group ) {
			as_unschedule_all_actions( '', array(), $group );
		}
	}

	private function __construct() {
		// Static-only handler.
	}
}
