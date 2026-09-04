<?php
/**
 * Tests for the legacy Helper autoresponder-list filtering (PRO-1277).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Helper;

require_once dirname( __DIR__, 2 ) . '/includes/smaily-helper.class.php';

/**
 * The CF7 / Elementor / Gutenberg-block "Autoresponder" dropdowns are all
 * shaped by Helper::filter_enabled_autoresponders() from the raw
 * `workflows.php?trigger_type=form_submitted` response. A workflow disabled
 * in Smaily accepts an enroll but silently sends nothing — the dropdown must
 * not offer it, and a previously saved binding that's now disabled/gone must
 * not be dropped without a visible flag (Helper::is_autoresponder_unavailable).
 */
final class LegacyHelperAutorespondersTest extends TestCase {

	public function test_missing_is_enabled_key_is_kept(): void {
		// We don't know the workflow's state without the key, so we don't
		// drop a row we can't classify.
		$out = Helper::filter_enabled_autoresponders(
			array(
				array(
					'id'    => 3,
					'title' => 'No status field',
				),
			)
		);

		self::assertSame( array( 3 => 'No status field' ), $out );
	}

	/**
	 * @dataProvider provide_is_enabled_wire_shapes
	 */
	public function test_is_enabled_handles_bool_int_and_string_wire_shapes( $raw_value, bool $should_be_kept ): void {
		$out = Helper::filter_enabled_autoresponders(
			array(
				array(
					'id'         => 1,
					'title'      => 'Row',
					'is_enabled' => $raw_value,
				),
			)
		);

		self::assertSame( $should_be_kept, array_key_exists( 1, $out ) );
	}

	public function provide_is_enabled_wire_shapes(): array {
		return array(
			'bool true'        => array( true, true ),
			'bool false'       => array( false, false ),
			'int 1'            => array( 1, true ),
			'int 0'            => array( 0, false ),
			'string "1"'       => array( '1', true ),
			'string "0"'       => array( '0', false ),
			'string "true"'    => array( 'true', true ),
			'string "false"'   => array( 'false', false ),
			'string "FALSE"'   => array( 'FALSE', false ),
			'string empty'     => array( '', false ),
		);
	}

	public function test_junk_and_incomplete_rows_are_dropped(): void {
		$out = Helper::filter_enabled_autoresponders(
			array(
				'not an array',
				array( 'id' => 5 ),                 // no title
				array( 'title' => 'No id' ),         // no id
				array( 'id' => 0, 'title' => 'Zero id' ),
				array( 'id' => 6, 'title' => '' ),   // empty title
				array( 'id' => 8, 'title' => 'Kept', 'is_enabled' => true ),
			)
		);

		self::assertSame( array( 8 => 'Kept' ), $out );
	}

	/**
	 * @dataProvider provide_saved_bindings
	 */
	public function test_is_autoresponder_unavailable( int $saved_id, array $enabled_autoresponders, bool $expected ): void {
		self::assertSame( $expected, Helper::is_autoresponder_unavailable( $saved_id, $enabled_autoresponders ) );
	}

	public function provide_saved_bindings(): array {
		return array(
			'still enabled'                  => array( 7, array( 7 => 'Welcome' ), false ),
			// Filtered out of the enabled list by filter_enabled_autoresponders()
			// (disabled in Smaily), but the saved id is still 9.
			'now disabled'                    => array( 9, array( 7 => 'Welcome' ), true ),
			'no longer present at all'        => array( 42, array(), true ),
			'no saved binding'                => array( 0, array(), false ),
		);
	}
}
