<?php
/**
 * RecEngine Client automations methods (§11–§13, contract v1.1.0) —
 * endpoint-URL resolution (engine `automations_*` map keys vs the PATH_*
 * fallback for pre-v1.1.0 connections), the PUT `{configs}` wrapper, and
 * the wire-level PUT shape (wp_remote_request 'method' => 'PUT' + JSON
 * body — the first PUT verb in this Client) incl. the 422 → ApiException
 * `errors[]` passthrough the settings UI depends on.
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

final class ClientAutomationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_automations_catalog_gets_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'automations_catalog' => 'https://engine.test/api/v1/automations/catalog' )
		);

		$client->automations_catalog();

		self::assertSame( 'GET', $client->captured['method'] );
		self::assertSame(
			'https://engine.test/api/v1/automations/catalog',
			$client->captured['url'],
			'The engine endpoints map (key automations_catalog, v1.1.0) is the source of truth for the URL.'
		);
		self::assertNull( $client->captured['body'], 'Catalog is a GET — no body.' );
	}

	public function test_automations_catalog_falls_back_to_constant_path_without_map(): void {
		// A connection exchanged before contract v1.1.0 has no automations_*
		// keys in its stored map ("Map age", §1) — the fallback constant is
		// load-bearing for every such existing connection.
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->automations_catalog();

		self::assertSame(
			'https://base.test' . Client::PATH_AUTOMATIONS_CATALOG,
			$client->captured['url']
		);
	}

	public function test_automations_config_gets_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'automations_config' => 'https://engine.test/api/v1/automations/config' )
		);

		$client->automations_config();

		self::assertSame( 'GET', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/automations/config', $client->captured['url'] );
		self::assertNull( $client->captured['body'] );
	}

	public function test_automations_config_falls_back_to_constant_path_without_map(): void {
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->automations_config();

		self::assertSame(
			'https://base.test' . Client::PATH_AUTOMATIONS_CONFIG,
			$client->captured['url']
		);
	}

	public function test_put_automations_config_puts_configs_wrapper_to_engine_map_url(): void {
		$client = $this->capturing_client(
			'sk_live',
			'https://base.test',
			array( 'automations_config' => 'https://engine.test/api/v1/automations/config' )
		);

		$row = $this->valid_row();
		$client->put_automations_config( array( $row ) );

		self::assertSame( 'PUT', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/automations/config', $client->captured['url'] );
		self::assertSame(
			array( 'configs' => array( $row ) ),
			$client->captured['body'],
			'Automations wire wrapper key is `configs` (§13).'
		);
	}

	public function test_put_automations_config_falls_back_to_constant_path_without_map(): void {
		$client = $this->capturing_client( 'sk_live', 'https://base.test' );

		$client->put_automations_config( array( $this->valid_row() ) );

		self::assertSame(
			'https://base.test' . Client::PATH_AUTOMATIONS_CONFIG,
			$client->captured['url']
		);
	}

	public function test_put_sends_method_put_with_json_body_over_the_wire(): void {
		// PUT is the first non-POST body verb in this Client — pin that
		// request_url() actually hands wp_remote_request 'method' => 'PUT'
		// WITH the JSON body + Content-Type (a transport that dropped the
		// body on PUT would pass every request_url()-doubled test above).
		$captured = array();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"ok":true,"upserted":1}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$client = new Client( 'sk_live', 'https://base.test' );
		$row    = $this->valid_row();
		$result = $client->put_automations_config( array( $row ) );

		self::assertSame( array( 'ok' => true, 'upserted' => 1 ), $result );
		self::assertSame( 'https://base.test' . Client::PATH_AUTOMATIONS_CONFIG, $captured['url'] );
		self::assertSame( 'PUT', $captured['args']['method'] );
		self::assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );
		self::assertSame(
			array( 'configs' => array( $row ) ),
			json_decode( (string) $captured['args']['body'], true ),
			'The PUT body must carry the {configs} wrapper as JSON.'
		);
	}

	public function test_put_422_throws_api_exception_carrying_indexed_errors(): void {
		// §13: validation is all-or-nothing — a 422 means NOTHING was written
		// and the body's indexed D6-style errors[] must survive onto the
		// ApiException (errors()) so the settings UI can bind every entry to
		// its row/field. 4xx is terminal: no retry.
		$body_422 = array(
			'error'  => 'validation_failed',
			'errors' => array(
				array(
					'index'       => 0,
					'trigger_key' => 'replenish_due',
					'field'       => 'automation_map',
					'message'     => 'automation_map.id on nõutav',
				),
			),
		);
		$attempts = 0;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$attempts ): array {
				++$attempts;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 422 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( (string) json_encode( $body_422 ) );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$client = new Client( 'sk_live', 'https://base.test' );

		try {
			$client->put_automations_config( array( $this->valid_row() ) );
			self::fail( 'A 422 must throw ApiException.' );
		} catch ( ApiException $e ) {
			self::assertSame( 422, $e->getCode() );
			self::assertSame( 'validation_failed', $e->error_code() );
			self::assertSame( $body_422['errors'], $e->errors(), 'The indexed errors[] must be preserved verbatim.' );
		}

		self::assertSame( 1, $attempts, 'A 422 is a terminal 4xx — the client must not retry.' );
	}

	/**
	 * A §13-valid config row (all 8 required fields).
	 *
	 * @return array<string, mixed>
	 */
	private function valid_row(): array {
		return array(
			'trigger_key'    => 'replenish_due',
			'enabled'        => true,
			'language_mode'  => 'single',
			'automation_map' => array( 'id' => '123' ),
			'cooldown_days'  => 7,
			'daily_cap'      => null,
			'test_mode'      => true,
			'test_emails'    => array( 'owner@example.com' ),
		);
	}

	/**
	 * Client double that captures the resolved (method, url, body) instead
	 * of hitting the network, and returns a canned 200 body — same pattern
	 * as ClientTest::capturing_client().
	 *
	 * @param array<string, string> $endpoints
	 */
	private function capturing_client( string $api_key, string $base_url, array $endpoints = array() ): Client {
		return new class( $api_key, $base_url, $endpoints ) extends Client {
			/** @var array<string, mixed> */
			public array $captured = array();

			protected function request_url( string $method, string $url, ?array $body = null ): array {
				$this->captured = array(
					'method' => $method,
					'url'    => $url,
					'body'   => $body,
				);
				return array( 'ok' => true );
			}
		};
	}
}
