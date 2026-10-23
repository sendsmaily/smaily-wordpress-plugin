<?php
/**
 * Integration: "Edit design" on the plugin's emails (contract §22–§23, CON-173)
 * through the real REST proxy against the mock engine, and the send-time use
 * of the Smaily workflow id the engine pushed.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What this catches:
 *
 *   - The design-link proxy: the one-time URL reaches the admin, the engine
 *     gets the admin's language, and a `return_url` only when it is https on
 *     the store's site host (the engine answers 422 otherwise), with no
 *     backslash, user info or port (CON-206).
 *   - The design read-back: the list reaches the admin, and the Smaily
 *     workflow ids in it are stored for the send paths.
 *   - Send time: an email mapped to the default Smaily account in the store
 *     default language starts with the workflow id the last read returned,
 *     and with the mapped workflow while that id is empty. The transactional
 *     account, a Mode A language account and a Mode B language other than
 *     the default keep the mapped workflow (audit 3.19.0 M1). A send never
 *     asks the engine.
 *   - No connection, a refused account or an engine without the routes: the
 *     routes answer an error (the admin shows no button) and the stored ids
 *     stay as they were.
 *   - The stored key never reaches the browser.
 */
final class EmailDesignsTest extends TestCase {

	/** The six plugin emails and their §22 keys. */
	private const EMAIL_KEYS = array(
		'welcome'               => 'plugin.welcome',
		'first_order'           => 'plugin.first_order',
		'abandoned_cart'        => 'plugin.abandoned_cart',
		'follow_up'             => 'plugin.follow_up',
		'order_confirmation'    => 'transactional.order_confirmation',
		'shipping_confirmation' => 'transactional.shipping_confirmation',
	);

	private const MAPPED_WORKFLOW = 4242;

	private static ?RecEngineMockServer $engine = null;

	/** @var callable|null */
	private $site_url_filter = null;

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
		delete_option( Router::OPTION_MODE );
		DetectorFactory::reset();
		if ( $this->site_url_filter !== null ) {
			remove_filter( 'site_url', $this->site_url_filter );
			$this->site_url_filter = null;
		}
		$prop = new \ReflectionProperty( Bootstrap::instance(), 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( Bootstrap::instance(), array() );
		parent::tearDown();
	}

	public function test_both_routes_answer_not_available_without_a_connection(): void {
		$designs = RestRequestHelper::get( '/rec-engine/emails/designs' );
		self::assertSame( 503, $designs->get_status() );
		self::assertSame( 'not_available', $designs->get_data()['error'] );

		$link = RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'plugin.welcome' ) );
		self::assertSame( 503, $link->get_status() );
		self::assertSame( 0, self::$engine->request_count(), 'No engine call without a connection.' );
	}

	public function test_designs_list_every_email_without_leaking_the_api_key(): void {
		$this->connect( 'tok_designs_list' );

		$response = RestRequestHelper::get( '/rec-engine/emails/designs' );
		self::assertSame( 200, $response->get_status() );

		$keys = array_column( $response->get_data()['emails'], 'email_key' );
		foreach ( self::EMAIL_KEYS as $email_key ) {
			self::assertContains( $email_key, $keys );
		}
		self::assertContains( 'replenish_due', $keys, 'Engine emails are listed too.' );

		$serialised = (string) wp_json_encode( $response->get_data() );
		self::assertStringNotContainsString( ( new RecEngineSettings() )->api_key(), $serialised );
		self::assertStringNotContainsString( 'sk_', $serialised );
	}

	public function test_the_default_account_emails_start_with_the_pushed_workflow_and_the_others_keep_the_mapped_one(): void {
		$this->connect( 'tok_designs_override' );
		$this->map_every_trigger();
		$pushed = $this->push_every_email();
		self::assertSame( 200, RestRequestHelper::get( '/rec-engine/emails/designs' )->get_status() );

		$resolver = Bootstrap::instance()->workflow_resolver();
		foreach ( array( 'welcome', 'first_order', 'abandoned_cart' ) as $trigger ) {
			$match = $resolver->resolve_workflow( $trigger, null );
			self::assertNotNull( $match );
			self::assertSame( $pushed[ $trigger ], $match->workflow_id, "{$trigger} starts with the workflow the engine pushed." );
			self::assertSame( 'default', $match->account_key, 'The account stays the mapped one.' );
		}
		foreach ( array( 'follow_up', 'order_confirmation', 'shipping_confirmation' ) as $trigger ) {
			$match = $resolver->resolve_workflow( $trigger, null );
			self::assertNotNull( $match );
			self::assertSame( self::MAPPED_WORKFLOW, $match->workflow_id, "{$trigger} is on the transactional account: the mapped workflow." );
			self::assertSame( 'transactional', $match->account_key );
		}

		// The engine's next answer has no workflow for the welcome email (a
		// push that has not happened): the mapped workflow is used again.
		self::$engine->set_email_design( 'plugin.welcome', array( 'smaily_workflow_id' => null ) );
		RestRequestHelper::get( '/rec-engine/emails/designs' );
		self::assertSame( self::MAPPED_WORKFLOW, $resolver->resolve_workflow( 'welcome', null )->workflow_id );
		self::assertSame( $pushed['first_order'], $resolver->resolve_workflow( 'first_order', null )->workflow_id );
	}

	public function test_a_mode_a_language_account_keeps_the_mapped_workflow(): void {
		$this->connect( 'tok_designs_mode_a' );
		update_option( Router::OPTION_MODE, Router::MODE_A );
		list( $default, $other ) = $this->two_language_store();
		$this->map( 'welcome', $default, 'default', 4301 );
		$this->map( 'welcome', $other, 'account_' . $other, 4302 );
		$pushed = $this->push_every_email();
		RestRequestHelper::get( '/rec-engine/emails/designs' );

		$resolver = Bootstrap::instance()->workflow_resolver();
		self::assertSame( $pushed['welcome'], $resolver->resolve_workflow( 'welcome', $default )->workflow_id, 'The default account in the store default language.' );
		$match = $resolver->resolve_workflow( 'welcome', $other );
		self::assertSame( 4302, $match->workflow_id, 'A language account starts its own mapped workflow.' );
		self::assertSame( 'account_' . $other, $match->account_key );
	}

	public function test_a_mode_b_language_other_than_the_store_default_keeps_the_mapped_workflow(): void {
		$this->connect( 'tok_designs_mode_b' );
		update_option( Router::OPTION_MODE, Router::MODE_B );
		list( $default, $other ) = $this->two_language_store();
		foreach ( array( 'welcome', 'first_order', 'abandoned_cart' ) as $trigger ) {
			$this->map( $trigger, $default, 'default', 4401 );
			$this->map( $trigger, $other, 'default', 4402 );
		}
		$pushed = $this->push_every_email();
		RestRequestHelper::get( '/rec-engine/emails/designs' );

		$resolver = Bootstrap::instance()->workflow_resolver();
		foreach ( array( 'welcome', 'first_order', 'abandoned_cart' ) as $trigger ) {
			self::assertSame( $pushed[ $trigger ], $resolver->resolve_workflow( $trigger, $default )->workflow_id, "{$trigger} in the store default language." );
			self::assertSame( 4402, $resolver->resolve_workflow( $trigger, $other )->workflow_id, "{$trigger} in {$other} keeps its own workflow." );
		}
	}

	public function test_the_first_order_email_reaches_smaily_with_the_pushed_workflow(): void {
		$this->connect( 'tok_designs_send' );
		$this->map_every_trigger();
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
		self::$engine->set_email_design( 'plugin.first_order', $this->pushed( '777' ) );
		RestRequestHelper::get( '/rec-engine/emails/designs' );

		$captured = null;
		$fake     = static function ( $pre, $args, $url ) use ( &$captured ) {
			if ( strpos( (string) $url, 'testsub' ) === false ) {
				return $pre;
			}
			$captured = isset( $args['body'] ) ? $args['body'] : null;
			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode( array( 'code' => 101, 'message' => 'OK' ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => '',
			);
		};
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			self::$engine->reset_request_count();
			$sent = Bootstrap::instance()->automation_router()->trigger_automation( 'first_order', array( 'email' => 'first@example.test' ) );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertTrue( $sent );
		self::assertIsArray( $captured );
		self::assertSame( 777, (int) $captured['autoresponder'] );
		self::assertSame( 0, self::$engine->request_count(), 'A send never asks the engine for the ids.' );
	}

	public function test_a_send_uses_the_stored_ids_until_the_next_read(): void {
		$this->connect( 'tok_designs_stored' );
		$this->map_every_trigger();
		self::$engine->set_email_design( 'plugin.abandoned_cart', $this->pushed( '500' ) );
		RestRequestHelper::get( '/rec-engine/emails/designs' );

		// The engine pushes a newer workflow, but nothing read it yet.
		self::$engine->set_email_design( 'plugin.abandoned_cart', $this->pushed( '501' ) );
		self::$engine->reset_request_count();

		self::assertSame( 500, Bootstrap::instance()->workflow_resolver()->resolve_workflow( 'abandoned_cart', null )->workflow_id );
		self::assertSame( 0, self::$engine->request_count() );

		RestRequestHelper::get( '/rec-engine/emails/designs' );
		self::assertSame( 501, Bootstrap::instance()->workflow_resolver()->resolve_workflow( 'abandoned_cart', null )->workflow_id );
	}

	public function test_an_engine_without_the_routes_shows_nothing_and_keeps_the_stored_ids(): void {
		$this->connect( 'tok_designs_unavailable' );
		$this->map_every_trigger();
		self::$engine->set_email_design( 'plugin.welcome', $this->pushed( '600' ) );
		RestRequestHelper::get( '/rec-engine/emails/designs' );

		self::$engine->set_email_designs_unavailable( true );

		$designs = RestRequestHelper::get( '/rec-engine/emails/designs' );
		self::assertSame( 404, $designs->get_status() );
		self::assertSame( 'not_available', $designs->get_data()['error'] );
		$link = RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'plugin.welcome' ) );
		self::assertSame( 404, $link->get_status() );

		self::assertSame( 600, Bootstrap::instance()->workflow_resolver()->resolve_workflow( 'welcome', null )->workflow_id );
	}

	public function test_a_connection_without_the_map_keys_uses_the_fallback_paths(): void {
		$this->connect( 'tok_designs_old_map' );
		$endpoints = ( new RecEngineSettings() )->endpoints();
		unset( $endpoints['emails_design_link'], $endpoints['emails_designs'] );
		update_option( RecEngineSettings::OPTION_ENDPOINTS, (string) wp_json_encode( $endpoints ), false );

		self::assertSame( 200, RestRequestHelper::get( '/rec-engine/emails/designs' )->get_status() );
		self::assertSame(
			200,
			RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'plugin.welcome' ) )->get_status()
		);
	}

	public function test_a_refused_account_gets_no_call(): void {
		$this->connect( 'tok_designs_refused' );
		( new RecEngineSettings() )->mark_refused();
		self::$engine->reset_request_count();

		self::assertSame( 503, RestRequestHelper::get( '/rec-engine/emails/designs' )->get_status() );
		self::assertSame( 503, RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'plugin.welcome' ) )->get_status() );
		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_disconnecting_forgets_the_pushed_workflows(): void {
		$this->connect( 'tok_designs_disconnect' );
		$this->map_every_trigger();
		self::$engine->set_email_design( 'plugin.welcome', $this->pushed( '700' ) );
		RestRequestHelper::get( '/rec-engine/emails/designs' );

		self::assertSame( 200, RestRequestHelper::post( '/rec-engine/disconnect' )->get_status() );

		self::assertSame( self::MAPPED_WORKFLOW, Bootstrap::instance()->workflow_resolver()->resolve_workflow( 'welcome', null )->workflow_id );
	}

	public function test_design_link_returns_a_one_time_url_and_sends_the_admin_language(): void {
		$this->connect( 'tok_design_link' );

		$response = RestRequestHelper::post(
			'/rec-engine/emails/design-link',
			array(
				'email_key'  => 'plugin.abandoned_cart',
				// The test site is http, so the engine would refuse this.
				'return_url' => get_site_url() . '/wp-admin/admin.php?page=smaily-connect-settings',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertStringContainsString( '/design/email/dl_', $response->get_data()['url'] );
		self::assertNotSame( '', (string) $response->get_data()['expires_at'] );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );

		$sent = self::$engine->last_design_link_request();
		self::assertSame( 'plugin.abandoned_cart', $sent['email_key'] );
		self::assertSame( 'en', $sent['body']['language'], 'The admin\'s language, as a short code.' );
		self::assertArrayNotHasKey( 'return_url', $sent['body'], 'An http return URL is left out.' );
	}

	public function test_a_return_url_reaches_the_engine_only_on_the_store_host(): void {
		$this->site_url_filter = static function (): string {
			return 'https://shop.example';
		};
		add_filter( 'site_url', $this->site_url_filter );
		$this->connect( 'tok_design_return' );

		$own = 'https://shop.example/wp-admin/admin.php?page=smaily-connect-settings#automations';
		self::assertSame(
			200,
			RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'replenish_due', 'return_url' => $own ) )->get_status()
		);
		self::assertSame( $own, self::$engine->last_design_link_request()['body']['return_url'] );

		self::assertSame(
			200,
			RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'replenish_due', 'return_url' => 'https://elsewhere.example/wp-admin/' ) )->get_status()
		);
		self::assertArrayNotHasKey( 'return_url', self::$engine->last_design_link_request()['body'] );

		// PHP's parser reads the store host in these; a browser does not
		// (a backslash is a slash to it), or adds a user name or a port.
		foreach ( array( 'https://elsewhere.example\\@shop.example/wp-admin/', 'https://user:pass@shop.example/wp-admin/', 'https://shop.example:8443/wp-admin/' ) as $return_url ) {
			self::assertSame(
				200,
				RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'replenish_due', 'return_url' => $return_url ) )->get_status()
			);
			self::assertArrayNotHasKey( 'return_url', self::$engine->last_design_link_request()['body'], $return_url );
		}
	}

	public function test_the_mock_refuses_a_return_url_on_another_host_like_the_engine(): void {
		$this->connect( 'tok_design_mock_422' );
		$settings = new RecEngineSettings();
		$client   = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1 );

		try {
			$client->email_design_link( 'plugin.welcome', array( 'return_url' => 'https://elsewhere.example/' ) );
			self::fail( 'The engine answers 422 for a return_url on another host.' );
		} catch ( ApiException $e ) {
			self::assertSame( 422, $e->getCode() );
			self::assertSame( 'return_url', $e->errors()[0]['field'] );
		}
	}

	public function test_an_unknown_email_key_answers_404(): void {
		$this->connect( 'tok_design_unknown' );

		$response = RestRequestHelper::post( '/rec-engine/emails/design-link', array( 'email_key' => 'plugin.not_a_starter' ) );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'unknown_email', $response->get_data()['error'] );
	}

	// --- helpers -------------------------------------------------------------

	private function connect( string $token ): void {
		$exchange = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => self::$engine->setup_url( $token ) )
		);
		self::assertSame( 200, $exchange->get_status() );
	}

	/** Map every plugin email to MAPPED_WORKFLOW, as the settings screen does. */
	private function map_every_trigger(): void {
		foreach ( array_keys( self::EMAIL_KEYS ) as $trigger ) {
			$this->map( $trigger, 'default', $this->account_of( $trigger ), self::MAPPED_WORKFLOW, 1 );
		}
	}

	private function map( string $trigger, string $language, string $account_key, int $workflow_id, int $is_default_fallback = 0 ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'smly_plus_automation_mapping',
			array(
				'trigger_type'        => $trigger,
				'language'            => $language,
				'account_key'         => $account_key,
				'workflow_id'         => (string) $workflow_id,
				'is_default_fallback' => $is_default_fallback,
			)
		);
	}

	/**
	 * Stands in for a multilingual plugin: an `et` store that also sells in
	 * `lt`. tearDown() puts the real detector back.
	 *
	 * @return array{0: string, 1: string} the default language, another language
	 */
	private function two_language_store(): array {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( 'et' );
		$detector->method( 'get_detected_languages' )->willReturn( array( 'et', 'lt' ) );
		$cached = new \ReflectionProperty( DetectorFactory::class, 'cached' );
		$cached->setAccessible( true );
		$cached->setValue( null, $detector );

		return array( 'et', 'lt' );
	}

	/**
	 * Push a design of every plugin email, each with its own workflow id.
	 *
	 * @return array<string, int> trigger => pushed workflow id
	 */
	private function push_every_email(): array {
		$pushed = array();
		$id     = 9001;
		foreach ( self::EMAIL_KEYS as $trigger => $email_key ) {
			$pushed[ $trigger ] = $id;
			self::$engine->set_email_design( $email_key, $this->pushed( (string) $id ) );
			++$id;
		}

		return $pushed;
	}

	private function account_of( string $trigger ): string {
		return in_array( $trigger, array( 'order_confirmation', 'shipping_confirmation', 'follow_up' ), true ) ? 'transactional' : 'default';
	}

	/**
	 * A §23 item after a push to Smaily.
	 *
	 * @return array<string, mixed>
	 */
	private function pushed( string $workflow_id ): array {
		return array(
			'design_state'       => 'edited',
			'subject'            => 'Hello',
			'version'            => 1,
			'smaily_state'       => 'pushed',
			'smaily_template_id' => '123',
			'smaily_workflow_id' => $workflow_id,
			'smaily_pushed_at'   => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'updated_at'         => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
		);
	}
}
