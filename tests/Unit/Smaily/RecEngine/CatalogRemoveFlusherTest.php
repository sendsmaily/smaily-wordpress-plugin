<?php
/**
 * CatalogRemoveFlusher tests — catalog.remove queue rows → §3b wire wrapper,
 * the non-D6 all-sent response handling (incl. not_found as success), the
 * inherited terminal-4xx / transient-retry policy, and the keyless-row skip.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CatalogRemoveFlusherTest extends TestCase {

	public function test_returns_early_when_not_connected(): void {
		$queue = $this->fake_queue( array( $this->remove_row( 1, '100' ) ) );
		$flush = $this->flusher( $queue, $this->success_client(), false );

		$stats = $flush->flush();

		self::assertSame( 0, $stats['processed'] );
		self::assertSame( array(), $queue->sent );
	}

	public function test_flush_scopes_drain_to_catalog_remove_rows_only(): void {
		// The queue is shared with catalog.upsert/delete + customers/orders —
		// this flusher must only ever see its own event type.
		$queue = $this->fake_queue( array( $this->remove_row( 1, '100' ) ) );
		$flush = $this->flusher( $queue, $this->success_client(), true );

		$flush->flush();

		self::assertSame(
			array( CatalogHookHandler::EVENT_CATALOG_REMOVE ),
			$queue->pending_event_types
		);
	}

	public function test_batch_posts_unique_raw_ids_and_marks_all_sent(): void {
		// Two products + a duplicate row for the first: the wrapper carries the
		// RAW ids, de-duplicated; every row is marked sent (§3b has no per-item
		// errors — a 2xx applied the whole wrapper).
		$queue  = $this->fake_queue(
			array( $this->remove_row( 1, '100' ), $this->remove_row( 2, '200' ), $this->remove_row( 3, '100' ) )
		);
		$client = $this->success_client();
		$flush  = $this->flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( array( '100', '200' ), $client->sent_ids, 'RAW un-prefixed parent ids, de-duplicated within one wrapper.' );
		self::assertSame( array( 1, 2, 3 ), $queue->sent );
		self::assertSame( 3, $stats['sent'] );
		self::assertStringContainsString( '"outcome":"removed"', (string) $queue->exchanges[1]['response'] );
	}

	public function test_not_found_id_is_sent_not_retried_and_observable(): void {
		// Idempotent §3b: an id matching no engine row lands in not_found — a
		// SUCCESS per the contract ("already removed, or never sent"), never a
		// retry loop. The stored exchange says so (F3-44 observability).
		$queue  = $this->fake_queue( array( $this->remove_row( 1, '100' ), $this->remove_row( 2, '999' ) ) );
		$client = $this->success_client(
			array(
				'ok'               => true,
				'removed_products' => 1,
				'rows_tombstoned'  => 3,
				'not_found'        => array( '999' ),
			)
		);
		$flush = $this->flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( array( 1, 2 ), $queue->sent );
		self::assertSame( 2, $stats['sent'] );
		self::assertSame( array(), $queue->attempts );
		self::assertStringContainsString( '"outcome":"removed"', (string) $queue->exchanges[1]['response'] );
		self::assertStringContainsString( '"outcome":"not_found"', (string) $queue->exchanges[2]['response'] );
	}

	public function test_terminal_4xx_marks_failed_without_retry(): void {
		$queue  = $this->fake_queue( array( $this->remove_row( 1, '100' ) ) );
		$client = $this->throwing_client( new ApiException( 400, 'validation_failed', 'bad wrapper' ) );
		$flush  = $this->flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->attempts );
		self::assertStringContainsString( 'validation_failed', $queue->failed[0]['error'] );
	}

	public function test_transient_5xx_records_a_retry_with_backoff(): void {
		$queue  = $this->fake_queue( array( $this->remove_row( 1, '100', 0, 5 ) ) );
		$client = $this->throwing_client( new ApiException( 503, 'unavailable', 'down' ) );
		$flush  = $this->flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['retried'] );
		self::assertSame( 1, $queue->attempts[0]['id'] );
		self::assertSame( 60, $queue->attempts[0]['retry'] );
	}

	public function test_row_without_product_id_is_skipped_observably(): void {
		// A keyless row can't be sent — terminal skip, but never silent: the
		// stored exchange records that nothing was POSTed (LESSONS §2.11).
		$row            = $this->remove_row( 9, '' );
		$row['payload'] = '{}';

		$queue  = $this->fake_queue( array( $row ) );
		$client = $this->success_client();
		$flush  = $this->flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( array( 9 ), $queue->sent, 'The row leaves the queue (marked sent) but the exchange says skipped.' );
		self::assertSame( array(), $client->sent_ids );
		self::assertStringContainsString( 'skipped', (string) $queue->exchanges[9]['response'] );
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
			/** @var array<int, array{sent: ?string, response: ?string}> */
			public array $exchanges = array();
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
			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( 'sent' => $sent_payload, 'response' => $last_response );
			}
		};
	}

	/**
	 * @param array<string, mixed> $response Override the canned §3b body.
	 */
	private function success_client( array $response = array() ): Client {
		return new class( $response ) extends Client {
			/** @var array<int, string> */
			public array $sent_ids = array();
			/** @var array<string, mixed> */
			private array $response;

			/** @param array<string, mixed> $response */
			public function __construct( array $response ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->response = $response;
			}

			public function catalog_remove( array $product_ids ): array {
				$this->sent_ids = $product_ids;
				return $this->response !== array()
					? $this->response
					: array(
						'ok'               => true,
						'removed_products' => count( $product_ids ),
						'rows_tombstoned'  => count( $product_ids ),
						'not_found'        => array(),
					);
			}
		};
	}

	private function throwing_client( ApiException $e ): Client {
		return new class( $e ) extends Client {
			/** @var array<int, string> */
			public array $sent_ids = array();
			private ApiException $e;

			public function __construct( ApiException $e ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->e = $e;
			}

			public function catalog_remove( array $product_ids ): array {
				$this->sent_ids = $product_ids;
				throw $this->e;
			}
		};
	}

	private function flusher( IngestQueue $queue, Client $client, bool $connected ): CatalogRemoveFlusher {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		return new CatalogRemoveFlusher(
			$queue,
			$settings,
			static function () use ( $client ): Client {
				return $client;
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function remove_row( int $id, string $product_id, int $attempts = 0, int $max = 5 ): array {
		return array(
			'id'           => $id,
			'event_type'   => CatalogHookHandler::EVENT_CATALOG_REMOVE,
			'entity_id'    => $product_id,
			'event_uuid'   => 'uuid-' . $id,
			'payload'      => (string) json_encode( array( 'product_id' => $product_id ) ),
			'attempts'     => $attempts,
			'max_attempts' => $max,
		);
	}
}
