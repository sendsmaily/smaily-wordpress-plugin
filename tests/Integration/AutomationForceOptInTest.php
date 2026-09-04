<?php
/**
 * Integration: an automation trigger never re-subscribes a contact (PRO-1716).
 *
 * The "Force opt-in on automation triggers" setting is retired. Its stored
 * option stays behind on a store that once enabled it, so what a live store
 * sends must not depend on it any more: every trigger POSTs
 * `force_opt_in=false`, whatever the retired option says, under the
 * legitimate-interest preset that was the only place the setting applied.
 *
 * The cases drive the REAL queue + flusher + Smaily client with only the HTTP
 * transport faked (the established pre_http_request pattern), and the REAL
 * settings route for the save-tolerance case.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class AutomationForceOptInTest extends TestCase {

	/** The option the retired setting used to write. */
	private const RETIRED_OPTION = 'smly_plus_contact_sync_automation_force_opt_in';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );

		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_store_that_never_saw_the_setting_sends_force_opt_in_false(): void {
		self::assertFalse( $this->triggered_force_opt_in() );
	}

	public function test_a_store_that_had_the_setting_ON_now_sends_force_opt_in_false(): void {
		update_option( self::RETIRED_OPTION, '1' );

		self::assertFalse(
			$this->triggered_force_opt_in(),
			'The retired option is an orphan — it must not change what a trigger sends.'
		);
	}

	public function test_a_store_that_had_the_setting_OFF_sends_force_opt_in_false(): void {
		update_option( self::RETIRED_OPTION, '' );

		self::assertFalse( $this->triggered_force_opt_in() );
	}

	public function test_saving_the_subscribers_tab_with_the_retired_field_still_succeeds(): void {
		update_option( self::RETIRED_OPTION, '1' );

		// What a browser holding a cached pre-PRO-1716 admin bundle posts.
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled' => true,
					'syncFields'            => array( 'first_name' ),
					'contactSyncMode'       => ContactSyncMode::MODE_LEGITIMATE_INTEREST,
					'automationForceOptIn'  => true,
				),
			)
		);

		self::assertSame( 200, $response->get_status(), 'A stale field must be ignored, not rejected.' );
		self::assertTrue( $response->get_data()['saved'] );
		self::assertSame(
			'1',
			get_option( self::RETIRED_OPTION ),
			'The save must leave the orphan untouched rather than write it again.'
		);

		$saved = ( new \Smaily\Connect\Wizard\EnvDetector() )->saved_settings();
		self::assertArrayNotHasKey( 'automationForceOptIn', $saved, 'The boot payload no longer carries the field.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Run one welcome automation through the real queue + flusher on a
	 * legitimate-interest store and report the `force_opt_in` that reached the
	 * Smaily transport.
	 */
	private function triggered_force_opt_in(): bool {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		$this->map_welcome_to_workflow( '4242' );

		( new EventQueue() )->enqueue(
			HookHandler::EVENT_AUTOMATION_WELCOME,
			'1',
			array( 'email' => 'force-opt-in@example.test' )
		);

		foreach ( $this->flush() as $body ) {
			if ( is_array( $body ) && isset( $body['autoresponder'] ) && (string) $body['autoresponder'] === '4242' ) {
				self::assertArrayHasKey( 'force_opt_in', $body );
				return (bool) $body['force_opt_in'];
			}
		}

		self::fail( 'The welcome automation must reach the Smaily transport.' );
	}

	/** Turn the welcome trigger on and map it, through the real settings route. */
	private function map_welcome_to_workflow( string $workflow_id ): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'welcomeEnabled'     => true,
					'automationMappings' => array(
						array(
							'triggerType'       => 'welcome',
							'language'          => 'default',
							'accountKey'        => 'default',
							'workflowId'        => $workflow_id,
							'isDefaultFallback' => true,
						),
					),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/**
	 * Drain the Smaily queue through a faked transport, collecting every POST body.
	 *
	 * @return array<int, mixed>
	 */
	private function flush(): array {
		$bodies = array();
		$fake   = static function ( $pre, $args ) use ( &$bodies ) {
			$bodies[] = isset( $args['body'] ) ? $args['body'] : null;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 101,
						'message' => 'OK',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $bodies;
	}
}
