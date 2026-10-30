<?php
/**
 * Integration: "Open Campaign Intelligence" (contract §24, CON-228) through
 * the real REST route against the mock engine.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What this catches:
 *
 *   - Only a signed-in admin with a valid `wp_rest` nonce gets a link: no
 *     nonce, a wrong nonce or a user without `manage_options` is refused
 *     before any engine call; a GET finds no route.
 *   - One press = one §24 call, carrying the signed-in admin's own email and
 *     the stored key; the link reaches the admin with `no-store, private`.
 *   - Nothing keeps the link or the email: no option or transient, no queue
 *     row in either queue.
 *   - Each engine refusal gets its own answer: 404 (with the console address
 *     of the stored engine origin), 401, 403 (`tenant_inactive`, recorded as
 *     a refused account), 429 (with the engine's Retry-After).
 *   - No connection: no engine call.
 */
final class ConsoleEntryTest extends TestCase {

	private const ADMIN_EMAIL = 'console-admin@example.test';

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_set_current_user( 1 );
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_no_engine_call_without_a_connection(): void {
		$this->sign_in( 'administrator' );

		$response = $this->press();

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'not_available', $response->get_data()['error'] );
		self::assertSame( array(), self::$engine->console_entry_requests() );
	}

	public function test_a_signed_in_admin_gets_one_link_with_their_own_email_and_nothing_keeps_it(): void {
		$this->connect( 'tok_console_ok' );
		$this->sign_in( 'administrator' );
		$before = $this->stored_rows();

		$response = $this->press();

		self::assertSame( 200, $response->get_status() );
		$url = (string) $response->get_data()['url'];
		self::assertStringStartsWith( self::$engine->base_url() . '/console-entry#g=seg_', $url );
		self::assertNotSame( '', $response->get_data()['expires_at'] );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		self::assertSame( array( array( 'email' => self::ADMIN_EMAIL ) ), self::$engine->console_entry_requests(), 'One call with the admin\'s own email.' );

		$serialised = (string) wp_json_encode( $response->get_data() );
		self::assertStringNotContainsString( ( new RecEngineSettings() )->api_key(), $serialised );

		self::assertSame( $before['queues'], $this->stored_rows()['queues'], 'No queue row in either queue.' );
		self::assertSame( 0, $this->options_holding( $url ), 'No option or transient holds the link.' );
		self::assertSame( 0, $this->options_holding( self::ADMIN_EMAIL ), 'No option or transient holds the email.' );
	}

	public function test_each_press_mints_a_new_link(): void {
		$this->connect( 'tok_console_twice' );
		$this->sign_in( 'administrator' );

		$first  = $this->press()->get_data()['url'];
		$second = $this->press()->get_data()['url'];

		self::assertNotSame( $first, $second );
		self::assertCount( 2, self::$engine->console_entry_requests() );
	}

	public function test_no_nonce_a_wrong_nonce_or_no_capability_is_refused_before_the_engine(): void {
		$this->connect( 'tok_console_refused' );
		$this->sign_in( 'administrator' );

		self::assertSame( 403, $this->press( '' )->get_status(), 'No nonce.' );
		self::assertSame( 403, $this->press( 'not-a-nonce' )->get_status(), 'A wrong nonce.' );

		$this->sign_in( 'editor' );
		self::assertSame( 403, $this->press()->get_status(), 'No manage_options.' );

		wp_set_current_user( 0 );
		self::assertSame( 403, $this->press( 'whatever' )->get_status(), 'No session.' );

		self::assertSame( array(), self::$engine->console_entry_requests() );
	}

	public function test_the_route_only_answers_post(): void {
		$this->connect( 'tok_console_get' );
		$this->sign_in( 'administrator' );

		$request = new WP_REST_Request( 'GET', '/smaily-connect/v1/rec-engine/console-entry' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_get_server()->dispatch( $request );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'rest_no_route', $response->as_error()->get_error_code() );
		self::assertSame( array(), self::$engine->console_entry_requests() );
	}

	public function test_a_switched_off_engine_answers_404_with_the_console_address(): void {
		$this->connect( 'tok_console_404' );
		$this->sign_in( 'administrator' );
		self::$engine->set_console_entry_status( 404 );

		$response = $this->press();

		self::assertSame( 404, $response->get_status() );
		self::assertSame(
			array(
				'error'       => 'not_available',
				'console_url' => self::$engine->base_url() . '/login',
			),
			$response->get_data()
		);
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		self::assertCount( 1, self::$engine->console_entry_requests() );
	}

	public function test_a_rejected_key_answers_the_reconnect_error(): void {
		$this->connect( 'tok_console_401' );
		$this->sign_in( 'administrator' );
		self::$engine->set_console_entry_status( 401 );

		$response = $this->press();

		self::assertSame( 502, $response->get_status() );
		self::assertSame( array( 'error' => 'api_key_rejected' ), $response->get_data() );
	}

	public function test_an_inactive_store_answers_403_and_is_recorded_as_refused(): void {
		$this->connect( 'tok_console_403' );
		$this->sign_in( 'administrator' );
		self::$engine->set_console_entry_status( 403 );

		$response = $this->press();

		self::assertSame( 403, $response->get_status() );
		self::assertSame( array( 'error' => 'tenant_inactive' ), $response->get_data() );
		self::assertTrue( ( new RecEngineSettings() )->is_refused(), 'Client records `403 tenant_inactive` centrally.' );

		// From then on the button answers without an engine call.
		self::assertSame( 403, $this->press()->get_status() );
		self::assertCount( 1, self::$engine->console_entry_requests() );
	}

	public function test_a_throttled_press_answers_429_with_the_engine_retry_after_and_one_call(): void {
		$this->connect( 'tok_console_429' );
		$this->sign_in( 'administrator' );
		self::$engine->set_console_entry_status( 429 );

		$response = $this->press();

		self::assertSame( 429, $response->get_status() );
		self::assertSame(
			array(
				'error'       => 'rate_limited',
				'retry_after' => 37,
			),
			$response->get_data()
		);
		self::assertCount( 1, self::$engine->console_entry_requests(), 'No retry.' );
	}

	/** Press the button: a POST with the given nonce (default: a valid one). */
	private function press( ?string $nonce = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/rec-engine/console-entry' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $nonce ?? wp_create_nonce( 'wp_rest' ) );
		return rest_get_server()->dispatch( $request );
	}

	private function connect( string $token ): void {
		$exchange = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $exchange->get_status() );
	}

	/** Sign in as a fresh user with the role; an administrator has the fake email. */
	private function sign_in( string $role ): void {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_console_' . $role . '_' . $slug,
				'user_email' => $role === 'administrator' ? self::ADMIN_EMAIL : 'console-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => $role,
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		wp_set_current_user( $user_id );
	}

	/** @return array{queues: array<string, int>} */
	private function stored_rows(): array {
		global $wpdb;
		$queues = array();
		foreach ( array( 'smly_rec_event_queue', 'smly_plus_event_queue' ) as $suffix ) {
			$table             = $wpdb->prefix . $suffix;
			$queues[ $suffix ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		return array( 'queues' => $queues );
	}

	private function options_holding( string $needle ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s OR option_name LIKE %s",
				'%' . $wpdb->esc_like( $needle ) . '%',
				'%' . $wpdb->esc_like( $needle ) . '%'
			)
		);
	}
}
