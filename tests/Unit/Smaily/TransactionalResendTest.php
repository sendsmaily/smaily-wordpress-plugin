<?php
/**
 * TransactionalResend tests — a "Send again" rebuilds the merge tags for
 * the same email the row was.
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
use Smaily\Connect\Smaily\TransactionalPayloadBuilder;
use Smaily\Connect\Smaily\TransactionalResend;
use Smaily\Connect\Smaily\WorkflowMatch;

final class TransactionalResendTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_send_again_tells_the_builder_which_email_it_rebuilds(): void {
		// PRO-3190: the merchant's extra-fields filter gets the trigger on a
		// resend too, so a snippet can tell the two confirmations apart.
		$order = new class() extends \WC_Order {};
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$gate = new class() extends TransactionalGate {
			public function __construct() {}

			public function resolve_if_open( string $trigger_type, ?\WC_Order $order = null ): ?WorkflowMatch {
				return new WorkflowMatch( 1, 'transactional' );
			}
		};
		$builder = new class() extends TransactionalPayloadBuilder {
			/** @var string[] */
			public array $triggers = array();

			public function build( \WC_Order $order, string $trigger = '' ): array {
				$this->triggers[] = $trigger;
				return array( 'order_number' => '1' );
			}
		};
		$flusher = new class() extends TransactionalFlusher {
			public function __construct() {}

			public function enqueue_resend( string $trigger_type, \WC_Order $order, WorkflowMatch $match, array $context, string $to_status = '' ): ?int {
				return 5;
			}
		};

		$result = ( new TransactionalResend( $gate, $builder, $flusher ) )
			->resend( 1, TransactionalGate::event_type_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ), 'completed' );

		self::assertSame( 5, $result['id'] );
		self::assertSame( array( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ), $builder->triggers );
	}
}
