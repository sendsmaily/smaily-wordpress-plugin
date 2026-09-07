<?php
/**
 * TransactionalFlusher tests (PRO-1504 Stage 2) — send_now() sync dispatch,
 * flush() retry, terminal/transient error split, fail-open, and the
 * once-per-order-per-type meta guard.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\WorkflowMatch;

final class TransactionalFlusherTest extends TestCase {

	/** @var array<int, \WC_Order> */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->orders = array();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$orders = &$this->orders;
		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( &$orders ) {
				return $orders[ $id ] ?? false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_flush_scopes_pending_to_the_two_transactional_event_types(): void {
		$queue = $this->fake_queue( array() );

		( new TransactionalFlusher( $queue, static fn () => null ) )->flush();

		self::assertSame(
			array(
				TransactionalFlusher::DEFAULT_BATCH_SIZE,
				array( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION, TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION ),
				array(),
			),
			$queue->last_pending_args,
			'The transactional drain must scope pending() to its own two event types — other flushers own the rest.'
		);
	}

	public function test_send_now_success_enqueues_dispatches_and_marks_meta_sent(): void {
		$order = $this->fake_order( 501, 'buyer@example.test' );
		$this->orders[501] = $order;

		$queue  = $this->fake_queue( array() );
		$client = $this->createMock( Client::class );
		$client->expects( self::once() )
			->method( 'send_message' )
			->with( 4242, 'buyer@example.test', array( 'order_number' => '1' ) )
			->willReturn( array( 'code' => 101 ) );
		$client->method( 'last_exchange' )->willReturn(
			array( 'request' => array( 'endpoint' => 'message/send' ), 'response' => array( 'http' => 200, 'body' => array( 'code' => 101 ) ) )
		);

		$flusher = new TransactionalFlusher( $queue, static fn () => $client );
		$flusher->send_now(
			TransactionalGate::TRIGGER_ORDER_CONFIRMATION,
			$order,
			new WorkflowMatch( 4242, 'transactional' ),
			array( 'order_number' => '1' )
		);

		self::assertSame( array( 1 ), $queue->marked_sent, 'The row must exist in the Event Log and be marked sent.' );
		self::assertSame( TransactionalFlusher::META_STATUS_SENT, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
		self::assertStringContainsString( '"endpoint":"message\/send"', (string) $queue->exchanges[1]['sent'] );
	}

	public function test_send_now_without_a_billing_email_is_a_noop(): void {
		$order = $this->fake_order( 502, '' );

		$queue = $this->fake_queue( array() );
		$client = $this->createMock( Client::class );
		$client->expects( self::never() )->method( 'send_message' );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->send_now(
			TransactionalGate::TRIGGER_ORDER_CONFIRMATION,
			$order,
			new WorkflowMatch( 1, 'transactional' ),
			array()
		);

		self::assertSame( array(), $queue->enqueued, 'No recipient → nothing enqueued, nothing attempted.' );
	}

	public function test_terminal_response_marks_failed_and_fails_open_order_confirmation(): void {
		$order = $this->fake_order( 503, 'buyer@example.test' );
		$this->orders[503] = $order;

		$queue  = $this->fake_queue( array() );
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willReturn( array( 'code' => 203, 'message' => 'validation error' ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		$fired = $this->stub_native_mailer( 'WC_Email_Customer_Processing_Order' );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->send_now(
			TransactionalGate::TRIGGER_ORDER_CONFIRMATION,
			$order,
			new WorkflowMatch( 1, 'transactional' ),
			array()
		);

		self::assertSame( 1, $queue->marked_failed[0]['id'] );
		self::assertStringContainsString( 'smaily_response_code_203', $queue->marked_failed[0]['error'] );
		self::assertSame( TransactionalFlusher::META_STATUS_FAILED_OPEN, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
		self::assertSame( array( 503 ), $fired->calls, 'Fail-open must re-fire the native processing-order email.' );
	}

	public function test_terminal_response_fails_open_the_completed_order_email_when_to_status_is_completed(): void {
		$order = $this->fake_order( 504, 'buyer@example.test' );
		$this->orders[504] = $order;

		$queue  = $this->fake_queue( array() );
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willReturn( array( 'code' => 221 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		$fired = $this->stub_native_mailer( 'WC_Email_Customer_Completed_Order' );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->send_now(
			TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION,
			$order,
			new WorkflowMatch( 1, 'transactional' ),
			array(),
			'completed'
		);

		self::assertSame( array( 504 ), $fired->calls );
	}

	public function test_terminal_response_with_a_custom_shipped_status_does_not_fire_a_native_email(): void {
		// A custom shipped status ('shipped') has no native WC email to
		// suppress or re-fire — fail-open just records the failure.
		$order = $this->fake_order( 505, 'buyer@example.test' );
		$this->orders[505] = $order;

		$queue  = $this->fake_queue( array() );
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willReturn( array( 'code' => 221 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		$fired = $this->stub_native_mailer( 'WC_Email_Customer_Completed_Order' );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->send_now(
			TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION,
			$order,
			new WorkflowMatch( 1, 'transactional' ),
			array(),
			'shipped'
		);

		self::assertSame( array(), $fired->calls, 'No native email exists for a custom shipped status.' );
		self::assertSame( TransactionalFlusher::META_STATUS_FAILED_OPEN, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) ), 'The failure is still recorded via the meta + mark_failed row.' );
	}

	public function test_fail_open_meta_guard_stops_a_manually_retried_row_from_double_firing(): void {
		$order = $this->fake_order( 506, 'buyer@example.test' );
		$order->update_meta_data( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), TransactionalFlusher::META_STATUS_FAILED_OPEN );
		$this->orders[506] = $order;

		$queue  = $this->fake_queue(
			array(
				array(
					'id'         => 9,
					'event_type' => TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					'entity_id'  => '506',
					'payload'    => json_encode( array( 'to' => 'buyer@example.test', 'workflow_id' => 1, 'account_key' => 'transactional', 'context' => array() ) ),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willReturn( array( 'code' => 203 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		$fired = $this->stub_native_mailer( 'WC_Email_Customer_Processing_Order' );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->flush();

		self::assertSame( array(), $fired->calls, 'Already failed-open once — a manual retry must not double-fire the native email.' );
	}

	public function test_api_exception_records_attempt_and_leaves_meta_queued(): void {
		$order = $this->fake_order( 507, 'buyer@example.test' );
		$this->orders[507] = $order;

		$queue  = $this->fake_queue( array() );
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willThrowException( new ApiException( 'rate limited', 429 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 429 ) ) );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->send_now(
			TransactionalGate::TRIGGER_ORDER_CONFIRMATION,
			$order,
			new WorkflowMatch( 1, 'transactional' ),
			array()
		);

		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( array(), $queue->marked_failed );
		self::assertSame( 1, $queue->attempts[0]['id'] );
		self::assertSame(
			TransactionalFlusher::META_STATUS_QUEUED,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ),
			'A transient failure leaves the meta guard at "queued" — the WC hook must not double-enqueue meanwhile.'
		);
	}

	public function test_unexpected_throwable_from_the_client_factory_is_terminal_and_fails_open(): void {
		$order = $this->fake_order( 508, 'buyer@example.test' );
		$this->orders[508] = $order;

		$queue = $this->fake_queue( array() );
		$this->stub_native_mailer( 'WC_Email_Customer_Processing_Order' );

		( new TransactionalFlusher(
			$queue,
			static function (): Client {
				throw new \RuntimeException( 'Smaily credentials are not configured' );
			}
		) )->send_now(
			TransactionalGate::TRIGGER_ORDER_CONFIRMATION,
			$order,
			new WorkflowMatch( 1, 'transactional' ),
			array()
		);

		self::assertStringContainsString( 'RuntimeException', $queue->marked_failed[0]['error'] );
		self::assertSame( TransactionalFlusher::META_STATUS_FAILED_OPEN, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
	}

	public function test_a_row_past_the_retry_ceiling_fails_open_without_calling_the_api(): void {
		// PRO-1519: a row that has been sitting pending() past
		// RETRY_CEILING_SECONDS must terminal-fail (mark_failed + fail-open)
		// on the NEXT flush tick, without even attempting the call — a
		// transient-only failure history never exhausts otherwise, and the
		// customer's native email would stay suppressed forever.
		$order = $this->fake_order( 509, 'buyer@example.test' );
		$this->orders[509] = $order;

		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 21,
					'event_type' => TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					'entity_id'  => '509',
					'payload'    => json_encode( array( 'to' => 'buyer@example.test', 'workflow_id' => 1, 'account_key' => 'transactional', 'context' => array() ) ),
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - TransactionalFlusher::RETRY_CEILING_SECONDS - 60 ),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->expects( self::never() )->method( 'send_message' );
		$client->method( 'last_exchange' )->willReturn( null );

		$fired = $this->stub_native_mailer( 'WC_Email_Customer_Processing_Order' );

		$stats = ( new TransactionalFlusher( $queue, static fn () => $client ) )->flush();

		self::assertSame( array( 'id' => 21, 'error' => 'retry_ceiling_exceeded' ), $queue->marked_failed[0] );
		self::assertSame( array(), $queue->attempts, 'A ceiling-expired row must not be recorded as another transient attempt.' );
		self::assertSame( TransactionalFlusher::META_STATUS_FAILED_OPEN, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
		self::assertSame( array( 509 ), $fired->calls, 'Fail-open must re-fire the native email once the ceiling forces a terminal failure.' );
		self::assertSame( array( 'processed' => 1, 'sent' => 0, 'failed' => 1, 'retried' => 0 ), $stats );
	}

	public function test_a_row_still_within_the_retry_ceiling_keeps_retrying_as_normal(): void {
		// Boundary sanity: a row that is old but NOT yet past the ceiling
		// must follow the ordinary transient-failure path (record_attempt),
		// not be force-failed early.
		$order = $this->fake_order( 510, 'buyer@example.test' );
		$this->orders[510] = $order;

		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 22,
					'event_type' => TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					'entity_id'  => '510',
					'payload'    => json_encode( array( 'to' => 'buyer@example.test', 'workflow_id' => 1, 'account_key' => 'transactional', 'context' => array() ) ),
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - TransactionalFlusher::RETRY_CEILING_SECONDS + 60 ),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->expects( self::once() )->method( 'send_message' )->willThrowException( new ApiException( 'temporary outage', 503 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 503 ) ) );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->flush();

		self::assertSame( array(), $queue->marked_failed );
		self::assertSame( 22, $queue->attempts[0]['id'] );
	}

	public function test_the_retry_ceiling_never_applies_to_a_non_transactional_event_type(): void {
		// PRO-1519 scoping: marketing-side queue rows (contact.sync,
		// automation.* etc.) keep the queue's existing unbounded-retry
		// convention untouched — the ceiling lives ONLY inside this class
		// and is itself gated on the two transactional event types, not
		// merely on which flusher happened to read the row.
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 23,
					'event_type' => 'contact.sync',
					'entity_id'  => '999',
					'payload'    => json_encode( array( 'to' => 'buyer@example.test', 'workflow_id' => 1, 'account_key' => 'transactional', 'context' => array() ) ),
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - TransactionalFlusher::RETRY_CEILING_SECONDS - ( 10 * DAY_IN_SECONDS ) ),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->expects( self::once() )->method( 'send_message' )->willThrowException( new ApiException( 'temporary outage', 503 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 503 ) ) );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->flush();

		self::assertSame( array(), $queue->marked_failed, 'A non-transactional-type row must never be force-failed by the ceiling.' );
		self::assertSame( 23, $queue->attempts[0]['id'] );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * @return object{calls: array<int,int>} The stubbed native email; ->calls
	 *                                       collects the order ids it was
	 *                                       triggered with.
	 */
	private function stub_native_mailer( string $wc_email_class ) {
		$email = new class() {
			public array $calls = array();
			public function trigger( $order_id ): void {
				$this->calls[] = (int) $order_id;
			}
		};

		$mailer         = new \stdClass();
		$mailer->emails = array( $wc_email_class => $email );

		Functions\when( 'WC' )->justReturn(
			new class( $mailer ) {
				private $mailer;
				public function __construct( $mailer ) {
					$this->mailer = $mailer;
				}
				public function mailer() {
					return $this->mailer;
				}
			}
		);

		return $email;
	}

	private function fake_order( int $id, string $email ): \WC_Order {
		return new class( $id, $email ) extends \WC_Order {
			private int $id;
			private string $email;
			private array $meta = array();

			public function __construct( int $id, string $email ) {
				$this->id    = $id;
				$this->email = $email;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_billing_email( $context = 'view' ): string {
				return $this->email;
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function save() {
				return $this->id;
			}
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	public function test_enqueue_resend_queues_a_second_confirmation_without_moving_the_meta_guard(): void {
		// PRO-2324: the merchant asked for this one, so the once-per-order
		// marker is neither consulted nor changed — it still reads 'sent',
		// which is what keeps a status transition from sending a third.
		Functions\when( 'as_next_scheduled_action' )->justReturn( 4242 );

		$order = $this->fake_order( 601, 'buyer@example.test' );
		$order->update_meta_data( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ), TransactionalFlusher::META_STATUS_SENT );
		$this->orders[601] = $order;

		$queue   = $this->fake_queue( array() );
		$flusher = new TransactionalFlusher(
			$queue,
			static function (): Client {
				self::fail( 'enqueue_resend() only queues — the send belongs to the next scheduled pass.' );
			}
		);

		$id = $flusher->enqueue_resend(
			TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION,
			$order,
			new WorkflowMatch( 5151, 'transactional' ),
			array( 'order_number' => '7' ),
			'shipped'
		);

		self::assertSame( 1, $id );
		self::assertCount( 1, $queue->enqueued );
		self::assertSame( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION, $queue->enqueued[0]['event_type'] );
		self::assertSame( '601', $queue->enqueued[0]['entity_id'] );
		self::assertTrue( $queue->enqueued[0]['payload'][ TransactionalFlusher::PAYLOAD_KEY_RESEND ] );
		self::assertSame( array( 'order_number' => '7' ), $queue->enqueued[0]['payload']['context'] );
		self::assertSame( 'shipped', $queue->enqueued[0]['payload']['to_status'] );
		self::assertSame(
			TransactionalFlusher::META_STATUS_SENT,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) ),
			'The bypass is scoped to this enqueue — the marker the status path reads is untouched.'
		);
	}

	public function test_a_failed_resend_neither_fails_open_nor_moves_the_meta_guard(): void {
		// PRO-2324: fail-open exists so the shopper is never left without a
		// confirmation. On a re-send they already have one, so a failure must
		// not mail them WooCommerce's own copy on top — the mark_failed row
		// is the whole record.
		$order = $this->fake_order( 602, 'buyer@example.test' );
		$order->update_meta_data( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), TransactionalFlusher::META_STATUS_SENT );
		$this->orders[602] = $order;

		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 12,
					'event_type' => TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					'entity_id'  => '602',
					'payload'    => json_encode(
						array(
							'to'          => 'buyer@example.test',
							'workflow_id' => 1,
							'account_key' => 'transactional',
							'context'     => array(),
							TransactionalFlusher::PAYLOAD_KEY_RESEND => true,
						)
					),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willReturn( array( 'code' => 203 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		$fired = $this->stub_native_mailer( 'WC_Email_Customer_Processing_Order' );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->flush();

		self::assertSame( 12, $queue->marked_failed[0]['id'], 'The failure is still recorded.' );
		self::assertSame( array(), $fired->calls, 'A re-send failure must not mail the native WooCommerce email.' );
		self::assertSame(
			TransactionalFlusher::META_STATUS_SENT,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) )
		);
	}

	public function test_a_successful_resend_writes_nothing_to_the_meta_guard(): void {
		// Seeded with a value a normal send would overwrite with 'sent', so
		// the assertion below can tell "left alone" from "written again".
		$order = $this->fake_order( 603, 'buyer@example.test' );
		$order->update_meta_data( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), TransactionalFlusher::META_STATUS_QUEUED );
		$this->orders[603] = $order;

		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 13,
					'event_type' => TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					'entity_id'  => '603',
					'payload'    => json_encode(
						array(
							'to'          => 'buyer@example.test',
							'workflow_id' => 1,
							'account_key' => 'transactional',
							'context'     => array(),
							TransactionalFlusher::PAYLOAD_KEY_RESEND => true,
						)
					),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->method( 'send_message' )->willReturn( array( 'code' => 101 ) );
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		( new TransactionalFlusher( $queue, static fn () => $client ) )->flush();

		self::assertSame( array( 13 ), $queue->marked_sent );
		self::assertSame(
			TransactionalFlusher::META_STATUS_QUEUED,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ),
			'A re-send repeats a confirmation the marker already records — it must not write it again.'
		);
	}

	public function test_revive_leaves_the_send_to_the_next_scheduled_pass(): void {
		// PRO-2323: revive() never asks for a run-now flush. This flusher's
		// recurring action is always scheduled, so the dedup guard always wins
		// and the revived row goes out on the next scheduled pass. The age
		// restart (PRO-1519) still has to happen.
		Functions\when( 'as_next_scheduled_action' )->justReturn( 4242 );

		$scheduled = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () use ( &$scheduled ): int {
				$scheduled = true;
				return 1;
			}
		);

		$queue = $this->fake_queue( array() );
		TransactionalFlusher::revive( $queue, array( 11, 12 ) );

		self::assertSame( array( 11, 12 ), $queue->age_restarted );
		self::assertFalse( $scheduled, 'A flush already on the schedule must not be duplicated.' );
	}

	private function fake_queue( array $events ): EventQueue {
		return new class( $events ) extends EventQueue {
			private array $events;
			private int $next_id = 1;

			public array $marked_sent   = array();
			public array $marked_failed = array();
			public array $attempts      = array();
			public array $enqueued      = array();

			/** @var array<int, mixed> */
			public array $last_pending_args = array();

			/** @var array<int, array{sent: ?string, response: ?string}> */
			public array $exchanges = array();

			/** @var int[] */
			public array $age_restarted = array();

			public function __construct( array $events ) {
				$this->events = $events;
			}

			public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
				$id              = $this->next_id++;
				$this->enqueued[] = compact( 'id', 'event_type', 'entity_id', 'payload' );
				return $id;
			}

			public function pending( int $limit = 50, ?array $only_types = null, array $exclude_types = array() ): array {
				$this->last_pending_args = array( $limit, $only_types, $exclude_types );
				return $this->events;
			}

			public function mark_sent( int $id ): void {
				$this->marked_sent[] = $id;
			}

			public function mark_failed( int $id, string $error ): void {
				$this->marked_failed[] = compact( 'id', 'error' );
			}

			public function record_attempt( int $id, string $error, int $retry_in_seconds = 0 ): void {
				$this->attempts[] = compact( 'id', 'error', 'retry_in_seconds' );
			}

			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( 'sent' => $sent_payload, 'response' => $last_response );
			}

			public function restart_age( array $ids ): int {
				$this->age_restarted = $ids;
				return count( $ids );
			}
		};
	}
}
