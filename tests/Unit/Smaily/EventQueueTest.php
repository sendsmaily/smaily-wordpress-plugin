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
