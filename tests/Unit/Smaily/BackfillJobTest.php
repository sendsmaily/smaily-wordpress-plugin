<?php
/**
 * BackfillJob tests — exercise start() + process_batch() flows against
 * stubbed $wpdb and mocked WP user-table helpers. The integration suite
 * (later phase) covers the real DB writes and large-batch performance.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;

final class BackfillJobTest extends TestCase {

	/** @var array<string, mixed> First contact payload captured from upsert_subscribers. */
	private array $captured_payload = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->captured_payload = array();
		// ContactLanguageResolver (via build_subscriber_payload) caches the
		// detector through DetectorFactory's process-global static.
		DetectorFactory::reset();

		Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );
		// No multilingual plugin in unit context → SiteLocale default.
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );

		// Users opted-in by default so the default (consent) audience syncs them;
		// no _smaily_synced_at, so every user looks "never synced" (F3-48).
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) {
				return $key === 'user_newsletter' ? '1' : '';
			}
		);

		// Pass-through for update_user_meta — tests don't need to inspect it
		// unless an assertion explicitly cares.
		Functions\when( 'update_user_meta' )->justReturn( true );

		// Sub-PR 2.H.15 — SubscriberPayloadBuilder reads sync-toggle
		// opt-ins from this option. null falls back to the documented
		// "every cross-channel field enabled" default. The contact-sync
		// switch is the one option that defaults to ON, so it has to answer
		// with its default the way a real get_option() would.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				return $key === ContactSyncMode::OPTION_SYNC_ENABLED ? $default : null;
			}
		);
		// start() clears the run's refused-contact list (PRO-3988).
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_site_url' )->justReturn( 'http://example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_start_seeds_a_running_row_and_returns_its_id(): void {
		$wpdb            = $this->fake_wpdb_for_start( 77 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 5000 ) );

		$job = new BackfillJob( $this->createMock( Client::class ) );

		self::assertSame( 77, $job->start() );

		// Audience COUNT + one INSERT ... ON DUPLICATE KEY UPDATE + one SELECT id.
		self::assertCount( 3, $wpdb->prepare_calls );
		self::assertStringContainsString( 'INSERT INTO wp_smly_plus_backfill_job', $wpdb->prepare_calls[1]['sql'] );
		self::assertSame( 'running', $wpdb->prepare_calls[1]['args'][2] );
		self::assertSame( 5000, $wpdb->prepare_calls[1]['args'][3] );
		self::assertStringContainsString( 'SELECT id FROM wp_smly_plus_backfill_job', $wpdb->prepare_calls[2]['sql'] );
		self::assertSame( array(), $wpdb->updates, 'A run with an audience is left for the tick to walk.' );
	}

	/**
	 * PRO-1715: on a store where nobody is in the sync audience the walk cannot
	 * produce a contact, so start() closes the run itself instead of leaving a
	 * 'running' row whose only exit is an Action Scheduler tick — the merchant
	 * used to watch a progress spinner that never moved.
	 */
	public function test_start_completes_immediately_when_the_audience_is_empty(): void {
		$wpdb            = $this->fake_wpdb_for_start( 77, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 5000 ) );

		self::assertSame( 77, ( new BackfillJob( $this->createMock( Client::class ) ) )->start() );

		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'completed', $wpdb->updates[0]['data']['status'] );
		self::assertSame( 5000, $wpdb->updates[0]['data']['processed_count'], 'Every user is accounted for — each one would have been audience-skipped.' );
		self::assertSame( 0, $wpdb->updates[0]['data']['synced_count'] );
		self::assertNotEmpty( $wpdb->updates[0]['data']['completed_at'] );
	}

	public function test_process_batch_syncs_users_marks_meta_and_updates_progress(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '2',
			),
			array( 1, 2 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn(
			array(
				$this->fake_user( 1, 'a@x.test' ),
				$this->fake_user( 2, 'b@x.test' ),
			)
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->exactly( 2 ) )
			->method( 'upsert_subscribers' );

		$update_meta_calls = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ) use ( &$update_meta_calls ): bool {
				$update_meta_calls[] = compact( 'user_id', 'key', 'value' );
				return true;
			}
		);

		$result = ( new BackfillJob( $client ) )->process_batch( 10 );

		self::assertSame( 2, $result['processed'] );
		self::assertTrue( $result['completed'] );

		// _smaily_synced_at meta should have been set on both user ids.
		$user_ids = array_column( $update_meta_calls, 'user_id' );
		self::assertContains( 1, $user_ids );
		self::assertContains( 2, $user_ids );
		self::assertSame( BackfillJob::META_KEY, $update_meta_calls[0]['key'] );

		// processed_count + cursor updated.
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'completed', $wpdb->updates[0]['data']['status'] );
		self::assertSame( '2', $wpdb->updates[0]['data']['cursor_value'] );
	}

	public function test_process_batch_skips_fresh_users(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '1',
			),
			array( 1 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn( array( $this->fake_user( 1, 'fresh@x.test' ) ) );

		// Opted-in (passes the audience) but synced one minute ago — well inside
		// the 7-day freshness window — so upsert_subscribers must not be called.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) {
				if ( $key === 'user_newsletter' ) {
					return '1';
				}
				if ( $key === BackfillJob::META_KEY ) {
					return (string) ( time() - 60 );
				}
				return '';
			}
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'upsert_subscribers' );

		$result = ( new BackfillJob( $client ) )->process_batch();

		self::assertSame( 0, $result['processed'], 'No API calls because all users were already fresh.' );
	}

	public function test_process_batch_returns_completed_when_no_state_row_exists(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch( null );
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new BackfillJob( $this->createMock( Client::class ) ) )->process_batch();

		self::assertSame(
			array(
				'processed' => 0,
				'remaining' => 0,
				'completed' => true,
			),
			$result
		);
	}

	/**
	 * PRO-3868: the failed batch leaves the row failed — no progress write
	 * flips it to running/completed or moves the cursor past the users of
	 * this page that were never sent — and stops the tick chain.
	 */
	public function test_process_batch_records_error_on_api_failure_and_stops_there(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'status'          => 'running',
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '3',
			),
			array( 1, 2, 3 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn(
			array(
				$this->fake_user( 1, 'a@x.test' ),
				$this->fake_user( 2, 'b@x.test' ),
				$this->fake_user( 3, 'c@x.test' ),
			)
		);

		$calls  = 0;
		$client = $this->createMock( Client::class );
		$client->expects( $this->exactly( 2 ) )
			->method( 'upsert_subscribers' )
			->willReturnCallback(
				static function () use ( &$calls ): array {
					++$calls;
					if ( $calls === 2 ) {
						throw new ApiException( 'rate limited', 429 );
					}
					return array();
				}
			);

		$result = ( new BackfillJob( $client ) )->process_batch( 3 );

		self::assertCount( 1, $wpdb->updates, 'Only the error is written — nothing overwrites it.' );
		self::assertSame( 'failed', $wpdb->updates[0]['data']['status'] );
		self::assertSame( 'rate limited', $wpdb->updates[0]['data']['error_message'] );
		self::assertSame( 'running', $wpdb->updates[0]['where']['status'] );
		self::assertSame( 1, $result['processed'] );
		self::assertSame( 3, $result['remaining'] );
		self::assertTrue( $result['completed'], 'A failed import schedules no further tick.' );
	}

	/**
	 * PRO-3988: Smaily refuses one contact for good in its body code while it
	 * accepts the others. The contact is skipped — not marked or counted as
	 * synced — and recorded with its user id and Smaily's reason (address
	 * masked); the rest of the page is sent and the walk goes on.
	 */
	public function test_process_batch_skips_a_contact_smaily_refuses_and_records_it(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'status'          => 'running',
				'cursor_value'    => '0',
				'processed_count' => '0',
				'synced_count'    => '0',
				'total_count'     => '3',
			),
			array( 1, 2, 3 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn(
			array(
				$this->fake_user( 1, 'a@example.com' ),
				$this->fake_user( 2, 'bad@example.com' ),
				$this->fake_user( 3, 'c@example.com' ),
			)
		);
		$marked = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id ) use ( &$marked ): bool {
				$marked[] = $user_id;
				return true;
			}
		);
		$stored = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value, $autoload = null ) use ( &$stored ): bool {
				$stored[ $key ] = array( $value, $autoload );
				return true;
			}
		);

		$result = ( new BackfillJob( $this->client_refusing( array( 'bad@example.com' ) ) ) )->process_batch( 10 );

		self::assertSame( array( 1, 3 ), $marked, 'Only the contacts Smaily accepted are marked synced.' );
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'completed', $wpdb->updates[0]['data']['status'], 'One refused contact does not stop the import.' );
		self::assertSame( 2, $wpdb->updates[0]['data']['synced_count'], 'The refused contact is not counted as synced.' );
		self::assertSame( '3', $wpdb->updates[0]['data']['cursor_value'], 'The walk moves past the refused contact.' );
		self::assertSame( 2, $result['processed'] );
		self::assertSame(
			array(
				array(
					'count'    => 1,
					'contacts' => array(
						array(
							'user_id' => 2,
							'reason'  => 'permanent_envelope_204: Smaily API returned code 204: Invalid email [email]',
						),
					),
				),
				false,
			),
			$stored[ BackfillJob::OPTION_REFUSED ] ?? null,
			'The refused contact is recorded by user id, with Smaily\'s reason and no address, autoload off.'
		);
	}

	/**
	 * PRO-3988: on the daily refresh a page often sends just one contact. A
	 * refusal of that single contact is still that contact's, not the
	 * request's — the import goes on.
	 */
	public function test_process_batch_skips_the_only_contact_a_page_sends_when_smaily_refuses_it(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'status'          => 'running',
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '200',
			),
			array( 1 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn( array( $this->fake_user( 1, 'bad@example.com' ) ) );
		$recorded = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$recorded ): bool {
				$recorded = $value;
				return true;
			}
		);

		( new BackfillJob( $this->client_refusing( array( 'bad@example.com' ) ) ) )->process_batch( 1 );

		self::assertSame( 1, $recorded['count'] ?? null, 'The refused contact is recorded.' );
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'running', $wpdb->updates[0]['data']['status'], 'The import goes on to the next page.' );
		self::assertSame( '1', $wpdb->updates[0]['data']['cursor_value'] );
	}

	/**
	 * PRO-3988: when Smaily refuses every contact a page sends (two or more),
	 * the refusal is about the request, not a contact: the import stops
	 * failed as before (PRO-3868), with Smaily's answer, the cursor before
	 * the page and no refused-contact list written.
	 */
	public function test_process_batch_stops_failed_when_smaily_refuses_every_contact_of_a_page(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'status'          => 'running',
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '2',
			),
			array( 1, 2 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn(
			array(
				$this->fake_user( 1, 'a@example.com' ),
				$this->fake_user( 2, 'b@example.com' ),
			)
		);
		Functions\when( 'update_option' )->alias(
			static function (): bool {
				throw new \LogicException( 'A request-level refusal records no refused contacts.' );
			}
		);

		$result = ( new BackfillJob( $this->client_refusing( array( 'a@example.com', 'b@example.com' ) ) ) )->process_batch( 10 );

		self::assertCount( 1, $wpdb->updates, 'Only the error is written — the cursor stays before the page.' );
		self::assertSame( 'failed', $wpdb->updates[0]['data']['status'] );
		self::assertSame( 'permanent_envelope_204: Smaily API returned code 204: Invalid email b@example.com', $wpdb->updates[0]['data']['error_message'] );
		self::assertSame( 'running', $wpdb->updates[0]['where']['status'] );
		self::assertTrue( $result['completed'], 'A failed import schedules no further tick.' );
	}

	/**
	 * PRO-3868: a tick that reads a failed import — one Action Scheduler had
	 * already claimed — sends nothing and leaves the row failed, like a
	 * cancelled one (PRO-3821).
	 */
	public function test_process_batch_sends_nothing_for_a_failed_import(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'status'          => 'failed',
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '1',
			),
			array( 1 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'upsert_subscribers' );

		$result = ( new BackfillJob( $client ) )->process_batch();

		self::assertSame( array(), $wpdb->updates );
		self::assertSame( 0, $result['processed'] );
		self::assertTrue( $result['completed'] );
	}

	public function test_backfill_payload_carries_resolved_default_language(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '1',
			),
			array( 1 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn( array( $this->fake_user( 1, 'a@x.test' ) ) );

		// No _user_preferred_language meta → resolver falls to the SiteLocale
		// default ('et_EE' → 'et'). This is the corrective re-sync: a contact
		// the buggy cron pushed as 'en' is re-sent with the store default.
		( new BackfillJob( $this->capturing_client() ) )->process_batch( 10 );

		self::assertSame( 'et', $this->captured_payload['language'] ?? null );
	}

	public function test_should_start_refresh_true_when_never_run(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch( null );

		self::assertTrue( ( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh() );
	}

	public function test_should_start_refresh_false_while_a_walk_is_running(): void {
		$this->batch_queued( true );
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch( $this->running_since( 3600 ) );

		self::assertFalse(
			( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh(),
			'Restarting a running walk would reset its cursor.'
		);
	}

	public function test_should_start_refresh_false_for_a_walk_started_within_the_grace_period(): void {
		$this->batch_queued( false );
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch( $this->running_since( 60 ) );

		self::assertFalse(
			( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh(),
			'A just-started walk whose first batch is not queued yet is not stalled.'
		);
	}

	/**
	 * PRO-3981: a running walk that nothing drives any more is restarted by the
	 * daily refresh, the way a failed one is.
	 */
	public function test_should_start_refresh_true_when_a_running_walk_has_stalled(): void {
		$this->batch_queued( false );
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch(
			$this->running_since( AbstractBackfillJob::STALL_GRACE_SECONDS + 600 )
		);

		self::assertTrue( ( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh() );
	}

	public function test_should_start_refresh_true_after_a_failed_walk(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch( array( 'status' => 'failed' ) );

		self::assertTrue( ( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh() );
	}

	public function test_should_start_refresh_false_when_recently_completed(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch(
			array( 'status' => 'completed', 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) )
		);

		self::assertFalse(
			( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh(),
			'A refresh completed an hour ago — nothing is due within the 7-day freshness window.'
		);
	}

	public function test_should_start_refresh_true_when_completed_long_ago(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb_for_process_batch(
			array( 'status' => 'completed', 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - ( 8 * 86400 ) ) )
		);

		self::assertTrue(
			( new BackfillJob( $this->createMock( Client::class ) ) )->should_start_refresh(),
			'Last refresh is older than the freshness window — re-arm.'
		);
	}

	public function test_process_batch_skips_non_opted_in_users_in_consent_mode(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => '1',
			),
			array( 1 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn( array( $this->fake_user( 1, 'a@x.test' ) ) );
		// Default mode is consent; this user is NOT opted in → audience-skipped.
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'upsert_subscribers' );

		$result = ( new BackfillJob( $client ) )->process_batch();

		self::assertSame( 0, $result['processed'], 'Consent mode must skip users without user_newsletter=1.' );
	}

	/**
	 * PRO-1769: the cursor has to reach the QUERY. It used to be applied in PHP
	 * to a page that always started at the first user, so every tick after the
	 * first re-read page one, filtered it empty, and closed the job as done.
	 */
	public function test_process_batch_asks_the_database_for_the_page_after_the_cursor(): void {
		$wpdb            = $this->fake_wpdb_for_process_batch(
			array(
				'id'              => 77,
				'cursor_value'    => '4706',
				'processed_count' => '100',
				'total_count'     => '151',
			),
			array( 4707, 4708 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_users' )->justReturn(
			array(
				$this->fake_user( 4707, 'c@x.test' ),
				$this->fake_user( 4708, 'd@x.test' ),
			)
		);

		( new BackfillJob( $this->createMock( Client::class ) ) )->process_batch( 2 );

		$page_query = null;
		foreach ( $wpdb->prepare_calls as $call ) {
			if ( strpos( $call['sql'], 'FROM wp_users' ) !== false ) {
				$page_query = $call;
			}
		}

		self::assertNotNull( $page_query, 'The user page is read with a WHERE ID > cursor query.' );
		self::assertStringContainsString( 'WHERE ID > %d', $page_query['sql'] );
		self::assertSame( 4706, $page_query['args'][0], 'The stored cursor is what the query pages after.' );
		self::assertSame( 2, $page_query['args'][1], 'The batch size is the LIMIT.' );
		self::assertSame( '4708', $wpdb->updates[0]['data']['cursor_value'], 'The cursor advances to the last id of the page.' );
	}

	/**
	 * Client mock whose upsert_subscribers records the first contact payload
	 * into $this->captured_payload.
	 */
	private function capturing_client(): Client {
		$captured = &$this->captured_payload;

		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )->willReturnCallback(
			static function ( array $subscribers ) use ( &$captured ): array {
				$captured = is_array( $subscribers[0] ?? null ) ? $subscribers[0] : array();
				return array();
			}
		);

		return $client;
	}

	/**
	 * Client mock that answers HTTP 200 with Smaily's code 204 for the given
	 * addresses and 101 for every other contact.
	 *
	 * @param string[] $refused
	 */
	private function client_refusing( array $refused ): Client {
		$last   = '';
		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )->willReturnCallback(
			static function ( array $subscribers ) use ( &$last ): array {
				$last = (string) ( $subscribers[0]['email'] ?? '' );
				return array();
			}
		);
		$client->method( 'last_exchange' )->willReturnCallback(
			static function () use ( &$last, $refused ): array {
				$body = in_array( $last, $refused, true )
					? array(
						'code'    => 204,
						'message' => 'Invalid email ' . $last,
					)
					: array(
						'code'    => 101,
						'message' => 'OK',
					);
				return array(
					'response' => array(
						'http' => 200,
						'body' => $body,
					),
				);
			}
		);

		return $client;
	}

	private function fake_user( int $id, string $email ): \WP_User {
		return new class( $id, $email ) extends \WP_User {
			public function __construct( int $id, string $email ) {
				$this->ID         = $id;
				$this->user_email = $email;
				$this->first_name = '';
				$this->last_name  = '';
			}
		};
	}

	/**
	 * @param int $audience Rows ContactAudience::count_audience() finds — the
	 *                      COUNT() query start() runs before seeding the row.
	 */
	private function fake_wpdb_for_start( int $stamped_id, int $audience = 1 ): object {
		return new class( $stamped_id, $audience ) {
			public string $prefix   = 'wp_';
			public string $usermeta = 'wp_usermeta';
			public array $prepare_calls = array();
			public array $queries       = array();
			public array $updates       = array();
			private int $stamp;
			private int $audience;

			public function __construct( int $id, int $audience ) {
				$this->stamp    = $id;
				$this->audience = $audience;
			}

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_var( string $sql ) {
				return strpos( $sql, 'COUNT(' ) !== false
					? (string) $this->audience
					: (string) $this->stamp;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				return 1;
			}
		};
	}

	/**
	 * @param array<string, mixed>|null $state_row
	 * @param int[]                     $user_ids  The page the cursor query
	 *                                             (`WHERE ID > cursor`) returns.
	 */
	/**
	 * Whether Action Scheduler holds a contact-import batch, as is_stalled() asks.
	 */
	private function batch_queued( bool $queued ): void {
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( string $hook, ?array $args = null ) use ( $queued ): bool {
				self::assertSame( BackfillJobInterface::TICK_HOOK, $hook );
				self::assertSame( array( 'job_type' => BackfillJob::BACKFILL_TYPE ), $args );
				return $queued;
			}
		);
	}

	/**
	 * @return array<string, string> A running state row that started $seconds ago.
	 */
	private function running_since( int $seconds ): array {
		return array(
			'status'     => 'running',
			'started_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
		);
	}

	private function fake_wpdb_for_process_batch( ?array $state_row, array $user_ids = array() ): object {
		return new class( $state_row, $user_ids ) {
			public string $prefix         = 'wp_';
			public string $users          = 'wp_users';
			public array $prepare_calls   = array();
			public array $updates         = array();
			public array $queries         = array();
			private ?array $state_row     = null;
			/** @var int[] */
			private array $user_ids       = array();

			/**
			 * @param array<string, mixed>|null $row
			 * @param int[]                     $user_ids
			 */
			public function __construct( ?array $row, array $user_ids ) {
				$this->state_row = $row;
				$this->user_ids  = $user_ids;
			}

			/** @return int[] */
			public function get_col( string $sql ): array {
				return $this->user_ids;
			}

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}

			public function get_row( string $sql, string $output = ARRAY_A ) {
				return $this->state_row;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				return 1;
			}
		};
	}
}
