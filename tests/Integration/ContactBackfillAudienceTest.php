<?php
/**
 * Integration: audience-aware contact-backfill accounting (F3-55).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\BackfillEndpoint;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
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

		as_unschedule_all_actions( BackfillEndpoint::TICK_HOOK );

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
			as_get_scheduled_actions( array( 'hook' => BackfillEndpoint::TICK_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ),
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

	// --- helpers -------------------------------------------------------------

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
