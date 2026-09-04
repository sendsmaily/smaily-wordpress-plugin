<?php
/**
 * Integration: the Smaily queue's retry policy, driven through the real flow.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RetryPolicy;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * PRO-1685. Against the real table + the real Flusher (via its Action
 * Scheduler hook), with only the HTTP transport faked, this pins:
 *
 *   - a refusal that can never succeed (401) stops being retried, is
 *     recorded `failed`, and the queue stops growing;
 *   - the merchant learns about it — the failed row is counted by the
 *     health check that raises the "N sync events failed" notice;
 *   - a failure that could succeed later (500) is retried with the
 *     policy's spacing, not every 60s tick;
 *   - a 429 waits exactly as long as Smaily asked;
 *   - work queued behind a doomed row still goes out;
 *   - a row that reaches the attempt ceiling says so in the Event Log;
 *   - the Event Log's recovery path un-does any of it (the bound on a
 *     mis-classification): status, attempts AND the retry park reset.
 */
final class SmailyRetryPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();

		update_option( 'smly_plus_setup_completed', true );
		$this->seed_credentials();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_refusal_that_can_never_succeed_stops_being_retried(): void {
		$id = $this->enqueue_contact_sync( 'doomed@example.test' );

		$calls = $this->flush_against( 401 );
		self::assertSame( 1, $calls, 'The first attempt is made.' );

		$row = $this->row( $id );
		self::assertSame( EventQueue::STATUS_FAILED, $row['status'], 'A 401 can never succeed on retry — the row must stop being work.' );
		self::assertSame( 0, (int) $row['attempts'], 'A permanent refusal must not burn retry attempts.' );
		self::assertStringContainsString( 'permanent_http_401', (string) $row['last_error'] );

		// …and the queue stops growing: a later tick doesn't touch it.
		self::assertSame( 0, $this->flush_against( 401 ), 'A row given up on is never re-POSTed.' );
	}

	public function test_a_row_that_stopped_being_retried_reaches_the_merchant_notice(): void {
		$this->enqueue_contact_sync( 'doomed@example.test' );
		$this->flush_against( 401 );

		// The notice fires above a threshold (50 by default); filter it to 0 so
		// one real failed row exercises the same counting path a pilot's burst
		// would. The rec-engine + Smaily probes are inert here (not connected /
		// no client), so `failed_events` is the only signal under test.
		$zero = static fn (): int => 0;
		add_filter( 'smaily_connect_failed_notice_threshold', $zero );
		try {
			( new NotificationManager(
				new RecEngineSettings(),
				static fn () => Bootstrap::instance()->rec_client(),
				static fn () => null
			) )->run_health_check();
		} finally {
			remove_filter( 'smaily_connect_failed_notice_threshold', $zero );
		}

		$notices = (array) get_option( NotificationManager::OPTION_NOTICES, array() );
		self::assertArrayHasKey( 'failed_events', $notices, 'A row that stopped being retried must reach the merchant.' );
		self::assertSame( 1, (int) $notices['failed_events']['count'] );
	}

	public function test_a_failure_that_could_succeed_later_is_retried_with_policy_spacing(): void {
		$id = $this->enqueue_contact_sync( 'later@example.test' );

		$this->flush_against( 500 );

		$row = $this->row( $id );
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'], 'A 5xx stays work.' );
		self::assertSame( 1, (int) $row['attempts'] );
		$this->assertParkedFor( $row, 60 );

		// The spacing is real: an immediate second tick must not re-attempt it.
		self::assertSame( 0, $this->flush_against( 500 ), 'A parked row is not due yet — no fixed-minute hammering.' );

		// Once due, the next failure spaces further out (1m → 5m).
		$this->make_due( $id );
		self::assertSame( 1, $this->flush_against( 500 ) );
		$this->assertParkedFor( $this->row( $id ), 300 );
	}

	public function test_the_store_waits_as_long_as_smaily_asks(): void {
		$id = $this->enqueue_contact_sync( 'slowdown@example.test' );

		$this->flush_against( 429, array( 'retry-after' => '900' ) );

		$row = $this->row( $id );
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'], 'Being asked to slow down is not a refusal.' );
		$this->assertParkedFor( $row, 900 );
	}

	public function test_work_queued_behind_a_doomed_row_still_sends(): void {
		// Oldest-first: the doomed row is at the head of the queue and the
		// batch here holds exactly one row, so before PRO-1685 the good row
		// could never reach the transport.
		$this->enqueue_contact_sync( 'doomed@example.test' );
		$good_id = $this->enqueue_contact_sync( 'fine@example.test' );

		$refuse_doomed = $this->fake_transport(
			$calls,
			static fn ( array $body ): int => str_contains( (string) wp_json_encode( $body ), 'doomed@' ) ? 401 : 200
		);

		add_filter( 'pre_http_request', $refuse_doomed, 10, 3 );
		try {
			Bootstrap::instance()->flusher()->flush( 1 );
			Bootstrap::instance()->flusher()->flush( 1 );
		} finally {
			remove_filter( 'pre_http_request', $refuse_doomed, 10 );
		}

		self::assertSame( EventQueue::STATUS_SENT, $this->row( $good_id )['status'], 'The row behind the doomed one must still go out.' );
	}

	public function test_a_row_at_the_retry_ceiling_says_why_in_the_event_log(): void {
		$id = $this->enqueue_contact_sync( 'outage@example.test' );
		$this->set_attempts( $id, RetryPolicy::MAX_ATTEMPTS - 1 );

		$this->flush_against( 503 );

		$row = $this->row( $id );
		self::assertSame( EventQueue::STATUS_FAILED, $row['status'] );

		// Read it the way the merchant does — through the Event Log route.
		$response = RestRequestHelper::get( '/events', array( 'status' => 'failed' ) );
		self::assertSame( 200, $response->get_status() );
		$logged = null;
		foreach ( $response->get_data()['events'] as $event ) {
			if ( $event['source'] === 'smaily' && (int) $event['id'] === $id ) {
				$logged = $event;
			}
		}
		self::assertIsArray( $logged, 'The given-up row must be listed in the Event Log.' );
		self::assertStringContainsString( 'retry_limit_exceeded after 5 attempts', (string) $logged['last_error'] );
		self::assertStringContainsString( 'HTTP 503', (string) $logged['last_error'], 'The underlying reason survives.' );
	}

	public function test_the_event_log_recovery_path_undoes_a_given_up_row(): void {
		// The bound on a mis-classification: whatever this policy gave up on,
		// "Retry" makes due work again — status, attempts and the retry park.
		$id = $this->enqueue_contact_sync( 'recovered@example.test' );
		$this->flush_against( 401 );
		self::assertSame( EventQueue::STATUS_FAILED, $this->row( $id )['status'] );

		$response = RestRequestHelper::post( '/events/retry', array( 'source' => 'smaily', 'id' => $id ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $response->get_data()['reset'] );

		$row = $this->row( $id );
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'] );
		self::assertSame( 0, (int) $row['attempts'] );
		self::assertNull( $row['next_retry_at'], 'A revived row is due immediately, not still parked.' );

		self::assertSame( 1, $this->flush_against( 200 ), 'The revived row is re-attempted at once.' );
		self::assertSame( EventQueue::STATUS_SENT, $this->row( $id )['status'] );
	}

	// --- helpers -------------------------------------------------------------

	private function enqueue_contact_sync( string $email ): int {
		$id = ( new EventQueue() )->enqueue(
			HookHandler::EVENT_CONTACT_SYNC,
			$email,
			array(
				'email'  => $email,
				'fields' => array(),
			)
		);
		self::assertIsInt( $id );

		return $id;
	}

	/**
	 * Run the real flush hook with the Smaily transport faked to answer
	 * $status. Returns how many requests actually reached the transport.
	 *
	 * @param array<string, string> $headers
	 */
	private function flush_against( int $status, array $headers = array() ): int {
		$calls = 0;
		$fake  = $this->fake_transport( $calls, static fn ( array $body ): int => $status, $headers );

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $calls;
	}

	/**
	 * @param int|null              $calls   By-ref call counter.
	 * @param callable(array): int  $status  Picks the HTTP status from the POST body.
	 * @param array<string, string> $headers Response headers (e.g. retry-after).
	 */
	private function fake_transport( &$calls, callable $status, array $headers = array() ): callable {
		$calls = 0;

		return static function ( $pre, $args ) use ( &$calls, $status, $headers ) {
			++$calls;
			$code = $status( is_array( $args['body'] ?? null ) ? $args['body'] : array() );

			return array(
				'headers'  => $headers,
				'body'     => wp_json_encode( array( 'code' => $code === 200 ? 101 : 0 ) ),
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function assertParkedFor( array $row, int $seconds ): void {
		self::assertNotNull( $row['next_retry_at'], 'A retried row must be parked, not left due immediately.' );
		$parked = strtotime( (string) $row['next_retry_at'] . ' UTC' );
		$delta  = $parked - time();
		self::assertGreaterThan( $seconds - 30, $delta, sprintf( 'Expected a ~%ds wait, got %ds.', $seconds, $delta ) );
		self::assertLessThan( $seconds + 30, $delta, sprintf( 'Expected a ~%ds wait, got %ds.', $seconds, $delta ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE id = %d", $id ),
			ARRAY_A
		);
		self::assertIsArray( $row );

		return $row;
	}

	private function set_attempts( int $id, int $attempts ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'smly_plus_event_queue', array( 'attempts' => $attempts ), array( 'id' => $id ) );
	}

	/** Bring a parked row forward so the next tick sees it as due. */
	private function make_due( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'smly_plus_event_queue', array( 'next_retry_at' => null ), array( 'id' => $id ) );
	}

	private function seed_credentials(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}
}
