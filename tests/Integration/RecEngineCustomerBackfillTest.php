<?php
/**
 * Integration: CustomerBackfillJob (3.5.1) — cursor traversal of existing users
 * into the same ingest queue + CustomerFlusher the live hook uses, with the
 * A-filter (F3-20) matching CustomerHookHandler.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CustomerBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * Proves the same backfill properties as catalog (resumability + bounded queue)
 * AND the A-filter consistency that matters for customers: the backfill must
 * enumerate the SAME cohort CustomerHookHandler enqueues — every registered
 * user, NO role filter — so a non-`customer` role (subscriber, editor, custom)
 * is backfilled, not silently skipped.
 */
final class RecEngineCustomerBackfillTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->truncate_queue();
		$this->delete_non_admin_users();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array( 'ingest_customers' => $base . '/api/v1/ingest/customers' ),
			)
		);
	}

	protected function tearDown(): void {
		$this->delete_non_admin_users();
		parent::tearDown();
	}

	public function test_a_filter_enumerates_every_role_no_role_filter(): void {
		$subscriber = $this->make_user( 'bf-sub@example.test', 'subscriber' );
		$customer   = $this->make_user( 'bf-cust@example.test', 'customer' );
		$editor     = $this->make_user( 'bf-editor@example.test', 'editor' );

		$ids = $this->job()->ids_after( 0, 10000 );

		self::assertContains( $subscriber, $ids, 'A subscriber is backfilled — no role filter (A-filter, F3-20).' );
		self::assertContains( $customer, $ids );
		self::assertContains( $editor, $ids, 'A custom/editor role is backfilled too.' );
	}

	public function test_resumes_from_cursor_and_does_not_restart(): void {
		// 2 users + the admin = 3 total; batch_size 2 → batch1 (2), batch2 (1,
		// completes). Not an exact multiple, so completion lands on batch 2.
		$this->make_user( 'bf-r1@example.test', 'customer' );
		$this->make_user( 'bf-r2@example.test', 'customer' );

		$job   = $this->job( 2 );
		$job->start();
		$total = (int) $this->read_backfill_row()['total_count']; // admin + 2 = 3

		$b1      = $job->process_batch();
		$cursor1 = (int) $this->read_backfill_row()['cursor_value'];
		self::assertSame( 2, $b1['processed'] );
		self::assertFalse( $b1['completed'] );
		self::assertSame( 2, (int) $this->read_backfill_row()['processed_count'] );

		$b2      = $job->process_batch();
		$cursor2 = (int) $this->read_backfill_row()['cursor_value'];
		self::assertSame( 1, $b2['processed'], 'Resumed: only the 1 user after the cursor, not a restart.' );
		self::assertGreaterThan( $cursor1, $cursor2, 'Cursor advanced — batch 2 resumed past batch 1.' );
		self::assertSame(
			$total,
			(int) $this->read_backfill_row()['processed_count'],
			'Each user processed exactly once across batches (cumulative to total, not restarted).'
		);
		self::assertTrue( $b2['completed'] );
	}

	public function test_queue_is_bounded_after_each_batch(): void {
		$this->make_user( 'bf-b1@example.test', 'customer' );
		$this->make_user( 'bf-b2@example.test', 'customer' );
		$this->make_user( 'bf-b3@example.test', 'customer' );

		$queue = new IngestQueue();
		$job   = $this->job( 2, $queue );
		$job->start();

		$job->process_batch();
		self::assertSame(
			array(),
			$queue->pending( 1000, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ),
			'Inline flush drained the batch — the queue is bounded.'
		);
	}

	public function test_full_backfill_processes_every_user_and_completes(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->make_user( "bf-full{$i}@example.test", 'customer' );
		}

		$job = $this->job( 2 );
		$job->start();
		$total = (int) $this->read_backfill_row()['total_count'];

		$guard = 0;
		do {
			$result = $job->process_batch();
		} while ( empty( $result['completed'] ) && ++$guard < 20 );

		$row = $this->read_backfill_row();
		self::assertSame( 'completed', $row['status'] );
		self::assertSame( $total, (int) $row['processed_count'], 'Every user (incl. admin) processed.' );
	}

	// --- helpers --------------------------------------------------------

	/**
	 * Test double: a tunable batch size + a public window onto the protected
	 * enumerator so the A-filter (no role filter) is assertable. No declared
	 * return type so the anonymous subclass's `ids_after` stays visible.
	 */
	private function job( int $batch_size = 100, ?IngestQueue $queue = null ) {
		$settings = new RecEngineSettings();
		$queue    = $queue ?? new IngestQueue();
		$flusher  = new CustomerFlusher(
			$queue,
			new CustomerPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);

		return new class( $queue, $flusher, $batch_size ) extends CustomerBackfillJob {
			private int $bs;

			public function __construct( IngestQueue $queue, CustomerFlusher $flusher, int $bs ) {
				parent::__construct( $queue, $flusher );
				$this->bs = $bs;
			}

			protected function batch_size(): int {
				return $this->bs;
			}

			/**
			 * @return int[]
			 */
			public function ids_after( int $after_id, int $limit ): array {
				return $this->fetch_ids_after( $after_id, $limit );
			}
		};
	}

	private function make_user( string $email, string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'bf_' . md5( $email ),
				'user_pass'  => 'x' . wp_generate_password( 12, false ),
				'user_email' => $email,
				'role'       => $role,
			)
		);
		self::assertIsInt( $id );
		$this->created_users[] = (int) $id;
		return (int) $id;
	}

	private function delete_non_admin_users(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$ids = get_users( array( 'fields' => 'ID', 'exclude' => array( 1 ) ) );
		foreach ( $ids as $id ) {
			wp_delete_user( (int) $id );
		}
		$this->created_users = array();
	}

	private function truncate_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_backfill_row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = %s",
				'customers',
				AbstractBackfillJob::TARGET
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}
}
