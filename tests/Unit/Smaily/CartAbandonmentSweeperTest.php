<?php
/**
 * CartAbandonmentSweeper tests (PRO-1195).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\CartAbandonmentSweeper;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\CartPayloadBuilder;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;

require_once dirname( __DIR__, 3 ) . '/includes/smaily-options.class.php';

final class CartAbandonmentSweeperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_options( true, true, 30 );

		// The AS kick is exercised at integration level; in the unit env the
		// functions may exist (defined by an earlier Brain Monkey test in the
		// same process) — stub them so the kick is a no-op either way.
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_disabled_toggle_still_runs_housekeeping_but_never_enqueues(): void {
		$this->stub_options( true, false, 30 );

		$store = $this->fake_store( array( $this->row( 1 ) ) );
		$queue = $this->fake_queue();

		$stats = ( new CartAbandonmentSweeper( $store, $this->builder_returning( $this->payload() ), $queue ) )->sweep();

		self::assertSame( 1, $store->delete_expired_calls, 'Expiry (PII bound) must run even while the feature is off.' );
		self::assertSame( 1, $store->prune_notified_calls );
		self::assertSame( 0, $store->due_rows_calls, 'A disabled store must not even query for due carts.' );
		self::assertSame( array(), $queue->enqueued );
		self::assertSame( 0, $stats['enqueued'] );
	}

	public function test_incomplete_wizard_gates_the_sweep(): void {
		// Contact-path rule: the dispatch needs wizard credentials, so an
		// un-wizarded store never enqueues (its carts expire under the guard).
		$this->stub_options( false, true, 30 );

		$store = $this->fake_store( array( $this->row( 1 ) ) );
		$queue = $this->fake_queue();

		( new CartAbandonmentSweeper( $store, $this->builder_returning( $this->payload() ), $queue ) )->sweep();

		self::assertSame( 0, $store->due_rows_calls );
		self::assertSame( array(), $queue->enqueued );
	}

	public function test_due_cart_is_enqueued_once_and_marked(): void {
		$store = $this->fake_store( array( $this->row( 7 ) ) );
		$queue = $this->fake_queue();

		$stats = ( new CartAbandonmentSweeper( $store, $this->builder_returning( $this->payload() ), $queue ) )->sweep();

		self::assertSame( 1, $stats['enqueued'] );
		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CartFlusher::EVENT_TYPE, $queue->enqueued[0]['type'] );
		self::assertSame( 'cart:7', $queue->enqueued[0]['entity_id'] );
		self::assertSame( 'buyer@example.test', $queue->enqueued[0]['payload']['email'] );
		self::assertSame( array( 7 ), $store->marked, 'The row is stamped so it never gets a second reminder (legacy mail_sent parity).' );
	}

	public function test_cutoff_and_backlog_window_bound_the_due_query(): void {
		// The delay semantics carry over from the legacy pass: due = older
		// than the merchant's cutoff (minutes option), but newer than the
		// F3-37 backlog window (default 24h, same filter name).
		$this->stub_options( true, true, 45 );

		$store = $this->fake_store( array() );
		$queue = $this->fake_queue();

		$before = time();
		( new CartAbandonmentSweeper( $store, $this->builder_returning( null ), $queue ) )->sweep();
		$after = time();

		self::assertNotSame( array(), $store->due_args );
		list( $threshold, $floor ) = $store->due_args;

		$threshold_ts = strtotime( $threshold . ' UTC' );
		$floor_ts     = strtotime( $floor . ' UTC' );

		self::assertGreaterThanOrEqual( $before - 45 * 60 - 2, $threshold_ts );
		self::assertLessThanOrEqual( $after - 45 * 60 + 2, $threshold_ts );
		self::assertGreaterThanOrEqual( $before - DAY_IN_SECONDS - 2, $floor_ts );
		self::assertLessThanOrEqual( $after - DAY_IN_SECONDS + 2, $floor_ts );

		self::assertSame( array( $floor ), $store->expired_args, 'delete_expired must use the same backlog floor.' );
	}

	public function test_unreadable_payload_is_terminal_marked_without_enqueue(): void {
		// F3-53 spirit: a row the builder can't read is deterministic — mark
		// it (observable) instead of re-reading it forever.
		$store = $this->fake_store( array( $this->row( 3 ) ) );
		$queue = $this->fake_queue();

		$stats = ( new CartAbandonmentSweeper( $store, $this->builder_returning( null ), $queue ) )->sweep();

		self::assertSame( array(), $queue->enqueued );
		self::assertSame( array( 3 ), $store->marked );
		self::assertSame( 1, $stats['skipped'] );
	}

	public function test_throwing_row_is_terminal_marked_and_the_pass_continues(): void {
		// F3-53 backstop: one poison row never aborts the sweep.
		$store = $this->fake_store( array( $this->row( 4 ), $this->row( 5 ) ) );
		$queue = $this->fake_queue();

		$builder = new class extends CartPayloadBuilder {
			public function build( array $row ): ?array {
				if ( (int) $row['id'] === 4 ) {
					throw new \RuntimeException( 'poison row' );
				}
				return array(
					'email'  => 'ok@example.test',
					'fields' => array(),
				);
			}
		};

		$stats = ( new CartAbandonmentSweeper( $store, $builder, $queue ) )->sweep();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( 1, $stats['enqueued'], 'The row after the poison one must still be processed.' );
		self::assertSame( array( 4, 5 ), $store->marked );
	}

	public function test_failed_enqueue_leaves_the_row_for_the_next_tick(): void {
		$store = $this->fake_store( array( $this->row( 6 ) ) );
		$queue = $this->fake_queue( false );

		$stats = ( new CartAbandonmentSweeper( $store, $this->builder_returning( $this->payload() ), $queue ) )->sweep();

		self::assertSame( 0, $stats['enqueued'] );
		self::assertSame( array(), $store->marked, 'A transient queue-insert failure must not consume the cart\'s single reminder.' );
	}

	// --- helpers -------------------------------------------------------------

	private function stub_options( bool $setup_completed, bool $enabled, int $cutoff_minutes ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( $setup_completed, $enabled, $cutoff_minutes ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return $setup_completed;
				}
				if ( $key === \Smaily_Connect\Includes\Options::ABANDONED_CART_STATUS_OPTION ) {
					return array(
						'enabled'          => $enabled,
						'autoresponder_id' => 0,
					);
				}
				if ( $key === \Smaily_Connect\Includes\Options::ABANDONED_CART_CUTOFF_OPTION ) {
					return $cutoff_minutes;
				}
				return $fallback;
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		return array(
			'id'           => $id,
			'cart_token'   => 'token-' . $id,
			'user_id'      => 0,
			'email'        => 'buyer@example.test',
			'first_name'   => '',
			'last_name'    => '',
			'cart_content' => '[{"product_id":11,"variation_id":0,"quantity":1}]',
			'cart_updated' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return array(
			'email'  => 'buyer@example.test',
			'fields' => array( 'is_abandoned_cart' => 'true' ),
		);
	}

	private function builder_returning( ?array $payload ): CartPayloadBuilder {
		return new class( $payload ) extends CartPayloadBuilder {
			private ?array $payload;

			public function __construct( ?array $payload ) {
				$this->payload = $payload;
			}

			public function build( array $row ): ?array {
				return $this->payload;
			}
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $due
	 */
	private function fake_store( array $due ): CartSessionStore {
		return new class( $due ) extends CartSessionStore {
			/** @var array<int, array<string, mixed>> */
			private array $due;

			public int $delete_expired_calls = 0;
			public int $prune_notified_calls = 0;
			public int $due_rows_calls       = 0;

			/** @var array<int, string> */
			public array $due_args = array();

			/** @var array<int, string> */
			public array $expired_args = array();

			/** @var array<int, int> */
			public array $marked = array();

			public function __construct( array $due ) {
				$this->due = $due;
			}

			public function delete_expired( string $min_updated ): int {
				++$this->delete_expired_calls;
				$this->expired_args[] = $min_updated;
				return 0;
			}

			public function prune_notified( string $older_than ): int {
				++$this->prune_notified_calls;
				return 0;
			}

			public function due_rows( string $cutoff_threshold, string $min_updated, int $limit = 200 ): array {
				++$this->due_rows_calls;
				$this->due_args = array( $cutoff_threshold, $min_updated );
				return $this->due;
			}

			public function mark_reminder_enqueued( int $id ): void {
				$this->marked[] = $id;
			}
		};
	}

	private function fake_queue( bool $insert_succeeds = true ): EventQueue {
		return new class( $insert_succeeds ) extends EventQueue {
			private bool $insert_succeeds;

			/** @var array<int, array{type: string, entity_id: string, payload: array<string, mixed>}> */
			public array $enqueued = array();

			public function __construct( bool $insert_succeeds ) {
				$this->insert_succeeds = $insert_succeeds;
			}

			public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
				if ( ! $this->insert_succeeds ) {
					return null;
				}
				$this->enqueued[] = array(
					'type'      => $event_type,
					'entity_id' => $entity_id,
					'payload'   => $payload,
				);
				return count( $this->enqueued );
			}
		};
	}
}
