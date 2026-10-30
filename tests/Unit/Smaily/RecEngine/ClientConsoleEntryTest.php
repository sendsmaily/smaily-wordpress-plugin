<?php
/**
 * RecEngine Client §24 console entry (contract v1.19.0, CON-228): the
 * endpoints-map URL vs the fallback path, the `{email}` body, and the one
 * short attempt — a press of "Open Campaign Intelligence" makes exactly one
 * engine call, never retried or held by a Retry-After, whose seconds still
 * reach the caller for the admin's message.
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

final class ClientConsoleEntryTest extends TestCase {

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
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

	public function test_posts_the_admin_email_to_the_map_url(): void {
		$this->engine_answers( 200, '{"url":"https://engine.test/console-entry#g=seg_1","expires_at":"2026-10-27T09:05:00.000Z"}' );

		$result = $this->client( array( 'console_entry' => 'https://engine.test/api/v1/console/entry' ) )
			->console_entry( 'admin@example.test' );

		self::assertSame( 'https://engine.test/console-entry#g=seg_1', $result['url'] );
		self::assertCount( 1, $this->requests );
		self::assertSame( 'https://engine.test/api/v1/console/entry', $this->requests[0]['url'] );
		self::assertSame( 'POST', $this->requests[0]['args']['method'] );
		self::assertSame( '{"email":"admin@example.test"}', $this->requests[0]['args']['body'] );
		self::assertSame( 'Bearer sk_unit', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_falls_back_to_the_path_constant_without_the_map_key(): void {
		$this->engine_answers( 200, '{"url":"https://engine.unit/console-entry#g=seg_1"}' );

		$this->client()->console_entry( 'admin@example.test' );

		self::assertSame( '/api/v1/console/entry', Client::PATH_CONSOLE_ENTRY );
		self::assertSame( 'https://engine.unit' . Client::PATH_CONSOLE_ENTRY, $this->requests[0]['url'] );
	}

	public function test_a_throttling_engine_gets_one_short_attempt_and_its_retry_after_reaches_the_caller(): void {
		$this->engine_answers( 429, '{"error":"rate_limit_exceeded"}', '42' );
		$client = $this->client();

		try {
			$client->console_entry( 'admin@example.test' );
			self::fail( 'A 429 must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 429, $e->getCode() );
			self::assertSame( 'rate_limit_exceeded', $e->error_code() );
			self::assertSame( 42, $e->retry_after() );
		}

		self::assertCount( 1, $this->requests, 'One press, one engine call.' );
		self::assertSame( array(), $client->sleeps, 'The admin is never held by a Retry-After.' );
		self::assertSame( Client::BROWSE_TIMEOUT_SECONDS, $this->requests[0]['args']['timeout'] );
		self::assertSame( 0, $this->requests[0]['args']['redirection'] );
	}

	public function test_a_429_without_retry_after_carries_none(): void {
		$this->engine_answers( 429, '{"error":"rate_limit_exceeded"}' );

		try {
			$this->client()->console_entry( 'admin@example.test' );
			self::fail( 'A 429 must throw.' );
		} catch ( ApiException $e ) {
			self::assertNull( $e->retry_after() );
		}
	}

	public function test_a_failing_engine_gets_one_attempt(): void {
		$this->engine_answers( 503, '{"error":"internal_error"}', '5' );
		$client = $this->client();

		try {
			$client->console_entry( 'admin@example.test' );
			self::fail( 'A 5xx must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 503, $e->getCode() );
			self::assertNull( $e->retry_after(), 'Only a 429 carries a retry-after.' );
		}

		self::assertCount( 1, $this->requests );
		self::assertSame( array(), $client->sleeps );
	}

	public function test_the_switched_off_engine_answers_404_not_found(): void {
		$this->engine_answers( 404, '{"error":"not_found"}' );

		try {
			$this->client()->console_entry( 'admin@example.test' );
			self::fail( 'A 404 must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 404, $e->getCode() );
			self::assertSame( 'not_found', $e->error_code() );
		}
		self::assertCount( 1, $this->requests );
	}

	private function engine_answers( int $status, string $body, string $retry_after = '' ): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) use ( $body ): array {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array( 'body' => $body );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, string $header ) use ( $retry_after ): string {
				return $header === 'retry-after' ? $retry_after : '';
			}
		);
	}

	/**
	 * A client on the DEFAULT retry ceiling that records its waits — so "one
	 * attempt" is console_entry()'s own rule, not the construction's.
	 *
	 * @param array<string, string> $endpoints
	 */
	private function client( array $endpoints = array() ): Client {
		return new class( 'sk_unit', 'https://engine.unit', $endpoints ) extends Client {
			/** @var array<int, int> */
			public array $sleeps = array();

			protected function sleep_with_backoff( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		};
	}
}
