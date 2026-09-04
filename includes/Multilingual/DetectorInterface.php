<?php
/**
 * Multilingual detector contract.
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Abstracts the per-plugin language API (WPML / Polylang / TranslatePress /
 * single-language fallback) so the rest of the codebase can ask "what
 * languages does this site speak?" without branching on which plugin is
 * installed.
 *
 * Locale-code shape follows whatever the underlying plugin returns
 * (WPML and Polylang use 2-letter ISO 639-1 codes like "et", "en";
 * TranslatePress sometimes uses 5-letter locale strings like "en_GB").
 * Callers should treat them as opaque identifiers; comparison is
 * case-sensitive string equality.
 */
interface DetectorInterface {

	/**
	 * All languages configured on this site, including the default.
	 *
	 * Single-language sites return a one-element list with the WordPress
	 * site locale (e.g. `["en_US"]`).
	 *
	 * @return string[]
	 */
	public function get_detected_languages(): array;

	/**
	 * Locale code for the current request context.
	 *
	 * On a frontend page-view this matches what the i18n plugin reports;
	 * on background jobs (cron, Action Scheduler) it falls back to the
	 * site locale, since there's no request-time language in that
	 * context.
	 */
	public function get_current_language(): string;

	/**
	 * Returns the post ID that holds the translation of $post_id into
	 * $language, or null when no translation exists.
	 *
	 * For TranslatePress (which translates rendered HTML rather than
	 * duplicating posts) this returns $post_id unchanged.
	 */
	public function get_translated_post_id( int $post_id, string $language ): ?int;

	/**
	 * The site's default/source language code (e.g. Polylang's default
	 * language, WPML's default language). Empty when it can't be resolved
	 * or no multilingual plugin is active beyond the site locale.
	 *
	 * Used by the catalog enumeration (P1) to pick the canonical language
	 * for translation collapse — see get_canonical_post_id().
	 */
	public function get_default_language(): string;

	/**
	 * Collapse a possibly-translated product post to its CANONICAL post id —
	 * the single record the catalog should ingest, identical across every
	 * language so the `woo-{id}` key is stable (catalog-correctness
	 * P1; RECENGINE_API_CONTRACT.md §3 "one row per canonical product").
	 *
	 * For WPML/Polylang (translations are separate wp_posts rows) this is the
	 * default-language translation of $post_id; a default-language post maps to
	 * itself. For TranslatePress / single-language sites (one record per
	 * product) it returns $post_id unchanged. Falls back to $post_id whenever
	 * the canonical can't be resolved (no default language, untranslated /
	 * language-less post) — a degraded passthrough never DROPS a product.
	 */
	public function get_canonical_post_id( int $post_id ): int;

	/**
	 * Permalink that resolves to the $language version of $post_id.
	 *
	 * Single-language sites return get_permalink($post_id) unchanged.
	 * TranslatePress prepends/rewrites the URL via its language code.
	 */
	public function get_translated_permalink( int $post_id, string $language ): ?string;

	/**
	 * Mass-fetch the translatable fields (name, description, product_url)
	 * for $post_id keyed by language code.
	 *
	 * Used by Phase 3's catalog.upsert event payload (PLUGIN.md §9):
	 * single-language sites get string values; multilingual sites get
	 * `array<string, string>` keyed by locale.
	 *
	 * @return array{name: array<string, string>|string, description: array<string, string>|string, product_url: array<string, string>|string}
	 */
	public function get_translations( int $post_id ): array;
}
