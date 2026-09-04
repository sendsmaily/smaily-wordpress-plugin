<?php
/**
 * Plugin-wide constants for the Smaily Connect 2.0 fork.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

namespace Smaily\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized constants for URLs, slugs, option keys, and capability checks.
 *
 * Values that may need per-environment overrides are wrapped in WordPress
 * filters so they can be adjusted without modifying plugin code:
 *
 *   add_filter( 'smaily_connect_setup_url', fn() => 'https://my-staging.example/setup/exchange' );
 */
final class Constants {

	/**
	 * Plugin slug — matches the directory name and text domain.
	 */
	public const SLUG = 'smaily-connect';

	/**
	 * Text domain for translations.
	 */
	public const TEXT_DOMAIN = 'smaily-connect';

	/**
	 * Recommendation-engine setup-token exchange endpoint.
	 *
	 * Points to the engine's production domain. This is only the STATIC default
	 * used for the very first exchange; the engine returns its live
	 * engine_base_url in the setup-exchange response, so installs auto-adapt if
	 * the host ever moves. Production migrations bump this constant in a one-line
	 * plugin update. Per-site overrides are possible through the
	 * smaily_connect_setup_url filter.
	 */
	public const SETUP_BASE_URL = 'https://intelligence.smaily.com/setup/exchange';

	/**
	 * Merchant documentation site (install / wizard / settings / troubleshooting).
	 *
	 * Currently hosted at smaily.com; the long-term plan is to move every Smaily
	 * plugin's docs under connect.smaily.com (each plugin its own home). When that
	 * lands this is a one-line change — every UI link resolves through docs_url().
	 * Per-site overrides via the smaily_connect_docs_url filter.
	 */
	public const DOCS_URL = 'https://smaily.com/connect-woo/';

	/**
	 * REST namespace exposed by the plugin (e.g. /wp-json/smaily-connect/v1/...).
	 */
	public const REST_NAMESPACE = 'smaily-connect/v1';

	/**
	 * Option key holding the schema version run by DB\Migrator.
	 */
	public const OPTION_SCHEMA_VERSION = 'smly_plus_schema_version';

	/**
	 * Capability required to manage plugin settings.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Resolves the rec-engine setup URL, allowing per-site overrides.
	 */
	public static function setup_url(): string {
		$url = (string) apply_filters( 'smaily_connect_setup_url', self::SETUP_BASE_URL );

		return $url !== '' ? $url : self::SETUP_BASE_URL;
	}

	/**
	 * Resolves the merchant documentation URL, allowing per-site overrides.
	 */
	public static function docs_url(): string {
		$url = (string) apply_filters( 'smaily_connect_docs_url', self::DOCS_URL );

		return $url !== '' ? $url : self::DOCS_URL;
	}

	/**
	 * Returns the plugin version from the bootstrap define.
	 */
	public static function version(): string {
		return defined( 'SMAILY_CONNECT_VERSION' ) ? (string) SMAILY_CONNECT_VERSION : '0.0.0-dev';
	}

	private function __construct() {
		// Static-only utility class.
	}
}
