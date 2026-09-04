<?php
/**
 * CustomerFlusher tests — the D6 per-item contract: errors[].index → failed
 * row, every other row sent (processed or deduplicated), plus the
 * terminal-4xx / transient-retry / user-gone paths and the customer.* drain
 * scoping.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class CustomerFlusherTest extends TestCase {

	public function test_returns_early_when_not_connected(): void {
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array() ), false );

		$stats = $flush->flush();

		self::assertSame( 0, $stats['processed'] );
		self::assertSame( array(), $queue->sent );
	}

	public function test_flush_scopes_drain_to_customer_event_type(): void {
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->d6_client( array( 'processed' => 1 ) ), true, array( 100 => true ) );

		$flush->flush();

		self::assertSame(
			array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ),
			$queue->pending_event_types,
			'Customer flusher must scope pending() to customer.upsert only.'
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
		// Engine rejects index 1; indexes 0 + 2 are processed.
		$client = $this->d6_client(
			array(
				'ok'           => true,
				'processed'    => 2,
				'deduplicated' => 0,
				'errors'       => array(
					array( 'index' => 1, 'email' => 'bad', 'field' => 'email', 'message' => 'Invalid email' ),
				),
			)
		);
		$flush = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true, 102 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 3 ), $queue->sent, 'Rows 0 and 2 (ids 1,3) succeeded → sent.' );
		self::assertCount( 1, $queue->failed );
		self::assertSame( 2, $queue->failed[0]['id'], 'errors[].index 1 → batch_rows[1] (id 2) → failed.' );
		self::assertStringContainsString( 'Invalid email', $queue->failed[0]['error'] );
		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 1, $stats['failed'] );
	}

	public function test_all_valid_rows_marked_sent(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->d6_client( array( 'ok' => true, 'processed' => 2, 'deduplicated' => 0, 'errors' => array() ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 2 ), $queue->sent );
		self::assertSame( array(), $queue->failed );
		self::assertSame( 2, $stats['sent'] );
	}

	public function test_deduplicated_items_are_treated_as_sent(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		// Pure no-op retry: both already seen → deduplicated, none errored.
		$client = $this->d6_client(
			array( 'ok' => true, 'processed' => 0, 'deduplicated' => 2, 'errors' => array(), 'deduplicated_all' => true )
		);
		$flush = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 2 ), $queue->sent, 'deduplicated == success → mark sent, never retry.' );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 2, $stats['sent'] );
	}

	public function test_terminal_4xx_marks_whole_batch_failed(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->throwing_client( new ApiException( 400, 'validation_failed', 'bad batch' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertCount( 2, $queue->failed, 'A wrapper-level 400 fails the whole batch — no per-item split.' );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 2, $stats['failed'] );
	}

	public function test_transient_5xx_records_retry_below_max(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1', 0, 5 ) ) );
		$client = $this->throwing_client( new ApiException( 500, 'internal_error', 'boom' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertCount( 1, $queue->attempts, 'A 5xx below max_attempts parks the row for retry.' );
		self::assertSame( array(), $queue->failed );
		self::assertSame( 1, $stats['retried'] );
	}

	public function test_exhausted_retries_mark_failed(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1', 4, 5 ) ) );
		$client = $this->throwing_client( new ApiException( 500, 'internal_error', 'boom' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertCount( 1, $queue->failed, 'attempts+1 == max → terminal failure, no further retry.' );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 1, $stats['failed'] );
	}

	public function test_user_gone_is_skipped_and_leaves_queue(): void {
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 999, 'u1' ) ) );
		// 999 not in the users map → get_user returns null (deleted user).
		$flush = $this->fake_flusher( $queue, $this->d6_client( array() ), true, array() );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( array( 1 ), $queue->sent, 'A vanished user is marked sent so the row leaves the queue.' );
	}

	public function test_invariant_mismatch_still_follows_errors_array(): void {
		// Engine returns inconsistent counts (processed 9 for a 2-row batch);
		// the row states must still follow errors[] (authoritative), not counts.
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->d6_client( array( 'ok' => true, 'processed' => 9, 'deduplicated' => 0, 'errors' => array() ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 2 ), $queue->sent, 'errors[] is empty → both rows sent regardless of the bogus count.' );
		self::assertSame( 2, $stats['sent'] );
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
			/** @var array<int, array<string, mixed>> */
			public array $sent_customers = array();
			/** @var array<string, mixed> */
			private array $response;

			/** @param array<string, mixed> $response */
			public function __construct( array $response ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->response = $response;
			}

			public function ingest_customers( array $customers ): array {
				$this->sent_customers = $customers;
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

			public function ingest_customers( array $customers ): array {
				throw $this->e;
			}
		};
	}

	/**
	 * @param array<int, true> $users_by_id Entity ids that resolve to a (stub) WP_User.
	 */
	private function fake_flusher( IngestQueue $queue, Client $client, bool $connected, array $users_by_id = array() ): CustomerFlusher {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		$builder = new class() extends CustomerPayloadBuilder {
			public function build( \WP_User $user, string $event_uuid ): array {
				return array( 'email' => 'stub@example.test', 'event_id' => $event_uuid );
			}
		};

		$factory = static function () use ( $client ): Client {
			return $client;
		};

		return new class( $queue, $builder, $settings, $factory, $users_by_id ) extends CustomerFlusher {
			/** @var array<int, true> */
			private array $users_by_id;

			/**
			 * @param callable(): Client $factory
			 * @param array<int, true>   $users_by_id
			 */
			public function __construct( IngestQueue $queue, CustomerPayloadBuilder $builder, RecEngineSettings $settings, callable $factory, array $users_by_id ) {
				parent::__construct( $queue, $builder, $settings, $factory );
				$this->users_by_id = $users_by_id;
			}

			protected function get_user( int $user_id ): ?\WP_User {
				if ( ! isset( $this->users_by_id[ $user_id ] ) ) {
					return null;
				}
				return new class() extends \WP_User {
					public function __construct() {}
				};
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function upsert_row( int $id, int $entity_id, string $uuid, int $attempts = 0, int $max = 5 ): array {
		return array(
			'id'           => $id,
			'event_type'   => CustomerFlusher::EVENT_CUSTOMER_UPSERT,
			'entity_id'    => (string) $entity_id,
			'event_uuid'   => $uuid,
			'payload'      => '',
			'attempts'     => $attempts,
			'max_attempts' => $max,
		);
	}
}
