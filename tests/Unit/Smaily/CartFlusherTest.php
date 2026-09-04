<?php
/**
 * CartFlusher dispatch tests (PRO-1195).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\EventQueue;

require_once dirname( __DIR__, 3 ) . '/includes/smaily-options.class.php';

final class CartFlusherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default: legacy status option with no autoresponder id; individual
		// tests override.
		$this->stub_status_option( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_flush_drains_only_the_cart_event_type(): void {
		$queue = $this->fake_queue( array() );

		( new CartFlusher( $queue, $this->router_returning( true ), static fn () => null ) )->flush();

		self::assertSame(
			array( CartFlusher::DEFAULT_BATCH_SIZE, array( CartFlusher::EVENT_TYPE ), array() ),
			$queue->last_pending_args,
			'The cart drain must scope pending() to its own event type — other flushers own the rest.'
		);
	}

	public function test_routed_send_marks_sent_and_stores_the_router_exchange(): void {
		$queue = $this->fake_queue( array( $this->cart_event( 1 ) ) );

		$router = new class extends AutomationRouter {
			public array $calls = array();

			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array() ): bool {
				$this->calls[] = compact( 'trigger_type', 'contact_data', 'additional_fields' );
				return true;
			}

			public function last_exchange(): ?array {
				return array(
					'request'  => array( 'method' => 'POST', 'endpoint' => 'autoresponder', 'body' => array( 'autoresponder' => 4242 ) ),
					'response' => array( 'http' => 200, 'body' => array( 'code' => 101 ) ),
				);
			}
		};

		$stats = ( new CartFlusher( $queue, $router, static fn () => null ) )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( array( 1 ), $queue->marked_sent );
		self::assertSame( 'abandoned_cart', $router->calls[0]['trigger_type'] );
		self::assertSame( 'buyer@example.test', $router->calls[0]['contact_data']['email'] );
		self::assertSame( 'et', $router->calls[0]['contact_data']['language'] );
		self::assertSame( 'true', $router->calls[0]['additional_fields']['is_abandoned_cart'] );
		self::assertStringContainsString( '"endpoint":"autoresponder"', (string) $queue->exchanges[1]['sent'] );
		self::assertStringContainsString( '"http":200', (string) $queue->exchanges[1]['response'] );
	}

	public function test_no_mapping_falls_back_to_the_legacy_autoresponder_id(): void {
		// F3-54 carry-over: an upgraded store whose only workflow source is
		// the legacy option's autoresponder_id keeps sending — force_opt_in
		// false, the legacy fallback's exact posture.
		$this->stub_status_option( 88 );

		$queue = $this->fake_queue( array( $this->cart_event( 2 ) ) );

		$captured = array();
		$client   = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'trigger_automation' )
			->willReturnCallback(
				static function ( int $workflow_id, array $addresses, bool $force_opt_in ) use ( &$captured ): array {
					$captured = compact( 'workflow_id', 'addresses', 'force_opt_in' );
					return array( 'code' => 101 );
				}
			);
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array( 'method' => 'POST', 'endpoint' => 'autoresponder', 'body' => array( 'autoresponder' => 88 ) ),
				'response' => array( 'http' => 200, 'body' => array( 'code' => 101 ) ),
			)
		);

		$stats = ( new CartFlusher( $queue, $this->router_returning( false ), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( 88, $captured['workflow_id'] );
		self::assertFalse( $captured['force_opt_in'] );
		self::assertSame( 'buyer@example.test', $captured['addresses'][0]['email'] );
		self::assertSame( 'true', $captured['addresses'][0]['is_abandoned_cart'] );
		self::assertStringContainsString( '"autoresponder":88', (string) $queue->exchanges[2]['sent'] );
	}

	public function test_no_mapping_and_no_autoresponder_is_a_terminal_skip_with_a_skip_marker(): void {
		$queue = $this->fake_queue( array( $this->cart_event( 3 ) ) );

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'trigger_automation' );

		$stats = ( new CartFlusher( $queue, $this->router_returning( false ), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['sent'], 'Unconfigured is a terminal skip (mark_sent) — a retry cannot conjure a workflow.' );
		self::assertNull( $queue->exchanges[3]['sent'] );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $queue->exchanges[3]['response'] );
	}

	public function test_fallback_non_101_body_code_is_terminal_failed(): void {
		// The legacy API signals failure inside HTTP 200 (code 101 = OK). A
		// deleted autoresponder id is deterministic — mark_failed, never an
		// eternal 60s retry loop (the F3-53 class).
		$this->stub_status_option( 77 );

		$queue = $this->fake_queue( array( $this->cart_event( 4 ) ) );

		$client = $this->createMock( Client::class );
		$client->method( 'trigger_automation' )->willReturn(
			array(
				'code'    => 203,
				'message' => 'no such autoresponder',
			)
		);
		$client->method( 'last_exchange' )->willReturn( array( 'request' => array(), 'response' => array( 'http' => 200 ) ) );

		$stats = ( new CartFlusher( $queue, $this->router_returning( false ), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertStringContainsString( 'smaily_response_code_203', $queue->marked_failed[0]['error'] );
	}

	public function test_api_exception_records_attempt_for_retry(): void {
		$queue = $this->fake_queue( array( $this->cart_event( 5 ) ) );

		$router = new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array() ): bool {
				throw new ApiException( 'rate limited', 429 );
			}

			public function last_exchange(): ?array {
				return array( 'request' => array( 'endpoint' => 'autoresponder' ), 'response' => array( 'http' => 429 ) );
			}
		};

		$stats = ( new CartFlusher( $queue, $router, static fn () => null ) )->flush();

		self::assertSame( 1, $stats['retried'], 'ApiException is transient → record_attempt.' );
		self::assertSame( 5, $queue->attempts[0]['id'] );
		self::assertStringContainsString( '"http":429', (string) $queue->exchanges[5]['response'], 'The throwing exchange is still captured (try/finally, F3-44).' );
	}

	public function test_a_permanent_refusal_stops_being_retried(): void {
		// PRO-1685: same policy as the main flusher — a 4xx that can never
		// succeed fails the row instead of being retried every 60s forever.
		$queue = $this->fake_queue( array( $this->cart_event( 5 ) ) );

		$router = new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array() ): bool {
				throw new ApiException( 'Smaily API returned HTTP 403 for POST autoresponder', 403 );
			}

			public function last_exchange(): ?array {
				return array( 'request' => array( 'endpoint' => 'autoresponder' ), 'response' => array( 'http' => 403 ) );
			}
		};

		$stats = ( new CartFlusher( $queue, $router, static fn () => null ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->attempts );
		self::assertStringContainsString( 'permanent_http_403', $queue->marked_failed[0]['error'] );
	}

	public function test_malformed_payload_marks_failed(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 6,
					'event_type' => CartFlusher::EVENT_TYPE,
					'payload'    => 'not-json',
				),
			)
		);

		$stats = ( new CartFlusher( $queue, $this->router_returning( true ), static fn () => null ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 'payload_decode_failure', $queue->marked_failed[0]['error'] );
	}

	public function test_unexpected_throwable_is_terminal_failed_not_an_eternal_retry(): void {
		// F3-53: a deterministic throw (here an unconfigured client factory)
		// must terminal-mark the row, not park it pending for every future tick.
		$this->stub_status_option( 99 );

		$queue = $this->fake_queue( array( $this->cart_event( 7 ) ) );

		$stats = ( new CartFlusher(
			$queue,
			$this->router_returning( false ),
			static function (): Client {
				throw new \RuntimeException( 'Smaily credentials are not configured' );
			}
		) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 0, $stats['retried'] );
		self::assertStringContainsString( 'RuntimeException', $queue->marked_failed[0]['error'] );
	}

	public function test_missing_email_is_a_terminal_skip(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 8,
					'event_type' => CartFlusher::EVENT_TYPE,
					'payload'    => json_encode( array( 'fields' => array() ) ),
				),
			)
		);

		$stats = ( new CartFlusher( $queue, $this->router_returning( true ), static fn () => null ) )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $queue->exchanges[8]['response'] );
	}

	// --- helpers -------------------------------------------------------------

	private function stub_status_option( int $autoresponder_id ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( $autoresponder_id ) {
				if ( $key === \Smaily_Connect\Includes\Options::ABANDONED_CART_STATUS_OPTION ) {
					return array(
						'enabled'          => true,
						'autoresponder_id' => $autoresponder_id,
					);
				}
				return $fallback;
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cart_event( int $id ): array {
		return array(
			'id'         => $id,
			'event_type' => CartFlusher::EVENT_TYPE,
			'payload'    => json_encode(
				array(
					'email'    => 'buyer@example.test',
					'language' => 'et',
					'fields'   => array(
						'is_abandoned_cart' => 'true',
						'product_name_1'    => 'Koeratoit',
					),
				)
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	private function fake_queue( array $events ): EventQueue {
		return new class( $events ) extends EventQueue {
			/** @var array<int, array<string, mixed>> */
			private array $events;

			public array $marked_sent   = array();
			public array $marked_failed = array();
			public array $attempts      = array();

			/** @var array<int, mixed> */
			public array $last_pending_args = array();

			/** @var array<int, array{sent: ?string, response: ?string}> */
			public array $exchanges = array();

			public function __construct( array $events ) {
				$this->events = $events;
			}

			public function pending( int $limit = 50, ?array $only_types = null, array $exclude_types = array() ): array {
				$this->last_pending_args = array( $limit, $only_types, $exclude_types );
				return $this->events;
			}

			public function mark_sent( int $id ): void {
				$this->marked_sent[] = $id;
			}

			public function mark_failed( int $id, string $error ): void {
				$this->marked_failed[] = compact( 'id', 'error' );
			}

			public function record_attempt( int $id, string $error, int $retry_in_seconds = 0 ): void {
				$this->attempts[] = compact( 'id', 'error', 'retry_in_seconds' );
			}

			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( 'sent' => $sent_payload, 'response' => $last_response );
			}
		};
	}

	private function router_returning( bool $result ): AutomationRouter {
		return new class( $result ) extends AutomationRouter {
			private bool $result;

			public function __construct( bool $result ) {
				$this->result = $result;
			}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array() ): bool {
				return $this->result;
			}

			public function last_exchange(): ?array {
				return null;
			}
		};
	}
}
