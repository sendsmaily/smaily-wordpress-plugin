<?php
/**
 * RecEngine Client §25 history progress (contract v1.20.0, CON-248): the
 * endpoints-map URL vs the fallback path, the body as given, and one short
 * attempt — a report never holds up the import's batches behind it.
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

final class ClientHistoryProgressTest extends TestCase {

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

	public function test_posts_the_report_to_the_map_url(): void {
		$this->engine_answers( 200, '{"ok":true,"stale":false,"phase":"started"}' );
		$body = array(
			'import_id'     => 'woo-20261102T120000Z-a1b2c3',
			'phase'         => 'started',
			'horizon_start' => '2021-11-02',
			'entities'      => array(
				'catalog'   => array( 'sent' => 0 ),
				'customers' => array( 'sent' => 0 ),
				'orders'    => array( 'sent' => 0 ),
			),
		);

		$result = $this->client( array( 'ingest_history_progress' => 'https://engine.test/api/v1/ingest/history/progress' ) )
			->history_progress( $body );

		self::assertTrue( $result['ok'] );
		self::assertCount( 1, $this->requests );
		self::assertSame( 'https://engine.test/api/v1/ingest/history/progress', $this->requests[0]['url'] );
		self::assertSame( 'POST', $this->requests[0]['args']['method'] );
		self::assertSame( json_encode( $body ), $this->requests[0]['args']['body'] );
	}

	public function test_falls_back_to_the_path_constant_without_the_map_key(): void {
		$this->engine_answers( 200, '{"ok":true}' );

		$this->client()->history_progress( array( 'phase' => 'started' ) );

		self::assertSame( '/api/v1/ingest/history/progress', Client::PATH_INGEST_HISTORY_PROGRESS );
		self::assertSame( 'https://engine.unit' . Client::PATH_INGEST_HISTORY_PROGRESS, $this->requests[0]['url'] );
	}

	public function test_a_failing_engine_gets_one_short_attempt(): void {
		$this->engine_answers( 500, '{"error":"db_failure"}', '5' );
		$client = $this->client();

		try {
			$client->history_progress( array( 'phase' => 'started' ) );
			self::fail( 'A 5xx must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 500, $e->getCode() );
			self::assertSame( 'db_failure', $e->error_code() );
		}

		self::assertCount( 1, $this->requests, 'The caller retries 5 minutes later, not this request.' );
		self::assertSame( array(), $client->sleeps );
		self::assertSame( Client::BROWSE_TIMEOUT_SECONDS, $this->requests[0]['args']['timeout'] );
		self::assertSame( 0, $this->requests[0]['args']['redirection'] );
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
	 * attempt" is history_progress()'s own rule, not the construction's.
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
