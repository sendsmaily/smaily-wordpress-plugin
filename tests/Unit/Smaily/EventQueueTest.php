<?php
/**
 * EventQueue tests — exercise enqueue() against a stubbed $wpdb + AS API
 * mocks. The integration suite (added later) covers real DB writes.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\EventQueue;

final class EventQueueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Default Action Scheduler env: functions exist and no job is queued yet.
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_enqueue_persists_row_and_schedules_flush(): void {
		$wpdb           = $this->fake_wpdb_with_successful_insert( 1234 );
		$GLOBALS['wpdb'] = $wpdb;

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$enqueued ): int {
				$enqueued[] = compact( 'hook', 'args', 'group' );
				return 1;
			}
		);

		$queue = new EventQueue();
		$id    = $queue->enqueue( 'contact.sync', '42', array( 'email' => 'a@b.c' ) );

		self::assertSame( 1234, $id );
		self::assertCount( 1, $wpdb->inserts );
		self::assertSame( 'wp_smly_plus_event_queue', $wpdb->inserts[0]['table'] );
		self::assertSame( 'contact.sync', $wpdb->inserts[0]['data']['event_type'] );
		self::assertSame( '42', $wpdb->inserts[0]['data']['entity_id'] );
		self::assertSame( EventQueue::STATUS_PENDING, $wpdb->inserts[0]['data']['status'] );
		self::assertCount( 1, $enqueued );
		self::assertSame( EventQueue::FLUSH_HOOK, $enqueued[0]['hook'] );
		self::assertSame( EventQueue::AS_GROUP, $enqueued[0]['group'] );
	}

	public function test_enqueue_keys_the_row_to_its_contact_as_a_hash(): void {
		// PRO-1723: the row carries a HASH of the address, never the address —
		// it is what the checkout path looks the contact's rows up by, and it
		// is normalised so a differently-cased checkout address still finds
		// the row a reminder was queued under.
		$wpdb            = $this->fake_wpdb_with_successful_insert( 1 );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		$queue = new EventQueue();
		$queue->enqueue( 'automation.abandoned_cart', '42', array( 'email' => '  Shopper@Example.TEST ' ) );
		$queue->enqueue( 'contact.sync', '43', array( 'fields' => array() ) );

		self::assertSame(
			hash( 'sha256', 'shopper@example.test' ),
			$wpdb->inserts[0]['data']['contact_key']
		);
		self::assertNull(
			$wpdb->inserts[1]['data']['contact_key'],
			'A row with no address carries no key.'
		);
	}

	public function test_withdraw_pending_for_answers_both_questions_from_one_keyed_read(): void {
		// The checkout path asks "was a reminder delivered?" and "is one still
		// pending?" about the same shopper — one SELECT by contact_key, no
		// search of the stored payload text (PRO-1723).
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		$wpdb->next_results = array(
			array(
				'id'           => 5,
				'status'       => EventQueue::STATUS_SENT,
				'sent_payload' => '{"addresses":[]}',
			),
			array(
				'id'           => 6,
				'status'       => EventQueue::STATUS_PENDING,
				'sent_payload' => null,
			),
		);

		$delivered = ( new EventQueue() )->withdraw_pending_for( 'automation.abandoned_cart', 'Shopper@example.test' );

		self::assertTrue( $delivered );
		self::assertCount( 1, $wpdb->prepare_calls, 'Both answers come from one read.' );
		self::assertStringContainsString( 'WHERE event_type = %s AND contact_key = %s', $wpdb->prepare_calls[0]['sql'] );
		self::assertStringNotContainsString( 'LIKE', $wpdb->prepare_calls[0]['sql'], 'The queue no longer searches its own payload text.' );
		self::assertSame(
			array( 'automation.abandoned_cart', EventQueue::contact_key( 'shopper@example.test' ) ),
			$wpdb->prepare_calls[0]['args']
		);

		// Only the pending row is withdrawn, through the terminal-skip pair
		// (mark_sent + a skip exchange), by primary key.
		self::assertCount( 2, $wpdb->updates );
		self::assertSame( array( 'id' => 6 ), $wpdb->updates[0]['where'] );
		self::assertSame( array( 'status' => EventQueue::STATUS_SENT ), $wpdb->updates[0]['data'] );
		self::assertSame( array( 'id' => 6 ), $wpdb->updates[1]['where'] );
		self::assertNull( $wpdb->updates[1]['data']['sent_payload'], 'Nothing was POSTed.' );
		self::assertStringContainsString( '"outcome":"cancelled"', (string) $wpdb->updates[1]['data']['last_response'] );
	}

	public function test_a_row_that_posted_nothing_does_not_count_as_delivered(): void {
		// A terminal skip (no workflow mapped) also ends as `sent` — only
		// sent_payload tells the two apart, and nothing may be withdrawn.
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		$wpdb->next_results = array(
			array(
				'id'           => 5,
				'status'       => EventQueue::STATUS_SENT,
				'sent_payload' => null,
			),
		);

		self::assertFalse( ( new EventQueue() )->withdraw_pending_for( 'automation.abandoned_cart', 'shopper@example.test' ) );
		self::assertSame( array(), $wpdb->updates );
	}

	public function test_enqueue_returns_null_when_insert_fails(): void {
		$wpdb           = $this->fake_wpdb_with_failed_insert();
		$GLOBALS['wpdb'] = $wpdb;

		$scheduled = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled ): int {
				$scheduled = true;
				return 1;
			}
		);

		$queue = new EventQueue();
		$id    = $queue->enqueue( 'contact.sync', '42', array() );

		self::assertNull( $id );
		self::assertFalse( $scheduled, 'Flush must not be scheduled when the row insert fails.' );
	}

	public function test_enqueue_skips_scheduling_when_flush_already_pending(): void {
		$wpdb            = $this->fake_wpdb_with_successful_insert( 99 );
		$GLOBALS['wpdb'] = $wpdb;

		// Pretend AS already has a pending flush.
		Functions\when( 'as_next_scheduled_action' )->justReturn( 4242 );

		$scheduled_again = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled_again ): int {
				$scheduled_again = true;
				return 1;
			}
		);

		$queue = new EventQueue();
		$queue->enqueue( 'contact.sync', '7', array() );

		self::assertFalse(
			$scheduled_again,
			'enqueue() must dedupe — a second flush schedule on top of an existing pending one is wasteful.'
		);
	}

	public function test_enqueue_returns_null_when_payload_cannot_be_json_encoded(): void {
		$wpdb            = $this->fake_wpdb_with_successful_insert( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'wp_json_encode' )->justReturn( false );

		$queue = new EventQueue();
		self::assertNull( $queue->enqueue( 'contact.sync', '7', array( 'whatever' ) ) );
		self::assertCount( 0, $wpdb->inserts, 'No insert should be attempted on JSON failure.' );
	}

	public function test_pending_runs_prepared_statement_and_returns_rows(): void {
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		$rows = array(
			array(
				'id'         => 1,
				'event_type' => 'contact.sync',
				'entity_id'  => '42',
				'payload'    => '{}',
				'created_at' => '2026-05-19 12:00:00',
				'attempts'   => 0,
			),
		);
		$wpdb->next_results = $rows;

		$result = ( new EventQueue() )->pending( 25 );

		self::assertSame( $rows, $result );
		self::assertCount( 1, $wpdb->prepare_calls );
		self::assertStringContainsString( 'FROM wp_smly_plus_event_queue', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( EventQueue::STATUS_PENDING, $wpdb->prepare_calls[0]['args'][0] );
		self::assertSame( 25, $wpdb->prepare_calls[0]['args'][2] );
	}

	public function test_pending_only_returns_rows_whose_retry_park_has_elapsed(): void {
		// PRO-1685: a row parked by record_attempt()'s backoff must stay out
		// of the drain until it's due — otherwise the 60s tick hammers it and
		// it keeps its oldest-first slot ahead of fresher work.
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		( new EventQueue() )->pending( 25 );

		self::assertStringContainsString(
			'( next_retry_at IS NULL OR next_retry_at <= %s )',
			$wpdb->prepare_calls[0]['sql']
		);
		self::assertSame( '2026-05-19 12:00:00', $wpdb->prepare_calls[0]['args'][1], 'The due-check compares against UTC now.' );
	}

	public function test_pending_event_type_scoping_builds_in_and_not_in_clauses(): void {
		// PRO-1195: the queue is drained by two flushers — the CartFlusher
		// scopes to its own type, the main Flusher excludes it. Pin the SQL
		// shape + arg order for both.
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		$now = '2026-05-19 12:00:00';

		( new EventQueue() )->pending( 10, array( 'automation.abandoned_cart' ) );
		self::assertStringContainsString( 'event_type IN ( %s )', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( array( EventQueue::STATUS_PENDING, $now, 'automation.abandoned_cart', 10 ), $wpdb->prepare_calls[0]['args'] );

		( new EventQueue() )->pending( 10, null, array( 'automation.abandoned_cart' ) );
		self::assertStringContainsString( 'event_type NOT IN ( %s )', $wpdb->prepare_calls[1]['sql'] );
		self::assertSame( array( EventQueue::STATUS_PENDING, $now, 'automation.abandoned_cart', 10 ), $wpdb->prepare_calls[1]['args'] );
	}

	public function test_pending_returns_empty_array_when_get_results_returns_non_array(): void {
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		$wpdb->next_results = null;

		self::assertSame( array(), ( new EventQueue() )->pending() );
	}

	public function test_mark_sent_writes_status_sent_via_update(): void {
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		( new EventQueue() )->mark_sent( 42 );

		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'wp_smly_plus_event_queue', $wpdb->updates[0]['table'] );
		self::assertSame(
			array( 'status' => EventQueue::STATUS_SENT ),
			$wpdb->updates[0]['data']
		);
		self::assertSame( array( 'id' => 42 ), $wpdb->updates[0]['where'] );
	}

	public function test_mark_failed_writes_status_and_error_message(): void {
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		( new EventQueue() )->mark_failed( 7, 'timeout' );

		self::assertCount( 1, $wpdb->updates );
		self::assertSame( EventQueue::STATUS_FAILED, $wpdb->updates[0]['data']['status'] );
		self::assertSame( 'timeout', $wpdb->updates[0]['data']['last_error'] );
		self::assertSame( array( 'id' => 7 ), $wpdb->updates[0]['where'] );
	}

	public function test_record_attempt_bumps_counter_and_parks_the_row(): void {
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		( new EventQueue() )->record_attempt( 5, 'rate limited', 900 );

		self::assertCount( 1, $wpdb->prepare_calls );
		self::assertStringContainsString( 'attempts = attempts + 1', $wpdb->prepare_calls[0]['sql'] );
		self::assertStringContainsString( 'next_retry_at = ( UTC_TIMESTAMP() + INTERVAL %d SECOND )', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( array( 'rate limited', 900, 5 ), $wpdb->prepare_calls[0]['args'] );
		self::assertCount( 1, $wpdb->queries );
	}

	public function test_record_attempt_without_a_backoff_leaves_the_row_due_immediately(): void {
		// The backoff is opt-in: TransactionalFlusher bounds its retries by
		// elapsed time, not by spacing, so it must keep the every-tick
		// behaviour it was built on (PRO-1519 / PRO-1685).
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		( new EventQueue() )->record_attempt( 5, 'temporary outage' );

		self::assertSame( array( 'temporary outage', 0, 5 ), $wpdb->prepare_calls[0]['args'] );
	}

	public function test_reset_failed_clears_the_retry_park_so_a_revived_row_is_due_now(): void {
		// The Event Log recovery path must be able to undo a wrong
		// classification: status, attempts AND the park all go back (PRO-1685).
		$wpdb            = $this->fake_wpdb_full();
		$GLOBALS['wpdb'] = $wpdb;

		( new EventQueue() )->reset_failed( array( 7 ) );

		self::assertStringContainsString( 'attempts = 0', $wpdb->prepare_calls[0]['sql'] );
		self::assertStringContainsString( 'next_retry_at = NULL', $wpdb->prepare_calls[0]['sql'] );
	}

	public function test_redaction_keeps_the_shape_and_drops_every_personal_value(): void {
		// PRO-2383: an erased contact's already-sent row stays in the Event Log,
		// so the KEYS survive (the merchant can still see what shape went out)
		// and the values do not — whatever the event type's payload carries.
		$json = (string) EventQueue::redact_json(
			(string) wp_json_encode(
				array(
					'email'  => 'erase-me@example.test',
					'fields' => array(
						'first_name'       => 'Given',
						'last_name'        => 'Family',
						'product_name_1'   => 'Mystery Box',
						'is_abandoned_cart' => 'true',
					),
				)
			)
		);

		self::assertStringNotContainsString( 'erase-me@example.test', $json );
		self::assertStringNotContainsString( 'Given', $json );
		self::assertStringNotContainsString( 'Mystery Box', $json );

		$decoded = json_decode( $json, true );
		self::assertSame( array( 'email', 'fields' ), array_keys( $decoded ) );
		self::assertSame( EventQueue::ERASED_PLACEHOLDER, $decoded['email'] );
		self::assertSame( EventQueue::ERASED_PLACEHOLDER, $decoded['fields']['first_name'] );
		self::assertArrayHasKey( 'product_name_1', $decoded['fields'], 'The matrix keys stay so the row still reads as a reminder.' );
	}

	public function test_redaction_keeps_the_routing_and_outcome_scalars(): void {
		// The Event Log labels a withdrawn reminder from `outcome` (PRO-2372)
		// and the "Send again" guard reads `to_status` — neither says anything
		// about the person, and losing them would break a row that must stay
		// readable after the erasure.
		$exchange = (string) EventQueue::redact_json( '{"http":200,"outcome":"cancelled","error":"no contact erase-me@example.test"}' );
		self::assertSame(
			array(
				'http'    => 200,
				'outcome' => 'cancelled',
				'error'   => EventQueue::ERASED_PLACEHOLDER,
			),
			json_decode( $exchange, true )
		);

		$payload = (string) EventQueue::redact_json( '{"to":"erase-me@example.test","workflow_id":"wf-7","account_key":"main","to_status":"completed"}' );
		self::assertSame(
			array(
				'to'          => EventQueue::ERASED_PLACEHOLDER,
				'workflow_id' => 'wf-7',
				'account_key' => 'main',
				'to_status'   => 'completed',
			),
			json_decode( $payload, true )
		);
	}

	public function test_redaction_replaces_an_undecodable_blob_wholesale_and_leaves_an_absent_one_alone(): void {
		// Nothing can be assumed impersonal in a blob we cannot parse; a row
		// that stored nothing (a terminal skip's null sent_payload) stays null.
		self::assertSame( EventQueue::ERASED_PLACEHOLDER, EventQueue::redact_json( 'erase-me@example.test' ) );
		self::assertNull( EventQueue::redact_json( null ) );
		self::assertSame( '', EventQueue::redact_json( '' ) );
	}

	/**
	 * Builds a fake $wpdb compatible enough with EventQueue's usage to record
	 * insert() and update() calls without touching a real database.
	 */
	private function fake_wpdb_with_successful_insert( int $insert_id ): object {
		return new class( $insert_id ) {
			public string $prefix    = 'wp_';
			public int $insert_id    = 0;
			public array $inserts    = array();
			private int $stamped_id  = 0;

			public function __construct( int $id ) {
				$this->stamped_id = $id;
			}

			public function insert( string $table, array $data, array $formats ): int {
				$this->inserts[]  = compact( 'table', 'data', 'formats' );
				$this->insert_id  = $this->stamped_id;
				return 1;
			}
		};
	}

	private function fake_wpdb_with_failed_insert(): object {
		// Matches the real $wpdb->insert signature: returns int|false (false
		// on error). The EventQueue check is "$inserted !== 1", so false
		// triggers the early-return path.
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 0;
			public array $inserts = array();

			public function insert( string $table, array $data, array $formats ) {
				return false;
			}
		};
	}

	/**
	 * Build a $wpdb stub that covers the full surface EventQueue's read /
	 * mutate methods touch — prepare(), get_results(), update(), query().
	 */
	private function fake_wpdb_full(): object {
		return new class() {
			public string $prefix = 'wp_';

			public array $prepare_calls = array();
			public array $updates       = array();
			public array $queries       = array();

			/** @var array<int, array<string, mixed>>|null */
			public ?array $next_results = array();

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}

			public function get_results( string $sql, string $output = ARRAY_A ) {
				return $this->next_results;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				return 1;
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}
		};
	}
}
