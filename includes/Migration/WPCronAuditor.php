<?php
/**
 * Audits and removes legacy WP-Cron entries the BETA fork migrates to AS.
 *
 * @package Smaily\Connect\Migration
 */

declare(strict_types=1);

namespace Smaily\Connect\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Three-pass migration helper for moving legacy smaily_connect_cron_*
 * WP-Cron schedules onto Action Scheduler. Ratified with Erkki for
 * sub-PR 5.D:
 *
 *   1. audit_before_clear() — return the legacy hooks currently scheduled
 *      with WP-Cron, so the activation log shows what was found before
 *      we touch anything. If a future upstream release adds new
 *      smaily_connect_* hooks we don't know about, the audit_after_clear
 *      pass below will flag survivors for follow-up.
 *
 *   2. clear_legacy_crons() — run wp_clear_scheduled_hook for every
 *      known legacy hook. Idempotent (clear-no-op when nothing's
 *      scheduled).
 *
 *   3. audit_after_clear() — re-scan. The returned array should be empty;
 *      anything in it means the clear didn't take effect for some reason
 *      (custom plugin re-registering the hook on the same request) and
 *      needs investigation.
 *
 * The legacy Smaily_Connect\Integrations\WooCommerce\Cron class's
 * add_action('smaily_connect_cron_*', ...) registrations stay in place —
 * we don't touch the legacy callbacks. The new AS recurring actions
 * (smly_plus_abandoned_cart, smly_plus_contact_sync) bridge through to
 * those same hook names via do_action() inside Bootstrap's tick
 * handlers, so business logic is unchanged: only the scheduler is.
 */
final class WPCronAuditor {

	/**
	 * Hook names registered by the legacy Smaily_Connect lifecycle that
	 * must migrate to Action Scheduler. Stored as a const so the audit /
	 * clear methods stay in sync without a list parameter being threaded
	 * through every caller.
	 */
	public const LEGACY_HOOKS = array(
		'smaily_connect_cron_sync_subscribers',
		'smaily_connect_cron_abandoned_carts_status',
		'smaily_connect_cron_abandoned_carts_email',
	);

	/**
	 * Returns hooks that currently have a WP-Cron schedule.
	 *
	 * @return array<string, int> Map of hook → next-scheduled UNIX timestamp.
	 */
	public function audit_before_clear(): array {
		$found = array();

		foreach ( self::LEGACY_HOOKS as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( is_int( $next ) ) {
				$found[ $hook ] = $next;
			}
		}

		return $found;
	}

	/**
	 * Unschedule every legacy hook. Returns the list it acted on so the
	 * caller can log what was cleared (some of which may have already
	 * been absent — wp_clear_scheduled_hook treats that as a no-op).
	 *
	 * @return string[]
	 */
	public function clear_legacy_crons(): array {
		foreach ( self::LEGACY_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		return self::LEGACY_HOOKS;
	}

	/**
	 * Paranoid re-audit. Should be empty; a non-empty result means
	 * something re-registered the legacy hook between the clear call
	 * and now (a custom plugin, or upstream rolling out a new feature
	 * we haven't accounted for). Caller logs an error notice when this
	 * happens.
	 *
	 * @return string[]
	 */
	public function audit_after_clear(): array {
		$survivors = array();

		foreach ( self::LEGACY_HOOKS as $hook ) {
			if ( wp_next_scheduled( $hook ) !== false ) {
				$survivors[] = $hook;
			}
		}

		return $survivors;
	}
}
