<?php
/**
 * RecEngine Client §21 onboarding (CON-175): the status read and the start
 * go to their path constants with no key — the store has none yet — the
 * status in the one short attempt, the start in the attempt and timeout the
 * client was built with.
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

final class ClientOnboardingTest extends TestCase {

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

	public function test_the_status_is_one_short_key_less_get(): void {
		$this->engine_answers( 200, '{"start_open":true}' );

		$answer = $this->client()->onboarding_status();

		self::assertSame( array( 'start_open' => true ), $answer );
		self::assertCount( 1, $this->requests );
		self::assertSame( 'https://intelligence.smaily.com/api/v1/onboarding/status', $this->requests[0]['url'] );
		self::assertSame( 'GET', $this->requests[0]['args']['method'] );
		self::assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );
		self::assertArrayNotHasKey( 'body', $this->requests[0]['args'] );
		self::assertSame( Client::BROWSE_TIMEOUT_SECONDS, $this->requests[0]['args']['timeout'] );
		self::assertSame( 0, $this->requests[0]['args']['redirection'] );
	}

	public function test_the_start_posts_the_body_once_without_a_key(): void {
		$this->engine_answers( 502, '{"error":"smaily_unavailable","message":"Smaily did not answer."}' );
		$client = $this->client();

		try {
			$client->onboarding_start( array( 'platform' => 'woocommerce' ) );
			self::fail( 'A 502 must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 502, $e->getCode() );
			self::assertSame( 'smaily_unavailable', $e->error_code() );
		}

		self::assertCount( 1, $this->requests, 'One press, one engine call.' );
		self::assertSame( array(), $client->sleeps );
		self::assertSame( 'https://intelligence.smaily.com/api/v1/onboarding/start', $this->requests[0]['url'] );
		self::assertSame( 'POST', $this->requests[0]['args']['method'] );
		self::assertSame( '{"platform":"woocommerce"}', $this->requests[0]['args']['body'] );
		self::assertSame( 10, $this->requests[0]['args']['timeout'] );
		self::assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );
	}

	public function test_a_store_that_exists_carries_its_console_address(): void {
		$this->engine_answers( 409, '{"error":"store_exists","console_url":"https://intelligence.smaily.com/console/t1/home"}' );

		try {
			$this->client()->onboarding_start( array() );
			self::fail( 'A 409 must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 'https://intelligence.smaily.com/console/t1/home', $e->console_url() );
		}
	}

	private function engine_answers( int $status, string $body ): void {
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
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	/** A key-less client as the registry builds it: one attempt, 10 s. */
	private function client(): Client {
		return new class( '', 'https://intelligence.smaily.com', array(), 1, null, 10 ) extends Client {
			/** @var array<int, int> */
			public array $sleeps = array();

			protected function sleep_with_backoff( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		};
	}
}
