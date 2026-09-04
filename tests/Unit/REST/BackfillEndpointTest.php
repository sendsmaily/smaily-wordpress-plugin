<?php
/**
 * BackfillEndpoint tests.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\BackfillEndpoint;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\Client;
use WP_REST_Request;

final class BackfillEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		// F3-55: a non-running contacts status computes the audience estimate
		// (ContactAudience → ContactSyncMode → get_option). checkout_optin
		// short-circuits to 0 before any $wpdb read, keeping the fake wpdb
		// fixtures untouched; the real count paths are integration-tested
		// (ContactBackfillAudienceTest).
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default = false ) => $key === 'smly_plus_contact_sync_mode' ? 'checkout_optin' : $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_start_returns_400_for_unsupported_job_type(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'magic-job' );

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 0 ) );
		$response = $endpoint->start( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array( 'contacts', 'products', 'customers', 'orders' ), $response->get_data()['supported_types'] );
	}

	public function test_start_persists_row_and_schedules_first_tick(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		$wpdb            = $this->fake_wpdb_with_state(
			array(
				'id'              => 77,
				'status'          => BackfillEndpoint::STATUS_RUNNING,
				'processed_count' => '0',
				'total_count'     => '5000',
				'started_at'      => null,
				'completed_at'    => null,
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$enqueued ): int {
				$enqueued[] = compact( 'hook', 'args', 'group' );
				return 1;
			}
		);

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 77 ) );
		$response = $endpoint->start( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 77, $response->get_data()['job_id'] );
		self::assertSame( 5000, $response->get_data()['total'] );

		self::assertCount( 1, $enqueued );
		self::assertSame( BackfillEndpoint::TICK_HOOK, $enqueued[0]['hook'] );
		self::assertSame( 'contacts', $enqueued[0]['args']['job_type'] );
	}

	/**
	 * PRO-1715: when start() already closed the run (an empty contact audience
	 * has nothing to walk) the endpoint must report the terminal status and
	 * leave no tick behind — a tick would reopen the row as 'running' and put
	 * the merchant back in front of a spinner.
	 */
	public function test_start_reports_a_finished_job_without_scheduling_a_tick(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		$GLOBALS['wpdb'] = $this->fake_wpdb_with_state(
			array(
				'id'              => 77,
				'status'          => BackfillEndpoint::STATUS_COMPLETED,
				'processed_count' => '5000',
				'total_count'     => '5000',
				'started_at'      => '2026-05-19 12:00:00',
				'completed_at'    => '2026-05-19 12:00:00',
			)
		);

		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$enqueued ): int {
				$enqueued[] = compact( 'hook', 'args', 'group' );
				return 1;
			}
		);

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 77 ) );
		$response = $endpoint->start( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( BackfillEndpoint::STATUS_COMPLETED, $response->get_data()['status'] );
		self::assertSame( array(), $enqueued );
	}

	public function test_status_returns_idle_when_no_row_exists(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		$GLOBALS['wpdb'] = $this->fake_wpdb_with_state( null );

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 0 ) );
		$response = $endpoint->status( $request );

		self::assertSame( 'idle', $response->get_data()['status'] );
		self::assertSame( 0, $response->get_data()['total'] );
		self::assertNull( $response->get_data()['eta_seconds'] );
	}

	public function test_status_computes_percent_and_eta(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		$GLOBALS['wpdb'] = $this->fake_wpdb_with_state(
			array(
				'id'              => 77,
				'status'          => 'running',
				'processed_count' => '50',
				'total_count'     => '200',
				'started_at'      => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'completed_at'    => null,
			)
		);

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 0 ) );
		$data     = $endpoint->status( $request )->get_data();

		self::assertSame( 25, $data['percent'] );
		// 50 items in ~60s → ~0.83 items/sec; 150 remaining → ~180s ETA.
		self::assertGreaterThan( 100, $data['eta_seconds'] );
		self::assertLessThan( 300, $data['eta_seconds'] );
	}

	public function test_cancel_writes_cancelled_status_and_unschedules_ticks(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		$wpdb            = $this->fake_wpdb_for_cancel( true );
		$GLOBALS['wpdb'] = $wpdb;

		$unscheduled = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$unscheduled ): int {
				$unscheduled[] = compact( 'hook', 'args', 'group' );
				return 1;
			}
		);

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 0 ) );
		$response = $endpoint->cancel( $request );

		self::assertTrue( $response->get_data()['cancelled'] );
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( BackfillEndpoint::STATUS_CANCELLED, $wpdb->updates[0]['data']['status'] );
		self::assertCount( 1, $unscheduled );
		self::assertSame( BackfillEndpoint::TICK_HOOK, $unscheduled[0]['hook'] );
	}

	public function test_cancel_returns_false_when_no_rows_were_updated(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		$GLOBALS['wpdb'] = $this->fake_wpdb_for_cancel( false );

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 0 ) );
		$response = $endpoint->cancel( $request );

		self::assertFalse( $response->get_data()['cancelled'] );
	}

	public function test_start_returns_503_when_credentials_factory_returns_null(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'job_type', 'contacts' );

		// Factory returns null — mirrors Bootstrap's behaviour when
		// Smaily credentials aren't yet configured. The endpoint must
		// still surface a structured 503, never a 200 / exception.
		$endpoint = new BackfillEndpoint( static fn (): ?BackfillJob => null );
		$response = $endpoint->start( $request );

		self::assertSame( 503, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'not_configured', $data['error'] );
	}

	public function test_permission_check_denies_users_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$endpoint = new BackfillEndpoint( fn (): BackfillJob => $this->fake_job( 0 ) );
		$result   = $endpoint->permission_check( new WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	private function fake_job( int $stamped_id ): BackfillJob {
		return new class( $stamped_id ) extends BackfillJob {
			private int $stamped;
			public function __construct( int $id ) {
				$this->stamped = $id;
			}
			public function start( bool $reset_freshness = true ): int {
				return $this->stamped;
			}
			public function process_batch( int $batch_size = 100 ): array {
				return array(
					'processed' => 0,
					'remaining' => 0,
					'completed' => true,
				);
			}
		};
	}

	/**
	 * @param array<string, mixed>|null $state_row
	 */
	private function fake_wpdb_with_state( ?array $state_row ): object {
		return new class( $state_row ) {
			public string $prefix = 'wp_';
			public array $prepare_calls = array();
			/** @var array<string, mixed>|null */
			private ?array $state;

			public function __construct( ?array $row ) {
				$this->state = $row;
			}
			public function prepare( string $sql, ...$args ): string {
				$this->prepare_calls[] = compact( 'sql', 'args' );
				return $sql;
			}
			public function get_row( string $sql, string $output = ARRAY_A ) {
				return $this->state;
			}
		};
	}

	private function fake_wpdb_for_cancel( bool $row_existed ): object {
		return new class( $row_existed ) {
			public string $prefix     = 'wp_';
			public array $updates     = array();
			private bool $row_existed = false;

			public function __construct( bool $existed ) {
				$this->row_existed = $existed;
			}
			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				$this->updates[] = compact( 'table', 'data', 'where' );
				return $this->row_existed ? 1 : 0;
			}
		};
	}
}
