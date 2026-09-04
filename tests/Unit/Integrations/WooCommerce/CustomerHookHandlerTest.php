<?php
/**
 * CustomerHookHandler tests — user hooks → customer.upsert rows routed to
 * the customer flush hook, the is_connected gate, the A-filter (every
 * registered user, no role check), and per-request dedupe.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CustomerHookHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		CustomerHookHandler::reset_seen();
	}

	protected function tearDown(): void {
		CustomerHookHandler::reset_seen();
		parent::tearDown();
	}

	public function test_user_register_enqueues_customer_upsert_routed_to_customer_hook(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_user_register( 67 );

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $queue->enqueued[0]['type'] );
		self::assertSame( '67', $queue->enqueued[0]['entity_id'] );
		self::assertSame( array(), $queue->enqueued[0]['payload'], 'Upsert payload is empty — the flusher loads the user fresh.' );
		self::assertSame( CustomerFlusher::FLUSH_HOOK, $queue->enqueued[0]['flush_hook'], 'Customer rows must schedule the customer flush hook, not catalog.' );
		self::assertSame( CustomerFlusher::AS_GROUP, $queue->enqueued[0]['flush_group'] );
	}

	public function test_no_enqueue_when_not_connected(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, false );

		$handler->on_user_register( 67 );

		self::assertSame( array(), $queue->enqueued, 'No rec-engine tenant connected → the gate is shut.' );
	}

	public function test_all_four_hooks_enqueue(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_user_register( 1 );
		$handler->on_profile_update( 2 );
		$handler->on_woocommerce_created_customer( 3 );
		$handler->on_save_account_details( 4 );

		self::assertCount( 4, $queue->enqueued );
		self::assertSame(
			array( '1', '2', '3', '4' ),
			array_column( $queue->enqueued, 'entity_id' )
		);
	}

	public function test_a_filter_enqueues_any_user_without_role_check(): void {
		// The handler never inspects roles — every registered user is enqueued
		// (consistent with the email-sync handler; see DECISIONS 3.3.3).
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_user_register( 999 ); // could be an admin; still enqueued.

		self::assertCount( 1, $queue->enqueued );
		self::assertSame( '999', $queue->enqueued[0]['entity_id'] );
	}

	public function test_invalid_user_id_is_ignored(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_user_register( 0 );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_repeat_update_in_one_request_is_deduped(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_profile_update( 42 );
		$handler->on_profile_update( 42 );

		self::assertCount( 1, $queue->enqueued, 'profile_update fires repeatedly in a request — collapse to one row.' );
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

	private function handler( IngestQueue $queue, bool $connected ): CustomerHookHandler {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		return new CustomerHookHandler( $queue, $settings );
	}
}
