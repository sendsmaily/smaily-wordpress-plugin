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
 *
 *   - A save never sends test_mode=false (CON-172): the proxy leaves it out,
 *     so the engine keeps the stored value — a new row stays in test mode
 *     and a trigger whose real sends are on stays on.
 *
 *   - A save that leaves out automation_map / test_mode / test_emails keeps
 *     what the engine's setup page stored (§13 v1.15.0, CON-172).
 *
 *   - A cleared workflow goes to the engine as `{}`, never `[]` — the mock
 *     refuses a list, as the engine's schema does (§13 types it an object).
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

	public function test_a_real_sends_request_never_reaches_the_engine_and_a_live_trigger_stays_live(): void {
		$this->connect( 'tok_automations_real_sends' );
		// Real sends for winback_risk were switched on outside the plugin
		// (the setup page's go-live, or a Smaily operator).
		$live              = $this->valid_row( 'winback_risk' );
		$live['test_mode'] = false;
		self::$engine->set_automations_configs( array( $live ) );

		$requested              = $this->valid_row();
		$requested['test_mode'] = false;
		$resaved                = $this->valid_row( 'winback_risk' );
		$resaved['test_mode']   = false;

		$put = RestRequestHelper::put(
			'/rec-engine/automations/config',
			array( 'configs' => array( $requested, $resaved ) )
		);
		self::assertSame( 200, $put->get_status() );
		self::assertSame( 2, $put->get_data()['upserted'] );

		$sent = self::$engine->last_automations_config_put();
		self::assertCount( 2, $sent );
		foreach ( $sent as $row ) {
			self::assertArrayNotHasKey( 'test_mode', $row, 'The plugin never sends test_mode=false (CON-172).' );
		}

		$configs = array_column(
			RestRequestHelper::get( '/rec-engine/automations/config' )->get_data()['configs'],
			null,
			'trigger_key'
		);
		self::assertTrue( $configs['replenish_due']['test_mode'], 'A new row is stored in test mode (§13).' );
		self::assertTrue( $configs['replenish_due']['enabled'], 'Every other field is stored as sent.' );
		self::assertFalse( $configs['winback_risk']['test_mode'], 'A trigger whose real sends are on keeps them on a re-save.' );
	}

	public function test_a_save_keeps_the_workflow_test_mode_and_test_addresses_the_setup_page_stored(): void {
		$this->connect( 'tok_automations_setup_page' );
		$stored                   = $this->valid_row();
		$stored['automation_map'] = array( 'id' => '777' );
		$stored['test_mode']      = false;
		$stored['test_emails']    = array( 'store@shop.example' );
		self::$engine->set_automations_configs( array( $stored ) );

		// What the settings screen sends when the merchant changed only the
		// cooldown: no workflow, no test mode, no test addresses.
		$saved                  = $this->valid_row();
		$saved['cooldown_days'] = 14;
		unset( $saved['automation_map'], $saved['test_mode'], $saved['test_emails'] );

		$put = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array( $saved ) ) );
		self::assertSame( 200, $put->get_status(), 'The three keys are optional (§13, v1.15.0).' );

		$row = RestRequestHelper::get( '/rec-engine/automations/config' )->get_data()['configs'][0];
		self::assertSame( 14, $row['cooldown_days'], 'The merchant\'s change is stored.' );
		self::assertSame( array( 'id' => '777' ), $row['automation_map'], 'The stored workflow is kept.' );
		self::assertFalse( $row['test_mode'], 'The stored test mode is kept.' );
		self::assertSame( array( 'store@shop.example' ), $row['test_emails'], 'The stored test addresses are kept.' );
	}

	public function test_a_new_row_that_leaves_them_out_is_stored_in_test_mode_with_no_test_addresses(): void {
		$this->connect( 'tok_automations_new_row' );

		$row            = $this->valid_row();
		$row['enabled'] = false;
		unset( $row['automation_map'], $row['test_mode'], $row['test_emails'] );

		$put = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array( $row ) ) );
		self::assertSame( 200, $put->get_status() );

		$stored = RestRequestHelper::get( '/rec-engine/automations/config' )->get_data()['configs'][0];
		self::assertTrue( $stored['test_mode'], '§13: a new row is stored with test_mode=true.' );
		self::assertSame( array(), $stored['test_emails'], '§13: a new row is stored with test_emails=[].' );
	}

	public function test_an_enabled_row_with_no_workflow_sent_or_stored_is_refused(): void {
		$this->connect( 'tok_automations_no_workflow' );

		$row = $this->valid_row();
		unset( $row['automation_map'] );

		$put = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array( $row ) ) );

		self::assertSame( 422, $put->get_status() );
		self::assertSame( 'automation_map', $put->get_data()['errors'][0]['field'] );
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

	public function test_a_cleared_workflow_is_saved_as_an_empty_map(): void {
		$this->connect( 'tok_automations_cleared' );

		$put = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array( $this->valid_row() ) ) );
		self::assertSame( 200, $put->get_status() );

		// The merchant clears the workflow and switches the trigger off. The
		// mock refuses a map sent as a JSON list, so a 200 here means the
		// proxy sent `{}` (§13: automation_map is an object).
		$cleared                   = $this->valid_row();
		$cleared['enabled']        = false;
		$cleared['automation_map'] = array();
		$put                       = RestRequestHelper::put( '/rec-engine/automations/config', array( 'configs' => array( $cleared ) ) );
		self::assertSame( 200, $put->get_status(), 'A cleared workflow must reach the engine as an object.' );

		$read = RestRequestHelper::get( '/rec-engine/automations/config' );
		self::assertSame( array(), $read->get_data()['configs'][0]['automation_map'], 'The sent empty map replaces the stored workflow.' );
	}

	public function test_mock_refuses_an_automation_map_sent_as_a_json_list(): void {
		// A direct Client encodes an empty PHP array as `[]` — the shape the
		// proxy must never send. §13 types automation_map as an object, and
		// the engine's Zod schema refuses a list.
		$row                   = $this->valid_row();
		$row['enabled']        = false;
		$row['automation_map'] = array();
		$client                = new Client( 'sk_mock_list', self::$engine->base_url() );

		try {
			$client->put_automations_config( array( $row ) );
			self::fail( 'An automation_map sent as a JSON list must be refused.' );
		} catch ( ApiException $e ) {
			self::assertSame( 422, $e->getCode() );
			self::assertSame( 'validation_failed', $e->error_code() );
			$errors = $e->errors();
			self::assertCount( 1, $errors );
			self::assertSame( 0, $errors[0]['index'] );
			self::assertSame( 'automation_map', $errors[0]['field'] );
		}
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
