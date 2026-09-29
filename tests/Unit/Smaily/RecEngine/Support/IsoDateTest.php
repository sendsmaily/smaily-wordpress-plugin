<?php
/**
 * IsoDate tests — the rec-engine wire datetime format (ISO 8601 UTC, `Z`
 * suffix), the single-source formatter all PayloadBuilders route through.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Support;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

final class IsoDateTest extends TestCase {

	public function test_formats_timestamp_as_iso8601_utc_with_z_suffix(): void {
		$timestamp = (int) strtotime( '2026-05-19 10:15:23 UTC' );

		self::assertSame( '2026-05-19T10:15:23Z', IsoDate::to_z( $timestamp ) );
	}

	public function test_uses_z_not_numeric_offset(): void {
		// The whole point: the engine's strict Zod .datetime() rejects `+00:00`.
		$out = IsoDate::to_z( (int) strtotime( '2026-01-01 00:00:00 UTC' ) );

		self::assertStringEndsWith( 'Z', $out );
		self::assertStringNotContainsString( '+00:00', $out );
		self::assertSame( '2026-01-01T00:00:00Z', $out );
	}

	public function test_emits_utc_regardless_of_offset_in_source_instant(): void {
		// A timestamp for 12:00 in a +02:00 zone is 10:00 UTC.
		$timestamp = (int) strtotime( '2026-06-01 12:00:00 +02:00' );

		self::assertSame( '2026-06-01T10:00:00Z', IsoDate::to_z( $timestamp ) );
	}

	public function test_parse_z_is_the_inverse_of_to_z(): void {
		$timestamp = (int) strtotime( '2026-05-19 10:15:23 UTC' );

		self::assertSame( $timestamp, IsoDate::parse_z( IsoDate::to_z( $timestamp ) ) );
	}

	/**
	 * PRO-3434: only the exact form `to_z()` writes parses.
	 *
	 * @dataProvider not_z_form
	 */
	public function test_parse_z_rejects_anything_else( string $value ): void {
		self::assertNull( IsoDate::parse_z( $value ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_z_form(): array {
		return array(
			'empty'           => array( '' ),
			'relative word'   => array( 'tomorrow' ),
			'garbage'         => array( 'not-a-date' ),
			'numeric offset'  => array( '2026-05-19T10:15:23+00:00' ),
			'fraction'        => array( '2026-05-19T10:15:23.000Z' ),
			'date only'       => array( '2026-05-19' ),
			'rolled-over day' => array( '2026-02-30T10:15:23Z' ),
			'trailing data'   => array( '2026-05-19T10:15:23Z ' ),
		);
	}
}
