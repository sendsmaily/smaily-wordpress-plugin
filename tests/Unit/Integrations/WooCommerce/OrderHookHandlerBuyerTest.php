<?php
/**
 * OrderHookHandler — the registered buyer's customer row (CON-246): queued
 * with the order, once per order, skipped for a guest, a buyer that already
 * has a pending customer row, a buyer out of scope, and while disconnected.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class OrderHookHandlerBuyerTest extends TestCase {

	/** @var array<int, int> order id → customer id (0 = guest). */
	private array $customers = array();

	/** @var array<int, bool> orders whose buyer is marked queued. */
	private array $marked = array();

	/** @var string[] user ids with a pending customer row. */
	private array $pending_customers = array();

	/** @var int[]|null user ids in scope; null = every user. */
	private ?array $in_scope = null;

	protected function setUp(): void {
		parent::setUp();
		OrderHookHandler::reset_seen();
	}

	protected function tearDown(): void {
		OrderHookHandler::reset_seen();
		parent::tearDown();
	}

	public function test_a_registered_buyers_order_queues_the_customer_after_the_order(): void {
		$this->customers[55] = 67;
		$queue               = $this->fake_queue();

		$this->handler( $queue, true )->on_order_status_changed( 55, 'pending', 'processing' );

		self::assertSame(
			array(
				array( OrderFlusher::EVENT_ORDER_UPSERT, '55', OrderFlusher::FLUSH_HOOK ),
				array( CustomerFlusher::EVENT_CUSTOMER_UPSERT, '67', CustomerFlusher::FLUSH_HOOK ),
			),
			array_map(
				static function ( array $row ): array {
					return array( $row['type'], $row['entity_id'], $row['flush_hook'] );
				},
				$queue->enqueued
			)
		);
		self::assertTrue( $this->marked[55] ?? false, 'The order remembers that its buyer was queued.' );
	}

	public function test_a_later_status_change_of_the_same_order_does_not_queue_the_customer_again(): void {
		$this->customers[55] = 67;
		$queue               = $this->fake_queue();
		$handler             = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'processing' );
		OrderHookHandler::reset_seen(); // A later request.
		$handler->on_order_status_changed( 55, 'processing', 'completed' );

		self::assertSame( 1, $this->count_customer_rows( $queue ), 'Once per order, not per status change.' );
		self::assertCount( 3, $queue->enqueued, 'The order itself is still sent on each change.' );
	}

	public function test_a_second_order_of_the_same_buyer_queues_the_customer_again(): void {
		$this->customers[55] = 67;
		$this->customers[56] = 67;
		$queue               = $this->fake_queue();
		$handler             = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'processing' );
		$handler->on_order_status_changed( 56, 'pending', 'processing' );

		self::assertSame( 2, $this->count_customer_rows( $queue ) );
	}

	public function test_a_pending_customer_row_for_the_buyer_is_not_duplicated(): void {
		$this->customers[55]     = 67;
		$this->pending_customers = array( '67' );
		$queue                   = $this->fake_queue();

		$this->handler( $queue, true )->on_order_status_changed( 55, 'pending', 'processing' );

		self::assertSame( 0, $this->count_customer_rows( $queue ) );
		self::assertTrue( $this->marked[55] ?? false, 'The waiting row covers this order — it is still marked done.' );
	}

	public function test_a_guest_order_queues_no_customer_row(): void {
		$this->customers[55] = 0;
		$queue               = $this->fake_queue();

		$this->handler( $queue, true )->on_order_status_changed( 55, 'pending', 'processing' );

		self::assertSame( 0, $this->count_customer_rows( $queue ) );
		self::assertCount( 1, $queue->enqueued, 'The order is queued as before.' );
	}

	public function test_a_buyer_out_of_scope_is_not_queued(): void {
		// An order placed before the horizon, or a deleted account.
		$this->customers[55] = 67;
		$this->in_scope      = array();
		$queue               = $this->fake_queue();

		$this->handler( $queue, true )->on_order_status_changed( 55, 'pending', 'processing' );

		self::assertSame( 0, $this->count_customer_rows( $queue ) );
	}

	public function test_no_customer_row_when_the_order_itself_is_not_queued(): void {
		$this->customers[55] = 67;
		$queue               = $this->fake_queue();

		$this->handler( $queue, true )->on_order_status_changed( 55, 'processing', 'on-hold' );
		$this->handler( $queue, false )->on_order_status_changed( 56, 'pending', 'processing' );

		self::assertSame( array(), $queue->enqueued, 'Gated like the order enqueue: a sale-state change, while connected.' );
	}

	public function test_a_partial_refund_queues_no_customer_row(): void {
		$this->customers[55] = 67;
		$queue               = $this->fake_queue();

		$this->handler( $queue, true )->on_order_partially_refunded( 55 );

		self::assertSame( 0, $this->count_customer_rows( $queue ) );
	}

	// --- doubles -------------------------------------------------------------

	private function count_customer_rows( IngestQueue $queue ): int {
		return count(
			array_filter(
				$queue->enqueued,
				static function ( array $row ): bool {
					return $row['type'] === CustomerFlusher::EVENT_CUSTOMER_UPSERT;
				}
			)
		);
	}

	private function fake_queue(): IngestQueue {
		$test = $this;
		return new class( $test ) extends IngestQueue {
			/** @var array<int, array{type:string, entity_id:string, flush_hook:?string}> */
			public array $enqueued = array();
			private OrderHookHandlerBuyerTest $test;
			public function __construct( OrderHookHandlerBuyerTest $test ) {
				$this->test = $test;
			}
			public function enqueue( string $event_type, string $entity_id, array $payload, ?string $event_uuid = null, ?string $flush_hook = null, ?string $flush_group = null ): ?int {
				$this->enqueued[] = array(
					'type'       => $event_type,
					'entity_id'  => $entity_id,
					'flush_hook' => $flush_hook,
				);
				return count( $this->enqueued );
			}
			public function has_pending_for( string $event_type, string $entity_id ): bool {
				return $event_type === CustomerFlusher::EVENT_CUSTOMER_UPSERT && $this->test->has_pending_customer( $entity_id );
			}
		};
	}

	/** @internal Read by the queue double. */
	public function has_pending_customer( string $user_id ): bool {
		return in_array( $user_id, $this->pending_customers, true );
	}

	/** @internal Read by the handler double. */
	public function customer_of( int $order_id ): int {
		return $this->customers[ $order_id ] ?? 0;
	}

	/** @internal Read by the handler double. */
	public function is_marked( int $order_id ): bool {
		return $this->marked[ $order_id ] ?? false;
	}

	/** @internal Written by the handler double. */
	public function mark( int $order_id ): void {
		$this->marked[ $order_id ] = true;
	}

	/** @internal Read by the scope double. */
	public function in_scope( int $user_id ): bool {
		return $this->in_scope === null || in_array( $user_id, $this->in_scope, true );
	}

	private function handler( IngestQueue $queue, bool $connected ): OrderHookHandler {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};
		$test  = $this;
		$scope = new class( $settings, $test ) extends CustomerScope {
			private OrderHookHandlerBuyerTest $test;
			public function __construct( RecEngineSettings $settings, OrderHookHandlerBuyerTest $test ) {
				parent::__construct( $settings );
				$this->test = $test;
			}
			public function includes_user( int $user_id ): bool {
				return $this->test->in_scope( $user_id );
			}
		};

		return new class( $queue, new OrderPayloadBuilder(), $settings, $scope, $test ) extends OrderHookHandler {
			private OrderHookHandlerBuyerTest $test;
			public function __construct( IngestQueue $queue, OrderPayloadBuilder $builder, RecEngineSettings $settings, CustomerScope $scope, OrderHookHandlerBuyerTest $test ) {
				parent::__construct( $queue, $builder, $settings, $scope );
				$this->test = $test;
			}
			protected function order_customer_id( int $order_id ): int {
				return $this->test->customer_of( $order_id );
			}
			protected function buyer_marked( int $order_id ): bool {
				return $this->test->is_marked( $order_id );
			}
			protected function mark_buyer( int $order_id ): void {
				$this->test->mark( $order_id );
			}
		};
	}
}
