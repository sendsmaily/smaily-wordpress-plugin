<?php
/**
 * Integration: CustomerBackfillJob (3.5.1) — cursor traversal of existing users
 * into the same ingest queue + CustomerFlusher the live hook uses, over the
 * §4 customer scope (CON-246) CustomerHookHandler applies too.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CustomerBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Wizard\EnvDetector;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * Proves the same backfill properties as catalog (resumability + bounded queue)
 * AND the customer scope (contract v1.20.0 §4, CON-246): the page query
 * returns the users the contact sync puts into Smaily plus the buyers with a
 * sale order inside the horizon, on whichever order storage the leg runs
 * (CI runs this on legacy and HPOS, PRO-3435), with no role filter. The
 * resumability tests run in the legitimate-interest mode, where every
 * registered user is in the contact audience.
 */
final class RecEngineCustomerBackfillTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_users = array();

	/** @var int[] */
	private array $created_orders = array();

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
		delete_option( ContactSyncMode::OPTION_SYNC_ENABLED );
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_LEGITIMATE_INTEREST );
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true ); // Not wp_delete_post: a no-op under HPOS.
			}
		}
		$this->created_orders = array();
		$this->delete_non_admin_users();
		delete_option( ContactSyncMode::OPTION_MODE );
		delete_option( ContactSyncMode::OPTION_SYNC_ENABLED );
		parent::tearDown();
	}

	public function test_legitimate_interest_puts_every_role_in_scope_no_role_filter(): void {
		$subscriber = $this->make_user( 'bf-sub@example.test', 'subscriber' );
		$customer   = $this->make_user( 'bf-cust@example.test', 'customer' );
		$editor     = $this->make_user( 'bf-editor@example.test', 'editor' );

		$ids = $this->job()->ids_after( 0, 10000 );

		self::assertContains( $subscriber, $ids, 'A subscriber is backfilled — no role filter.' );
		self::assertContains( $customer, $ids );
		self::assertContains( $editor, $ids, 'A custom/editor role is backfilled too.' );
	}

	/**
	 * CON-246 on the active order storage: in the consent mode the import
	 * reads the opted-in users and the buyers with a sale order inside the
	 * 60-month horizon — not an account that never bought or subscribed
	 * (an administrator included), not a buyer whose only sale is older than
	 * the horizon, not one whose only recent order is not a sale.
	 */
	public function test_consent_mode_imports_subscribers_and_recent_buyers_only(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );

		$subscriber   = $this->make_user( 'bf-scope-sub@example.test', 'customer' );
		$recent_buyer = $this->make_user( 'bf-scope-buyer@example.test', 'customer' );
		$old_buyer    = $this->make_user( 'bf-scope-old@example.test', 'customer' );
		$pending      = $this->make_user( 'bf-scope-pending@example.test', 'customer' );
		$custom       = $this->make_user( 'bf-scope-custom@example.test', 'customer' );
		$plain        = $this->make_user( 'bf-scope-plain@example.test', 'customer' );
		$admin        = $this->make_user( 'bf-scope-admin@example.test', 'administrator' );

		update_user_meta( $subscriber, ContactAudience::OPTIN_META, '1' );
		$this->make_order( $recent_buyer, 'completed', strtotime( '-2 years' ) );
		$this->make_order( $old_buyer, 'completed', strtotime( '-7 years' ) );
		$this->make_order( $pending, 'pending', strtotime( '-1 month' ) );
		$this->set_stored_status( $this->make_order( $custom, 'processing', strtotime( '-3 months' ) ), 'wc-label-printed' );

		$job  = $this->job();
		$ids  = $job->ids_after( 0, 10000 );
		$mine = array_values( array_intersect( $ids, $this->created_users ) );
		sort( $mine );
		$expected = array( $subscriber, $recent_buyer, $custom );
		sort( $expected );

		self::assertSame( $expected, $mine, 'Subscribers + buyers inside the horizon; a custom status counts as a sale (F3-42).' );
		self::assertNotContains( $admin, $ids, 'An admin with no order and no subscription is never sent.' );
		self::assertNotContains( $plain, $ids );
		self::assertNotContains( $old_buyer, $ids, 'A sale older than the horizon does not put its buyer in scope.' );
		self::assertNotContains( $pending, $ids, 'A pending order is not a sale.' );

		$job->start();
		self::assertSame( count( $ids ), (int) $this->read_backfill_row()['total_count'], 'The progress total counts the set the walk reads.' );
	}

	public function test_with_contact_sync_switched_off_only_buyers_are_imported(): void {
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '' );

		$buyer = $this->make_user( 'bf-off-buyer@example.test', 'customer' );
		$other = $this->make_user( 'bf-off-other@example.test', 'customer' );
		$this->make_order( $buyer, 'processing', strtotime( '-1 week' ) );

		$ids = $this->job()->ids_after( 0, 10000 );

		self::assertContains( $buyer, $ids );
		self::assertNotContains( $other, $ids, 'Legitimate interest with the switch off syncs no contacts — PRO-1742.' );
	}

	public function test_the_admin_panel_count_is_the_import_total(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$this->make_user( 'bf-count-plain@example.test', 'customer' );
		$buyer = $this->make_user( 'bf-count-buyer@example.test', 'customer' );
		$this->make_order( $buyer, 'completed', strtotime( '-1 day' ) );

		$snapshot = ( new EnvDetector() )->snapshot();
		$job      = $this->job();
		$job->start();

		self::assertSame( (int) $this->read_backfill_row()['total_count'], $snapshot['storeTotals']['engineCustomers'] );
		self::assertSame( count( $job->ids_after( 0, 10000 ) ), $snapshot['storeTotals']['engineCustomers'] );
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

	/**
	 * The engine answers a logged-in shopper's storefront recommendations by
	 * the store's own customer id (contract §15 `customer_external_id`), which
	 * it only knows from the `external_id` sent here — so a re-import must
	 * carry it on every customer, not just on the live hook's.
	 */
	public function test_every_backfilled_customer_carries_the_wordpress_user_id(): void {
		$first  = $this->make_user( 'bf-ext1@example.test', 'customer' );
		$second = $this->make_user( 'bf-ext2@example.test', 'subscriber' );

		$job = $this->job();
		$job->start();
		$job->process_batch();

		$sent = array();
		foreach ( (array) ( self::$engine->state()['last_customers_payload'] ?? array() ) as $customer ) {
			$sent[ (string) $customer['email'] ] = $customer['external_id'] ?? null;
		}

		self::assertSame( (string) $first, $sent['bf-ext1@example.test'] ?? null );
		self::assertSame( (string) $second, $sent['bf-ext2@example.test'] ?? null );
		self::assertSame( '1', $sent[ strtolower( (string) get_userdata( 1 )->user_email ) ] ?? null, 'The admin is a customer too.' );
	}

	/**
	 * PRO-3821: Action Scheduler had already claimed the next tick when the
	 * merchant pressed Cancel (or Hold back). That tick must send nothing and
	 * leave the import cancelled, not flip it back to running.
	 */
	public function test_a_tick_already_claimed_when_the_merchant_cancels_sends_nothing(): void {
		$this->make_user( 'bf-cancel1@example.test', 'customer' );

		$job = $this->job();
		$job->start();
		$this->truncate_queue(); // Drop the live hook's row for the new user.
		self::$engine->reset_request_count();

		$this->cancel_through_rest( 'customers' );
		$result = $job->process_batch(); // The tick that was already claimed.

		self::assertSame( 0, $result['processed'] );
		self::assertTrue( $result['completed'], 'A cancelled import schedules no further tick.' );
		self::assertSame( 0, $this->queue_row_count(), 'Nothing was enqueued.' );
		self::assertSame( 0, self::$engine->request_count(), 'Nothing reached the engine.' );
		$row = $this->read_backfill_row();
		self::assertSame( 'cancelled', $row['status'] );
		self::assertSame( 0, (int) $row['processed_count'] );
	}

	/**
	 * PRO-3821: a cancel that lands while a batch is already sending. The batch
	 * finishes what it started, but its progress write must not undo the
	 * cancel, and the tick it reschedules sends nothing.
	 */
	public function test_a_cancel_that_lands_while_a_batch_sends_stays_cancelled(): void {
		$this->make_user( 'bf-cancel2@example.test', 'customer' );
		$this->make_user( 'bf-cancel3@example.test', 'customer' );

		$cancelled = false;
		$job       = $this->job(
			2,
			null,
			function () use ( &$cancelled ): void {
				if ( ! $cancelled ) {
					$cancelled = true;
					$this->cancel_through_rest( 'customers' );
				}
			}
		);
		$job->start();

		$job->process_batch();
		self::assertSame( 'cancelled', $this->read_backfill_row()['status'], 'The batch that was sending did not undo the cancel.' );

		self::$engine->reset_request_count();
		$next = $job->process_batch();
		self::assertTrue( $next['completed'] );
		self::assertSame( 0, self::$engine->request_count(), 'The rescheduled tick sent nothing.' );
		self::assertSame( 'cancelled', $this->read_backfill_row()['status'] );
	}

	// --- helpers --------------------------------------------------------

	/**
	 * Test double: a tunable batch size + a public window onto the protected
	 * enumerator so the A-filter (no role filter) is assertable. No declared
	 * return type so the anonymous subclass's `ids_after` stays visible.
	 */
	private function job( int $batch_size = 100, ?IngestQueue $queue = null, ?callable $before_enqueue = null ) {
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

		return new class( $queue, $flusher, $batch_size, $before_enqueue, new CustomerScope( $settings ) ) extends CustomerBackfillJob {
			private int $bs;

			/** @var callable|null */
			private $before_enqueue;

			public function __construct( IngestQueue $queue, CustomerFlusher $flusher, int $bs, ?callable $before_enqueue, CustomerScope $scope ) {
				parent::__construct( $queue, $flusher, $scope );
				$this->bs             = $bs;
				$this->before_enqueue = $before_enqueue;
			}

			protected function batch_size(): int {
				return $this->bs;
			}

			protected function enqueue_record( int $entity_id ): void {
				if ( $this->before_enqueue !== null ) {
					( $this->before_enqueue )();
				}
				parent::enqueue_record( $entity_id );
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

	/** An order of $customer_id placed at $created, without line items: the scope reads the order row only. */
	private function make_order( int $customer_id, string $status, int $created ): int {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->set_billing_email( 'bf-order-' . wp_generate_uuid4() . '@example.test' );
		$order->set_status( $status );
		$order->set_date_created( $created );
		$id                     = (int) $order->save();
		$this->created_orders[] = $id;
		return $id;
	}

	/** Write a status straight into the active order table (a custom one WooCommerce has not registered). */
	private function set_stored_status( int $order_id, string $status ): void {
		global $wpdb;
		$spec = OrderBackfillJob::table_spec( OrderBackfillJob::uses_hpos(), $wpdb->prefix );
		$wpdb->update( $spec['table'], array( $spec['status_col'] => $status ), array( $spec['id_col'] => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();
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

	/** The merchant's Cancel / Hold back, through the real REST route. */
	private function cancel_through_rest( string $job_type ): void {
		RestRequestHelper::login_as_admin();
		$response = RestRequestHelper::post( '/backfill/cancel', array( 'job_type' => $job_type ) );
		wp_set_current_user( 0 );
		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['cancelled'] );
	}

	private function queue_row_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smly_rec_event_queue" );
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
