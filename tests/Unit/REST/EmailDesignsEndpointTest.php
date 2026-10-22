<?php
/**
 * EmailDesignsEndpoint — the sending gate, what the §22 request carries
 * (the admin's language; a return URL only on the store's https host), the
 * one-time link's answer, and the §23 read that stores the workflow ids. The
 * real HTTP path is in the integration suite (EmailDesignsTest).
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\EmailDesignsEndpoint;
use Smaily\Connect\Settings\EmailDesignWorkflows;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class EmailDesignsEndpointTest extends TestCase {

	private bool $factory_called = false;

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'get_user_locale' )->justReturn( 'et_EE' );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			fn ( string $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		$this->factory_called = false;
		$this->options        = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_engine_call_unless_sending_is_allowed(): void {
		foreach ( array( new FakeRecEngineSettings( false ), new FakeRecEngineSettings( true, true ), new FakeRecEngineSettings( true, false, '' ) ) as $settings ) {
			$endpoint = $this->endpoint( $settings, $this->stub_client() );

			self::assertSame( 503, $endpoint->designs( new WP_REST_Request() )->get_status() );
			self::assertSame( 'not_available', $endpoint->designs( new WP_REST_Request() )->get_data()['error'] );
			self::assertSame( 503, $endpoint->design_link( $this->link_request( 'plugin.welcome' ) )->get_status() );
		}

		self::assertFalse( $this->factory_called );
	}

	public function test_design_link_sends_the_admin_language_and_a_return_url_on_the_store_host(): void {
		$client   = $this->stub_client( array( 'url' => 'https://engine.unit/design/email/dl_1', 'expires_at' => '2026-10-23T08:05:00.000Z' ) );
		$endpoint = $this->endpoint( new FakeRecEngineSettings(), $client );

		$response = $endpoint->design_link(
			$this->link_request( 'plugin.abandoned_cart', 'https://shop.example/wp-admin/admin.php?page=smaily-connect-settings' )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'https://engine.unit/design/email/dl_1', $response->get_data()['url'] );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		self::assertSame( 'plugin.abandoned_cart', $client->received['email_key'] );
		self::assertSame(
			array(
				'return_url' => 'https://shop.example/wp-admin/admin.php?page=smaily-connect-settings',
				'language'   => 'et',
			),
			$client->received['body']
		);
	}

	public function test_design_link_leaves_out_a_return_url_the_engine_would_refuse(): void {
		foreach ( array( 'http://shop.example/wp-admin/', 'https://elsewhere.example/wp-admin/', 'javascript:alert(1)', 'https://shop.example/' . str_repeat( 'a', 2048 ), '' ) as $return_url ) {
			$client = $this->stub_client( array( 'url' => 'https://engine.unit/design/email/dl_1', 'expires_at' => 'x' ) );

			$this->endpoint( new FakeRecEngineSettings(), $client )->design_link( $this->link_request( 'plugin.welcome', $return_url ) );

			self::assertArrayNotHasKey( 'return_url', $client->received['body'], $return_url );
		}
	}

	public function test_design_link_leaves_out_a_language_that_is_not_a_two_letter_code(): void {
		Functions\when( 'get_user_locale' )->justReturn( 'fur' );
		$client = $this->stub_client( array( 'url' => 'https://engine.unit/design/email/dl_1', 'expires_at' => 'x' ) );

		$this->endpoint( new FakeRecEngineSettings(), $client )->design_link( $this->link_request( 'plugin.welcome' ) );

		self::assertSame( array(), $client->received['body'] );
	}

	public function test_design_link_refuses_an_engine_answer_that_is_not_a_web_link(): void {
		$client = $this->stub_client( array( 'url' => 'javascript:alert(1)', 'expires_at' => 'x' ) );

		$response = $this->endpoint( new FakeRecEngineSettings(), $client )->design_link( $this->link_request( 'plugin.welcome' ) );

		self::assertSame( 502, $response->get_status() );
		self::assertArrayNotHasKey( 'url', $response->get_data() );
	}

	public function test_engine_errors_map_to_answers_without_the_key(): void {
		$cases = array(
			array( new ApiException( 404, 'unknown_email', 'No such email' ), 404, 'unknown_email' ),
			array( new ApiException( 404, 'not_found', 'No route' ), 404, 'not_available' ),
			array( new ApiException( 401, 'unauthorised', 'Bad key' ), 502, 'api_key_rejected' ),
			array( new ApiException( 422, 'validation_failed', 'Invalid', array( 'errors' => array( array( 'field' => 'return_url' ) ) ) ), 422, 'validation_failed' ),
			array( new ApiException( 429, 'rate_limit_exceeded', 'Slow down' ), 502, 'rate_limit_exceeded' ),
		);
		foreach ( $cases as list( $exception, $status, $error ) ) {
			$response = $this->endpoint( new FakeRecEngineSettings(), $this->stub_client( null, $exception ) )
				->design_link( $this->link_request( 'plugin.welcome' ) );

			self::assertSame( $status, $response->get_status(), $error );
			self::assertSame( $error, $response->get_data()['error'] );
			self::assertStringNotContainsString( 'sk_unit', (string) wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_designs_passes_the_list_through_and_stores_the_workflow_ids(): void {
		$body = array(
			'emails' => array(
				array(
					'email_key'          => 'plugin.welcome',
					'kind'               => 'plugin',
					'smaily_state'       => 'pushed',
					'smaily_workflow_id' => '456',
				),
			),
		);

		$response = $this->endpoint( new FakeRecEngineSettings(), $this->stub_client( $body ) )->designs( new WP_REST_Request() );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( $body, $response->get_data() );
		self::assertSame( 456, ( new EmailDesignWorkflows() )->workflow_id( 'welcome' ) );
	}

	public function test_a_failed_designs_read_keeps_the_stored_ids(): void {
		$this->options[ EmailDesignWorkflows::OPTION ] = array( 'plugin.welcome' => 456 );

		$response = $this->endpoint( new FakeRecEngineSettings(), $this->stub_client( null, new ApiException( 404, 'not_found', 'No route' ) ) )
			->designs( new WP_REST_Request() );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 456, ( new EmailDesignWorkflows() )->workflow_id( 'welcome' ) );
	}

	private function link_request( string $email_key, ?string $return_url = null ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'email_key', $email_key );
		if ( $return_url !== null ) {
			$request->set_param( 'return_url', $return_url );
		}
		return $request;
	}

	/**
	 * @param array<string, mixed>|null $canned
	 */
	private function stub_client( ?array $canned = null, ?ApiException $throws = null ): Client {
		return new class( $canned ?? array(), $throws ) extends Client {
			/** @var array<string, mixed> */
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

			public function email_design_link( string $email_key, array $body ): array {
				$this->received = array(
					'email_key' => $email_key,
					'body'      => $body,
				);
				return $this->reply();
			}

			public function email_designs(): array {
				return $this->reply();
			}

			/** @return array<string, mixed> */
			private function reply(): array {
				if ( $this->throws !== null ) {
					throw $this->throws;
				}
				return $this->canned;
			}
		};
	}

	private function endpoint( RecEngineSettings $settings, Client $client ): EmailDesignsEndpoint {
		return new EmailDesignsEndpoint(
			$settings,
			new EmailDesignWorkflows(),
			function () use ( $client ): Client {
				$this->factory_called = true;
				return $client;
			}
		);
	}
}
