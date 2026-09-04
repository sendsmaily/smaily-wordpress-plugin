<?php
/**
 * Integration: POST /rec-engine/ping proxy + Disconnect flow.
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
 * What this catches:
 *
 *   - api_key leakage. The plugin proxies ping through the server
 *     (RecEngineEndpoint::ping). The proxy decrypts the stored key
 *     and forwards it as Bearer auth. The response body the React
 *     layer sees must NOT include the Authorization header value or
 *     the api_key — only the engine's own ping body.
 *
 *   - "not_configured" guard. Calling ping before exchange should
 *     return 503, not crash. The mock server doesn't need to be
 *     consulted for this case.
 *
 *   - Round-trip after exchange. The exchange-stored key must be
 *     decryptable; the proxy must hand it to the engine; the engine
 *     must respond with the expected ping shape; the proxy must
 *     translate snake_case back to camelCase. Read/write symmetry
 *     applies to runtime calls too, not just persistence.
 *
 *   - Disconnect wipes state. After disconnect, ping must return
 *     503 again and the connected flag must be false in the boot
 *     payload.
 */
final class RecEnginePingTest extends TestCase {

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

	public function test_ping_returns_503_when_not_configured(): void {
		$response = RestRequestHelper::post( '/rec-engine/ping' );
		self::assertSame( 503, $response->get_status() );
		$body = $response->get_data();
		self::assertFalse( $body['ok'] );
		self::assertSame( 'not_configured', $body['error'] );
	}

	public function test_ping_after_exchange_returns_engine_response_without_leaking_api_key(): void {
		// Exchange first so the api_key gets stored.
		$exchange = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( 'tok_ping_e2e' ) )
		);
		self::assertSame( 200, $exchange->get_status() );

		$response = RestRequestHelper::post( '/rec-engine/ping' );
		self::assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		self::assertTrue( $body['ok'] );
		self::assertSame( '1.0.0', $body['engineVersion'] );
		self::assertSame( 'active', $body['tenantStatus'] );
		self::assertNotSame( '', $body['serverTime'] );

		// The proxy must not leak the secret into the response body
		// under any name. Scan the JSON body for the cipher and the
		// plaintext sk_* prefix.
		$decoded_api_key = ( new RecEngineSettings() )->api_key();
		$serialised      = (string) wp_json_encode( $body );
		self::assertStringNotContainsString( $decoded_api_key, $serialised );
		self::assertStringNotContainsString( 'sk_', $serialised );
		self::assertArrayNotHasKey( 'apiKey', $body );
		self::assertArrayNotHasKey( 'api_key', $body );
	}

	public function test_disconnect_wipes_state_and_returns_ping_to_not_configured(): void {
		// Connect.
		RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( 'tok_disconnect_e2e' ) )
		);
		self::assertTrue( ( new RecEngineSettings() )->is_connected() );

		// Disconnect.
		$response = RestRequestHelper::post( '/rec-engine/disconnect' );
		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['disconnected'] );

		// Every rec-engine option must be gone — disconnect is total.
		foreach (
			array(
				RecEngineSettings::OPTION_CONNECTED,
				RecEngineSettings::OPTION_API_KEY,
				RecEngineSettings::OPTION_BASE_URL,
				RecEngineSettings::OPTION_TENANT_ID,
				RecEngineSettings::OPTION_TENANT_NAME,
				RecEngineSettings::OPTION_ENDPOINTS,
				RecEngineSettings::OPTION_CONFIG,
				RecEngineSettings::OPTION_ISSUED_AT,
				RecEngineSettings::OPTION_ENGINE_VERSION,
			)
			as $key
		) {
			self::assertFalse(
				get_option( $key, false ),
				sprintf( 'Disconnect did not delete %s.', $key )
			);
		}

		// Ping after disconnect — back to 503.
		$ping = RestRequestHelper::post( '/rec-engine/ping' );
		self::assertSame( 503, $ping->get_status() );

		// Boot payload reflects the disconnect immediately (no
		// alloptions stale-cache leak).
		$detector = new \Smaily\Connect\Wizard\EnvDetector();
		$saved    = $detector->saved_settings();
		self::assertFalse( $saved['recEngine']['connected'] );
		self::assertSame( '', $saved['recEngine']['tenantName'] );
	}
}
