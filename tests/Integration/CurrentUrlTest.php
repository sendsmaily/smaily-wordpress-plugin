<?php
/**
 * Integration: the newsletter forms' default return address is the store's
 * own address, never the client's Host header (CON-346), with the request's
 * percent-encoded path kept as it came (CON-348).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Helper;

final class CurrentUrlTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		parent::tearDown();
	}

	public function test_the_address_is_the_store_s_own_with_the_request_path_and_query(): void {
		$_SERVER['HTTP_HOST']   = 'attacker.example';
		$_SERVER['REQUEST_URI'] = '/shop/page/?code=101&lang=et';

		self::assertSame( $this->origin() . '/shop/page/?code=101&lang=et', Helper::get_current_url() );
	}

	public function test_an_absolute_request_target_keeps_only_its_path_and_query(): void {
		$_SERVER['HTTP_HOST']   = 'attacker.example';
		$_SERVER['REQUEST_URI'] = 'http://attacker.example/landing?x=1';

		self::assertSame( $this->origin() . '/landing?x=1', Helper::get_current_url() );

		$_SERVER['REQUEST_URI'] = '//attacker.example/landing';

		self::assertSame( $this->origin() . '/landing', Helper::get_current_url() );
	}

	public function test_a_percent_encoded_path_comes_back_unchanged(): void {
		$_SERVER['HTTP_HOST']   = 'attacker.example';
		$_SERVER['REQUEST_URI'] = '/tooted/m%C3%BCts%20ja%20sall/?lang=et&code=101';

		self::assertSame( $this->origin() . '/tooted/m%C3%BCts%20ja%20sall/?lang=et&code=101', Helper::get_current_url() );
	}

	private function origin(): string {
		$home = wp_parse_url( home_url() );
		return $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	}
}
