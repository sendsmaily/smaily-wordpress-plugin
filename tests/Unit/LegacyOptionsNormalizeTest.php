<?php
/**
 * Tests for the legacy Options abandoned-cart status normalizer (F3-54).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Options;

require_once dirname( __DIR__, 2 ) . '/includes/smaily-options.class.php';

/**
 * F3-54 (Prike, 2026-07-08): the abandoned-cart status option exists in TWO
 * shapes in the wild — the legacy array {enabled, autoresponder_id} and the
 * bare boolean the pre-3.4.3 Settings wrote (stored by WordPress as the
 * string '1'/''). A raw `$status['enabled']` on the string shape is a PHP 8
 * TypeError ("Cannot access offset of type string on string") that fataled
 * the abandoned-cart tick every 15 minutes. The normalizer is the single
 * shape gate every consumer reads through.
 */
final class LegacyOptionsNormalizeTest extends TestCase {

	public function test_legacy_array_shape_passes_through(): void {
		$out = Options::normalize_abandoned_cart_status(
			array(
				'enabled'          => true,
				'autoresponder_id' => 77,
			)
		);

		self::assertSame( array( 'enabled' => true, 'autoresponder_id' => 77 ), $out );
	}

	public function test_disabled_array_shape_is_false_not_truthy_array(): void {
		$out = Options::normalize_abandoned_cart_status(
			array(
				'enabled'          => false,
				'autoresponder_id' => 77,
			)
		);

		self::assertFalse( $out['enabled'], 'A disabled array must normalize to enabled=false — the old (bool) cast on the whole array read it as TRUE.' );
		self::assertSame( 77, $out['autoresponder_id'] );
	}

	public function test_boolean_true_string_shape_the_prike_fatal(): void {
		// update_option( ..., true ) stores '1'.
		$out = Options::normalize_abandoned_cart_status( '1' );

		self::assertSame( array( 'enabled' => true, 'autoresponder_id' => 0 ), $out );
	}

	public function test_boolean_false_string_shape(): void {
		// update_option( ..., false ) stores ''.
		$out = Options::normalize_abandoned_cart_status( '' );

		self::assertSame( array( 'enabled' => false, 'autoresponder_id' => 0 ), $out );
	}

	public function test_partial_array_and_garbage_ids_normalize_safely(): void {
		self::assertSame(
			array( 'enabled' => true, 'autoresponder_id' => 0 ),
			Options::normalize_abandoned_cart_status( array( 'enabled' => 1 ) )
		);
		self::assertSame(
			array( 'enabled' => false, 'autoresponder_id' => 0 ),
			Options::normalize_abandoned_cart_status( array() )
		);
		self::assertSame(
			array( 'enabled' => true, 'autoresponder_id' => 0 ),
			Options::normalize_abandoned_cart_status( array( 'enabled' => true, 'autoresponder_id' => array( 'nested' => 'junk' ) ) ),
			'A non-scalar autoresponder_id must not survive the cast.'
		);
	}

	public function test_unexpected_scalars_gate_on_truthiness(): void {
		self::assertTrue( Options::normalize_abandoned_cart_status( 1 )['enabled'] );
		self::assertFalse( Options::normalize_abandoned_cart_status( 0 )['enabled'] );
		self::assertFalse( Options::normalize_abandoned_cart_status( null )['enabled'] );
	}
}
