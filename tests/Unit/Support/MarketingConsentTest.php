<?php
/**
 * Tests for the marketing-consent rule (PRO-3845, parity with Magento PRO-3664).
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
		parent::tearDown();
	}

	public function test_a_consent_type_and_a_yes_is_consent(): void {
		self::assertTrue( MarketingConsent::decide( 'optin', true ) );
		self::assertTrue( MarketingConsent::decide( 'optout', true ) );
	}

	public function test_no_consent_type_is_no_consent_even_when_wp_has_consent_is_true(): void {
		self::assertFalse( MarketingConsent::decide( '', true ) );
	}

	public function test_a_consent_type_without_a_yes_is_no_consent(): void {
		self::assertFalse( MarketingConsent::decide( 'optin', false ) );
	}

	/**
	 * @dataProvider unreadable_values
	 *
	 * @param mixed $consent_type
	 * @param mixed $has_consent
	 */
	public function test_an_unreadable_value_is_no_consent( $consent_type, $has_consent ): void {
		self::assertFalse( MarketingConsent::decide( $consent_type, $has_consent ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function unreadable_values(): array {
		return array(
			'null type'        => array( null, true ),
			'boolean type'     => array( true, true ),
			'truthy string'    => array( 'optin', '1' ),
			'integer one'      => array( 'optin', 1 ),
			'null consent'     => array( 'optin', null ),
		);
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
