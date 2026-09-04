<?php
/**
 * Test-support helper — resets every wp_options row + table content the
 * plugin owns so each integration test starts from a known clean slate.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;

/**
 * Mirror of uninstall.php's cleanup but WITHOUT dropping the custom
 * tables (we only TRUNCATE) — the schema must stay so the next test
 * doesn't need to reactivate the plugin. Use this in setUp() of every
 * integration test to avoid cross-test state bleed.
 *
 * Why not piggyback on uninstall.php directly: that file DROPs tables
 * + invalidates the object cache, both of which are expensive and
 * destructive between test cases. EnvScrub is the lighter weight
 * sibling for test-isolation.
 */
final class EnvScrub {

	public static function reset(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// wp_options — every smly_* row (smly_plus_* + smly_rec_*) and the
		// legacy keys this plugin owns. The single LIKE 'smly_%' sweep
		// covers both prefixes; uninstall.php uses the same pattern.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'smly_' ) . '%'
			)
		);
		$legacy_options = array(
			'smaily_connect_api_credentials',
			'smaily_connect_subscriber_sync_enabled',
			'smaily_connect_subscriber_sync_fields',
			'smaily_connect_checkout_subscription_enabled',
			'smaily_connect_wp_subscription_enabled',
			'smaily_connect_abandoned_cart_cutoff',
			'smaily_connect_abandoned_cart_status',
			'smaily_connect_abandoned_cart_fields',
		);
		foreach ( $legacy_options as $opt ) {
			delete_option( $opt );
		}

		// Custom tables — TRUNCATE only (keep schema).
		$tables = array(
			'smly_plus_event_queue',
			'smly_plus_backfill_job',
			'smly_plus_automation_mapping',
			'smly_plus_cart_session',
			'smly_rec_event_queue',
			'smly_rec_visitor',
		);
		foreach ( $tables as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// Defensive: integration suite may run before activation
			// completed the migrator; TRUNCATE on a missing table is
			// fatal, so skip when absent.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Invalidate alloptions so the in-process get_option() reflects
		// the deletes immediately rather than serving the warm cache.
		// LIKE-prefix DELETEs go straight to MariaDB and bypass the
		// per-option cache delete that delete_option() normally fires,
		// so we also have to flush the per-key entries — otherwise
		// autoload=false options (smly_rec_api_key et al.) keep
		// serving their pre-scrub values across tests.
		wp_cache_delete( 'alloptions', 'options' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$leftover_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'smly_' ) . '%'
			)
		);
		// phpcs:enable
		// $leftover_keys is what survived the DELETE — usually empty;
		// we also drop the known set we just scrubbed.
		$keys_to_flush = array_merge( $leftover_keys, array(
			RecEngineSettings::OPTION_CONNECTED,
			RecEngineSettings::OPTION_API_KEY,
			RecEngineSettings::OPTION_BASE_URL,
			RecEngineSettings::OPTION_ENGINE_VERSION,
			RecEngineSettings::OPTION_TENANT_ID,
			RecEngineSettings::OPTION_TENANT_NAME,
			RecEngineSettings::OPTION_ENDPOINTS,
			RecEngineSettings::OPTION_CONFIG,
			RecEngineSettings::OPTION_ISSUED_AT,
			// One-time-stamp options (autoload=false → per-key cache) the
			// LIKE-sweep deletes from the DB but not from the object cache;
			// stale cached values would make re-runnable one-time migrations
			// (e.g. the PRO-1195 legacy cart drain) silently no-op in tests.
			\Smaily\Connect\Migration\LegacyCartDrain::DRAINED_OPTION,
			// Same class of bug, worse symptom (PRO-1943): with the version
			// stamp still cached, update_option() to a NEW value finds the row
			// gone (0 rows affected), writes nothing and leaves the cache — so
			// Bootstrap::maybe_run_upgrade() cannot be re-armed mid-suite and
			// upgrade-detect tests pass or fail by suite order.
			\Smaily\Connect\Activation::OPTION_PLUGIN_VERSION,
		) );
		foreach ( $keys_to_flush as $key ) {
			wp_cache_delete( (string) $key, 'options' );
			wp_cache_delete( (string) $key, 'notoptions' );
		}
	}

	private function __construct() {
		// Static-only helper.
	}
}
