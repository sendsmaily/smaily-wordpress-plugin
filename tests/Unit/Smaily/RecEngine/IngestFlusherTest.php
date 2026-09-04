<?php
/**
 * IngestFlusher tests — queue row → catalog object → engine, and the
 * sent / deduplicated / terminal-4xx / transient-retry / exhausted /
 * delete / product-gone response handling.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class IngestFlusherTest extends TestCase {

	public function test_returns_early_when_not_connected(): void {
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->success_client(), false );

		$stats = $flush->flush();

		self::assertSame( 0, $stats['processed'], 'A disconnected tenant must not drain the queue.' );
		self::assertSame( array(), $queue->sent );
	}

	public function test_upsert_batch_all_marked_sent_and_carries_event_ids(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ) ) );
		$client = $this->success_client();
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true ) );

		$stats = $flush->flush();

		self::assertSame( 2, $stats['sent'] );
		self::assertSame( array( 1, 2 ), $queue->sent );
		self::assertCount( 2, $client->sent_products );
		self::assertSame( 'u1', $client->sent_products[0]['event_id'] );
		self::assertSame( 'u2', $client->sent_products[1]['event_id'] );
	}

	public function test_flush_scopes_drain_to_catalog_event_types(): void {
		// The queue is shared with customers; the catalog flusher must drain
		// only catalog.* rows so it never silently consumes a customer row.
		$queue = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$flush = $this->fake_flusher( $queue, $this->success_client(), true, array( 100 => true ) );

		$flush->flush();

		self::assertSame(
			array( CatalogHookHandler::EVENT_CATALOG_UPSERT, CatalogHookHandler::EVENT_CATALOG_DELETE ),
			$queue->pending_event_types,
			'Catalog flusher must scope pending() to catalog event types.'
		);
	}

	public function test_deduplicated_response_is_treated_as_sent_not_retried(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		// D6 dedup retry: the row was already seen → deduplicated, not errored.
		$client = $this->success_client( array( 'ok' => true, 'processed' => 0, 'deduplicated' => 1, 'errors' => array(), 'deduplicated_all' => true ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1 ), $queue->sent, 'A deduplicated row is success — mark sent, never retry.' );
		self::assertSame( array(), $queue->attempts );
	}

	public function test_d6_partial_success_fails_errored_row_and_sends_the_rest(): void {
		// N-7: catalog is D6 now. A 200 with errors[] must fail exactly that
		// row and send the rest — this is the lock fix (was: marked all sent).
		$queue  = $this->fake_queue(
			array( $this->upsert_row( 1, 100, 'u1' ), $this->upsert_row( 2, 101, 'u2' ), $this->upsert_row( 3, 102, 'u3' ) )
		);
		$client = $this->success_client(
			array(
				'ok'           => true,
				'processed'    => 2,
				'deduplicated' => 0,
				'errors'       => array(
					array( 'index' => 1, 'sku' => 'BAD', 'field' => 'product_url', 'message' => 'Invalid input' ),
				),
			)
		);
		$flush = $this->fake_flusher( $queue, $client, true, array( 100 => true, 101 => true, 102 => true ) );

		$stats = $flush->flush();

		self::assertSame( array( 1, 3 ), $queue->sent );
		self::assertCount( 1, $queue->failed );
		self::assertSame( 2, $queue->failed[0]['id'], 'errors[].index 1 → batch_rows[1] (id 2) → failed (not silently sent).' );
		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 1, $stats['failed'] );
	}

	public function test_terminal_4xx_marks_failed_without_retry(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1' ) ) );
		$client = $this->throwing_client( new ApiException( 401, 'api_key_revoked', 'revoked' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->attempts, 'A terminal 4xx must not schedule a row-level retry.' );
		self::assertStringContainsString( 'api_key_revoked', $queue->failed[0]['error'] );
	}

	public function test_transient_5xx_records_a_retry_with_backoff(): void {
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1', 0, 5 ) ) );
		$client = $this->throwing_client( new ApiException( 503, 'unavailable', 'down' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['retried'] );
		self::assertSame( array(), $queue->failed );
		self::assertSame( 1, $queue->attempts[0]['id'] );
		self::assertSame( 60, $queue->attempts[0]['retry'], 'First retry parks the row 60s out.' );
	}

	public function test_transient_failure_at_attempt_ceiling_marks_failed(): void {
		// attempts=4, max=5 → this attempt (5th) exhausts the budget.
		$queue  = $this->fake_queue( array( $this->upsert_row( 1, 100, 'u1', 4, 5 ) ) );
		$client = $this->throwing_client( new ApiException( 503, 'unavailable', 'down' ) );
		$flush  = $this->fake_flusher( $queue, $client, true, array( 100 => true ) );

		$stats = $flush->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->attempts, 'No retry once max_attempts is reached.' );
	}

	public function test_delete_row_sends_stored_object_with_in_stock_false(): void {
		$row            = $this->upsert_row( 7, 0, 'del-uuid' );
		$row['event_type'] = CatalogHookHandler::EVENT_CATALOG_DELETE;
		$row['payload']    = (string) json_encode( array( 'object' => array( 'sku' => 'GONE-1', 'in_stock' => true, 'event_id' => '' ) ) );

		$queue  = $this->fake_queue( array( $row ) );
		$client = $this->success_client();
		$flush  = $this->fake_flusher( $queue, $client, true );

		$flush->flush();

		self::assertSame( array( 7 ), $queue->sent );
		self::assertSame( 'GONE-1', $client->sent_products[0]['sku'] );
		self::assertFalse( $client->sent_products[0]['in_stock'], 'A delete must force in_stock=false.' );
		self::assertSame( 'del-uuid', $client->sent_products[0]['event_id'], 'event_uuid → event_id on the captured object.' );
	}

	public function test_delete_row_with_stored_blank_required_fields_is_repaired_at_flush(): void {
		// PRO-1506: a row captured BEFORE the PRO-1498 enqueue-time fix stored
		// blank category_path/product_url. Retrying it must repair — not
		// resend — the blank the engine already rejected.
		$row               = $this->upsert_row( 8, 0, 'del-blank-uuid' );
		$row['event_type'] = CatalogHookHandler::EVENT_CATALOG_DELETE;
		$row['payload']    = (string) json_encode(
			array(
				'object' => array(
					'sku'           => 'GONE-2',
					'in_stock'      => true,
					'event_id'      => '',
					'category_path' => '',
					'product_url'   => '',
					'external_id'   => '42',
				),
			)
		);

		$queue  = $this->fake_queue( array( $row ) );
		$client = $this->success_client();
		$flush  = $this->fake_flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( array( 8 ), $queue->sent, 'A repaired blank tombstone must be sent, never skipped/failed.' );
		self::assertSame( 0, $stats['skipped'] );
		self::assertSame( 'uncategorized', $client->sent_products[0]['category_path'] );
		self::assertNotSame( '', $client->sent_products[0]['product_url'] );
		self::assertSame( 'true', $client->sent_products[0]['tags']['category_defaulted'], 'A force-filled category_path is flagged, per PRO-1499.' );
		self::assertFalse( $client->sent_products[0]['in_stock'] );
		self::assertSame( 'del-blank-uuid', $client->sent_products[0]['event_id'] );
	}

	public function test_delete_row_with_no_captured_object_falls_back_to_build_unresolvable(): void {
		// PRO-1506: a row whose payload has no 'object' at all (corrupt or a
		// legacy shape) must still reach the engine as a minimal tombstone,
		// never a terminal skip with nothing sent.
		$row               = $this->upsert_row( 6, 42, 'del-missing-uuid' );
		$row['event_type'] = CatalogHookHandler::EVENT_CATALOG_DELETE;
		$row['payload']    = '';

		$queue  = $this->fake_queue( array( $row ) );
		$client = $this->success_client();
		$flush  = $this->fake_flusher( $queue, $client, true );

		$stats = $flush->flush();

		self::assertSame( array( 6 ), $queue->sent );
		self::assertSame( 0, $stats['skipped'] );
		self::assertSame( 'woo-42', $client->sent_products[0]['sku'] );
		self::assertFalse( $client->sent_products[0]['in_stock'] );
	}

	public function test_upsert_for_a_deleted_product_is_skipped_not_sent(): void {
		// get_product returns null (product gone since enqueue) → terminal skip.
		$queue  = $this->fake_queue( array( $this->upsert_row( 9, 999, 'u9' ) ) );
		$client = $this->success_client();
		$flush  = $this->fake_flusher( $queue, $client, true, array() ); // no product registered for 999

		$stats = $flush->flush();

		self::assertSame( 1, $stats['skipped'] );
		self::assertSame( array( 9 ), $queue->sent, 'A vanished product is marked sent so the row leaves the queue.' );
		self::assertSame( array(), $client->sent_products, 'Nothing to POST when the only row was skipped.' );
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
			/** @var array<int, string>|null Event types the flusher scoped the drain to. */
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
	 * @param array<string, mixed> $response Override the canned D6 body (e.g. a dedup retry).
	 */
	private function success_client( array $response = array() ): Client {
		return new class( $response ) extends Client {
			/** @var array<int, array<string, mixed>> */
			public array $sent_products = array();
			/** @var array<string, mixed> */
			private array $response;

			/** @param array<string, mixed> $response */
			public function __construct( array $response ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->response = $response;
			}

			public function ingest_catalog( array $products ): array {
				$this->sent_products = $products;
				// Default to a D6 all-processed body so the invariant holds; a
				// test may override (e.g. a dedup retry).
				return $this->response !== array()
					? $this->response
					: array( 'ok' => true, 'processed' => count( $products ), 'deduplicated' => 0, 'errors' => array() );
			}
		};
	}

	private function throwing_client( ApiException $e ): Client {
		return new class( $e ) extends Client {
			/** @var array<int, array<string, mixed>> */
			public array $sent_products = array();
			private ApiException $e;

			public function __construct( ApiException $e ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->e = $e;
			}

			public function ingest_catalog( array $products ): array {
				$this->sent_products = $products;
				throw $this->e;
			}
		};
	}

	/**
	 * @param array<int, true> $products_by_id Entity ids that resolve to a (stub) product.
	 */
	private function fake_flusher( IngestQueue $queue, Client $client, bool $connected, array $products_by_id = array() ): IngestFlusher {
		$settings = new class( $connected ) extends RecEngineSettings {
			private bool $connected;
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}
			public function is_connected(): bool {
				return $this->connected;
			}
		};

		$builder = new class() extends CatalogPayloadBuilder {
			public function build( \WC_Product $product, string $event_uuid ): array {
				return array( 'sku' => 'SKU-' . $event_uuid, 'event_id' => $event_uuid, 'in_stock' => true );
			}
			public function build_unresolvable( int $product_id, string $event_uuid ): array {
				return array(
					'event_id'      => $event_uuid,
					'sku'           => 'woo-' . $product_id,
					'name'          => 'Unavailable product #' . $product_id,
					'category_path' => 'uncategorized',
					'price'         => 0.0,
					'in_stock'      => false,
					'product_url'   => 'https://shop.test/?smaily_connect_removed_product=' . $product_id,
					'external_id'   => (string) $product_id,
					'tags'          => array( 'category_defaulted' => 'true' ),
				);
			}
			// Mirrors the real ensure_valid_removal() output shape without calling
			// the real home_url() — this raw (non-Brain\Monkey) test file has no
			// WordPress loaded; the real method's behaviour is covered directly in
			// CatalogPayloadBuilderTest.
			public function ensure_valid_removal( array $object ): array {
				if ( (string) ( $object['category_path'] ?? '' ) === '' ) {
					$object['category_path'] = 'uncategorized';
					$tags                       = is_array( $object['tags'] ?? null ) ? $object['tags'] : array();
					$tags['category_defaulted'] = 'true';
					$object['tags']             = $tags;
				}
				$product_url = $object['product_url'] ?? '';
				$has_url     = is_array( $product_url ) ? $product_url !== array() : (string) $product_url !== '';
				if ( ! $has_url ) {
					$object['product_url'] = 'https://shop.test/?smaily_connect_removed_product=' . ( $object['external_id'] ?? '0' );
				}
				return $object;
			}
		};

		$factory = static function () use ( $client ): Client {
			return $client;
		};

		return new class( $queue, $builder, $settings, $factory, $products_by_id ) extends IngestFlusher {
			/** @var array<int, true> */
			private array $products_by_id;

			/**
			 * @param callable(): Client $factory
			 * @param array<int, true>   $products_by_id
			 */
			public function __construct( IngestQueue $queue, CatalogPayloadBuilder $builder, RecEngineSettings $settings, callable $factory, array $products_by_id ) {
				parent::__construct( $queue, $builder, $settings, $factory );
				$this->products_by_id = $products_by_id;
			}

			protected function get_product( int $product_id ): ?\WC_Product {
				return isset( $this->products_by_id[ $product_id ] ) ? new \WC_Product() : null;
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function upsert_row( int $id, int $entity_id, string $uuid, int $attempts = 0, int $max = 5 ): array {
		return array(
			'id'           => $id,
			'event_type'   => CatalogHookHandler::EVENT_CATALOG_UPSERT,
			'entity_id'    => (string) $entity_id,
			'event_uuid'   => $uuid,
			'payload'      => '',
			'attempts'     => $attempts,
			'max_attempts' => $max,
		);
	}
}

// Minimal WC_Product shim the flusher's get_product() returns; the doubled
// CatalogPayloadBuilder ignores it, so no methods are needed.
if ( ! class_exists( \WC_Product::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( 'class WC_Product {}' );
}
