<?php
/**
 * Integration: the queue janitor's retention prune + the migration-006
 * created_at index, against the real MariaDB tables.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\DB\QueueJanitor;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\QueueRowFixture;

/**
 * What FABLE_AUDIT §5/§7#9 risk this guards against:
 *
 *   Both durable queues grow without bound — sent/failed rows were never
 *   pruned, and the rec-queue had no index a created_at range scan could
 *   use, so by the time pruning became urgent it would also be slow.
 *
 *   The invariants pinned here: terminal rows past retention are deleted,
 *   recent terminal rows survive, and PENDING rows are NEVER deleted
 *   regardless of age (they are work, not history — an old parked retry
 *   must outlive any retention window).
 */
final class QueueJanitorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		// EnvScrub clears the schema-version pointer with the smly_% sweep;
		// re-running activation re-applies migrations idempotently (incl. 006).
		Activation::run();
	}

	protected function tearDown(): void {
		as_unschedule_all_actions( QueueJanitor::HOOK, QueueJanitor::FOLLOW_UP_ARGS, QueueJanitor::AS_GROUP );
		parent::tearDown();
	}

	/**
	 * Insert $count rows of one status and age in multi-row INSERTs (CON-335:
	 * a backlog bigger than one 1000-row batch).
	 */
	private function seed_rows( string $table_suffix, string $status, int $age_days, int $count ): void {
		global $wpdb;

		$table      = QueueRowFixture::table( $table_suffix );
		$created_at = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
		$ingest     = IngestQueue::TABLE_SUFFIX === $table_suffix;
		$columns    = $ingest ? '(event_type, entity_id, payload, created_at, status, event_uuid)' : '(event_type, entity_id, payload, created_at, status)';
		$value      = $wpdb->prepare( $ingest ? "(%s, %s, '{}', %s, %s, UUID())" : "(%s, %s, '{}', %s, %s)", 'janitor.bulk', 'e-bulk', $created_at, $status );

		for ( $left = $count; $left > 0; $left -= 500 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- every value is prepared above.
			$wpdb->query( "INSERT INTO {$table} {$columns} VALUES " . implode( ',', array_fill( 0, min( 500, $left ), $value ) ) );
		}
	}

	private function count_rows( string $table_suffix, string $status ): int {
		global $wpdb;
		$table = QueueRowFixture::table( $table_suffix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/** @return array<int, int> The pending follow-up runs. */
	private function pending_follow_ups(): array {
		return (array) as_get_scheduled_actions(
			array(
				'hook'     => QueueJanitor::HOOK,
				'group'    => QueueJanitor::AS_GROUP,
				'status'   => 'pending',
				'args'     => QueueJanitor::FOLLOW_UP_ARGS,
				'per_page' => -1,
			),
			'ids'
		);
	}

	/**
	 * Insert a row with a controlled age into one of the two queue tables.
	 */
	private function seed_row( string $table_suffix, string $status, int $age_days ): int {
		$row = array(
			'event_type' => 'janitor.test',
			'entity_id'  => 'e-' . $status . '-' . $age_days,
			'payload'    => '{}',
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) ),
			'status'     => $status,
		);
		if ( IngestQueue::TABLE_SUFFIX === $table_suffix ) {
			$row['event_uuid'] = wp_generate_uuid4();
		}
		return QueueRowFixture::insert( $table_suffix, $row );
	}

	private function row_exists( string $table_suffix, int $id ): bool {
		return QueueRowFixture::exists( $table_suffix, $id );
	}

	public function test_prunes_expired_terminal_rows_and_keeps_everything_else(): void {
		$cases = array();
		foreach ( array( EventQueue::TABLE_SUFFIX, IngestQueue::TABLE_SUFFIX ) as $suffix ) {
			$cases[] = array( $suffix, $this->seed_row( $suffix, 'sent', 40 ), false, 'sent past 30d retention' );
			$cases[] = array( $suffix, $this->seed_row( $suffix, 'sent', 5 ), true, 'recent sent kept' );
			$cases[] = array( $suffix, $this->seed_row( $suffix, 'failed', 100 ), false, 'failed past 90d retention' );
			$cases[] = array( $suffix, $this->seed_row( $suffix, 'failed', 40 ), true, 'failed inside 90d kept (still retryable evidence)' );
			$cases[] = array( $suffix, $this->seed_row( $suffix, 'pending', 400 ), true, 'pending NEVER pruned, any age' );
		}

		$deleted = ( new QueueJanitor() )->run();

		self::assertSame( 4, $deleted, 'exactly the 2 expired terminal rows per table are deleted' );
		foreach ( $cases as [ $suffix, $id, $should_survive, $why ] ) {
			self::assertSame( $should_survive, $this->row_exists( $suffix, $id ), "{$suffix}: {$why}" );
		}
	}

	public function test_retention_windows_are_filterable(): void {
		$sent_id = $this->seed_row( EventQueue::TABLE_SUFFIX, 'sent', 10 );

		$shorten = static fn (): int => 7;
		add_filter( 'smaily_connect_janitor_sent_retention_days', $shorten );
		try {
			( new QueueJanitor() )->run();
		} finally {
			remove_filter( 'smaily_connect_janitor_sent_retention_days', $shorten );
		}

		self::assertFalse(
			$this->row_exists( EventQueue::TABLE_SUFFIX, $sent_id ),
			'a 10-day-old sent row falls to a filter-shortened 7-day retention'
		);
	}

	public function test_migration_006_added_created_at_index_to_both_queues(): void {
		global $wpdb;
		foreach ( array( EventQueue::TABLE_SUFFIX, IngestQueue::TABLE_SUFFIX ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_created_at'" );
			self::assertNotEmpty( $index, "{$table} has the idx_created_at index" );
		}
	}

	public function test_a_backlog_over_one_batch_drains_in_one_run_and_keeps_pending(): void {
		$this->seed_rows( IngestQueue::TABLE_SUFFIX, 'sent', 40, 2500 );
		$this->seed_rows( EventQueue::TABLE_SUFFIX, 'failed', 100, 1200 );
		$pending = $this->seed_row( IngestQueue::TABLE_SUFFIX, 'pending', 400 );

		$deleted = ( new QueueJanitor() )->run();

		self::assertSame( 3700, $deleted, 'the whole backlog goes in one run, batch after batch' );
		self::assertSame( 0, $this->count_rows( IngestQueue::TABLE_SUFFIX, 'sent' ) );
		self::assertSame( 0, $this->count_rows( EventQueue::TABLE_SUFFIX, 'failed' ) );
		self::assertTrue( $this->row_exists( IngestQueue::TABLE_SUFFIX, $pending ), 'pending NEVER pruned, any age' );
	}

	public function test_a_run_whose_budget_ends_schedules_one_follow_up(): void {
		$this->seed_rows( EventQueue::TABLE_SUFFIX, 'sent', 40, 2500 );

		// The pause between batches jumps the clock past the budget: each
		// run deletes one batch, then stops with rows left.
		$janitor = new class() extends QueueJanitor {
			private float $clock = 0.0;

			protected function now(): float {
				return $this->clock;
			}

			protected function pause( float $seconds ): void {
				$this->clock += 100;
			}
		};

		$janitor->on_tick();
		self::assertSame( 1500, $this->count_rows( EventQueue::TABLE_SUFFIX, 'sent' ) );
		self::assertCount( 1, $this->pending_follow_ups(), 'one follow-up a minute later' );

		$janitor->on_tick();
		self::assertSame( 500, $this->count_rows( EventQueue::TABLE_SUFFIX, 'sent' ) );
		self::assertCount( 1, $this->pending_follow_ups(), 'never a second while one is pending' );
	}
}
