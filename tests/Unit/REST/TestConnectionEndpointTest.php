<?php
/**
 * TestConnectionEndpoint tests.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\TestConnectionEndpoint;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\RefusalReason;
use WP_REST_Request;

final class TestConnectionEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( 'x' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_permission_check_denies_users_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$endpoint = new TestConnectionEndpoint();
		$result   = $endpoint->permission_check( new WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_check_passes_for_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		self::assertTrue(
			( new TestConnectionEndpoint() )->permission_check( new WP_REST_Request() )
		);
	}

	public function test_register_calls_register_rest_route_with_post_method_and_subdomain_args(): void {
		$captured_args = null;
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$captured_args ): bool {
				$captured_args = compact( 'namespace', 'route', 'args' );
				return true;
			}
		);

		( new TestConnectionEndpoint() )->register();

		self::assertNotNull( $captured_args );
		self::assertSame( 'smaily-connect/v1', $captured_args['namespace'] );
		self::assertSame( '/test-smaily', $captured_args['route'] );
		self::assertSame( 'POST', $captured_args['args']['methods'] );
		self::assertArrayHasKey( 'subdomain', $captured_args['args']['args'] );
		self::assertArrayHasKey( 'username', $captured_args['args']['args'] );
		self::assertArrayHasKey( 'password', $captured_args['args']['args'] );
		self::assertTrue( $captured_args['args']['args']['subdomain']['required'] );
		// PRO-2286: an upgraded store tests with the password field empty.
		self::assertFalse( $captured_args['args']['args']['password']['required'] );
	}

	public function test_handle_returns_connected_true_when_client_validates_credentials(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', 's3cret' );

		$endpoint = $this->endpoint_with_client( RefusalReason::OK );
		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['connected'] );
		self::assertNull( $response->get_data()['error'] );
	}

	public function test_handle_returns_connected_false_when_credentials_rejected(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', 'wrong' );

		$endpoint = $this->endpoint_with_client( RefusalReason::CREDENTIALS_REJECTED );
		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertFalse( $response->get_data()['connected'] );
		$error = (string) $response->get_data()['error'];
		self::assertStringContainsString( 'credentials', $error );
		self::assertStringNotContainsString( 'package', $error, 'Wrong credentials must not be blamed on the plan.' );
	}

	public function test_handle_blames_the_package_when_that_is_what_smaily_said(): void {
		// PRO-1686: a freemium account refuses every endpoint with 403 {"code":227}.
		// The test must say so — the credentials were never the problem.
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', 's3cret' );

		$endpoint = $this->endpoint_with_client( RefusalReason::PLAN_BLOCKED );
		$response = $endpoint->handle( $request );

		self::assertFalse( $response->get_data()['connected'] );
		$error = (string) $response->get_data()['error'];
		self::assertStringContainsString( 'package', $error );
		self::assertStringNotContainsString( 'could not be reached', $error );
	}

	public function test_handle_says_unreachable_when_smaily_never_answered(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', 's3cret' );

		$endpoint = $this->endpoint_with_client( RefusalReason::UNREACHABLE );
		$response = $endpoint->handle( $request );

		self::assertFalse( $response->get_data()['connected'] );
		$error = (string) $response->get_data()['error'];
		self::assertStringContainsString( 'could not be reached', $error );
		self::assertStringNotContainsString( 'package', $error );
	}

	public function test_handle_short_circuits_when_required_field_empty(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', '' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', 's3cret' );

		// No Client should ever be built — assert by failing the factory.
		$endpoint = new class extends TestConnectionEndpoint {
			protected function build_client( string $subdomain, string $username, string $password ): Client {
				throw new \RuntimeException( 'Client must not be built when fields are empty' );
			}
		};

		$response = $endpoint->handle( $request );

		self::assertFalse( $response->get_data()['connected'] );
		self::assertNotNull( $response->get_data()['error'] );
	}

	public function test_handle_reuses_the_stored_password_when_none_was_typed(): void {
		// PRO-2286: the upgrade path. The wizard never puts the stored
		// secret in the browser, so Step 1 must be verifiable without it.
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', '' );

		$endpoint = $this->endpoint_with_client(
			RefusalReason::OK,
			new CredentialSet( 'demo', 'alice', 'stored-secret' )
		);
		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['connected'] );
		self::assertSame( 'stored-secret', $endpoint->last_password_used() );
		self::assertArrayNotHasKey( 'password', $response->get_data() );
	}

	public function test_handle_reports_failure_when_the_stored_password_no_longer_works(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', '' );

		$endpoint = $this->endpoint_with_client(
			RefusalReason::CREDENTIALS_REJECTED,
			new CredentialSet( 'demo', 'alice', 'stale-secret' )
		);
		$response = $endpoint->handle( $request );

		self::assertFalse( $response->get_data()['connected'] );
		self::assertStringContainsString( 'credentials', (string) $response->get_data()['error'] );
	}

	public function test_handle_asks_for_the_password_when_the_stored_account_is_a_different_one(): void {
		// Typing another subdomain/username means another account is being
		// tested — the stored password must not vouch for it.
		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'other' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', '' );

		$endpoint = $this->endpoint_with_client(
			RefusalReason::OK,
			new CredentialSet( 'demo', 'alice', 'stored-secret' )
		);
		$response = $endpoint->handle( $request );

		self::assertFalse( $response->get_data()['connected'] );
		self::assertStringContainsString( 'required', (string) $response->get_data()['error'] );
		self::assertSame(
			'',
			$endpoint->last_password_used(),
			'No Client may be built for a mismatching account — the recorder stays untouched.'
		);
	}

	public function test_handle_asks_for_the_password_when_nothing_is_stored(): void {
		// Real Credentials reader over an empty option — the fresh-install
		// path, where there is no stored secret to fall back on.
		Functions\when( 'get_option' )->justReturn( array() );

		$request = new WP_REST_Request();
		$request->set_param( 'subdomain', 'demo' );
		$request->set_param( 'username', 'alice' );
		$request->set_param( 'password', '' );

		$endpoint = new class extends TestConnectionEndpoint {
			protected function build_client( string $subdomain, string $username, string $password ): Client {
				throw new \RuntimeException( 'Client must not be built without a password' );
			}
		};

		$response = $endpoint->handle( $request );

		self::assertFalse( $response->get_data()['connected'] );
		self::assertStringContainsString( 'required', (string) $response->get_data()['error'] );
	}

	private function endpoint_with_client( string $connection_result, ?CredentialSet $stored = null ): TestConnectionEndpoint {
		$client = $this->createMock( Client::class );
		$client->method( 'check_connection' )->willReturn( $connection_result );

		return new class( $client, $stored ) extends TestConnectionEndpoint {
			private Client $injected;
			private ?CredentialSet $stored;
			private string $password_used = '';
			public function __construct( Client $injected, ?CredentialSet $stored ) {
				$this->injected = $injected;
				$this->stored   = $stored;
			}
			/** The password the endpoint actually authenticated with. */
			public function last_password_used(): string {
				return $this->password_used;
			}
			protected function stored_credentials(): ?CredentialSet {
				return $this->stored;
			}
			protected function build_client( string $subdomain, string $username, string $password ): Client {
				$this->password_used = $password;
				return $this->injected;
			}
		};
	}
}
