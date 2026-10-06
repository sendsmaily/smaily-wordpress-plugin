<?php
/**
 * The store's marketing-consent rule, read from the WP Consent API.
 *
 * @package Smaily\Connect\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Consent counts only as an explicit yes (Erkki, 2026-10-06; parity with the
 * Magento plugin's PRO-3664 decision).
 *
 * The WP Consent API's `wp_has_consent()` answers true when no consent plugin
 * has set a consent type (the API reads that as "no consent management") and,
 * in an opt-out region, until the visitor opts out. Neither is a yes from the
 * shopper. So consent is given only when BOTH hold: a consent plugin has set a
 * consent type (`wp_get_consent_type()` is a non-empty string), and
 * `wp_has_consent()` is true for the category. Everything else — no API, no
 * type, no consent for the category, a value of the wrong type — is no consent.
 */
final class MarketingConsent {

	/** Filter naming the WP Consent API category Smaily gates on. */
	public const FILTER_CATEGORY = 'smaily_connect_beacon_consent_category';

	/** The WP Consent API category Smaily gates on (default `marketing`). */
	public static function category(): string {
		/** Filter the WP Consent API category Smaily gates on. */
		return (string) apply_filters( self::FILTER_CATEGORY, 'marketing' );
	}

	/** Whether the current visitor gave an explicit yes for the category. */
	public static function given(): bool {
		if ( ! function_exists( 'wp_get_consent_type' ) || ! function_exists( 'wp_has_consent' ) ) {
			return false;
		}

		return self::decide( wp_get_consent_type(), wp_has_consent( self::category() ) );
	}

	/**
	 * The rule itself, on the two WP Consent API answers.
	 *
	 * @param mixed $consent_type What `wp_get_consent_type()` returned.
	 * @param mixed $has_consent  What `wp_has_consent()` returned.
	 */
	public static function decide( $consent_type, $has_consent ): bool {
		return is_string( $consent_type ) && '' !== $consent_type && true === $has_consent;
	}
}
