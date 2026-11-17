<?php
/**
 * The connection's code prefix (contract v1.33.0 §1/§2, CON-323): stored from
 * the setup exchange, moved by the ping, kept by Disconnect for the next
 * exchange's `previous_prefix`, and '' whenever there is no connection.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\ExchangeResult;

final class RecEngineConnectionPrefixTest extends TestCase {

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $fallback;
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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_successful_exchange_stores_its_prefix(): void {
		( new RecEngineSettings() )->store( $this->exchange( array( 'connection' => array( 'prefix' => 'c2-' ) ) ) );

		self::assertSame( 'c2-', ( new RecEngineSettings() )->connection_prefix() );
	}

	public function test_an_engine_before_v1_33_stores_no_prefix(): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTION_PREFIX ] = 'c2-';

		( new RecEngineSettings() )->store( $this->exchange( array() ) );

		self::assertSame( '', ( new RecEngineSettings() )->connection_prefix(), 'The new connection overwrites the last one.' );
		self::assertSame( '', ( new RecEngineSettings() )->previous_prefix() );
	}

	public function test_no_connection_means_no_prefix_but_the_last_one_is_remembered(): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTION_PREFIX ] = 'c2-';

		self::assertSame( '', ( new RecEngineSettings() )->connection_prefix() );
		self::assertSame( 'c2-', ( new RecEngineSettings() )->previous_prefix() );
	}

	public function test_a_store_that_never_connected_has_no_previous_prefix(): void {
		self::assertNull( ( new RecEngineSettings() )->previous_prefix() );
	}

	public function test_disconnect_keeps_the_prefix_for_the_next_exchange(): void {
		// Not connected, so disconnect() sends no §16 request.
		$this->options[ RecEngineSettings::OPTION_CONNECTION_PREFIX ] = 'c2-';
		$this->options[ RecEngineSettings::OPTION_TENANT_NAME ]       = 'Spa';

		( new RecEngineSettings() )->disconnect();

		self::assertArrayNotHasKey( RecEngineSettings::OPTION_TENANT_NAME, $this->options );
		self::assertSame( 'c2-', ( new RecEngineSettings() )->previous_prefix() );
	}

	/**
	 * @dataProvider ping_answers
	 *
	 * @param array<string, mixed> $answer
	 */
	public function test_the_ping_moves_the_stored_prefix( array $answer, string $expected ): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTED ]         = true;
		$this->options[ RecEngineSettings::OPTION_CONNECTION_PREFIX ] = 'c2-';

		( new RecEngineSettings() )->note_ping( $answer );

		self::assertSame( $expected, ( new RecEngineSettings() )->connection_prefix() );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function ping_answers(): array {
		return array(
			'another prefix wins'       => array( array( 'config' => array( 'connection' => array( 'prefix' => 'c3-' ) ) ), 'c3-' ),
			'no prefix wins too'        => array( array( 'config' => array( 'connection' => array( 'prefix' => '' ) ) ), '' ),
			'no connection block'       => array( array( 'config' => array( 'history' => array() ) ), 'c2-' ),
			'no config at all'          => array( array( 'ok' => true ), 'c2-' ),
			'a malformed value ignored' => array( array( 'config' => array( 'connection' => array( 'prefix' => 'C3-' ) ) ), 'c2-' ),
			'a block without a prefix'  => array( array( 'config' => array( 'connection' => array() ) ), 'c2-' ),
		);
	}

	public function test_a_ping_while_disconnected_stores_nothing(): void {
		( new RecEngineSettings() )->note_ping( array( 'config' => array( 'connection' => array( 'prefix' => 'c3-' ) ) ) );

		self::assertArrayNotHasKey( RecEngineSettings::OPTION_CONNECTION_PREFIX, $this->options );
	}

	public function test_every_ping_reads_the_prefix(): void {
		$this->options[ RecEngineSettings::OPTION_CONNECTED ] = true;
		$client = new class( 'sk_unit', 'https://engine.unit' ) extends Client {
			protected function request( string $method, string $path, ?array $body = null ): array {
				return array(
					'ok'     => true,
					'config' => array( 'connection' => array( 'prefix' => 'c4-' ) ),
				);
			}
		};

		$client->ping();

		self::assertSame( 'c4-', ( new RecEngineSettings() )->connection_prefix() );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function exchange( array $config ): ExchangeResult {
		return ExchangeResult::success(
			array(
				'tenant_id'       => 'ten_1',
				'tenant_name'     => 'Spa',
				'api_key'         => '',
				'engine_base_url' => 'https://intelligence.smaily.com',
				'config'          => $config,
			)
		);
	}
}
