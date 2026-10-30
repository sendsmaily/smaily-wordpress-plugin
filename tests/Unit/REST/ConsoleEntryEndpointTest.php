<?php
/**
 * ConsoleEntryEndpoint (contract §24, CON-228) — the admin check (session,
 * nonce, capability), the sending gate, the signed-in admin's own email as
 * the only thing sent, the no-store answer, and the engine status mapping.
 * The real HTTP path is in the integration suite (ConsoleEntryTest).
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\ConsoleEntryEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class ConsoleEntryEndpointTest extends TestCase {

	private const LINK = 'https://engine.unit/console-entry#g=seg_1';

	private bool $factory_called = false;

	private string $user_email = 'admin@example.test';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_email' )->alias(
			static fn ( string $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : false
		);
		Functions\when( 'wp_get_current_user' )->alias(
			function () {
				$user             = new \stdClass();
				$user->user_email = $this->user_email;
				return $user;
			}
		);
		$this->factory_called = false;
		$this->user_email     = 'admin@example.test';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_check_needs_a_session_a_valid_nonce_and_the_capability(): void {
		$endpoint = $this->endpoint( new FakeRecEngineSettings(), $this->stub_client() );
		Functions\when( 'current_user_can' )->justReturn( true );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		self::assertInstanceOf( \WP_Error::class, $endpoint->admin_check( $this->request( 'n' ) ), 'No session.' );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		self::assertInstanceOf( \WP_Error::class, $endpoint->admin_check( $this->request( '' ) ), 'No nonce.' );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		self::assertInstanceOf( \WP_Error::class, $endpoint->admin_check( $this->request( 'bad' ) ), 'A wrong nonce.' );

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );
		self::assertInstanceOf( \WP_Error::class, $endpoint->admin_check( $this->request( 'n' ) ), 'No capability.' );

		Functions\when( 'current_user_can' )->justReturn( true );
		self::assertTrue( $endpoint->admin_check( $this->request( 'n' ) ) );
	}

	public function test_no_engine_call_unless_sending_is_allowed(): void {
		$cases = array(
			array( new FakeRecEngineSettings( false ), 503, 'not_available' ),
			array( new FakeRecEngineSettings( true, true ), 403, 'tenant_inactive' ),
			array( new FakeRecEngineSettings( true, false, '' ), 503, 'not_available' ),
		);
		foreach ( $cases as list( $settings, $status, $error ) ) {
			$response = $this->endpoint( $settings, $this->stub_client() )->open( $this->request() );

			self::assertSame( $status, $response->get_status() );
			self::assertSame( $error, $response->get_data()['error'] );
			self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		}

		self::assertFalse( $this->factory_called );
	}

	public function test_sends_the_signed_in_admin_email_and_answers_the_link_without_a_cache(): void {
		$client = $this->stub_client(
			array(
				'url'        => self::LINK,
				'expires_at' => '2026-10-27T09:05:00.000Z',
			)
		);

		$response = $this->endpoint( new FakeRecEngineSettings(), $client )->open( $this->request() );

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'url'        => self::LINK,
				'expires_at' => '2026-10-27T09:05:00.000Z',
			),
			$response->get_data()
		);
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		self::assertSame( array( 'admin@example.test' ), $client->received, 'One call, with the admin\'s own email.' );
	}

	public function test_an_admin_without_a_usable_email_is_not_sent(): void {
		foreach ( array( '', 'not-an-email', str_repeat( 'a', 250 ) . '@example.test' ) as $email ) {
			$this->user_email = $email;
			$client           = $this->stub_client( array( 'url' => self::LINK ) );

			$response = $this->endpoint( new FakeRecEngineSettings(), $client )->open( $this->request() );

			self::assertSame( 400, $response->get_status(), $email );
			self::assertSame( 'no_admin_email', $response->get_data()['error'] );
			self::assertSame( array(), $client->received );
		}
	}

	public function test_refuses_an_engine_answer_that_is_not_a_web_link(): void {
		foreach ( array( 'javascript:alert(1)', 'http://elsewhere.example/x', '' ) as $url ) {
			$response = $this->endpoint( new FakeRecEngineSettings(), $this->stub_client( array( 'url' => $url ) ) )->open( $this->request() );

			self::assertSame( 502, $response->get_status(), $url );
			self::assertSame( 'invalid_engine_response', $response->get_data()['error'] );
		}
	}

	public function test_engine_errors_map_to_their_own_answers(): void {
		$cases = array(
			array( new ApiException( 404, 'not_found', 'Not found' ), 404, array( 'error' => 'not_available', 'console_url' => 'https://engine.unit/login' ) ),
			array( new ApiException( 401, 'api_key_revoked', 'Revoked' ), 502, array( 'error' => 'api_key_rejected' ) ),
			array( new ApiException( 403, 'tenant_inactive', 'Inactive' ), 403, array( 'error' => 'tenant_inactive' ) ),
			array( new ApiException( 429, 'rate_limit_exceeded', 'Slow down', array(), 30 ), 429, array( 'error' => 'rate_limited', 'retry_after' => 30 ) ),
			array( new ApiException( 429, 'rate_limit_exceeded', 'Slow down' ), 429, array( 'error' => 'rate_limited', 'retry_after' => null ) ),
			array( new ApiException( 403, 'forbidden', 'No' ), 502, array( 'error' => 'engine_unavailable' ) ),
			array( new ApiException( 400, 'validation_failed', 'Bad email' ), 502, array( 'error' => 'engine_unavailable' ) ),
			array( new ApiException( 0, 'network_error', 'Timed out' ), 502, array( 'error' => 'engine_unavailable' ) ),
		);
		foreach ( $cases as list( $exception, $status, $body ) ) {
			$response = $this->endpoint( new FakeRecEngineSettings(), $this->stub_client( null, $exception ) )->open( $this->request() );

			self::assertSame( $status, $response->get_status(), $exception->error_code() );
			self::assertSame( $body, $response->get_data(), $exception->error_code() );
			self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
			self::assertStringNotContainsString( 'sk_', (string) json_encode( $response->get_data() ) );
		}
	}

	public function test_the_console_address_comes_from_the_stored_engine_origin(): void {
		self::assertSame( 'https://intelligence.smaily.com/login', ConsoleEntryEndpoint::console_url( 'https://intelligence.smaily.com' ) );
		self::assertSame( 'http://127.0.0.1:9876/login', ConsoleEntryEndpoint::console_url( 'http://127.0.0.1:9876/' ) );
		self::assertSame( '', ConsoleEntryEndpoint::console_url( '' ) );
		self::assertSame( '', ConsoleEntryEndpoint::console_url( 'javascript:alert(1)' ) );

		$response = $this->endpoint( new FakeRecEngineSettings( true, false, 'sk_unit', '' ), $this->stub_client() )->open( $this->request() );
		self::assertSame( 503, $response->get_status(), 'No stored origin: no engine call.' );
	}

	private function request( string $nonce = 'nonce' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', $nonce );
		return $request;
	}

	/**
	 * @param array<string, mixed>|null $canned
	 */
	private function stub_client( ?array $canned = null, ?ApiException $throws = null ): Client {
		return new class( $canned ?? array(), $throws ) extends Client {
			/** @var array<int, string> Every email the endpoint sent. */
			public array $received = array();

			/** @var array<string, mixed> */
			private array $canned;

			private ?ApiException $throws;

			/** @param array<string, mixed> $canned */
			public function __construct( array $canned, ?ApiException $throws ) {
				parent::__construct( 'sk_unit', 'https://engine.unit' );
				$this->canned = $canned;
				$this->throws = $throws;
			}

			public function console_entry( string $email ): array {
				$this->received[] = $email;
				if ( $this->throws !== null ) {
					throw $this->throws;
				}
				return $this->canned;
			}
		};
	}

	private function endpoint( RecEngineSettings $settings, Client $client ): ConsoleEntryEndpoint {
		return new ConsoleEntryEndpoint(
			$settings,
			function () use ( $client ): Client {
				$this->factory_called = true;
				return $client;
			}
		);
	}
}
