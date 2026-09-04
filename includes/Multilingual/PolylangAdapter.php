<?php
/**
 * Polylang adapter — uses the pll_* function API surface.
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Implements DetectorInterface against Polylang.
 *
 * Polylang exposes a small set of free functions (pll_languages_list,
 * pll_get_post, pll_current_language) which we call directly through
 * the global namespace. Detection uses `function_exists('pll_languages_list')`
 * to confirm Polylang's bootstrap has run.
 *
 * Code structure mirrors PLUGIN_IMPLEMENTATION_WP.md §"Multilingual
 * support" PolylangAdapter section.
 */
final class PolylangAdapter implements DetectorInterface {

	public function get_detected_languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}

		$languages = \pll_languages_list();

		return is_array( $languages ) ? array_values( array_map( 'strval', $languages ) ) : array();
	}

	public function get_current_language(): string {
		if ( ! function_exists( 'pll_current_language' ) ) {
			return '';
		}

		$current = \pll_current_language();

		return is_string( $current ) ? $current : '';
	}

	public function get_translated_post_id( int $post_id, string $language ): ?int {
		if ( ! function_exists( 'pll_get_post' ) ) {
			return null;
		}

		$translated = \pll_get_post( $post_id, $language );

		return is_int( $translated ) && $translated > 0 ? $translated : null;
	}

	public function get_default_language(): string {
		if ( ! function_exists( 'pll_default_language' ) ) {
			return '';
		}

		$default = \pll_default_language();

		return is_string( $default ) ? $default : '';
	}

	public function get_canonical_post_id( int $post_id ): int {
		$default = $this->get_default_language();
		if ( $default === '' ) {
			return $post_id;
		}

		// pll_get_post( $default_lang_post, $default ) returns the post itself,
		// so a default-language product maps to its own id (idempotent).
		$canonical = $this->get_translated_post_id( $post_id, $default );

		return ( $canonical !== null ) ? $canonical : $post_id;
	}

	public function get_translated_permalink( int $post_id, string $language ): ?string {
		$translated_id = $this->get_translated_post_id( $post_id, $language );
		if ( $translated_id === null ) {
			return null;
		}

		$permalink = get_permalink( $translated_id );

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

			$permalink    = get_permalink( $translated_id );
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
