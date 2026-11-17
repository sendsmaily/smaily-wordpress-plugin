<?php
/**
 * QueueJanitor (CON-335) — a run drains a backlog in small batches until it
 * is gone or the run's time budget ends, schedules ONE follow-up when rows
 * are left, never deletes a pending row, and a failed DELETE stops that
 * status quietly with a debug line. On a fake clock and a recording $wpdb.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\DB;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\DB\QueueJanitor;

final class QueueJanitorTest extends TestCase {

	private const EVENT_QUEUE  = 'wp_smly_plus_event_queue';
	private const INGEST_QUEUE = 'wp_smly_rec_event_queue';

	/** @var object{clock: float} */
	private object $time;

	/** @var object */
	private object $wpdb;

	/** @var array<int, array<int, mixed>> Every as_schedule_single_action() call. */
	private array $scheduled = array();

	/** @var array<int, int> What the pending-action lookup answers. */
	private array $pending_follow_ups = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->time = (object) array( 'clock' => 0.0 );

		$time       = $this->time;
		$this->wpdb = new class( $time ) {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			/** @var array<string, array<string, int>> Rows past retention: table => status => count. */
			public array $rows = array();
			/** @var array<int, string> Every DELETE statement. */
			public array $deletes = array();
			/** @var array<string, bool> "table|status" => whether its DELETE fails. */
			public array $fail = array();
			/** Seconds one DELETE moves the clock on. */
			public float $delete_seconds = 0.1;
			/** @var object{clock: float} */
			private object $time;

			public function __construct( object $time ) {
				$this->time = $time;
			}

			/**
			 * @return int|false
			 */
			public function query( string $sql ) {
				if ( ! preg_match( "/^DELETE FROM (\\w+) WHERE status = '(\\w+)' AND created_at < '[^']+' LIMIT (\\d+)$/", $sql, $m ) ) {
					return 0;
				}
				$this->deletes[]     = $sql;
				$this->time->clock  += $this->delete_seconds;
				[ , $table, $status, $limit ] = $m;
				if ( isset( $this->fail[ $table . '|' . $status ] ) ) {
					$this->last_error = 'Lock wait timeout for customer@example.com';
					return false;
				}
				$left = $this->rows[ $table ][ $status ] ?? 0;
				$gone = min( $left, (int) $limit );

				$this->rows[ $table ][ $status ] = $left - $gone;
				return $gone;
			}

			/** No Action Scheduler tables: the history pass is a no-op. */
			public function get_var( string $sql ): ?string {
				return null;
			}

			/**
			 * @param mixed ...$args
			 */
			public function prepare( string $query, ...$args ): string {
				if ( count( $args ) === 1 && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			/** @return array<int, string> The DELETE statements for one table and status. */
			public function deletes_of( string $table, string $status ): array {
				return array_values(
					array_filter(
						$this->deletes,
						static function ( string $sql ) use ( $table, $status ): bool {
							return strpos( $sql, "DELETE FROM {$table} WHERE status = '{$status}'" ) === 0;
						}
					)
				);
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->scheduled          = array();
		$this->pending_follow_ups = array();
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query ): array {
				self::assertSame( QueueJanitor::HOOK, $query['hook'] );
				self::assertSame( QueueJanitor::AS_GROUP, $query['group'] );
				self::assertSame( 'pending', $query['status'], 'only a pending follow-up counts (CON-285)' );
				self::assertSame( QueueJanitor::FOLLOW_UP_ARGS, $query['args'], 'the follow-up, never the recurring daily run' );
				return $this->pending_follow_ups;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ): int {
				$this->scheduled[] = $args;
				return 1;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function janitor(): QueueJanitor {
		$time = $this->time;

		return new class( $time ) extends QueueJanitor {
			/** @var object{clock: float} */
			private object $time;

			/** @var array<int, float> */
			public array $pauses = array();

			public function __construct( object $time ) {
				$this->time = $time;
			}

			protected function now(): float {
				return $this->time->clock;
			}

			protected function pause( float $seconds ): void {
				$this->pauses[]     = $seconds;
				$this->time->clock += $seconds;
			}
		};
	}

	public function test_a_backlog_larger_than_one_batch_drains_to_empty_within_the_budget(): void {
		$this->wpdb->rows = array(
			self::EVENT_QUEUE  => array(
				'sent'   => 1200,
				'failed' => 0,
			),
			self::INGEST_QUEUE => array(
				'sent'   => 3500,
				'failed' => 2000,
			),
		);

		$deleted = $this->janitor()->run();

		self::assertSame( 6700, $deleted );
		self::assertSame( 0, $this->wpdb->rows[ self::INGEST_QUEUE ]['sent'] );
		self::assertSame( 0, $this->wpdb->rows[ self::INGEST_QUEUE ]['failed'] );
		self::assertSame( 0, $this->wpdb->rows[ self::EVENT_QUEUE ]['sent'] );
		self::assertCount( 4, $this->wpdb->deletes_of( self::INGEST_QUEUE, 'sent' ), '1000 + 1000 + 1000 + 500' );
		self::assertCount( 3, $this->wpdb->deletes_of( self::INGEST_QUEUE, 'failed' ), 'a full last batch is followed by one that finds nothing' );
		foreach ( $this->wpdb->deletes as $sql ) {
			self::assertStringEndsWith( 'LIMIT 1000', $sql, 'small batches only' );
		}
	}

	public function test_a_run_that_finishes_schedules_no_follow_up(): void {
		$this->wpdb->rows = array( self::INGEST_QUEUE => array( 'sent' => 2500 ) );

		$this->janitor()->on_tick();

		self::assertSame( 0, $this->wpdb->rows[ self::INGEST_QUEUE ]['sent'] );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_batches_pause_between_each_other(): void {
		$this->wpdb->rows = array( self::INGEST_QUEUE => array( 'sent' => 2500 ) );
		$janitor          = $this->janitor();

		$janitor->run();

		// Three DELETEs on the ingest sent rows: a pause before the 2nd and 3rd.
		self::assertSame( array( 0.05, 0.05 ), $janitor->pauses );
	}

	public function test_a_run_whose_budget_ends_with_rows_left_schedules_one_follow_up(): void {
		$this->wpdb->rows           = array(
			self::EVENT_QUEUE  => array( 'sent' => 100000 ),
			self::INGEST_QUEUE => array( 'sent' => 100000 ),
		);
		$this->wpdb->delete_seconds = 5.0;

		$this->janitor()->on_tick();

		// 0 s, 5.05 s, 10.1 s, 15.15 s: four batches start inside the 20 s
		// budget, then no more — on either table.
		self::assertCount( 4, $this->wpdb->deletes, 'no batch starts after the budget' );
		self::assertSame( 96000, $this->wpdb->rows[ self::EVENT_QUEUE ]['sent'] );
		self::assertSame( 100000, $this->wpdb->rows[ self::INGEST_QUEUE ]['sent'] );

		self::assertCount( 1, $this->scheduled, 'exactly one follow-up' );
		[ $timestamp, $hook, $args, $group ] = $this->scheduled[0];
		self::assertEqualsWithDelta( time() + QueueJanitor::FOLLOW_UP_DELAY_SECONDS, $timestamp, 5 );
		self::assertSame( QueueJanitor::HOOK, $hook );
		self::assertSame( QueueJanitor::FOLLOW_UP_ARGS, $args );
		self::assertSame( QueueJanitor::AS_GROUP, $group );
	}

	public function test_no_second_follow_up_while_one_is_pending(): void {
		$this->wpdb->rows           = array( self::INGEST_QUEUE => array( 'sent' => 100000 ) );
		$this->wpdb->delete_seconds = 5.0;
		$this->pending_follow_ups   = array( 42 );

		$this->janitor()->on_tick();

		self::assertSame( array(), $this->scheduled );
	}

	public function test_the_safety_cap_ends_a_run_whose_clock_does_not_move(): void {
		$this->wpdb->rows           = array( self::INGEST_QUEUE => array( 'sent' => 10000000 ) );
		$this->wpdb->delete_seconds = 0.0;
		$janitor                    = new class() extends QueueJanitor {
			protected function now(): float {
				return 0.0;
			}

			protected function pause( float $seconds ): void {
			}
		};

		$janitor->on_tick();

		self::assertCount( 500, $this->wpdb->deletes_of( self::INGEST_QUEUE, 'sent' ) );
		self::assertCount( 1, $this->scheduled, 'rows are left: one follow-up' );
	}

	public function test_pending_rows_are_never_deleted(): void {
		$this->wpdb->rows = array(
			self::EVENT_QUEUE  => array(
				'pending' => 5000,
				'sent'    => 1500,
			),
			self::INGEST_QUEUE => array(
				'pending' => 5000,
				'failed'  => 1500,
			),
		);

		$this->janitor()->on_tick();

		self::assertSame( 5000, $this->wpdb->rows[ self::EVENT_QUEUE ]['pending'] );
		self::assertSame( 5000, $this->wpdb->rows[ self::INGEST_QUEUE ]['pending'] );
		self::assertNotEmpty( $this->wpdb->deletes );
		foreach ( $this->wpdb->deletes as $sql ) {
			self::assertMatchesRegularExpression( "/ WHERE status = '(sent|failed)' /", $sql );
		}
	}

	/**
	 * The failing status stops after its one DELETE; the others go on; no
	 * follow-up (the next daily run tries again); the debug line names the
	 * code and where — never the database's error text (CON-277).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_delete_stops_that_status_quietly_with_a_debug_line(): void {
		define( 'WP_DEBUG', true );
		$this->wpdb->rows                                  = array(
			self::INGEST_QUEUE => array(
				'sent'   => 5000,
				'failed' => 1500,
			),
		);
		$this->wpdb->fail[ self::INGEST_QUEUE . '|sent' ] = true;

		$log      = (string) tempnam( sys_get_temp_dir(), 'smly-log' );
		$previous = (string) ini_set( 'error_log', $log );
		try {
			$this->janitor()->on_tick();
			$written = (string) file_get_contents( $log );
		} finally {
			ini_set( 'error_log', $previous );
			unlink( $log );
		}

		self::assertCount( 1, $this->wpdb->deletes_of( self::INGEST_QUEUE, 'sent' ), 'the failing status stops at once' );
		self::assertSame( 5000, $this->wpdb->rows[ self::INGEST_QUEUE ]['sent'] );
		self::assertSame( 0, $this->wpdb->rows[ self::INGEST_QUEUE ]['failed'], 'the other status still drains' );
		self::assertSame( array(), $this->scheduled, 'an error schedules no follow-up' );
		self::assertStringContainsString( '[smaily-connect queue-janitor] delete_failed table=smly_rec_event_queue status=sent', $written );
		self::assertStringNotContainsString( 'customer@example.com', $written );
		self::assertStringNotContainsString( 'Lock wait', $written );
	}
}
