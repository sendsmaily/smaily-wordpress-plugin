<?php
/**
 * WorkflowsEndpoint tests — credential lookup + Smaily-side fetch +
 * response normalisation.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\WorkflowsEndpoint;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;
use WP_REST_Request;

final class WorkflowsEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_empty_list_when_account_credentials_missing(): void {
		$credentials = $this->createMock( Credentials::class );
		$credentials->method( 'get' )->willReturn( null );

		$endpoint = new WorkflowsEndpoint( $credentials, static function (): Client {
			TestCase::fail( 'Client factory must not run when credentials are absent.' );
		} );

		$request = new WP_REST_Request();
		$request->set_param( 'account_key', 'default' );

		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), $response->get_data()['workflows'] );
		self::assertNotEmpty( $response->get_data()['error'] );
	}

	public function test_returns_normalised_workflows_on_smaily_success(): void {
		$set = new CredentialSet( 'demo', 'alice', 's3cret' );

		$credentials = $this->createMock( Credentials::class );
		$credentials->method( 'get' )->willReturn( $set );

		$client = $this->createMock( Client::class );
		$client->method( 'list_autoresponders' )->willReturn( array(
			array( 'id' => 42, 'name' => 'Welcome series', 'status' => 'ACTIVE' ),
			array( 'id' => 99, 'name' => 'Cart reminder', 'status' => 'INACTIVE' ),
			array( 'id' => 0,  'name' => 'Should be dropped' ),     // missing id
			'not an array',                                          // junk row
		) );

		$endpoint = new WorkflowsEndpoint( $credentials, static fn (): Client => $client );

		$request = new WP_REST_Request();
		$request->set_param( 'account_key', 'default' );

		$response = $endpoint->handle( $request );
		$data     = $response->get_data();

		self::assertCount( 2, $data['workflows'], 'Junk + zero-id rows must be filtered out.' );
		self::assertSame( '42', $data['workflows'][0]['id'] );
		self::assertSame( 'Welcome series', $data['workflows'][0]['name'] );
		self::assertSame( 'ACTIVE', $data['workflows'][0]['status'] );
		self::assertSame( 'INACTIVE', $data['workflows'][1]['status'] );
	}

	public function test_drops_workflows_without_a_name(): void {
		// Sub-PR 2.H.14 — Smaily's /api/autoresponder.php always returns
		// `name`; a row missing it indicates either a broken upstream
		// response or a different endpoint. Either way the dropdown
		// can't surface a meaningful label, so we drop the row instead
		// of falling back to `#{id}` (which previously misled Erkki
		// into thinking #3 / #4 were the real workflow names).
		$set         = new CredentialSet( 'demo', 'alice', 's3cret' );
		$credentials = $this->createMock( Credentials::class );
		$credentials->method( 'get' )->willReturn( $set );

		$client = $this->createMock( Client::class );
		$client->method( 'list_autoresponders' )->willReturn(
			array(
				array( 'id' => 7 ),                       // no name → dropped
				array( 'id' => 8, 'name' => '   ' ),       // whitespace-only → dropped
				array( 'id' => 9, 'name' => 'Real name' ), // kept
			),
		);

		$endpoint = new WorkflowsEndpoint( $credentials, static fn (): Client => $client );

		$request = new WP_REST_Request();
		$request->set_param( 'account_key', 'default' );

		$data = $endpoint->handle( $request )->get_data();

		self::assertCount( 1, $data['workflows'] );
		self::assertSame( 'Real name', $data['workflows'][0]['name'] );
	}

	public function test_returns_error_message_when_smaily_throws(): void {
		$set         = new CredentialSet( 'demo', 'alice', 's3cret' );
		$credentials = $this->createMock( Credentials::class );
		$credentials->method( 'get' )->willReturn( $set );

		$client = $this->createMock( Client::class );
		$client->method( 'list_autoresponders' )
			->willThrowException( new ApiException( 'Smaily rejected auth', 401 ) );

		$endpoint = new WorkflowsEndpoint( $credentials, static fn (): Client => $client );

		$request = new WP_REST_Request();
		$request->set_param( 'account_key', 'default' );

		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), $response->get_data()['workflows'] );
		self::assertStringContainsString( 'Smaily rejected auth', $response->get_data()['error'] );
	}

	public function test_permission_check_denies_non_admins(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$credentials = $this->createMock( Credentials::class );
		$endpoint    = new WorkflowsEndpoint( $credentials, static fn (): Client => $this->createMock( Client::class ) );

		$result = $endpoint->permission_check( new WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_register_wires_route_with_account_key_arg(): void {
		$captured_args = null;
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$captured_args ): bool {
				$captured_args = compact( 'namespace', 'route', 'args' );
				return true;
			}
		);

		$credentials = $this->createMock( Credentials::class );
		( new WorkflowsEndpoint( $credentials, static fn (): Client => $this->createMock( Client::class ) ) )->register();

		self::assertSame( 'smaily-connect/v1', $captured_args['namespace'] );
		self::assertSame( '/workflows', $captured_args['route'] );
		self::assertSame( 'GET', $captured_args['args']['methods'] );
		self::assertArrayHasKey( 'account_key', $captured_args['args']['args'] );
		self::assertSame( 'default', $captured_args['args']['args']['account_key']['default'] );
	}
}
