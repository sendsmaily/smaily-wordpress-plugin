<?php
/**
 * Integration: an automation's "Send test email" and "Test mode" switch
 * (contract §19–§20, CON-455) through the real REST routes against the mock
 * engine.
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
 *   - A test send is ONE §19 call with the typed address and the store's
 *     default language, also when the engine answers 502 or 429 (no retry:
 *     a retry could send a second email); nothing keeps the address.
 *   - Each §19 refusal reaches the admin as the engine's codes only, never
 *     its message text: 409 `not_ready` with its reasons, 409
 *     `sender_refused`, 429, 502 `smaily_unavailable`, 404 for a plugin email.
 *   - Turning test mode off is §20 `{live: true, confirmed: true,
 *     switched_by: <user id>}` and only with the admin's confirmation; §12
 *     then reads the email as sending to real customers with its daily
 *     limit. Turning it back on is `{live: false}`. A blocked switch lists
 *     the blockers.
 *   - A connection with no map keys (set up before 2026-11-26) reaches the
 *     same engine routes through the fallback paths.
 *   - No connection, no nonce or no capability: no engine call.
 */
final class AutomationSendsTest extends TestCase {

	private const TYPED_EMAIL = 'owner-test@example.test';

	private static ?RecEngineMockServer $engine = null;

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
		wp_set_current_user( 1 );
		parent::tearDown();
	}

	public function test_no_engine_call_without_a_connection(): void {
		self::assertSame( 503, $this->test_send()->get_status() );
		self::assertSame( 503, $this->go_live( true, true )->get_status() );
		self::assertSame( array(), self::$engine->automation_send_requests() );
	}

	public function test_no_nonce_or_no_capability_is_refused_before_the_engine(): void {
		$this->connect( 'tok_sends_refused' );

		self::assertSame( 403, $this->dispatch( 'test-send', array( 'trigger_key' => 'replenish_due', 'email' => self::TYPED_EMAIL ), '' )->get_status() );
		self::assertSame( 403, $this->dispatch( 'go-live', array( 'trigger_key' => 'replenish_due', 'live' => false ), 'not-a-nonce' )->get_status() );

		$editor = wp_insert_user(
			array(
				'user_login' => 'smly_sends_editor_' . wp_generate_password( 6, false ),
				'user_email' => 'sends-editor@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => 'editor',
			)
		);
		self::assertIsInt( $editor );
		wp_set_current_user( $editor );
		self::assertSame( 403, $this->test_send()->get_status() );
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor );

		self::assertSame( array(), self::$engine->automation_send_requests() );
	}

	public function test_a_test_send_is_one_call_with_the_typed_address_and_the_store_language_and_nothing_keeps_it(): void {
		$this->connect( 'tok_sends_ok' );

		$response = $this->test_send();

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['ok'] );
		self::assertNotSame( '', $response->get_data()['accepted_at'] );

		$requests = self::$engine->automation_send_requests();
		self::assertCount( 1, $requests );
		self::assertSame( 'test-send', $requests[0]['action'] );
		self::assertSame( 'replenish_due', $requests[0]['trigger_key'] );
		self::assertSame( self::TYPED_EMAIL, $requests[0]['body']['email'] );
		self::assertMatchesRegularExpression( '/^[a-z]{2}$/', (string) $requests[0]['body']['language'], 'The store default language, two letters.' );

		self::assertStringNotContainsString( self::TYPED_EMAIL, (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 0, $this->options_holding( self::TYPED_EMAIL ), 'No option or transient holds the typed address.' );
		self::assertSame( 0, $this->queue_rows_holding( self::TYPED_EMAIL ), 'No queue row holds the typed address.' );
	}

	public function test_an_invalid_address_is_refused_before_the_engine(): void {
		$this->connect( 'tok_sends_invalid' );

		$response = $this->test_send( 'not-an-address' );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array( 'error' => 'invalid_email' ), $response->get_data() );
		self::assertSame( array(), self::$engine->automation_send_requests() );
	}

	public function test_a_not_ready_email_answers_its_reasons_and_never_the_engine_message(): void {
		$this->connect( 'tok_sends_not_ready' );
		self::$engine->set_automation_send_answer(
			'test-send',
			409,
			array(
				'error'   => 'not_ready',
				'message' => 'Secret engine text for ' . self::TYPED_EMAIL,
				'details' => array( 'reasons' => array( 'no_workflow', 'no_products', 'Not A Code' ) ),
			)
		);

		$response = $this->test_send();

		self::assertSame( 409, $response->get_status() );
		self::assertSame(
			array(
				'error'   => 'not_ready',
				'reasons' => array( 'no_workflow', 'no_products' ),
			),
			$response->get_data()
		);
		self::assertCount( 1, self::$engine->automation_send_requests() );
	}

	public function test_a_refused_sender_answers_the_sender_state(): void {
		$this->connect( 'tok_sends_sender' );
		self::$engine->set_automation_send_answer(
			'test-send',
			409,
			array(
				'error'   => 'sender_refused',
				'message' => 'Smaily refused.',
				'details' => array(
					'sender_state' => 'unverified',
					'from_address' => 'news@shop.example',
				),
			)
		);

		$response = $this->test_send();

		self::assertSame( 409, $response->get_status() );
		self::assertSame(
			array(
				'error'        => 'sender_refused',
				'sender_state' => 'unverified',
			),
			$response->get_data()
		);
	}

	public function test_a_502_and_a_429_are_answered_after_one_call_without_a_retry(): void {
		$this->connect( 'tok_sends_retry' );

		self::$engine->set_automation_send_answer( 'test-send', 502, array( 'error' => 'smaily_unavailable', 'message' => 'Smaily did not answer.' ) );
		$failed = $this->test_send();
		self::assertSame( 502, $failed->get_status() );
		self::assertSame( array( 'error' => 'smaily_unavailable' ), $failed->get_data() );
		self::assertCount( 1, self::$engine->automation_send_requests(), 'A retried 502 could send a second email.' );

		self::$engine->set_automation_send_answer( 'test-send', 429, array( 'error' => 'rate_limit_exceeded', 'message' => 'Too many requests.' ) );
		$throttled = $this->test_send();
		self::assertSame( 429, $throttled->get_status() );
		self::assertSame(
			array(
				'error'       => 'rate_limited',
				'retry_after' => 1800,
			),
			$throttled->get_data()
		);
		self::assertCount( 2, self::$engine->automation_send_requests(), 'No wait and no retry on a 429.' );
	}

	public function test_a_plugin_email_has_no_test_send(): void {
		$this->connect( 'tok_sends_plugin' );

		$response = $this->test_send( self::TYPED_EMAIL, 'plugin.welcome' );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( array( 'error' => 'unknown_automation' ), $response->get_data() );
	}

	public function test_turning_test_mode_off_needs_the_confirmation_and_sends_the_admin_user_id(): void {
		$this->connect( 'tok_sends_live' );
		self::$engine->set_automations_configs( array( $this->test_mode_row() ) );

		$unconfirmed = $this->go_live( true, false );
		self::assertSame( 400, $unconfirmed->get_status() );
		self::assertSame( array( 'error' => 'not_confirmed' ), $unconfirmed->get_data() );
		self::assertSame( array(), self::$engine->automation_send_requests() );

		$response = $this->go_live( true, true );

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'ok'          => true,
				'changed'     => true,
				'setup_state' => 'live',
				'daily_limit' => 10,
			),
			$response->get_data()
		);
		$requests = self::$engine->automation_send_requests();
		self::assertCount( 1, $requests );
		self::assertSame( 'go-live', $requests[0]['action'] );
		self::assertSame(
			array(
				'live'        => true,
				'confirmed'   => true,
				'switched_by' => (string) get_current_user_id(),
			),
			$requests[0]['body']
		);

		$setup = $this->setup_item( 'replenish_due' );
		self::assertSame( 'live', $setup['setup_state'] );
		self::assertSame( 10, $setup['daily_limit'] );
	}

	public function test_turning_test_mode_back_on_sends_live_false(): void {
		$this->connect( 'tok_sends_back' );
		self::$engine->set_automations_configs( array( array_merge( $this->test_mode_row(), array( 'test_mode' => false ) ) ) );

		$response = $this->go_live( false );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'test', $response->get_data()['setup_state'] );
		self::assertSame(
			array(
				'live'        => false,
				'switched_by' => (string) get_current_user_id(),
			),
			self::$engine->automation_send_requests()[0]['body']
		);
		self::assertSame( 'test', $this->setup_item( 'replenish_due' )['setup_state'] );
	}

	public function test_a_blocked_switch_lists_the_blockers(): void {
		$this->connect( 'tok_sends_blocked' );
		self::$engine->set_automations_configs( array( $this->test_mode_row() ) );
		self::$engine->set_automation_setup(
			'replenish_due',
			array(
				'setup_state'      => 'stopped',
				'stop_reason'      => 'auto_stop_complaint',
				'go_live_blockers' => array( 'stopped_needs_review', 'no_design' ),
			)
		);

		$response = $this->go_live( true, true );

		self::assertSame( 409, $response->get_status() );
		self::assertSame(
			array(
				'error'    => 'go_live_blocked',
				'blockers' => array( 'stopped_needs_review', 'no_design' ),
			),
			$response->get_data()
		);
		self::assertSame( 'stopped', $this->setup_item( 'replenish_due' )['setup_state'], 'Nothing changes.' );
	}

	public function test_a_connection_without_the_map_keys_reaches_the_fallback_paths(): void {
		$this->connect( 'tok_sends_fallback' );
		$settings  = new RecEngineSettings();
		$endpoints = $settings->endpoints();
		self::assertArrayHasKey( 'automations_test_send', $endpoints, 'The mock exchange returns the keys, as the live engine does.' );
		unset( $endpoints['automations_test_send'], $endpoints['automations_go_live'] );
		update_option( RecEngineSettings::OPTION_ENDPOINTS, (string) wp_json_encode( $endpoints ), false );
		self::$engine->set_automations_configs( array( $this->test_mode_row() ) );

		self::assertSame( 200, $this->test_send()->get_status() );
		self::assertSame( 200, $this->go_live( true, true )->get_status() );

		self::assertSame( array( 'test-send', 'go-live' ), array_column( self::$engine->automation_send_requests(), 'action' ) );
	}

	public function test_an_inactive_store_answers_403_and_is_recorded_as_refused(): void {
		$this->connect( 'tok_sends_inactive' );
		self::$engine->set_tenant_inactive( true );

		$response = $this->test_send();

		self::assertSame( 403, $response->get_status() );
		self::assertSame( array( 'error' => 'tenant_inactive' ), $response->get_data() );
		self::assertTrue( ( new RecEngineSettings() )->is_refused() );

		// From then on the routes answer without an engine call.
		self::assertSame( 403, $this->go_live( false )->get_status() );
		self::assertSame( 1, self::$engine->request_count() - $this->requests_before_inactive );
	}

	/** @var int The mock's request count right after the connect. */
	private int $requests_before_inactive = 0;

	private function test_send( string $email = self::TYPED_EMAIL, string $trigger_key = 'replenish_due' ): WP_REST_Response {
		return $this->dispatch(
			'test-send',
			array(
				'trigger_key' => $trigger_key,
				'email'       => $email,
			)
		);
	}

	private function go_live( bool $live, ?bool $confirmed = null ): WP_REST_Response {
		$body = array(
			'trigger_key' => 'replenish_due',
			'live'        => $live,
		);
		if ( $confirmed !== null ) {
			$body['confirmed'] = $confirmed;
		}
		return $this->dispatch( 'go-live', $body );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function dispatch( string $route, array $body, ?string $nonce = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/rec-engine/automations/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $nonce ?? wp_create_nonce( 'wp_rest' ) );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function connect( string $token ): void {
		$exchange = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $exchange->get_status() );
		$this->requests_before_inactive = self::$engine->request_count();
	}

	/** @return array<string, mixed> */
	private function test_mode_row(): array {
		return array(
			'trigger_key'    => 'replenish_due',
			'enabled'        => true,
			'language_mode'  => 'single',
			'automation_map' => array( 'id' => '123' ),
			'cooldown_days'  => 7,
			'daily_cap'      => null,
			'test_mode'      => true,
			'test_emails'    => array(),
		);
	}

	/** @return array<string, mixed> The email's §12 `setup[]` item as the plugin's own proxy reads it. */
	private function setup_item( string $trigger_key ): array {
		$response = RestRequestHelper::get( '/rec-engine/automations/config' );
		self::assertSame( 200, $response->get_status() );
		foreach ( (array) $response->get_data()['setup'] as $item ) {
			if ( $item['trigger_key'] === $trigger_key ) {
				return $item;
			}
		}
		self::fail( 'No setup item for ' . $trigger_key );
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

	private function queue_rows_holding( string $needle ): int {
		global $wpdb;
		$count = 0;
		foreach ( array( 'smly_rec_event_queue', 'smly_plus_event_queue' ) as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			$count += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE payload LIKE %s OR sent_payload LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%', '%' . $wpdb->esc_like( $needle ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		return $count;
	}
}
