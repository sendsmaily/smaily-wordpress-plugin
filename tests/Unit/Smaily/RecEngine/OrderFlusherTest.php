<?php
/**
 * OrderFlusher tests — the D6 per-item contract (errors[].index → failed row,
 * the rest sent), the order.* drain scoping, the order-gone and
 * status-no-longer-mappable terminal skips, and the terminal-4xx /
 * transient-retry paths.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class OrderFlusherTest extends TestCase {

	public function test_flush_scopes_drain_to_order_event_type(): void {
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array( 'processed' => 1 ) ), true, array( 100 => true ) );

		$flush->flush();

		self::assertSame(
			array( OrderFlusher::EVENT_ORDER_UPSERT ),
			$queue->pending_event_types,
			'Order flusher must scope pending() to order.upsert only.'
		);
	}

	public function test_d6_partial_success_fails_errored_row_and_sends_the_rest(): void {
		$queue  = $this->fake_queue(
			array(
				$this->upsert_row( 1, 100, 'u1' ),
				$this->upsert_row( 2, 101, 'u2' ),
				$this->upsert_row( 3, 102, 'u3' ),
			)
		);
		$client = $this->d6_client(
			array(
				'ok'           => true,
				'processed'    => 2,
				'deduplicated' => 0,
				'errors'       => array(
					array( 'index' => 1, 'external_order_id' => 'WC-99', 'field' => 'status', 'message' => 'Invalid enum value' ),
				),
			)
		);
		$flush = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true, 102 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 3 ), $queue->sent );
		self::assertCount( 1, $queue->failed );
		self::assertSame( 2, $queue->failed[0]['id'], 'errors[].index 1 → batch_rows[1] (id 2) → failed.' );
		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 1, $stats['failed'] );
	}

	public function test_all_valid_rows_marked_sent(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->d6_client( array( 'ok' => true, 'processed' => 2, 'deduplicated' => 0, 'errors' => array() ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 2 ), $queue->sent );
		self::assertSame( 2, $stats['sent'] );
	}

	public function test_deduplicated_items_are_treated_as_sent(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$client = $this->d6_client( array( 'ok' => true, 'processed' => 0, 'deduplicated' => 1, 'errors' => array(), 'deduplicated_all' => true ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$flush->flush();

		self::assertSame( array( 1 ), $queue->sent, 'deduplicated == success → mark sent.' );
		self::assertSame( array(), $queue->attempts );
	}

	public function test_terminal_4xx_marks_whole_batch_failed(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->throwing_client( new ApiException( 400, 'validation_failed', 'bad batch' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertCount( 2, $queue->failed );
		self::assertSame( 2, $stats['failed'] );
	}

	public function test_transient_5xx_records_retry_below_max(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1', 0, 5 ) ) );
		$client = $this->throwing_client( new ApiException( 500, 'internal_error', 'boom' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertCount( 1, $queue->attempts );
		self::assertSame( 1, $stats['retried'] );
	}

	public function test_order_gone_is_skipped(): void {
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 999, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array() ), true, array() ); // 999 not present

		$stats = $flush->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( array( 1 ), $queue->sent, 'A vanished order leaves the queue (mark_sent).' );
	}

	public function test_non_mappable_status_at_flush_is_skipped(): void {
		// The order moved back to a non-confirmed status (e.g. pending) after
		// enqueue → map_status '' → skip; the hook re-enqueues if it returns.
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array() ), true, array( 100 => true ), '' );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( array( 1 ), $queue->sent );
	}

	public function test_empty_items_payload_is_terminal_skip_not_send(): void {
		// F3-36: every line dropped (only non-product lines, or unkeyable
		// deleted-product lines) → engine would D6-reject on items min 1, on
		// every retry, forever. Terminal skip instead — never sent.
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array() ), true, array( 100 => true ), 'completed', false );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( array( 1 ), $queue->sent, 'Empty-items order leaves the queue without an engine call.' );
		self::assertSame( array(), $queue->failed );
	}

	public function test_exchange_recorded_for_an_accepted_row(): void {
		// F3-44: the flusher records the exact object it POSTed + the engine
		// reply, so the Event Log "Details" can show them (no more empty payload).
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array( 'ok' => true, 'processed' => 1, 'deduplicated' => 0, 'errors' => array() ) ), true, array( 100 => true ) );

		$flush->flush();

		self::assertArrayHasKey( 1, $queue->exchanges );
		self::assertNotNull( $queue->exchanges[1]['sent'], 'The exact POSTed object is recorded.' );
		self::assertNotSame( '', (string) $queue->exchanges[1]['sent'] );
		self::assertStringContainsString( '"outcome":"accepted"', (string) $queue->exchanges[1]['response'] );
		self::assertStringContainsString( '"http":200', (string) $queue->exchanges[1]['response'] );
	}

	public function test_exchange_records_the_error_for_a_d6_rejected_row(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->d6_client(
			array(
				'ok'           => true,
				'processed'    => 1,
				'deduplicated' => 0,
				'errors'       => array( array( 'index' => 1, 'field' => 'status', 'message' => 'Invalid enum value' ) ),
			)
		);
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$flush->flush();

		self::assertStringContainsString( '"outcome":"rejected"', (string) $queue->exchanges[2]['response'] );
		self::assertStringContainsString( 'status', (string) $queue->exchanges[2]['response'] );
		self::assertNotNull( $queue->exchanges[2]['sent'], 'A rejected row still records what was sent.' );
	}

	public function test_exchange_records_a_skip_when_nothing_was_posted(): void {
		// A terminal-skip row (empty items) is marked sent but never POSTed — the
		// exchange makes that visible: sent=null, response outcome=skipped (F3-44).
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array() ), true, array( 100 => true ), 'completed', false );

		$flush->flush();

		self::assertNull( $queue->exchanges[1]['sent'], 'Nothing was POSTed → no sent payload.' );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $queue->exchanges[1]['response'] );
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function fake_queue( array $rows ): IngestQueue {
		return new class( $rows ) extends IngestQueue {
			/** @var array<int, array<string, mixed>> */
			public array $pending_rows;
			/** @var array<int, int> */
			public array $sent = array();
			/** @var array<int, array<string, mixed>> */
			public array $failed = array();
			/** @var array<int, array<string, mixed>> */
			public array $attempts = array();
			/** @var array<int, string>|null */
			public ?array $pending_event_types = null;

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows ) {
				$this->pending_rows = $rows;
			}

			public function pending( int $limit = 100, ?array $event_types = null ): array {
				$this->pending_event_types = $event_types;
				return $this->pending_rows;
			}
			public function mark_sent( int $id ): void {
				$this->sent[] = $id;
			}
			public function mark_failed( int $id, string $error ): void {
				$this->failed[] = array( 'id' => $id, 'error' => $error );
			}
			public function record_attempt( int $id, string $error, int $retry_in_seconds = 60 ): void {
				$this->attempts[] = array( 'id' => $id, 'error' => $error, 'retry' => $retry_in_seconds );
			}
			/** @var array<int, array{sent: ?string, response: ?string}> */
			public array $exchanges = array();
			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( 'sent' => $sent_payload, 'response' => $last_response );
			}
		};
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function d6_client( array $response ): Client {
		return new class( $response ) extends Client {
			/** @var array<string, mixed> */
			private array $response;
			/** @param array<string, mixed> $response */
			public function __construct( array $response ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->response = $response;
			}
			public function ingest_orders( array $orders ): array {
				return $this->response;
			}
		};
	}

	private function throwing_client( ApiException $e ): Client {
		return new class( $e ) extends Client {
			private ApiException $e;
			public function __construct( ApiException $e ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->e = $e;
			}
			public function ingest_orders( array $orders ): array {
				throw $this->e;
			}
		};
	}

	/**
	 * @param array<int, true> $orders_by_id Entity ids that resolve to an order.
	 */
	private function fake_flusher( IngestQueue $queue, Client $client, bool $connected, array $orders_by_id = array(), string $map_status = 'completed', bool $with_items = true ): OrderFlusher {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		$builder = new class( $map_status, $with_items ) extends OrderPayloadBuilder {
			private string $map_status;
			private bool $with_items;
			public function __construct( string $map_status, bool $with_items ) {
				$this->map_status = $map_status;
				$this->with_items = $with_items;
			}
			public function map_status( string $wc_status ): string {
				return $this->map_status;
			}
			public function build( \WC_Order $order, string $event_uuid ): array {
				return array(
					'external_order_id' => 'WC',
					'event_id'          => $event_uuid,
					'items'             => $this->with_items
						? array( array( 'sku' => 'S', 'qty' => 1, 'unit_price' => 1.0, 'line_total' => 1.0 ) )
						: array(),
				);
			}
		};

		$factory = static function () use ( $client ): Client {
			return $client;
		};

		return new class( $queue, $builder, $settings, $factory, $orders_by_id ) extends OrderFlusher {
			/** @var array<int, true> */
			private array $orders_by_id;

			/**
			 * @param callable(): Client $factory
			 * @param array<int, true>   $orders_by_id
			 */
			public function __construct( IngestQueue $queue, OrderPayloadBuilder $builder, RecEngineSettings $settings, callable $factory, array $orders_by_id ) {
				parent::__construct( $queue, $builder, $settings, $factory );
				$this->orders_by_id = $orders_by_id;
			}

			protected function get_order( int $order_id ): ?\WC_Order {
				return isset( $this->orders_by_id[ $order_id ] ) ? new \WC_Order() : null;
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function upsert_row( int $id, int $entity_id, string $uuid, int $attempts = 0, int $max = 5 ): array {
		return array(
			'id'           => $id,
			'event_type'   => OrderFlusher::EVENT_ORDER_UPSERT,
			'entity_id'    => (string) $entity_id,
			'event_uuid'   => $uuid,
			'payload'      => '',
			'attempts'     => $attempts,
			'max_attempts' => $max,
		);
	}
}
