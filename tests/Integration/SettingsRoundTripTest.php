<?php
/**
 * Integration: POST /settings + boot-payload round-trip.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily\Connect\Wizard\EnvDetector;

/**
 * What Faas-2 bug this catches:
 *
 *   Sub-PR 2.H.19 #1 — `wordpressSubscriptionCheckbox` did not persist.
 *
 *   SettingsEndpoint::save_subscribers() wrote to
 *   `smly_plus_wordpress_subscription_checkbox`, but
 *   EnvDetector::saved_settings() read from
 *   `smaily_connect_wp_subscription_enabled`. Two different keys, no
 *   round-trip. The mock unit tests passed because each side asserted
 *   its own internal contract; nothing exercised the writer + reader
 *   in the same test against the same wp_options state.
 *
 *   This test exercises every tab's write + read in pairs. The
 *   `expected` map uses the SAME camelCase keys the React boot
 *   payload uses (the read-side surface) — if a writer puts the
 *   value under a different option name, EnvDetector reads the
 *   default and the assertion fails loudly with the diverging key.
 *
 *   Faas 3 sub-PRs only have to extend the per-tab fixtures here as
 *   new fields land; the structural check (writer key == reader key)
 *   is then automatic.
 */
final class SettingsRoundTripTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
	}

	public function test_subscribers_tab_round_trip(): void {
		$payload = array(
			'subscriberSyncEnabled'         => true,
			'syncFields'                    => array( 'first_name', 'last_name' ),
			'wordpressSubscriptionCheckbox' => true,
			'checkoutSubscriptionCheckbox'  => false,
		);

		$response = RestRequestHelper::post(
			'/settings',
			array( 'tab' => 'subscribers', 'data' => $payload )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['saved'] );

		$saved = ( new EnvDetector() )->saved_settings();

		// Symmetric check: every key we wrote, we must read.
		self::assertSame( true, $saved['subscriberSyncEnabled'], 'subscriberSyncEnabled round-trip broken' );
		self::assertSame( array( 'first_name', 'last_name' ), $saved['syncFields'], 'syncFields round-trip broken' );
		self::assertSame(
			true,
			$saved['wordpressSubscriptionCheckbox'],
			'Bug-2.H.19#1: wordpressSubscriptionCheckbox writer / reader keys diverge'
		);
		self::assertSame( false, $saved['checkoutSubscriptionCheckbox'], 'checkoutSubscriptionCheckbox round-trip broken' );

		// Negative case — flipping a true value back to false must also persist.
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled'         => true,
					'syncFields'                    => array( 'first_name' ),
					'wordpressSubscriptionCheckbox' => false,
					'checkoutSubscriptionCheckbox'  => false,
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
		$saved = ( new EnvDetector() )->saved_settings();
		self::assertSame( false, $saved['wordpressSubscriptionCheckbox'], 'Toggle-off did not persist back through the boot payload' );
	}

	public function test_woocommerce_tab_round_trip_including_automation_mappings(): void {
		$payload = array(
			'welcomeEnabled'             => true,
			'firstOrderEnabled'          => false,
			'abandonedCartEnabled'       => true,
			'abandonedCartCutoffMinutes' => 45,
			'automationMappings'         => array(
				array(
					'triggerType'       => 'welcome',
					'language'          => 'default',
					'accountKey'        => 'default',
					'workflowId'        => '101',
					'isDefaultFallback' => true,
				),
				array(
					'triggerType'       => 'abandoned_cart',
					'language'          => 'default',
					'accountKey'        => 'default',
					'workflowId'        => '202',
					'isDefaultFallback' => true,
				),
			),
		);

		$response = RestRequestHelper::post(
			'/settings',
			array( 'tab' => 'woocommerce', 'data' => $payload )
		);
		self::assertSame( 200, $response->get_status() );

		$saved = ( new EnvDetector() )->saved_settings();

		self::assertSame( true, $saved['welcomeEnabled'] );
		self::assertSame( false, $saved['firstOrderEnabled'] );
		self::assertSame( true, $saved['abandonedCartEnabled'] );
		self::assertSame( 45, $saved['abandonedCartCutoffMinutes'] );

		// Bug-2.H.19#2: the mapping table got persisted but the boot
		// payload never read it back, so the React layer started with
		// automationMappings=[] on every reload. This row-by-row check
		// pins both the writer (replace_automation_mappings) and the
		// reader (EnvDetector::automation_mappings) to the same shape.
		self::assertArrayHasKey( 'automationMappings', $saved );
		self::assertCount( 2, $saved['automationMappings'] );

		$by_trigger = array();
		foreach ( $saved['automationMappings'] as $row ) {
			$by_trigger[ $row['triggerType'] ] = $row;
		}

		self::assertSame( '101', $by_trigger['welcome']['workflowId'] );
		self::assertSame( '202', $by_trigger['abandoned_cart']['workflowId'] );
		self::assertTrue( $by_trigger['welcome']['isDefaultFallback'] );
	}

	public function test_connection_tab_round_trip_including_transactional_account(): void {
		// PRO-1540 — the transactional account (toggle + credentials) moved
		// to the connection tab's payload, mirroring where the React UI now
		// renders it. Same writer/reader symmetry check PRO-1504 stage 1 had.
		$payload = array(
			'smailyCredentials'          => array(
				'subdomain' => 'roundtrip-main',
				'username'  => 'alice@example.com',
				'password'  => 'main-secret',
			),
			'multilingualMode'           => 'single',
			'transactionalEmailsEnabled' => true,
			'transactionalCredentials'   => array(
				'subdomain' => 'trxroundtrip',
				'username'  => 'trx-user',
				'password'  => 'trx-secret',
			),
		);

		$response = RestRequestHelper::post(
			'/settings',
			array( 'tab' => 'connection', 'data' => $payload )
		);
		self::assertSame( 200, $response->get_status() );

		$saved = ( new EnvDetector() )->saved_settings();

		self::assertTrue( $saved['transactionalEmailsEnabled'] );
		self::assertSame( 'trxroundtrip', $saved['transactionalCredentials']['subdomain'] );
		self::assertSame( 'trx-user', $saved['transactionalCredentials']['username'] );
		self::assertSame( '', $saved['transactionalCredentials']['password'], 'Password must never round-trip to the boot payload.' );
		self::assertTrue( $saved['transactionalConnected'], 'A complete credential pair must mark the account verified.' );
	}

	public function test_woocommerce_tab_round_trip_including_transactional_triggers(): void {
		// PRO-1504 stage 1 — the two trigger mappings + "counts as shipped"
		// status set stay on the woocommerce tab (PRO-1540 only moved the
		// account connection itself, not these).
		$payload = array(
			'orderConfirmationEnabled'    => true,
			'shippingConfirmationEnabled' => true,
			'shippedOrderStatuses'        => array( 'completed', 'shipped' ),
			'automationMappings'          => array(
				array(
					'triggerType'       => 'order_confirmation',
					'language'          => 'default',
					'accountKey'        => 'transactional',
					'workflowId'        => '301',
					'isDefaultFallback' => true,
				),
				array(
					'triggerType'       => 'shipping_confirmation',
					'language'          => 'default',
					'accountKey'        => 'transactional',
					'workflowId'        => '302',
					'isDefaultFallback' => true,
				),
			),
		);

		$response = RestRequestHelper::post(
			'/settings',
			array( 'tab' => 'woocommerce', 'data' => $payload )
		);
		self::assertSame( 200, $response->get_status() );

		$saved = ( new EnvDetector() )->saved_settings();

		self::assertTrue( $saved['orderConfirmationEnabled'] );
		self::assertTrue( $saved['shippingConfirmationEnabled'] );
		self::assertSame( array( 'completed', 'shipped' ), $saved['shippedOrderStatuses'] );

		$by_trigger = array();
		foreach ( $saved['automationMappings'] as $row ) {
			$by_trigger[ $row['triggerType'] ] = $row;
		}
		self::assertSame( '301', $by_trigger['order_confirmation']['workflowId'] );
		self::assertSame( 'transactional', $by_trigger['order_confirmation']['accountKey'] );
		self::assertSame( '302', $by_trigger['shipping_confirmation']['workflowId'] );
	}

	public function test_connection_tab_preserves_password_on_empty_resave(): void {
		// Bug-2.H.19#3 regression guard. The "already-connected" view on
		// Step 1 leaves password='' in React state. Continue would POST
		// the empty string back; if SettingsEndpoint::save_connection
		// blindly encrypted '' and overwrote the stored secret, the
		// workflow endpoint would silently fail on every second wizard
		// visit because the decrypted password is empty.
		$first_save = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'connection',
				'data' => array(
					'smailyCredentials' => array(
						'subdomain' => 'roundtrip',
						'username'  => 'alice@example.com',
						'password'  => 'real-secret-v1',
					),
					'multilingualMode'  => 'single',
				),
			)
		);
		self::assertSame( 200, $first_save->get_status() );
		$row1 = get_option( 'smaily_connect_api_credentials' );
		self::assertIsString( $row1['password'] );
		self::assertNotSame( '', $row1['password'], 'First save did not store a password.' );

		$second_save = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'connection',
				'data' => array(
					'smailyCredentials' => array(
						'subdomain' => 'roundtrip',
						'username'  => 'alice@example.com',
						'password'  => '',
					),
					'multilingualMode'  => 'single',
				),
			)
		);
		self::assertSame( 200, $second_save->get_status() );

		$row2 = get_option( 'smaily_connect_api_credentials' );
		self::assertSame(
			$row1['password'],
			$row2['password'],
			'Bug-2.H.19#3: empty inbound password wiped the stored secret instead of preserving it.'
		);
	}
}
