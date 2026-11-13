<?php
/**
 * TranslatePressAdapter tests — the visitor's current language (CON-203).
 *
 * TranslatePress keeps the language of the request in its global
 * `$TRP_LANGUAGE`; before it sets it, the adapter answers with the store's
 * default language.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Multilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\TranslatePressAdapter;

final class TranslatePressAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['TRP_LANGUAGE'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_current_language_is_the_language_translatepress_set_for_the_request(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default-language' => 'et' ) );
		$GLOBALS['TRP_LANGUAGE'] = 'en_GB';

		self::assertSame( 'en_GB', ( new TranslatePressAdapter() )->get_current_language() );
	}

	public function test_current_language_is_the_default_before_translatepress_sets_it(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default-language' => 'et' ) );

		self::assertSame( 'et', ( new TranslatePressAdapter() )->get_current_language() );
	}

	public function test_an_empty_request_language_falls_back_to_the_default(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default-language' => 'et' ) );
		$GLOBALS['TRP_LANGUAGE'] = '';

		self::assertSame( 'et', ( new TranslatePressAdapter() )->get_current_language() );
	}

	public function test_without_settings_the_fallback_is_the_site_locale(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		self::assertSame( 'en_US', ( new TranslatePressAdapter() )->get_current_language() );
	}
}
