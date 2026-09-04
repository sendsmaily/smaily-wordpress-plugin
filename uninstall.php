<?php
/**
 * Smaily Connect uninstall handler.
 *
 * Fires when the merchant clicks "Delete" on the plugin in wp-admin
 * (NOT on deactivate — that's a temporary reconfiguration). WordPress
 * loads this file in a fresh PHP process with the plugin NOT bootstrapped,
 * so we can only rely on $wpdb + the standard option / cron APIs.
 *
 * Removes:
 *   1. Every `smly_plus_*` row in wp_options (Phase-2 BETA fork state), plus
 *      the ProfilingConsent per-contact cache/stale transients (PRO-1336) and
 *      every `smly_rec_*` row — the rec-engine connection (per-connection API
 *      key, tenant identity, endpoints map, config, connected flag —
 *      `Settings\RecEngineSettings` — plus `NotificationManager::
 *      OPTION_DOWN_SINCE`) (PRO-1337). This is a full irrecoverable local
 *      delete: no engine-side revoke call is made, so the api_key stays valid
 *      on the engine until rotated/revoked in the engine admin; a re-install
 *      needs a fresh setup token (per-connection keys since engine migration
 *      0036, so no other store is affected).
 *   2. The explicit `smaily_connect_*` option keys this plugin owns
 *      (credentials, sync settings, schema-version marker), plus the
 *      `smly_profiling_optouts` durable opt-out registry (PRO-1194/PRO-1336).
 *   3. Custom tables created by the migration runner (includes the rec-engine
 *      `smly_rec_event_queue` / `smly_rec_visitor` tables).
 *   4. User-meta freshness markers seeded by BackfillJob.
 *   5. Cron events + Action Scheduler actions the plugin scheduled (both
 *      `smly_plus_*` and `smly_rec_*` hooks — the latter are the four
 *      rec-engine flush ticks, PRO-1337).
 *
 * Without this, a deactivate + delete + reinstall cycle leaves the
 * `smly_plus_setup_completed` flag in place, which the wizard-first
 * gate (sub-PR 2.I) reads as "already onboarded" — Settings opens
 * without the wizard ever running on the new install. Erkki hit this
 * on staging; my chromium walkthrough missed it because the test
 * harness pre-cleaned the option as part of "fresh" setup.
 *
 * @package Smaily\Connect
 */

// WP only loads this file when triggered by the plugin Delete flow; reject
// every other entry point as a hard safety net.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- top-level uninstall script: locals live in this one-shot file's own scope, not global pollution.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct option/meta cleanup on uninstall; table names are $wpdb properties, values are $wpdb->prepare()d.

global $wpdb;

// --- 1. wp_options: every smly_plus_* row + a known legacy set. -----------
//
// LIKE-prefix delete catches the per-language credential keys
// (smly_plus_credentials_et, smly_plus_credentials_en, …) without
// requiring this file to know about every detected language.
//
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'smly_plus_' ) . '%'
	)
);

// Every `smly_rec_*` row: the rec-engine connection (Settings\
// RecEngineSettings — per-connection API key, base URL, tenant id/name,
// endpoints map, config, connected flag, issued_at) plus
// NotificationManager::OPTION_DOWN_SINCE (`smly_rec_health_down_since`). A
// LIKE-prefix delete, like the smly_plus_* sweep above, so a future
// smly_rec_* option doesn't need a new uninstall.php line to be swept.
// Approved design (PRO-1337): full irrecoverable local delete — no network
// call to the engine is made here, so the api_key remains valid engine-side
// until revoked/rotated in the engine admin; a re-install needs a fresh
// setup token (per-connection keys since engine migration 0036, so this
// can't affect any other store).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'smly_rec_' ) . '%'
	)
);

// ProfilingConsent (includes/Privacy/ProfilingConsent.php) caches its
// per-contact decision as two transients keyed by a hashed email —
// `smly_profiling_<hash>` (fresh, daily TTL) and `smly_profiling_stale_
// <hash>` (no-expiry fallback cache, PRO-1194) — so, like the smly_plus_*
// sweep above, a LIKE-prefix delete is the only way to catch every
// contact's rows without enumerating them. Transients without an external
// object cache live in wp_options as `_transient_{name}` /
// `_transient_timeout_{name}`; the stale prefix nests inside the fresh
// prefix, so one LIKE pair covers both (PRO-1336).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_smly_profiling_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_smly_profiling_' ) . '%'
	)
);

$legacy_options = array(
	// Credentials + verification state for the default account.
	'smaily_connect_api_credentials',
	// Subscriber-sync toggle + field selection.
	'smaily_connect_subscriber_sync_enabled',
	'smaily_connect_subscriber_sync_fields',
	'smaily_connect_subscriber_sync',
	// Checkout / WP-registration opt-in toggles.
	'smaily_connect_checkout_subscription_enabled',
	'smaily_connect_checkout_subscription_location',
	'smaily_connect_checkout_subscription_position',
	'smaily_connect_wp_subscription_enabled',
	// Abandoned-cart configuration.
	'smaily_connect_abandoned_cart_cutoff',
	'smaily_connect_abandoned_cart_status',
	// RSS widget defaults.
	'smaily_connect_rss',
	'smaily_connect_rss_category',
	'smaily_connect_rss_limit',
	'smaily_connect_rss_order_by',
	'smaily_connect_rss_sort_by',
	'smaily_connect_rss_tax_rate',
	'smaily_connect_rss_url',
	// Newsletter / tutorial / misc UI state.
	'smaily_connect_newsletter_form',
	'smaily_connect_notice_dismissed',
	'smaily_connect_notices',
	'smaily_connect_setup_url',
	'smaily_connect_user_language',
	'smaily_connect_dismiss_notice',
	// Schema-version marker the migration runner reads.
	'smaily_connect_db_version',
	// ProfilingConsent durable opt-out registry (autoload=false, hashed-email
	// keys — PRO-1194); must not survive an uninstall (PRO-1336).
	'smly_profiling_optouts',
);
foreach ( $legacy_options as $opt ) {
	delete_option( $opt );
}

// --- 2. Custom tables. ----------------------------------------------------
//
// Names match migrations/*.sql. Drop in dependency-order (none of these
// FK to each other, so order doesn't matter functionally — alphabetical
// keeps the diff predictable). The rec-engine tables (`smly_rec_event_queue`,
// `smly_rec_visitor`) were already covered here pre-PRO-1337 — no change
// needed for this list, only the options + AS-actions sweeps below.
$tables = array(
	'smly_plus_automation_mapping',
	'smly_plus_backfill_job',
	'smly_plus_cart_session',
	'smly_plus_event_queue',
	'smly_rec_event_queue',
	'smly_rec_visitor',
);
foreach ( $tables as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// --- 3. User meta freshness markers seeded by BackfillJob. ----------------
delete_metadata( 'user', 0, '_smaily_synced_at', '', true );

// --- 4. Cron + Action Scheduler events. -----------------------------------
//
// Legacy WP-Cron hooks the upstream plugin registered. clear-scheduled-hook
// is a no-op when nothing is scheduled.
$cron_hooks = array(
	'smaily_connect_cron_sync_subscribers',
	'smaily_connect_cron_abandoned_carts_status',
	'smaily_connect_cron_abandoned_carts_email',
);
foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// Action Scheduler jobs (Phase-2 fork + rec-engine flushers, PRO-1337). We
// can't load the AS library from uninstall context cleanly, so we drop rows
// directly via $wpdb. The table is created by AS itself; absent table = no-op.
// `smly_rec_%` catches the four rec-engine recurring flush hooks
// (smly_rec_flush_ingest/_customers/_orders/_catalog_remove, Bootstrap.php) —
// left unscheduled they'd keep firing on a class that no longer exists.
$as_table = $wpdb->prefix . 'actionscheduler_actions';
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$table_exists = $wpdb->get_var(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table )
);
if ( $table_exists === $as_table ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$as_table} WHERE hook LIKE %s OR hook LIKE %s",
			$wpdb->esc_like( 'smly_plus_' ) . '%',
			$wpdb->esc_like( 'smly_rec_' ) . '%'
		)
	);
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// --- 5. Invalidate the options cache. ------------------------------------
//
// We deleted rows directly via $wpdb to handle the LIKE-prefix sweep + the
// AS table cleanup in bulk, but `delete_option()` is what normally fires
// `wp_cache_delete('alloptions', 'options')` and the per-option cache
// invalidation. Without this flush, object-cache backends (Redis,
// Memcached) keep serving the stale values to the next request — the
// in-process alloptions array gets stale too, but production uninstall
// runs in a one-shot process so that piece is harmless. The persistent
// cache piece is not. wp_cache_flush() is heavy-handed, but uninstall
// is a one-time op and the cost is negligible.
wp_cache_delete( 'alloptions', 'options' );
foreach ( $legacy_options as $opt ) {
	wp_cache_delete( $opt, 'options' );
}
