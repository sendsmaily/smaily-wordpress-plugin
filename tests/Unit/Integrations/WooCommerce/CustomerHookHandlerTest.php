<?php
/**
 * CustomerHookHandler tests — user hooks → customer.upsert rows routed to
 * the customer flush hook, the is_connected gate, the §4 customer scope
 * (CON-246: only an in-scope user is queued), the opt-in meta trigger and
 * per-request dedupe.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
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

	public function test_a_user_out_of_scope_is_not_enqueued_on_any_hook(): void {
		// CON-246: an account the contact sync leaves out and that has no
		// order inside the horizon (e.g. an admin) never reaches the engine.
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true, array( 7 ) );

		$handler->on_user_register( 999 );
		$handler->on_profile_update( 999 );
		$handler->on_woocommerce_created_customer( 999 );
		$handler->on_save_account_details( 999 );
		$handler->on_user_meta_changed( 1, 999, ContactAudience::OPTIN_META );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_an_opt_in_meta_change_enqueues_an_in_scope_user(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_user_meta_changed( 11, 42, ContactAudience::OPTIN_META );

		self::assertSame( array( '42' ), array_column( $queue->enqueued, 'entity_id' ) );
	}

	public function test_another_meta_key_is_ignored(): void {
		$queue   = $this->fake_queue();
		$handler = $this->handler( $queue, true );

		$handler->on_user_meta_changed( 11, 42, 'billing_phone' );

		self::assertSame( array(), $queue->enqueued );
	}

	public function test_a_user_that_enters_the_scope_later_in_the_request_is_still_enqueued(): void {
		// The registration form writes the opt-in after user_register: the
		// first check is out of scope and must not mark the user seen.
		$queue   = $this->fake_queue();
		$ids     = new \ArrayObject( array() );
		$handler = $this->handler_with_scope( $queue, $this->scope_over( $ids ) );

		$handler->on_user_register( 42 );
		$ids->append( 42 );
		$handler->on_user_meta_changed( 11, 42, ContactAudience::OPTIN_META );

		self::assertSame( array( '42' ), array_column( $queue->enqueued, 'entity_id' ) );
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

	private function settings( bool $connected ): RecEngineSettings {
		return new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};
	}

	/**
	 * A scope whose answer is a fixed set of user ids; null = every user.
	 *
	 * @param int[]|null $in_scope
	 */
	private function scope( ?array $in_scope ): CustomerScope {
		return $in_scope === null ? $this->scope_over( null ) : $this->scope_over( new \ArrayObject( $in_scope ) );
	}

	/**
	 * A scope over a set the test can change between calls; null = every user.
	 *
	 * @param \ArrayObject<int, int>|null $ids
	 */
	private function scope_over( ?\ArrayObject $ids ): CustomerScope {
		return new class( $this->settings( true ), $ids ) extends CustomerScope {
			/** @var \ArrayObject<int, int>|null */
			private ?\ArrayObject $ids;
			/** @param \ArrayObject<int, int>|null $ids */
			public function __construct( RecEngineSettings $settings, ?\ArrayObject $ids ) {
				parent::__construct( $settings );
				$this->ids = $ids;
			}
			public function includes_user( int $user_id ): bool {
				return $this->ids === null || in_array( $user_id, $this->ids->getArrayCopy(), true );
			}
		};
	}

	/**
	 * @param int[]|null $in_scope
	 */
	private function handler( IngestQueue $queue, bool $connected, ?array $in_scope = null ): CustomerHookHandler {
		return new CustomerHookHandler( $queue, $this->settings( $connected ), $this->scope( $in_scope ) );
	}

	private function handler_with_scope( IngestQueue $queue, CustomerScope $scope ): CustomerHookHandler {
		return new CustomerHookHandler( $queue, $this->settings( true ), $scope );
	}
}
