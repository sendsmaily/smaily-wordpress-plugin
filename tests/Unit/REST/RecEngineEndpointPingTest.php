<?php
/**
 * The admin's connection check answers a failed engine call with the engine's
 * error code under the plugin's own sentence, never the engine's message,
 * which can name a customer (CON-301).
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LogicException;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\RecEngineEndpoint;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class RecEngineEndpointPingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_failed_check_answers_the_code_and_never_the_engine_message(): void {
		$client = new class( 'sk_unit', 'https://engine.unit' ) extends Client {
			public function ping(): array {
				throw new ApiException( 404, 'customer_not_found', 'No customer with email jane.doe@example.com' );
			}
		};

		$endpoint = new RecEngineEndpoint(
			new FakeRecEngineSettings(),
			static function () {
				throw new LogicException( 'The check runs no setup exchange.' );
			},
			static fn (): Client => $client,
			static function (): void {}
		);

		$response = $endpoint->ping( new WP_REST_Request() );
		$data     = $response->get_data();

		self::assertSame( 502, $response->get_status() );
		self::assertFalse( $data['ok'] );
		self::assertSame( 'customer_not_found', $data['error'] );
		self::assertSame( 'The connection check failed (error code: customer_not_found).', $data['message'] );
		self::assertStringNotContainsString( 'jane.doe', (string) json_encode( $data ) );
	}
}
