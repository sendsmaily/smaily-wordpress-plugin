<?php
/**
 * Integration: legacy WP-Cron must stay dead — no re-arm, no live mass-send callback.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * What F3-53 bug class this pins (Prike, 2026-07-08):
 *
 *   Scheduling moved to Action Scheduler (sub-PR 5.D) and WPCronAuditor
 *   clears the legacy smaily_connect_cron_* WP-Cron events at activation —
 *   but the legacy Lifecycle used to RE-schedule them from activate() and
 *   from `activated_plugin` (any WooCommerce (re)activation), resurrecting
 *   a duplicate scheduler that nothing cleared again. Worst consequence:
 *   the daily legacy subscriber mass-send (Cron::smaily_sync_subscribers)
 *   was still add_action-registered, so a surviving WP-Cron event ran the
 *   cron-unsafe language path (the F3-47 clobber) even though F3-48.3 had
 *   "orphaned" it from the AS tick. An orphaned callback isn't dead while
 *   a legacy scheduler event can still fire it.
 *
 *   Pinned here against the real plugin load:
 *   - nothing is registered on smaily_connect_cron_sync_subscribers;
 *   - a WooCommerce activation event no longer schedules any legacy cron;
 *   - the legacy Lifecycle no longer carries a WP-Cron scheduler at all.
 */
final class LegacyCronScheduleTest extends TestCase {

	private const LEGACY_HOOKS = array(
		'smaily_connect_cron_sync_subscribers',
		'smaily_connect_cron_abandoned_carts_status',
		'smaily_connect_cron_abandoned_carts_email',
	);

	public function test_legacy_subscriber_mass_send_has_no_registered_callback(): void {
		self::assertFalse(
			has_action( 'smaily_connect_cron_sync_subscribers' ),
			'The legacy daily mass-send must not be invocable — its language source is cron-unsafe (F3-47).'
		);
	}

	public function test_woocommerce_activation_does_not_rearm_legacy_wp_cron(): void {
		foreach ( self::LEGACY_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Fires the legacy Lifecycle::check_for_dependency — the re-arm path
		// that resurrected the duplicate scheduler on every WC (re)activation.
		do_action( 'activated_plugin', 'woocommerce/woocommerce.php', false );

		foreach ( self::LEGACY_HOOKS as $hook ) {
			self::assertFalse(
				wp_next_scheduled( $hook ),
				sprintf( 'WooCommerce activation must not schedule the legacy WP-Cron event %s.', $hook )
			);
		}
	}

	public function test_legacy_abandoned_cart_pass_has_no_registered_callbacks(): void {
		// PRO-1195: the namespaced pipeline owns abandoned cart. A stray
		// surviving legacy WP-Cron event must find NOTHING to fire — the
		// retired status/email pass double-reminding against the new
		// pipeline would be the F3-53 resurrection class all over again.
		self::assertFalse(
			has_action( 'smaily_connect_cron_abandoned_carts_status' ),
			'The legacy abandoned-cart status pass must not be invocable.'
		);
		self::assertFalse(
			has_action( 'smaily_connect_cron_abandoned_carts_email' ),
			'The legacy abandoned-cart email pass must not be invocable.'
		);
	}

	public function test_legacy_lifecycle_no_longer_carries_a_wp_cron_scheduler(): void {
		self::assertFalse(
			method_exists( \Smaily_Connect\Includes\Lifecycle::class, 'set_scheduled_actions' ),
			'Scheduling is owned by Action Scheduler; the legacy scheduler method must stay removed.'
		);
	}
}
