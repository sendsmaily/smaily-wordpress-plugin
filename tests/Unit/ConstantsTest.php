<?php
/**
 * Smoke test for Smaily\Connect\Constants — verifies the test infrastructure
 * (autoload, Brain\Monkey filter mocking) is wired up correctly before any
 * substantive class is added.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Constants;

final class ConstantsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_slug_matches_expected_value(): void {
		self::assertSame( 'smaily-connect', Constants::SLUG );
		self::assertSame( 'smaily-connect', Constants::TEXT_DOMAIN );
	}

	public function test_rest_namespace_uses_v1(): void {
		self::assertSame( 'smaily-connect/v1', Constants::REST_NAMESPACE );
	}

	public function test_setup_url_returns_default_when_filter_passes_through(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		self::assertSame(
			'https://intelligence.smaily.com/setup/exchange',
			Constants::setup_url()
		);
	}

	public function test_setup_url_can_be_overridden_by_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, string $value ): string {
				return $hook === 'smaily_connect_setup_url'
					? 'https://override.example/setup/exchange'
					: $value;
			}
		);

		self::assertSame(
			'https://override.example/setup/exchange',
			Constants::setup_url()
		);
	}

	public function test_setup_url_falls_back_to_default_when_filter_returns_empty_string(): void {
		Functions\when( 'apply_filters' )->justReturn( '' );

		self::assertSame(
			Constants::SETUP_BASE_URL,
			Constants::setup_url(),
			'An empty filter return value must not blank the setup URL.'
		);
	}

	public function test_version_helper_reflects_define(): void {
		self::assertSame( '3.11.2', Constants::version() );
	}
}
