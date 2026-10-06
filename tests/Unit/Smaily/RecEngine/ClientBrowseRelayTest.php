<?php
/**
 * PRO-3620: the browse forward runs inside a shopper's storefront request (the
 * public `/relay` proxy), so it is ONE short attempt — no retry, no back-off,
 * no Retry-After wait, no redirect — whatever retry ceiling the client was
 * built with. Every other engine call keeps the retry policy.
 *
 * Driven through the real request_url() with the WP HTTP functions stubbed,
 * so the transport arguments and the sleep calls are observed directly.
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

final class ClientBrowseRelayTest extends TestCase {

	/** @var array<int, array<string, mixed>> Transport args of every request made. */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->requests = array();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_throttling_engine_gets_one_short_attempt_and_no_wait(): void {
		$this->engine_answers( 429, '30' );
		$client = $this->client();

		try {
			$client->ingest_browse( $this->events() );
			self::fail( 'A refused browse batch must throw — the relay answers 502 and the batch is lost.' );
		} catch ( ApiException $e ) {
			self::assertSame( 429, $e->getCode() );
		}

		self::assertCount( 1, $this->requests, 'One attempt — a 429 is never retried on the storefront path.' );
		self::assertSame( array(), $client->sleeps, 'The engine\'s Retry-After is never waited out in a shopper\'s request.' );
		self::assertSame( Client::BROWSE_TIMEOUT_SECONDS, $this->requests[0]['timeout'] );
		self::assertSame( 3, Client::BROWSE_TIMEOUT_SECONDS, 'The fixed bound on the whole request, connect included.' );
		self::assertSame( 0, $this->requests[0]['redirection'], 'A redirect hop would get its own timeout.' );
	}

	public function test_a_failing_engine_gets_one_attempt(): void {
		$this->engine_answers( 503, '' );
		$client = $this->client();

		try {
			$client->ingest_browse( $this->events() );
			self::fail( 'A 5xx must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 503, $e->getCode() );
		}

		self::assertCount( 1, $this->requests );
		self::assertSame( array(), $client->sleeps );
	}

	public function test_an_unreachable_engine_gets_one_attempt(): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ): \WP_Error {
				$this->requests[] = $args;
				return new \WP_Error( 'http_request_failed', 'Operation timed out after 3000 milliseconds' );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof \WP_Error;
			}
		);
		$client = $this->client();

		try {
			$client->ingest_browse( $this->events() );
			self::fail( 'A transport failure must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 'network_error', $e->error_code() );
		}

		self::assertCount( 1, $this->requests );
		self::assertSame( array(), $client->sleeps );
	}

	public function test_other_engine_calls_keep_the_retry_policy(): void {
		// Same client, browse first: the single-attempt mode must not leak into
		// the next call (a flusher or backfill keeps its retries and waits).
		$this->engine_answers( 503, '7' );
		$client = $this->client();

		try {
			$client->ingest_browse( $this->events() );
		} catch ( ApiException $e ) {
			unset( $e );
		}
		$this->requests = array();

		try {
			$client->ingest_catalog( array( array( 'sku' => 'woo-1', 'event_id' => 'u1' ) ) );
		} catch ( ApiException $e ) {
			unset( $e );
		}

		self::assertCount( Client::DEFAULT_MAX_ATTEMPTS, $this->requests, 'Catalog still retries up to the ceiling.' );
		self::assertSame( array( 7, 7, 7, 7 ), $client->sleeps, 'Catalog still honours Retry-After between attempts.' );
		self::assertSame( 15, $this->requests[0]['timeout'] );
		self::assertArrayNotHasKey( 'redirection', $this->requests[0] );
	}

	/**
	 * Every request answers the given status, with an optional Retry-After.
	 */
	private function engine_answers( int $status, string $retry_after ): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ): array {
				$this->requests[] = $args;
				return array( 'body' => '{"error":"engine_busy"}' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"engine_busy"}' );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, string $header ) use ( $retry_after ): string {
				return $header === 'retry-after' ? $retry_after : '';
			}
		);
	}

	/**
	 * A client on the DEFAULT retry ceiling (5) that records its waits instead
	 * of sleeping — so "one attempt" is the browse call's own rule, not the
	 * construction's.
	 */
	private function client(): Client {
		return new class( 'sk_unit', 'https://engine.unit' ) extends Client {
			/** @var array<int, int> */
			public array $sleeps = array();

			protected function sleep_with_backoff( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		};
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function events(): array {
		return array(
			array(
				'event_id'   => 'e1',
				'event_type' => 'product_view',
				'sku'        => 'woo-1',
			),
		);
	}
}
