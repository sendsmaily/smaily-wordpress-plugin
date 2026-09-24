<?php
/**
 * TransactionalSuppression tests (PRO-1504 Stage 2, design point 6) — the
 * WC-email suppression filters and the fail-open bypass mechanic.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\TransactionalSuppression;
use Smaily\Connect\Smaily\WorkflowMatch;

final class TransactionalSuppressionTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		$opts = &$this->options;
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

	public function test_processing_order_is_suppressed_while_the_gate_is_open(): void {
		$suppression = new TransactionalSuppression( $this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) );

		self::assertFalse( $suppression->filter_processing_order( true ) );
	}

	public function test_processing_order_is_untouched_when_the_gate_is_closed(): void {
		$suppression = new TransactionalSuppression( $this->gate_open_for( '' ) );

		self::assertTrue( $suppression->filter_processing_order( true ) );
		self::assertFalse( $suppression->filter_processing_order( false ), 'The original value passes through unchanged.' );
	}

	public function test_completed_order_is_suppressed_only_when_completed_is_a_shipped_status(): void {
		$this->options['smly_plus_shipped_order_statuses'] = array( 'completed', 'shipped' );
		$suppression                                        = new TransactionalSuppression( $this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) );

		self::assertFalse( $suppression->filter_completed_order( true ) );
	}

	public function test_completed_order_is_untouched_when_completed_is_not_a_shipped_status(): void {
		// A custom shipped status only ('shipped') — no native WC email exists
		// for it, so 'completed' stays on WooCommerce's own email.
		$this->options['smly_plus_shipped_order_statuses'] = array( 'shipped' );
		$suppression                                        = new TransactionalSuppression( $this->gate_open_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) );

		self::assertTrue( $suppression->filter_completed_order( true ) );
	}

	public function test_completed_order_is_untouched_when_the_gate_is_closed(): void {
		$this->options['smly_plus_shipped_order_statuses'] = array( 'completed' );
		$suppression                                        = new TransactionalSuppression( $this->gate_open_for( '' ) );

		self::assertTrue( $suppression->filter_completed_order( true ) );
	}

	public function test_the_filters_hand_the_emails_order_to_the_gate(): void {
		// PRO-3187: the gate picks the workflow by the order's language, so
		// suppression must ask about THIS order — else it could suppress an
		// email whose language has no workflow (or keep one that does).
		$this->options['smly_plus_shipped_order_statuses'] = array( 'completed' );
		$gate        = $this->gate_recording_orders();
		$suppression = new TransactionalSuppression( $gate );
		$order       = $this->createMock( \WC_Order::class );

		$suppression->filter_processing_order( true, $order );
		$suppression->filter_completed_order( true, $order );
		$suppression->filter_processing_order( true, 'not-an-order' );

		self::assertSame( array( $order, $order, null ), $gate->orders );
	}

	public function test_everything_off_is_zero_behavior_change(): void {
		// The invariant the acceptance criteria calls out explicitly: with
		// both triggers gated closed, neither filter ever forces false.
		$suppression = new TransactionalSuppression( $this->gate_open_for( '' ) );

		self::assertTrue( $suppression->filter_processing_order( true ) );
		self::assertFalse( $suppression->filter_processing_order( false ) );
		self::assertTrue( $suppression->filter_completed_order( true ) );
		self::assertFalse( $suppression->filter_completed_order( false ) );
	}

	public function test_fire_native_bypassing_suppression_triggers_the_named_email_and_bypasses_the_filter(): void {
		$triggered = array();
		$email     = new class( $triggered ) {
			public array $calls = array();
			public function __construct( array $calls ) {
				$this->calls = $calls;
			}
			public function trigger( $order_id ) {
				$this->calls[] = $order_id;
			}
		};

		$mailer         = new \stdClass();
		$mailer->emails = array( 'WC_Email_Customer_Processing_Order' => $email );

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

		// While fire_native_bypassing_suppression() is running, the filter
		// callback must return the ORIGINAL value (bypass), not force false —
		// otherwise WC_Email::is_enabled() (called from inside trigger()) would
		// re-suppress the very email fail-open is trying to re-fire.
		$suppression = new TransactionalSuppression( $this->gate_open_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) );
		self::assertFalse( $suppression->filter_processing_order( true ), 'Suppressed outside the bypass window.' );

		TransactionalSuppression::fire_native_bypassing_suppression( 'WC_Email_Customer_Processing_Order', 909 );

		self::assertSame( array( 909 ), $email->calls, 'The native email must actually fire.' );
		// After the call the bypass flag resets — suppression resumes.
		self::assertFalse( $suppression->filter_processing_order( true ) );
	}

	// --- helpers -------------------------------------------------------------

	private function gate_recording_orders(): TransactionalGate {
		return new class() extends TransactionalGate {
			/** @var array<int, ?\WC_Order> */
			public array $orders = array();
			public function __construct() {}
			public function resolve_if_open( string $trigger_type, ?\WC_Order $order = null ): ?WorkflowMatch {
				$this->orders[] = $order;
				return null;
			}
		};
	}

	private function gate_open_for( string $open_trigger_type ): TransactionalGate {
		return new class( $open_trigger_type ) extends TransactionalGate {
			private string $open_trigger_type;
			public function __construct( string $open_trigger_type ) {
				$this->open_trigger_type = $open_trigger_type;
			}
			public function resolve_if_open( string $trigger_type, ?\WC_Order $order = null ): ?WorkflowMatch {
				return $trigger_type === $this->open_trigger_type ? new WorkflowMatch( 1, 'transactional' ) : null;
			}
		};
	}
}
