<?php
/**
 * Tests for the Smaily HTTP client — exercises request building, basic-auth
 * header, error mapping. wp_remote_* is mocked through Brain\Monkey so no
 * real HTTP traffic is generated.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\RefusalReason;

final class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_bloginfo' )->alias(
			static fn ( string $what ): string => $what === 'version' ? '6.2.0' : 'https://example.test'
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// Every non-2xx reads Retry-After (PRO-1685); absent unless a test says otherwise.
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_check_connection_is_ok_on_2xx_workflows_response(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->successful_response( '[]' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertSame( RefusalReason::OK, $client->check_connection() );
	}

	public function test_check_connection_names_rejected_credentials_on_4xx(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->successful_response( '{"error":"unauthorized"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"unauthorized"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertSame( RefusalReason::CREDENTIALS_REJECTED, $client->check_connection() );
	}

	public function test_check_connection_names_the_package_on_a_freemium_account(): void {
		// The exact live answer from a real freemium account (PRO-1686 probe,
		// 2026-08-04): every endpoint the plugin uses replies 403 {"code":227}.
		$body = '{"code":227,"message":"A paid package is required."}';
		Functions\when( 'wp_remote_get' )->justReturn( $this->successful_response( $body ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertSame( RefusalReason::PLAN_BLOCKED, $client->check_connection() );
	}

	public function test_a_refusal_carries_smailys_own_response_code(): void {
		$body = '{"code":227,"message":"A paid package is required."}';
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( $body ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'is_wp_error' )->justReturn( false );

		try {
			( new Client( 'demo', 'user', 'pass' ) )->upsert_subscribers( array( array( 'email' => 'a@b.c' ) ) );
			self::fail( 'A 403 must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 403, $e->getCode() );
			self::assertSame( 227, $e->smaily_code() );
			// The Event Log reason should say why, not only that.
			self::assertStringContainsString( 'Smaily code 227', $e->getMessage() );
		}
	}

	public function test_check_connection_reports_unreachable_on_transport_error(): void {
		$err = new \stdClass();
		Functions\when( 'wp_remote_get' )->justReturn( $err );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$wp_error = $this->getMockBuilder( \stdClass::class )
			->addMethods( array( 'get_error_message' ) )
			->getMock();
		$wp_error->method( 'get_error_message' )->willReturn( 'cURL exploded' );

		Functions\when( 'wp_remote_get' )->justReturn( $wp_error );

		$client = new Client( 'demo', 'user', 'pass' );

		self::assertSame( RefusalReason::UNREACHABLE, $client->check_connection() );
	}

	public function test_trigger_automation_throws_api_exception_on_5xx(): void {
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"err":"boom"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"err":"boom"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 503 );

		$client->trigger_automation( 42, array( array( 'email' => 'a@b.c' ) ) );
	}

	public function test_a_rate_limited_response_carries_smailys_retry_after(): void {
		// PRO-1685: "429 honour Retry-After" needs the wait to reach the
		// caller — RetryPolicy parks the row for exactly this many seconds.
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"err":"slow down"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"err":"slow down"}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '120' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		try {
			$client->upsert_subscribers( array( array( 'email' => 'a@b.c' ) ) );
			self::fail( 'A 429 must throw.' );
		} catch ( ApiException $e ) {
			self::assertSame( 429, $e->getCode() );
			self::assertSame( 120, $e->retry_after() );
		}
	}

	public function test_a_failure_without_a_retry_after_leaves_the_wait_to_the_caller(): void {
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"err":"boom"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"err":"boom"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		try {
			( new Client( 'demo', 'user', 'pass' ) )->upsert_subscribers( array( array( 'email' => 'a@b.c' ) ) );
			self::fail( 'A 503 must throw.' );
		} catch ( ApiException $e ) {
			self::assertNull( $e->retry_after() );
		}
	}

	public function test_last_exchange_captures_request_and_response_without_auth(): void {
		// F3-44: request() records the exchange (method/endpoint/body + reply) so
		// the Smaily Flusher can store it in the Event Log — NEVER the auth header.
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"code":101}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":101}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );
		self::assertNull( $client->last_exchange(), 'No exchange before the first request.' );

		$client->trigger_automation( 42, array( array( 'email' => 'a@b.c' ) ) );

		$exchange = $client->last_exchange();
		self::assertIsArray( $exchange );
		self::assertSame( 'POST', $exchange['request']['method'] );
		self::assertSame( 'autoresponder', $exchange['request']['endpoint'] );
		self::assertSame( 42, $exchange['request']['body']['autoresponder'] );
		self::assertSame( 200, $exchange['response']['http'] );
		self::assertSame( array( 'code' => 101 ), $exchange['response']['body'] );

		// The Basic-auth credentials must NEVER appear in the recorded exchange.
		$json = (string) json_encode( $exchange );
		self::assertStringNotContainsString( 'Authorization', $json );
		self::assertStringNotContainsString( base64_encode( 'user:pass' ), $json );
	}

	public function test_last_exchange_records_a_failed_response(): void {
		// On a non-2xx the response is still captured (before the throw), so the
		// Event Log shows what the engine actually replied (F3-44).
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"error":"bad"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 422 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"bad"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'user', 'pass' );

		try {
			$client->trigger_automation( 42, array( array( 'email' => 'a@b.c' ) ) );
		} catch ( ApiException $e ) {
			unset( $e );
		}

		$exchange = $client->last_exchange();
		self::assertSame( 422, $exchange['response']['http'] );
	}

	public function test_request_includes_basic_auth_header(): void {
		$captured_args = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		( new Client( 'demo', 'alice', 's3cret' ) )->list_autoresponders();

		self::assertIsArray( $captured_args );
		self::assertSame(
			'Basic ' . base64_encode( 'alice:s3cret' ),
			$captured_args['headers']['Authorization']
		);
		self::assertStringStartsWith( 'smaily-connect/', $captured_args['user-agent'] );
	}

	public function test_list_autoresponders_hits_the_documented_smaily_endpoint(): void {
		// Sub-PR 2.H.14 — pin the URL so a future refactor can't quietly
		// flip back to the bogus /api/workflows.php that returned empty
		// rows and made the dropdown surface `#{id}` placeholders.
		$captured_url = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		( new Client( 'demo', 'alice', 's3cret' ) )->list_autoresponders();

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/autoresponder.php', $captured_url );
		self::assertStringContainsString( 'status=ACTIVE', $captured_url );
	}

	public function test_get_action_log_hits_history_endpoint_and_parses_rows(): void {
		$captured_url = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'[{"seq_id":42,"email":"a@x.test","action":"optout"}]'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		$rows = ( new Client( 'demo', 'alice', 's3cret' ) )->get_action_log( 0, array( 'optin', 'optout' ) );

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/history.php', $captured_url );
		self::assertStringContainsString( 'since_seq_id=0', $captured_url );
		self::assertCount( 1, $rows );
		self::assertSame( 'optout', $rows[0]['action'] );
	}

	public function test_get_action_log_returns_empty_on_status_payload(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":223,"message":"missing date"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		self::assertSame( array(), ( new Client( 'demo', 'u', 'p' ) )->get_action_log( 0 ) );
	}

	public function test_list_contacts_hits_list_1_endpoint(): void {
		$captured_url = null;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '[{"email":"a@x.test","is_unsubscribed":"1"}]' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$rows = ( new Client( 'demo', 'alice', 's3cret' ) )->list_contacts( 0 );

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/contact.php', $captured_url );
		self::assertStringContainsString( 'list=1', $captured_url );
		self::assertStringContainsString( 'fields=', $captured_url );
		self::assertCount( 1, $rows );
	}

	public function test_send_message_posts_a_json_body_to_message_send_endpoint(): void {
		// PRO-1504 Stage 2: message/send.php is the one endpoint that takes a
		// JSON body (Content-Type: application/json), not the form encoding
		// every other Client method uses.
		$captured_url  = null;
		$captured_args = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured_url, &$captured_args ) {
				$captured_url  = $url;
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"code":101}',
					'headers'  => array(),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":101}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$response = ( new Client( 'demo', 'alice', 's3cret' ) )->send_message( 4242, 'buyer@example.test', array( 'order_number' => '123' ) );

		self::assertIsString( $captured_url );
		self::assertStringContainsString( '/api/message/send.php', $captured_url );
		self::assertIsArray( $captured_args );
		self::assertSame( 'application/json', $captured_args['headers']['Content-Type'] );
		$body = json_decode( (string) $captured_args['body'], true );
		self::assertSame( 4242, $body['autoresponder_id'] );
		self::assertSame( array( 'buyer@example.test' ), $body['to'] );
		self::assertSame( '123', $body['context']['order_number'] );
		self::assertSame( 101, $response['code'] );
	}

	public function test_send_message_last_exchange_never_carries_the_auth_header(): void {
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"code":101}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":101}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$client = new Client( 'demo', 'alice', 's3cret' );
		$client->send_message( 1, 'a@example.test', array() );

		$exchange = $client->last_exchange();
		self::assertSame( 'message/send', $exchange['request']['endpoint'] );
		self::assertStringNotContainsString( 'Authorization', (string) wp_json_encode( $exchange ) );
	}

	public function test_send_message_throws_api_exception_on_5xx(): void {
		Functions\when( 'wp_remote_post' )->justReturn( $this->successful_response( '{"err":"boom"}' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"err":"boom"}' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->expectException( ApiException::class );

		( new Client( 'demo', 'alice', 's3cret' ) )->send_message( 1, 'a@example.test', array() );
	}

	private function successful_response( string $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
			'headers'  => array(),
		);
	}
}
