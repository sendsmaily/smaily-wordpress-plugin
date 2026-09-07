<?php
/**
 * AutomationMarker tests (PRO-1681) — the field names are permanent.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\AutomationMarker;

final class AutomationMarkerTest extends TestCase {

	/**
	 * These names are merchant-visible segment/template identifiers: once a
	 * merchant has built a Smaily segment on one, renaming it breaks the
	 * segment silently. This case exists to make a rename a failing test.
	 */
	public function test_each_trigger_keeps_its_permanent_field_name(): void {
		self::assertSame( 'welcome_automation_at', AutomationMarker::field( 'welcome' ) );
		self::assertSame( 'first_order_automation_at', AutomationMarker::field( 'first_order' ) );
		self::assertSame( 'abandoned_cart_automation_at', AutomationMarker::field( 'abandoned_cart' ) );
	}

	/**
	 * Both markers are a wire commitment: the merchant's workflow exits the
	 * reminder series on `abandoned_cart_purchased_at` compared against
	 * `abandoned_cart_automation_at` (PRO-1723), so the two names and the one
	 * shared format have to hold.
	 *
	 * @dataProvider markers
	 *
	 * @param callable(): array<string, string> $make_stamp
	 */
	public function test_a_marker_is_its_field_stamped_as_a_utc_datetime( callable $make_stamp, string $field ): void {
		$before = gmdate( 'Y-m-d H:i:s' );
		$stamp  = $make_stamp();
		$after  = gmdate( 'Y-m-d H:i:s' );

		self::assertSame( array( $field ), array_keys( $stamp ) );
		$value = $stamp[ $field ];
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value );
		// UTC, not site time: the value must sit inside the UTC window the
		// call was made in.
		self::assertGreaterThanOrEqual( $before, $value );
		self::assertLessThanOrEqual( $after, $value );
	}

	/**
	 * @return array<string, array{0: callable(): array<string, string>, 1: string}>
	 */
	public static function markers(): array {
		return array(
			'a trigger marker' => array(
				static fn (): array => AutomationMarker::stamp( 'welcome' ),
				'welcome_automation_at',
			),
			'the purchase marker' => array(
				static fn (): array => AutomationMarker::purchase_stamp(),
				'abandoned_cart_purchased_at',
			),
		);
	}

	public function test_a_trigger_with_no_marker_stamps_nothing(): void {
		// Omit, never empty: an unmarked trigger sends no key at all, so
		// Smaily leaves whatever it already holds intact (F3-47 rule 2).
		self::assertSame( array(), AutomationMarker::stamp( 'order_confirmation' ) );
		self::assertSame( '', AutomationMarker::field( 'order_confirmation' ) );
	}
}
