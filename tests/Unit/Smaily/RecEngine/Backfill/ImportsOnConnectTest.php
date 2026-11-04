<?php
/**
 * ImportsOnConnect — connecting starts the catalog, customers and orders
 * imports one after another, and a store connected before CON-247 starts
 * them once after the update. Real WP + WC + Action Scheduler:
 * RecEngineImportsOnConnectTest.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Constants;
use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogImportOnConnect;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;

final class ImportsOnConnectTest extends TestCase {

	/** @var array<string, mixed> wp_options */
	private array $options = array();

	/** @var array<string, array<string, mixed>> state rows by job type */
	private array $rows = array();

	/** @var string[] job types whose start() ran, in order */
	private array $started = array();

	/** @var array<int, array{at: int, hook: string, args: array<string, mixed>}> */
	private array $scheduled = array();

	/** @var array<string, bool> TICK_HOOK pending per job type */
	private array $tick_pending = array();

	/** @var int|bool|false as_next_scheduled_action() for the check hook */
	private $check_next = false;

	private bool $sending_allowed = true;

	/** @var string[] job types whose state row the database cannot read (CON-282) */
	private array $unreadable = array();

	/** True: every start() fails to write its row (CON-282). */
	private bool $start_fails = false;

	/**
	 * HistoryProgress::begin() calls: the first check time and how many parts
	 * had started by then (CON-248). Null = no history double.
	 *
	 * @var array<int, array{at: int, started: int}>|null
	 */
	private ?array $begun = null;

	/** @var (callable(): void)|null runs when a check takes the lock: what an overlapping check did meanwhile */
	private $on_lock = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The update has reached the schema this code needs (CON-282).
		$this->options[ Constants::OPTION_SCHEMA_VERSION ] = Migrator::REQUIRED_VERSION;

		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
		$on_lock = &$this->on_lock;
		Functions\when( 'add_option' )->alias(
			static function ( string $key, $value ) use ( &$options, &$on_lock ): bool {
				if ( $key === 'smly_rec_import_sequence_lock' && $on_lock !== null ) {
					( $on_lock )();
				}
				if ( array_key_exists( $key, $options ) ) {
					return false;
				}
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$options ): bool {
				unset( $options[ $key ] );
				return true;
			}
		);

		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$scheduled = &$this->scheduled;
		$pending   = &$this->tick_pending;
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $at, string $hook, array $args = array() ) use ( &$scheduled, &$pending ): int {
				$scheduled[] = array(
					'at'   => $at,
					'hook' => $hook,
					'args' => $args,
				);
				if ( $hook === BackfillJobInterface::TICK_HOOK ) {
					$pending[ (string) $args['job_type'] ] = true;
				}
				return count( $scheduled );
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( string $hook, ?array $args = null ) use ( &$pending ): bool {
				return $hook === BackfillJobInterface::TICK_HOOK && ! empty( $pending[ (string) ( $args['job_type'] ?? '' ) ] );
			}
		);
		// The pending actions (CON-285): the check that runs now is not among them.
		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $query ) use ( &$scheduled ): array {
				return array_keys(
					array_filter(
						$scheduled,
						static fn ( array $action ): bool => $action['hook'] === $query['hook']
					)
				);
			}
		);
		$check_next = &$this->check_next;
		Functions\when( 'as_next_scheduled_action' )->alias(
			static function () use ( &$check_next ) {
				return $check_next;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_connecting_starts_the_catalog_held_back_and_stores_the_sequence(): void {
		$before = time();

		self::assertTrue( $this->sequence()->start() );

		self::assertSame( array( 'products' ), $this->started );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		$tick = $this->scheduled_for( BackfillJobInterface::TICK_HOOK );
		self::assertCount( 1, $tick );
		self::assertSame( array( 'job_type' => 'products' ), $tick[0]['args'] );
		self::assertGreaterThanOrEqual( $before + CatalogImportOnConnect::DELAY_SECONDS, $tick[0]['at'], 'The catalog keeps its hold-back window.' );
		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'The stall check is armed.' );
	}

	public function test_connecting_while_the_catalog_import_runs_waits_for_it(): void {
		$this->row( 'products', 'running' );
		$this->tick_pending['products'] = true;

		self::assertFalse( $this->sequence()->start() );

		self::assertSame( array(), $this->started, 'The running import is not restarted.' );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_START_DUE, $this->options, 'It runs: nothing to start later.' );
	}

	public function test_each_part_starts_when_the_one_before_it_has_finished(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'running' );
		$this->tick_pending['products'] = true;

		$this->sequence()->advance();
		self::assertSame( array(), $this->started, 'Nothing starts while the catalog import runs.' );

		$this->row( 'products', 'completed' );
		$this->tick_pending['products'] = false;
		$this->sequence()->advance();

		self::assertSame( array( 'customers' ), $this->started );
		self::assertSame( array( 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		$tick = $this->scheduled_for( BackfillJobInterface::TICK_HOOK );
		self::assertSame( array( 'job_type' => 'customers' ), $tick[0]['args'] );
		self::assertLessThanOrEqual( time() + ImportsOnConnect::NEXT_PART_DELAY, $tick[0]['at'] );

		$this->row( 'customers', 'completed' );
		$this->tick_pending['customers'] = false;
		$this->sequence()->advance();

		self::assertSame( array( 'customers', 'orders' ), $this->started );
		self::assertSame( array( 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );

		$this->row( 'orders', 'completed' );
		$this->sequence()->advance();

		self::assertArrayNotHasKey( ImportsOnConnect::OPTION, $this->options, 'The sequence ends after the orders import.' );
		self::assertSame( array( 'customers', 'orders' ), $this->started );
	}

	public function test_a_failed_part_does_not_hold_back_the_next_one(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'failed' );

		$this->sequence()->advance();

		self::assertSame( array( 'customers' ), $this->started );
		self::assertSame( 'failed', $this->rows['products']['status'], 'The failure stays on its card.' );
	}

	public function test_a_stalled_part_does_not_hold_back_the_next_one(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'customers', 'orders' );
		$this->row( 'customers', 'running', time() - AbstractBackfillJob::STALL_GRACE_SECONDS - 1 );

		$this->sequence()->advance();

		self::assertSame( array( 'orders' ), $this->started );
	}

	public function test_a_cancelled_part_ends_the_sequence(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'cancelled' );

		$this->sequence()->advance();

		self::assertSame( array(), $this->started );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION, $this->options );
	}

	public function test_a_refused_account_does_not_move_the_sequence(): void {
		$this->sending_allowed = false;
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'completed' );

		$this->sequence()->run_check();

		self::assertSame( array(), $this->started );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		self::assertSame( array(), $this->scheduled_for( ImportsOnConnect::HOOK ), 'No check is armed while sending is refused.' );
	}

	public function test_a_part_already_running_is_waited_for_not_restarted(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'running' );
		$this->tick_pending['customers'] = true;

		$this->sequence()->advance();

		self::assertSame( array(), $this->started );
		self::assertSame( array( 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
	}

	public function test_a_held_lock_moves_nothing(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->options['smly_rec_import_sequence_lock'] = (string) time();
		$this->row( 'products', 'completed' );

		$this->sequence()->advance();

		self::assertSame( array(), $this->started, 'The tick and the check never start a part twice.' );
	}

	public function test_the_check_re_arms_itself_while_a_sequence_waits(): void {
		$this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ] = '1';
		$this->options[ ImportsOnConnect::OPTION ]                = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'running' );
		$this->tick_pending['products'] = true;
		$this->check_next               = true; // The check that runs now is in progress.

		$this->sequence()->run_check();

		$check = $this->scheduled_for( ImportsOnConnect::HOOK );
		self::assertCount( 1, $check );
		self::assertGreaterThanOrEqual( time() + ImportsOnConnect::CHECK_INTERVAL - 1, $check[0]['at'] );
	}

	public function test_a_check_pending_beside_the_running_one_is_not_doubled(): void {
		$this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ] = '1';
		$this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ]  = '1';
		$this->options[ ImportsOnConnect::OPTION ]                = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'running' );
		$this->tick_pending['products'] = true;
		$this->check_next               = true; // The check that runs now is in progress.
		// A copy already pending beside it.
		$this->scheduled[] = array(
			'at'   => time() + 60,
			'hook' => ImportsOnConnect::HOOK,
			'args' => array(),
		);

		$this->sequence()->run_check();

		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'CON-285: one pending check.' );
	}

	public function test_after_an_update_the_sequence_starts_from_the_first_part_that_never_completed(): void {
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'failed' );

		self::assertTrue( $this->sequence()->start_after_update() );

		self::assertSame( array( 'customers' ), $this->started, 'The completed catalog import is skipped.' );
		self::assertSame( array( 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ] );
	}

	public function test_after_an_update_a_completed_part_in_the_middle_is_skipped(): void {
		$this->row( 'customers', 'completed' );

		self::assertTrue( $this->sequence()->start_after_update() );

		self::assertSame( array( 'products' ), $this->started );
		self::assertSame( array( 'products', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
	}

	public function test_after_an_update_it_starts_once_only(): void {
		$sequence = $this->sequence();
		self::assertTrue( $sequence->start_after_update() );

		ImportsOnConnect::forget();
		$this->started = array();

		self::assertFalse( $sequence->start_after_update() );
		self::assertSame( array(), $this->started );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION, $this->options );
	}

	public function test_after_an_update_a_held_back_catalog_import_starts_nothing(): void {
		// Erkki, 2026-11-02: Hold back promised that nothing is sent.
		$this->row( 'products', 'cancelled' );

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertSame( array(), $this->started );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION, $this->options );
		self::assertSame( 'cancelled', $this->rows['products']['status'], 'The held-back import is left untouched.' );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ], 'Decided once.' );
	}

	public function test_after_an_update_a_cancelled_customers_import_is_skipped_and_the_orders_import_starts(): void {
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'cancelled' );

		self::assertTrue( $this->sequence()->start_after_update() );

		self::assertSame( array( 'orders' ), $this->started );
		self::assertSame( array( 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		self::assertSame( 'cancelled', $this->rows['customers']['status'], 'The cancelled import is left untouched.' );
	}

	public function test_after_an_update_nothing_starts_when_the_only_unfinished_part_was_cancelled(): void {
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'completed' );
		$this->row( 'orders', 'cancelled' );

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertSame( array(), $this->started );
	}

	public function test_after_an_update_a_failed_or_stalled_part_runs_again(): void {
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'running', time() - AbstractBackfillJob::STALL_GRACE_SECONDS - 1 );
		$this->row( 'orders', 'failed' );

		self::assertTrue( $this->sequence()->start_after_update() );

		self::assertSame( array( 'customers' ), $this->started, 'The stalled customers import is started again.' );
		self::assertSame( array( 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ], 'The failed orders import follows.' );
	}

	public function test_after_an_update_nothing_starts_when_customers_and_orders_completed(): void {
		$this->row( 'customers', 'completed' );
		$this->row( 'orders', 'completed' );

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertSame( array(), $this->started );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ], 'Decided once.' );
	}

	public function test_after_an_update_nothing_starts_unless_sending_is_allowed(): void {
		$this->sending_allowed = false;

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertSame( array(), $this->started );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ], 'Decided once.' );
	}

	public function test_after_an_update_a_sequence_a_connection_started_is_left_alone(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'customers', 'orders' );

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertSame( array(), $this->started );
		self::assertSame( array( 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
	}

	public function test_the_first_check_is_armed_only_while_there_is_something_to_do(): void {
		$this->sequence()->ensure_check_scheduled();
		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'The one-time start after an update is still to decide.' );

		$this->scheduled = array();
		$this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ] = '1';
		$this->sequence()->ensure_check_scheduled();
		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'CON-282: the one-time re-run is still to decide.' );

		$this->scheduled = array();
		$this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] = '1';
		$this->sequence()->ensure_check_scheduled();
		self::assertSame( array(), $this->scheduled, 'Nothing to decide and no sequence.' );

		$this->options[ ImportsOnConnect::OPTION ] = array( 'orders' );
		$this->check_next                          = 1234;
		$this->sequence()->ensure_check_scheduled();
		self::assertSame( array(), $this->scheduled, 'A check is already pending.' );

		$this->check_next = false;
		$this->sequence()->ensure_check_scheduled();
		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'A reactivation re-arms the waiting sequence.' );
	}

	public function test_connecting_begins_one_history_import_before_the_catalog_starts(): void {
		$this->begun = array();
		$before      = time();

		$this->sequence()->start();

		self::assertCount( 1, $this->begun, 'One import id for the whole sequence (CON-248).' );
		self::assertSame( 0, $this->begun[0]['started'], 'Begun before any part starts.' );
		self::assertGreaterThanOrEqual( $before + CatalogImportOnConnect::DELAY_SECONDS, $this->begun[0]['at'], 'The first report waits for the hold-back window.' );
	}

	public function test_after_an_update_a_sequence_with_the_orders_begins_a_history_import(): void {
		$this->begun = array();
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'completed' );

		self::assertTrue( $this->sequence()->start_after_update() );

		self::assertSame( array( 'orders' ), $this->started );
		self::assertCount( 1, $this->begun );
		self::assertSame( 0, $this->begun[0]['started'], 'Begun before the orders import starts.' );
	}

	public function test_after_an_update_a_sequence_without_the_orders_begins_no_history_import(): void {
		$this->begun = array();
		$this->row( 'orders', 'completed' );

		self::assertTrue( $this->sequence()->start_after_update() );

		self::assertSame( array( 'products' ), $this->started );
		self::assertSame( array(), $this->begun, 'No order history is sent again.' );
	}

	// --- helpers -----------------------------------------------------------

	// --- CON-282 ---------------------------------------------------------------

	public function test_an_unreadable_part_is_not_taken_as_ended(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->unreadable                          = array( 'products' );

		$this->sequence()->advance();

		self::assertSame( array(), $this->started );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ], 'Nothing moves, nothing is forgotten.' );
	}

	public function test_a_part_that_cannot_start_keeps_the_sequence_and_schedules_no_batch(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->row( 'products', 'completed' );
		$this->start_fails = true;

		$this->sequence()->advance();

		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ], 'The next check tries again.' );
		self::assertSame( array(), $this->scheduled_for( BackfillJobInterface::TICK_HOOK ) );

		$this->start_fails = false;
		$this->sequence()->advance();
		self::assertSame( array( 'customers' ), $this->started );
	}

	public function test_a_catalog_import_that_cannot_start_schedules_no_batch(): void {
		$this->start_fails = true;

		self::assertFalse( $this->sequence()->start() );

		self::assertSame( array(), $this->scheduled_for( BackfillJobInterface::TICK_HOOK ) );
	}

	public function test_a_catalog_import_that_cannot_start_on_connect_is_started_by_a_later_check(): void {
		// An earlier run's row: not this sequence's catalog import.
		$this->row( 'products', 'completed', time() - DAY_IN_SECONDS );
		$this->start_fails = true;

		self::assertFalse( $this->sequence()->start() );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_START_DUE ] );
		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'A later check comes.' );

		$this->sequence()->advance();
		self::assertSame( array(), $this->started, 'Still failing: it waits, the customers import does not start.' );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );

		$this->start_fails = false;
		$before            = time();
		$this->sequence()->advance();

		self::assertSame( array( 'products' ), $this->started, 'CON-292: the catalog import is started, not skipped.' );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_START_DUE, $this->options );
		$tick = $this->scheduled_for( BackfillJobInterface::TICK_HOOK );
		self::assertCount( 1, $tick );
		self::assertGreaterThanOrEqual( $before + CatalogImportOnConnect::DELAY_SECONDS, $tick[0]['at'], 'The catalog keeps its hold-back window.' );

		// Once it has ended, the sequence moves on as before.
		$this->row( 'products', 'completed' );
		$this->tick_pending['products'] = false;
		$this->sequence()->advance();
		self::assertSame( array( 'products', 'customers' ), $this->started );
	}

	public function test_after_an_update_an_unreadable_part_decides_nothing_yet(): void {
		$this->unreadable = array( 'orders' );

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertSame( array(), $this->started );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_UPDATE_CHECKED, $this->options, 'The next check decides.' );
	}

	public function test_after_an_update_a_part_that_cannot_start_is_decided_again(): void {
		$this->start_fails = true;

		self::assertFalse( $this->sequence()->start_after_update() );

		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_UPDATE_CHECKED, $this->options );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION, $this->options );
		self::assertSame( array(), $this->scheduled_for( BackfillJobInterface::TICK_HOOK ) );
	}

	public function test_before_the_schema_is_ready_the_check_decides_and_moves_nothing(): void {
		$this->options[ Constants::OPTION_SCHEMA_VERSION ] = Migrator::REQUIRED_VERSION - 1;
		$this->options[ ImportsOnConnect::OPTION ]         = array( 'customers', 'orders' );
		$this->row( 'customers', 'completed' );

		$this->sequence()->run_check();

		self::assertSame( array(), $this->started );
		self::assertSame( array( 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ], 'Not forgotten.' );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_UPDATE_CHECKED, $this->options, 'The after-update start is not spent.' );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_RERUN_CHECKED, $this->options );
		self::assertCount( 1, $this->scheduled_for( ImportsOnConnect::HOOK ), 'The check comes back.' );
	}

	public function test_a_store_3_21_0_left_without_a_finished_history_import_gets_the_sequence_once_more(): void {
		$this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ] = '1';
		// The pet store: 3.20 runs, completed before the sent_count column.
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'completed' );
		$this->row( 'orders', 'completed' );
		$this->begun = array();

		$this->sequence()->run_check();

		self::assertSame( array( 'products' ), $this->started );
		self::assertSame( array( 'products', 'customers', 'orders' ), $this->options[ ImportsOnConnect::OPTION ] );
		self::assertCount( 1, $this->begun, 'One history import for the re-run.' );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] );

		// The sequence runs to its end; later checks start nothing again.
		unset( $this->options[ ImportsOnConnect::OPTION ] );
		$this->row( 'products', 'completed' );
		$this->sequence()->run_check();
		self::assertSame( array( 'products' ), $this->started, 'Once only.' );
	}

	public function test_a_store_whose_history_import_completed_is_not_re_run(): void {
		$this->options[ ImportsOnConnect::OPTION_UPDATE_CHECKED ] = '1';
		$this->row( 'products', 'completed' );
		$this->row( 'customers', 'completed' );
		$this->row( 'orders', 'completed' );
		$this->rows['orders']['sent_count'] = '120';

		self::assertFalse( $this->sequence()->rerun_once( true ) );

		self::assertSame( array(), $this->started );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] );
	}

	public function test_the_re_run_is_only_for_a_store_3_21_0_ran_on_that_can_send(): void {
		self::assertFalse( $this->sequence()->rerun_once( false ), 'The after-update start of this release decides.' );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] );

		unset( $this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] );
		$this->sending_allowed = false;
		self::assertFalse( $this->sequence()->rerun_once( true ), 'Not connected, or refused.' );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] );
		self::assertSame( array(), $this->started );
	}

	public function test_the_re_run_respects_a_cancelled_part(): void {
		$this->row( 'products', 'cancelled' );

		self::assertFalse( $this->sequence()->rerun_once( true ) );

		self::assertSame( array(), $this->started );
		self::assertSame( '1', $this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] );
	}

	public function test_the_re_run_waits_while_an_import_runs_or_a_sequence_waits(): void {
		$this->row( 'orders', 'running' );
		$this->tick_pending['orders'] = true;

		self::assertFalse( $this->sequence()->rerun_once( true ), 'A merchant\'s Import now: no second chain beside it.' );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_RERUN_CHECKED, $this->options, 'Decided once it has ended.' );

		$this->row( 'orders', 'failed' );
		$this->options[ ImportsOnConnect::OPTION ] = array( 'orders' );
		self::assertFalse( $this->sequence()->rerun_once( true ) );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_RERUN_CHECKED, $this->options );

		unset( $this->options[ ImportsOnConnect::OPTION ] );
		$this->unreadable = array( 'customers' );
		self::assertFalse( $this->sequence()->rerun_once( true ) );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_RERUN_CHECKED, $this->options );
		self::assertSame( array(), $this->started );
	}

	public function test_two_overlapping_checks_begin_one_history_import(): void {
		$this->begun = array();
		// The other check started the re-run while this one waited for the lock.
		$this->on_lock = function (): void {
			$this->options[ ImportsOnConnect::OPTION_RERUN_CHECKED ] = '1';
		};

		self::assertFalse( $this->sequence()->rerun_once( true ) );

		self::assertSame( array(), $this->begun, 'CON-292: no second import id.' );
		self::assertSame( array(), $this->started );
	}

	public function test_a_re_run_that_cannot_start_is_decided_again(): void {
		$this->start_fails = true;

		self::assertFalse( $this->sequence()->rerun_once( true ) );

		self::assertArrayNotHasKey( ImportsOnConnect::OPTION_RERUN_CHECKED, $this->options, 'The next check decides.' );
		self::assertArrayNotHasKey( ImportsOnConnect::OPTION, $this->options );
	}

	private function sequence(): ImportsOnConnect {
		$settings = $this->createMock( RecEngineSettings::class );
		$settings->method( 'sending_allowed' )->willReturnCallback( fn (): bool => $this->sending_allowed );

		$history = null;
		if ( $this->begun !== null ) {
			$history = $this->createMock( HistoryProgress::class );
			$history->method( 'begin' )->willReturnCallback(
				function ( int $at ): string {
					$this->begun[] = array(
						'at'      => $at,
						'started' => count( $this->started ),
					);
					return 'woo-test';
				}
			);
		}

		return new ImportsOnConnect(
			$settings,
			fn ( string $job_type ): AbstractBackfillJob => $this->job( $job_type ),
			fn ( string $job_type ): array|false|null => in_array( $job_type, $this->unreadable, true ) ? false : ( $this->rows[ $job_type ] ?? null ),
			$history
		);
	}

	private function job( string $job_type ): AbstractBackfillJob {
		$job = $this->createMock( AbstractBackfillJob::class );
		$job->method( 'job_type' )->willReturn( $job_type );
		$job->method( 'is_running' )->willReturnCallback(
			fn (): bool => ( $this->rows[ $job_type ]['status'] ?? '' ) === BackfillJobInterface::STATUS_RUNNING
		);
		$job->method( 'start' )->willReturnCallback(
			function () use ( $job_type ): int {
				if ( $this->start_fails ) {
					return 0;
				}
				$this->started[] = $job_type;
				$this->row( $job_type, 'running' );
				return 1;
			}
		);

		return $job;
	}

	private function row( string $job_type, string $status, ?int $started = null ): void {
		$this->rows[ $job_type ] = array(
			'id'         => '1',
			'status'     => $status,
			'started_at' => gmdate( 'Y-m-d H:i:s', $started ?? time() ),
		);
	}

	/**
	 * @return array<int, array{at: int, hook: string, args: array<string, mixed>}>
	 */
	private function scheduled_for( string $hook ): array {
		return array_values(
			array_filter(
				$this->scheduled,
				static function ( array $action ) use ( $hook ): bool {
					return $action['hook'] === $hook;
				}
			)
		);
	}
}
