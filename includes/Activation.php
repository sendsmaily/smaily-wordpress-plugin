<?php
/**
 * Plugin activation handler for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Migration\LegacyCartDrain;
use Smaily\Connect\Migration\WPCronAuditor;
use Smaily\Connect\Smaily\EventQueue;

/**
 * Runs on plugin activation.
 *
 * The legacy Lifecycle class (Smaily_Connect\Includes\Lifecycle) handles CF7 /
 * Elementor / WooCommerce table setup for the 1.x feature set. This class
 * complements it by initialising 2.0-only state:
 *
 *   - DB schema for the new event-queue / backfill-job / automation-mapping /
 *     rec-engine tables (via DB\Migrator)
 *   - Default option values for the new BETA features
 *   - Legacy WP-Cron → Action Scheduler migration (via Migration\WPCronAuditor)
 *   - Action Scheduler recurring jobs that drive the queue + cart flows
 *
 * Activation must be idempotent — WordPress runs it on every "activate" click,
 * including after upgrades. The schema migration is keyed on
 * Constants::OPTION_SCHEMA_VERSION so re-runs are no-ops. AS scheduling uses
 * as_has_scheduled_action() so we never enqueue a duplicate recurring row.
 */
final class Activation {

	/**
	 * Stamp of the plugin version this routine last ran for. Bootstrap's
	 * admin_init upgrade-detect compares it to SMAILY_CONNECT_VERSION so a
	 * file-overwrite upgrade (which never fires register_activation_hook)
	 * still triggers the migrations exactly once.
	 */
	public const OPTION_PLUGIN_VERSION = 'smly_plus_plugin_version';

	/**
	 * Activation callback. WordPress passes a $network_wide boolean we ignore
	 * for now — multisite network-wide activation is in the v1.x backlog
	 * (PLUGIN.md §1 "Selgelt väljas").
	 */
	public static function run(): void {
		self::set_default_options();
		self::run_migrations();
		self::drain_legacy_abandoned_carts();
		self::cleanup_retired_options();
		self::reencrypt_legacy_secrets();
		self::migrate_wp_cron_to_action_scheduler();
		self::schedule_recurring_action_scheduler_jobs();
		self::stamp_plugin_version();
	}

	/**
	 * One-time drain of in-flight legacy abandoned-cart rows into the new
	 * tracker (PRO-1195 upgrade continuity — a store that just updates the
	 * plugin loses zero carts). Runs AFTER run_migrations (the tracker table
	 * must exist); LegacyCartDrain self-guards with an option stamp so every
	 * later activation/upgrade is a no-op. Read-only on the legacy table and
	 * schedules NOTHING (F3-53: an upgrade-time migration must never re-arm
	 * a legacy WP-Cron schedule).
	 */
	private static function drain_legacy_abandoned_carts(): void {
		try {
			( new LegacyCartDrain() )->maybe_run();
		} catch ( \Throwable $e ) {
			// Activation must never fatal on a drain problem — the carts stay
			// in the legacy table (un-stamped, retried on the next upgrade).
			\Smaily\Connect\Support\DebugLog::write(
				sprintf( '[smaily-connect cart.drain] drain failed: %s', $e->getMessage() )
			);
		}
	}

	/**
	 * One-time migration of stored secrets from the legacy CBC blob format to
	 * the v2 GCM format (FABLE_AUDIT §4#2: the legacy format used a static IV
	 * taken from AUTH_KEY and persisted it in the blob, leaking an AUTH_KEY
	 * prefix into every DB dump).
	 *
	 * Idempotent: already-migrated blobs are skipped by the `smy2:` prefix
	 * check, and a blob that no longer decrypts (e.g. the WP salts were
	 * rotated since it was written) is left untouched — it is equally
	 * unreadable in either format, and overwriting it would destroy the
	 * evidence the merchant needs to know to re-enter the credential.
	 *
	 * Covers every location that persists a Cypher blob: the legacy default
	 * Smaily credentials array, the per-account Phase-2 credential arrays,
	 * and the rec-engine API key (a raw string option, autoload=false).
	 */
	private static function reencrypt_legacy_secrets(): void {
		if ( ! class_exists( \Smaily_Connect\Includes\Cypher::class ) ) {
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-cypher.class.php';
		}

		global $wpdb;

		// Array-shaped credential options: the legacy default + per-account.
		$option_keys = array( Settings\Credentials::LEGACY_OPTION_KEY );
		$like        = $wpdb->esc_like( Settings\Credentials::PHASE2_OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time option_name scan on (de)activation; nothing to cache.
		$per_account = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		foreach ( array_merge( $option_keys, is_array( $per_account ) ? $per_account : array() ) as $key ) {
			$value = get_option( $key );
			if ( ! is_array( $value ) || ! isset( $value['password'] ) ) {
				continue;
			}
			$migrated = self::reencrypted_blob( (string) $value['password'] );
			if ( $migrated !== null ) {
				$value['password'] = $migrated;
				update_option( $key, $value );
			}
		}

		// Raw-string rec-engine API key (keep autoload=false).
		$migrated = self::reencrypted_blob( (string) get_option( Settings\RecEngineSettings::OPTION_API_KEY, '' ) );
		if ( $migrated !== null ) {
			update_option( Settings\RecEngineSettings::OPTION_API_KEY, $migrated, false );
		}
	}

	/**
	 * Re-encrypt a single stored blob, or null when no write is needed
	 * (empty, already v2, or undecryptable — see reencrypt_legacy_secrets).
	 */
	private static function reencrypted_blob( string $blob ): ?string {
		if ( $blob === '' || \Smaily_Connect\Includes\Cypher::is_v2( $blob ) ) {
			return null;
		}
		$plain = \Smaily_Connect\Includes\Cypher::decrypt( $blob );
		if ( $plain === '' ) {
			return null;
		}

		return \Smaily_Connect\Includes\Cypher::encrypt( $plain );
	}

	/**
	 * One-time removal of option keys whose setting has been retired.
	 *
	 * The uninstall sweep already catches these by LIKE-prefix, but that only
	 * runs on delete — an upgraded store keeps the orphan row forever unless
	 * it is removed here. delete_option() is idempotent (a no-op when the key
	 * is absent, as on a fresh install that never saved the setting), so this
	 * is safe to run on every upgrade-detect.
	 *
	 * Retired keys:
	 *
	 *   - The per-domain rec-engine sync toggles (3.9). `sync_orders` /
	 *     `sync_customers` / `sync_products` / `track_cart_events` were
	 *     write-only UI toggles with no consumer — the ingest hook handlers
	 *     always gated on is_connected() alone. 3.9 made that the explicit
	 *     model (connect ⇒ sync all; the system decides) and dropped the
	 *     toggles. The browse-tracking key (smly_plus_rec_track_browsing) is
	 *     the surviving Step-4 preference and is NOT removed.
	 *   - "Force opt-in on automation triggers" (PRO-1716). Every reader is
	 *     gone — AutomationRouter now passes force_opt_in=false
	 *     unconditionally — so a store that once enabled it kept a truthy
	 *     option the merchant can no longer see or change (PRO-1897).
	 */
	private static function cleanup_retired_options(): void {
		$dead_keys = array(
			'smly_plus_rec_sync_orders',
			'smly_plus_rec_sync_customers',
			'smly_plus_rec_sync_products',
			'smly_plus_rec_track_cart_events',
			'smly_plus_contact_sync_automation_force_opt_in',
		);
		foreach ( $dead_keys as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Record the version this run completed for, so the admin_init
	 * upgrade-detect settles to a single get_option() no-op until the next
	 * version bump.
	 */
	private static function stamp_plugin_version(): void {
		if ( defined( 'SMAILY_CONNECT_VERSION' ) ) {
			update_option( self::OPTION_PLUGIN_VERSION, (string) SMAILY_CONNECT_VERSION, false );
		}
	}

	private static function set_default_options(): void {
		// Placeholder: future sub-commits populate this with defaults for the
		// new Settings keys (multilingual mode, notification preferences, etc.).
		// Kept as an explicit empty hook so the structure is visible to readers.
	}

	private static function run_migrations(): void {
		( new Migrator() )->migrate();
	}

	/**
	 * Three-pass legacy-cron migration (WPCronAuditor):
	 *   1. log what's currently scheduled,
	 *   2. wp_clear_scheduled_hook each known legacy hook,
	 *   3. paranoid re-audit; admin-error if anything survived.
	 *
	 * Legacy hooks: smaily_connect_cron_sync_subscribers,
	 * smaily_connect_cron_abandoned_carts_status,
	 * smaily_connect_cron_abandoned_carts_email.
	 */
	private static function migrate_wp_cron_to_action_scheduler(): void {
		$auditor = new WPCronAuditor();

		$before = $auditor->audit_before_clear();
		if ( ! empty( $before ) ) {
			\Smaily\Connect\Support\DebugLog::write(
				sprintf(
					'[smaily-connect] WP-Cron audit before clear: %s',
					wp_json_encode( $before )
				)
			);
		}

		$auditor->clear_legacy_crons();

		$survivors = $auditor->audit_after_clear();
		if ( ! empty( $survivors ) ) {
			\Smaily\Connect\Support\DebugLog::write(
				sprintf(
					'[smaily-connect] WP-Cron audit AFTER clear still shows %s — investigate (custom plugin re-scheduling?)',
					wp_json_encode( $survivors )
				)
			);
		}
	}

	/**
	 * Replace the legacy WP-Cron schedules with Action Scheduler recurring
	 * actions (Bootstrap registers the actual tick callbacks):
	 *
	 *   smly_plus_contact_sync   — daily,  bridges to smaily_connect_cron_sync_subscribers
	 *   smly_plus_abandoned_cart — 15 min, bridges to the two abandoned_cart_* legacy hooks
	 *
	 * smly_plus_flush_event_queue + smly_plus_retry_failed_events are
	 * scheduled by Bootstrap::register_action_scheduler_jobs on init for
	 * every request; the activation hook only seeds the two cart/sync
	 * recurring rows because those need the daily / 15-min cadence rather
	 * than the queue's tight 60s flush loop.
	 */
	private static function schedule_recurring_action_scheduler_jobs(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( 'smly_plus_contact_sync', array(), EventQueue::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time(),
				DAY_IN_SECONDS,
				'smly_plus_contact_sync',
				array(),
				EventQueue::AS_GROUP
			);
		}

		if ( ! as_has_scheduled_action( 'smly_plus_abandoned_cart', array(), EventQueue::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time(),
				15 * MINUTE_IN_SECONDS,
				'smly_plus_abandoned_cart',
				array(),
				EventQueue::AS_GROUP
			);
		}
	}

	private function __construct() {
		// Static-only handler.
	}
}
