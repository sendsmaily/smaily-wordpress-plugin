<?php
/**
 * Integration: the Dashboard's state route (CON-464) counts the plugin's own
 * queues and reads the health check's stored state, through the real route.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\DB\QueueJanitor;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\REST\DashboardEndpoint;
use Smaily\Connect\Report\ConnectionHealth;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\QueueRowFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use WP_REST_Request;

final class DashboardEndpointTest extends TestCase {

	private const PROBE_HOOK = 'smly_plus_con464_probe';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . QueueJanitor::AS_ACTIONS_TABLE, array( 'hook' => self::PROBE_HOOK ) );
		EnvScrub::reset();
		parent::tearDown();
	}

	public function test_the_tiles_count_what_went_out_in_thirty_days_what_waits_today_and_what_failed(): void {
		$now = time();
		// Two contact syncs delivered, one skipped (sent nothing), one too old.
		$this->smaily_row( 'contact.sync', 'sent', $now - 3600, '{"http":200,"outcome":"accepted"}' );
		$this->smaily_row( 'contact.sync', 'sent', $now - 5 * DAY_IN_SECONDS, null );
		$this->smaily_row( 'contact.sync', 'sent', $now - 3600, '{"outcome":"skipped","reason":"stale_subscription"}' );
		$this->smaily_row( 'contact.sync', 'sent', $now - 31 * DAY_IN_SECONDS, null );
		// One catalog item delivered; the nightly list is no catalog item.
		$this->rec_row( 'catalog.upsert', 'sent', $now - 3600 );
		$this->rec_row( 'catalog.manifest', 'sent', $now - 3600 );
		// Waiting: one in each queue queued just now, one queued long ago.
		$this->smaily_row( 'automation.welcome', 'pending', $now, null );
		$this->rec_row( 'order.upsert', 'pending', $now );
		$this->rec_row( 'order.upsert', 'pending', $now - 3 * DAY_IN_SECONDS );
		// Failed in the last 24 hours, in both queues.
		$this->smaily_row( 'contact.sync', 'failed', $now - 3600, null );
		$this->rec_row( 'customer.upsert', 'failed', $now - 3600 );

		$state = $this->state();

		self::assertSame( 2, $state['contact_syncs_30d'] );
		self::assertSame( 1, $state['catalog_items_30d'] );
		self::assertSame( 2, $state['queued_today'] );
		self::assertSame( 2, $state['failed_24h'] );
	}

	/** The Dashboard and the Log page count failed rows of both queues alike — Campaign Intelligence rows included. */
	public function test_failed_catalog_rows_count_on_the_dashboard_as_on_the_log_page(): void {
		for ( $i = 0; $i < 29; $i++ ) {
			$this->rec_row( 'catalog.upsert', 'failed', time() - 600, 'http_401' );
		}

		$events = RestRequestHelper::get( '/events', array( 'per_page' => 10 ) );
		self::assertSame( 200, $events->get_status() );

		self::assertSame( 29, $this->state()['failed_24h'] );
		self::assertSame( 29, $events->get_data()['failed_24h'] );
		self::assertSame( 29, NotificationManager::failed_last_24h() );
	}

	public function test_an_engine_that_refuses_the_stored_key_reads_as_key_rejected(): void {
		EnvSeed::connect();
		update_option( ConnectionHealth::OPTION, array( 'engine' => array( 'error_code' => 'unauthorised' ) ), false );

		self::assertTrue( $this->state()['engine_key_rejected'] );

		// A deactivated account is told apart (is_refused()), not as a rejected key.
		( new RecEngineSettings() )->mark_refused();
		self::assertFalse( $this->state()['engine_key_rejected'] );
	}

	public function test_a_key_the_engine_takes_again_or_no_connection_is_no_rejected_key(): void {
		update_option( ConnectionHealth::OPTION, array( 'engine' => array( 'error_code' => 'unauthorised' ) ), false );
		self::assertFalse( $this->state()['engine_key_rejected'], 'not connected' );

		EnvSeed::connect();
		update_option( ConnectionHealth::OPTION, array( 'engine' => array( 'error_code' => '' ) ), false );
		self::assertFalse( $this->state()['engine_key_rejected'] );
	}

	public function test_the_smaily_and_engine_states_come_from_the_health_check(): void {
		EnvSeed::connect();
		update_option(
			NotificationManager::OPTION_NOTICES,
			array(
				'smaily_plan_blocked' => array( 'severity' => 'error' ),
				'engine_down'         => array( 'severity' => 'error' ),
			),
			false
		);

		$state = $this->state();

		self::assertSame( 'plan_blocked', $state['smaily_refusal'] );
		self::assertTrue( $state['engine_down'] );
	}

	public function test_an_engine_that_is_not_connected_is_never_down_or_missing_a_product_list(): void {
		update_option( NotificationManager::OPTION_NOTICES, array( 'engine_down' => array( 'severity' => 'error' ) ), false );

		$state = $this->state();

		self::assertFalse( $state['engine_down'] );
		self::assertSame( 0, $state['unsent_product_list_nights'] );
		self::assertSame( '', $state['smaily_refusal'] );
	}

	public function test_the_missing_product_list_counts_the_nights_since_the_last_one_sent(): void {
		EnvSeed::connect();
		$this->rec_row( 'catalog.manifest', 'sent', time() - 4 * DAY_IN_SECONDS - 60 );
		$this->rec_row( 'catalog.manifest', 'failed', time() - DAY_IN_SECONDS );

		self::assertSame( 4, $this->state()['unsent_product_list_nights'] );

		$this->rec_row( 'catalog.manifest', 'sent', time() - 3600 );

		self::assertSame( 0, $this->state()['unsent_product_list_nights'] );
	}

	public function test_a_recent_run_of_our_actions_reads_as_running(): void {
		global $wpdb;
		$ran = gmdate( 'Y-m-d H:i:s', time() - 30 );
		$wpdb->insert(
			$wpdb->prefix . QueueJanitor::AS_ACTIONS_TABLE,
			array(
				'hook'                 => self::PROBE_HOOK,
				'status'               => 'complete',
				'scheduled_date_gmt'   => $ran,
				'scheduled_date_local' => $ran,
				'last_attempt_gmt'     => $ran,
				'last_attempt_local'   => $ran,
				'args'                 => '[]',
			)
		);

		$background = $this->state()['background'];

		self::assertTrue( $background['running'] );
		// The suite's own Action Scheduler runs our flushers too: the newest run is this one or later.
		self::assertGreaterThanOrEqual( $ran, $background['last_run'] );
		self::assertSame( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, $background['wp_cron_disabled'] );
	}

	public function test_the_route_refuses_a_request_without_a_nonce_or_the_capability(): void {
		$request = new WP_REST_Request( 'GET', '/smaily-connect/v1' . DashboardEndpoint::ROUTE );
		self::assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( 0 );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		self::assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$request = new WP_REST_Request( 'GET', '/smaily-connect/v1' . DashboardEndpoint::ROUTE );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_get_server()->dispatch( $request );
		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertIsArray( $data );
		return $data;
	}

	private function smaily_row( string $type, string $status, int $created, ?string $last_response ): void {
		QueueRowFixture::insert(
			EventQueue::TABLE_SUFFIX,
			array(
				'event_type'    => $type,
				'entity_id'     => '1',
				'payload'       => '{}',
				'created_at'    => gmdate( 'Y-m-d H:i:s', $created ),
				'status'        => $status,
				'last_response' => $last_response,
			)
		);
	}

	private function rec_row( string $type, string $status, int $created, ?string $last_error = null ): void {
		QueueRowFixture::insert(
			IngestQueue::TABLE_SUFFIX,
			array(
				'event_type' => $type,
				'entity_id'  => '1',
				'event_uuid' => wp_generate_uuid4(),
				'payload'    => '{}',
				'created_at' => gmdate( 'Y-m-d H:i:s', $created ),
				'status'     => $status,
				'last_error' => $last_error,
			)
		);
	}
}
