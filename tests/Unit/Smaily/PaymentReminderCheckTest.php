<?php
/**
 * PaymentReminderCheck tests (CON-328) — the payment reminder's wait ends:
 * only an order that still waits for payment gets a queue row.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\PaymentReminderCheck;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\WorkflowMatch;

final class PaymentReminderCheckTest extends TestCase {

	/** @var array<int, \WC_Order> */
	private array $orders = array();

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->orders  = array();
		$this->options = array();

		$orders = &$this->orders;
		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( &$orders ) {
				return $orders[ $id ] ?? false;
			}
		);
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$options ) {
				return $options[ $key ] ?? $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schedule_waits_the_merchants_minutes_in_its_own_group_with_the_order_id_only(): void {
		$this->options[ TransactionalGate::OPTION_PAYMENT_REMINDER_DELAY_MINUTES ] = 25;
		$calls = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $at, string $hook, array $args, string $group ) use ( &$calls ): int {
				$calls[] = compact( 'at', 'hook', 'args', 'group' );
				return 7;
			}
		);

		$before = time();
		self::assertTrue( PaymentReminderCheck::schedule( 61 ) );

		self::assertCount( 1, $calls );
		self::assertSame( PaymentReminderCheck::HOOK, $calls[0]['hook'] );
		self::assertSame( array( 61 ), $calls[0]['args'], 'No email or name in the action.' );
		self::assertSame( PaymentReminderCheck::AS_GROUP, $calls[0]['group'] );
		self::assertGreaterThanOrEqual( $before + 25 * MINUTE_IN_SECONDS, $calls[0]['at'] );
		self::assertLessThanOrEqual( time() + 25 * MINUTE_IN_SECONDS, $calls[0]['at'] );
	}

	/**
	 * @dataProvider orders_still_waiting
	 */
	public function test_an_order_still_waiting_for_payment_gets_its_reminder_queued_due_now( string $status ): void {
		$order            = $this->order( 62, 'checkout', $status, true );
		$this->orders[62] = $order;
		$flusher          = $this->recording_flusher();

		( new PaymentReminderCheck( $this->gate( '', new WorkflowMatch( 8181, 'transactional' ) ), $flusher ) )->run( 62 );

		self::assertSame( array( array( TransactionalGate::TRIGGER_PAYMENT_REMINDER, 62, 8181, $status, 0 ) ), $flusher->scheduled );
		self::assertSame( array(), $flusher->skips );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function orders_still_waiting(): array {
		return array(
			'pending' => array( 'pending' ),
			'failed'  => array( 'failed' ),
		);
	}

	/**
	 * @dataProvider orders_not_waiting
	 */
	public function test_an_order_that_no_longer_waits_leaves_nothing( string $created_via, string $status, bool $needs_payment, bool $anonymized ): void {
		$order = $this->order( 63, $created_via, $status, $needs_payment );
		if ( $anonymized ) {
			$order->update_meta_data( '_anonymized', 'yes' );
		}
		$this->orders[63] = $order;
		$flusher          = $this->recording_flusher();

		// A closed gate would log — the order is checked first.
		( new PaymentReminderCheck( $this->gate( TransactionalGate::REASON_NO_WORKFLOW, null ), $flusher ) )->run( 63 );

		self::assertSame( array(), $flusher->scheduled );
		self::assertSame( array(), $flusher->skips, 'No Event Log row.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
	 */
	public static function orders_not_waiting(): array {
		return array(
			'paid'                    => array( 'checkout', 'processing', false, false ),
			'bank transfer (on-hold)' => array( 'checkout', 'on-hold', false, false ),
			'cancelled'               => array( 'store-api', 'cancelled', false, false ),
			'nothing to pay'          => array( 'checkout', 'pending', false, false ),
			'made in wp-admin'        => array( 'admin', 'pending', true, false ),
			'erased meanwhile'        => array( 'checkout', 'pending', true, true ),
		);
	}

	public function test_an_order_that_is_gone_leaves_nothing(): void {
		$flusher = $this->recording_flusher();

		( new PaymentReminderCheck( $this->gate( '', new WorkflowMatch( 1, 'transactional' ) ), $flusher ) )->run( 64 );

		self::assertSame( array(), $flusher->scheduled );
		self::assertSame( array(), $flusher->skips );
	}

	public function test_a_reminder_that_cannot_go_out_says_why_like_the_other_transactional_emails(): void {
		$this->orders[65] = $this->order( 65, 'checkout', 'pending', true );
		$flusher          = $this->recording_flusher();

		( new PaymentReminderCheck( $this->gate( TransactionalGate::REASON_NO_WORKFLOW, null ), $flusher ) )->run( 65 );

		self::assertSame( array(), $flusher->scheduled );
		self::assertSame( array( array( TransactionalGate::TRIGGER_PAYMENT_REMINDER, 65, TransactionalGate::REASON_NO_WORKFLOW ) ), $flusher->skips );
	}

	public function test_a_reminder_switched_off_meanwhile_leaves_nothing(): void {
		$this->orders[66] = $this->order( 66, 'checkout', 'pending', true );
		$flusher          = $this->recording_flusher();

		( new PaymentReminderCheck( $this->gate( TransactionalGate::REASON_TRIGGER_OFF, null ), $flusher ) )->run( 66 );

		self::assertSame( array(), $flusher->scheduled );
		self::assertSame( array(), $flusher->skips );
	}

	// --- helpers -------------------------------------------------------------

	private function gate( string $reason, ?WorkflowMatch $match ): TransactionalGate {
		return new class( $reason, $match ) extends TransactionalGate {
			private string $reason;
			private ?WorkflowMatch $match;
			public function __construct( string $reason, ?WorkflowMatch $match ) {
				$this->reason = $reason;
				$this->match  = $match;
			}
			public function evaluate( string $trigger_type, ?\WC_Order $order = null ): array {
				return array( $this->reason, $this->match );
			}
		};
	}

	private function recording_flusher(): TransactionalFlusher {
		return new class() extends TransactionalFlusher {
			public array $scheduled = array();
			public array $skips     = array();
			public function __construct() {}
			public function schedule( string $trigger_type, \WC_Order $order, WorkflowMatch $match, string $to_status, int $delay_seconds ): void {
				$this->scheduled[] = array( $trigger_type, $order->get_id(), $match->workflow_id, $to_status, $delay_seconds );
			}
			public function record_skip( string $trigger_type, int $order_id, string $reason ): void {
				$this->skips[] = array( $trigger_type, $order_id, $reason );
			}
		};
	}

	private function order( int $id, string $created_via, string $status, bool $needs_payment ): \WC_Order {
		return new class( $id, $created_via, $status, $needs_payment ) extends \WC_Order {
			private int $id;
			private string $created_via;
			private string $status;
			private bool $needs_payment;
			private array $meta = array();

			public function __construct( int $id, string $created_via, string $status, bool $needs_payment ) {
				$this->id            = $id;
				$this->created_via   = $created_via;
				$this->status        = $status;
				$this->needs_payment = $needs_payment;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_created_via( $context = 'view' ) {
				return $this->created_via;
			}

			public function get_status( $context = 'view' ): string {
				return $this->status;
			}

			public function needs_payment() {
				return $this->needs_payment;
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}
		};
	}
}
