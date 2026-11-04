<?php
/**
 * AbstractBackfillJob — a batch that hits an unexpected error stops the
 * import (PRO-3890), a running import nothing drives counts as stalled
 * (PRO-3886), and one run sends page after page at the import pace until the
 * import ends, the budget is spent, the engine answers 429 or the backlog
 * guard stops it (CON-236, on a fake clock). Real WP + WC + Action Scheduler:
 * RecEngineImportStopTest, RecEngineImportPaceTest.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\AbstractD6Flusher;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportPace;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Unit\Support\FakeClockImportPace;

final class AbstractBackfillJobTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'current_time' )->justReturn( '2026-10-06 12:00:00' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_an_unexpected_error_marks_a_running_import_failed_and_ends_the_tick_chain(): void {
		$wpdb            = $this->fake_wpdb( $this->state( 'running' ) );
		$GLOBALS['wpdb'] = $wpdb;
		$job             = $this->job( new \RuntimeException( 'no such table for jane@example.com' ) );

		$result = $job->process_batch();

		self::assertTrue( $result['completed'], 'The tick schedules no further batch.' );
		self::assertSame( 0, $result['processed'] );
		self::assertSame( 7, $result['remaining'] );
		self::assertCount( 1, $wpdb->updates, 'Only the failure is written.' );
		self::assertSame( BackfillJobInterface::STATUS_FAILED, $wpdb->updates[0]['data']['status'] );
		self::assertMatchesRegularExpression( '/^RuntimeException at AbstractBackfillJobTest\.php:\d+$/', $wpdb->updates[0]['data']['error_message'] );
		self::assertSame(
			array(
				'id'     => 5,
				'status' => BackfillJobInterface::STATUS_RUNNING,
			),
			$wpdb->updates[0]['where'],
			'A cancel that landed meanwhile stays a cancel.'
		);
	}

	public function test_a_failed_import_sends_nothing_on_a_stray_tick(): void {
		$wpdb            = $this->fake_wpdb( $this->state( 'failed' ) );
		$GLOBALS['wpdb'] = $wpdb;
		$job             = $this->job( new \LogicException( 'must not walk' ) );

		$result = $job->process_batch();

		self::assertTrue( $result['completed'] );
		self::assertSame( array(), $wpdb->updates, 'The failure is left as it is.' );
	}

	public function test_a_running_import_with_no_batch_queued_after_the_grace_period_is_stalled(): void {
		$queued = false;
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( string $hook, ?array $args = null ) use ( &$queued ): bool {
				self::assertSame( BackfillJobInterface::TICK_HOOK, $hook );
				self::assertSame( array( 'job_type' => 'products' ), $args );
				return $queued;
			}
		);
		$old = $this->state( 'running', time() - AbstractBackfillJob::STALL_GRACE_SECONDS - 1 );

		self::assertTrue( AbstractBackfillJob::is_stalled( 'products', $old ) );

		$queued = true;
		self::assertFalse( AbstractBackfillJob::is_stalled( 'products', $old ), 'A queued or running batch drives it.' );

		$queued = false;
		self::assertFalse( AbstractBackfillJob::is_stalled( 'products', $this->state( 'running', time() - 60 ) ), 'Within the grace period after start.' );
		self::assertFalse( AbstractBackfillJob::is_stalled( 'products', $this->state( 'completed', time() - DAY_IN_SECONDS ) ), 'Only a running import can stall.' );
	}

	public function test_every_row_a_batch_queues_carries_the_run_mark_and_a_live_enqueue_none(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( $this->state( 'running', strtotime( '2026-11-02 12:00:00 UTC' ) ) );
		$flusher         = $this->createMock( AbstractD6Flusher::class );
		$flusher->method( 'sending_allowed' )->willReturn( true );
		$flusher->method( 'flush' )->willReturn(
			array(
				'processed' => 0,
				'sent'      => 0,
				'failed'    => 0,
				'retried'   => 0,
				'skipped'   => 0,
			)
		);
		$job = new class( $this->createMock( IngestQueue::class ), $flusher ) extends AbstractBackfillJob {
			/** @var array<int, array<string, mixed>> */
			public array $payloads = array();

			public function job_type(): string {
				return 'orders';
			}

			protected function batch_size(): int {
				return 2;
			}

			protected function count_total(): int {
				return 2;
			}

			protected function fetch_ids_after( int $after_id, int $limit ): array {
				return array( 11, 12 );
			}

			protected function enqueue_record( int $entity_id ): void {
				$this->payloads[] = $this->import_payload( array( 'object' => array( 'sku' => 'woo-' . $entity_id ) ) );
			}

			/** @return array<string, mixed> */
			public function outside_a_batch(): array {
				return $this->import_payload();
			}
		};

		$job->process_batch();

		$mark = array(
			'import' => 'orders',
			'run'    => '2026-11-02 12:00:00',
		);
		self::assertSame(
			array(
				array_merge( array( 'object' => array( 'sku' => 'woo-11' ) ), $mark ),
				array_merge( array( 'object' => array( 'sku' => 'woo-12' ) ), $mark ),
			),
			$job->payloads,
			'CON-248: the flusher counts these on this run; the captured object is kept.'
		);
		self::assertSame( array(), $job->outside_a_batch(), 'Outside a batch nothing is marked.' );
	}

	public function test_a_run_sends_page_after_page_at_the_pace_until_the_import_ends(): void {
		$wpdb            = $this->fake_wpdb( $this->walk_state( 7 ) );
		$GLOBALS['wpdb'] = $wpdb;
		$engine          = $this->engine();
		$job             = $this->paged_job( range( 1, 7 ), 2, $engine );
		$pace            = new FakeClockImportPace();

		$result = $job->process_run( $pace );

		self::assertTrue( $result['completed'], 'One run read the whole import.' );
		self::assertSame( 7, $result['processed'] );
		self::assertSame( 7, $result['sent'] );
		self::assertSame( range( 1, 7 ), $job->enqueued );
		self::assertSame( 'completed', $wpdb->state['status'] );
		self::assertSame( 4, $pace->turns, 'Four pages, one paced request each.' );
		self::assertEqualsWithDelta( 0.6, $pace->clock, 0.0001, 'Four requests start 0.2 s apart.' );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $result['next_run_in'] );
	}

	public function test_a_run_starts_no_request_after_its_budget(): void {
		$wpdb                  = $this->fake_wpdb( $this->walk_state( 100 ) );
		$GLOBALS['wpdb']       = $wpdb;
		$engine                = $this->engine();
		$job                   = $this->paged_job( range( 1, 100 ), 2, $engine );
		$pace                  = new FakeClockImportPace();
		$pace->request_seconds = 5.0;

		$result = $job->process_run( $pace );

		self::assertFalse( $result['completed'] );
		self::assertSame( 8, $pace->turns, 'Requests start at 0, 5, … 35 s; none at 40 s.' );
		self::assertSame( 16, $result['processed'] );
		self::assertSame( '16', (string) $wpdb->state['cursor_value'], 'The next run resumes after the last page read.' );
		self::assertSame( 'running', $wpdb->state['status'] );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $result['next_run_in'] );
	}

	public function test_a_429_ends_the_run_and_the_next_run_waits_the_engines_wait(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( $this->walk_state( 20 ) );
		$engine          = $this->engine( 3, new ApiException( 429, 'rate_limit_exceeded', 'slow down', array(), 17 ) );
		$job             = $this->paged_job( range( 1, 20 ), 2, $engine );
		$pace            = new FakeClockImportPace();

		$result = $job->process_run( $pace );

		self::assertFalse( $result['completed'] );
		self::assertSame( 3, $pace->turns, 'Nothing is sent after the 429.' );
		self::assertSame( 6, $result['processed'], 'The throttled page is read; its rows wait in the queue.' );
		self::assertSame( 4, $result['sent'] );
		self::assertSame( 17, $result['next_run_in'] );
	}

	public function test_the_backlog_guard_reads_no_page_while_2000_rows_wait(): void {
		$wpdb            = $this->fake_wpdb( $this->walk_state( 20 ) );
		$GLOBALS['wpdb'] = $wpdb;
		$engine          = $this->engine();
		$engine->backlog = ImportPace::BACKLOG_ROWS;
		$job             = $this->paged_job( range( 1, 20 ), 2, $engine );

		$result = $job->process_run( new FakeClockImportPace() );

		self::assertSame( array(), $job->enqueued, 'No page is read.' );
		self::assertSame( array(), $wpdb->updates, 'The cursor stays where it is.' );
		self::assertFalse( $result['completed'] );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $result['next_run_in'] );

		$engine->backlog = ImportPace::BACKLOG_ROWS - 1;
		self::assertTrue( $job->process_run( new FakeClockImportPace() )['completed'], 'Below the guard the run goes on.' );
	}

	public function test_a_run_that_may_not_send_reads_nothing_and_ends(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( $this->walk_state( 20 ) );
		$engine          = $this->engine();
		$engine->allowed = false;
		$job             = $this->paged_job( range( 1, 20 ), 2, $engine );

		$result = $job->process_run( new FakeClockImportPace() );

		self::assertSame( array(), $job->enqueued );
		self::assertFalse( $result['completed'], 'Paused, not lost (PRO-1893).' );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $result['next_run_in'] );
	}

	public function test_a_cancel_during_a_run_stops_it_at_the_next_page(): void {
		$wpdb            = $this->fake_wpdb( $this->walk_state( 20 ) );
		$GLOBALS['wpdb'] = $wpdb;
		$engine          = $this->engine();
		$engine->on_send = static function () use ( $wpdb ): void {
			$wpdb->state['status'] = BackfillJobInterface::STATUS_CANCELLED;
		};
		$job             = $this->paged_job( range( 1, 20 ), 2, $engine );

		$result = $job->process_run( new FakeClockImportPace() );

		self::assertSame( array( 1, 2 ), $job->enqueued, 'The page that was sending finishes; no other is read.' );
		self::assertSame( BackfillJobInterface::STATUS_CANCELLED, $wpdb->state['status'], 'PRO-3821: the cancel stays.' );
		self::assertTrue( $result['completed'], 'No further tick.' );
	}

	/**
	 * CON-282: a stray tick on a completed import — the row a start() that
	 * could not write left behind — walked it, the cursor write matched no
	 * running row, and the same page went out again for the whole run.
	 */
	public function test_a_tick_on_a_completed_import_sends_nothing_and_ends(): void {
		$wpdb            = $this->fake_wpdb( array_merge( $this->walk_state( 20 ), array( 'status' => BackfillJobInterface::STATUS_COMPLETED ) ) );
		$GLOBALS['wpdb'] = $wpdb;
		$engine          = $this->engine();
		$job             = $this->paged_job( range( 1, 20 ), 2, $engine );
		$pace            = new FakeClockImportPace();

		$result = $job->process_run( $pace );

		self::assertTrue( $result['completed'], 'The tick chain ends.' );
		self::assertSame( 0, $result['processed'] );
		self::assertSame( array(), $job->enqueued, 'Nothing is queued.' );
		self::assertSame( 0, $engine->requests, 'Nothing is sent.' );
		self::assertSame( array(), $wpdb->updates, 'The completed row is left as it is.' );
	}

	public function test_a_cursor_write_that_matches_no_running_row_ends_the_run(): void {
		$wpdb            = $this->fake_wpdb( $this->walk_state( 20 ) );
		$wpdb->refuse    = true;
		$GLOBALS['wpdb'] = $wpdb;
		$engine          = $this->engine();
		$job             = $this->paged_job( range( 1, 20 ), 2, $engine );

		$result = $job->process_run( new FakeClockImportPace() );

		self::assertSame( array( 1, 2 ), $job->enqueued, 'CON-282: the page is never read again.' );
		self::assertSame( 1, $engine->requests );
		self::assertTrue( $result['completed'], 'No further tick.' );
	}

	public function test_start_reports_0_when_the_row_cannot_be_written(): void {
		$GLOBALS['wpdb'] = $this->failing_wpdb();
		$job             = $this->paged_job( range( 1, 5 ), 2, $this->engine() );

		self::assertSame( 0, $job->start(), 'CON-282: e.g. a column the update has not added yet.' );
	}

	public function test_read_state_tells_a_database_error_from_never_ran(): void {
		$wpdb            = $this->failing_wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		self::assertFalse( AbstractBackfillJob::read_state( 'orders' ), 'CON-282: unreadable is not "never ran".' );

		$wpdb->fail = false;
		self::assertNull( AbstractBackfillJob::read_state( 'orders' ) );
	}

	/**
	 * A running import at its start, $total records to walk.
	 *
	 * @return array<string, mixed>
	 */
	private function walk_state( int $total ): array {
		return array_merge(
			$this->state( 'running' ),
			array(
				'cursor_value'    => '0',
				'processed_count' => '0',
				'total_count'     => (string) $total,
			)
		);
	}

	/**
	 * A flusher double that sends whatever the job queued: one paced request
	 * per pass, the $fail_on-th request failing with $error.
	 */
	private function engine( int $fail_on = 0, ?ApiException $error = null ): AbstractD6Flusher {
		return new class( $fail_on, $error ) extends AbstractD6Flusher {
			public int $queued   = 0;
			public int $backlog  = 0;
			public bool $allowed = true;
			public int $requests = 0;
			/** @var callable|null */
			public $on_send;
			private int $fail_on;
			private ?ApiException $error;

			public function __construct( int $fail_on, ?ApiException $error ) {
				$this->fail_on = $fail_on;
				$this->error   = $error;
			}

			public function sending_allowed(): bool {
				return $this->allowed;
			}

			public function backlog(): int {
				return $this->backlog;
			}

			public function flush( ?int $batch_size = null, ?ImportPace $pace = null ): array {
				$stats = array(
					'processed' => 0,
					'sent'      => 0,
					'failed'    => 0,
					'retried'   => 0,
					'skipped'   => 0,
				);
				if ( $this->queued === 0 ) {
					return $stats;
				}
				if ( $pace !== null ) {
					$pace->await_turn();
				}
				++$this->requests;
				$stats['processed'] = $this->queued;
				if ( $this->on_send !== null ) {
					( $this->on_send )();
				}
				if ( $this->requests === $this->fail_on && $this->error !== null ) {
					if ( $pace !== null ) {
						$pace->record_failure( $this->error );
					}
					$stats['retried'] = $this->queued;
				} else {
					$stats['sent'] = $this->queued;
				}
				$this->queued = 0;
				return $stats;
			}

			protected function event_types(): array {
				return array( 'customer.upsert' );
			}

			protected function batch_size(): int {
				return 100;
			}

			protected function endpoint_label(): string {
				return 'customers';
			}

			protected function send( array $batch ): array {
				return array();
			}

			protected function row_to_object( array $row ): ?array {
				return null;
			}
		};
	}

	/**
	 * An id walk over $ids, $batch_size a page, queuing into $engine.
	 *
	 * @param int[] $ids
	 */
	private function paged_job( array $ids, int $batch_size, AbstractD6Flusher $engine ): AbstractBackfillJob {
		return new class( $this->createMock( IngestQueue::class ), $engine, $ids, $batch_size ) extends AbstractBackfillJob {
			/** @var int[] */
			public array $enqueued = array();
			/** @var int[] */
			private array $ids;
			private int $size;

			/** @param int[] $ids */
			public function __construct( IngestQueue $queue, AbstractD6Flusher $flusher, array $ids, int $size ) {
				parent::__construct( $queue, $flusher );
				$this->ids  = $ids;
				$this->size = $size;
			}

			public function job_type(): string {
				return 'customers';
			}

			protected function batch_size(): int {
				return $this->size;
			}

			protected function count_total(): int {
				return count( $this->ids );
			}

			protected function fetch_ids_after( int $after_id, int $limit ): array {
				$after = array_values(
					array_filter(
						$this->ids,
						static function ( int $id ) use ( $after_id ): bool {
							return $id > $after_id;
						}
					)
				);
				return array_slice( $after, 0, $limit );
			}

			protected function enqueue_record( int $entity_id ): void {
				$this->enqueued[] = $entity_id;
				++$this->flusher->queued;
			}
		};
	}

	/** @return array<string, mixed> */
	private function state( string $status, ?int $started = null ): array {
		return array(
			'id'              => '5',
			'status'          => $status,
			'cursor_value'    => '10',
			'processed_count' => '3',
			'total_count'     => '10',
			'started_at'      => gmdate( 'Y-m-d H:i:s', $started ?? time() ),
		);
	}

	private function job( \Throwable $error ): AbstractBackfillJob {
		$flusher = $this->createMock( AbstractD6Flusher::class );
		$flusher->method( 'sending_allowed' )->willReturn( true );

		return new class( $this->createMock( IngestQueue::class ), $flusher, $error ) extends AbstractBackfillJob {
			private \Throwable $error;

			public function __construct( IngestQueue $queue, AbstractD6Flusher $flusher, \Throwable $error ) {
				parent::__construct( $queue, $flusher );
				$this->error = $error;
			}

			public function job_type(): string {
				return 'products';
			}

			protected function batch_size(): int {
				return 100;
			}

			protected function count_total(): int {
				return 10;
			}

			protected function fetch_ids_after( int $after_id, int $limit ): array {
				throw $this->error;
			}

			protected function enqueue_record( int $entity_id ): void {
			}
		};
	}

	/**
	 * @param array<string, mixed> $state_row
	 */
	private function fake_wpdb( array $state_row ): object {
		return new class( $state_row ) {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $updates = array();
			/** @var array<string, mixed> */
			public array $state;
			/** True: no update matches a row. */
			public bool $refuse = false;

			/** @param array<string, mixed> $row */
			public function __construct( array $row ) {
				$this->state = $row;
			}

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/** @return array<string, mixed> */
			public function get_row( string $sql, string $output = ARRAY_A ): array {
				return $this->state;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 */
			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				if ( $this->refuse || ( isset( $where['status'] ) && $where['status'] !== $this->state['status'] ) ) {
					return 0;
				}
				$this->state = array_merge( $this->state, $data );
				return 1;
			}
		};
	}

	/**
	 * A $wpdb on which every query fails as on a missing column.
	 */
	private function failing_wpdb(): object {
		return new class() {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public bool $fail         = true;

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/** @return false */
			public function query( string $sql ) {
				$this->last_error = $this->fail ? "Unknown column 'sent_count' in 'field list'" : '';
				return false;
			}

			/** @return null */
			public function get_row( string $sql, string $output = ARRAY_A ) {
				$this->query( $sql );
				return null;
			}

			public function get_var( string $sql ): string {
				return '5';
			}
		};
	}
}
