<?php
/**
 * LegacyOrderDateIndex (CON-259) — when the orders-import index is due, which
 * storage gets it, the online-DDL statement and its fallback, and the bounded
 * failure state, with a recording $wpdb.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\DB;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\DB\LegacyOrderDateIndex;

final class LegacyOrderDateIndexTest extends TestCase {

	/** @var object{posts: string, last_error: string, queries: array<int, string>, indexes: array<int, array<string, string|int>>, fail: array<string, string>, suppress: bool} */
	private object $wpdb;

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb = new class() {
			public string $posts      = 'wp_posts';
			public string $last_error = '';
			/** @var array<int, string> */
			public array $queries = array();
			/** @var array<int, array<string, string|int>> SHOW INDEX rows. */
			public array $indexes = array();
			/** @var array<string, string> A statement fragment => the error it fails with. */
			public array $fail    = array();
			public bool $suppress = false;

			/**
			 * @return bool|int
			 */
			public function query( string $sql ) {
				$this->queries[]  = $sql;
				$this->last_error = '';
				foreach ( $this->fail as $fragment => $error ) {
					if ( strpos( $sql, $fragment ) !== false ) {
						$this->last_error = $error;
						return false;
					}
				}
				return true;
			}

			/**
			 * @return array<int, array<string, string|int>>
			 */
			public function get_results( string $sql, string $output ): array {
				$this->queries[] = $sql;
				return $this->indexes;
			}

			public function get_var( string $sql ): ?string {
				$this->queries[] = $sql;
				if ( strpos( $sql, '@@SESSION.lock_wait_timeout' ) !== false ) {
					return '86400';
				}
				foreach ( $this->indexes as $row ) {
					if ( strpos( $sql, "'" . $row['Key_name'] . "'" ) !== false ) {
						return 'wp_posts';
					}
				}
				return null;
			}

			public function prepare( string $query, string ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function suppress_errors( bool $suppress ): bool {
				$previous       = $this->suppress;
				$this->suppress = $suppress;
				return $previous;
			}

			/** @return array<int, string> The statements that changed the schema. */
			public function alters(): array {
				return array_values(
					array_filter(
						$this->queries,
						static function ( string $sql ): bool {
							return strpos( $sql, 'ALTER TABLE' ) === 0;
						}
					)
				);
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return $this->options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function on( string $storage, bool $connected = true ): LegacyOrderDateIndex {
		return new LegacyOrderDateIndex(
			static function () use ( $storage ): string {
				return $storage;
			},
			static function () use ( $connected ): bool {
				return $connected;
			}
		);
	}

	/** @return array<string, mixed> */
	private function state(): array {
		$state = $this->options[ LegacyOrderDateIndex::OPTION ] ?? array();
		return is_array( $state ) ? $state : array();
	}

	// --- When an attempt is due --------------------------------------------

	public function test_a_store_with_no_state_is_due_at_once(): void {
		self::assertSame( 1000, LegacyOrderDateIndex::due_at( array(), 1000 ) );
	}

	public function test_an_added_or_present_index_is_never_due_again(): void {
		self::assertNull( LegacyOrderDateIndex::due_at( array( 'status' => LegacyOrderDateIndex::STATUS_ADDED, 'attempts' => 1, 'at' => 5 ), 1000 ) );
		self::assertNull( LegacyOrderDateIndex::due_at( array( 'status' => LegacyOrderDateIndex::STATUS_PRESENT, 'attempts' => 0, 'at' => 5 ), 1000 ) );
	}

	public function test_a_failed_attempt_is_retried_a_day_later_until_the_attempts_are_used(): void {
		$failed = array( 'status' => LegacyOrderDateIndex::STATUS_FAILED, 'attempts' => 1, 'at' => 5000 );
		self::assertSame( 5000 + LegacyOrderDateIndex::RETRY_AFTER_SECONDS, LegacyOrderDateIndex::due_at( $failed, 6000 ) );

		$failed['attempts'] = LegacyOrderDateIndex::MAX_ATTEMPTS;
		self::assertNull( LegacyOrderDateIndex::due_at( $failed, 6000 ), 'No retry forever.' );
	}

	public function test_a_running_attempt_waits_and_one_the_host_killed_counts_as_failed(): void {
		$now     = 100000;
		$running = array( 'status' => LegacyOrderDateIndex::STATUS_RUNNING, 'attempts' => 1, 'at' => $now - 60 );
		self::assertSame( $now - 60 + LegacyOrderDateIndex::RUNNING_STALE_SECONDS, LegacyOrderDateIndex::due_at( $running, $now ) );

		$running['at'] = $now - LegacyOrderDateIndex::RUNNING_STALE_SECONDS - 1;
		self::assertSame( $now, LegacyOrderDateIndex::due_at( $running, $now ) );

		$running['attempts'] = LegacyOrderDateIndex::MAX_ATTEMPTS;
		self::assertNull( LegacyOrderDateIndex::due_at( $running, $now ) );
	}

	// --- Pure rules --------------------------------------------------------

	public function test_an_index_led_by_type_and_gmt_date_covers_the_query(): void {
		self::assertTrue( LegacyOrderDateIndex::covers( array( 'post_type', 'post_date_gmt', 'ID' ) ) );
		self::assertTrue( LegacyOrderDateIndex::covers( array( 'POST_TYPE', 'post_date_gmt' ) ) );
		self::assertFalse( LegacyOrderDateIndex::covers( array( 'post_type', 'post_status', 'post_date', 'ID' ) ), 'type_status_date sorts by the local date.' );
		self::assertFalse( LegacyOrderDateIndex::covers( array( 'post_date_gmt' ) ) );
	}

	public function test_only_a_refused_online_ddl_clause_falls_back_to_a_plain_add(): void {
		self::assertTrue( LegacyOrderDateIndex::is_clause_refused( 'ALGORITHM=INPLACE is not supported for this operation. Try ALGORITHM=COPY' ) );
		self::assertTrue( LegacyOrderDateIndex::is_clause_refused( 'LOCK=NONE is not supported. Reason: Fulltext index creation requires a lock. Try LOCK=SHARED' ) );
		self::assertTrue( LegacyOrderDateIndex::is_clause_refused( "You have an error in your SQL syntax; check the manual near 'ALGORITHM=INPLACE, LOCK=NONE' at line 1" ) );
		self::assertFalse( LegacyOrderDateIndex::is_clause_refused( 'Lock wait timeout exceeded; try restarting transaction' ) );
		self::assertFalse( LegacyOrderDateIndex::is_clause_refused( "ALTER command denied to user 'shop'@'localhost' for table 'wp_posts'" ) );
		self::assertFalse( LegacyOrderDateIndex::is_clause_refused( "Duplicate key name 'smaily_connect_order_date'" ) );
	}

	public function test_a_stored_error_has_no_quoted_user_or_host(): void {
		self::assertSame(
			"ALTER command denied to user '?'@'?' for table '?'",
			LegacyOrderDateIndex::redact( "ALTER command denied to user 'shop'@'db.example' for table 'wp_posts'" )
		);
	}

	// --- Scheduling ---------------------------------------------------------

	public function test_hpos_and_a_store_without_woocommerce_schedule_nothing(): void {
		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();

		self::on( LegacyOrderDateIndex::STORAGE_HPOS )->ensure_scheduled();
		self::on( LegacyOrderDateIndex::STORAGE_NONE )->ensure_scheduled();

		self::assertSame( array(), $this->options, 'Nothing is recorded, so a later switch to legacy storage still gets the index.' );
	}

	public function test_a_store_not_connected_to_campaign_intelligence_gets_nothing(): void {
		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();
		$storage_read = false;
		$index        = new LegacyOrderDateIndex(
			static function () use ( &$storage_read ): string {
				$storage_read = true;
				return LegacyOrderDateIndex::STORAGE_LEGACY;
			},
			static function (): bool {
				return false;
			}
		);

		$index->ensure_scheduled();
		self::assertSame( 'not_connected', $index->run() );

		self::assertFalse( $storage_read, 'The connection is checked first.' );
		self::assertSame( array(), $this->wpdb->queries, 'No table is touched.' );
		self::assertSame( array(), $this->options, 'Nothing is recorded, so a later connect still gets the index.' );
	}

	public function test_a_connected_legacy_store_schedules_one_action_while_it_is_due(): void {
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( LegacyOrderDateIndex::HOOK, array(), LegacyOrderDateIndex::AS_GROUP )
			->andReturn( false );
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::type( 'int' ), LegacyOrderDateIndex::HOOK, array(), LegacyOrderDateIndex::AS_GROUP );

		self::on( LegacyOrderDateIndex::STORAGE_LEGACY )->ensure_scheduled();

		$this->addToAssertionCount( 1 );
	}

	public function test_a_scheduled_action_is_not_scheduled_twice(): void {
		Functions\expect( 'as_has_scheduled_action' )->once()->andReturn( true );
		Functions\expect( 'as_schedule_single_action' )->never();

		self::on( LegacyOrderDateIndex::STORAGE_LEGACY )->ensure_scheduled();

		$this->addToAssertionCount( 1 );
	}

	public function test_a_legacy_store_that_has_the_index_schedules_nothing(): void {
		$this->options[ LegacyOrderDateIndex::OPTION ] = array( 'status' => LegacyOrderDateIndex::STATUS_ADDED, 'attempts' => 1, 'at' => 5 );
		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();

		self::on( LegacyOrderDateIndex::STORAGE_LEGACY )->ensure_scheduled();

		$this->addToAssertionCount( 1 );
	}

	// --- The action ---------------------------------------------------------

	public function test_on_hpos_the_action_touches_no_table_and_records_nothing(): void {
		self::assertSame( 'not_legacy', self::on( LegacyOrderDateIndex::STORAGE_HPOS )->run() );
		self::assertSame( array(), $this->wpdb->queries );
		self::assertSame( array(), $this->options );
	}

	public function test_a_connected_legacy_store_gets_the_index_online_with_a_short_lock_wait(): void {
		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, self::on( LegacyOrderDateIndex::STORAGE_LEGACY, true )->run() );

		self::assertSame(
			array( 'ALTER TABLE wp_posts ADD INDEX smaily_connect_order_date (post_type, post_date_gmt, ID), ALGORITHM=INPLACE, LOCK=NONE' ),
			$this->wpdb->alters()
		);
		self::assertContains( 'SET SESSION lock_wait_timeout = ' . LegacyOrderDateIndex::LOCK_WAIT_SECONDS, $this->wpdb->queries );
		self::assertSame( 'SET SESSION lock_wait_timeout = 86400', end( $this->wpdb->queries ), 'The session lock wait is restored.' );
		self::assertFalse( $this->wpdb->suppress, 'Error suppression is restored.' );
		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, $this->state()['status'] );
		self::assertSame( 1, $this->state()['attempts'] );
	}

	public function test_a_server_that_refuses_the_online_clause_gets_a_plain_add(): void {
		$this->wpdb->fail = array( 'ALGORITHM=INPLACE' => 'ALGORITHM=INPLACE is not supported for this operation. Try ALGORITHM=COPY' );

		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, self::on( LegacyOrderDateIndex::STORAGE_LEGACY )->run() );
		self::assertSame(
			array(
				'ALTER TABLE wp_posts ADD INDEX smaily_connect_order_date (post_type, post_date_gmt, ID), ALGORITHM=INPLACE, LOCK=NONE',
				'ALTER TABLE wp_posts ADD INDEX smaily_connect_order_date (post_type, post_date_gmt, ID)',
			),
			$this->wpdb->alters()
		);
	}

	public function test_a_denied_alter_is_recorded_without_a_plain_retry_and_tried_again_later(): void {
		$this->wpdb->fail = array( 'ADD INDEX' => "ALTER command denied to user 'shop'@'localhost' for table 'wp_posts'" );

		self::assertSame( LegacyOrderDateIndex::STATUS_FAILED, self::on( LegacyOrderDateIndex::STORAGE_LEGACY )->run() );

		self::assertCount( 1, $this->wpdb->alters(), 'Only a refused clause falls back to a plain ADD INDEX.' );
		$state = $this->state();
		self::assertSame( LegacyOrderDateIndex::STATUS_FAILED, $state['status'] );
		self::assertSame( 1, $state['attempts'] );
		self::assertSame( "ALTER command denied to user '?'@'?' for table '?'", $state['error'] );
		self::assertSame( (int) $state['at'] + LegacyOrderDateIndex::RETRY_AFTER_SECONDS, LegacyOrderDateIndex::due_at( $state, (int) $state['at'] ) );
	}

	public function test_after_the_last_attempt_fails_nothing_runs_again(): void {
		$this->wpdb->fail = array( 'ADD INDEX' => 'Lock wait timeout exceeded; try restarting transaction' );
		$this->options[ LegacyOrderDateIndex::OPTION ] = array(
			'status'   => LegacyOrderDateIndex::STATUS_FAILED,
			'attempts' => LegacyOrderDateIndex::MAX_ATTEMPTS - 1,
			'at'       => time() - LegacyOrderDateIndex::RETRY_AFTER_SECONDS - 1,
		);
		$index = self::on( LegacyOrderDateIndex::STORAGE_LEGACY );

		self::assertSame( LegacyOrderDateIndex::STATUS_FAILED, $index->run() );
		self::assertSame( LegacyOrderDateIndex::MAX_ATTEMPTS, $this->state()['attempts'] );
		self::assertNull( LegacyOrderDateIndex::due_at( $this->state(), time() + 10 * LegacyOrderDateIndex::RETRY_AFTER_SECONDS ) );

		$this->wpdb->queries = array();
		self::assertSame( 'not_due', $index->run() );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_an_index_that_already_serves_the_query_is_not_added_again(): void {
		$this->wpdb->indexes = array(
			array( 'Key_name' => 'type_status_date', 'Seq_in_index' => 1, 'Column_name' => 'post_type' ),
			array( 'Key_name' => 'type_status_date', 'Seq_in_index' => 2, 'Column_name' => 'post_status' ),
			array( 'Key_name' => 'someone_elses', 'Seq_in_index' => 2, 'Column_name' => 'post_date_gmt' ),
			array( 'Key_name' => 'someone_elses', 'Seq_in_index' => 1, 'Column_name' => 'post_type' ),
		);

		self::assertSame( LegacyOrderDateIndex::STATUS_PRESENT, self::on( LegacyOrderDateIndex::STORAGE_LEGACY )->run() );
		self::assertSame( array(), $this->wpdb->alters() );
		self::assertSame( LegacyOrderDateIndex::STATUS_PRESENT, $this->state()['status'] );
	}

	// --- Uninstall ----------------------------------------------------------

	public function test_drop_removes_only_this_index_and_only_when_it_exists(): void {
		LegacyOrderDateIndex::drop();
		self::assertSame( array(), $this->wpdb->alters(), 'No index, no ALTER.' );

		$this->wpdb->indexes = array(
			array( 'Key_name' => LegacyOrderDateIndex::INDEX, 'Seq_in_index' => 1, 'Column_name' => 'post_type' ),
		);
		LegacyOrderDateIndex::drop();
		self::assertSame( array( 'ALTER TABLE wp_posts DROP INDEX smaily_connect_order_date' ), $this->wpdb->alters() );
	}

	public function test_uninstall_drops_the_index(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		self::assertStringContainsString( "require_once __DIR__ . '/includes/DB/LegacyOrderDateIndex.php';", $source );
		self::assertStringContainsString( 'LegacyOrderDateIndex::drop();', $source );
	}
}
