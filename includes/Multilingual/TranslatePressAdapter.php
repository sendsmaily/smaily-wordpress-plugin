<?php
/**
 * TranslatePress adapter — TP translates rendered HTML, not post records.
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Implements DetectorInterface against TranslatePress.
 *
 * TranslatePress is structurally different from WPML/Polylang: instead of
 * duplicating WP_Post rows per language, it translates the rendered HTML
 * output at request time and stores translations in its own
 * trp_dictionary table. Consequences:
 *
 *   - get_translated_post_id returns $post_id unchanged — there's only
 *     one underlying record.
 *   - get_translated_permalink uses trp_get_url_for_language to prefix /
 *     rewrite the URL into the requested language's URL slug.
 *   - get_translations falls back to the source-language title /
 *     excerpt; per-language strings come from TP's dictionary, which
 *     requires loading TP's TRP_Translation_Render class. Phase 1's
 *     surface is intentionally light here — the rec-engine catalog
 *     consumer (Phase 3) is the only caller that needs the per-language
 *     payload, and that's where the deeper TP integration belongs.
 *
 * Detection: function_exists('trp_get_url_for_language') confirms TP's
 * bootstrap has run.
 */
final class TranslatePressAdapter implements DetectorInterface {

	public function get_detected_languages(): array {
		$settings = get_option( 'trp_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['translation-languages'] ) ) {
			return array();
		}

		$languages = $settings['translation-languages'];

		return is_array( $languages ) ? array_values( array_map( 'strval', $languages ) ) : array();
	}

	public function get_current_language(): string {
		$settings = get_option( 'trp_settings', array() );

		if ( is_array( $settings ) && isset( $settings['default-language'] ) ) {
			return (string) $settings['default-language'];
		}

		return function_exists( 'get_locale' ) ? (string) get_locale() : '';
	}

	public function get_translated_post_id( int $post_id, string $language ): ?int {
		// TP doesn't duplicate posts — the same record serves every language.
		return $post_id;
	}

	public function get_default_language(): string {
		$settings = get_option( 'trp_settings', array() );

		if ( is_array( $settings ) && isset( $settings['default-language'] ) ) {
			return (string) $settings['default-language'];
		}

		return function_exists( 'get_locale' ) ? (string) get_locale() : '';
	}

	public function get_canonical_post_id( int $post_id ): int {
		// TP translates rendered HTML, not post records — there is exactly one
		// underlying record per product, so every post is already canonical.
		return $post_id;
	}

	public function get_translated_permalink( int $post_id, string $language ): ?string {
		$base = get_permalink( $post_id );
		if ( ! is_string( $base ) ) {
			return null;
		}

		if ( ! function_exists( 'trp_get_url_for_language' ) ) {
			return $base;
		}

		$translated = \trp_get_url_for_language( $base, $language );

		return is_string( $translated ) ? $translated : $base;
	}

	public function get_translations( int $post_id ): array {
		// Phase 1: return only the source-language values. Phase 3's catalog
		// encoder needs the full per-language payload; a TRP_Translation_Render
		// integration goes there once the rec-engine event flow lands.
		$languages = $this->get_detected_languages();

		$source_title       = (string) get_the_title( $post_id );
		$source_description = (string) get_post_field( 'post_excerpt', $post_id );

		$name        = array();
		$description = array();
		$url         = array();

		foreach ( $languages as $lang ) {
			$name[ $lang ] = $source_title;
			if ( $source_description !== '' ) {
				$description[ $lang ] = $source_description;
			}
			$permalink    = $this->get_translated_permalink( $post_id, $lang );
			$url[ $lang ] = $permalink ?? '';
		}

		return array(
			'name'        => $name,
			'description' => $description,
			'product_url' => $url,
		);
	}
}
