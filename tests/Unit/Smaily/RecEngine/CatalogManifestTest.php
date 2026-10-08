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

	public const ITEMS = array(
		array(
			'sku'      => 'woo-1',
			'in_stock' => true,
		),
		array(
			'sku'      => 'woo-2',
			'in_stock' => false,
		),
	);

	/** The process's own PHP time limit, restored after each test. */
	private int $time_limit = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->time_limit = (int) ini_get( 'max_execution_time' );
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n ) );
	}

	protected function tearDown(): void {
		set_time_limit( $this->time_limit );
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

	public function test_a_list_that_fails_to_build_is_never_sent_and_the_row_says_why(): void {
		$queue  = $this->fake_queue();
		$client = $this->client();

		$this->manifest( $queue, $client, null, array( 'throw' => true ) )->run();

		self::assertSame( array(), $client->sent );
		self::assertSame( array( CatalogManifest::EVENT_TYPE ), $queue->enqueued );
		self::assertSame( 1, $queue->failed[0]['id'] );
		self::assertStringStartsWith( 'Not sent: building the product list stopped on an unexpected error (RuntimeException at CatalogManifestTest.php:', $queue->failed[0]['error'] );
		self::assertStringNotContainsString( 'out of memory', $queue->failed[0]['error'], 'The class and place, never the message.' );
		self::assertSame( array(), $queue->sent );
		self::assertNull( $queue->exchanges[1]['sent'] );
		self::assertStringContainsString( '"reason":"build_failed"', (string) $queue->exchanges[1]['response'] );
	}

	public function test_the_row_exists_and_the_time_limit_is_raised_before_the_walk(): void {
		$queue = $this->fake_queue();
		$seen  = array();
		$walk  = static function () use ( $queue, &$seen ): void {
			$seen = array(
				'rows'       => $queue->enqueued,
				'time_limit' => ini_get( 'max_execution_time' ),
			);
		};
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- A host's 30 s limit, restored in tearDown().
		ini_set( 'max_execution_time', '30' );

		$this->manifest( $queue, $this->client(), null, array( 'on_walk' => $walk ) )->run();

		self::assertSame( array( CatalogManifest::EVENT_TYPE ), $seen['rows'], 'The Event Log row is written before the walk begins.' );
		self::assertSame( (string) CatalogManifest::TIME_LIMIT_SECONDS, $seen['time_limit'], 'The time limit is raised before the walk begins.' );
		self::assertSame( array( 1 ), $queue->sent );
	}

	public function test_an_unlimited_or_higher_time_limit_is_left_alone(): void {
		foreach ( array( '0', '900' ) as $limit ) {
			$seen = null;
			$walk = static function () use ( &$seen ): void {
				$seen = ini_get( 'max_execution_time' );
			};
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restored in tearDown().
			ini_set( 'max_execution_time', $limit );

			$this->manifest( $this->fake_queue(), $this->client(), null, array( 'on_walk' => $walk ) )->run();

			self::assertSame( $limit, $seen );
		}
	}

	public function test_an_unexpected_error_during_the_send_fails_the_row_with_a_plain_reason(): void {
		$queue  = $this->fake_queue();
		$client = $this->client( array(), new \TypeError( 'secret@example.com' ) );

		$this->manifest( $queue, $client )->run();

		self::assertSame( array(), $queue->sent );
		self::assertSame( 1, $queue->failed[0]['id'] );
		self::assertStringStartsWith( 'The send stopped on an unexpected error (TypeError at CatalogManifestTest.php:', $queue->failed[0]['error'] );
		self::assertStringNotContainsString( 'secret@example.com', $queue->failed[0]['error'] );
		self::assertSame( (string) wp_json_encode( array( 'products' => self::ITEMS ) ), $queue->exchanges[1]['sent'] );
		self::assertSame( '{"outcome":"error"}', $queue->exchanges[1]['response'] );
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

	/**
	 * The stored request encodes only the head of the list; it must equal the
	 * whole list encoded and then capped, above the cap and below it.
	 */
	public function test_the_stored_request_equals_the_whole_list_encoded_and_capped(): void {
		$shortest = array(
			'sku'      => 'woo-1',
			'in_stock' => true,
		);
		$mixed    = array();
		for ( $i = 1; $i <= 2000; $i++ ) {
			$mixed[] = array(
				'sku'      => 'woo-' . $i,
				'in_stock' => $i % 3 !== 0,
			);
		}

		$lists = array(
			'shortest items, above the cap' => array_fill( 0, 1000, $shortest ),
			'shortest items, just above'    => array_fill( 0, 334, $shortest ),
			'shortest items, below the cap' => array_fill( 0, 300, $shortest ),
			'mixed items, above the cap'    => $mixed,
			'two items'                     => self::ITEMS,
		);

		foreach ( $lists as $label => $items ) {
			$queue = $this->fake_queue();

			$this->manifest( $queue, $this->client(), null, array( 'items' => $items ) )->run();

			$whole    = (string) wp_json_encode( array( 'products' => $items ) );
			$expected = strlen( $whole ) <= 10000 ? $whole : substr( $whole, 0, 10000 ) . '…[truncated]';
			self::assertSame( $expected, $queue->exchanges[1]['sent'], $label );
		}
		self::assertGreaterThan( 10000, strlen( (string) wp_json_encode( array( 'products' => $lists['shortest items, just above'] ) ) ) );
		self::assertLessThan( 10000, strlen( (string) wp_json_encode( array( 'products' => $lists['shortest items, below the cap'] ) ) ) );
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
	 * @param array{import_active?: bool, changes_waiting?: bool, throw?: bool, limit?: int, items?: array<int, array{sku: string, in_stock: bool}>, on_walk?: callable(): void} $opts
	 */
	private function manifest( IngestQueue $queue, Client $client, ?RecEngineSettings $settings = null, array $opts = array() ): CatalogManifest {
		$catalog = new class( ! empty( $opts['throw'] ), $opts['items'] ?? null, $opts['on_walk'] ?? null ) extends CatalogBackfillJob {
			private bool $throw;
			/** @var array<int, array{sku: string, in_stock: bool}>|null */
			private ?array $items;
			/** @var (callable(): void)|null */
			private $on_walk;

			/**
			 * @param array<int, array{sku: string, in_stock: bool}>|null $items
			 * @param (callable(): void)|null                            $on_walk
			 */
			public function __construct( bool $throw, ?array $items, ?callable $on_walk ) {
				$this->throw   = $throw;
				$this->items   = $items;
				$this->on_walk = $on_walk;
			}

			public function manifest_items( int $limit ): array {
				if ( $this->on_walk !== null ) {
					( $this->on_walk )();
				}
				if ( $this->throw ) {
					throw new \RuntimeException( 'out of memory' );
				}
				return array_slice( $this->items ?? CatalogManifestTest::ITEMS, 0, $limit + 1 );
			}
		};

		return new class( $queue, $settings ?? new FakeRecEngineSettings(), static fn (): Client => $client, $catalog, $opts ) extends CatalogManifest {
			/** @var array{import_active?: bool, changes_waiting?: bool, throw?: bool, limit?: int, items?: array<int, array{sku: string, in_stock: bool}>, on_walk?: callable(): void} */
			private array $opts;

			/**
			 * @param array{import_active?: bool, changes_waiting?: bool, throw?: bool, limit?: int, items?: array<int, array{sku: string, in_stock: bool}>, on_walk?: callable(): void} $opts
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
	 * @param array<string, mixed> $response
	 */
	private function client( array $response = array( 'ok' => true ), ?\Throwable $error = null ): Client {
		return new class( $response, $error ) extends Client {
			/** @var array<int, array<int, array<string, mixed>>> */
			public array $sent = array();
			/** @var array<string, mixed> */
			private array $response;
			private ?\Throwable $error;

			/** @param array<string, mixed> $response */
			public function __construct( array $response, ?\Throwable $error ) {
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
