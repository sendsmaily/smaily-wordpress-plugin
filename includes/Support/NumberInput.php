<?php
/**
 * Whole-number settings values.
 *
 * @package Smaily\Connect\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule for a whole-number setting (a day count, say) that arrives
 * from a request or a stored option: an int, or a string of digits.
 */
final class NumberInput {

	/**
	 * A whole number from $min to $max, or null — for a float, a sign, a
	 * fraction, an empty string or anything outside the range.
	 *
	 * @param mixed $value
	 */
	public static function whole_number_in_range( $value, int $min, int $max ): ?int {
		if ( is_int( $value ) ) {
			$number = $value;
		} elseif ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) === 1 ) {
			$number = (int) trim( $value );
		} else {
			return null;
		}

		return $number >= $min && $number <= $max ? $number : null;
	}
}
