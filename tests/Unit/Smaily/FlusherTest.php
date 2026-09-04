<?php
/**
 * Flusher dispatch tests.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\Flusher;
use Smaily\Connect\Smaily\WorkflowMatch;
use Smaily\Connect\Smaily\WorkflowResolverInterface;

final class FlusherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_contact_sync_event_calls_upsert_subscribers_and_marks_sent(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 1,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode(
						array(
							'email'    => 'a@b.c',
							'language' => 'et_EE',
							'fields'   => array(
								'first_name' => 'Alice',
								'user_id'    => '42',
							),
						)
					),
				),
			)
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'upsert_subscribers' )
			->with(
				$this->callback(
					static function ( array $rows ): bool {
						return $rows[0]['email'] === 'a@b.c'
							&& $rows[0]['first_name'] === 'Alice'
							&& $rows[0]['user_id'] === '42';
					}
				)
			);

		$flusher = new Flusher(
			$queue,
			$this->automation_router_returning_true(),
			static fn () => $client
		);

		$stats = $flusher->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
		self::assertSame( 0, $stats['retried'] );
		self::assertSame( array( 1 ), $queue->marked_sent );
	}

	public function test_contact_sync_forwards_language_and_is_unsubscribed(): void {
		// F3-48.6: `language` (F3-47) + `is_unsubscribed` are top-level payload
		// keys the custom-`fields` bag doesn't carry; the Flusher must merge them
		// into the Smaily row (language was previously dropped on the live path).
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 1,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode(
						array(
							'email'           => 'a@b.c',
							'language'        => 'et',
							'is_unsubscribed' => 1,
							'fields'          => array( 'first_name' => 'Alice' ),
						)
					),
				),
			)
		);

		$captured = array();
		$client   = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )->willReturnCallback(
			static function ( array $rows ) use ( &$captured ): array {
				$captured = $rows[0];
				return array();
			}
		);

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client ) )->flush();

		self::assertSame( 'et', $captured['language'] ?? null );
		self::assertSame( 1, $captured['is_unsubscribed'] ?? null );
	}

	public function test_contact_sync_records_the_exchange_from_the_client(): void {
		// F3-44: the Flusher stores what the Client sent + the Smaily reply, read
		// from the Client's last_exchange() (populated in Client::request()).
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 7,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array( 'first_name' => 'Alice' ) ) ),
				),
			)
		);

		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )->willReturn( array() );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array( 'method' => 'POST', 'endpoint' => 'contact', 'body' => array( array( 'email' => 'a@b.c' ) ) ),
				'response' => array( 'http' => 200, 'body' => array( 'code' => 101 ) ),
			)
		);

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client );
		$flusher->flush();

		self::assertArrayHasKey( 7, $queue->exchanges );
		self::assertStringContainsString( '"endpoint":"contact"', (string) $queue->exchanges[7]['sent'] );
		self::assertStringContainsString( '"http":200', (string) $queue->exchanges[7]['response'] );
	}

	public function test_no_call_records_a_skip_exchange(): void {
		// A missing-email contact.sync makes no HTTP call → the exchange records a
		// skip (sent=null, outcome=skipped) so the row isn't a bare "sent" (F3-44).
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 9,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'email' => '', 'fields' => array() ) ),
				),
			)
		);

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $this->createMock( Client::class ) );
		$flusher->flush();

		self::assertNull( $queue->exchanges[9]['sent'] );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $queue->exchanges[9]['response'] );
	}

	public function test_automation_event_routes_through_automation_router(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 5,
					'event_type' => HookHandler::EVENT_AUTOMATION_WELCOME,
					'payload'    => json_encode(
						array(
							'email'    => 'newbie@example.test',
							'language' => 'en_US',
							'fields'   => array( 'first_name' => 'Newbie' ),
						)
					),
				),
			)
		);

		$captured = array();
		$router   = new class( $captured ) extends AutomationRouter {
			private array $sink;

			public function __construct( array &$sink ) {
				$this->sink = &$sink;
				// Skip parent constructor — we override trigger_automation directly.
			}

			public function trigger_automation(
				string $trigger_type,
				array $contact_data,
				array $additional_fields = array()
			): bool {
				$this->sink[] = compact( 'trigger_type', 'contact_data', 'additional_fields' );
				return true;
			}
		};

		$flusher = new Flusher( $queue, $router, static fn () => null );
		$flusher->flush();

		self::assertCount( 1, $captured );
		self::assertSame( 'welcome', $captured[0]['trigger_type'] );
		self::assertSame( 'newbie@example.test', $captured[0]['contact_data']['email'] );
		self::assertSame( 'en_US', $captured[0]['contact_data']['language'] );
		self::assertSame( array( 'first_name' => 'Newbie' ), $captured[0]['additional_fields'] );
	}

	public function test_missing_email_short_circuits_and_marks_sent(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 9,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'fields' => array( 'first_name' => 'No-Email' ) ) ),
				),
			)
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'upsert_subscribers' );

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client );
		$stats   = $flusher->flush();

		self::assertSame( 1, $stats['sent'], 'Missing email is terminal-skip → mark_sent.' );
		self::assertSame( 0, $stats['failed'] );
	}

	public function test_unknown_event_type_marks_failed(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 10,
					'event_type' => 'mystery.unknown',
					'payload'    => json_encode( array( 'email' => 'a@b.c' ) ),
				),
			)
		);

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => null );
		$stats   = $flusher->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 1, $stats['failed'] );
		self::assertCount( 1, $queue->marked_failed );
		self::assertStringContainsString( 'unknown_event_type', $queue->marked_failed[0]['error'] );
	}

	public function test_malformed_payload_marks_failed(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 11,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => 'not-json',
				),
			)
		);

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => null );
		$stats   = $flusher->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 'payload_decode_failure', $queue->marked_failed[0]['error'] );
	}

	public function test_api_exception_records_attempt_for_retry(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 12,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array() ) ),
				),
			)
		);

		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )
			->willThrowException( new ApiException( 'rate limited', 429 ) );

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client );
		$stats   = $flusher->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
		self::assertSame( 1, $stats['retried'], 'ApiException → record_attempt, not mark_failed.' );
		self::assertCount( 1, $queue->attempts );
		self::assertSame( 12, $queue->attempts[0]['id'] );
		self::assertSame( 60, $queue->attempts[0]['retry_in_seconds'], 'First retry waits one flush cadence.' );
	}

	public function test_a_permanent_refusal_stops_being_retried(): void {
		// PRO-1685: a 4xx that can never succeed (revoked credentials here)
		// used to be recorded as an attempt and re-POSTed every 60s forever.
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 12,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array() ) ),
					'attempts'   => 0,
				),
			)
		);

		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )
			->willThrowException( new ApiException( 'Smaily API returned HTTP 401 for POST contact', 401 ) );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 0, $stats['retried'] );
		self::assertSame( array(), $queue->attempts );
		self::assertStringContainsString( 'permanent_http_401', $queue->marked_failed[0]['error'] );
	}

	public function test_the_retry_ceiling_fails_a_row_that_keeps_failing_transiently(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 12,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array() ) ),
					'attempts'   => \Smaily\Connect\Smaily\RetryPolicy::MAX_ATTEMPTS - 1,
				),
			)
		);

		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )
			->willThrowException( new ApiException( 'Smaily API returned HTTP 503 for POST contact', 503 ) );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertStringContainsString( 'retry_limit_exceeded', $queue->marked_failed[0]['error'] );
	}

	public function test_automation_skip_no_workflow_match_is_terminal_sent(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 13,
					'event_type' => HookHandler::EVENT_AUTOMATION_WELCOME,
					'payload'    => json_encode(
						array(
							'email'    => 'no-mapping@example.test',
							'language' => 'fr_FR',
							'fields'   => array(),
						)
					),
				),
			)
		);

		$router = new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation(
				string $trigger_type,
				array $contact_data,
				array $additional_fields = array()
			): bool {
				return false; // no workflow mapped — terminal skip
			}
		};

		$flusher = new Flusher( $queue, $router, static fn () => null );
		$stats   = $flusher->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( 0, $stats['retried'] );
	}

	public function test_flush_excludes_the_cart_event_type_from_its_drain(): void {
		// PRO-1195: automation.abandoned_cart rows belong to the CartFlusher
		// (its own AS action); PRO-1504 Stage 2 adds the two transactional
		// types belonging to TransactionalFlusher. The main drain must
		// exclude all three at the pending() query so no flusher consumes
		// another's rows.
		$queue = $this->fake_queue( array() );

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => null ) )->flush();

		self::assertSame(
			array(
				Flusher::DEFAULT_BATCH_SIZE,
				null,
				array(
					\Smaily\Connect\Smaily\CartFlusher::EVENT_TYPE,
					\Smaily\Connect\Smaily\TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					\Smaily\Connect\Smaily\TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
				),
			),
			$queue->last_pending_args
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

			public function __construct( array $events ) {
				$this->events = $events;
			}

			/** @var array<int, mixed> The (limit, only, exclude) args of the last pending() call. */
			public array $last_pending_args = array();

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
			/** @var array<int, array{sent: ?string, response: ?string}> */
			public array $exchanges = array();
			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( 'sent' => $sent_payload, 'response' => $last_response );
			}
		};
	}

	private function automation_router_returning_true(): AutomationRouter {
		return new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation(
				string $trigger_type,
				array $contact_data,
				array $additional_fields = array()
			): bool {
				return true;
			}
		};
	}
}
