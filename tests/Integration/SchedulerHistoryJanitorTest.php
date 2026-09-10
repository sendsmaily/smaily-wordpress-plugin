<?php
/**
 * Integration: the janitor's prune of the plugin's own finished Action
 * Scheduler rows, against the real Action Scheduler tables.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\DB\QueueJanitor;

/**
 * What PRO-2438 risk this guards against:
 *
 *   Action Scheduler's own cleaner never purges `failed` actions, and this
 *   plugin is the store's heaviest scheduler producer — the pilot store had
 *   466 148 action rows, thousands of them failed abandoned-cart actions
 *   from three months earlier, each carrying its own log rows.
 *
 *   The invariants pinned here: our finished actions past the retention
 *   window go, with their logs; `pending` / `in-progress` / recently
 *   finished rows stay; another plugin's rows are never touched; a run is
 *   bounded and leaves the remainder for the next tick; and a store without
 *   the scheduler tables is a silent no-op.
 */
final class SchedulerHistoryJanitorTest extends TestCase {

	private const OUR_HOOK     = 'smly_plus_janitor_fixture';
	private const OUR_REC_HOOK = 'smly_rec_janitor_fixture';
	private const FOREIGN_HOOK = 'other_plugin_janitor_fixture';

	/** @var int[] */
	private array $seeded = array();

	protected function setUp(): void {
		parent::setUp();
		// Normalise: any of OUR finished-and-expired rows another test (or an
		// earlier suite run) left behind would otherwise count towards the
		// bounded-run assertions below.
		( new QueueJanitor() )->prune_scheduler_history();
	}

	protected function tearDown(): void {
		global $wpdb;

		foreach ( $this->seeded as $action_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->logs_table()} WHERE action_id = %d", $action_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->actions_table()} WHERE action_id = %d", $action_id ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$this->seeded = array();

		parent::tearDown();
	}

	private function actions_table(): string {
		global $wpdb;
		return $wpdb->prefix . QueueJanitor::AS_ACTIONS_TABLE;
	}

	private function logs_table(): string {
		global $wpdb;
		return $wpdb->prefix . QueueJanitor::AS_LOGS_TABLE;
	}

	/**
	 * Plant an action row with a controlled hook, status and age.
	 */
	private function seed_action( string $hook, string $status, int $age_days ): int {
		global $wpdb;

		$date = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$this->actions_table(),
			array(
				'hook'                 => $hook,
				'status'               => $status,
				'scheduled_date_gmt'   => $date,
				'scheduled_date_local' => $date,
				'args'                 => '[]',
				'schedule'             => '',
				'group_id'             => 0,
				'attempts'             => 1,
				'last_attempt_gmt'     => $date,
				'last_attempt_local'   => $date,
				'claim_id'             => 0,
			)
		);

		$action_id      = (int) $wpdb->insert_id;
		$this->seeded[] = $action_id;

		return $action_id;
	}

	private function seed_log( int $action_id ): void {
		global $wpdb;

		$wpdb->insert(
			$this->logs_table(),
			array(
				'action_id'      => $action_id,
				'message'        => 'janitor fixture',
				'log_date_gmt'   => gmdate( 'Y-m-d H:i:s' ),
				'log_date_local' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	private function action_exists( int $action_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT action_id FROM {$this->actions_table()} WHERE action_id = %d", $action_id ) );
	}

	private function log_count( int $action_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->logs_table()} WHERE action_id = %d", $action_id ) );
	}

	public function test_prunes_our_expired_finished_actions_with_their_logs(): void {
		$expired = array(
			'complete' => $this->seed_action( self::OUR_HOOK, 'complete', 30 ),
			'failed'   => $this->seed_action( self::OUR_HOOK, 'failed', 90 ),
			'canceled' => $this->seed_action( self::OUR_REC_HOOK, 'canceled', 30 ),
		);
		$kept    = array(
			'pending past retention'           => $this->seed_action( self::OUR_HOOK, 'pending', 30 ),
			'in-progress past retention'       => $this->seed_action( self::OUR_HOOK, 'in-progress', 30 ),
			'failed inside the window'         => $this->seed_action( self::OUR_HOOK, 'failed', 3 ),
			'complete inside the window'       => $this->seed_action( self::OUR_REC_HOOK, 'complete', 6 ),
			'another plugin, expired + failed' => $this->seed_action( self::FOREIGN_HOOK, 'failed', 90 ),
		);
		foreach ( array_merge( $expired, $kept ) as $action_id ) {
			$this->seed_log( $action_id );
			$this->seed_log( $action_id );
		}

		$deleted = ( new QueueJanitor() )->prune_scheduler_history();

		self::assertSame( 3, $deleted, 'exactly our three expired finished actions are deleted' );
		foreach ( $expired as $status => $action_id ) {
			self::assertFalse( $this->action_exists( $action_id ), "expired {$status} action pruned" );
			self::assertSame( 0, $this->log_count( $action_id ), "log rows of the pruned {$status} action pruned" );
		}
		foreach ( $kept as $why => $action_id ) {
			self::assertTrue( $this->action_exists( $action_id ), "kept: {$why}" );
			self::assertSame( 2, $this->log_count( $action_id ), "logs kept: {$why}" );
		}
	}

	public function test_a_run_stops_at_its_ceiling_and_the_rest_goes_next_run(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->seed_action( self::OUR_HOOK, 'failed', 30 );
		}

		// Ceiling of two rows per run (2 rows/statement x 1 statement).
		$janitor = new class() extends QueueJanitor {
			protected function scheduler_batch_size(): int {
				return 2;
			}
			protected function scheduler_max_batches(): int {
				return 1;
			}
		};

		self::assertSame( 2, $janitor->prune_scheduler_history(), 'the first run deletes at most its ceiling' );
		self::assertSame( 1, $janitor->prune_scheduler_history(), 'the next run takes the remainder' );
		self::assertSame( 0, $janitor->prune_scheduler_history(), 'and then there is nothing left to do' );
	}

	public function test_a_store_without_the_scheduler_tables_is_a_no_op(): void {
		$action_id = $this->seed_action( self::OUR_HOOK, 'failed', 30 );

		$janitor = new class() extends QueueJanitor {
			protected function scheduler_table( string $table ): string {
				return 'smly_no_such_' . $table;
			}
		};

		self::assertSame( 0, $janitor->prune_scheduler_history(), 'missing tables prune nothing and raise nothing' );
		self::assertTrue( $this->action_exists( $action_id ), 'and nothing else was touched either' );
	}
}
