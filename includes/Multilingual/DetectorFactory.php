<?php
/**
 * Resolves the active multilingual detector via plugin-presence detection.
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the right DetectorInterface implementation for the current site.
 *
 * Detection order (PLUGIN.md §4, PROJECT_PLAN.md §3.1 punkti 22):
 *
 *   1. WPML        — via defined('ICL_SITEPRESS_VERSION')
 *   2. Polylang    — via function_exists('pll_languages_list')
 *   3. TranslatePress — via function_exists('trp_get_url_for_language')
 *   4. SiteLocale  — fallback (always available)
 *
 * The order matters when more than one plugin is technically active —
 * historically WPML and Polylang have refused to coexist, but pilot
 * installs occasionally have both bootstraps loaded due to deactivation
 * not fully unloading classes. WPML wins in that case because it's the
 * more invasive and harder-to-misdetect of the two.
 *
 * The factory caches its first decision per request — the same detector
 * is returned for every subsequent call. Tests can reset via
 * DetectorFactory::reset() if they need a fresh decision after mocking.
 */
final class DetectorFactory {

	private static ?DetectorInterface $cached = null;

	/**
	 * Returns the detector matching the active multilingual plugin.
	 */
	public static function create(): DetectorInterface {
		if ( self::$cached !== null ) {
			return self::$cached;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			self::$cached = new WPMLAdapter();
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			self::$cached = new PolylangAdapter();
		} elseif ( function_exists( 'trp_get_url_for_language' ) ) {
			self::$cached = new TranslatePressAdapter();
		} else {
			self::$cached = new SiteLocaleAdapter();
		}

		return self::$cached;
	}

	/**
	 * Clears the per-request cache. Production code never calls this —
	 * the detector is stable for the lifetime of a request — but tests
	 * mocking ICL_SITEPRESS_VERSION / function_exists between cases need
	 * a way to discard the previous decision.
	 */
	public static function reset(): void {
		self::$cached = null;
	}

	private function __construct() {
		// Static-only factory.
	}
}
