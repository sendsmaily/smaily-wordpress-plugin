<?php
/**
 * Plugin deactivation handler for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation.
 *
 * Per PROJECT_PLAN.md §3.1 and PLUGIN.md §14:
 *   - WP-Cron schedules registered by the new code path are cleared here
 *   - Action Scheduler queues are intentionally NOT cancelled — a deactivation
 *     is often a temporary reconfiguration, and the pending events should
 *     resume on the next activation rather than silently disappear
 *   - DB tables and option values are preserved; uninstall.php (Phase 4) is
 *     responsible for full data removal when the user opts in
 *
 * Legacy WP-Cron schedules registered by Smaily_Connect\Includes\Lifecycle
 * (smaily_connect_cron_sync_subscribers, abandoned-cart cron) are cleared by
 * the legacy lifecycle's own deactivate() callback; we don't touch them here.
 */
final class Deactivation {

	public static function run(): void {
		// Placeholder: future sub-commits clear any WP-Cron events the new code
		// path may register. Action Scheduler jobs deliberately stay queued.
	}

	private function __construct() {
		// Static-only handler.
	}
}
