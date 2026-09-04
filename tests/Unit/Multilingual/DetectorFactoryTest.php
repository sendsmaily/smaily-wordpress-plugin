<?php
/**
 * DetectorFactory tests — verify the detection chain picks adapters in the
 * documented priority order (WPML → Polylang → TranslatePress → SiteLocale).
 *
 * Detection signals (`defined('ICL_SITEPRESS_VERSION')` for WPML,
 * `function_exists('pll_languages_list')` for Polylang,
 * `function_exists('trp_get_url_for_language')` for TranslatePress) can't
 * be controlled at runtime in a vanilla PHP process. The factory caches
 * decisions via a static, which compounds the difficulty.
 *
 * Approach: drive the factory through the SiteLocale fallback branch
 * (no detection signals available in a unit-test environment) and verify
 * the cache + reset behaviour. The plugin-specific branches are covered
 * by the integration suite, which has real WPML / Polylang stubs.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Multilingual;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;

final class DetectorFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DetectorFactory::reset();
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_site_locale_adapter_when_no_multilingual_plugin_is_active(): void {
		$detector = DetectorFactory::create();

		self::assertInstanceOf( SiteLocaleAdapter::class, $detector );
		self::assertInstanceOf( DetectorInterface::class, $detector );
	}

	public function test_subsequent_calls_return_the_same_instance(): void {
		$first  = DetectorFactory::create();
		$second = DetectorFactory::create();

		self::assertSame(
			$first,
			$second,
			'Cached factory must return the same DetectorInterface on subsequent calls.'
		);
	}

	public function test_reset_clears_the_cache_so_a_fresh_decision_is_made(): void {
		$first = DetectorFactory::create();
		DetectorFactory::reset();
		$second = DetectorFactory::create();

		self::assertNotSame(
			$first,
			$second,
			'reset() must drop the cached detector so create() returns a new instance.'
		);
		self::assertInstanceOf( SiteLocaleAdapter::class, $second );
	}
}
