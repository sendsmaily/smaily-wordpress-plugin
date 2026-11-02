<?php
/**
 * The orders-import index on a legacy posts table (CON-259), on the real
 * MariaDB: nothing on a store not connected to Campaign Intelligence; on a
 * connected one added once by the action, a second run is a no-op, the import's
 * real page query reads it without a filesort and returns the same orders,
 * deactivation leaves it, uninstall's drop() removes it — and on HPOS the
 * posts table is not touched at all. CI runs this on both order storages
 * (PRO-3435); each test skips on the storage it is not about.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\DB\LegacyOrderDateIndex;
use Smaily\Connect\Deactivation;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

final class LegacyOrderDateIndexTest extends TestCase {

	private const SEED_SLUG = 'smaily-con259-order-';

	private const SEED_COUNT = 600;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		EnvSeed::connect();
		LegacyOrderDateIndex::drop();
		$this->forget_state();
		as_unschedule_all_actions( '', array(), LegacyOrderDateIndex::AS_GROUP );
	}

	protected function tearDown(): void {
		$this->delete_seeded_orders();
		LegacyOrderDateIndex::drop();
		$this->forget_state();
		as_unschedule_all_actions( '', array(), LegacyOrderDateIndex::AS_GROUP );
		EnvScrub::reset();
		parent::tearDown();
	}

	public function test_a_store_not_connected_to_campaign_intelligence_gets_nothing(): void {
		$this->require_storage( false );
		EnvScrub::reset();
		$index  = new LegacyOrderDateIndex();
		$before = $this->posts_indexes();

		$index->ensure_scheduled();
		self::assertSame( 'not_connected', $index->run() );

		self::assertSame( $before, $this->posts_indexes() );
		self::assertCount( 0, $this->pending_actions() );
		self::assertSame( array(), LegacyOrderDateIndex::read_state(), 'Nothing is recorded: a later connect still gets the index.' );

		// Connecting later: the same check schedules it and the action adds it.
		EnvSeed::connect();
		$index->ensure_scheduled();
		self::assertCount( 1, $this->pending_actions() );
		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, $index->run() );

		// Disconnecting keeps it.
		EnvScrub::reset();
		self::assertSame( 'not_connected', $index->run() );
		self::assertSame( array( 'post_type', 'post_date_gmt', 'ID' ), $this->columns_of( LegacyOrderDateIndex::INDEX ) );
	}

	public function test_on_legacy_storage_the_action_adds_the_index_once(): void {
		$this->require_storage( false );
		$index = new LegacyOrderDateIndex();

		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, $index->run() );
		self::assertSame( array( 'post_type', 'post_date_gmt', 'ID' ), $this->columns_of( LegacyOrderDateIndex::INDEX ) );
		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, LegacyOrderDateIndex::read_state()['status'] );

		// A second run is a no-op: the state says it is done.
		self::assertSame( 'not_due', $index->run() );

		// With the state lost, the existence check still adds nothing.
		$this->forget_state();
		self::assertSame( LegacyOrderDateIndex::STATUS_PRESENT, $index->run() );
		self::assertSame( array( 'post_type', 'post_date_gmt', 'ID' ), $this->columns_of( LegacyOrderDateIndex::INDEX ) );
	}

	public function test_on_legacy_storage_one_action_is_scheduled_until_the_index_is_added(): void {
		$this->require_storage( false );
		$index = new LegacyOrderDateIndex();

		$index->ensure_scheduled();
		$index->ensure_scheduled();
		self::assertCount( 1, $this->pending_actions() );

		as_unschedule_all_actions( '', array(), LegacyOrderDateIndex::AS_GROUP );
		$index->run();
		$index->ensure_scheduled();
		self::assertCount( 0, $this->pending_actions(), 'Nothing is scheduled once the index is in.' );
	}

	public function test_the_orders_import_page_query_reads_the_index_without_a_filesort(): void {
		$this->require_storage( false );
		global $wpdb;
		$this->seed_orders();

		list( $before ) = $this->import_page();
		self::assertCount( 50, $before['ids'] );

		self::assertSame( LegacyOrderDateIndex::STATUS_ADDED, ( new LegacyOrderDateIndex() )->run() );

		list( $after, $sql ) = $this->import_page();
		self::assertSame( $before['ids'], $after['ids'], 'The index changes how the page is read, not which orders it holds.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- EXPLAIN of the import's own prepared statement.
		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
		self::assertCount( 1, $plan );
		self::assertSame( LegacyOrderDateIndex::INDEX, $plan[0]['key'], 'Plan: ' . wp_json_encode( $plan ) );
		self::assertStringNotContainsString( 'filesort', (string) $plan[0]['Extra'], 'Plan: ' . wp_json_encode( $plan ) );
	}

	public function test_deactivation_leaves_the_index_and_uninstall_drop_removes_it(): void {
		$this->require_storage( false );
		( new LegacyOrderDateIndex() )->run();

		try {
			Deactivation::run();
			self::assertNotSame( array(), $this->columns_of( LegacyOrderDateIndex::INDEX ), 'Deactivation leaves the index.' );
		} finally {
			Activation::run();
			Bootstrap::instance()->register_action_scheduler_jobs();
		}

		LegacyOrderDateIndex::drop();
		self::assertSame( array(), $this->columns_of( LegacyOrderDateIndex::INDEX ) );
		LegacyOrderDateIndex::drop();
		self::assertSame( array(), $this->columns_of( LegacyOrderDateIndex::INDEX ), 'A second drop is a no-op.' );
	}

	public function test_on_hpos_the_posts_table_is_not_touched(): void {
		$this->require_storage( true );
		$index  = new LegacyOrderDateIndex();
		$before = $this->posts_indexes();

		$index->ensure_scheduled();
		self::assertSame( 'not_legacy', $index->run() );

		self::assertSame( $before, $this->posts_indexes() );
		self::assertSame( array(), $this->columns_of( LegacyOrderDateIndex::INDEX ) );
		self::assertCount( 0, $this->pending_actions() );
		self::assertSame( array(), LegacyOrderDateIndex::read_state(), 'Nothing is recorded: a later switch to legacy storage still gets the index.' );
	}

	private function require_storage( bool $hpos ): void {
		if ( OrderBackfillJob::uses_hpos() !== $hpos ) {
			self::markTestSkipped( $hpos ? 'Runs on the HPOS leg.' : 'Runs on the legacy leg.' );
		}
	}

	private function forget_state(): void {
		delete_option( LegacyOrderDateIndex::OPTION );
		wp_cache_delete( LegacyOrderDateIndex::OPTION, 'options' );
		wp_cache_delete( LegacyOrderDateIndex::OPTION, 'notoptions' );
	}

	/** @return int[] */
	private function pending_actions(): array {
		return as_get_scheduled_actions(
			array(
				'hook'   => LegacyOrderDateIndex::HOOK,
				'group'  => LegacyOrderDateIndex::AS_GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
	}

	/** @return string[] The columns of the posts index $name, in order. */
	private function columns_of( string $name ): array {
		$columns = array();
		foreach ( $this->posts_indexes() as $row ) {
			if ( $row['Key_name'] === $name ) {
				$columns[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
		}
		ksort( $columns );
		return array_values( $columns );
	}

	/** @return array<int, array{Key_name: string, Seq_in_index: string, Column_name: string}> */
	private function posts_indexes(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema read.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts}", ARRAY_A );
		return array_map(
			static function ( array $row ): array {
				return array(
					'Key_name'     => (string) $row['Key_name'],
					'Seq_in_index' => (string) $row['Seq_in_index'],
					'Column_name'  => (string) $row['Column_name'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * The import's first page, read with its own query, and that query's SQL.
	 *
	 * @return array{0: array{ids: int[], cursor: string, completed: bool}, 1: string}
	 */
	private function import_page(): array {
		$bootstrap = Bootstrap::instance();
		$job       = new class( $bootstrap->ingest_queue(), $bootstrap->order_flusher(), $bootstrap->history_config() ) extends OrderBackfillJob {
			/** @return array{ids: int[], cursor: string, completed: bool} */
			public function first_page( string $cursor ): array {
				return $this->next_page( $cursor, $this->batch_size() );
			}
		};
		$sql     = '';
		$capture = static function ( string $query ) use ( &$sql ): string {
			if ( strpos( $query, 'ORDER BY post_date_gmt DESC' ) !== false ) {
				$sql = $query;
			}
			return $query;
		};
		$window  = OrderBackfillJob::window(
			array(
				'start_months'   => 18,
				'horizon_months' => 60,
				'order'          => 'newest_first',
			),
			time()
		);

		add_filter( 'query', $capture );
		try {
			$page = $job->first_page( (string) wp_json_encode( $window ) );
		} finally {
			remove_filter( 'query', $capture );
		}
		self::assertNotSame( '', $sql, 'The import ran its page query.' );

		return array( $page, $sql );
	}

	/**
	 * SEED_COUNT shop_order posts over the last 12 months, straight into the
	 * table (no hooks, nothing queued, no customer data); every 20th is a
	 * non-sale status, every 50th shares its second with the one before.
	 */
	private function seed_orders(): void {
		global $wpdb;
		$now  = time();
		$span = 365 * DAY_IN_SECONDS;
		$rows = array();
		for ( $i = 1; $i <= self::SEED_COUNT; $i++ ) {
			$at     = $now - 60 - (int) floor( $span * ( $i - ( $i % 50 === 0 ? 1 : 0 ) ) / ( self::SEED_COUNT + 1 ) );
			$gmt    = gmdate( 'Y-m-d H:i:s', $at );
			$status = $i % 20 === 0 ? 'wc-pending' : 'wc-completed';
			$rows[] = $wpdb->prepare( '(1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $gmt, $gmt, 'Order', '', '', $status, self::SEED_SLUG . $i, $gmt, $gmt, 'shop_order', '', '', '' );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test seed; each row is prepared above.
		$wpdb->query( "INSERT INTO {$wpdb->posts} ( post_author, post_date, post_date_gmt, post_title, post_content, post_excerpt, post_status, post_name, post_modified, post_modified_gmt, post_type, to_ping, pinged, post_content_filtered ) VALUES " . implode( ',', $rows ) );
		$wpdb->query( "ANALYZE TABLE {$wpdb->posts}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function delete_seeded_orders(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the rows seed_orders() wrote.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_name LIKE %s", $wpdb->esc_like( self::SEED_SLUG ) . '%' ) );
	}
}
