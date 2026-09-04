<?php
/**
 * Integration: the automations config API round-trip (contract §11–§13)
 * through the real REST proxy against the mock engine.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What this catches:
 *
 *   - The full proxy chain: REST route → AutomationsEndpoint → Client
 *     (endpoints-map URL) → mock engine over real HTTP. The §11 catalog
 *     shape, the §12 empty-state, and the §13 PUT→GET round-trip
 *     (engine-stamped configured_via/updated_at) are asserted against the
 *     wire, not a doubled request_url().
 *
 *   - The §13 all-or-nothing 422: an invalid row rejects the WHOLE batch —
 *     the indexed D6-style errors[] reaches the REST caller verbatim AND the
 *     valid sibling row is NOT saved (a partial-save here would silently
 *     enable a trigger the merchant thinks failed).
 *
 *   - The wrapper-level 422 (empty configs) — same shape, no index,
 *     field="configs" — passed through by the proxy, not re-mapped.
 *
 *   - The not-connected gate (503 before any engine call) and the mock
 *     engine's Bearer-auth 401 on a bad key (via a direct Client, since the
 *     proxy always sends the stored key).
 *
 *   - api_key non-leak: none of the proxied bodies may carry the stored key.
 */
final class RecEngineAutomationsTest extends TestCase {

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

	public function test_automations_routes_gate_on_connection(): void {
		$catalog = RestRequestHelper::get( '/rec-engine/automations/catalog' );
		self::assertSame( 503, $catalog->get_status() );
		self::assertSame( 'not_configured', $catalog->get_data()['error'] );

		$put = RestRequestHelper::put(
			'/rec-engine/automations/config',
			array( 'configs' => array( $this->valid_row() ) )
		);
		self::assertSame( 503, $put->get_status() );
		self::assertSame( 'not_configured', $put->get_data()['error'] );
	}

	public function test_catalog_returns_the_contract_shape_without_leaking_the_api_key(): void {
		$this->connect( 'tok_automations_catalog' );

		$response = RestRequestHelper::get( '/rec-engine/automations/catalog' );
		self::assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		self::assertIsArray( $body['triggers'] );
		self::assertNotEmpty( $body['triggers'], 'The mock serves a non-empty sector catalog.' );
		foreach ( $body['triggers'] as $trigger ) {
			foreach ( array( 'key', 'name_et', 'name_en', 'description_et', 'description_en', 'recipe_et', 'recipe_en' ) as $field ) {
				self::assertArrayHasKey( $field, $trigger, "Every §11 trigger carries {$field}." );
				self::assertNotSame( '', (string) $trigger[ $field ] );
			}
		}
		self::assertSame(
			array( 'single', 'per_language' ),
			$body['language_modes'],
			'language_modes is the closed §13 enum set.'
		);
		self::assertIsString( $body['docs'] );
		self::assertNotSame( '', $body['docs'], 'The docs help URL comes from the response — the UI must link it, not hardcode it.' );

		// The proxy forwards the engine body only — never the Bearer key.
		$decoded_api_key = ( new RecEngineSettings() )->api_key();
		$serialised      = (string) wp_json_encode( $body );
		self::assertStringNotContainsString( $decoded_api_key, $serialised );
		self::assertStringNotContainsString( 'sk_', $serialised );
	}

	public function test_config_put_then_get_round_trip(): void {
		$this->connect( 'tok_automations_roundtrip' );

		// Fresh tenant: no rows — everything is off (fail-closed).
		$empty = RestRequestHelper::get( '/rec-engine/automations/config' );
		self::assertSame( 200, $empty->get_status() );
		self::assertSame( array(), $empty->get_data()['configs'] );

		$row = $this->valid_row();
		$put = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array( $row ) ) );
		self::assertSame( 200, $put->get_status() );
		self::assertTrue( $put->get_data()['ok'] );
		self::assertSame( 1, $put->get_data()['upserted'] );

		// The engine's GET is the source of truth — the saved row comes back
		// with the 8 fields intact plus the engine-stamped read-only pair.
		$read = RestRequestHelper::get( '/rec-engine/automations/config' );
		self::assertSame( 200, $read->get_status() );
		$configs = $read->get_data()['configs'];
		self::assertCount( 1, $configs );
		foreach ( $row as $field => $value ) {
			self::assertSame( $value, $configs[0][ $field ], "Round-trip must preserve {$field}." );
		}
		self::assertSame( 'plugin', $configs[0]['configured_via'], 'This endpoint always writes configured_via=plugin (§13).' );
		self::assertNotSame( '', (string) $configs[0]['updated_at'] );
	}

	public function test_invalid_row_returns_indexed_422_and_saves_nothing(): void {
		$this->connect( 'tok_automations_invalid' );

		// Row 0 is valid; row 1 violates the enabled=true + single binding
		// rule (automation_map without `id`).
		$invalid                   = $this->valid_row( 'winback_risk' );
		$invalid['automation_map'] = array();

		$put = RestRequestHelper::put(
			'/rec-engine/automations/config',
			array( 'configs' => array( $this->valid_row(), $invalid ) )
		);

		self::assertSame( 422, $put->get_status() );
		$body = $put->get_data();
		self::assertSame( 'validation_failed', $body['error'] );
		self::assertCount( 1, $body['errors'] );
		self::assertSame( 1, $body['errors'][0]['index'], 'The error is bound to the offending row.' );
		self::assertSame( 'winback_risk', $body['errors'][0]['trigger_key'] );
		self::assertSame( 'automation_map', $body['errors'][0]['field'] );
		self::assertStringContainsString( 'automation_map.id', $body['errors'][0]['message'] );

		// All-or-nothing: the VALID row 0 must not have been saved either.
		$read = RestRequestHelper::get( '/rec-engine/automations/config' );
		self::assertSame(
			array(),
			$read->get_data()['configs'],
			'A 422 means nothing was written — a partial save would silently enable a trigger the merchant saw fail.'
		);
	}

	public function test_wrapper_violation_is_a_422_with_field_configs_and_no_index(): void {
		$this->connect( 'tok_automations_wrapper' );

		// An empty configs array passes the proxy's minimal is-array check —
		// the 1..50 wrapper rule is the ENGINE's (all validation lives
		// there), and its 422 comes back unchanged.
		$put = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array() ) );

		self::assertSame( 422, $put->get_status() );
		$body = $put->get_data();
		self::assertSame( 'validation_failed', $body['error'] );
		self::assertSame( 'configs', $body['errors'][0]['field'] );
		self::assertArrayNotHasKey( 'index', $body['errors'][0], 'Wrapper-level failures carry no index (§13).' );
	}

	public function test_mock_engine_requires_bearer_auth(): void {
		// The REST proxy always sends the stored key, so the 401 path is
		// probed with a direct Client carrying a malformed key (the mock's
		// auth regex requires an sk_ prefix, same as ping).
		$client = new Client( 'not-a-key', self::$engine->base_url() );

		try {
			$client->automations_catalog();
			self::fail( 'A missing/malformed Bearer key must 401.' );
		} catch ( ApiException $e ) {
			self::assertSame( 401, $e->getCode() );
			self::assertSame( 'unauthorized', $e->error_code() );
		}
	}

	/**
	 * Exchange a fresh one-time token so the proxy has a stored connection.
	 */
	private function connect( string $token ): void {
		$exchange = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $exchange->get_status() );
	}

	/**
	 * A §13-valid config row (all 8 required fields, mock catalog key).
	 *
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
			'test_emails'    => array( 'owner@example.com' ),
		);
	}
}
