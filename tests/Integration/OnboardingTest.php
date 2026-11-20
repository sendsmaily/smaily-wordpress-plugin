<?php
/**
 * Integration: "Try Intelligence free" (contract §21, CON-175) against the
 * mock engine — the status read, the start and the setup token's way into
 * the existing exchange.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\OnboardingEndpoint;
use Smaily\Connect\Report\ReportBuilder;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What this catches:
 *
 *   - The status is true only on `200 {start_open: true}`; false, an error,
 *     a connected store or a store without a Smaily account read false. The
 *     answer is kept for five minutes: one engine call for two reads.
 *   - The start sends §21's body with the main Smaily account's login and no
 *     key, answers the start link on the engine origin, and the password is
 *     in no REST answer, option, queue row or log line.
 *   - Each engine refusal gets its code — never the engine's message — and a
 *     404 closes the start for the next status read.
 *   - The setup link the start window posts goes through the existing
 *     setup-exchange route: the store connects and the imports start.
 *   - Routes refuse a request without a nonce or the capability before any
 *     engine call; the plugin keeps its own 10-per-minute ceiling.
 *
 * The endpoint under test is built on the mock engine; the registered route
 * points at the real engine host, so only its refusals are dispatched here.
 */
final class OnboardingTest extends TestCase {

	private const PASSWORD = 'onboard-secret-9f2c';

	private static ?RecEngineMockServer $engine = null;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->forget_transients();
		RestRequestHelper::login_as_admin();
		update_option(
			Credentials::LEGACY_OPTION_KEY,
			array(
				'subdomain' => 'myshop',
				'username'  => 'api-user',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( self::PASSWORD ),
			)
		);
	}

	protected function tearDown(): void {
		$this->forget_transients();
		ImportsOnConnect::forget();
		HistoryProgress::forget();
		parent::tearDown();
	}

	public function test_the_status_is_true_only_when_the_engine_says_so_and_is_kept_five_minutes(): void {
		self::$engine->set_onboarding_open( true );

		self::assertTrue( $this->status()->get_data()['start_open'] );
		self::assertTrue( $this->status()->get_data()['start_open'] );
		self::assertSame( 1, self::$engine->onboarding_status_count(), 'The second read comes from the cache.' );
		self::assertSame( '1', get_transient( OnboardingEndpoint::STATUS_TRANSIENT ) );
	}

	public function test_false_and_an_engine_error_read_false(): void {
		foreach ( array( false, 'error' ) as $answer ) {
			delete_transient( OnboardingEndpoint::STATUS_TRANSIENT );
			self::$engine->set_onboarding_open( $answer );

			self::assertFalse( $this->status()->get_data()['start_open'], 'Engine answer: ' . var_export( $answer, true ) );
		}
	}

	public function test_a_connected_store_or_one_without_a_smaily_account_asks_nothing(): void {
		self::$engine->set_onboarding_open( true );
		delete_option( Credentials::LEGACY_OPTION_KEY );
		self::assertFalse( $this->status()->get_data()['start_open'] );

		$this->exchange( self::$engine->setup_url( 'tok_onboarding_connected' ) );
		self::assertFalse( $this->status()->get_data()['start_open'] );

		self::assertSame( 0, self::$engine->onboarding_status_count() );
	}

	public function test_the_start_sends_the_main_account_login_and_the_password_stays_on_the_server(): void {
		$log = (string) tempnam( sys_get_temp_dir(), 'smly-onboarding-log' );
		$old = ini_set( 'error_log', $log );
		try {
			$response = $this->start( 'frame' );
		} finally {
			ini_set( 'error_log', (string) $old );
		}

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertStringStartsWith( self::$engine->base_url() . '/onboarding/start/f/', $data['start_url'] );
		self::assertSame( 'frame', $data['display'] );
		self::assertSame( self::$engine->base_url(), $data['engine_origin'] );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		$requests = self::$engine->onboarding_start_requests();
		self::assertCount( 1, $requests );
		self::assertNull( $requests[0]['authorization'], 'The start carries no key.' );
		$body = $requests[0]['body'];
		self::assertSame( (string) home_url(), $body['shop_url'] );
		self::assertSame( 'woocommerce', $body['platform'] );
		self::assertSame( 'myshop', $body['smaily_subdomain'] );
		self::assertSame( 'api-user', $body['smaily_username'] );
		self::assertSame( self::PASSWORD, $body['smaily_password'] );
		self::assertSame( 'frame', $body['display'] );
		self::assertSame( 'en', $body['language'] );
		self::assertSame( (string) wp_get_current_user()->user_email, $body['admin_email'] );
		self::assertArrayNotHasKey( 'frame_origin', $body );
		self::assertArrayNotHasKey( 'opener_origin', $body );

		self::assertStringNotContainsString( self::PASSWORD, (string) wp_json_encode( $data ) );
		self::assertSame( 0, $this->options_holding( self::PASSWORD ), 'No option or transient holds the password.' );
		self::assertSame( 0, $this->queue_rows_holding( self::PASSWORD ), 'No queue row holds the password.' );
		self::assertStringNotContainsString( self::PASSWORD, (string) file_get_contents( $log ), 'No log line holds the password.' );
		unlink( $log );
	}

	public function test_a_window_start_or_an_engine_without_the_frame_mode_opens_a_window(): void {
		self::assertSame( 'window', $this->start( 'window' )->get_data()['display'] );
		self::assertArrayNotHasKey( 'display', self::$engine->onboarding_start_requests()[0]['body'] );

		self::$engine->set_onboarding_frame( false );
		self::assertSame( 'window', $this->start( 'frame' )->get_data()['display'] );
	}

	public function test_each_refusal_answers_its_code_and_never_the_engine_message(): void {
		$cases = array(
			400 => array( 400, 'frame_origin_not_allowed' ),
			401 => array( 401, 'smaily_login_refused' ),
			403 => array( 403, 'smaily_paid_package_required' ),
			404 => array( 404, 'not_found' ),
			429 => array( 429, 'rate_limited' ),
			502 => array( 502, 'unavailable' ),
		);
		foreach ( $cases as $engine_status => list( $status, $error ) ) {
			self::$engine->set_onboarding_start_status( $engine_status );

			$response = $this->start( 'frame' );

			self::assertSame( $status, $response->get_status(), 'Engine ' . $engine_status );
			self::assertSame( array( 'error' => $error ), $response->get_data(), 'Engine ' . $engine_status );
			self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		}
	}

	public function test_a_store_that_exists_answers_the_console_only_on_the_engine_host(): void {
		self::$engine->set_onboarding_start_status( 409 );

		self::assertSame(
			array(
				'error'       => 'store_exists',
				'console_url' => self::$engine->base_url() . '/console/t_mock/home',
			),
			$this->start( 'frame' )->get_data()
		);

		self::$engine->set_onboarding_console_url( 'https://evil.example/console' );
		self::assertSame( array( 'error' => 'store_exists' ), $this->start( 'frame' )->get_data() );
	}

	public function test_a_404_closes_the_start_for_the_next_status_read(): void {
		self::$engine->set_onboarding_open( true );
		self::assertTrue( $this->status()->get_data()['start_open'] );

		self::$engine->set_onboarding_start_status( 404 );
		self::assertSame( 404, $this->start( 'frame' )->get_status() );

		self::assertFalse( $this->status()->get_data()['start_open'] );
		self::assertSame( 1, self::$engine->onboarding_status_count() );
	}

	public function test_the_setup_link_from_the_start_window_connects_through_the_existing_exchange(): void {
		self::assertSame( 200, $this->start( 'frame' )->get_status() );

		// What the start window posts: `{type: 'smaily_intelligence_setup',
		// setup_token, setup_url}` — the admin sends `setup_url` to the route
		// the paste field uses.
		$response = $this->exchange( self::$engine->setup_url( 'tok_onboarding_window' ) );

		self::assertTrue( $response->get_data()['connected'] );
		self::assertSame( 'started', $response->get_data()['catalogImport'] );
		self::assertTrue( ( new RecEngineSettings() )->is_connected() );
		wp_cache_delete( ImportsOnConnect::OPTION, 'options' );
		self::assertSame( array( 'products', 'customers', 'orders' ), array_values( (array) get_option( ImportsOnConnect::OPTION, array() ) ) );
		self::assertSame( 0, $this->options_holding( self::PASSWORD ) );

		self::assertSame( 409, $this->start( 'frame' )->get_status(), 'A connected store starts nothing.' );
		self::assertCount( 1, self::$engine->onboarding_start_requests() );
	}

	public function test_without_a_smaily_account_the_start_asks_nothing(): void {
		delete_option( Credentials::LEGACY_OPTION_KEY );

		self::assertSame( array( 'error' => 'smaily_login_refused' ), $this->start( 'frame' )->get_data() );
		self::assertSame( array(), self::$engine->onboarding_start_requests() );
	}

	public function test_the_plugin_keeps_its_own_ten_per_minute_ceiling(): void {
		self::$engine->set_onboarding_start_status( 502 );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->start( 'frame' );
		}

		self::assertSame( array( 'error' => 'rate_limited' ), $this->start( 'frame' )->get_data() );
		self::assertCount( 10, self::$engine->onboarding_start_requests() );
	}

	public function test_the_routes_refuse_a_request_without_a_nonce_or_the_capability(): void {
		foreach ( array( array( 'GET', OnboardingEndpoint::ROUTE_STATUS ), array( 'POST', OnboardingEndpoint::ROUTE_START ) ) as list( $method, $route ) ) {
			$request = new WP_REST_Request( $method, '/smaily-connect/v1' . $route );
			self::assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), $route . ' without a nonce' );

			wp_set_current_user( 0 );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			self::assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), $route . ' without a session' );
			wp_set_current_user( 1 );
		}
	}

	private function endpoint(): OnboardingEndpoint {
		return new OnboardingEndpoint(
			new RecEngineSettings(),
			static function () {
				return ( new ReportBuilder() )->main_credentials();
			},
			static function (): Client {
				return new Client( '', self::$engine->base_url(), array(), 1, new RecEngineSettings(), OnboardingEndpoint::START_TIMEOUT_SECONDS );
			}
		);
	}

	private function status(): WP_REST_Response {
		return $this->endpoint()->status( new WP_REST_Request( 'GET', '/smaily-connect/v1' . OnboardingEndpoint::ROUTE_STATUS ) );
	}

	private function start( string $display ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1' . OnboardingEndpoint::ROUTE_START );
		$request->set_param( 'display', $display );
		return $this->endpoint()->start( $request );
	}

	private function exchange( string $setup_url ): WP_REST_Response {
		$response = RestRequestHelper::post( '/rec-engine/setup-exchange', array( 'setup_url' => $setup_url ) );
		self::assertSame( 200, $response->get_status() );
		return $response;
	}

	private function forget_transients(): void {
		delete_transient( OnboardingEndpoint::STATUS_TRANSIENT );
		delete_transient( OnboardingEndpoint::THROTTLE_TRANSIENT );
	}

	private function options_holding( string $needle ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $needle ) . '%'
			)
		);
	}

	private function queue_rows_holding( string $needle ): int {
		global $wpdb;
		$like  = '%' . $wpdb->esc_like( $needle ) . '%';
		$count = 0;
		foreach ( array( 'smly_rec_event_queue', 'smly_plus_event_queue' ) as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			$count += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE payload LIKE %s OR sent_payload LIKE %s OR last_response LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like,
					$like,
					$like
				)
			);
		}
		return $count;
	}
}
