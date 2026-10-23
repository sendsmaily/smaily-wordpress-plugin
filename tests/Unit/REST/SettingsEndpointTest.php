<?php
/**
 * SettingsEndpoint tests — per-tab payload validation + persistence.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\SettingsEndpoint;
use WP_REST_Request;

final class SettingsEndpointTest extends TestCase {

	/** @var array<string, mixed> Captures update_option() writes. */
	private array $option_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );

		$this->option_writes = array();
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->option_writes[ $key ] = $value;
				return true;
			}
		);
		// The empty-inbound-password preserve path reads the stored secret
		// back via get_option.
		$writes =& $this->option_writes;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$writes ) {
				return $writes[ $key ] ?? $default;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $value ): string => json_encode( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_rejects_unknown_tab(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'mystery' );
		$request->set_param( 'data', array() );

		$response = $endpoint->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertFalse( $response->get_data()['saved'] );
	}

	public function test_connection_tab_persists_credentials_via_legacy_option(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials' => array(
					'subdomain' => 'mypetshop',
					'username'  => 'alice',
					'password'  => 's3cret',
				),
				'multilingualMode'  => 'B',
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['saved'] );
		self::assertArrayHasKey( 'smaily_connect_api_credentials', $this->option_writes );
		self::assertSame( 'mypetshop', $this->option_writes['smaily_connect_api_credentials']['subdomain'] );
		self::assertSame( 'B', $this->option_writes['smly_plus_multilingual_mode'] );
	}

	public function test_connection_tab_persists_credentials_plus_verified_flag_mode_b(): void {
		// Regression test for sub-PR 2.H.15. Erkki's staging reported a
		// blank smailyCredentials round-trip on Mode B + multilingual
		// (Polylang, two languages). The legacy 2.H.10 path didn't carry
		// the verified-flag write. Pin both writes here so any future
		// reorder / early-return between credential persistence and the
		// verified flag fails the suite.
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials' => array(
					'subdomain' => 'smailydemo',
					'username'  => 'iedky7',
					'password'  => 'plaintext',
				),
				'multilingualMode'         => 'B',
				'perLanguageAccounts'      => array(),
				'defaultFallbackAccountKey' => 'default',
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['saved'] );

		// Credentials persisted with full triple.
		self::assertArrayHasKey( 'smaily_connect_api_credentials', $this->option_writes );
		$creds = $this->option_writes['smaily_connect_api_credentials'];
		self::assertSame( 'smailydemo', $creds['subdomain'] );
		self::assertSame( 'iedky7', $creds['username'] );
		self::assertNotSame( '', $creds['password'], 'Password must be encrypted, not blank.' );

		// Verified flag flipped on alongside the credential write.
		self::assertTrue( $this->option_writes['smly_plus_default_connection_verified'] );

		// Mode B carried through.
		self::assertSame( 'B', $this->option_writes['smly_plus_multilingual_mode'] );
		self::assertSame( 'default', $this->option_writes['smly_plus_default_fallback_account'] );
	}

	public function test_connection_tab_keeps_the_stored_password_when_none_was_typed(): void {
		// PRO-2286: after a connection test that reused the stored password,
		// Continue arrives with an empty password field. The secret must
		// survive that save — otherwise unlocking Steps 2-6 would cost the
		// merchant the very credentials the test just proved work.
		$this->option_writes['smaily_connect_api_credentials'] = array(
			'subdomain' => 'smailydemo',
			'username'  => 'iedky7',
			'password'  => 'already-encrypted-blob',
		);

		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials' => array(
					'subdomain' => 'smailydemo',
					'username'  => 'iedky7',
					'password'  => '',
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 200, $response->get_status() );
		$creds = $this->option_writes['smaily_connect_api_credentials'];
		self::assertSame( 'already-encrypted-blob', $creds['password'] );
		self::assertTrue( $this->option_writes['smly_plus_default_connection_verified'] );
	}

	public function test_connection_tab_rejects_missing_subdomain(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials' => array(
					'subdomain' => '',
					'username'  => 'alice',
					'password'  => 's3cret',
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertNotEmpty( $response->get_data()['errors'] );
		self::assertSame( 'subdomain', $response->get_data()['errors'][0]['field'] );
		self::assertArrayNotHasKey( 'smaily_connect_api_credentials', $this->option_writes );
	}

	public function test_subscribers_tab_persists_field_list_and_toggles(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'subscribers' );
		$request->set_param(
			'data',
			array(
				'subscriberSyncEnabled'         => true,
				'syncFields'                    => array( 'first_name', 'last_name' ),
				'wordpressSubscriptionCheckbox' => true,
				'checkoutSubscriptionCheckbox'  => false,
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertSame(
			array( 'first_name', 'last_name' ),
			$this->option_writes['smaily_connect_subscriber_sync_fields']
		);
		self::assertTrue( $this->option_writes['smly_plus_wordpress_subscription_checkbox'] );
		self::assertFalse( $this->option_writes['smaily_connect_checkout_subscription_enabled'] );
	}

	public function test_woocommerce_tab_clamps_cutoff_and_persists_toggles(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'welcomeEnabled'             => true,
				'firstOrderEnabled'          => false,
				'abandonedCartEnabled'       => true,
				'abandonedCartCutoffMinutes' => 5,    // below 10-minute floor
				'automationMappings'         => array(
					array(
						'triggerType'       => 'welcome',
						'language'          => 'et_EE',
						'accountKey'        => 'default',
						'workflowId'        => '42',
						'isDefaultFallback' => true,
					),
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_welcome_enabled'] );
		self::assertFalse( $this->option_writes['smly_plus_first_order_enabled'] );
		self::assertSame( 10, $this->option_writes['smaily_connect_abandoned_cart_cutoff'] );
		self::assertFalse( $this->option_writes['smly_plus_transactional_personal_data_enabled'], 'PRO-3190: a payload without the switch saves it off.' );
	}

	public function test_connection_tab_persists_transactional_account_and_toggle(): void {
		// PRO-1540 — the transactional account connection (toggle +
		// credentials) moved from the woocommerce tab's payload to the
		// connection tab's, mirroring where the React UI now renders it.
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials'          => array(
					'subdomain' => 'mypetshop',
					'username'  => 'alice',
					'password'  => 's3cret',
				),
				'transactionalEmailsEnabled' => true,
				'transactionalCredentials'   => array(
					'subdomain' => 'trxdemo',
					'username'  => 'bob',
					'password'  => 'plaintext',
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_transactional_emails_enabled'] );

		$creds = $this->option_writes['smly_plus_credentials_transactional'];
		self::assertSame( 'trxdemo', $creds['subdomain'] );
		self::assertSame( 'bob', $creds['username'] );
		self::assertNotSame( '', $creds['password'], 'Password must be encrypted, not blank.' );

		self::assertTrue( $this->option_writes['smly_plus_transactional_connection_verified'] );
	}

	public function test_connection_tab_marks_transactional_connection_unverified_when_credentials_incomplete(): void {
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'connection' );
		$request->set_param(
			'data',
			array(
				'smailyCredentials'        => array(
					'subdomain' => 'mypetshop',
					'username'  => 'alice',
					'password'  => 's3cret',
				),
				'transactionalCredentials' => array(
					'subdomain' => '',
					'username'  => '',
					'password'  => '',
				),
			)
		);

		$endpoint->handle( $request );

		self::assertFalse( $this->option_writes['smly_plus_transactional_connection_verified'] );
	}

	public function test_woocommerce_tab_persists_transactional_triggers_and_shipped_statuses(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'welcomeEnabled'              => false,
				'firstOrderEnabled'           => false,
				'abandonedCartEnabled'        => false,
				'abandonedCartCutoffMinutes'  => 30,
				'automationMappings'          => array(),
				'orderConfirmationEnabled'    => true,
				'shippingConfirmationEnabled' => true,
				'shippedOrderStatuses'        => array( 'completed', 'shipped' ),
				'transactionalPersonalData'   => true,
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_transactional_personal_data_enabled'] );
		self::assertTrue( $this->option_writes['smly_plus_order_confirmation_enabled'] );
		self::assertTrue( $this->option_writes['smly_plus_shipping_confirmation_enabled'] );
		self::assertSame(
			array( 'completed', 'shipped' ),
			$this->option_writes['smly_plus_shipped_order_statuses']
		);

		// The woocommerce tab must NOT touch the transactional account —
		// that slice belongs to the connection tab's payload only.
		self::assertArrayNotHasKey( 'smly_plus_transactional_emails_enabled', $this->option_writes );
		self::assertArrayNotHasKey( 'smly_plus_credentials_transactional', $this->option_writes );
	}

	public function test_woocommerce_tab_persists_the_follow_up_email_settings(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'automationMappings'     => array(),
				'followUpEnabled'        => true,
				'followUpOrderStatuses'  => array( 'completed', 'delivered' ),
				'followUpDelayDays'      => 14,
			)
		);

		self::assertTrue( $endpoint->handle( $request )->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_follow_up_enabled'] );
		self::assertSame( array( 'completed', 'delivered' ), $this->option_writes['smly_plus_follow_up_order_statuses'] );
		self::assertSame( 14, $this->option_writes['smly_plus_follow_up_delay_days'] );
	}

	/**
	 * @dataProvider invalid_follow_up_delays
	 *
	 * @param mixed $days
	 */
	public function test_woocommerce_tab_refuses_a_follow_up_delay_outside_one_to_ninety_days( $days ): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'automationMappings' => array(),
				'followUpEnabled'    => true,
				'followUpDelayDays'  => $days,
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'followUpDelayDays', $response->get_data()['errors'][0]['field'] );
		self::assertArrayNotHasKey( 'smly_plus_follow_up_delay_days', $this->option_writes );
		self::assertArrayNotHasKey( 'smly_plus_follow_up_enabled', $this->option_writes, 'Nothing on the tab is saved.' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_follow_up_delays(): array {
		return array(
			'zero'     => array( 0 ),
			'too long' => array( 91 ),
			'fraction' => array( 2.5 ),
			'text'     => array( 'soon' ),
			'negative' => array( -3 ),
		);
	}

	public function test_woocommerce_tab_persists_each_automations_discount_code_settings(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'automationMappings'  => array(),
				'automationDiscounts' => array(
					'welcome'        => array(
						'enabled'    => true,
						'templateId' => 12,
						'validDays'  => 14,
					),
					'first_order'    => array(
						'enabled'    => false,
						'templateId' => 0,
						'validDays'  => 7,
					),
					'abandoned_cart' => array(
						'enabled'    => true,
						'templateId' => '31',
						'validDays'  => '3',
					),
				),
			)
		);

		self::assertTrue( $endpoint->handle( $request )->get_data()['saved'] );
		self::assertSame(
			array(
				'welcome'        => array(
					'enabled'     => true,
					'template_id' => 12,
					'valid_days'  => 14,
				),
				'first_order'    => array(
					'enabled'     => false,
					'template_id' => 0,
					'valid_days'  => 7,
				),
				'abandoned_cart' => array(
					'enabled'     => true,
					'template_id' => 31,
					'valid_days'  => 3,
				),
			),
			$this->option_writes['smly_plus_automation_discounts']
		);
	}

	/**
	 * @dataProvider invalid_discount_days
	 *
	 * @param mixed $days
	 */
	public function test_woocommerce_tab_refuses_a_discount_code_valid_outside_one_to_365_days( $days ): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'automationMappings'  => array(),
				'welcomeEnabled'      => true,
				'automationDiscounts' => array(
					'welcome' => array(
						'enabled'    => true,
						'templateId' => 12,
						'validDays'  => $days,
					),
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'automationDiscounts', $response->get_data()['errors'][0]['field'] );
		self::assertArrayNotHasKey( 'smly_plus_automation_discounts', $this->option_writes );
		self::assertArrayNotHasKey( 'smly_plus_welcome_enabled', $this->option_writes, 'Nothing on the tab is saved.' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_discount_days(): array {
		return array(
			'zero'     => array( 0 ),
			'too long' => array( 366 ),
			'fraction' => array( 2.5 ),
			'text'     => array( 'soon' ),
		);
	}

	public function test_woocommerce_tab_accepts_transactional_trigger_mappings(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'automationMappings' => array(
					array(
						'triggerType'       => 'order_confirmation',
						'language'          => 'default',
						'accountKey'        => 'transactional',
						'workflowId'        => '99',
						'isDefaultFallback' => true,
					),
					array(
						'triggerType'       => 'shipping_confirmation',
						'language'          => 'default',
						'accountKey'        => 'transactional',
						'workflowId'        => '100',
						'isDefaultFallback' => true,
					),
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		// 1 TRUNCATE + 2 row INSERTs.
		self::assertCount( 3, $GLOBALS['wpdb']->queries );
	}

	public function test_woocommerce_tab_rejects_unknown_trigger_type(): void {
		$endpoint        = new SettingsEndpoint();
		$GLOBALS['wpdb'] = $this->fake_wpdb_with_mappings();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'woocommerce' );
		$request->set_param(
			'data',
			array(
				'automationMappings' => array(
					array(
						'triggerType'       => 'mystery_trigger',
						'language'          => 'default',
						'accountKey'        => 'default',
						'workflowId'        => '1',
						'isDefaultFallback' => true,
					),
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		// Only the TRUNCATE — an unknown trigger_type must never reach an INSERT.
		self::assertCount( 1, $GLOBALS['wpdb']->queries );
	}

	public function test_a_save_answers_with_the_fresh_confirmation_status(): void {
		// PRO-4091: the card's status line is read AFTER the save, so a
		// merchant who just picked the missing workflow sees it take effect.
		$endpoint = new SettingsEndpoint(
			static function (): array {
				return array( 'order_confirmation' => array( 'reason' => '' ) );
			}
		);

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'recommendations' );
		$request->set_param( 'data', array( 'recEngineFeatures' => array( 'trackBrowsing' => false ) ) );

		$data = $endpoint->handle( $request )->get_data();

		self::assertTrue( $data['saved'] );
		self::assertSame( array( 'order_confirmation' => array( 'reason' => '' ) ), $data['transactionalStatus'] );
	}

	public function test_recommendations_tab_persists_only_browse_preference(): void {
		// 3.9: the per-domain sync toggles were removed — connecting the engine
		// syncs all domains unconditionally. The only persisted Step-4 preference
		// is browse tracking. The four removed keys must NOT be written (a
		// regression guard so a reintroduced toggle doesn't silently re-persist).
		$endpoint = new SettingsEndpoint();

		$request = new WP_REST_Request();
		$request->set_param( 'tab', 'recommendations' );
		$request->set_param(
			'data',
			array(
				'recEngineFeatures' => array(
					'trackBrowsing' => true,
				),
			)
		);

		$response = $endpoint->handle( $request );

		self::assertTrue( $response->get_data()['saved'] );
		self::assertTrue( $this->option_writes['smly_plus_rec_track_browsing'] );
		self::assertArrayNotHasKey( 'smly_plus_rec_sync_orders', $this->option_writes );
		self::assertArrayNotHasKey( 'smly_plus_rec_sync_customers', $this->option_writes );
		self::assertArrayNotHasKey( 'smly_plus_rec_sync_products', $this->option_writes );
		self::assertArrayNotHasKey( 'smly_plus_rec_track_cart_events', $this->option_writes );
	}

	public function test_permission_check_denies_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$endpoint = new SettingsEndpoint();
		$result   = $endpoint->permission_check( new WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	private function fake_wpdb_with_mappings(): object {
		return new class() {
			public string $prefix = 'wp_';
			public array $queries = array();

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
		};
	}
}
