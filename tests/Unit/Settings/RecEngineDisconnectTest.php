<?php
/**
 * Disconnect and uninstall tell the engine the connection ends (CON-219).
 *
 * Contract §16 (`POST /api/v1/store/uninstall`, "disconnect" since v1.18.0)
 * revokes the calling connection's key; a store left with no connection is
 * paused, never deleted. The plugin sends it from Disconnect (the REST route
 * → `RecEngineSettings::disconnect()`) and from `uninstall.php`, BEFORE it
 * deletes the stored key, in one short attempt — and whatever the engine
 * answers, the key is deleted. Replaces CON-51's "never call §16" pin.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LogicException;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\RecEngineEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily_Connect\Includes\Cypher;
use WP_Error;
use WP_REST_Request;

final class RecEngineDisconnectTest extends TestCase {

	private const MAP_URL = 'https://intelligence.smaily.com/api/v1/store/uninstall';

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	/** @var array<int, array{url: string, args: array<string, mixed>, key_stored: bool}> */
	private array $requests = array();

	/** @var mixed What the fake HTTP layer answers: a WP_Error or `[status, body]`. */
	private $answer = array( 200, array( 'ok' => true, 'status' => 'store_paused', 'other_connections_remain' => false ) );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Cypher::$decrypt_return = 'sk_test_key';

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$opts ): bool {
				$opts[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$opts ): bool {
				unset( $opts[ $key ] );
				return true;
			}
		);

		$requests =& $this->requests;
		$answer   =& $this->answer;
		Functions\when( 'wp_remote_request' )->alias(
			static function ( string $url, array $args ) use ( &$opts, &$requests, &$answer ) {
				$requests[] = array(
					'url'        => $url,
					'args'       => $args,
					'key_stored' => array_key_exists( RecEngineSettings::OPTION_API_KEY, $opts ),
				);
				return $answer;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( array $response ): int {
				return (int) $response[0];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( array $response ): string {
				return (string) json_encode( $response[1] );
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Cypher::$decrypt_return = '';
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_disconnect_route_tells_the_engine_before_it_deletes_the_key(): void {
		$this->connected( array( 'store_uninstall' => self::MAP_URL ) );
		$fail = static function (): void {
			throw new LogicException( 'Disconnect needs no setup exchange, route client or import.' );
		};

		$response = ( new RecEngineEndpoint( new RecEngineSettings(), $fail, $fail, $fail ) )->disconnect( new WP_REST_Request() );

		self::assertSame( array( 'disconnected' => true ), $response->get_data() );
		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::MAP_URL, $request['url'], 'The endpoints-map key `store_uninstall` is the source of truth.' );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertSame( 'Bearer sk_test_key', $request['args']['headers']['Authorization'] );
		self::assertArrayNotHasKey( 'body', $request['args'], '§16 takes no request body.' );
		self::assertSame( 3, $request['args']['timeout'], 'One short attempt: the admin waits on it.' );
		self::assertSame( 0, $request['args']['redirection'] );
		self::assertTrue( $request['key_stored'], 'The call is made while the key is still stored.' );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_API_KEY, $this->options, 'The key is deleted afterwards.' );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_CONNECTED, $this->options );
	}

	public function test_a_connection_without_the_map_key_uses_the_fallback_path(): void {
		$this->connected( array() );

		( new RecEngineSettings() )->disconnect();

		self::assertSame( 'https://intelligence.smaily.com/api/v1/store/uninstall', $this->requests[0]['url'] );
	}

	/**
	 * @dataProvider failing_answers
	 *
	 * @param mixed $answer
	 */
	public function test_no_engine_answer_keeps_the_key( $answer ): void {
		$this->connected( array( 'store_uninstall' => self::MAP_URL ) );
		$this->answer = $answer;

		$settings = new RecEngineSettings();
		self::assertSame( '', $settings->end_engine_connection() );
		$settings->disconnect();

		self::assertCount( 2, $this->requests, 'One attempt per call, never a retry.' );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_API_KEY, $this->options );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_CONNECTED, $this->options );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_REFUSED_AT, $this->options );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function failing_answers(): array {
		return array(
			'unreachable'     => array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) ),
			'401 unauthorised' => array( array( 401, array( 'error' => 'unauthorised' ) ) ),
			'403 inactive'    => array( array( 403, array( 'error' => 'tenant_inactive', 'tenant_status' => 'suspended' ) ) ),
			'429'             => array( array( 429, array( 'error' => 'rate_limit_exceeded' ) ) ),
			'500'             => array( array( 500, array( 'error' => 'internal_error' ) ) ),
		);
	}

	public function test_both_success_answers_are_reported(): void {
		$this->connected( array( 'store_uninstall' => self::MAP_URL ) );
		$settings = new RecEngineSettings();

		self::assertSame( 'store_paused', $settings->end_engine_connection() );

		$this->answer = array( 200, array( 'ok' => true, 'status' => 'connection_removed', 'other_connections_remain' => true ) );
		self::assertSame( 'connection_removed', $settings->end_engine_connection() );
	}

	public function test_a_refused_account_is_still_told_once(): void {
		$this->connected( array( 'store_uninstall' => self::MAP_URL ) );
		$this->options[ RecEngineSettings::OPTION_REFUSED_AT ] = 1700000000;

		( new RecEngineSettings() )->disconnect();

		self::assertCount( 1, $this->requests, 'A suspended store may still disconnect (§16).' );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_REFUSED_AT, $this->options );
	}

	public function test_nothing_is_sent_when_not_connected(): void {
		$this->options = array(
			RecEngineSettings::OPTION_API_KEY  => 'encrypted-key',
			RecEngineSettings::OPTION_BASE_URL => 'https://intelligence.smaily.com',
		);

		( new RecEngineSettings() )->disconnect();

		self::assertSame( array(), $this->requests );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_API_KEY, $this->options );
	}

	public function test_nothing_is_sent_without_a_stored_key(): void {
		$this->connected( array( 'store_uninstall' => self::MAP_URL ) );
		unset( $this->options[ RecEngineSettings::OPTION_API_KEY ] );

		self::assertSame( '', ( new RecEngineSettings() )->end_engine_connection() );
		self::assertSame( array(), $this->requests );
	}

	public function test_uninstall_tells_the_engine_before_it_sweeps_the_connection(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		$call  = strpos( $source, '->end_engine_connection()' );
		$sweep = strpos( $source, "esc_like( 'smly_rec_' )" );
		self::assertNotFalse( $call, 'uninstall.php must tell the engine the connection ends (§16, CON-219).' );
		self::assertNotFalse( $sweep );
		self::assertLessThan( $sweep, $call, 'The §16 call needs the stored key, so it runs before the smly_rec_* sweep deletes it.' );
		self::assertStringContainsString( "vendor/autoload.php'", $source, 'The plugin is not loaded during uninstall: its autoloader must be.' );
		self::assertStringContainsString( "includes/smaily-cypher.class.php'", $source, 'The stored key is encrypted: the Cypher class must be loaded.' );
	}

	/**
	 * @param array<string, string> $endpoints
	 */
	private function connected( array $endpoints ): void {
		$this->options = array(
			RecEngineSettings::OPTION_CONNECTED => true,
			RecEngineSettings::OPTION_API_KEY   => 'encrypted-key',
			RecEngineSettings::OPTION_BASE_URL  => 'https://intelligence.smaily.com',
			RecEngineSettings::OPTION_ENDPOINTS => (string) json_encode( $endpoints ),
		);
	}
}
