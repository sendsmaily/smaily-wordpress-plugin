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

	public function test_the_stamp_is_a_utc_datetime_in_the_contact_wire_shape(): void {
		$before = gmdate( 'Y-m-d H:i:s' );
		$stamp  = AutomationMarker::stamp( 'welcome' );
		$after  = gmdate( 'Y-m-d H:i:s' );

		self::assertSame( array( 'welcome_automation_at' ), array_keys( $stamp ) );
		$value = $stamp['welcome_automation_at'];
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value );
		// UTC, not site time: the value must sit inside the UTC window the
		// call was made in.
		self::assertGreaterThanOrEqual( $before, $value );
		self::assertLessThanOrEqual( $after, $value );
	}

	public function test_a_trigger_with_no_marker_stamps_nothing(): void {
		// Omit, never empty: an unmarked trigger sends no key at all, so
		// Smaily leaves whatever it already holds intact (F3-47 rule 2).
		self::assertSame( array(), AutomationMarker::stamp( 'order_confirmation' ) );
		self::assertSame( '', AutomationMarker::field( 'order_confirmation' ) );
	}
}
