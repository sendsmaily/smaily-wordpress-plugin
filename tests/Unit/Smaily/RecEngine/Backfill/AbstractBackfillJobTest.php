<?php
/**
 * AbstractBackfillJob — a batch that hits an unexpected error stops the
 * import (PRO-3890), and a running import nothing drives counts as stalled
 * (PRO-3886). Real WP + WC + Action Scheduler: RecEngineImportStopTest.
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
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

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
			private array $state;

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
				return 1;
			}
		};
	}
}
