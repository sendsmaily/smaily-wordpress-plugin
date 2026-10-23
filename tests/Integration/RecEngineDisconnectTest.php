<?php
/**
 * Integration: Disconnect and uninstall tell the engine the connection ends
 * (contract §16 "disconnect", v1.18.0; CON-219) before the plugin deletes its
 * stored engine key — and no engine answer keeps the key from going.
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

/**
 * What this catches that the unit test cannot: the request reaches a real
 * HTTP server with the key the setup exchange stored (so it was sent before
 * the key was deleted), carries no body, is sent once even when the engine
 * fails, and the real wp_options rows are gone afterwards in every case.
 *
 * `uninstall.php` itself is not run here — it drops the plugin's tables, the
 * rest of the suite's fixtures. Its §16 call is the same
 * `RecEngineSettings::end_engine_connection()` these tests exercise; the unit
 * suite pins that `uninstall.php` calls it before its `smly_rec_*` sweep.
 */
final class RecEngineDisconnectTest extends TestCase {

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
		self::$engine->set_tenant_inactive( false );
		self::$engine->set_store_uninstall_fails( false );
		self::$engine->set_store_other_connections( false );
		parent::tearDown();
	}

	public function test_disconnect_tells_the_engine_with_the_stored_key_then_deletes_it(): void {
		$this->connect( 'tok_disconnect_s16' );
		$stored_key = (string) self::$engine->state()['api_key'];

		$response = RestRequestHelper::post( '/rec-engine/disconnect' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'disconnected' => true ), $response->get_data() );
		self::assertSame(
			array(
				array(
					'key'  => $stored_key,
					'body' => '',
				),
			),
			self::$engine->store_uninstall_requests(),
			'One §16 request, with the connection key the plugin stored and no body.'
		);
		$this->assert_connection_options_gone();
	}

	public function test_a_failing_engine_is_asked_once_and_the_key_is_still_deleted(): void {
		$this->connect( 'tok_disconnect_500' );
		self::$engine->set_store_uninstall_fails( true );

		$response = RestRequestHelper::post( '/rec-engine/disconnect' );

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, self::$engine->store_uninstall_requests(), 'One attempt: no retry holds the admin request.' );
		$this->assert_connection_options_gone();
	}

	public function test_a_deactivated_account_still_disconnects(): void {
		$this->connect( 'tok_disconnect_refused' );
		self::$engine->set_tenant_inactive( true );
		( new RecEngineSettings() )->mark_refused();

		$response = RestRequestHelper::post( '/rec-engine/disconnect' );

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, self::$engine->store_uninstall_requests(), 'A suspended store may still disconnect (§16).' );
		$this->assert_connection_options_gone();
		self::assertFalse( get_option( RecEngineSettings::OPTION_REFUSED_AT, false ) );
	}

	public function test_a_connection_without_the_map_key_uses_the_fallback_path(): void {
		// A connection exchanged before contract v1.14.0 has no
		// `store_uninstall` in its stored endpoints map.
		$this->connect( 'tok_disconnect_fallback' );
		$endpoints = ( new RecEngineSettings() )->endpoints();
		unset( $endpoints['store_uninstall'] );
		update_option( RecEngineSettings::OPTION_ENDPOINTS, (string) wp_json_encode( $endpoints ), false );

		self::assertSame( 'store_paused', ( new RecEngineSettings() )->end_engine_connection() );
		self::assertCount( 1, self::$engine->store_uninstall_requests() );
	}

	public function test_the_uninstall_call_reports_the_engine_answer(): void {
		$this->connect( 'tok_uninstall_paused' );
		self::assertSame( 'store_paused', ( new RecEngineSettings() )->end_engine_connection() );

		RecEngineMockServer::reset();
		$this->connect( 'tok_uninstall_other' );
		self::$engine->set_store_other_connections( true );
		self::assertSame( 'connection_removed', ( new RecEngineSettings() )->end_engine_connection() );
	}

	public function test_an_unreachable_engine_does_not_keep_the_key(): void {
		$this->connect( 'tok_disconnect_unreachable' );
		// Nothing listens on port 1: the connection is refused at once.
		update_option( RecEngineSettings::OPTION_BASE_URL, 'http://127.0.0.1:1', false );
		update_option( RecEngineSettings::OPTION_ENDPOINTS, '{}', false );

		$response = RestRequestHelper::post( '/rec-engine/disconnect' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), self::$engine->store_uninstall_requests() );
		$this->assert_connection_options_gone();
	}

	public function test_nothing_is_sent_when_the_store_is_not_connected(): void {
		self::$engine->reset_request_count();

		self::assertSame( '', ( new RecEngineSettings() )->end_engine_connection() );
		self::assertSame( 200, RestRequestHelper::post( '/rec-engine/disconnect' )->get_status() );
		self::assertSame( 0, self::$engine->request_count() );
	}

	private function connect( string $token ): void {
		$exchange = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $exchange->get_status() );
		self::assertTrue( ( new RecEngineSettings() )->is_connected() );
	}

	private function assert_connection_options_gone(): void {
		foreach ( array( RecEngineSettings::OPTION_CONNECTED, RecEngineSettings::OPTION_API_KEY, RecEngineSettings::OPTION_BASE_URL, RecEngineSettings::OPTION_ENDPOINTS ) as $key ) {
			self::assertFalse( get_option( $key, false ), sprintf( 'Disconnect did not delete %s.', $key ) );
		}
	}
}
