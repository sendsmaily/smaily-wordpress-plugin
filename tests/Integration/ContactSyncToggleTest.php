<?php
/**
 * Integration: "Sync contacts to Smaily" switched off actually stops the sync
 * (PRO-1742).
 *
 * The wizard/Settings toggle has always been stored as
 * `smaily_connect_subscriber_sync_enabled`, but the live sync read a
 * `smly_plus_subscriber_sync_enabled` that no version of this plugin has ever
 * written — so the gate always saw its default (on) and a merchant who
 * switched contact sync off kept sending contacts to Smaily.
 *
 * These cases drive the REAL settings-save route, the REAL account hooks and
 * the REAL contact backfill on the running store with only the Smaily
 * transport faked, and assert what does or doesn't reach it.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\LegacySettingsPage;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class ContactSyncToggleTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		HookHandler::reset_seen();
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
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_switching_the_sync_off_stops_contact_changes_reaching_smaily(): void {
		$this->save_toggle( false );

		$rows = $this->capture_contact_rows(
			function (): void {
				$this->change_a_customer();
			}
		);

		self::assertSame(
			array(),
			$rows,
			'Contact sync is switched off — no account change may reach Smaily.'
		);
	}

	public function test_leaving_the_sync_on_still_sends_contact_changes(): void {
		$this->save_toggle( true );

		$rows = $this->capture_contact_rows(
			function (): void {
				$this->change_a_customer();
			}
		);

		self::assertNotSame( array(), $rows, 'With the switch on, nothing about the sync changes.' );
		self::assertSame( $this->email_of( $this->created_users[0] ), (string) $rows[0]['email'] );
	}

	public function test_the_contact_backfill_honours_the_switch_too(): void {
		$this->save_toggle( false );

		$user_id = $this->make_customer();
		update_user_meta( $user_id, ContactAudience::OPTIN_META, 1 );

		self::assertSame(
			array(),
			$this->capture_contact_rows( fn () => $this->run_backfill() ),
			'The backfill sends contacts too — it must honour the same switch.'
		);

		// …and the same walk sends them again once the merchant switches it on.
		$this->save_toggle( true );
		delete_user_meta( $user_id, BackfillJob::META_KEY );

		$rows = $this->capture_contact_rows( fn () => $this->run_backfill() );
		self::assertSame( $this->email_of( $user_id ), (string) $rows[0]['email'] );
	}

	public function test_a_switch_saved_before_the_update_is_honoured_after_it(): void {
		// Written the way the pre-wizard settings page wrote it, not by hand:
		// the merchant enabled the sync once and later turned it back off.
		LegacySettingsPage::save_subscriber_sync_enabled( '1' );
		LegacySettingsPage::save_subscriber_sync_enabled( null );

		$rows = $this->capture_contact_rows(
			function (): void {
				$this->change_a_customer();
			}
		);

		self::assertSame(
			array(),
			$rows,
			'A store that switched contact sync off before updating stays switched off.'
		);
	}

	public function test_the_welcome_automation_is_not_switched_off_with_the_contact_sync(): void {
		$this->save_toggle( false );
		self::assertSame(
			200,
			RestRequestHelper::post(
				'/settings',
				array(
					'tab'  => 'woocommerce',
					'data' => array( 'welcomeEnabled' => true ),
				)
			)->get_status()
		);

		$user_id = $this->make_customer();
		do_action( 'woocommerce_created_customer', $user_id );

		$queued = array();
		foreach ( ( new EventQueue() )->pending( 50 ) as $row ) {
			$queued[] = (string) $row['event_type'];
		}

		self::assertContains(
			HookHandler::EVENT_AUTOMATION_WELCOME,
			$queued,
			'The welcome automation is a separate choice with its own toggle — turning contact sync off must not silently turn it off as well.'
		);
		self::assertNotContains( HookHandler::EVENT_CONTACT_SYNC, $queued );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Save the subscribers tab through the REAL wizard/settings route — the
	 * option shape under test is whatever that route writes.
	 */
	private function save_toggle( bool $enabled ): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled' => $enabled,
					'syncFields'            => array( 'first_name', 'last_name' ),
					'contactSyncMode'       => 'consent',
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/**
	 * Everything a customer can do that the live sync reacts to: get an
	 * account, opt in, then edit their profile.
	 */
	private function change_a_customer(): void {
		$user_id = $this->make_customer();
		update_user_meta( $user_id, ContactAudience::OPTIN_META, 1 );
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Mari',
			)
		);
	}

	private function make_customer(): int {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_toggle_' . $slug,
				'user_email' => 'toggle-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	private function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	private function run_backfill(): void {
		$job = new BackfillJob( new Client( 'testsub', 'tester', 'pw' ) );
		$job->start();
		$guard = 0;
		do {
			$result = $job->process_batch( 200 );
		} while ( ! $result['completed'] && ++$guard < 50 );
	}

	/**
	 * Run $run with the transport faked and return every contact row it handed
	 * over — the queue is drained inside, so a live hook's row counts too.
	 *
	 * @param callable(): void $run
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function capture_contact_rows( callable $run ): array {
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
			$run();
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$rows = array();
		foreach ( $bodies as $body ) {
			if ( is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ) {
				foreach ( $body as $row ) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}
}
