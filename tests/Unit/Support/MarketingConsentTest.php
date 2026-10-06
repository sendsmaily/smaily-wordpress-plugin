<?php
/**
 * Tests for the marketing-consent rule (PRO-3845, PRO-3849; parity with Magento PRO-3664).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Support\MarketingConsent;

final class MarketingConsentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_COOKIE = array();
		parent::tearDown();
	}

	public function test_a_consent_cookie_allow_and_a_yes_is_consent(): void {
		self::assertTrue( MarketingConsent::decide( true, 'allow' ) );
	}

	public function test_no_consent_cookie_is_no_consent_even_when_wp_has_consent_is_true(): void {
		// No consent type set, or the opt-out-region default (PRO-3849).
		self::assertFalse( MarketingConsent::decide( true, null ) );
	}

	public function test_a_consent_cookie_deny_is_no_consent(): void {
		self::assertFalse( MarketingConsent::decide( true, 'deny' ) );
	}

	public function test_a_consent_cookie_allow_without_a_yes_is_no_consent(): void {
		self::assertFalse( MarketingConsent::decide( false, 'allow' ) );
	}

	/**
	 * @dataProvider unreadable_values
	 *
	 * @param mixed $has_consent
	 * @param mixed $stored_consent
	 */
	public function test_an_unreadable_value_is_no_consent( $has_consent, $stored_consent ): void {
		self::assertFalse( MarketingConsent::decide( $has_consent, $stored_consent ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function unreadable_values(): array {
		return array(
			'empty cookie'      => array( true, '' ),
			'upper-case allow'  => array( true, 'ALLOW' ),
			'boolean cookie'    => array( true, true ),
			'truthy string yes' => array( '1', 'allow' ),
			'integer one yes'   => array( 1, 'allow' ),
			'null yes'          => array( null, 'allow' ),
		);
	}

	public function test_the_stored_consent_is_read_from_the_wp_consent_api_cookie(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		$_COOKIE = array(
			'wp_consent_marketing'  => 'allow',
			'wp_consent_statistics' => 'deny',
		);

		self::assertSame( 'allow', MarketingConsent::stored_consent( 'marketing' ) );
		self::assertSame( 'deny', MarketingConsent::stored_consent( 'statistics' ) );
		self::assertNull( MarketingConsent::stored_consent( 'preferences' ) );
	}

	public function test_the_stored_consent_follows_the_wp_consent_api_cookie_prefix_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'wp_consent_cookie_prefix' === $hook ? 'acme' : $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		$_COOKIE = array(
			'wp_consent_marketing' => 'allow',
			'acme_marketing'       => 'deny',
		);

		self::assertSame( 'deny', MarketingConsent::stored_consent( 'marketing' ) );
	}

	public function test_a_consent_cookie_that_is_not_a_string_is_no_stored_consent(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_COOKIE = array( 'wp_consent_marketing' => array( 'allow' ) );

		self::assertNull( MarketingConsent::stored_consent( 'marketing' ) );
	}

	public function test_without_the_wp_consent_api_there_is_no_consent(): void {
		// The unit runtime never defines the WP Consent API functions
		// (EnvDetectorTest pins that), so this runs the real check.
		Functions\when( 'apply_filters' )->returnArg( 2 );

		self::assertFalse( MarketingConsent::given() );
	}

	public function test_the_category_defaults_to_marketing_and_is_filterable(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		self::assertSame( 'marketing', MarketingConsent::category() );

		Functions\when( 'apply_filters' )->justReturn( 'statistics' );
		self::assertSame( 'statistics', MarketingConsent::category() );
	}
}
