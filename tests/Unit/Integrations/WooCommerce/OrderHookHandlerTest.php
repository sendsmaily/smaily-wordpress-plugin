<?php
/**
 * OrderHookHandler tests — order-status changes → order.upsert rows, the
 * "enqueue only when the mapped engine status changes" logic, the
 * is_connected gate, and per-request dedupe.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class OrderHookHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		OrderHookHandler::reset_seen();
	}

	protected function tearDown(): void {
		OrderHookHandler::reset_seen();
		parent::tearDown();
	}

	public function test_pending_to_processing_enqueues_and_routes_to_order_hook(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'processing' );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( OrderFlusher::EVENT_ORDER_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '55', $queue->enqueued[0]['entity_id'] );
		self::assertSame( OrderFlusher::FLUSH_HOOK, $queue->enqueued[0]['flush_hook'] );
		self::assertSame( OrderFlusher::AS_GROUP, $queue->enqueued[0]['flush_group'] );
	}

	public function test_processing_to_completed_enqueues(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'processing', 'completed' );

		self::assertCount( 1, $queue->enqueued );
	}

	public function test_processing_to_cancelled_enqueues(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'processing', 'cancelled' );

		self::assertCount( 1, $queue->enqueued );
	}

	public function test_processing_to_pending_skips_non_sale_target(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		// Target (pending) isn't a confirmed purchase → don't send it.
		$handler->on_order_status_changed( 55, 'processing', 'pending' );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_unmappable_to_unmappable_skips(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'failed' );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_pending_to_custom_status_enqueues_as_a_sale(): void {
		// A custom fulfilment status (a shipping plugin's `label-printed` /
		// `shipped`) now defaults THROUGH as a sale (F3-42), where the old 5-key
		// allowlist dropped it — so entering it from a non-sale status enqueues.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'label-printed' );

		self::assertCount( 1, $queue->enqueued, 'A custom status is a sale now — entering it from pending enqueues.' );
	}

	public function test_custom_status_to_completed_enqueues(): void {
		// The pilot case: label-printed → completed. to=completed (sale),
		// from=label-printed (now a sale → processing), engine status changes →
		// enqueue (the order finally reaches the engine as completed).
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'label-printed', 'completed' );

		self::assertCount( 1, $queue->enqueued );
	}

	public function test_on_hold_to_processing_enqueues_now_that_on_hold_is_non_sale(): void {
		// F3-42 reversal of F3-22: on-hold maps to '' (payment not captured →
		// not a sale). So on-hold → processing is a real engine-status change
		// ('' → processing) → enqueue, where it used to be skipped.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'on-hold', 'processing' );

		self::assertCount( 1, $queue->enqueued );
	}

	public function test_processing_to_on_hold_skips_non_sale_target(): void {
		// on-hold is not a sale (F3-42) → a transition INTO it isn't sent.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'processing', 'on-hold' );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_no_enqueue_when_not_connected(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, false );

		$handler->on_order_status_changed( 55, 'pending', 'completed' );

		self::assertSame( array(), $queue->enqueued, 'No rec-engine tenant connected → the gate is shut.' );
	}

	public function test_invalid_order_id_is_ignored(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 0, 'pending', 'completed' );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_repeat_change_in_one_request_is_deduped(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'processing' );
		$handler->on_order_status_changed( 55, 'processing', 'completed' );

		self::assertCount( 1, $queue->enqueued, 'Multiple status changes for one order in a request collapse to one row.' );
	}

	public function test_a_partial_refund_enqueues_a_resync_although_no_status_changed(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		// wc_create_refund() fires this with ( $order_id, $refund_id ) — we take
		// only the order id (Bootstrap registers 1 accepted arg). The order
		// keeps its status, so nothing else would ever re-sync it.
		$handler->on_order_partially_refunded( 55 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( OrderFlusher::EVENT_ORDER_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '55', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'No payload — the flusher re-derives the returns from the order.' );
		self::assertSame( OrderFlusher::FLUSH_HOOK, $queue->enqueued[0]['flush_hook'] );
	}

	public function test_a_partial_refund_is_skipped_while_no_engine_is_connected(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, false );

		$handler->on_order_partially_refunded( 55 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_a_refund_after_a_status_change_reuses_the_same_row(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_order_status_changed( 55, 'pending', 'processing' );
		$handler->on_order_partially_refunded( 55 );

		self::assertCount( 1, $queue->enqueued, 'One row per order per request — it is built fresh at send time anyway.' );
	}

	// --- doubles -------------------------------------------------------------

	private function fake_queue(): IngestQueue {
		return new class() extends IngestQueue {
			/** @var array<int, array{type:string, entity_id:string, payload:array<string,mixed>, flush_hook:?string, flush_group:?string}> */
			public array $enqueued = array();

			public function enqueue( string $event_type, string $entity_id, array $payload, ?string $event_uuid = null, ?string $flush_hook = null, ?string $flush_group = null ): ?int {
				$this->enqueued[] = array(
					'type'        => $event_type,
					'entity_id'   => $entity_id,
					'payload'     => $payload,
					'flush_hook'  => $flush_hook,
					'flush_group' => $flush_group,
				);
				return count( $this->enqueued );
			}
		};
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

		return new OrderHookHandler( $queue, new OrderPayloadBuilder(), $settings );
	}
}
