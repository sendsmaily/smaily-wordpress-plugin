<?php
/**
 * ConnectionHealth — each connection's last answer, as the installation
 * report carries it (CON-290).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Report;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Report\ConnectionHealth;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as EngineClient;

final class ConnectionHealthTest extends TestCase {

	private const NOW = 1794571200; // 2026-11-13 12:00:00 UTC.

	/** @var array<string, mixed> */
	private array $options = array();

	private int $writes = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$options = &$this->options;
		$writes  = &$this->writes;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options, &$writes ): bool {
				$options[ $key ] = $value;
				++$writes;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_account_never_asked_reads_ok_with_no_sync(): void {
		self::assertSame(
			array(
				'status'          => 'ok',
				'last_sync_at'    => null,
				'last_error_code' => null,
			),
			$this->health()->smaily( 'shop' )
		);
	}

	public function test_a_successful_write_is_a_sync_and_a_read_is_not(): void {
		$health = $this->health();
		$health->record_smaily( 'Shop', '', 'GET', 'autoresponder' );
		self::assertNull( $health->smaily( 'shop' )['last_sync_at'], 'A read writes no data.' );

		$health->record_smaily( 'shop', '', 'POST', 'contact' );
		self::assertSame( self::NOW, $health->smaily( 'shop' )['last_sync_at'] );
		self::assertSame( 'ok', $health->smaily( 'SHOP' )['status'], 'The subdomain is matched in lower case.' );
	}

	public function test_a_failure_reads_failing_with_its_code_and_keeps_the_last_sync(): void {
		$health = $this->health();
		$health->record_smaily( 'shop', '', 'POST', 'contact' );
		$health->record_smaily( 'shop', 'plan_blocked', 'POST', 'contact' );

		self::assertSame(
			array(
				'status'          => 'failing',
				'last_sync_at'    => self::NOW,
				'last_error_code' => 'plan_blocked',
			),
			$health->smaily( 'shop' )
		);

		$health->record_smaily( 'shop', '', 'GET', 'autoresponder' );
		self::assertSame( 'ok', $health->smaily( 'shop' )['status'], 'The next answer that succeeds clears it.' );
		self::assertNull( $health->smaily( 'shop' )['last_error_code'] );
	}

	/**
	 * Pre-3.22.0 audit L2: a write Smaily refuses in a 200 body (here 203)
	 * reaches the hook as a failure code, so the account reads failing and
	 * `last_sync_at` stays on the last write that succeeded.
	 */
	public function test_a_write_refused_in_a_200_body_keeps_the_last_sync(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->health()->record_smaily( 'shop', '', 'POST', 'contact' );

		$later = $this->health( self::NOW + ConnectionHealth::WRITE_EVERY_SECONDS );
		Actions\expectDone( SmailyClient::RESULT_HOOK )->once()->whenHappen( array( $later, 'record_smaily' ) );

		$body = '{"code":203,"message":"Invalid data for jane.doe@example.com"}';
		Functions\when( 'wp_remote_post' )->justReturn( array( 'body' => $body ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		( new SmailyClient( 'shop', 'user', 'pass' ) )->upsert_subscribers( array( array( 'email' => 'jane.doe@example.com' ) ) );

		self::assertSame(
			array(
				'status'          => 'failing',
				'last_sync_at'    => self::NOW,
				'last_error_code' => 'permanent_envelope_203',
			),
			$later->smaily( 'shop' )
		);
		self::assertStringNotContainsString( 'jane.doe@example.com', (string) json_encode( $this->options ) );
	}

	public function test_a_repeated_success_is_written_at_most_every_five_minutes(): void {
		$health = $this->health();
		$health->record_engine( '', 'POST', '/api/v1/ingest/orders' );
		$health->record_engine( '', 'POST', '/api/v1/ingest/orders' );
		$health->record_engine( '', 'GET', '/api/v1/ingest/ping' );
		self::assertSame( 1, $this->writes, 'A storefront /relay request must not write the option each time.' );

		$later = $this->health( self::NOW + ConnectionHealth::WRITE_EVERY_SECONDS );
		$later->record_engine( '', 'POST', '/api/v1/ingest/orders' );
		self::assertSame( 2, $this->writes );
		self::assertSame( self::NOW + ConnectionHealth::WRITE_EVERY_SECONDS, $later->engine()['last_sync_at'] );
	}

	public function test_a_change_of_status_is_written_at_once(): void {
		$health = $this->health();
		$health->record_engine( '', 'POST', '/api/v1/ingest/orders' );
		$health->record_engine( 'network_error', 'GET', '/api/v1/ingest/ping' );
		$health->record_engine( 'network_error', 'GET', '/api/v1/ingest/ping' );
		$health->record_engine( 'api_key_revoked', 'GET', '/api/v1/ingest/ping' );

		self::assertSame( 3, $this->writes );
		self::assertSame( 'api_key_revoked', $health->engine()['last_error_code'] );
	}

	public function test_only_a_code_is_stored_never_a_text(): void {
		$health = $this->health();
		$health->record_engine( 'No customer with email jane@example.com', 'GET', '/api/v1/ingest/ping' );

		self::assertSame( 'unknown_error', $health->engine()['last_error_code'] );
		self::assertStringNotContainsString( 'jane@example.com', (string) json_encode( $this->options ) );
	}

	public function test_register_listens_to_both_clients(): void {
		$health = new ConnectionHealth();
		$health->register();

		self::assertNotFalse( has_action( SmailyClient::RESULT_HOOK, array( $health, 'record_smaily' ) ) );
		self::assertNotFalse( has_action( EngineClient::RESULT_HOOK, array( $health, 'record_engine' ) ) );
	}

	private function health( int $now = self::NOW ): ConnectionHealth {
		return new class( $now ) extends ConnectionHealth {
			private int $at;

			public function __construct( int $at ) {
				$this->at = $at;
			}

			protected function now(): int {
				return $this->at;
			}
		};
	}
}
