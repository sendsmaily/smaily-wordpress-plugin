<?php
/**
 * ReportBuilder — the installation report body against contract
 * installation-report-v1 (CON-290): its shape, which account authenticates
 * it, when there is none, and what it never carries.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Report;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Report\ConnectionHealth;
use Smaily\Connect\Report\ReportBuilder;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;

require_once dirname( __DIR__, 3 ) . '/includes/smaily-options.class.php';

final class ReportBuilderTest extends TestCase {

	private const NOW       = 1794571200; // 2026-11-13 12:00:00 UTC.
	private const TENANT_ID = '0b7e4f2a-9c1d-4e8b-a3f6-5d2c8e1b7a90';
	private const API_KEY   = 'sk_live_secret_engine_key';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, CredentialSet> by account key */
	private array $accounts = array();

	private bool $engine_connected = false;

	private bool $engine_refused = false;

	/** @var array<string, array<int, string>> mapped trigger types by account key */
	private array $mapped = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.3' );
		Functions\when( 'home_url' )->justReturn( 'https://example-store.test/' );
		Functions\when( 'untrailingslashit' )->alias( static fn ( string $url ): string => rtrim( $url, '/' ) );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_no_connected_smaily_account_builds_nothing(): void {
		self::assertNull( $this->builder()->main_credentials() );
		self::assertNull( $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY ) );
	}

	public function test_an_incomplete_default_account_is_not_connected(): void {
		$this->accounts['default'] = new CredentialSet( 'example', 'api-user', '' );

		self::assertNull( $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY ) );
	}

	public function test_a_transactional_account_alone_never_authenticates_a_report(): void {
		$this->accounts['transactional'] = new CredentialSet( 'example-transactional', 'api-user', 'api-pass' );

		self::assertNull( $this->builder()->main_credentials() );
		self::assertNull( $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY ) );
	}

	public function test_a_daily_snapshot_has_the_contract_shape(): void {
		$this->connect_everything();

		$body = $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY )['body'];

		self::assertSame(
			array(
				'product'          => 'connect_woocommerce',
				'product_version'  => '3.21.1',
				'platforms'        => array(
					array(
						'name'    => 'wordpress',
						'version' => '6.8.3',
					),
				),
				'php_version'      => PHP_VERSION,
				'installation_url' => 'https://example-store.test',
				'smaily_subdomain' => 'example',
				'event'            => 'snapshot',
				'trigger'          => 'daily',
				'sent_at'          => '2026-11-13T12:00:00Z',
				'connections'      => array(
					array(
						'role'             => 'marketing',
						'store_url'        => 'https://example-store.test',
						'smaily_subdomain' => 'example',
						'features'         => array( 'subscriber_sync', 'welcome_automation', 'abandoned_cart', 'campaign_intelligence', 'browse_tracking' ),
						'smaily'           => array(
							'status'          => 'ok',
							'last_sync_at'    => '2026-11-13T11:00:00Z',
							'last_error_code' => null,
						),
						'intelligence'     => array(
							'tenant_id'       => self::TENANT_ID,
							'connected'       => true,
							'status'          => 'failing',
							'last_sync_at'    => '2026-11-13T10:00:00Z',
							'last_error_code' => 'rate_limit_exceeded',
						),
					),
					array(
						'role'             => 'transactional',
						'store_url'        => 'https://example-store.test',
						'smaily_subdomain' => 'example-transactional',
						'features'         => array( 'transactional_emails' ),
						'smaily'           => array(
							'status'          => 'failing',
							'last_sync_at'    => null,
							'last_error_code' => 'credentials_rejected',
						),
					),
				),
			),
			$body
		);
	}

	public function test_the_report_carries_no_credential_customer_admin_or_message_text(): void {
		$this->connect_everything();
		$this->options['admin_email'] = 'owner@example-store.test';
		$this->options['blogname']    = 'Jane Doe Shop';

		$json = (string) json_encode( $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_SETUP_SAVED )['body'] );

		foreach ( array( 'api-user', 'api-pass', 'tx-user', 'tx-pass', self::API_KEY, 'owner@example-store.test', 'Jane Doe', 'MiuMjau Tenant' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $json );
		}
		self::assertSame( array(), $this->keys_outside_the_contract( (array) json_decode( $json, true ) ) );
	}

	public function test_a_feature_needs_its_switch_and_a_finished_setup(): void {
		$this->connect_everything();
		unset( $this->options['smly_plus_setup_completed'] );

		$features = $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY )['body']['connections'][0]['features'];

		self::assertSame( array( 'campaign_intelligence', 'browse_tracking' ), $features, 'Sync and the automations wait for the wizard; the engine connection does not.' );
	}

	public function test_an_automation_counts_only_mapped_to_a_workflow(): void {
		$this->connect_everything();
		$this->mapped                                           = array();
		$this->options['smaily_connect_abandoned_cart_status'] = array(
			'enabled'          => true,
			'autoresponder_id' => 0,
		);

		$features = $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY )['body']['connections'][0]['features'];

		self::assertNotContains( 'welcome_automation', $features );
		self::assertNotContains( 'abandoned_cart', $features );
	}

	public function test_a_refused_engine_connection_reads_not_connected(): void {
		$this->connect_everything();
		$this->engine_refused = true;

		self::assertFalse( $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY )['body']['connections'][0]['intelligence']['connected'] );
	}

	public function test_a_campaign_intelligence_disconnect_carries_the_smaily_connections_that_stay(): void {
		$this->connect_everything();

		$body = $this->builder()->build( ReportBuilder::EVENT_DISCONNECTED, '', array(), true )['body'];

		self::assertSame( 'disconnected', $body['event'] );
		self::assertArrayNotHasKey( 'trigger', $body, 'A trigger goes with a snapshot only.' );
		self::assertCount( 2, $body['connections'] );
		self::assertArrayNotHasKey( 'intelligence', $body['connections'][0] );
		self::assertNotContains( 'campaign_intelligence', $body['connections'][0]['features'] );
		self::assertNotContains( 'browse_tracking', $body['connections'][0]['features'] );
	}

	public function test_a_cleared_transactional_account_leaves_the_report(): void {
		$this->connect_everything();

		$body = $this->builder()->build( ReportBuilder::EVENT_DISCONNECTED, '', array( 'transactional' ) )['body'];

		self::assertSame( array( 'marketing' ), array_column( $body['connections'], 'role' ) );
		self::assertSame( 'example', $body['smaily_subdomain'], 'The main account still authenticates it.' );
	}

	public function test_a_deactivation_carries_the_connections_as_they_are(): void {
		$this->connect_everything();

		$body = $this->builder()->build( ReportBuilder::EVENT_DEACTIVATED )['body'];

		self::assertSame( 'deactivated', $body['event'] );
		self::assertArrayNotHasKey( 'trigger', $body );
		self::assertArrayHasKey( 'intelligence', $body['connections'][0] );
	}

	public function test_mode_a_reports_each_language_account_with_its_language(): void {
		$this->accounts['default'] = new CredentialSet( 'example', 'api-user', 'api-pass' );
		$this->accounts['et']      = new CredentialSet( 'Example-ET', 'et-user', 'et-pass' );
		$this->accounts['en']      = new CredentialSet( 'example-en', 'en-user', '' );
		$this->options['smly_plus_multilingual_mode'] = 'A';
		$GLOBALS['wpdb'] = $this->option_names( array( 'smly_plus_credentials_et', 'smly_plus_credentials_en', 'smly_plus_credentials_transactional' ) );

		$connections = $this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY )['body']['connections'];

		self::assertSame( array( 'example', 'example-et' ), array_column( $connections, 'smaily_subdomain' ), 'An incomplete language account is not connected.' );
		self::assertArrayNotHasKey( 'language', $connections[0] );
		self::assertSame( 'et', $connections[1]['language'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woocommerce_is_a_platform_when_it_is_active(): void {
		define( 'WC_VERSION', '9.9.5' );
		$this->connect_everything();

		self::assertSame(
			array(
				array(
					'name'    => 'wordpress',
					'version' => '6.8.3',
				),
				array(
					'name'    => 'woocommerce',
					'version' => '9.9.5',
				),
			),
			$this->builder()->build( ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY )['body']['platforms']
		);
	}

	private function connect_everything(): void {
		$this->accounts['default']       = new CredentialSet( 'Example', 'api-user', 'api-pass' );
		$this->accounts['transactional'] = new CredentialSet( 'example-transactional', 'tx-user', 'tx-pass' );
		$this->engine_connected          = true;
		$this->mapped                    = array( 'default' => array( 'welcome', 'abandoned_cart' ) );

		$this->options['smly_plus_setup_completed']               = true;
		$this->options['smly_plus_welcome_enabled']               = true;
		$this->options['smly_plus_first_order_enabled']           = true;
		$this->options['smaily_connect_abandoned_cart_status']    = array(
			'enabled'          => true,
			'autoresponder_id' => 0,
		);
		$this->options['smly_plus_rec_track_browsing']            = true;
		$this->options['smly_plus_transactional_emails_enabled']  = true;
		$this->options['smly_plus_order_confirmation_enabled']    = true;
		$this->options[ ConnectionHealth::OPTION ]                = array(
			'smaily' => array(
				'example'               => array(
					'error_code'   => '',
					'last_sync_at' => self::NOW - 3600,
				),
				'example-transactional' => array( 'error_code' => 'credentials_rejected' ),
			),
			'engine' => array(
				'error_code'   => 'rate_limit_exceeded',
				'last_sync_at' => self::NOW - 7200,
			),
		);
	}

	private function builder(): ReportBuilder {
		$accounts    = $this->accounts;
		$credentials = $this->createMock( Credentials::class );
		$credentials->method( 'get' )->willReturnCallback(
			static fn ( string $key ): ?CredentialSet => $accounts[ $key ] ?? null
		);

		$engine = $this->createMock( RecEngineSettings::class );
		$engine->method( 'is_connected' )->willReturn( $this->engine_connected );
		$engine->method( 'sending_allowed' )->willReturn( $this->engine_connected && ! $this->engine_refused );
		$engine->method( 'tenant_id' )->willReturn( self::TENANT_ID );
		$engine->method( 'tenant_name' )->willReturn( 'MiuMjau Tenant' );
		$engine->method( 'api_key' )->willReturn( self::API_KEY );

		return new class( $credentials, $engine, $this->mapped ) extends ReportBuilder {
			/** @var array<string, array<int, string>> */
			private array $mapped;

			/**
			 * @param array<string, array<int, string>> $mapped
			 */
			public function __construct( Credentials $credentials, RecEngineSettings $engine, array $mapped ) {
				parent::__construct( $credentials, $engine, new ConnectionHealth() );
				$this->mapped = $mapped;
			}

			protected function now(): int {
				return 1794571200;
			}

			protected function mapped_triggers(): array {
				return $this->mapped;
			}
		};
	}

	/**
	 * Every key of the body, nested, that the contract does not name.
	 *
	 * @param array<mixed> $body
	 *
	 * @return array<int, string>
	 */
	private function keys_outside_the_contract( array $body, string $path = '' ): array {
		$allowed = array(
			''                           => array( 'product', 'product_version', 'platforms', 'php_version', 'installation_url', 'smaily_subdomain', 'event', 'trigger', 'sent_at', 'connections' ),
			'platforms'                  => array( 'name', 'version' ),
			'connections'                => array( 'role', 'store_url', 'smaily_subdomain', 'language', 'features', 'smaily', 'intelligence' ),
			'connections.smaily'         => array( 'status', 'last_sync_at', 'last_error_code' ),
			'connections.intelligence'   => array( 'tenant_id', 'connected', 'status', 'last_sync_at', 'last_error_code' ),
			'connections.features'       => null,
		);

		$outside = array();
		foreach ( $body as $key => $value ) {
			if ( is_int( $key ) ) {
				$outside = array_merge( $outside, is_array( $value ) ? $this->keys_outside_the_contract( $value, $path ) : array() );
				continue;
			}
			if ( ! array_key_exists( $path, $allowed ) || ( $allowed[ $path ] !== null && ! in_array( $key, $allowed[ $path ], true ) ) ) {
				$outside[] = ltrim( $path . '.' . $key, '.' );
				continue;
			}
			if ( is_array( $value ) ) {
				$outside = array_merge( $outside, $this->keys_outside_the_contract( $value, ltrim( $path . '.' . $key, '.' ) ) );
			}
		}

		return $outside;
	}

	/**
	 * A $wpdb that lists these per-account option names.
	 *
	 * @param array<int, string> $names
	 */
	private function option_names( array $names ): object {
		return new class( $names ) {
			public string $options = 'wp_options';

			/** @var array<int, string> */
			private array $names;

			/**
			 * @param array<int, string> $names
			 */
			public function __construct( array $names ) {
				$this->names = $names;
			}

			public function esc_like( string $text ): string {
				return $text;
			}

			/**
			 * @param mixed ...$args
			 */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/**
			 * @return array<int, string>
			 */
			public function get_col( string $query ): array {
				return $this->names;
			}
		};
	}
}
