<?php
/**
 * PolylangAdapter tests — focused on canonical-post collapse (catalog P1).
 *
 * Polylang stores each translation as its own wp_posts row, so the catalog
 * enumeration must map every translation back to the default-language
 * (canonical) post before keying it. These tests drive that logic through
 * Brain\Monkey stubs of the pll_* free functions; the broader detection
 * chain is covered by DetectorFactoryTest + the integration suite.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Multilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\PolylangAdapter;

final class PolylangAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_language_returns_pll_default_language(): void {
		Functions\when( 'pll_default_language' )->justReturn( 'et' );

		self::assertSame( 'et', ( new PolylangAdapter() )->get_default_language() );
	}

	public function test_default_language_is_empty_when_pll_returns_non_string(): void {
		// Defensive is_string() guard — Polylang booting mid-request can hand
		// back null/false before its languages are registered.
		Functions\when( 'pll_default_language' )->justReturn( null );

		self::assertSame( '', ( new PolylangAdapter() )->get_default_language() );
	}

	public function test_canonical_collapses_a_translation_to_the_default_language_post(): void {
		// The Latvian translation (woo-59221) must collapse to the Estonian
		// canonical (woo-59199) — the real MiuMjau shampoo case from the brief.
		Functions\when( 'pll_default_language' )->justReturn( 'et' );
		Functions\when( 'pll_get_post' )->alias(
			static function ( int $post_id, string $lang ): int {
				return ( $post_id === 59221 && $lang === 'et' ) ? 59199 : 0;
			}
		);

		self::assertSame( 59199, ( new PolylangAdapter() )->get_canonical_post_id( 59221 ) );
	}

	public function test_canonical_of_a_default_language_post_is_itself(): void {
		Functions\when( 'pll_default_language' )->justReturn( 'et' );
		// Polylang returns the post itself when asked for its own default lang.
		Functions\when( 'pll_get_post' )->justReturn( 59199 );

		self::assertSame( 59199, ( new PolylangAdapter() )->get_canonical_post_id( 59199 ) );
	}

	public function test_canonical_falls_back_to_input_when_untranslated(): void {
		Functions\when( 'pll_default_language' )->justReturn( 'et' );
		// pll_get_post returns 0/false for a language-less or untranslated post.
		Functions\when( 'pll_get_post' )->justReturn( 0 );

		self::assertSame( 4242, ( new PolylangAdapter() )->get_canonical_post_id( 4242 ) );
	}

	public function test_canonical_falls_back_to_input_when_no_default_language(): void {
		// Empty default (Polylang mid-boot / misconfigured) → never drop the
		// product, ingest it as itself; pll_get_post must not even be consulted.
		Functions\when( 'pll_default_language' )->justReturn( '' );

		self::assertSame( 4242, ( new PolylangAdapter() )->get_canonical_post_id( 4242 ) );
	}
}
