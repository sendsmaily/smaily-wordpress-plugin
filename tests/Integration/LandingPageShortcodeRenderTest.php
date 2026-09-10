<?php
/**
 * Integration: the [smaily_landing_page] shortcode reaches the same embed as
 * the block, so a merchant on a page builder that discards blocks (Elementor
 * replaces the post content wholesale) still gets the landing page.
 * See docs/DECISIONS.md, PRO-2440.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

final class LandingPageShortcodeRenderTest extends TestCase {

	private const PK = '01a42617-cb5d-4f65-8ea6-a8f4d1b94ffa';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();

		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'demostore',
				'username'  => 'api-user',
				'password'  => '',
			)
		);
	}

	protected function tearDown(): void {
		wp_dequeue_style( 'smaily-landingpage-block-style' );
		// The credentials this test writes are left for EnvScrub::reset() to
		// clear, like every sibling — it owns that option key.
		parent::tearDown();
	}

	public function test_the_shortcode_renders_the_same_embed_as_the_block(): void {
		$rendered = do_shortcode( '[smaily_landing_page url="' . $this->landing_page_url() . '"]' );

		self::assertStringContainsString(
			'src="https://demostore.sendsmaily.net/landing-pages/' . self::PK . '/html/"',
			$rendered
		);
		self::assertStringContainsString( 'smaily-connect-landingpage-block-front-wrapper', $rendered );
		self::assertStringContainsString( 'height:450px;width:500px', $rendered );
	}

	public function test_the_shortcode_honours_height_and_width(): void {
		$rendered = do_shortcode(
			'[smaily_landing_page url="' . $this->landing_page_url() . '" height="600" width="100%"]'
		);

		self::assertStringContainsString( 'height:600px;width:100%', $rendered );
	}

	/**
	 * @dataProvider refused_addresses
	 */
	public function test_the_shortcode_refuses_an_address_that_is_not_this_account_s_landing_page( string $url ): void {
		$rendered = do_shortcode( '[smaily_landing_page url="' . $url . '"]' );

		self::assertStringNotContainsString( '<iframe', $rendered );
		self::assertStringNotContainsString( 'smaily-connect-landingpage-block-front-wrapper', $rendered );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function refused_addresses(): array {
		return array(
			'another host'           => array( 'https://evil.example.com/landing-pages/' . self::PK . '/html/' ),
			'another Smaily account' => array( 'https://otherstore.sendsmaily.net/landing-pages/' . self::PK . '/html/' ),
			'not a landing page'     => array( 'https://demostore.sendsmaily.net/api/opt-in/' ),
			'not https'              => array( 'http://demostore.sendsmaily.net/landing-pages/' . self::PK . '/html/' ),
			'not a landing page key' => array( 'https://demostore.sendsmaily.net/landing-pages/not-a-uuid/html/' ),
		);
	}

	public function test_the_shortcode_without_attributes_embeds_nothing_and_stays_quiet(): void {
		$raised = array();
		set_error_handler(
			function ( int $errno, string $errstr ) use ( &$raised ): bool {
				$raised[] = $errno . ': ' . $errstr;
				return true;
			}
		);

		try {
			$rendered = do_shortcode( '[smaily_landing_page]' );
		} finally {
			restore_error_handler();
		}

		self::assertStringNotContainsString( '<iframe', $rendered );
		self::assertSame( array(), $raised, 'A shortcode written without attributes must not raise anything.' );
	}

	public function test_the_shortcode_brings_the_block_stylesheet_with_it(): void {
		self::assertTrue(
			wp_style_is( 'smaily-landingpage-block-style', 'registered' ),
			'The block registration must register the stylesheet the shortcode reuses.'
		);
		self::assertFalse( wp_style_is( 'smaily-landingpage-block-style', 'enqueued' ) );

		do_shortcode( '[smaily_landing_page url="' . $this->landing_page_url() . '"]' );

		self::assertTrue( wp_style_is( 'smaily-landingpage-block-style', 'enqueued' ) );
	}

	private function landing_page_url(): string {
		return 'https://demostore.sendsmaily.net/landing-pages/' . self::PK . '/html/';
	}
}
