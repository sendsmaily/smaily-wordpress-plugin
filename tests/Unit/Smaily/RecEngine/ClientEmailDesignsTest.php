<?php
/**
 * RecEngine Client email-design methods (§22–§23, contract v1.17.0): the
 * endpoints-map URL vs the fallback path, the `{email_key}` substitution and
 * the empty §22 body sent as the JSON object `{}`.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Client;

final class ClientEmailDesignsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_design_link_substitutes_the_url_encoded_key_into_the_map_url(): void {
		$client = $this->capturing_client(
			array( 'emails_design_link' => 'https://engine.test/api/v1/emails/{email_key}/design-link' )
		);

		$client->email_design_link( 'plugin.abandoned_cart', array( 'language' => 'et' ) );

		self::assertSame( 'POST', $client->captured['method'] );
		self::assertSame( 'https://engine.test/api/v1/emails/plugin.abandoned_cart/design-link', $client->captured['url'] );
		self::assertSame( array( 'language' => 'et' ), $client->captured['body'] );

		$client->email_design_link( 'a b/c', array() );
		self::assertSame( 'https://engine.test/api/v1/emails/a%20b%2Fc/design-link', $client->captured['url'], 'The key is URL-encoded.' );
	}

	public function test_design_link_and_designs_fall_back_to_the_path_constants_without_map_keys(): void {
		$client = $this->capturing_client();

		$client->email_design_link( 'transactional.order_confirmation', array() );
		self::assertSame( 'https://base.test/api/v1/emails/transactional.order_confirmation/design-link', $client->captured['url'] );

		$client->email_designs();
		self::assertSame( 'GET', $client->captured['method'] );
		self::assertSame( 'https://base.test' . Client::PATH_EMAILS_DESIGNS, $client->captured['url'] );
		self::assertNull( $client->captured['body'] );
	}

	public function test_designs_reads_the_map_url(): void {
		$client = $this->capturing_client( array( 'emails_designs' => 'https://engine.test/api/v1/emails/designs' ) );

		$client->email_designs();

		self::assertSame( 'https://engine.test/api/v1/emails/designs', $client->captured['url'] );
	}

	public function test_an_empty_design_link_body_goes_over_the_wire_as_a_json_object(): void {
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
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"url":"https://engine.test/design/email/dl_1","expires_at":"2026-10-23T08:05:00.000Z"}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$result = ( new Client( 'sk_live', 'https://base.test' ) )->email_design_link( 'plugin.welcome', array() );

		self::assertSame( 'https://engine.test/design/email/dl_1', $result['url'] );
		self::assertSame( '{}', $captured['body'], '§22 takes `{}`; `[]` is not an object.' );
		self::assertSame( 'application/json', $captured['headers']['Content-Type'] );
	}

	/**
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
				return array( 'ok' => true );
			}
		};
	}
}
