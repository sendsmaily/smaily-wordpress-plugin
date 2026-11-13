<?php
/**
 * Dev and test guard: the wp-env sites never reach Smaily's subscription
 * service (CON-290).
 *
 * The plugin sends its installation report to
 * https://subscription.smaily.com/v1/reports, and a report from a dev or CI
 * site would create a real record there. `.wp-env.json` mounts this folder
 * as `wp-content/mu-plugins` on both wp-env sites, and the integration
 * bootstrap loads this file too, so every process there — the suite, WP-Cron,
 * the Action Scheduler runner — answers a report request with a WP_Error
 * that nothing intercepted first. A test that wants an answer intercepts the
 * request itself on `pre_http_request` at a lower priority.
 *
 * Never part of the plugin ZIP (`tests/` is excluded).
 *
 * @package Smaily\Connect\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- a dev-only must-use plugin.

if ( ! defined( 'ABSPATH' ) || defined( 'SMAILY_CONNECT_NO_INSTALLATION_REPORT' ) ) {
	return;
}
define( 'SMAILY_CONNECT_NO_INSTALLATION_REPORT', true );

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( $preempt === false && is_string( $url ) && strpos( $url, 'https://subscription.smaily.com/' ) === 0 ) {
			return new WP_Error( 'smaily_connect_report_blocked', 'The installation report is never sent from a dev or test site.' );
		}

		return $preempt;
	},
	PHP_INT_MAX,
	3
);
