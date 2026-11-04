<?php
/**
 * HistoryProgress — the §25 history progress report (CON-248): the phases,
 * the accepted counts, the 5-minute throttle, a failed report retried 5
 * minutes later, and when the import ends. Real WP + WC + Action Scheduler
 * against the mock engine: RecEngineHistoryProgressTest.
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
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Backfill\HistoryProgress;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportsOnConnect;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class HistoryProgressTest extends TestCase {

	private const NOW = 1793620800; // 2026-11-02 12:00:00 UTC.

	/** @var array<string, mixed> wp_options */
	private array $options = array();

	/** @var array<string, array<string, mixed>> state rows by job type */
	private array $rows = array();

	/** @var array<int, array<string, mixed>> the §25 bodies the engine received */
	private array $sent = array();

	/** @var array<int, array{at: int, hook: string}> */
	private array $scheduled = array();

	private ?\Throwable $engine_error = null;

	private bool $sending_allowed = true;

	/** @var string[] job types whose state row the database cannot read (CON-282) */
	private array $unreadable = array();

	private int $now = self::NOW;

	/** Thrown by every state-row read: a check that fails (CON-284). */
	private ?\Throwable $reader_error = null;

	/** @var array<int, array{types: string[], created_by: ?string, max_id: ?int}> queue rows that still wait */
	private array $waiting = array();

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
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$options ): bool {
				unset( $options[ $key ] );
				return true;
			}
		);

		$scheduled = &$this->scheduled;
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $at, string $hook ) use ( &$scheduled ): int {
				$scheduled[] = array(
					'at'   => $at,
					'hook' => $hook,
				);
				return count( $scheduled );
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function () use ( &$scheduled ): void {
				$scheduled = array();
			}
		);
		// The pending checks (CON-285): the one that runs now is not among them.
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_begin_stores_a_new_import_with_a_contract_safe_id_and_arms_its_first_check(): void {
		$this->scheduled = array(
			array(
				'at'   => 1,
				'hook' => HistoryProgress::HOOK,
			),
		);

		$id = $this->progress()->begin( self::NOW + 180 );

		self::assertMatchesRegularExpression( '/^[A-Za-z0-9._:-]{1,128}$/', $id, '§25: letters, digits and . _ : - only.' );
		self::assertStringStartsWith( 'woo-20261102T120000Z-', $id );
		self::assertNotSame( $id, $this->progress()->begin( self::NOW + 180 ), 'Every import has its own id.' );
		$state = $this->options[ HistoryProgress::OPTION ];
		self::assertSame( '2026-11-02 12:00:00', $state['begun_at'] );
		self::assertSame( '2021-11-02 12:00:00', $state['horizon'], 'The stored config.history: 60 months.' );
		self::assertSame(
			array(
				array(
					'at'   => self::NOW + 180,
					'hook' => HistoryProgress::HOOK,
				),
			),
			$this->scheduled,
			'The queued check is replaced by the new import\'s first one.'
		);
	}

	public function test_started_goes_out_before_the_orders_part_with_counts_only(): void {
		$this->begin_with_sequence();
		$this->row( 'products', 'running', array( 'sent_count' => '40', 'processed_count' => '25', 'total_count' => '30' ) );

		$this->progress()->run();

		self::assertCount( 1, $this->sent );
		self::assertSame(
			array(
				'import_id'     => $this->options[ HistoryProgress::OPTION ]['import_id'],
				'phase'         => 'started',
				'horizon_start' => '2021-11-02',
				'entities'      => array(
					'catalog'   => array(
						'expected' => null,
						'sent'     => 40,
					),
					'customers' => array(
						'expected' => null,
						'sent'     => 0,
					),
					'orders'    => array(
						'expected'       => null,
						'sent'           => 0,
						'oldest_sent_at' => null,
					),
					'buyers'    => array( 'expected' => 77 ),
				),
			),
			$this->sent[0]
		);
		self::assertSame( array( HistoryProgress::HOOK ), array_column( $this->scheduled, 'hook' ), 'The next check is armed.' );
		self::assertSame( self::NOW + HistoryProgress::CHECK_EVERY_SECONDS, $this->scheduled[0]['at'] );
	}

	public function test_a_run_from_before_the_accepted_count_reports_the_rows_it_read(): void {
		$this->begin_with_sequence();
		$this->row( 'customers', 'completed', array( 'sent_count' => null, 'processed_count' => '12', 'total_count' => '15' ) );

		$this->progress()->run();

		self::assertSame(
			array(
				'expected' => 15,
				'sent'     => 12,
			),
			$this->sent[0]['entities']['customers']
		);
	}

	public function test_counts_go_out_at_most_every_five_minutes_within_a_phase(): void {
		$this->begin_with_sequence();
		$this->progress()->run();

		$this->now = self::NOW + HistoryProgress::REPORT_EVERY_SECONDS - 1;
		$this->progress()->run();
		self::assertCount( 1, $this->sent, 'Same phase, under five minutes: nothing sent.' );

		$this->now = self::NOW + HistoryProgress::REPORT_EVERY_SECONDS;
		$this->progress()->run();
		self::assertCount( 2, $this->sent );
		self::assertSame( 'started', $this->sent[1]['phase'] );
	}

	public function test_the_start_part_is_reported_at_once_when_its_rows_are_in(): void {
		$this->begin_with_sequence();
		$this->progress()->run();
		$this->orders_running( 410, 25 );
		$this->row( 'products', 'completed', array( 'completed_at' => '2026-11-02 12:01:00' ) );
		$this->row( 'customers', 'completed', array( 'completed_at' => '2026-11-02 12:02:00' ) );

		$this->now = self::NOW + 60;
		$this->progress()->run();

		self::assertCount( 2, $this->sent, 'A phase change does not wait for the five minutes.' );
		self::assertSame( 'start_part_complete', $this->sent[1]['phase'] );
		self::assertSame(
			array(
				'expected'       => 4000,
				'sent'           => 1500,
				'oldest_sent_at' => '2025-05-01',
			),
			$this->sent[1]['entities']['orders']
		);
		self::assertSame( '2021-11-02', $this->sent[1]['horizon_start'], 'The orders run\'s window.' );
	}

	public function test_the_start_part_waits_while_one_of_its_order_rows_waits(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->waiting[] = array(
			'types'      => array( 'order.upsert' ),
			'created_by' => null,
			'max_id'     => 410,
		);

		$this->progress()->run();

		self::assertSame( 'started', $this->sent[0]['phase'] );
	}

	public function test_the_start_part_waits_while_the_customers_import_runs_or_its_rows_wait(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->row( 'customers', 'running' );

		$this->progress()->run();
		self::assertSame( 'started', $this->sent[0]['phase'] );

		$this->row( 'customers', 'completed', array( 'completed_at' => '2026-11-02 12:02:00' ) );
		$this->waiting[] = array(
			'types'      => array( 'customer.upsert' ),
			'created_by' => '2026-11-02 12:02:00',
			'max_id'     => null,
		);
		$this->now = self::NOW + HistoryProgress::REPORT_EVERY_SECONDS;
		$this->progress()->run();
		self::assertSame( 'started', $this->sent[1]['phase'], 'A customer row queued by the import still waits.' );
	}

	public function test_complete_goes_out_once_and_ends_the_checks(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->rows['orders']['status']       = 'completed';
		$this->rows['orders']['completed_at'] = '2026-11-02 13:00:00';
		$this->rows['orders']['sent_count']   = '3990';

		$this->progress()->run();

		self::assertSame( 'complete', $this->sent[0]['phase'] );
		self::assertSame( 3990, $this->sent[0]['entities']['orders']['sent'] );
		self::assertSame( array(), $this->scheduled, 'No next check once complete is in.' );

		$this->now = self::NOW + DAY_IN_SECONDS;
		$this->progress()->run();
		$this->progress()->ensure_scheduled();
		self::assertCount( 1, $this->sent, 'Nothing more is reported.' );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_complete_waits_while_an_order_row_of_the_run_waits(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->rows['orders']['status']       = 'completed';
		$this->rows['orders']['completed_at'] = '2026-11-02 13:00:00';
		$this->waiting[]                      = array(
			'types'      => array( 'order.upsert' ),
			'created_by' => '2026-11-02 13:00:00',
			'max_id'     => null,
		);

		$this->progress()->run();

		self::assertSame( 'start_part_complete', $this->sent[0]['phase'] );
	}

	public function test_a_failed_report_is_tried_again_after_five_minutes_and_never_throws(): void {
		$this->begin_with_sequence();
		$this->engine_error = new ApiException( 500, 'db_failure', 'The engine did not store the call.' );

		$this->progress()->run();

		self::assertCount( 1, $this->sent, 'Tried once.' );
		self::assertSame( self::NOW, $this->options[ HistoryProgress::OPTION ]['failed_at'] );
		self::assertSame( '', $this->options[ HistoryProgress::OPTION ]['reported_phase'] );
		self::assertCount( 1, $this->scheduled, 'The checks go on.' );

		$this->engine_error = null;
		$this->now          = self::NOW + HistoryProgress::REPORT_EVERY_SECONDS - 1;
		$this->progress()->run();
		self::assertCount( 1, $this->sent, 'Not before five minutes.' );

		$this->now = self::NOW + HistoryProgress::REPORT_EVERY_SECONDS;
		$this->progress()->run();
		self::assertCount( 2, $this->sent );
		self::assertSame( 'started', $this->options[ HistoryProgress::OPTION ]['reported_phase'] );
		self::assertSame( 0, $this->options[ HistoryProgress::OPTION ]['failed_at'] );
	}

	public function test_a_check_that_throws_comes_back_after_five_minutes(): void {
		$this->begin_with_sequence();
		$this->reader_error = new \RuntimeException( 'database gone away' );

		$this->progress()->run();

		self::assertSame( array(), $this->sent );
		self::assertSame(
			array(
				array(
					'at'   => self::NOW + HistoryProgress::REPORT_EVERY_SECONDS,
					'hook' => HistoryProgress::HOOK,
				),
			),
			$this->scheduled,
			'CON-284: the checks go on, like a failed report\'s.'
		);

		$this->reader_error = null;
		$this->scheduled    = array();
		$this->now          = self::NOW + HistoryProgress::REPORT_EVERY_SECONDS;
		$this->progress()->run();
		self::assertSame( 'started', $this->sent[0]['phase'], 'The next check reports.' );
	}

	public function test_a_check_pending_beside_the_running_one_is_not_doubled(): void {
		$this->begin_with_sequence();

		// The hourly registration arms a check while one runs.
		$this->progress()->ensure_scheduled();
		$this->progress()->run();
		$this->progress()->ensure_scheduled();

		self::assertCount( 1, $this->scheduled, 'CON-285: one pending check.' );
	}

	public function test_a_held_back_import_ends_without_a_report(): void {
		$this->progress()->begin( self::NOW );
		$this->scheduled = array();
		// Hold back cancelled the catalog import and deleted the sequence.
		$this->row( 'products', 'cancelled' );

		$this->progress()->run();

		self::assertSame( array(), $this->sent, 'Hold back keeps "nothing is sent".' );
		self::assertTrue( $this->options[ HistoryProgress::OPTION ]['ended'] );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_a_cancelled_or_failed_orders_import_ends_the_import(): void {
		foreach ( array( 'cancelled', 'failed' ) as $status ) {
			$this->sent = array();
			$this->begin_with_sequence();
			$this->orders_running( 410, 25 );
			$this->rows['orders']['status'] = $status;

			$this->progress()->run();

			self::assertSame( array(), $this->sent, $status );
			self::assertTrue( $this->options[ HistoryProgress::OPTION ]['ended'], $status );
		}
	}

	public function test_an_orders_run_from_before_the_import_began_is_not_its_orders_part(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25, '2026-11-02 11:59:59' );

		$this->progress()->run();

		self::assertSame( 'started', $this->sent[0]['phase'] );
		self::assertNull( $this->sent[0]['entities']['orders']['expected'] );
		self::assertNull( $this->progress()->card( $this->rows['orders'] ) );
	}

	public function test_nothing_is_sent_while_sending_is_not_allowed(): void {
		$this->begin_with_sequence();
		$this->sending_allowed = false;

		$this->progress()->run();
		$this->progress()->ensure_scheduled();

		self::assertSame( array(), $this->sent );
		self::assertSame( array(), $this->scheduled, 'No check is armed while the account is refused.' );
	}

	public function test_the_card_shows_the_start_part_then_the_whole_window(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->row( 'customers', 'running' );
		$this->rows['orders']['sent_count'] = '2000';

		self::assertSame(
			array(
				'part'           => 'start',
				'sent'           => 1700,
				'expected'       => 1700,
				'percent'        => 100,
				'start_months'   => 18,
				'horizon_months' => 60,
			),
			$this->progress()->card( $this->rows['orders'] ),
			'The start part\'s share, capped at its own orders.'
		);

		$this->row( 'customers', 'completed', array( 'completed_at' => '2026-11-02 12:02:00' ) );
		self::assertSame(
			array(
				'part'           => 'background',
				'sent'           => 2000,
				'expected'       => 4000,
				'percent'        => 50,
				'start_months'   => 18,
				'horizon_months' => 60,
			),
			$this->progress()->card( $this->rows['orders'] )
		);
	}

	public function test_forget_deletes_the_import(): void {
		$this->begin_with_sequence();

		HistoryProgress::forget();

		self::assertArrayNotHasKey( HistoryProgress::OPTION, $this->options );
		self::assertSame( array(), $this->scheduled );
		self::assertNull( $this->progress()->state() );
	}

	// --- helpers -----------------------------------------------------------

	private function begin_with_sequence(): void {
		$this->options[ ImportsOnConnect::OPTION ] = array( 'products', 'customers', 'orders' );
		$this->progress()->begin( self::NOW );
		$this->scheduled = array();
	}

	/**
	 * The orders import of this history import, running, its start part
	 * read up to queue row $queued_to.
	 */
	private function orders_running( int $queued_to, int $last_id, string $started = '2026-11-02 12:00:00' ): void {
		$this->rows['orders'] = array(
			'id'              => '3',
			'status'          => 'running',
			'started_at'      => $started,
			'completed_at'    => null,
			'total_count'     => '4000',
			'processed_count' => '1800',
			'sent_count'      => '1500',
			'cursor_value'    => (string) json_encode(
				array(
					'until'                => '2026-11-02 12:00:00',
					'start_from'           => '2025-05-02 12:00:00',
					'horizon'              => '2021-11-02 12:00:00',
					'after'                => '2025-05-01 09:30:00|' . $last_id,
					'start_part_queued_to' => $queued_to,
				)
			),
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function row( string $job_type, string $status, array $fields = array() ): void {
		$this->rows[ $job_type ] = array_merge(
			array(
				'id'              => '1',
				'status'          => $status,
				'started_at'      => '2026-11-02 12:00:00',
				'completed_at'    => null,
				'total_count'     => null,
				'processed_count' => '0',
				'sent_count'      => '0',
			),
			$fields
		);
	}

	public function test_an_unreadable_import_ends_nothing_and_reports_nothing(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->rows['orders']['status'] = 'failed';
		$this->scheduled                = array();

		foreach ( array( 'orders', 'customers' ) as $type ) {
			$this->unreadable = array( $type );
			$this->progress()->run();

			self::assertSame( array(), $this->sent, $type );
			self::assertFalse( $this->options[ HistoryProgress::OPTION ]['ended'], 'CON-282: not ended on what could not be read — ' . $type );
			self::assertCount( 1, $this->scheduled, 'The next check comes — ' . $type );
			$this->scheduled = array();
		}
	}

	public function test_before_the_schema_is_ready_the_check_waits(): void {
		$this->begin_with_sequence();
		$this->orders_running( 410, 25 );
		$this->rows['orders']['status']                    = 'failed';
		$this->options[ Constants::OPTION_SCHEMA_VERSION ] = Migrator::REQUIRED_VERSION - 1;
		$this->scheduled                                   = array();

		$this->progress()->run();

		self::assertSame( array(), $this->sent );
		self::assertFalse( $this->options[ HistoryProgress::OPTION ]['ended'] );
		self::assertCount( 1, $this->scheduled );
	}

	/**
	 * @return array<string, mixed>|false|null
	 */
	private function read_row( string $type ): array|false|null {
		if ( $this->reader_error !== null ) {
			throw $this->reader_error;
		}

		return in_array( $type, $this->unreadable, true ) ? false : ( $this->rows[ $type ] ?? null );
	}

	private function progress(): HistoryProgress {
		$settings = $this->createMock( RecEngineSettings::class );
		$settings->method( 'sending_allowed' )->willReturnCallback( fn (): bool => $this->sending_allowed );
		$settings->method( 'config' )->willReturn( array() );

		$client = $this->createMock( Client::class );
		$client->method( 'history_progress' )->willReturnCallback(
			function ( array $body ): array {
				$this->sent[] = $body;
				if ( $this->engine_error !== null ) {
					throw $this->engine_error;
				}
				return array( 'ok' => true );
			}
		);

		$queue = $this->createMock( IngestQueue::class );
		$queue->method( 'has_pending_before' )->willReturnCallback(
			function ( array $types, ?string $created_by, ?int $max_id = null ): bool {
				foreach ( $this->waiting as $row ) {
					if ( array_intersect( $types, $row['types'] ) !== array()
						&& ( $created_by === null || ( $row['created_by'] !== null && strcmp( $row['created_by'], $created_by ) <= 0 ) )
						&& ( $max_id === null || ( $row['max_id'] !== null && $row['max_id'] <= $max_id ) )
					) {
						return true;
					}
				}
				return false;
			}
		);

		$now = &$this->now;
		return new class( $settings, static fn (): Client => $client, $queue, fn ( string $type ): array|false|null => $this->read_row( $type ), $now ) extends HistoryProgress {
			/** @var int */
			private $clock;

			/**
			 * @param callable(): Client                     $client_factory
			 * @param callable(string): (array<string, mixed>|false|null) $state_reader
			 */
			public function __construct( RecEngineSettings $settings, callable $client_factory, IngestQueue $queue, callable $state_reader, int &$clock ) {
				parent::__construct( $settings, $client_factory, $queue, $state_reader );
				$this->clock = &$clock;
			}

			protected function now(): int {
				return $this->clock;
			}

			protected function count_orders( string $from, string $until ): int {
				return 1700;
			}

			protected function count_buyers( string $from, string $until ): int {
				return 77;
			}

			protected function is_stalled( string $job_type, array $row ): bool {
				return false;
			}
		};
	}
}
