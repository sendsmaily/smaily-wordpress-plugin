<?php
/**
 * A new Campaign Intelligence connection may point only at
 * https://intelligence.smaily.com (PRO-3623): the pasted setup link before
 * any request, and the engine's reply — base URL and every endpoint — before
 * it is stored. The unit suite never defines the test-host constant, so these
 * tests see the production rule.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\ExchangeResult;
use Smaily\Connect\Smaily\RecEngine\SetupExchange;

final class SetupExchangeTest extends TestCase {

	private const BASE = 'https://intelligence.smaily.com';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_unit_suite_runs_without_the_test_host_seam(): void {
		self::assertFalse( defined( SetupExchange::TEST_ENGINE_HOST_CONSTANT ) );
	}

	/**
	 * @dataProvider allowed_urls
	 */
	public function test_an_https_url_on_the_engine_host_is_allowed( string $url ): void {
		self::assertTrue( SetupExchange::is_allowed_engine_url( $url ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function allowed_urls(): array {
		return array(
			'the base'                    => array( 'https://intelligence.smaily.com' ),
			'an endpoint'                 => array( 'https://intelligence.smaily.com/api/v1/ingest/catalog' ),
			'an {email} endpoint'         => array( 'https://intelligence.smaily.com/api/v1/customer/{email}/export' ),
			'host in another letter case' => array( 'HTTPS://Intelligence.Smaily.com/api/v1/ingest/orders' ),
		);
	}

	/**
	 * @dataProvider refused_urls
	 */
	public function test_any_other_url_is_refused( string $url ): void {
		self::assertFalse( SetupExchange::is_allowed_engine_url( $url ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refused_urls(): array {
		return array(
			'empty'                         => array( '' ),
			'plain http'                    => array( 'http://intelligence.smaily.com' ),
			'another scheme'                => array( 'ftp://intelligence.smaily.com' ),
			'no scheme'                     => array( '//intelligence.smaily.com/api' ),
			'a relative path'               => array( '/api/v1/ingest/catalog' ),
			'another host'                  => array( 'https://evil.example' ),
			'a lookalike suffix'            => array( 'https://intelligence.smaily.com.evil.com' ),
			'a lookalike prefix'            => array( 'https://evilintelligence.smaily.com' ),
			'a subdomain of the host'       => array( 'https://x.intelligence.smaily.com' ),
			'a trailing dot'                => array( 'https://intelligence.smaily.com./api' ),
			'user info before another host' => array( 'https://intelligence.smaily.com@evil.com/setup/tok' ),
			'user info on the host'         => array( 'https://user:pass@intelligence.smaily.com/api' ),
			'a backslash parser trick'      => array( 'https://intelligence.smaily.com\\@evil.com/api' ),
			'the host in the fragment'      => array( 'https://evil.com#@intelligence.smaily.com/api' ),
			'the host in the query'         => array( 'https://evil.com/?h=intelligence.smaily.com' ),
			'an explicit port'              => array( 'https://intelligence.smaily.com:8443/api' ),
			'leading whitespace'            => array( ' https://intelligence.smaily.com/api' ),
			'a trailing newline'            => array( "https://intelligence.smaily.com/api\n" ),
			'the integration mock host'     => array( 'http://127.0.0.1:9876' ),
		);
	}

	public function test_a_setup_link_with_user_info_resolves_to_a_refused_base(): void {
		$parsed = SetupExchange::parse_setup_url( 'https://intelligence.smaily.com@evil.com/setup/tok_abc' );

		self::assertSame( 'tok_abc', $parsed['token'] );
		self::assertFalse( SetupExchange::is_allowed_engine_url( $parsed['base'] ) );
	}

	public function test_a_setup_link_on_the_engine_host_resolves_to_an_allowed_base(): void {
		$parsed = SetupExchange::parse_setup_url( 'https://intelligence.smaily.com/setup/tok_abc' );

		self::assertSame( 'tok_abc', $parsed['token'] );
		self::assertTrue( SetupExchange::is_allowed_engine_url( $parsed['base'] ) );
	}

	public function test_a_base_on_another_host_is_refused_before_any_request(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$result = ( new SetupExchange() )->exchange( 'tok_abc', 'https://intelligence.smaily.com.evil.com' );

		self::assertSame( ExchangeResult::KIND_HOST_NOT_ALLOWED, $result->kind );
	}

	public function test_a_reply_on_the_engine_host_is_a_success(): void {
		$this->engine_replies( $this->reply() );

		$result = ( new SetupExchange() )->exchange( 'tok_abc', self::BASE );

		self::assertSame( ExchangeResult::KIND_SUCCESS, $result->kind );
		self::assertSame( self::BASE, $result->engine_base_url );
	}

	public function test_a_reply_with_a_base_on_another_host_is_refused(): void {
		$reply                    = $this->reply();
		$reply['engine_base_url'] = 'https://evil.example';
		$this->engine_replies( $reply );

		$result = ( new SetupExchange() )->exchange( 'tok_abc', self::BASE );

		self::assertSame( ExchangeResult::KIND_HOST_NOT_ALLOWED, $result->kind );
		self::assertSame( '', $result->api_key, 'A refused reply must not carry the API key to the caller.' );
	}

	public function test_a_reply_with_an_http_base_is_refused(): void {
		$reply                    = $this->reply();
		$reply['engine_base_url'] = 'http://intelligence.smaily.com';
		$this->engine_replies( $reply );

		$result = ( new SetupExchange() )->exchange( 'tok_abc', self::BASE );

		self::assertSame( ExchangeResult::KIND_HOST_NOT_ALLOWED, $result->kind );
	}

	public function test_a_reply_with_one_endpoint_on_another_host_is_refused(): void {
		$reply                               = $this->reply();
		$reply['endpoints']['ingest_orders'] = 'https://evil.example/api/v1/ingest/orders';
		$this->engine_replies( $reply );

		$result = ( new SetupExchange() )->exchange( 'tok_abc', self::BASE );

		self::assertSame( ExchangeResult::KIND_HOST_NOT_ALLOWED, $result->kind );
	}

	public function test_a_reply_with_a_missing_base_is_refused(): void {
		$reply = $this->reply();
		unset( $reply['engine_base_url'] );
		$this->engine_replies( $reply );

		$result = ( new SetupExchange() )->exchange( 'tok_abc', self::BASE );

		self::assertSame( ExchangeResult::KIND_HOST_NOT_ALLOWED, $result->kind );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function reply(): array {
		return array(
			'tenant_id'       => 'ten_1',
			'tenant_name'     => 'Test tenant',
			'api_key'         => 'sk_test_1',
			'engine_base_url' => self::BASE,
			'engine_version'  => '1.0.0',
			'endpoints'       => array(
				'ingest_catalog'  => self::BASE . '/api/v1/ingest/catalog',
				'ingest_orders'   => self::BASE . '/api/v1/ingest/orders',
				'customer_export' => self::BASE . '/api/v1/customer/{email}/export',
			),
		);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function engine_replies( array $body ): void {
		Functions\expect( 'wp_remote_post' )
			->once()
			->with( self::BASE . '/api/setup/exchange', \Mockery::type( 'array' ) )
			->andReturn( array( 'reply' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( (string) json_encode( $body ) );
	}
}
