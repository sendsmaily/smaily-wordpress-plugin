<?php
/**
 * AttributionShape tests — the visitor-token length rule (contract Base
 * context: a token is an opaque string of up to 64 characters in all).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Support;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Support\AttributionShape;

final class AttributionShapeTest extends TestCase {

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function engine_tokens(): array {
		return array(
			'shortest: vt_ + 1'       => array( 'vt_a', true ),
			'engine-issued: vt_ + 10' => array( 'vt_8f3k2abz01', true ),
			'64 characters in all'    => array( 'vt_' . str_repeat( 'b', 61 ), true ),
			'65 characters in all'    => array( 'vt_' . str_repeat( 'b', 62 ), false ),
			'67 characters in all'    => array( 'vt_' . str_repeat( 'b', 64 ), false ),
			'prefix only'             => array( 'vt_', false ),
		);
	}

	/**
	 * @dataProvider engine_tokens
	 */
	public function test_an_engine_token_is_at_most_64_characters_in_all( string $token, bool $valid ): void {
		self::assertSame( $valid, AttributionShape::is_engine_visitor_token( $token ) );
		self::assertSame( $valid, AttributionShape::is_visitor_token( $token ) );
	}
}
