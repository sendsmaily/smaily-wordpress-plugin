<?php
/**
 * Tests for the single-language fallback adapter.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Multilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;

final class SiteLocaleAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_detected_languages_returns_site_locale_in_a_single_element_list(): void {
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );

		self::assertSame( array( 'et_EE' ), ( new SiteLocaleAdapter() )->get_detected_languages() );
	}

	public function test_current_language_equals_site_locale(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		self::assertSame( 'en_US', ( new SiteLocaleAdapter() )->get_current_language() );
	}

	public function test_translated_post_id_returns_the_input_unchanged(): void {
		self::assertSame( 42, ( new SiteLocaleAdapter() )->get_translated_post_id( 42, 'et_EE' ) );
	}

	public function test_default_language_equals_site_locale(): void {
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );

		self::assertSame( 'et_EE', ( new SiteLocaleAdapter() )->get_default_language() );
	}

	public function test_canonical_post_id_returns_the_input_unchanged(): void {
		self::assertSame( 42, ( new SiteLocaleAdapter() )->get_canonical_post_id( 42 ) );
	}

	public function test_get_translations_returns_scalar_values_not_lang_keyed_arrays(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/product/42' );
		Functions\when( 'get_the_title' )->justReturn( 'Sample Product' );
		Functions\when( 'get_post_field' )->justReturn( 'Sample excerpt.' );

		$result = ( new SiteLocaleAdapter() )->get_translations( 42 );

		self::assertSame( 'Sample Product', $result['name'] );
		self::assertSame( 'Sample excerpt.', $result['description'] );
		self::assertSame( 'https://example.test/product/42', $result['product_url'] );
	}
}
