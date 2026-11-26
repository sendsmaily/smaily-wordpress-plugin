<?php
/**
 * Integration: the engine's privacy requests (§9 delete, §10 opt-out) are
 * queued and sent from the background within the engine's limit of 10 per
 * 60 seconds per store (CON-431).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Deactivation;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\PrivacyRequests;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\EventsEndpointFactory;
use WP_REST_Request;

/**
 * Every test drives the requests' own action (run()) on a fake clock; the
 * mock engine counts the privacy requests it receives and plays the 429 and
 * the 5xx (RecEngineMockServer::set_privacy_answers()).
 */
final class RecEnginePrivacyRequestsTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** The fake clock's start. */
	private int $t0 = 0;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->connect();
		$this->t0 = time();
	}

	protected function tearDown(): void {
		EnvScrub::reset();
		parent::tearDown();
	}

	public function test_more_than_ten_requests_in_a_minute_wait_their_turn(): void {
		$requests = $this->requests();
		for ( $i = 1; $i <= 12; $i++ ) {
			self::assertTrue( $requests->queue_opt_out( "pace-{$i}@example.test", array( 'opt_out' => true, 'reason' => 'profiling_consent' ) ) );
		}
		self::assertSame( 0, self::$engine->privacy_request_count(), 'Queuing calls nothing.' );

		$requests->run();
		self::assertSame( 10, self::$engine->privacy_request_count(), 'Ten in the first minute.' );

		$requests->clock = $this->t0 + 59;
		$requests->run();
		self::assertSame( 10, self::$engine->privacy_request_count(), 'None more inside the same 60 seconds.' );

		$requests->clock = $this->t0 + 60;
		$requests->run();
		self::assertSame( 12, self::$engine->privacy_request_count(), 'The two that waited go once the window leaves room.' );

		foreach ( $this->rows() as $row ) {
			self::assertSame( IngestQueue::STATUS_SENT, $row['status'] );
			self::assertSame( '{}', $row['payload'], 'A request that ended keeps no address.' );
		}
	}

	public function test_a_429_holds_the_request_for_the_engines_wait_and_no_less(): void {
		self::$engine->set_privacy_answers( array( 429 ), 120 );
		$requests = $this->requests();
		$requests->queue_delete( 'held@example.test' );

		$started = microtime( true );
		$requests->run();
		self::assertLessThan( 5.0, microtime( true ) - $started, 'The run never sleeps the wait.' );
		self::assertSame( 1, self::$engine->privacy_request_count() );
		$row = $this->rows()[0];
		self::assertSame( IngestQueue::STATUS_PENDING, $row['status'], 'A 429 keeps the request.' );
		self::assertSame( 0, (int) $row['attempts'], 'A 429 spends no attempt.' );

		$requests->clock = $this->t0 + 119;
		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count(), 'Not before the engine\'s wait has passed.' );

		$requests->clock = $this->t0 + 120;
		$requests->run();
		self::assertSame( 2, self::$engine->privacy_request_count() );
		self::assertSame( IngestQueue::STATUS_SENT, $this->rows()[0]['status'] );
	}

	public function test_a_5xx_keeps_the_request_and_sends_it_again_after_a_growing_back_off(): void {
		self::$engine->set_privacy_answers( array( 503, 502 ) );
		$requests = $this->requests();
		$requests->queue_opt_out( 'flaky@example.test', array( 'opt_out' => true, 'reason' => 'profiling_consent' ) );

		$requests->run();
		$row = $this->rows()[0];
		self::assertSame( IngestQueue::STATUS_PENDING, $row['status'] );
		self::assertSame( 1, (int) $row['attempts'] );
		self::assertEqualsWithDelta( time() + PrivacyRequests::RETRY_SECONDS[0], strtotime( $row['next_retry_at'] . ' UTC' ), 10 );

		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count(), 'Not sent again before its back-off.' );

		$this->make_due();
		$requests->run();
		$row = $this->rows()[0];
		self::assertSame( 2, (int) $row['attempts'] );
		self::assertEqualsWithDelta( time() + PrivacyRequests::RETRY_SECONDS[1], strtotime( $row['next_retry_at'] . ' UTC' ), 10 );

		$this->make_due();
		$requests->run();
		self::assertSame( 3, self::$engine->privacy_request_count() );
		self::assertSame( IngestQueue::STATUS_SENT, $this->rows()[0]['status'] );
	}

	public function test_no_answer_keeps_the_request_and_sends_it_again(): void {
		$requests = $this->requests();
		$requests->queue_delete( 'offline@example.test' );
		$no_answer = static function ( $pre, array $args, string $url ) {
			return strpos( $url, '/api/v1/customer/' ) !== false ? new \WP_Error( 'http_request_failed', 'cURL error 28' ) : $pre;
		};
		add_filter( 'pre_http_request', $no_answer, 10, 3 );
		try {
			$requests->run();
		} finally {
			remove_filter( 'pre_http_request', $no_answer, 10 );
		}
		$row = $this->rows()[0];
		self::assertSame( IngestQueue::STATUS_PENDING, $row['status'] );
		self::assertSame( 'http_0 network_error', $row['last_error'] );

		$this->make_due();
		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count() );
		self::assertSame( IngestQueue::STATUS_SENT, $this->rows()[0]['status'] );
	}

	public function test_a_404_ends_the_request_as_done(): void {
		$requests = $this->requests();
		$requests->queue_opt_out( 'notfound-privacy@example.test', array( 'opt_out' => true, 'reason' => 'profiling_consent' ) );

		$requests->run();

		$row = $this->rows()[0];
		self::assertSame( IngestQueue::STATUS_SENT, $row['status'], 'Nothing to opt out — done, as before.' );
		self::assertStringContainsString( '"http":404', (string) $row['last_response'] );
		self::assertSame( '{}', $row['payload'] );
		$requests->clock = $this->t0 + 3600;
		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count(), 'Never sent again.' );
	}

	public function test_the_export_counts_against_the_limit(): void {
		$requests = $this->requests();
		$this->handler( $requests )->export( 'exported@example.test' );
		for ( $i = 1; $i <= 10; $i++ ) {
			$requests->queue_opt_out( "after-export-{$i}@example.test", array( 'opt_out' => true, 'reason' => 'profiling_consent' ) );
		}

		$requests->run();
		self::assertSame( 9, self::$engine->privacy_request_count(), 'The export took one of the ten.' );

		$requests->clock = $this->t0 + 60;
		$requests->run();
		self::assertSame( 10, self::$engine->privacy_request_count() );
	}

	public function test_a_later_choice_replaces_the_waiting_one(): void {
		$requests = $this->requests();
		$requests->queue_opt_out( 'changed@example.test', array( 'opt_out' => true, 'reason' => 'profiling_consent' ) );
		$requests->queue_opt_out( 'changed@example.test', array( 'opt_out' => false, 'reason' => 'profiling_consent' ) );

		$rows = $this->rows();
		self::assertCount( 1, $rows );
		self::assertStringContainsString( '"opt_out":false', (string) $rows[0]['payload'] );
	}

	public function test_a_waiting_request_is_logged_by_kind_and_anonymous_id_never_the_address(): void {
		self::$engine->set_privacy_answers( array( 503 ) );
		$requests = $this->requests();

		$log      = (string) tempnam( sys_get_temp_dir(), 'smly-privacy-log' );
		$previous = (string) ini_set( 'error_log', $log );
		try {
			$requests->queue_delete( 'logged@example.test' );
			$requests->run();
			$written = (string) file_get_contents( $log );
		} finally {
			ini_set( 'error_log', $previous );
			unlink( $log );
		}

		$id = PrivacyRequests::anonymous_id( 'logged@example.test' );
		self::assertSame( $id, $this->rows()[0]['entity_id'] );
		self::assertStringContainsString( '[smaily-connect privacy] privacy.delete ' . $id . ' queued', $written );
		self::assertStringContainsString( '[smaily-connect privacy] privacy.delete ' . $id . ' waits 60s: http=503 error=internal_error', $written );
		self::assertStringNotContainsString( 'logged@example.test', $written );
	}

	public function test_the_eraser_queues_the_delete_and_leaves_no_address_once_it_is_sent(): void {
		$email    = 'erased-privacy@example.test';
		$requests = $this->requests();
		$requests->queue_opt_out( $email, array( 'opt_out' => true, 'reason' => 'profiling_consent' ) );

		$result = $this->handler( $requests )->erase( $email );

		self::assertTrue( $result['items_removed'] );
		self::assertContains( 'Campaign Intelligence gets the deletion request in the background. It is sent again until Campaign Intelligence accepts it.', $result['messages'] );
		self::assertSame( 0, self::$engine->privacy_request_count(), 'The eraser calls nothing.' );
		$rows = $this->rows();
		self::assertCount( 1, $rows, 'The delete replaced the waiting opt-out.' );
		self::assertSame( PrivacyRequests::EVENT_DELETE, $rows[0]['event_type'] );

		$detail = EventsEndpointFactory::create()->detail( $this->detail_request( (int) $rows[0]['id'] ) )->get_data();
		self::assertSame( PrivacyRequests::EVENT_DELETE, $detail['event']['event_type'] );
		self::assertSame( PrivacyRequests::anonymous_id( $email ), $detail['event']['entity_id'] );
		self::assertStringNotContainsString( $email, (string) wp_json_encode( $detail ), 'The Event Log never shows the address.' );

		$requests->run();

		self::assertSame( 1, self::$engine->privacy_request_count() );
		$deletes = array_filter( self::$engine->request_paths(), static fn ( string $p ): bool => strpos( $p, 'DELETE /api/v1/customer/' ) === 0 );
		self::assertCount( 1, $deletes );
		self::assertSame( 0, $this->rows_naming( $email ), 'Nothing in the queue names the address once the delete is sent.' );
	}

	public function test_a_401_keeps_the_delete_until_a_new_connection_and_then_sends_it_once(): void {
		// Erkki, 2026-11-26: a refused key never closes a deletion.
		self::$engine->set_privacy_answers( array( 401 ) );
		$requests = $this->requests();
		$requests->queue_delete( 'revoked@example.test' );

		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count() );
		$this->assert_waiting( 'revoked@example.test' );

		$requests->clock = $this->t0 + 3600;
		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count(), 'Nothing is sent again under the refused key.' );
		$this->assert_waiting( 'revoked@example.test' );

		$this->connect( 'sk_test_second_connection_key' );
		$requests->run();
		self::assertSame( 2, self::$engine->privacy_request_count(), 'A new connection sends it.' );
		self::assertSame( IngestQueue::STATUS_SENT, $this->rows()[0]['status'] );
		self::assertSame( '{}', $this->rows()[0]['payload'] );

		$requests->clock = $this->t0 + 7200;
		$requests->run();
		self::assertSame( 2, self::$engine->privacy_request_count(), 'Sent once.' );
	}

	public function test_a_deactivated_account_keeps_the_delete_until_a_new_connection(): void {
		self::$engine->set_tenant_inactive( true );
		$requests = $this->requests();
		$requests->queue_delete( 'suspended@example.test' );

		$requests->run();
		self::assertCount( 1, $this->delete_paths() );
		$this->assert_waiting( 'suspended@example.test' );
		self::assertFalse( ( new RecEngineSettings() )->sending_allowed(), 'The 403 refused the connection.' );

		$requests->clock = $this->t0 + 3600;
		$requests->run();
		self::assertCount( 1, $this->delete_paths(), 'Nothing is sent while sending is not allowed.' );

		self::$engine->set_tenant_inactive( false );
		$this->connect( 'sk_test_after_reactivation_key' );
		$requests->run();
		self::assertCount( 2, $this->delete_paths(), 'A new connection sends it once.' );
		self::assertSame( IngestQueue::STATUS_SENT, $this->rows()[0]['status'] );
	}

	public function test_disconnect_keeps_a_waiting_deletion_for_the_next_connection(): void {
		$requests = $this->requests();
		$requests->queue_delete( 'kept@example.test' );

		( new RecEngineSettings() )->disconnect();
		$requests->run();

		self::assertSame( 0, self::$engine->privacy_request_count(), 'Nothing is sent while disconnected.' );
		$this->assert_waiting( 'kept@example.test' );

		$this->connect( 'sk_test_reconnected_key' );
		$requests->run();
		self::assertSame( 1, self::$engine->privacy_request_count() );
		self::assertSame( IngestQueue::STATUS_SENT, $this->rows()[0]['status'] );
	}

	public function test_deactivation_cancels_the_requests_action(): void {
		self::assertContains( PrivacyRequests::AS_GROUP, Deactivation::AS_GROUPS );
	}

	// ---- Helpers ---------------------------------------------------------

	/** Connect to the mock engine through RecEngineSettings::store(). */
	private function connect( string $api_key = EnvSeed::FIXTURE_API_KEY ): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'api_key'         => $api_key,
				'engine_base_url' => $base,
				'endpoints'       => array(
					'customer_export'  => $base . '/api/v1/customer/{email}/export',
					'customer_delete'  => $base . '/api/v1/customer/{email}',
					'customer_opt_out' => $base . '/api/v1/customer/{email}/opt-out',
				),
			)
		);
	}

	/** The one privacy row still waits, with its address and no attempt spent. */
	private function assert_waiting( string $email ): void {
		$rows = $this->rows();
		self::assertCount( 1, $rows );
		self::assertSame( IngestQueue::STATUS_PENDING, $rows[0]['status'] );
		self::assertSame( 0, (int) $rows[0]['attempts'] );
		self::assertStringContainsString( $email, (string) $rows[0]['payload'] );
	}

	/**
	 * The §9 deletes that reached the engine.
	 *
	 * @return array<int, string>
	 */
	private function delete_paths(): array {
		return array_values( array_filter( self::$engine->request_paths(), static fn ( string $p ): bool => strpos( $p, 'DELETE /api/v1/customer/' ) === 0 ) );
	}

	/**
	 * The requests on the mock engine, with a clock the test moves.
	 */
	private function requests(): PrivacyRequests {
		$settings = new RecEngineSettings();
		$requests = new class(
			new IngestQueue(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1, $settings, PrivacyRequests::TIMEOUT_SECONDS );
			}
		) extends PrivacyRequests {
			/** @var int */
			public $clock = 0;

			protected function now(): int {
				return $this->clock;
			}
		};
		$requests->clock = $this->t0;

		return $requests;
	}

	private function handler( PrivacyRequests $requests ): GdprHandler {
		$settings = new RecEngineSettings();
		return new GdprHandler(
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 1 );
			},
			new CartSessionStore(),
			new EventQueue(),
			new IngestQueue(),
			$requests
		);
	}

	private function detail_request( int $id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/smaily-connect/v1/events/detail' );
		$request->set_param( 'source', 'rec_engine' );
		$request->set_param( 'id', $id );
		return $request;
	}

	/**
	 * The privacy rows, oldest first.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type IN ( %s, %s ) ORDER BY id ASC", PrivacyRequests::EVENT_DELETE, PrivacyRequests::EVENT_OPT_OUT ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Rows with the address in any column. */
	private function rows_naming( string $email ): int {
		global $wpdb;
		$table = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		$like  = '%' . $wpdb->esc_like( $email ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE entity_id LIKE %s OR payload LIKE %s OR sent_payload LIKE %s OR last_response LIKE %s OR last_error LIKE %s",
				$like,
				$like,
				$like,
				$like,
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Bring every parked privacy row due now. */
	private function make_due(): void {
		global $wpdb;
		$table = $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET next_retry_at = ( UTC_TIMESTAMP() - INTERVAL 1 SECOND ) WHERE next_retry_at IS NOT NULL" );
	}
}
