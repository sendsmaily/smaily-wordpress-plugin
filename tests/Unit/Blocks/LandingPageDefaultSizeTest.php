<?php
/**
 * Tests that the PHP embed-size defaults still match the block editor's
 * (PRO-2440).
 *
 * The shortcode and the Elementor widget default to the size block.json
 * describes, but they can only hold a hand-copied constant — this pins the
 * copy to the original so the three surfaces cannot quietly drift apart.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Blocks\Landing_Page\Integration;

require_once dirname( __DIR__, 3 ) . '/blocks/landingpage/smaily-integration.class.php';

final class LandingPageDefaultSizeTest extends TestCase {

	public function test_the_php_defaults_match_the_block_editor_s(): void {
		$block = json_decode(
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/blocks/landingpage/src/block.json' ),
			true
		);

		self::assertSame( Integration::DEFAULT_HEIGHT, $block['attributes']['height']['default'] );
		self::assertSame( Integration::DEFAULT_WIDTH, $block['attributes']['width']['default'] );
	}
}
