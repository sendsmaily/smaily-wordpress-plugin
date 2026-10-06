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
 * shopper. A yes is what a consent banner stores through the API's
 * `wp_set_consent()`: the consent cookie for the category
 * (`wp_consent_<category>`, prefix filterable by the API's
 * `wp_consent_cookie_prefix`) holding `allow`. So consent is given only when
 * BOTH hold: that cookie is `allow`, and `wp_has_consent()` is true for the
 * category. Everything else — no API, no cookie, `deny`, a value of the wrong
 * type — is no consent (PRO-3849; the PRO-3845 rule asked for a consent type
 * instead, which CookieYes sets only in the browser, so the server never saw a
 * yes on a CookieYes store, and it let the opt-out-region default through).
 */
final class MarketingConsent {

	/** Filter naming the WP Consent API category Smaily gates on. */
	public const FILTER_CATEGORY = 'smaily_connect_beacon_consent_category';

	/** The value the WP Consent API stores for a yes. */
	private const ALLOW = 'allow';

	/** The WP Consent API category Smaily gates on (default `marketing`). */
	public static function category(): string {
		/** Filter the WP Consent API category Smaily gates on. */
		return (string) apply_filters( self::FILTER_CATEGORY, 'marketing' );
	}

	/** Whether the current visitor gave an explicit yes for the category. */
	public static function given(): bool {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return false;
		}

		$category = self::category();

		return self::decide( wp_has_consent( $category ), self::stored_consent( $category ) );
	}

	/**
	 * The consent a banner stored for the category through the WP Consent API
	 * (`allow` / `deny`), read from the API's consent cookie the way the API
	 * reads it; null when there is none.
	 */
	public static function stored_consent( string $category ): ?string {
		if ( function_exists( 'wp_validate_consent_category' ) ) {
			$category = wp_validate_consent_category( $category );
		}
		// The WP Consent API's own consent-cookie prefix, read as the API reads it.
		$prefix = (string) apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The WP Consent API's hook, not ours.
		$name   = $prefix . '_' . $category;

		if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
	}

	/**
	 * The rule itself, on the two WP Consent API answers.
	 *
	 * @param mixed $has_consent    What `wp_has_consent()` returned.
	 * @param mixed $stored_consent The category's consent cookie (`stored_consent()`).
	 */
	public static function decide( $has_consent, $stored_consent ): bool {
		return true === $has_consent && self::ALLOW === $stored_consent;
	}
}
