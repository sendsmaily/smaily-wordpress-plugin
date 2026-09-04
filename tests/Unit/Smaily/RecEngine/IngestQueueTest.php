<?php
/**
 * IngestQueue tests — exercise the rec-engine queue against a stubbed
 * $wpdb + Action Scheduler mocks. The integration suite covers real DB
 * writes + the uniq_event_uuid constraint end-to-end.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class IngestQueueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_time' )->justReturn( '2026-06-02 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'gen-uuid-0000' );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_enqueue_inserts_generated_uuid_and_schedules_flush(): void {
		$wpdb            = $this->fake_wpdb( 1, 777 );
		$GLOBALS['wpdb'] = $wpdb;

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$enqueued ): int {
				$enqueued[] = compact( 'hook', 'args', 'group' );
				return 1;
			}
		);

		$queue = new IngestQueue();
		$id    = $queue->enqueue( 'catalog.upsert', '42', array( 'sku' => 'ACA-1' ) );

		self::assertSame( 777, $id );
		self::assertCount( 1, $wpdb->prepare_calls );
		self::assertStringContainsString( 'INSERT IGNORE INTO wp_smly_rec_event_queue', $wpdb->prepare_calls[0]['sql'] );

		$args = $wpdb->prepare_calls[0]['args'];
		self::assertSame( 'catalog.upsert', $args[0] );
		self::assertSame( '42', $args[1] );
		self::assertSame( 'gen-uuid-0000', $args[2], 'event_uuid must come from wp_generate_uuid4().' );
		self::assertSame( '{"sku":"ACA-1"}', $args[3] );
		self::assertSame( IngestQueue::DEFAULT_MAX_ATTEMPTS, $args[6] );
		self::assertSame( IngestQueue::STATUS_PENDING, $args[7] );

		self::assertCount( 1, $enqueued );
		self::assertSame( IngestQueue::FLUSH_HOOK, $enqueued[0]['hook'] );
		self::assertSame( IngestQueue::AS_GROUP, $enqueued[0]['group'] );
	}

	public function test_enqueue_schedules_the_given_flush_hook_when_provided(): void {
		$wpdb            = $this->fake_wpdb( 1, 5 );
		$GLOBALS['wpdb'] = $wpdb;

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$enqueued ): int {
				$enqueued[] = compact( 'hook', 'group' );
				return 1;
			}
		);

		( new IngestQueue() )->enqueue(
			'customer.upsert',
			'67',
			array(),
			null,
			'smly_rec_flush_customers',
			'smaily-rec-customers'
		);

		self::assertCount( 1, $enqueued );
		self::assertSame( 'smly_rec_flush_customers', $enqueued[0]['hook'], 'A customer row schedules the customer flush hook, not catalog.' );
		self::assertSame( 'smaily-rec-customers', $enqueued[0]['group'] );
	}

	public function test_enqueue_uses_explicit_event_uuid_when_provided(): void {
		$wpdb            = $this->fake_wpdb( 1, 5 );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		( new IngestQueue() )->enqueue( 'catalog.upsert', '7', array(), 'caller-supplied-uuid' );

		self::assertSame(
			'caller-supplied-uuid',
			$wpdb->prepare_calls[0]['args'][2],
			'An explicit event_uuid must override the generated one — this is how a retry re-sends the same idempotency key.'
		);
	}

	public function test_enqueue_returns_null_and_skips_schedule_on_duplicate_uuid(): void {
		// INSERT IGNORE returns 0 affected rows when uniq_event_uuid collides.
		$wpdb            = $this->fake_wpdb( 0, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		$scheduled = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled ): int {
				$scheduled = true;
				return 1;
			}
		);

		$id = ( new IngestQueue() )->enqueue( 'catalog.upsert', '7', array() );

		self::assertNull( $id, 'A duplicate event_uuid must be a silent no-op, not a new row.' );
		self::assertFalse( $scheduled, 'No flush should be scheduled when nothing was inserted.' );
	}

	public function test_enqueue_returns_null_on_db_error(): void {
		$wpdb            = $this->fake_wpdb( false, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		$scheduled = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled ): int {
				$scheduled = true;
				return 1;
			}
		);

		self::assertNull( ( new IngestQueue() )->enqueue( 'catalog.upsert', '7', array() ) );
		self::assertFalse( $scheduled );
	}

	public function test_enqueue_returns_null_when_payload_cannot_be_json_encoded(): void {
		$wpdb            = $this->fake_wpdb( 1, 1 );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'wp_json_encode' )->justReturn( false );

		self::assertNull( ( new IngestQueue() )->enqueue( 'catalog.upsert', '7', array( 'x' ) ) );
		self::assertCount( 0, $wpdb->prepare_calls, 'No query should run on JSON-encode failure.' );
	}

	public function test_enqueue_dedupes_flush_when_one_is_already_pending(): void {
		$wpdb            = $this->fake_wpdb( 1, 9 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'as_next_scheduled_action' )->justReturn( 4242 );

		$scheduled_again = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled_again ): int {
				$scheduled_again = true;
				return 1;
			}
		);

		( new IngestQueue() )->enqueue( 'catalog.upsert', '7', array() );

		self::assertFalse( $scheduled_again, 'enqueue() must not stack a second flush on an existing pending one.' );
	}

	public function test_pending_runs_prepared_select_with_retry_window(): void {
		$wpdb            = $this->fake_wpdb( 1, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		$rows = array(
			array(
				'id'           => 1,
				'event_type'   => 'catalog.upsert',
				'entity_id'    => '42',
				'event_uuid'   => 'gen-uuid-0000',
				'payload'      => '{}',
				'created_at'   => '2026-06-02 12:00:00',
				'attempts'     => 0,
				'max_attempts' => 5,
			),
		);
		$wpdb->next_results = $rows;

		$result = ( new IngestQueue() )->pending( 100 );

		self::assertSame( $rows, $result );
		self::assertStringContainsString( 'FROM wp_smly_rec_event_queue', $wpdb->prepare_calls[0]['sql'] );
		self::assertStringContainsString( 'next_retry_at IS NULL OR next_retry_at <=', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( IngestQueue::STATUS_PENDING, $wpdb->prepare_calls[0]['args'][0] );
		self::assertSame( 100, $wpdb->prepare_calls[0]['args'][2] );
		self::assertStringNotContainsString( 'event_type IN', $wpdb->prepare_calls[0]['sql'], 'No event_type filter when none requested.' );
	}

	public function test_pending_scopes_to_event_types_when_requested(): void {
		$wpdb            = $this->fake_wpdb( 1, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		( new IngestQueue() )->pending( 50, array( 'customer.upsert', 'customer.delete' ) );

		$sql  = $wpdb->prepare_calls[0]['sql'];
		$args = $wpdb->prepare_calls[0]['args'];

		self::assertStringContainsString( 'event_type IN ( %s, %s )', $sql );
		// Arg order: status, time, <event types...>, limit.
		self::assertSame(
			array( IngestQueue::STATUS_PENDING, $args[1], 'customer.upsert', 'customer.delete', 50 ),
			$args,
			'Event types are bound between the retry-window time and the LIMIT.'
		);
	}

	public function test_pending_ignores_empty_event_types_filter(): void {
		$wpdb            = $this->fake_wpdb( 1, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		( new IngestQueue() )->pending( 100, array() );

		// Empty array behaves like null — no filter, limit stays at index 2.
		self::assertStringNotContainsString( 'event_type IN', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( 100, $wpdb->prepare_calls[0]['args'][2] );
	}

	public function test_pending_returns_empty_array_on_non_array_result(): void {
		$wpdb               = $this->fake_wpdb( 1, 0 );
		$wpdb->next_results = null;
		$GLOBALS['wpdb']    = $wpdb;

		self::assertSame( array(), ( new IngestQueue() )->pending() );
	}

	public function test_mark_sent_writes_status_sent(): void {
		$wpdb            = $this->fake_wpdb( 1, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		( new IngestQueue() )->mark_sent( 42 );

		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'wp_smly_rec_event_queue', $wpdb->updates[0]['table'] );
		self::assertSame( array( 'status' => IngestQueue::STATUS_SENT ), $wpdb->updates[0]['data'] );
		self::assertSame( array( 'id' => 42 ), $wpdb->updates[0]['where'] );
	}

	public function test_mark_failed_writes_status_and_error(): void {
		$wpdb            = $this->fake_wpdb( 1, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		( new IngestQueue() )->mark_failed( 7, 'http_400 bad_sku' );

		self::assertSame( IngestQueue::STATUS_FAILED, $wpdb->updates[0]['data']['status'] );
		self::assertSame( 'http_400 bad_sku', $wpdb->updates[0]['data']['last_error'] );
		self::assertSame( array( 'id' => 7 ), $wpdb->updates[0]['where'] );
	}

	public function test_record_attempt_bumps_counter_and_parks_next_retry(): void {
		$wpdb            = $this->fake_wpdb( 1, 0 );
		$GLOBALS['wpdb'] = $wpdb;

		( new IngestQueue() )->record_attempt( 5, 'rate_limited', 240 );

		self::assertStringContainsString( 'attempts = attempts + 1', $wpdb->prepare_calls[0]['sql'] );
		self::assertStringContainsString( 'next_retry_at', $wpdb->prepare_calls[0]['sql'] );
		self::assertSame( array( 'rate_limited', 240, 5 ), $wpdb->prepare_calls[0]['args'] );
	}

	/**
	 * Fake $wpdb covering IngestQueue's surface: prepare()/query() for the
	 * raw INSERT IGNORE + UPDATE + SELECT paths, update() for mark_*, and a
	 * settable query() return + insert_id to drive the enqueue branches.
	 *
	 * @param int|false $query_result Value query() returns (1 = inserted,
	 *                                0 = duplicate ignored, false = error).
	 */
	private function fake_wpdb( $query_result, int $insert_id ): object {
		return new class( $query_result, $insert_id ) {
			public string $prefix = 'wp_';
			public int $insert_id;
			public array $prepare_calls = array();
			public array $queries       = array();
			public array $updates       = array();
			/** @var array<int, array<string, mixed>>|null */
			public ?array $next_results = array();

			/** @var int|false */
			private $query_result;

			/**
			 * @param int|false $query_result
			 */
			public function __construct( $query_result, int $insert_id ) {
				$this->query_result = $query_result;
				$this->insert_id    = $insert_id;
			}

			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}

			/**
			 * @return int|false
			 */
			public function query( string $sql ) {
				$this->queries[] = $sql;
				return $this->query_result;
			}

			public function get_results( string $sql, string $output = ARRAY_A ) {
				return $this->next_results;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				return 1;
			}
		};
	}
}
