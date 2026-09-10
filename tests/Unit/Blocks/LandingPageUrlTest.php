<?php
/**
 * Tests for the server-side landing-page address check (PRO-2440).
 *
 * The shortcode and the Elementor widget both take an address a merchant
 * pasted, so this is the boundary that keeps a foreign host — or another
 * Smaily account's page — off the store's pages. The block editor's own
 * parser (blocks/landingpage/src/urlParser.js) is its browser-side twin.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily_Connect\Blocks\Landing_Page\Integration;

require_once dirname( __DIR__, 3 ) . '/blocks/landingpage/smaily-integration.class.php';

final class LandingPageUrlTest extends TestCase {

	private const PK = '01a42617-cb5d-4f65-8ea6-a8f4d1b94ffa';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_landing_page_of_this_account_yields_its_key(): void {
		self::assertSame(
			self::PK,
			Integration::landing_page_key(
				'https://demostore.sendsmaily.net/landing-pages/' . self::PK . '/html/',
				'demostore'
			)
		);
	}

	/**
	 * @dataProvider provide_refused_addresses
	 */
	public function test_anything_else_is_refused( string $url, string $subdomain ): void {
		self::assertSame( '', Integration::landing_page_key( $url, $subdomain ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function provide_refused_addresses(): array {
		$path = '/landing-pages/' . self::PK . '/html/';

		return array(
			'another host'            => array( 'https://evil.example.com' . $path, 'demostore' ),
			'a lookalike host'        => array( 'https://demostore.sendsmaily.net.evil.com' . $path, 'demostore' ),
			'another Smaily account'  => array( 'https://otherstore.sendsmaily.net' . $path, 'demostore' ),
			'plain http'              => array( 'http://demostore.sendsmaily.net' . $path, 'demostore' ),
			'not a landing page path' => array( 'https://demostore.sendsmaily.net/api/opt-in/', 'demostore' ),
			'not a landing page key'  => array( 'https://demostore.sendsmaily.net/landing-pages/12/html/', 'demostore' ),
			'no address at all'       => array( '', 'demostore' ),
			'no account connected'    => array( 'https://demostore.sendsmaily.net' . $path, '' ),
		);
	}
}
