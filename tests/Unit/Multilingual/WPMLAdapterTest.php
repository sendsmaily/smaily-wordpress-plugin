<?php
/**
 * WPMLAdapter tests — focused on canonical-post collapse (catalog P1).
 *
 * MiuMjau (the pilot) runs WPML + WooCommerce Multilingual, so this is the
 * pilot-relevant detector. WPML exposes its API through filters
 * (wpml_default_language, wpml_object_id), stubbed here via Brain\Monkey;
 * WCML registers product / product_variation as translatable, so the same
 * wpml_object_id resolution collapses both parents and variations.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Multilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\WPMLAdapter;

final class WPMLAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_language_returns_wpml_default_language_filter(): void {
		$this->stub_wpml( 'et', array() );

		self::assertSame( 'et', ( new WPMLAdapter() )->get_default_language() );
	}

	public function test_default_language_is_empty_when_filter_returns_non_string(): void {
		$this->stub_wpml( null, array() );

		self::assertSame( '', ( new WPMLAdapter() )->get_default_language() );
	}

	public function test_canonical_collapses_a_translation_to_the_default_language_post(): void {
		// WCML links the LV translation (59221) to the ET canonical (59199).
		$this->stub_wpml( 'et', array( 59221 => 59199 ) );

		self::assertSame( 59199, ( new WPMLAdapter() )->get_canonical_post_id( 59221 ) );
	}

	public function test_canonical_of_a_default_language_post_is_itself(): void {
		$this->stub_wpml( 'et', array( 59199 => 59199 ) );

		self::assertSame( 59199, ( new WPMLAdapter() )->get_canonical_post_id( 59199 ) );
	}

	public function test_canonical_falls_back_to_input_when_untranslated(): void {
		// wpml_object_id with return_original=false yields null for a post with
		// no default-language translation → never drop, key on itself.
		$this->stub_wpml( 'et', array() );

		self::assertSame( 4242, ( new WPMLAdapter() )->get_canonical_post_id( 4242 ) );
	}

	public function test_canonical_falls_back_to_input_when_no_default_language(): void {
		$this->stub_wpml( null, array() );

		self::assertSame( 4242, ( new WPMLAdapter() )->get_canonical_post_id( 4242 ) );
	}

	/**
	 * Stub WPML's two filters: wpml_default_language → $default, and
	 * wpml_object_id → the mapped canonical id (null when unmapped, mirroring
	 * WPML's return_original_if_missing=false behaviour).
	 *
	 * @param string|null     $default wpml_default_language return value.
	 * @param array<int, int> $map     post id → default-language post id.
	 */
	private function stub_wpml( ?string $default, array $map ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value = null, ...$args ) use ( $default, $map ) {
				if ( $tag === 'wpml_default_language' ) {
					return $default;
				}
				if ( $tag === 'wpml_object_id' ) {
					return $map[ $value ] ?? null;
				}
				return $value;
			}
		);
	}
}
