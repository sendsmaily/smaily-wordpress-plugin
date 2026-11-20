<?php
/**
 * OnboardingEndpoint (contract §21, CON-175) — the start's body, the
 * engine refusals mapped to codes, the start link's host check, the frame
 * decision, and the status read that anything but `start_open: true` makes
 * false. The real HTTP path is in the integration suite (OnboardingTest).
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\OnboardingEndpoint;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class OnboardingEndpointTest extends TestCase {

	private const START_URL = 'https://intelligence.smaily.com/onboarding/start/f/AQx#t=AQ3f';

	/** @var array<string, mixed> */
	private array $transients = array();

	private string $locale = 'en_US';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		$this->locale     = 'en_US';
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'admin_url' )->justReturn( 'https://shop.example/wp-admin/' );
		Functions\when( 'get_user_locale' )->alias( fn () => $this->locale );
		Functions\when( 'is_email' )->alias(
			static fn ( string $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : false
		);
		Functions\when( 'wp_get_current_user' )->alias(
			static function () {
				$user             = new \stdClass();
				$user->user_email = 'owner@shop.example';
				return $user;
			}
		);
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_start_sends_the_main_account_login_and_answers_the_engine_link(): void {
		$client = $this->stub_client( array( 'start_url' => self::START_URL, 'display' => 'frame' ) );

		$response = $this->endpoint( $client )->start( $this->request( 'frame' ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'start_url'     => self::START_URL,
				'display'       => 'frame',
				'engine_origin' => 'https://intelligence.smaily.com',
			),
			$response->get_data()
		);
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		self::assertSame(
			array(
				array(
					'shop_url'         => 'https://shop.example',
					'platform'         => 'woocommerce',
					'smaily_subdomain' => 'myshop',
					'smaily_username'  => 'api-user',
					'smaily_password'  => 'secret',
					'language'         => 'en',
					'display'          => 'frame',
					'admin_email'      => 'owner@shop.example',
				),
			),
			$client->starts
		);
	}

	public function test_an_estonian_admin_gets_the_window_in_estonian(): void {
		$this->locale = 'et';
		$client       = $this->stub_client( array( 'start_url' => self::START_URL ) );

		$this->endpoint( $client )->start( $this->request( 'window' ) );

		self::assertSame( 'et', $client->starts[0]['language'] );
		self::assertArrayNotHasKey( 'display', $client->starts[0] );
	}

	public function test_a_frame_only_when_asked_for_and_the_engine_says_so(): void {
		$cases = array(
			array( 'frame', array( 'start_url' => self::START_URL, 'display' => 'frame' ), 'frame' ),
			array( 'frame', array( 'start_url' => self::START_URL ), 'window' ),
			array( 'window', array( 'start_url' => self::START_URL, 'display' => 'frame' ), 'window' ),
		);
		foreach ( $cases as list( $asked, $answer, $display ) ) {
			$response = $this->endpoint( $this->stub_client( $answer ) )->start( $this->request( $asked ) );

			self::assertSame( $display, $response->get_data()['display'] );
		}
	}

	public function test_a_start_link_off_the_engine_host_is_refused(): void {
		foreach ( array( 'https://evil.example/onboarding/start#t=x', 'javascript:alert(1)', '' ) as $url ) {
			$response = $this->endpoint( $this->stub_client( array( 'start_url' => $url ) ) )->start( $this->request( 'frame' ) );

			self::assertSame( 502, $response->get_status(), $url );
			self::assertSame( array( 'error' => 'start_failed' ), $response->get_data() );
		}
	}

	public function test_engine_refusals_map_to_codes_without_the_engine_message(): void {
		$cases = array(
			array( new ApiException( 400, 'frame_origin_not_allowed', 'Not https' ), 400, array( 'error' => 'frame_origin_not_allowed' ) ),
			array( new ApiException( 400, 'validation_failed', 'Bad shop_url' ), 502, array( 'error' => 'start_failed' ) ),
			array( new ApiException( 401, 'smaily_login_refused', 'Refused for api-user' ), 401, array( 'error' => 'smaily_login_refused' ) ),
			array( new ApiException( 403, 'smaily_paid_package_required', 'Code 227' ), 403, array( 'error' => 'smaily_paid_package_required' ) ),
			array( new ApiException( 403, 'forbidden', 'No' ), 502, array( 'error' => 'start_failed' ) ),
			array( new ApiException( 404, 'not_found', 'Not found' ), 404, array( 'error' => 'not_found' ) ),
			array(
				new ApiException( 409, 'store_exists', 'Has a store', array( 'console_url' => 'https://intelligence.smaily.com/console/t1/home' ) ),
				409,
				array(
					'error'       => 'store_exists',
					'console_url' => 'https://intelligence.smaily.com/console/t1/home',
				),
			),
			array( new ApiException( 409, 'store_exists', 'Has a store', array( 'console_url' => 'https://evil.example/c' ) ), 409, array( 'error' => 'store_exists' ) ),
			array( new ApiException( 429, 'rate_limit_exceeded', 'Slow down' ), 429, array( 'error' => 'rate_limited' ) ),
			array( new ApiException( 502, 'smaily_unavailable', 'Smaily down' ), 502, array( 'error' => 'unavailable' ) ),
			array( new ApiException( 0, 'network_error', 'Timed out' ), 502, array( 'error' => 'unavailable' ) ),
		);
		foreach ( $cases as list( $exception, $status, $body ) ) {
			$this->transients = array();

			$response = $this->endpoint( $this->stub_client( null, $exception ) )->start( $this->request( 'frame' ) );

			self::assertSame( $status, $response->get_status(), $exception->error_code() );
			self::assertSame( $body, $response->get_data(), $exception->error_code() );
		}
	}

	public function test_a_404_closes_the_start_in_the_cached_status(): void {
		$this->endpoint( $this->stub_client( null, new ApiException( 404, 'not_found', 'Not found' ) ) )->start( $this->request( 'frame' ) );

		self::assertSame( '0', $this->transients[ OnboardingEndpoint::STATUS_TRANSIENT ] );
	}

	public function test_no_engine_call_when_connected_or_without_a_smaily_account(): void {
		$client = $this->stub_client( array( 'start_url' => self::START_URL ) );

		$connected = $this->endpoint( $client, new FakeRecEngineSettings( true ) )->start( $this->request( 'frame' ) );
		self::assertSame( array( 'error' => 'already_connected' ), $connected->get_data() );

		$no_account = $this->endpoint( $client, null, false )->start( $this->request( 'frame' ) );
		self::assertSame( array( 'error' => 'smaily_login_refused' ), $no_account->get_data() );

		self::assertSame( array(), $client->starts );
	}

	public function test_the_status_is_true_only_on_start_open_true_and_is_cached(): void {
		$cases = array(
			array( array( 'start_open' => true ), null, true ),
			array( array( 'start_open' => false ), null, false ),
			array( array( 'start_open' => 'true' ), null, false ),
			array( array(), null, false ),
			array( null, new ApiException( 0, 'network_error', 'Timed out' ), false ),
			array( null, new ApiException( 500, 'internal_error', 'Down' ), false ),
		);
		foreach ( $cases as list( $answer, $throws, $open ) ) {
			$this->transients = array();
			$client           = $this->stub_client( null, $throws, $answer ?? array() );

			$data = $this->endpoint( $client )->status( new WP_REST_Request() )->get_data();

			self::assertSame( $open, $data['start_open'] );
			self::assertTrue( $data['frame'] );
			self::assertSame( $open ? '1' : '0', $this->transients[ OnboardingEndpoint::STATUS_TRANSIENT ] );

			$this->endpoint( $client )->status( new WP_REST_Request() );
			self::assertSame( 1, $client->status_calls, 'The second read comes from the cache.' );
		}
	}

	public function test_an_http_shop_is_not_framed(): void {
		Functions\when( 'home_url' )->justReturn( 'http://shop.example' );
		Functions\when( 'admin_url' )->justReturn( 'http://shop.example/wp-admin/' );

		self::assertFalse( $this->endpoint( $this->stub_client() )->status( new WP_REST_Request() )->get_data()['frame'] );
	}

	private function request( string $display ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'display', $display );
		return $request;
	}

	/**
	 * @param array<string, mixed>|null $start  The start answer.
	 * @param array<string, mixed>      $status The status answer.
	 */
	private function stub_client( ?array $start = null, ?ApiException $throws = null, array $status = array() ): Client {
		return new class( $start ?? array(), $throws, $status ) extends Client {
			/** @var array<int, array<string, string>> Every start body sent. */
			public array $starts = array();

			public int $status_calls = 0;

			/** @var array<string, mixed> */
			private array $start;

			private ?ApiException $throws;

			/** @var array<string, mixed> */
			private array $status;

			/**
			 * @param array<string, mixed> $start
			 * @param array<string, mixed> $status
			 */
			public function __construct( array $start, ?ApiException $throws, array $status ) {
				parent::__construct( '', 'https://intelligence.smaily.com', array(), 1, new FakeRecEngineSettings( false ) );
				$this->start  = $start;
				$this->throws = $throws;
				$this->status = $status;
			}

			public function onboarding_start( array $body ): array {
				$this->starts[] = $body;
				if ( $this->throws !== null ) {
					throw $this->throws;
				}
				return $this->start;
			}

			public function onboarding_status(): array {
				++$this->status_calls;
				if ( $this->throws !== null ) {
					throw $this->throws;
				}
				return $this->status;
			}
		};
	}

	private function endpoint( Client $client, ?FakeRecEngineSettings $settings = null, bool $with_account = true ): OnboardingEndpoint {
		return new OnboardingEndpoint(
			$settings ?? new FakeRecEngineSettings( false ),
			static fn (): ?CredentialSet => $with_account ? new CredentialSet( 'MyShop', 'api-user', 'secret' ) : null,
			static fn (): Client => $client
		);
	}
}
