<?php
/**
 * RecEngine Client §15 `POST /api/v1/recommendations/customer` (contract
 * v1.9.0) — the URL (engine `recommendations_customer` map key vs the PATH_*
 * fallback for a pre-v1.9.0 connection), the body, and the short timeout a
 * storefront render needs.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;

final class ClientCustomerRecommendationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_posts_the_store_customer_id_and_limit_to_the_engine_map_url(): void {
		$client = $this->capturing_client(
			array( 'recommendations_customer' => 'https://engine.test/api/v1/recommendations/customer' )
		);

		$client->customer_recommendations( '1042', 4 );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/recommendations/customer', $client->captured['url'] );
		self::assertSame(
			array(
				'customer_external_id' => '1042',
				'limit'                => 4,
			),
			$client->captured['body'],
			'§15 names the shopper by the store id only — never an email.'
		);
	}

	public function test_posts_a_returning_guest_s_visitor_token_in_place_of_the_customer_id(): void {
		$client = $this->capturing_client(
			array( 'recommendations_customer' => 'https://engine.test/api/v1/recommendations/customer' )
		);

		$client->visitor_recommendations( 'vt_8f3k2a', 4 );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/recommendations/customer', $client->captured['url'] );
		self::assertSame(
			array(
				'smaily_visitor_token' => 'vt_8f3k2a',
				'limit'                => 4,
			),
			$client->captured['body'],
			'§15 v1.10.0: the token alone, never together with a customer id.'
		);
	}

	public function test_falls_back_to_the_constant_path_on_a_connection_without_the_key(): void {
		// A connection set up before v1.9.0 keeps a map without the key (§15).
		$client = $this->capturing_client();

		$client->customer_recommendations( '7', 4 );

		self::assertSame( 'https://base.test' . Client::PATH_RECOMMENDATIONS_CUSTOMER, $client->captured['url'] );
	}

	public function test_the_request_carries_the_timeout_the_client_was_built_with(): void {
		$captured = array();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$captured = $args;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"slots":[]}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$client = new Client( 'sk_live', 'https://base.test', array(), 1, null, 1 );

		self::assertSame( array( 'slots' => array() ), $client->customer_recommendations( '7', 4 ) );
		self::assertSame( 1, $captured['timeout'] );
	}

	public function test_a_single_attempt_client_neither_retries_nor_sleeps_on_429(): void {
		$attempts = 0;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$attempts ): array {
				++$attempts;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"rate_limited"}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '30' );

		$client = new class( 'sk_live', 'https://base.test', array(), 1, null, 1 ) extends Client {
			/** @var int[] */
			public array $slept = array();

			protected function sleep_with_backoff( int $seconds ): void {
				$this->slept[] = $seconds;
			}
		};

		try {
			$client->customer_recommendations( '7', 4 );
			self::fail( 'A 429 on a single-attempt client must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 429, $e->getCode() );
		}

		self::assertSame( 1, $attempts );
		self::assertSame( array(), $client->slept, 'A storefront render never waits out a Retry-After.' );
	}

	/**
	 * Client double that captures the resolved (method, url, body) instead of
	 * hitting the network.
	 *
	 * @param array<string, string> $endpoints
	 */
	private function capturing_client( array $endpoints = array() ): Client {
		return new class( 'sk_live', 'https://base.test', $endpoints ) extends Client {
			/** @var array<string, mixed> */
			public array $captured = array();

			protected function request_url( string $method, string $url, ?array $body = null, bool $single_attempt = false ): array {
				$this->captured = array(
					'method' => $method,
					'url'    => $url,
					'body'   => $body,
				);
				return array( 'slots' => array() );
			}
		};
	}
}
