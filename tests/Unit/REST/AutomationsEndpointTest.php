<?php
/**
 * AutomationsEndpoint tests — the connection gate, the pass-through-both-ways
 * contract (engine body out as-is, `configs` in as-is), and the ApiException
 * mapping (422 indexed errors[] passthrough, engine-401 → clear key-invalid
 * answer, other failures → 502). The real HTTP path against the mock engine
 * is covered by the integration suite (RecEngineAutomationsTest).
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\AutomationsEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_REST_Request;

final class AutomationsEndpointTest extends TestCase {

	/** @var array<string, mixed> Arguments the endpoint handed the client factory. */
	private array $factory_args = array();

	private bool $factory_called = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		$this->factory_args   = array();
		$this->factory_called = false;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_three_handlers_return_503_when_not_connected(): void {
		$endpoint = $this->endpoint( $this->settings( false ), $this->stub_client() );
		$request  = new WP_REST_Request();

		foreach ( array( 'catalog', 'get_config', 'put_config' ) as $handler ) {
			$response = $endpoint->{$handler}( $request );
			self::assertSame( 503, $response->get_status(), "{$handler} must gate on is_connected()." );
			self::assertSame( 'not_configured', $response->get_data()['error'] );
		}

		self::assertFalse( $this->factory_called, 'No engine client may be built while not connected.' );
	}

	public function test_incomplete_configuration_returns_503_before_any_engine_call(): void {
		$endpoint = $this->endpoint( $this->settings( true, '' ), $this->stub_client() );

		$response = $endpoint->catalog( new WP_REST_Request() );

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'configuration_incomplete', $response->get_data()['error'] );
		self::assertFalse( $this->factory_called );
	}

	public function test_catalog_passes_engine_body_through_and_hands_the_factory_the_endpoints_map(): void {
		$engine_body = array(
			'triggers'       => array(
				array(
					'key'            => 'replenish_due',
					'name_et'        => 'Taastäitumine',
					'name_en'        => 'Replenishment due',
					'description_et' => 'Kirjeldus.',
					'description_en' => 'Description.',
					'recipe_et'      => 'Retsept.',
				),
			),
			'language_modes' => array( 'single', 'per_language' ),
			'docs'           => 'https://engine.unit/docs/en/smaily-templates',
		);
		$endpoints   = array( 'automations_catalog' => 'https://engine.unit/api/v1/automations/catalog' );
		$endpoint    = $this->endpoint(
			$this->settings( true, 'sk_unit', 'https://engine.unit', $endpoints ),
			$this->stub_client( $engine_body )
		);

		$response = $endpoint->catalog( new WP_REST_Request() );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( $engine_body, $response->get_data(), 'The §11 body is forwarded as-is — no re-shaping, no caching.' );
		self::assertSame( 'sk_unit', $this->factory_args['api_key'] );
		self::assertSame( 'https://engine.unit', $this->factory_args['base_url'] );
		self::assertSame(
			$endpoints,
			$this->factory_args['endpoints'],
			'The factory must receive the stored endpoints map so the automations_* keys are honoured.'
		);
	}

	public function test_get_config_passes_engine_body_through(): void {
		$engine_body = array( 'configs' => array() );
		$endpoint    = $this->endpoint( $this->settings( true ), $this->stub_client( $engine_body ) );

		$response = $endpoint->get_config( new WP_REST_Request() );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( $engine_body, $response->get_data() );
	}

	public function test_put_config_forwards_configs_to_the_engine_as_is(): void {
		$client   = $this->stub_client( array( 'ok' => true, 'upserted' => 2 ) );
		$endpoint = $this->endpoint( $this->settings( true ), $client );

		$rows    = array( $this->valid_row(), $this->valid_row( 'winback_risk' ) );
		$request = new WP_REST_Request();
		$request->set_param( 'configs', $rows );

		$response = $endpoint->put_config( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'ok' => true, 'upserted' => 2 ), $response->get_data() );
		self::assertSame(
			$rows,
			$client->received_configs,
			'The rows go to the engine untouched — the engine is the validator (F3-51), no PHP-side duplicate.'
		);
	}

	public function test_put_config_rejects_a_missing_or_non_array_configs_with_400(): void {
		$endpoint = $this->endpoint( $this->settings( true ), $this->stub_client() );

		$response = $endpoint->put_config( new WP_REST_Request() );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'invalid_request', $response->get_data()['error'] );
		self::assertFalse( $this->factory_called, 'The minimal shape check happens before any engine call.' );
	}

	public function test_engine_422_passes_the_indexed_errors_through_unchanged(): void {
		$errors   = array(
			array(
				'index'       => 1,
				'trigger_key' => 'replenish_due',
				'field'       => 'automation_map',
				'message'     => 'automation_map.id on nõutav',
			),
			array(
				'index'   => 1,
				'field'   => 'test_emails.0',
				'message' => 'Invalid email',
			),
		);
		$endpoint = $this->endpoint(
			$this->settings( true ),
			$this->stub_client(
				null,
				new ApiException( 422, 'validation_failed', 'validation failed', array( 'errors' => $errors ) )
			)
		);

		$request = new WP_REST_Request();
		$request->set_param( 'configs', array( $this->valid_row() ) );
		$response = $endpoint->put_config( $request );

		self::assertSame( 422, $response->get_status() );
		self::assertSame( 'validation_failed', $response->get_data()['error'] );
		self::assertSame(
			$errors,
			$response->get_data()['errors'],
			'The §13 indexed errors[] must reach the UI verbatim (all-or-nothing: nothing was saved).'
		);
	}

	public function test_engine_401_maps_to_a_clear_api_key_rejected_502(): void {
		$endpoint = $this->endpoint(
			$this->settings( true ),
			$this->stub_client(
				null,
				new ApiException( 401, 'api_key_revoked', 'This api_key has been revoked.' )
			)
		);

		$response = $endpoint->catalog( new WP_REST_Request() );

		self::assertSame( 502, $response->get_status() );
		self::assertSame( 'api_key_rejected', $response->get_data()['error'] );
		self::assertSame( 'api_key_revoked', $response->get_data()['engineError'] );
	}

	public function test_other_engine_failures_map_to_502_with_the_engine_error_code(): void {
		$endpoint = $this->endpoint(
			$this->settings( true ),
			$this->stub_client(
				null,
				new ApiException( 0, 'network_error', 'Engine unreachable after 5 attempts: timeout' )
			)
		);

		$response = $endpoint->get_config( new WP_REST_Request() );

		self::assertSame( 502, $response->get_status() );
		self::assertSame( 'network_error', $response->get_data()['error'] );
	}

	// ---------------------------------------------------------------
	// Doubles.
	// ---------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function valid_row( string $trigger_key = 'replenish_due' ): array {
		return array(
			'trigger_key'    => $trigger_key,
			'enabled'        => true,
			'language_mode'  => 'single',
			'automation_map' => array( 'id' => '123' ),
			'cooldown_days'  => 7,
			'daily_cap'      => null,
			'test_mode'      => true,
			'test_emails'    => array(),
		);
	}

	/**
	 * Settings double — overrides the wp_options readers so no WP is needed.
	 *
	 * @param array<string, string> $endpoints
	 */
	private function settings( bool $connected, string $api_key = 'sk_unit', string $base_url = 'https://engine.unit', array $endpoints = array() ): RecEngineSettings {
		return new class( $connected, $api_key, $base_url, $endpoints ) extends RecEngineSettings {
			private bool $test_connected;
			private string $test_api_key;
			private string $test_base_url;
			/** @var array<string, string> */
			private array $test_endpoints;

			/** @param array<string, string> $endpoints */
			public function __construct( bool $connected, string $api_key, string $base_url, array $endpoints ) {
				$this->test_connected = $connected;
				$this->test_api_key   = $api_key;
				$this->test_base_url  = $base_url;
				$this->test_endpoints = $endpoints;
			}

			public function is_connected(): bool {
				return $this->test_connected;
			}

			public function api_key(): string {
				return $this->test_api_key;
			}

			public function base_url(): string {
				return $this->test_base_url;
			}

			public function endpoints(): array {
				return $this->test_endpoints;
			}
		};
	}

	/**
	 * Client double whose automations methods return a canned body or throw.
	 * Exposes the configs it received so the as-is forwarding is assertable.
	 *
	 * @param array<string, mixed>|null $canned
	 */
	private function stub_client( ?array $canned = null, ?ApiException $throws = null ): Client {
		return new class( $canned ?? array( 'ok' => true ), $throws ) extends Client {
			/** @var array<string, mixed> */
			private array $canned;

			private ?ApiException $throws;

			/** @var array<int, array<string, mixed>> */
			public array $received_configs = array();

			/** @param array<string, mixed> $canned */
			public function __construct( array $canned, ?ApiException $throws ) {
				parent::__construct( 'sk_unit', 'https://engine.unit' );
				$this->canned = $canned;
				$this->throws = $throws;
			}

			public function automations_catalog(): array {
				return $this->reply();
			}

			public function automations_config(): array {
				return $this->reply();
			}

			public function put_automations_config( array $configs ): array {
				$this->received_configs = $configs;
				return $this->reply();
			}

			/** @return array<string, mixed> */
			private function reply(): array {
				if ( $this->throws !== null ) {
					throw $this->throws;
				}
				return $this->canned;
			}
		};
	}

	private function endpoint( RecEngineSettings $settings, Client $client ): AutomationsEndpoint {
		return new AutomationsEndpoint(
			$settings,
			function ( string $api_key, string $base_url, array $endpoints ) use ( $client ): Client {
				$this->factory_called = true;
				$this->factory_args   = array(
					'api_key'   => $api_key,
					'base_url'  => $base_url,
					'endpoints' => $endpoints,
				);
				return $client;
			}
		);
	}
}
