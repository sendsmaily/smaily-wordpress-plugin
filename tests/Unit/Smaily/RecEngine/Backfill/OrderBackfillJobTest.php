<?php
/**
 * Unit: OrderBackfillJob's pure storage-mode mapping (3.5.2), the single-source
 * mapped-status list, the history window's dates (CON-245) and a cursor that
 * is not a window (CON-286). The walk itself runs against both order storages
 * in RecEngineOrderBackfillTest.
 *
 * @package Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;

final class OrderBackfillJobTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_legacy_table_spec_targets_wp_posts(): void {
		$spec = OrderBackfillJob::table_spec( false, 'wp_' );
		self::assertSame( 'wp_posts', $spec['table'] );
		self::assertSame( 'ID', $spec['id_col'] );
		self::assertSame( 'post_type', $spec['type_col'] );
		self::assertSame( 'post_status', $spec['status_col'] );
		self::assertSame( 'post_modified_gmt', $spec['modified_col'] );
		self::assertSame( 'post_date_gmt', $spec['created_col'], 'The order date in UTC.' );
	}

	public function test_hpos_table_spec_targets_wc_orders(): void {
		$spec = OrderBackfillJob::table_spec( true, 'wp_' );
		self::assertSame( 'wp_wc_orders', $spec['table'], 'HPOS reads the custom orders table, not wp_posts.' );
		self::assertSame( 'id', $spec['id_col'] );
		self::assertSame( 'type', $spec['type_col'] );
		self::assertSame( 'status', $spec['status_col'] );
		self::assertSame( 'date_updated_gmt', $spec['modified_col'] );
		self::assertSame( 'date_created_gmt', $spec['created_col'], 'The order date in UTC.' );
	}

	public function test_the_window_counts_calendar_months_back_from_the_start(): void {
		$window = OrderBackfillJob::window(
			array(
				'start_months'   => 18,
				'horizon_months' => 60,
				'order'          => 'newest_first',
			),
			(int) gmmktime( 12, 0, 0, 10, 29, 2026 )
		);

		self::assertSame(
			array(
				'until'                => '2026-10-29 12:00:00',
				'start_from'           => '2025-04-29 12:00:00',
				'horizon'              => '2021-10-29 12:00:00',
				'after'                => '',
				'start_part_queued_to' => null,
			),
			$window
		);
	}

	public function test_non_sale_statuses_are_the_single_source_for_the_filter(): void {
		// The backfill filters `status NOT IN (non-sale)`, so its single source is
		// the DENYLIST of non-sale statuses (F3-42). Sale + custom statuses are
		// NOT denylisted, so the backfill includes them.
		$non_sale = OrderPayloadBuilder::non_sale_wc_statuses();

		self::assertContains( 'pending', $non_sale );
		self::assertContains( 'failed', $non_sale );
		self::assertContains( 'trash', $non_sale );
		self::assertNotContains( 'completed', $non_sale );
		self::assertNotContains( 'shipped', $non_sale, 'A custom status is a sale now — not denylisted.' );

		$builder = new OrderPayloadBuilder();

		// Drift guard: every denylisted status maps to '' (excluded / skipped).
		foreach ( $non_sale as $status ) {
			self::assertSame( '', $builder->map_status( $status ), "{$status} must be excluded (map to '')." );
		}

		// A custom status is NOT denylisted and maps to a non-empty sale enum.
		self::assertSame( 'processing', $builder->map_status( 'label-printed' ), 'A custom status defaults through as a sale (F3-42).' );
	}

	/**
	 * CON-286: a running import whose cursor is not a window (a bare order id
	 * of a 3.20 run, or anything else) fails the run with the error type and
	 * line, and never asks the engine for its history settings — only start()
	 * does, once per run.
	 */
	public function test_a_cursor_that_is_not_a_window_fails_the_run_without_asking_the_engine(): void {
		foreach ( array( '12345', '', '{"until":"2026-11-13 10:00:00"}' ) as $cursor ) {
			$wpdb            = $this->fake_wpdb( $cursor );
			$GLOBALS['wpdb'] = $wpdb;
			$pings           = 0;
			$flusher         = $this->createMock( OrderFlusher::class );
			$flusher->method( 'sending_allowed' )->willReturn( true );
			$flusher->expects( self::never() )->method( 'flush' );
			$history = new HistoryConfig(
				new FakeRecEngineSettings(),
				static function () use ( &$pings ): Client {
					++$pings;
					throw new \LogicException( 'The batch must not ask the engine.' );
				}
			);
			$job     = new OrderBackfillJob( $this->createMock( IngestQueue::class ), $flusher, $history );

			$result = $job->process_batch();

			self::assertSame( 0, $pings, "cursor '{$cursor}': no history request." );
			self::assertTrue( $result['completed'], "cursor '{$cursor}': the tick chain ends." );
			self::assertSame( 0, $result['processed'] );
			self::assertCount( 1, $wpdb->updates, "cursor '{$cursor}': only the failure is written." );
			self::assertSame( BackfillJobInterface::STATUS_FAILED, $wpdb->updates[0]['data']['status'] );
			self::assertMatchesRegularExpression( '/^UnexpectedValueException at OrderBackfillJob\.php:\d+$/', $wpdb->updates[0]['data']['error_message'] );
			self::assertSame( BackfillJobInterface::STATUS_RUNNING, $wpdb->updates[0]['where']['status'], 'A cancel that landed meanwhile stays a cancel.' );
		}
	}

	/**
	 * A $wpdb whose orders state row is a running import with $cursor.
	 */
	private function fake_wpdb( string $cursor ): object {
		return new class( $cursor ) {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $updates = array();
			private string $cursor;

			public function __construct( string $cursor ) {
				$this->cursor = $cursor;
			}

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/** @return array<string, string> */
			public function get_row( string $sql, string $output = 'ARRAY_A' ): array {
				return array(
					'id'              => '9',
					'status'          => BackfillJobInterface::STATUS_RUNNING,
					'cursor_value'    => $this->cursor,
					'processed_count' => '50',
					'total_count'     => '200',
					'started_at'      => '2026-11-13 10:00:00',
				);
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
