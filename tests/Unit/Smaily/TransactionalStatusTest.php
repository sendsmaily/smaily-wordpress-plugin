<?php
/**
 * TransactionalStatus tests (PRO-4091) — what each confirmation card's
 * status line is built from: the send gate's own reason, the last real send,
 * and the "no recent order reached a ticked status" warning.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\TransactionalStatus;

final class TransactionalStatusTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array( TransactionalGate::OPTION_SHIPPED_STATUSES => array( 'completed' ) );
		$opts          = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$opts ) {
				return $opts[ $key ] ?? $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_each_confirmation_carries_the_gates_own_reason_and_its_last_send(): void {
		$status = $this->status(
			array(
				TransactionalGate::TRIGGER_ORDER_CONFIRMATION    => TransactionalGate::REASON_NO_WORKFLOW,
				TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION => TransactionalGate::REASON_PASSWORD_UNREADABLE,
			),
			array( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION => '2026-10-15 08:30:00' ),
			array()
		);

		self::assertSame(
			array(
				TransactionalGate::TRIGGER_ORDER_CONFIRMATION    => array(
					'reason'                 => TransactionalGate::REASON_NO_WORKFLOW,
					'workflowResolved'       => false,
					'lastSentAt'             => '2026-10-15 08:30:00',
					'untickedRecentStatuses' => array(),
				),
				TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION => array(
					'reason'                 => TransactionalGate::REASON_PASSWORD_UNREADABLE,
					'workflowResolved'       => true,
					'lastSentAt'             => '',
					'untickedRecentStatuses' => array(),
				),
			),
			$status->snapshot()
		);
	}

	public function test_a_ready_shipping_confirmation_lists_the_statuses_recent_orders_reached_when_none_is_ticked(): void {
		// The custom-fulfilment-status store: orders move to label-printed,
		// which isn't ticked, so nothing will ever send.
		$snapshot = $this->status( array(), array(), array( 'processing', 'label-printed' ) )->snapshot();

		self::assertSame( array( 'processing', 'label-printed' ), $snapshot[ TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ]['untickedRecentStatuses'] );
		self::assertSame( array(), $snapshot[ TransactionalGate::TRIGGER_ORDER_CONFIRMATION ]['untickedRecentStatuses'], 'Order confirmation fires on checkout, not on a ticked status.' );
	}

	public function test_no_warning_when_a_recent_order_reached_a_ticked_status(): void {
		$snapshot = $this->status( array(), array(), array( 'processing', 'completed' ) )->snapshot();

		self::assertSame( array(), $snapshot[ TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ]['untickedRecentStatuses'] );
	}

	public function test_no_warning_without_recent_orders(): void {
		$snapshot = $this->status( array(), array(), array() )->snapshot();

		self::assertSame( array(), $snapshot[ TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ]['untickedRecentStatuses'] );
	}

	public function test_a_blocked_shipping_confirmation_names_its_block_and_skips_the_order_lookup(): void {
		$status = $this->status(
			array( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION => TransactionalGate::REASON_TRIGGER_OFF ),
			array(),
			array( 'label-printed' )
		);

		$snapshot = $status->snapshot();

		self::assertSame( array(), $snapshot[ TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ]['untickedRecentStatuses'] );
		self::assertSame( 0, $status->order_lookups, 'The first blocking reason is what the merchant needs; no order query for it.' );
	}

	/**
	 * @param array<string, string>  $reasons   trigger → closed reason ('' / absent = open).
	 * @param array<string, string>  $last_sent event type → last real send.
	 * @param array<int, string>     $recent    Bare statuses recent orders sit at.
	 */
	private function status( array $reasons, array $last_sent, array $recent ): TransactionalStatus {
		$gate = new class( $reasons ) extends TransactionalGate {
			/** @var array<string, string> */
			private array $reasons;
			public function __construct( array $reasons ) {
				$this->reasons = $reasons;
			}
			public function closed_reason( string $trigger_type, ?\WC_Order $order = null ): string {
				return $this->reasons[ $trigger_type ] ?? '';
			}
		};

		return new class( $gate, $last_sent, $recent ) extends TransactionalStatus {
			public int $order_lookups = 0;
			/** @var array<string, string> */
			private array $last_sent;
			/** @var array<int, string> */
			private array $recent;
			public function __construct( TransactionalGate $gate, array $last_sent, array $recent ) {
				parent::__construct( $gate );
				$this->last_sent = $last_sent;
				$this->recent    = $recent;
			}
			protected function last_sent_at( array $event_types ): array {
				return array_intersect_key( $this->last_sent, array_flip( $event_types ) );
			}
			protected function recent_order_statuses(): array {
				++$this->order_lookups;
				return $this->recent;
			}
		};
	}
}
