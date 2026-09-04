<?php
/**
 * Single source of truth for a contact's Smaily `language` code.
 *
 * @package Smaily\Connect\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Support;

use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the language code we send to Smaily for a WP user / WC order.
 *
 * Why this exists (Prike incident, 2026-06-30): the upstream Smaily plugin's
 * "sync all subscribers" cron derives language from
 * Helper::get_current_language_code(), which is context-dependent and — in a
 * cron / Action-Scheduler request — falls back to get_locale() = the WP site
 * locale. On a store whose WP locale is `en` but whose real content default
 * (WPML) is `et`, that pushes `en` onto every contact lacking a stored
 * per-user language, daily, overwriting any correct value. ~1000 contacts
 * drifted to `en` that way.
 *
 * This resolver mirrors the language sources the merchant's Make automations
 * read (which were the *correct* logic, just racing the buggy cron):
 *   - customer language  → `_user_preferred_language` user meta
 *   - order language     → `wpml_language` order meta
 *   - default            → the multilingual plugin's configured default
 *                          (WPML `wpml_default_language` = `et`), NOT
 *                          get_locale().
 *
 * Context-independent by construction (no ICL_LANGUAGE_CODE /
 * pll_current_language reads), so it returns the same answer in a cron tick
 * as in an HTTP request — the property the legacy helper lacked.
 *
 * Empty-value policy mirrors SubscriberPayloadBuilder: when no language can be
 * determined the resolver returns '' and the CALLER omits the field. Smaily
 * treats absent and empty differently — absent leaves the contact's existing
 * language intact, empty would wipe it — so we never send ''.
 *
 * The WP profile locale (get_user_locale) is deliberately NOT a source: it is
 * the admin-UI language, defaults to the site locale for front-end customers,
 * and reintroduces the very `en`-leak this class fixes.
 */
final class ContactLanguageResolver {

	/**
	 * User meta holding the customer's preferred language (WPML / the
	 * merchant's Make `customer.update` source). Filterable so a store using a
	 * different key can redirect the lookup without forking this class.
	 */
	public const FILTER_USER_PREF_META = 'smaily_connect_user_language_meta_key';

	private const DEFAULT_USER_PREF_META = '_user_preferred_language';

	/** Order meta holding the language the order was placed in (WPML). */
	private const ORDER_LANGUAGE_META = 'wpml_language';

	/** Final override hook: apply_filters( FILTER, $language, $context ). */
	public const FILTER_LANGUAGE = 'smaily_connect_contact_language';

	private DetectorInterface $detector;

	/**
	 * Latest-order language lookup, injectable for testing. Given a user id,
	 * returns that user's most-recent order's `wpml_language` (raw, pre-
	 * normalisation) or '' when unavailable.
	 *
	 * @var callable(int): string
	 */
	private $order_language_provider;

	/**
	 * @param DetectorInterface|null  $detector               Multilingual detector; defaults to the active one.
	 * @param callable(int):string|null $order_language_provider Latest-order language lookup; defaults to a wc_get_orders() read.
	 */
	public function __construct( ?DetectorInterface $detector = null, ?callable $order_language_provider = null ) {
		$this->detector                = $detector ?? DetectorFactory::create();
		$this->order_language_provider = $order_language_provider ?? function ( int $user_id ): string {
			return $this->latest_order_language( $user_id );
		};
	}

	/**
	 * Resolve the language code for a WP user (contact sync, automations).
	 *
	 * Priority: `_user_preferred_language` → most-recent order's
	 * `wpml_language` → multilingual default → site locale.
	 */
	public function for_user( \WP_User $user ): string {
		$user_id = (int) $user->ID;

		$language = $this->from_user_meta( $user_id );

		if ( $language === '' ) {
			$language = $this->normalize( (string) ( $this->order_language_provider )( $user_id ) );
		}

		if ( $language === '' ) {
			$language = $this->default_language();
		}

		return $this->filtered( $this->clamp_to_active( $language ), $user );
	}

	/**
	 * Resolve the language code for a WC order (first-order automation, and
	 * any future order-triggered contact refresh).
	 *
	 * Priority: order's `wpml_language` → the (registered) customer's
	 * `_user_preferred_language` → multilingual default → site locale.
	 */
	public function for_order( \WC_Order $order ): string {
		$language = $this->normalize( (string) $order->get_meta( self::ORDER_LANGUAGE_META ) );

		if ( $language === '' ) {
			$customer_id = (int) $order->get_customer_id();
			if ( $customer_id > 0 ) {
				$language = $this->from_user_meta( $customer_id );
			}
		}

		if ( $language === '' ) {
			$language = $this->default_language();
		}

		return $this->filtered( $this->clamp_to_active( $language ), $order );
	}

	/**
	 * Resolve the language code for a contact we know only by email (a GUEST
	 * abandoned cart, PRO-1195): no user meta and no order exists yet, so the
	 * chain starts at the multilingual default → site-locale short code. Kept
	 * on the resolver so guest sends obey the same F3-47 rules as every other
	 * contact path (context-independent, clamped, omit-on-empty by the caller).
	 */
	public function for_guest(): string {
		return $this->filtered( $this->clamp_to_active( $this->default_language() ), null );
	}

	/**
	 * Hard guarantee against an out-of-set language reaching Smaily: a resolved
	 * code that is NOT one of the site's currently-active languages is clamped
	 * to the configured default. Protects against dirty history — a stray
	 * `_user_preferred_language` / old order `wpml_language` from a language the
	 * store has since removed (e.g. a `ru` value on an `et`/`en`-only store)
	 * would otherwise spawn a contact list that shouldn't exist (Erkki, F3-47).
	 *
	 * No-op when the detector can't enumerate languages (empty allowlist) —
	 * we don't clamp against an unknown set. The explicit
	 * `smaily_connect_contact_language` filter runs AFTER this, so a merchant
	 * override is still the last word.
	 */
	private function clamp_to_active( string $language ): string {
		if ( $language === '' ) {
			return '';
		}

		$active = $this->active_languages();
		if ( $active === array() || in_array( $language, $active, true ) ) {
			return $language;
		}

		return $this->default_language();
	}

	/**
	 * The site's currently-active language codes, normalised. Empty when the
	 * detector can't determine them.
	 *
	 * @return array<int, string>
	 */
	private function active_languages(): array {
		$languages = array();

		foreach ( $this->detector->get_detected_languages() as $code ) {
			$normalized = $this->normalize( (string) $code );
			if ( $normalized !== '' ) {
				$languages[] = $normalized;
			}
		}

		return array_values( array_unique( $languages ) );
	}

	/**
	 * Read + normalise the customer's preferred-language user meta.
	 */
	private function from_user_meta( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return '';
		}

		$value = get_user_meta( $user_id, $this->user_pref_meta_key(), true );

		return is_string( $value ) ? $this->normalize( $value ) : '';
	}

	private function user_pref_meta_key(): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::DEFAULT_USER_PREF_META;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant value IS the plugin prefix (smaily_connect_*); PCP can't resolve self::CONST.
		$key = apply_filters( self::FILTER_USER_PREF_META, self::DEFAULT_USER_PREF_META );

		return ( is_string( $key ) && $key !== '' ) ? $key : self::DEFAULT_USER_PREF_META;
	}

	/**
	 * The site's content default language — WPML/Polylang configured default
	 * (e.g. `et`), NOT the WP admin locale. Last-ditch: the site locale short
	 * code, so a single-language store still keys *something* sensible.
	 */
	private function default_language(): string {
		$default = $this->normalize( $this->detector->get_default_language() );
		if ( $default !== '' ) {
			return $default;
		}

		return $this->normalize( function_exists( 'get_locale' ) ? (string) get_locale() : '' );
	}

	/**
	 * Most-recent order's `wpml_language`. One query per user; only reached
	 * when the preferred-language meta is empty, and skipped entirely when
	 * WooCommerce isn't loaded (unit context).
	 */
	private function latest_order_language( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return '';
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		if ( ! is_array( $orders ) || $orders === array() ) {
			return '';
		}

		$order = $orders[0];
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$language = $order->get_meta( self::ORDER_LANGUAGE_META );

		return is_string( $language ) ? $language : '';
	}

	/**
	 * Collapse a locale to a lowercase 2-letter-ish language subtag
	 * (`en_US` → `en`, `ET` → `et`). Smaily and the merchant's Make automations
	 * both key on the short code.
	 */
	private function normalize( string $code ): string {
		$code = trim( $code );
		if ( $code === '' ) {
			return '';
		}

		$parts = preg_split( '/[_-]/', $code );
		$lang  = ( is_array( $parts ) && isset( $parts[0] ) ) ? $parts[0] : $code;

		return strtolower( $lang );
	}

	/**
	 * Final override seam. A filter may correct an edge case or force-set a
	 * language even when resolution returned '' (e.g. derive from a custom
	 * field). Returning '' from the filter keeps the omit behaviour.
	 *
	 * @param \WP_User|\WC_Order|null $context Null for guest (email-only) contacts.
	 */
	private function filtered( string $language, $context ): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $language;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant value IS the plugin prefix (smaily_connect_*); PCP can't resolve self::CONST.
		$filtered = apply_filters( self::FILTER_LANGUAGE, $language, $context );

		return is_string( $filtered ) ? $filtered : $language;
	}
}
