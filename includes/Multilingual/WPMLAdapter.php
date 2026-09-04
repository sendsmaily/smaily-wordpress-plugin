<?php
/**
 * WPML adapter — uses the wpml_* filter API surface.
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party plugin hooks (WPML / WooCommerce extensions) consumed here, not hooks we define.

/**
 * Implements DetectorInterface against WPML (WordPress Multilingual).
 *
 * WPML exposes its API through filter callbacks rather than free functions
 * — `apply_filters('wpml_active_languages', null)` returns the configured
 * languages, `apply_filters('wpml_object_id', ...)` resolves translations.
 * That makes WPML detectable safely via `defined('ICL_SITEPRESS_VERSION')`
 * (set by WPML's bootstrap) without having to require any of WPML's class
 * files at our load time.
 *
 * Code structure mirrors PLUGIN_IMPLEMENTATION_WP.md §"Multilingual
 * support" so the mootori-side documentation stays a useful reference
 * for whoever picks this up. Namespacing diverges (Smaily\Connect\* vs
 * the spec's Smaily\RecEngine\*) per the fork strategy in PLUGIN.md §2.
 */
final class WPMLAdapter implements DetectorInterface {

	public function get_detected_languages(): array {
		$languages = apply_filters( 'wpml_active_languages', null );

		if ( ! is_array( $languages ) ) {
			return array();
		}

		return array_values( array_map( 'strval', array_keys( $languages ) ) );
	}

	public function get_current_language(): string {
		$current = apply_filters( 'wpml_current_language', null );

		return is_string( $current ) ? $current : '';
	}

	public function get_translated_post_id( int $post_id, string $language ): ?int {
		$translated = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), false, $language );

		if ( ! is_int( $translated ) && ! ctype_digit( (string) $translated ) ) {
			return null;
		}

		return (int) $translated;
	}

	public function get_default_language(): string {
		$default = apply_filters( 'wpml_default_language', null );

		return is_string( $default ) ? $default : '';
	}

	public function get_canonical_post_id( int $post_id ): int {
		$default = $this->get_default_language();
		if ( $default === '' ) {
			return $post_id;
		}

		// wpml_object_id resolved into the default language returns the
		// default-language post itself when $post_id already is it (idempotent).
		$canonical = $this->get_translated_post_id( $post_id, $default );

		return ( $canonical !== null ) ? $canonical : $post_id;
	}

	public function get_translated_permalink( int $post_id, string $language ): ?string {
		$translated_id = $this->get_translated_post_id( $post_id, $language );
		if ( $translated_id === null ) {
			return null;
		}

		$permalink = apply_filters( 'wpml_permalink', get_permalink( $translated_id ), $language );

		return is_string( $permalink ) ? $permalink : null;
	}

	public function get_translations( int $post_id ): array {
		$languages = $this->get_detected_languages();

		$name        = array();
		$description = array();
		$url         = array();

		foreach ( $languages as $lang ) {
			$translated_id = $this->get_translated_post_id( $post_id, $lang );
			if ( $translated_id === null ) {
				continue;
			}

			$name[ $lang ]        = (string) get_the_title( $translated_id );
			$description[ $lang ] = (string) get_post_field( 'post_excerpt', $translated_id );

			$permalink    = apply_filters( 'wpml_permalink', get_permalink( $translated_id ), $lang );
			$url[ $lang ] = is_string( $permalink ) ? $permalink : '';
		}

		return array(
			'name'        => $name,
			'description' => array_filter(
				$description,
				static fn ( string $value ): bool => $value !== ''
			),
			'product_url' => $url,
		);
	}
}
