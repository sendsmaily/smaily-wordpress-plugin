<?php
/**
 * Single-language fallback adapter (WPML / Polylang / TranslatePress absent).
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Implements DetectorInterface for sites where no multilingual plugin is
 * active. Reads the single site locale via get_locale() and treats every
 * post as untranslated.
 *
 * Always returns a "translations" payload with scalar string values
 * (not language-keyed arrays) — Phase 3's catalog payload encoder
 * branches on this to stay backward-compatible with rec-engine ingestion
 * for the common case of single-language WC stores.
 */
final class SiteLocaleAdapter implements DetectorInterface {

	public function get_detected_languages(): array {
		return array( $this->locale() );
	}

	public function get_current_language(): string {
		return $this->locale();
	}

	public function get_translated_post_id( int $post_id, string $language ): ?int {
		return $post_id;
	}

	public function get_default_language(): string {
		return $this->locale();
	}

	public function get_canonical_post_id( int $post_id ): int {
		// Single-language site — every post is its own canonical record.
		return $post_id;
	}

	public function get_translated_permalink( int $post_id, string $language ): ?string {
		$permalink = get_permalink( $post_id );

		return is_string( $permalink ) ? $permalink : null;
	}

	public function get_translations( int $post_id ): array {
		$permalink = $this->get_translated_permalink( $post_id, $this->locale() );

		return array(
			'name'        => (string) get_the_title( $post_id ),
			'description' => (string) get_post_field( 'post_excerpt', $post_id ),
			'product_url' => $permalink ?? '',
		);
	}

	private function locale(): string {
		return function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
	}
}
