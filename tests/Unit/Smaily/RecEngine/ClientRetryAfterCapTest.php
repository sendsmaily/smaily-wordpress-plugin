<?php
/**
 * PRO-3817: a background engine call (flusher, backfill, ping, GDPR call)
 * honours the engine's Retry-After, but never waits longer than
 * Client::MAX_RETRY_AFTER_SECONDS before its next attempt — one long wait
 * would otherwise hold an Action Scheduler worker for as long as the engine
 * asks. The browse relay's single attempt (PRO-3620) is pinned in
 * ClientBrowseRelayTest.
 *
 * Driven through the real request_url() with the WP HTTP functions stubbed;
 * the client records its waits instead of sleeping.
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

final class ClientRetryAfterCapTest extends TestCase {

	/** @var int Requests made. */
	private int $requests = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->requests = 0;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_long_retry_after_is_waited_out_for_at_most_60_seconds(): void {
		$this->engine_answers( 429, '600' );
		$client = $this->client();

		$this->send_catalog( $client );

		self::assertSame( Client::DEFAULT_MAX_ATTEMPTS, $this->requests, 'The attempt count is unchanged.' );
		self::assertSame( array( 60, 60, 60, 60 ), $client->sleeps, 'A 600 s Retry-After is cut to 60 s between attempts.' );
		self::assertSame( 60, Client::MAX_RETRY_AFTER_SECONDS );
	}

	public function test_a_short_retry_after_is_waited_out_unchanged(): void {
		$this->engine_answers( 503, '5' );
		$client = $this->client();

		$this->send_catalog( $client );

		self::assertSame( Client::DEFAULT_MAX_ATTEMPTS, $this->requests );
		self::assertSame( array( 5, 5, 5, 5 ), $client->sleeps );
	}

	private function send_catalog( Client $client ): void {
		try {
			$client->ingest_catalog( array( array( 'sku' => 'woo-1', 'event_id' => 'u1' ) ) );
			self::fail( 'An engine that keeps refusing must throw after the last attempt.' );
		} catch ( ApiException $e ) {
			unset( $e );
		}
	}

	/**
	 * Every request answers the given status with the given Retry-After.
	 */
	private function engine_answers( int $status, string $retry_after ): void {
		Functions\when( 'wp_remote_request' )->alias(
			function (): array {
				++$this->requests;
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
	 * A client on the default retry ceiling that records its waits.
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
}
