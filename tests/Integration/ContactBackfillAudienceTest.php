<?php
/**
 * Integration: audience-aware contact-backfill accounting (F3-55).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What F3-55 bug class this pins (Prike, 2026-07-08):
 *
 *   The contact backfill WALKS every WP user but POSTs only the contact-sync
 *   mode's audience (F3-48). total_count/processed_count track the WALK, and
 *   the UI labelled that number "contacts synced" — a consent-mode store with
 *   30k users and 16k opt-ins read "30k contacts go to Smaily".
 *
 *   Pinned here against the real table + real REST route:
 *   - ContactAudience::count_audience() (the SQL count) AGREES with
 *     should_sync_user() (the per-user predicate) in every mode — the two
 *     implementations of one audience definition must not drift;
 *   - a real backfill run reports walked (processed_count) and audience
 *     (synced_count) separately, and the /backfill/status payload carries
 *     `synced` + `audience_estimate` for the UI.
 */
final class ContactBackfillAudienceTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_count_audience_agrees_with_the_per_user_predicate_in_every_mode(): void {
		$this->make_user( 'optin-a', '1' );
		$this->make_user( 'optin-b', '1' );
		$this->make_user( 'optout', '0' );
		$this->make_user( 'no-meta', null );
		$this->make_user( 'empty-meta', '' );

		foreach ( array( ContactSyncMode::MODE_CONSENT, ContactSyncMode::MODE_LEGITIMATE_INTEREST, ContactSyncMode::MODE_CHECKOUT_OPTIN ) as $mode ) {
			update_option( ContactSyncMode::OPTION_MODE, $mode );

			// Fresh instance per mode — the predicate and the SQL count must
			// answer for the SAME mode.
			$audience = new ContactAudience();

			$expected = 0;
			foreach ( get_users( array( 'fields' => 'all' ) ) as $user ) {
				if ( $audience->should_sync_user( $user ) ) {
					++$expected;
				}
			}

			self::assertSame(
				$expected,
				$audience->count_audience(),
				sprintf( 'count_audience() and should_sync_user() disagree in mode "%s" — the two halves of the audience definition have drifted.', $mode )
			);
		}
	}

	public function test_backfill_reports_walked_and_synced_separately_and_the_status_payload_carries_both(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );

		$this->make_user( 'bf-optin-a', '1' );
		$this->make_user( 'bf-optin-b', '1' );
		$this->make_user( 'bf-optout', '0' );
		$this->make_user( 'bf-nometa-a', null );
		$this->make_user( 'bf-nometa-b', null );

		$audience = new ContactAudience();
		$expected_synced = 0;
		$all_users       = get_users( array( 'fields' => 'all' ) );
		foreach ( $all_users as $user ) {
			if ( $audience->should_sync_user( $user ) ) {
				++$expected_synced;
			}
		}
		$expected_walked = count( $all_users );

		// Fake Smaily transport: every upsert succeeds (HTTP 200).
		$fake = static function () {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		$job = new BackfillJob( new Client( 'testsub', 'tester', 'pw' ) );

		add_filter( 'pre_http_request', $fake );
		try {
			$job->start();
			$guard = 0;
			do {
				$result = $job->process_batch( 200 );
			} while ( ! $result['completed'] && ++$guard < 50 );
		} finally {
			remove_filter( 'pre_http_request', $fake );
		}
		self::assertTrue( $result['completed'], 'Backfill did not complete within the batch guard.' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, total_count, processed_count, synced_count FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				BackfillJob::BACKFILL_TYPE,
				BackfillJob::BACKFILL_TARGET
			),
			ARRAY_A
		);

		self::assertSame( 'completed', $row['status'] );
		self::assertSame( $expected_walked, (int) $row['processed_count'], 'processed_count tracks users WALKED.' );
		self::assertSame( $expected_synced, (int) $row['synced_count'], 'synced_count tracks AUDIENCE members handled.' );
		self::assertLessThan(
			(int) $row['processed_count'],
			(int) $row['synced_count'],
			'On a consent-mode store with non-opted-in users the two numbers MUST differ — equality means the walk count is being sold as the contact count again.'
		);

		// The REST status payload the UI reads.
		RestRequestHelper::login_as_admin();
		$response = RestRequestHelper::get( '/backfill/status', array( 'job_type' => 'contacts' ) );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( $expected_synced, $data['synced'], 'The UI "contacts synced" number comes from synced_count.' );
		self::assertSame( $expected_walked, $data['processed'] );
		self::assertSame( $expected_synced, $data['audience_estimate'], 'Post-run (non-running) status carries the audience estimate for the panel hint.' );
	}

	/**
	 * PRO-1715: on a store with nothing to sync the run used to be seeded as
	 * 'running' and left to an Action Scheduler tick — which on a quiet store is
	 * minutes away — so the merchant watched a progress spinner that never moved
	 * and cancelled by hand. Started through the real REST route here: it must
	 * come back already finished, with no tick left behind to reopen the row.
	 */
	public function test_starting_with_an_empty_audience_finishes_the_run_without_a_tick(): void {
		// The route builds its job from the stored Smaily credentials.
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);

		// Checkout opt-in syncs no accounts at all — the audience is empty
		// whatever users this store has.
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CHECKOUT_OPTIN );
		self::assertSame( 0, ( new ContactAudience() )->count_audience() );

		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK );

		RestRequestHelper::login_as_admin();
		$start = RestRequestHelper::post( '/backfill/start', array( 'job_type' => 'contacts' ) );
		self::assertSame( 200, $start->get_status() );
		self::assertSame(
			'completed',
			$start->get_data()['status'],
			'A backfill with nothing to sync must be finished by the time /start answers.'
		);

		self::assertSame(
			array(),
			as_get_scheduled_actions( array( 'hook' => BackfillJobInterface::TICK_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ),
			'A finished run must not leave a tick that would flip its row back to running.'
		);

		$status = RestRequestHelper::get( '/backfill/status', array( 'job_type' => 'contacts' ) );
		$data   = $status->get_data();
		self::assertSame( 'completed', $data['status'] );
		self::assertSame( 0, $data['synced'] );
		self::assertSame( 0, $data['audience_estimate'], 'The panel reads this as "nothing to import".' );
		self::assertNotNull( $data['completed_at'] );
	}

	/**
	 * PRO-1769: the walk paged only on the FIRST tick. It asked for the first
	 * $batch_size users every time and pruned `ID > cursor` in PHP, so the second
	 * tick re-read page one, filtered every row away, and the empty page marked
	 * the job 'completed' — a store with more than one page of users imported
	 * only its first page and reported success (reproduced on the dev store:
	 * 151 users, 99 of 150 opt-ins synced, status 'completed' at 66%).
	 *
	 * Driven here with a batch size smaller than the store so the walk MUST span
	 * several pages: every audience member has to be POSTed, and the counts have
	 * to add up to the whole user table.
	 */
	public function test_a_store_with_several_pages_of_users_syncs_every_audience_member(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );

		$seeded = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$user_id  = $this->make_user( 'bf-page-' . $i, '1' );
			$seeded[] = strtolower( (string) get_userdata( $user_id )->user_email );
		}

		$audience        = new ContactAudience();
		$all_users       = get_users( array( 'fields' => 'all' ) );
		$expected_walked = count( $all_users );
		$expected_synced = 0;
		foreach ( $all_users as $user ) {
			if ( $audience->should_sync_user( $user ) ) {
				++$expected_synced;
			}
		}
		self::assertGreaterThan( 2, $expected_walked, 'The store needs more users than the batch size below.' );

		// Fake Smaily transport that records which contacts were actually POSTed.
		$posted = array();
		$fake   = static function ( $pre, $args ) use ( &$posted ) {
			foreach ( (array) ( $args['body'] ?? array() ) as $subscriber ) {
				if ( is_array( $subscriber ) && isset( $subscriber['email'] ) ) {
					$posted[] = strtolower( (string) $subscriber['email'] );
				}
			}

			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		$job = new BackfillJob( new Client( 'testsub', 'tester', 'pw' ) );

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			$job->start();
			$batches = 0;
			do {
				$result = $job->process_batch( 2 );
			} while ( ! $result['completed'] && ++$batches < 100 );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertTrue( $result['completed'], 'Backfill did not complete within the batch guard.' );

		foreach ( $seeded as $email ) {
			self::assertContains(
				$email,
				$posted,
				'Every audience member must reach Smaily — a user past the first page was never POSTed.'
			);
		}

		self::assertGreaterThan( 1, $batches, 'With a batch size of 2 this store must take several ticks.' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, processed_count, synced_count FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				BackfillJob::BACKFILL_TYPE,
				BackfillJob::BACKFILL_TARGET
			),
			ARRAY_A
		);

		self::assertSame( 'completed', $row['status'] );
		self::assertSame( $expected_walked, (int) $row['processed_count'], 'A completed walk has walked the whole user table.' );
		self::assertSame( $expected_synced, (int) $row['synced_count'], 'Every audience member is counted as synced.' );
	}

	/**
	 * PRO-3821: Action Scheduler had already claimed the next tick when the
	 * merchant pressed Cancel. That tick must POST nobody to Smaily and leave
	 * the contact import cancelled, not flip it back to running.
	 */
	public function test_a_tick_already_claimed_when_the_merchant_cancels_syncs_nobody(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$this->make_user( 'bf-cancel', '1' );

		$requests = 0;
		$fake     = static function ( $pre, $args, $url ) use ( &$requests ) {
			if ( strpos( (string) $url, 'testsub.sendsmaily.net' ) === false ) {
				return $pre;
			}
			++$requests;
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		$job = new BackfillJob( new Client( 'testsub', 'tester', 'pw' ) );

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$job->start();

			RestRequestHelper::login_as_admin();
			$cancel = RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) );
			wp_set_current_user( 0 );
			self::assertTrue( $cancel->get_data()['cancelled'] );

			$result = $job->process_batch(); // The tick that was already claimed.
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertSame( 0, $requests, 'Nothing was sent to Smaily.' );
		self::assertSame( 0, $result['processed'] );
		self::assertTrue( $result['completed'], 'A cancelled import schedules no further tick.' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, processed_count FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				BackfillJob::BACKFILL_TYPE,
				BackfillJob::BACKFILL_TARGET
			),
			ARRAY_A
		);
		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $row['status'] );
		self::assertSame( 0, (int) $row['processed_count'] );
	}

	/**
	 * PRO-3868: a Smaily call that fails mid-page leaves the contact import
	 * failed — not running or done — and does not move it past the rest of
	 * that page. Pressing Start import again (the merchant's retry) sends
	 * every customer the failed run did not reach.
	 */
	public function test_a_failed_batch_stays_failed_and_the_retry_syncs_the_rest_of_its_page(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		PipelineFixture::seed_credentials();

		$first  = $this->make_user( 'bf-fail-first', '1' );
		$broken = $this->make_user( 'bf-fail-broken', '1' );
		$after  = $this->make_user( 'bf-fail-after', '1' );
		$emails = array(
			$first  => get_userdata( $first )->user_email,
			$broken => get_userdata( $broken )->user_email,
			$after  => get_userdata( $after )->user_email,
		);

		$fail_broken = true;
		$sent        = array();
		$fake        = static function ( $pre, $args, $url ) use ( &$fail_broken, &$sent, $emails, $broken ) {
			if ( strpos( (string) $url, 'sendsmaily.net' ) === false ) {
				return $pre;
			}
			// The contact upsert posts a form-encoded list of contacts.
			$email  = (string) ( $args['body'][0]['email'] ?? '' );
			$refuse = $fail_broken && $email === $emails[ $broken ];
			if ( ! $refuse ) {
				$sent[] = $email;
			}
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => $refuse ? 500 : 200,
					'message' => $refuse ? 'Internal Server Error' : 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			RestRequestHelper::login_as_admin();
			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );
			$this->run_one_tick();

			$row = $this->contact_job_row();
			self::assertSame( BackfillJobInterface::STATUS_FAILED, $row['status'], 'The failed batch must not overwrite the failure with running or completed.' );
			self::assertNotSame( '', (string) $row['error_message'] );
			self::assertLessThan( $after, (int) $row['cursor_value'], 'The import must not move past the customers the failure kept from being sent.' );
			self::assertNotContains( $emails[ $after ], $sent, 'The batch stops at the failure.' );
			self::assertSame( array(), $this->pending_ticks(), 'A failed import schedules no further batch.' );

			$status = RestRequestHelper::get( '/backfill/status', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) );
			self::assertSame( BackfillJobInterface::STATUS_FAILED, $status->get_data()['status'], 'The screen shows the failure.' );
			self::assertSame( 'Smaily API returned HTTP 500 for POST contact', $status->get_data()['error'], 'The screen shows Smaily\'s reason (PRO-3881).' );

			// The merchant's retry: Smaily answers again, Start import is pressed.
			$fail_broken = false;
			$sent        = array();
			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );
			$this->run_one_tick();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
			wp_set_current_user( 0 );
		}

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->contact_job_row()['status'] );
		self::assertContains( $emails[ $broken ], $sent, 'The customer whose send failed is synced on retry.' );
		self::assertContains( $emails[ $after ], $sent, 'The customer after the failure on that page is synced on retry.' );
	}

	/**
	 * PRO-3904: Smaily can refuse a contact in the body code of an HTTP 200
	 * answer. The contact import reads that code the way the queued sends do
	 * (PRO-3862): the refused contact is not counted as synced and the import
	 * stops failed (PRO-3868) with Smaily's message — for 225 too, because
	 * the import fails on every error a queued send would retry.
	 *
	 * @dataProvider refusing_codes
	 */
	public function test_a_contact_smaily_refuses_in_a_successful_answer_fails_the_import( int $smaily_code ): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		PipelineFixture::seed_credentials();

		$first   = $this->make_user( 'bf-refused-first', '1' );
		$refused = $this->make_user( 'bf-refused', '1' );
		$after   = $this->make_user( 'bf-refused-after', '1' );
		$email   = get_userdata( $refused )->user_email;

		$fake = static function ( $pre, $args, $url ) use ( $email, $smaily_code ) {
			if ( strpos( (string) $url, 'sendsmaily.net' ) === false ) {
				return $pre;
			}
			$refuse = (string) ( $args['body'][0]['email'] ?? '' ) === $email;
			return array(
				'headers'  => array(),
				'body'     => $refuse
					? (string) wp_json_encode(
						array(
							'code'    => $smaily_code,
							'message' => 'Refused by the test',
						)
					)
					: '{"code":101,"message":"OK"}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			RestRequestHelper::login_as_admin();
			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );
			$this->run_one_tick();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
			wp_set_current_user( 0 );
		}

		$row = $this->contact_job_row();
		self::assertSame( BackfillJobInterface::STATUS_FAILED, $row['status'], 'A refusing body code must not let the import finish.' );
		self::assertStringContainsString( sprintf( 'code %d: Refused by the test', $smaily_code ), (string) $row['error_message'], 'The import keeps Smaily\'s answer.' );
		self::assertSame( 0, (int) $row['synced_count'], 'The refused contact is not counted as synced.' );
		self::assertSame( '', get_user_meta( $refused, BackfillJob::META_KEY, true ), 'The refused contact is not marked synced, so the next run sends it again.' );
		self::assertNotSame( '', get_user_meta( $first, BackfillJob::META_KEY, true ), 'The contact Smaily accepted is marked synced.' );
		self::assertLessThan( $after, (int) $row['cursor_value'], 'The import does not move past the refused contact.' );
		self::assertSame( array(), $this->pending_ticks(), 'A failed import schedules no further batch.' );
	}

	/**
	 * PRO-3902: an error that is not a Smaily answer — here the HTTP layer
	 * throws — stops the contact import as failed instead of leaving it
	 * running with no next batch. The reason names the error class and where
	 * it was thrown, never the message (PRO-3890). Start import runs it again.
	 */
	public function test_an_unexpected_error_in_a_batch_stops_the_import_and_start_import_runs_it_again(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		PipelineFixture::seed_credentials();

		$first  = $this->make_user( 'bf-error-first', '1' );
		$second = $this->make_user( 'bf-error-second', '1' );

		$explode = true;
		$sent    = array();
		$fake    = static function ( $pre, $args, $url ) use ( &$explode, &$sent ) {
			if ( strpos( (string) $url, 'sendsmaily.net' ) === false ) {
				return $pre;
			}
			if ( $explode ) {
				throw new \RuntimeException( 'connection reset while sending a contact' );
			}
			$sent[] = (string) ( $args['body'][0]['email'] ?? '' );
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			RestRequestHelper::login_as_admin();
			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );
			$this->run_one_tick();

			$row = $this->contact_job_row();
			self::assertSame( BackfillJobInterface::STATUS_FAILED, $row['status'], 'The import is stopped, not left running.' );
			self::assertStringStartsWith( 'RuntimeException at ', (string) $row['error_message'], 'The reason names the error class and where it was thrown.' );
			self::assertStringNotContainsString( 'connection reset', (string) $row['error_message'], 'No message text, which could carry personal data.' );
			self::assertSame( array(), $this->pending_ticks(), 'No further batch is scheduled.' );

			$status = $this->contact_status();
			self::assertSame( BackfillJobInterface::STATUS_FAILED, $status['status'], 'The Settings screen reads it as stopped.' );
			self::assertSame( $row['error_message'], $status['error'], 'The Settings screen shows why (PRO-3881).' );

			// The merchant presses Start import; the error is gone.
			$explode = false;
			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );
			self::assertNotSame( array(), $this->pending_ticks(), 'Start import queues the first batch again.' );
			$this->run_one_tick();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
			wp_set_current_user( 0 );
		}

		self::assertSame( BackfillJobInterface::STATUS_COMPLETED, $this->contact_job_row()['status'] );
		self::assertContains( get_userdata( $first )->user_email, $sent );
		self::assertContains( get_userdata( $second )->user_email, $sent );
	}

	/**
	 * PRO-3902: a contact import left `running` with no batch queued or
	 * running — deactivation cancelled it, or a batch died on a fatal error —
	 * reads as stopped once the grace period after its start has passed, like
	 * a Campaign Intelligence import (PRO-3886). Nothing restarts or rewrites
	 * it; Start import runs it again.
	 */
	public function test_a_running_contact_import_with_nothing_driving_it_shows_as_stopped(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		PipelineFixture::seed_credentials();
		$this->make_user( 'bf-stalled', '1' );

		RestRequestHelper::login_as_admin();
		try {
			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );

			// Its first batch queued: running, however long ago it started.
			$this->backdate_contact_start( HOUR_IN_SECONDS );
			self::assertNotSame( array(), $this->pending_ticks() );
			self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->contact_status()['status'], 'A queued batch drives it.' );

			// Within the grace period after start, even with no batch queued.
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK );
			$this->backdate_contact_start( 60 );
			self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->contact_status()['status'], 'A just-started import is not stalled yet.' );

			// Nothing drives it any more, and it started 20 minutes ago.
			$this->backdate_contact_start( 20 * MINUTE_IN_SECONDS );
			$status = $this->contact_status();
			self::assertSame( BackfillJobInterface::STATUS_FAILED, $status['status'], 'The Settings screen shows it stopped.' );
			self::assertSame( 'The import stopped running in the background.', $status['error'], 'The Settings screen says why (PRO-3881).' );
			self::assertSame( BackfillJobInterface::STATUS_RUNNING, $this->contact_job_row()['status'], 'Nothing rewrites the row.' );
			self::assertSame( array(), $this->pending_ticks(), 'It is not restarted automatically.' );

			self::assertSame( 200, RestRequestHelper::post( '/backfill/start', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) )->get_status() );
			$status = $this->contact_status();
			self::assertSame( BackfillJobInterface::STATUS_RUNNING, $status['status'], 'Start import runs it again.' );
			self::assertNull( $status['error'], 'A running import has no failure reason.' );
		} finally {
			as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function refusing_codes(): array {
		return array(
			'203 invalid data (fails a queued send at once)' => array( 203 ),
			'225 database insert failed (retried by a queued send)' => array( 225 ),
		);
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Run one contact-import batch the way Action Scheduler does, after
	 * dropping the tick /backfill/start queued.
	 */
	private function run_one_tick(): void {
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK );
		do_action( BackfillJobInterface::TICK_HOOK, BackfillJob::BACKFILL_TYPE );
	}

	/**
	 * @return int[]
	 */
	private function pending_ticks(): array {
		return as_get_scheduled_actions(
			array(
				'hook'   => BackfillJobInterface::TICK_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
	}

	/**
	 * @return array<string, mixed> What /backfill/status answers for the contact import.
	 */
	private function contact_status(): array {
		$response = RestRequestHelper::get( '/backfill/status', array( 'job_type' => BackfillJob::BACKFILL_TYPE ) );
		self::assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function backdate_contact_start( int $seconds ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'smly_plus_backfill_job',
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array(
				'job_type' => BackfillJob::BACKFILL_TYPE,
				'target'   => BackfillJob::BACKFILL_TARGET,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function contact_job_row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, cursor_value, synced_count, error_message FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				BackfillJob::BACKFILL_TYPE,
				BackfillJob::BACKFILL_TARGET
			),
			ARRAY_A
		);
		self::assertIsArray( $row );
		return $row;
	}

	/**
	 * @param string|null $newsletter user_newsletter meta value; null = no meta row.
	 */
	private function make_user( string $slug, ?string $newsletter ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_aud_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		if ( $newsletter !== null ) {
			update_user_meta( $user_id, ContactAudience::OPTIN_META, $newsletter );
		}

		return $user_id;
	}
}
