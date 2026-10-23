<?php
/**
 * AutomationDiscount — the settings shape and a code's last valid day (CON-199).
 *
 * The coupon itself is made by WooCommerce, so creating, retrying and
 * cleaning it up is pinned by the integration suite against real coupons
 * (AutomationDiscountCodeTest). This covers what needs no WooCommerce.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;

final class AutomationDiscountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_contact_field_names_match_the_other_smaily_plugins(): void {
		self::assertSame(
			array(
				'welcome'        => array( 'welcome_coupon_code', 'welcome_coupon_expires_at' ),
				'first_order'    => array( 'first_order_coupon_code', 'first_order_coupon_expires_at' ),
				'abandoned_cart' => array( 'abandoned_cart_coupon_code', 'abandoned_cart_coupon_expires_at' ),
			),
			AutomationDiscount::FIELDS
		);
	}

	public function test_every_automation_is_off_with_seven_days_until_saved(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$off = array(
			'enabled'     => false,
			'template_id' => 0,
			'valid_days'  => 7,
		);
		self::assertSame(
			array(
				'welcome'        => $off,
				'first_order'    => $off,
				'abandoned_cart' => $off,
			),
			AutomationDiscount::settings()
		);
	}

	public function test_a_damaged_stored_value_reads_as_off_or_in_range(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'welcome' => array(
					'enabled'     => '1',
					'template_id' => '12',
					'valid_days'  => 9000,
				),
				'first_order' => 'garbage',
			)
		);

		$settings = AutomationDiscount::settings();

		self::assertSame(
			array(
				'enabled'     => true,
				'template_id' => 12,
				'valid_days'  => 365,
			),
			$settings['welcome']
		);
		self::assertFalse( $settings['first_order']['enabled'] );
		self::assertSame( 7, $settings['first_order']['valid_days'] );
	}

	public function test_a_codes_last_day_is_that_many_days_after_today_in_the_store_timezone(): void {
		$tallinn = new \DateTimeZone( 'Europe/Tallinn' );
		// 22:30 UTC on 23 October is already 24 October in Tallinn.
		$now = ( new \DateTimeImmutable( '2026-10-23 22:30:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		[ $expires, $last_day ] = AutomationDiscount::expiry( $now, 7, $tallinn, null );

		self::assertSame( '2026-10-31', $last_day );
		self::assertSame(
			( new \DateTimeImmutable( '2026-10-31 23:59:59', $tallinn ) )->getTimestamp(),
			$expires,
			'The code works until the end of its last day.'
		);
	}

	public function test_a_code_never_outlives_its_template(): void {
		$tallinn = new \DateTimeZone( 'Europe/Tallinn' );
		$now     = ( new \DateTimeImmutable( '2026-10-23 12:00:00', $tallinn ) )->getTimestamp();
		// WooCommerce's own expiry date: the coupon expires at 00:00 of that day.
		$template = ( new \DateTimeImmutable( '2026-10-27 00:00:00', $tallinn ) )->getTimestamp();

		[ $expires, $last_day ] = AutomationDiscount::expiry( $now, 30, $tallinn, $template );

		self::assertSame( $template, $expires );
		self::assertSame( '2026-10-26', $last_day, 'The template works through the day before its expiry date.' );
	}

	public function test_a_template_ending_later_leaves_the_codes_own_last_day(): void {
		$utc      = new \DateTimeZone( 'UTC' );
		$now      = ( new \DateTimeImmutable( '2026-10-23 12:00:00', $utc ) )->getTimestamp();
		$template = ( new \DateTimeImmutable( '2027-01-01 00:00:00', $utc ) )->getTimestamp();

		[ $expires, $last_day ] = AutomationDiscount::expiry( $now, 1, $utc, $template );

		self::assertSame( '2026-10-24', $last_day );
		self::assertSame( ( new \DateTimeImmutable( '2026-10-24 23:59:59', $utc ) )->getTimestamp(), $expires );
	}

	public function test_an_erasure_takes_out_only_the_requesters_address_in_any_letter_case(): void {
		// CON-213: a used code keeps every other allowed email.
		self::assertSame(
			array( 'jäne@example.test', 'xjane@example.test', '*@example.test', 'john@example.test' ),
			AutomationDiscount::restrictions_without(
				array( 'jäne@example.test', 'Jane@Example.test', 'xjane@example.test', ' jane@example.test ', '*@example.test', 'john@example.test' ),
				'JANE@example.TEST'
			),
			'An accent, a longer address and a wildcard are someone else.'
		);
		self::assertSame( array(), AutomationDiscount::restrictions_without( array( 'jane@example.test', 7 ), 'jane@example.test' ) );
	}
}
