<?php
/**
 * Unit: AddressMatch — the one address match of the personal-data exporter
 * and eraser (PRO-3909). The stores that use it keep their own tests; this
 * pins the SQL and the patterns themselves.
 *
 * @package Smaily\Connect\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\AddressMatch;

final class AddressMatchTest extends TestCase {

	/** @var mixed */
	private $saved_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new class() {
			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_column_matches_in_any_letter_case_but_compares_bytes(): void {
		self::assertSame(
			'CAST( LOWER( m.meta_value ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY )',
			AddressMatch::column_equals( 'm.meta_value' )
		);
	}

	public function test_the_indexed_match_puts_a_plain_comparison_before_the_exact_one(): void {
		// PRO-4004: the plain `email = %s` lets idx_email narrow the rows;
		// the exact match still decides.
		self::assertSame(
			array(
				'( email = %s AND CAST( LOWER( email ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY ) )',
				array( 'Jane@Example.com', 'Jane@Example.com' ),
			),
			AddressMatch::column_equals_indexed( 'email', 'Jane@Example.com' )
		);
	}

	public function test_the_indexed_match_of_an_empty_address_matches_nothing(): void {
		self::assertNull( AddressMatch::column_equals_indexed( 'email', '' ) );
		self::assertNull( AddressMatch::column_equals_indexed( 'email', '  ' ) );
	}

	public function test_a_json_string_match_takes_the_address_after_each_prefix(): void {
		$match = AddressMatch::json_string( 'LOWER( payload )', '  Jane@Example.com ', array( '"email":', '"to":' ) );

		self::assertSame(
			array(
				'( LOWER( payload ) LIKE CAST( %s AS BINARY ) OR LOWER( payload ) LIKE CAST( %s AS BINARY ) )',
				array( '%"email":"jane@example.com"%', '%"to":"jane@example.com"%' ),
			),
			$match,
			'Trimmed and lowercased; one pattern per prefix when both forms are the same.'
		);
	}

	public function test_a_non_ascii_address_is_matched_as_typed_and_as_stored_escaped(): void {
		$match = AddressMatch::json_string( 'sent_payload', 'Jäne@example.com' );

		self::assertNotNull( $match );
		self::assertSame( '( sent_payload LIKE CAST( %s AS BINARY ) OR sent_payload LIKE CAST( %s AS BINARY ) )', $match[0] );
		self::assertSame( array( '%"jäne@example.com"%', '%"j\\\\u00e4ne@example.com"%' ), $match[1] );
	}

	public function test_like_wildcards_in_the_address_are_escaped(): void {
		$match = AddressMatch::json_string( 'sent_payload', 'a_b%c@example.com' );

		self::assertNotNull( $match );
		self::assertSame( array( '%"a\\_b\\%c@example.com"%' ), $match[1] );
	}

	public function test_an_empty_address_matches_nothing(): void {
		self::assertNull( AddressMatch::json_string( 'sent_payload', '  ' ) );
	}
}
