<?php
/**
 * CatalogManifest tests — the nightly §3c manifest: what makes a night skip
 * (nothing sent, no row), the over-limit row, and the one Event Log row with
 * the engine's answer.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;

final class CatalogManifestTest extends TestCase {

	private const ITEMS = array(
		array(
			'sku'      => 'woo-1',
			'in_stock' => true,
		),
		array(
			'sku'      => 'woo-2',
			'in_stock' => false,
		),
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sends_the_list_and_records_one_sent_row_with_the_engine_answer(): void {
		$queue  = $this->fake_queue();
		$client = $this->client(
			array(
				'ok'                   => true,
				'products_in_manifest' => 2,
				'removed'              => 1,
				'stock_fixed'          => 1,
				'missing_in_engine'    => 0,
				'guard_tripped'        => false,
				'guard_reason'         => null,
				'would_remove'         => 1,
			)
		);

		$this->manifest( $queue, $client )->run();

		self::assertSame( array( self::ITEMS ), $client->sent, 'One request, the whole list.' );
		self::assertCount( 1, $queue->enqueued );
		self::assertSame( CatalogManifest::EVENT_TYPE, $queue->enqueued[0] );
		self::assertSame( array( 1 ), $queue->sent );
		self::assertSame( (string) wp_json_encode( array( 'products' => self::ITEMS ) ), $queue->exchanges[1]['sent'] );
		$answer = json_decode( (string) $queue->exchanges[1]['response'], true );
		self::assertSame( 'accepted', $answer['outcome'] );
		self::assertSame( 1, $answer['removed'] );
		self::assertSame( 1, $answer['stock_fixed'] );
		self::assertFalse( $answer['guard_tripped'] );
		self::assertArrayNotHasKey( 'ok', $answer );
	}

	public function test_nothing_is_sent_while_the_engine_refuses_the_store(): void {
		$queue  = $this->fake_queue();
		$client = $this->client();

		$this->manifest( $queue, $client, new FakeRecEngineSettings( true, true ) )->run();

		self::assertSame( array(), $client->sent );
		self::assertSame( array(), $queue->enqueued, 'A skipped night writes no row.' );
	}

	public function test_nothing_is_sent_while_the_import_runs_or_waits(): void {
		$queue  = $this->fake_queue();
		$client = $this->client();

		$this->manifest( $queue, $client, null, array( 'import_active' => true ) )->run();

		self::assertSame( array(), $client->sent );
		self::assertSame( array(), $queue->enqueued );
	}

	public function test_nothing_is_sent_while_catalog_changes_wait(): void {
		$queue  = $this->fake_queue();
		$client = $this->client();

		$this->manifest( $queue, $client, null, array( 'changes_waiting' => true ) )->run();

		self::assertSame( array(), $client->sent );
		self::assertSame( array(), $queue->enqueued );
	}

	public function test_a_list_that_fails_to_build_is_never_sent(): void {
		$queue  = $this->fake_queue();
		$client = $this->client();

		$this->manifest( $queue, $client, null, array( 'throw' => true ) )->run();

		self::assertSame( array(), $client->sent );
		self::assertSame( array(), $queue->enqueued );
	}

	public function test_over_the_limit_sends_nothing_and_says_why_on_a_failed_row(): void {
		$queue  = $this->fake_queue();
		$client = $this->client();

		$this->manifest( $queue, $client, null, array( 'limit' => 1 ) )->run();

		self::assertSame( array(), $client->sent, 'A partial list is never sent.' );
		self::assertSame( 1, $queue->failed[0]['id'] );
		self::assertSame( 'Not sent: the store has more than 1 products, the most the nightly product list can hold.', $queue->failed[0]['error'] );
		self::assertNull( $queue->exchanges[1]['sent'] );
		self::assertStringContainsString( '"reason":"too_many_products"', (string) $queue->exchanges[1]['response'] );
	}

	public function test_an_engine_rejection_fails_the_row_with_the_answer(): void {
		$queue  = $this->fake_queue();
		$client = $this->client( array(), new ApiException( 400, 'validation_failed', 'bad item' ) );

		$this->manifest( $queue, $client )->run();

		self::assertSame( array( 'id' => 1, 'error' => 'http_400 validation_failed' ), $queue->failed[0] );
		self::assertSame( array(), $queue->sent );
		self::assertStringContainsString( '"outcome":"http_error"', (string) $queue->exchanges[1]['response'] );
	}

	public function test_a_retried_row_carries_the_next_manifest(): void {
		$queue          = $this->fake_queue();
		$queue->waiting = array(
			array(
				'id'         => 42,
				'event_type' => CatalogManifest::EVENT_TYPE,
			),
		);
		$client         = $this->client();

		$this->manifest( $queue, $client )->run();

		self::assertSame( array(), $queue->enqueued, 'No second row.' );
		self::assertSame( array( 42 ), $queue->sent );
		self::assertSame( array( CatalogManifest::EVENT_TYPE ), $queue->pending_types );
	}

	public function test_first_run_is_the_next_three_o_clock_store_time(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Tallinn' ) );

		// 2026-10-06 17:00 UTC = 20:00 in Tallinn → tomorrow 03:00 local = 00:00 UTC.
		self::assertSame( gmmktime( 0, 0, 0, 10, 7, 2026 ), CatalogManifest::next_run_timestamp( gmmktime( 17, 0, 0, 10, 6, 2026 ) ) );
		// 2026-10-06 22:30 UTC = 01:30 on the 7th in Tallinn → 03:00 that same local day.
		self::assertSame( gmmktime( 0, 0, 0, 10, 7, 2026 ), CatalogManifest::next_run_timestamp( gmmktime( 22, 30, 0, 10, 6, 2026 ) ) );
	}

	// --- doubles -------------------------------------------------------------

	/**
	 * @param array{import_active?: bool, changes_waiting?: bool, throw?: bool, limit?: int} $opts
	 */
	private function manifest( IngestQueue $queue, Client $client, ?RecEngineSettings $settings = null, array $opts = array() ): CatalogManifest {
		$catalog = new class( ! empty( $opts['throw'] ) ) extends CatalogBackfillJob {
			private bool $throw;

			public function __construct( bool $throw ) {
				$this->throw = $throw;
			}

			public function manifest_items( int $limit ): array {
				if ( $this->throw ) {
					throw new \RuntimeException( 'out of memory' );
				}
				return array_slice( CatalogManifestTest::items(), 0, $limit + 1 );
			}
		};

		return new class( $queue, $settings ?? new FakeRecEngineSettings(), static fn (): Client => $client, $catalog, $opts ) extends CatalogManifest {
			/** @var array{import_active?: bool, changes_waiting?: bool, throw?: bool, limit?: int} */
			private array $opts;

			/**
			 * @param array{import_active?: bool, changes_waiting?: bool, throw?: bool, limit?: int} $opts
			 */
			public function __construct( IngestQueue $queue, RecEngineSettings $settings, callable $client_factory, CatalogBackfillJob $catalog, array $opts ) {
				parent::__construct( $queue, $settings, $client_factory, $catalog );
				$this->opts = $opts;
			}

			protected function import_active(): bool {
				return ! empty( $this->opts['import_active'] );
			}

			protected function catalog_changes_waiting(): bool {
				return ! empty( $this->opts['changes_waiting'] );
			}

			protected function max_products(): int {
				return $this->opts['limit'] ?? parent::max_products();
			}
		};
	}

	/**
	 * @return array<int, array{sku: string, in_stock: bool}>
	 */
	public static function items(): array {
		return self::ITEMS;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function client( array $response = array( 'ok' => true ), ?ApiException $error = null ): Client {
		return new class( $response, $error ) extends Client {
			/** @var array<int, array<int, array<string, mixed>>> */
			public array $sent = array();
			/** @var array<string, mixed> */
			private array $response;
			private ?ApiException $error;

			/** @param array<string, mixed> $response */
			public function __construct( array $response, ?ApiException $error ) {
				parent::__construct( 'sk_test', 'https://e.test' );
				$this->response = $response;
				$this->error    = $error;
			}

			public function catalog_manifest( array $products ): array {
				if ( $this->error !== null ) {
					throw $this->error;
				}
				$this->sent[] = $products;
				return $this->response;
			}
		};
	}

	private function fake_queue(): IngestQueue {
		return new class() extends IngestQueue {
			/** @var array<int, array<string, mixed>> */
			public array $waiting = array();
			/** @var array<int, string>|null */
			public ?array $pending_types = null;
			/** @var array<int, string> */
			public array $enqueued = array();
			/** @var array<int, int> */
			public array $sent = array();
			/** @var array<int, array{id: int, error: string}> */
			public array $failed = array();
			/** @var array<int, array{sent: ?string, response: ?string}> */
			public array $exchanges = array();

			public function pending( int $limit = 100, ?array $event_types = null ): array {
				$this->pending_types = $event_types;
				return $this->waiting;
			}
			public function enqueue( string $event_type, string $entity_id, array $payload, ?string $event_uuid = null, ?string $flush_hook = null, ?string $flush_group = null ): ?int {
				$this->enqueued[] = $event_type;
				return count( $this->enqueued );
			}
			public function mark_sent( int $id ): void {
				$this->sent[] = $id;
			}
			public function mark_failed( int $id, string $error ): void {
				$this->failed[] = array(
					'id'    => $id,
					'error' => $error,
				);
			}
			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array(
					'sent'     => $sent_payload,
					'response' => $last_response,
				);
			}
		};
	}
}
