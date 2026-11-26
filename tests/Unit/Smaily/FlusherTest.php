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
use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\AutomationConsent;
use Smaily\Connect\Smaily\AutomationMarker;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\Flusher;
use Smaily\Connect\Smaily\StaleSubscription;
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
			static fn () => $client,
			$this->no_discounts()
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

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

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

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() );
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

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $this->createMock( Client::class ), $this->no_discounts() );
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
				array $additional_fields = array(),
				?callable $before_send = null
			): bool {
				$this->sink[] = compact( 'trigger_type', 'contact_data', 'additional_fields' );
				return true;
			}
		};

		$flusher = new Flusher( $queue, $router, static fn () => null, $this->no_discounts() );
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

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() );
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

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => null, $this->no_discounts() );
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

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => null, $this->no_discounts() );
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

		$flusher = new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() );
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

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 0, $stats['retried'] );
		self::assertSame( array(), $queue->attempts );
		self::assertStringContainsString( 'permanent_http_401', $queue->marked_failed[0]['error'] );
	}

	public function test_an_invalid_data_answer_fails_the_contact_sync_on_the_first_attempt(): void {
		// PRO-3750: HTTP 200 with Smaily code 203 "invalid data" — sending the
		// same data again is rejected again, so the row fails at once with
		// Smaily's answer, and no retry attempt is spent.
		$queue = $this->fake_queue( array( $this->contact_sync_event( 21 ) ) );

		$client = $this->client_answering( 203, 'Invalid data' );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 0, $stats['retried'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 'permanent_envelope_203: Smaily API returned code 203', $queue->marked_failed[0]['error'] );
		self::assertStringContainsString( '"code":203', (string) $queue->exchanges[21]['response'], 'Smaily\'s reply is kept for the Event Log.' );
	}

	public function test_an_invalid_data_answer_fails_an_automation_on_the_first_attempt(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 22,
					'event_type' => HookHandler::EVENT_AUTOMATION_WELCOME,
					'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array() ) ),
				),
			)
		);

		$router = new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				return true;
			}

			public function last_exchange(): ?array {
				return array(
					'request'  => array( 'endpoint' => 'autoresponder' ),
					'response' => array(
						'http' => 200,
						'body' => array(
							'code'    => 203,
							'message' => 'Invalid data',
						),
					),
				);
			}
		};

		$stats = ( new Flusher( $queue, $router, static fn () => null, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->attempts );
		self::assertStringStartsWith( 'permanent_envelope_203: ', $queue->marked_failed[0]['error'] );
	}

	public function test_another_refusing_smaily_code_fails_the_row_instead_of_marking_it_sent(): void {
		// PRO-3862: an HTTP 200 reply with any other refusing Smaily code
		// used to leave the row "sent". It now fails at once with Smaily's
		// answer (the per-code table is pinned in RetryPolicyTest). Smaily's message,
		// which can name the contact, stays out of the reason (CON-301).
		$queue = $this->fake_queue( array( $this->contact_sync_event( 23 ) ) );

		$client = $this->client_answering( 204, 'Invalid email address jane.doe@example.com' );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 'permanent_envelope_204: Smaily API returned code 204', $queue->marked_failed[0]['error'] );
	}

	public function test_a_smaily_database_insert_failure_is_retried_instead_of_marked_sent(): void {
		// PRO-3862: 225 is Smaily's own database error — the same data can
		// pass later, so the row takes the retry ladder.
		$queue = $this->fake_queue( array( $this->contact_sync_event( 24 ) ) );

		$client = $this->client_answering( 225, 'Database insert failed' );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['retried'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( array(), $queue->marked_failed );
		self::assertSame( 'Smaily API returned code 225', $queue->attempts[0]['error'] );
		self::assertSame( 60, $queue->attempts[0]['retry_in_seconds'] );
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

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

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
				array $additional_fields = array(),
				?callable $before_send = null
			): bool {
				return false; // no workflow mapped — terminal skip
			}
		};

		$flusher = new Flusher( $queue, $router, static fn () => null, $this->no_discounts() );
		$stats   = $flusher->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( 0, $stats['retried'] );
		// CON-431: the Event Log says why — e.g. the Smaily account was
		// replaced and its workflows removed.
		self::assertSame( 'no_workflow', EventQueue::skip_reason( (string) $queue->exchanges[13]['response'] ) );
	}

	// --- the automation's discount code (CON-199) -----------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function welcome_event( int $id ): array {
		return array(
			'id'         => $id,
			'event_type' => HookHandler::EVENT_AUTOMATION_WELCOME,
			'payload'    => json_encode(
				array(
					'email'  => 'newbie@example.test',
					'fields' => array( 'welcome_automation_at' => '2026-10-23 10:00:00' ),
				)
			),
		);
	}

	/**
	 * A router that finds a workflow, runs before_send like the real one and
	 * records the address fields it would post.
	 */
	private function router_running_before_send(): AutomationRouter {
		return new class extends AutomationRouter {
			/** @var array<int, array<string, mixed>> */
			public array $posted = array();

			public function __construct() {}

			public function trigger_automation(
				string $trigger_type,
				array $contact_data,
				array $additional_fields = array(),
				?callable $before_send = null
			): bool {
				if ( $before_send !== null ) {
					$additional_fields = array_merge( $additional_fields, $before_send() );
				}
				$this->posted[] = $additional_fields;
				return true;
			}

			public function last_exchange(): ?array {
				return array(
					'request'  => array( 'endpoint' => 'autoresponder' ),
					'response' => array(
						'http' => 200,
						'body' => array( 'code' => 101 ),
					),
				);
			}
		};
	}

	public function test_an_automation_posts_the_discount_code_issued_for_its_row(): void {
		$queue     = $this->fake_queue( array( $this->welcome_event( 5 ) ) );
		$router    = $this->router_running_before_send();
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->expects( self::once() )
			->method( 'issue' )
			->with(
				'welcome',
				self::callback( static fn ( array $payload ): bool => $payload['email'] === 'newbie@example.test' )
			)
			->willReturn(
				array(
					'fields' => array(
						'welcome_coupon_code'       => 'K7QM2XRT9P',
						'welcome_coupon_expires_at' => '2026-10-30',
					),
					'note'   => '',
				)
			);

		( new Flusher( $queue, $router, static fn () => null, $discounts ) )->flush();

		self::assertSame( 'K7QM2XRT9P', $router->posted[0]['welcome_coupon_code'] );
		self::assertSame( '2026-10-30', $router->posted[0]['welcome_coupon_expires_at'] );
		self::assertSame( '2026-10-23 10:00:00', $router->posted[0]['welcome_automation_at'] );
		self::assertSame( 'K7QM2XRT9P', $queue->payloads[5]['fields']['welcome_coupon_code'], 'The code is written into the row, so a retry posts it again.' );
		self::assertSame( '2026-10-23 10:00:00', $queue->payloads[5]['fields']['welcome_automation_at'] );
		self::assertSame( array( 5 ), $queue->marked_sent );
		self::assertStringNotContainsString( '"note"', (string) $queue->exchanges[5]['response'] );
	}

	public function test_an_email_sent_without_its_discount_code_says_why_in_the_event_log(): void {
		$queue     = $this->fake_queue( array( $this->welcome_event( 6 ) ) );
		$router    = $this->router_running_before_send();
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->method( 'issue' )->willReturn(
			array(
				'fields' => array(),
				'note'   => 'Sent without a discount code: the WooCommerce coupon #12 has expired.',
			)
		);

		$stats = ( new Flusher( $queue, $router, static fn () => null, $discounts ) )->flush();

		self::assertSame( 1, $stats['sent'], 'The email still goes — it is not a skipped send.' );
		self::assertArrayNotHasKey( 'welcome_coupon_code', $router->posted[0] );
		$response = json_decode( (string) $queue->exchanges[6]['response'], true );
		self::assertSame( 'Sent without a discount code: the WooCommerce coupon #12 has expired.', $response['note'] );
		self::assertSame( 200, $response['http'], 'The note rides beside Smaily\'s reply.' );
		self::assertArrayNotHasKey( 'outcome', $response );
	}

	public function test_no_discount_code_is_made_for_an_automation_without_a_workflow(): void {
		$queue     = $this->fake_queue( array( $this->welcome_event( 7 ) ) );
		$resolver  = new class() implements WorkflowResolverInterface {
			public function resolve_workflow( string $trigger_type, ?string $language ): ?WorkflowMatch {
				return null;
			}
		};
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->expects( self::never() )->method( 'issue' );

		$stats = ( new Flusher( $queue, new AutomationRouter( $resolver, static fn () => null ), static fn () => null, $discounts ) )->flush();

		self::assertSame( 1, $stats['sent'] );
	}

	public function test_a_contact_sync_never_asks_for_a_discount_code(): void {
		$queue     = $this->fake_queue( array( $this->contact_sync_event( 8 ) ) );
		$client    = $this->createMock( Client::class );
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->expects( self::never() )->method( 'issue' );

		( new Flusher( $queue, $this->router_running_before_send(), static fn () => $client, $discounts ) )->flush();

		self::assertSame( array( 8 ), $queue->marked_sent );
	}

	// --- the first-order email follows the consent rule (CON-386) -------------

	/**
	 * @param array<string, mixed> $extra Top-level payload keys (user_id, newsletter_optin).
	 *
	 * @return array<string, mixed>
	 */
	private function first_order_event( int $id, array $extra = array() ): array {
		return array(
			'id'         => $id,
			'event_type' => HookHandler::EVENT_AUTOMATION_FIRST_ORDER,
			'attempts'   => 0,
			'payload'    => json_encode(
				array(
					'email'  => 'buyer@example.test',
					'fields' => array( 'order_id' => '100' ),
				) + $extra
			),
		);
	}

	private function stub_mode( string $mode ): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( string $key, $fallback = false ) => $key === ContactSyncMode::OPTION_MODE ? $mode : $fallback
		);
	}

	/**
	 * A "default" account client whose contact read answers: null = an
	 * address Smaily does not have, else the contact's is_unsubscribed.
	 */
	private function reading_client( ?string $is_unsubscribed ): Client {
		$client = $this->createMock( Client::class );
		$client->method( 'get_contact_consent' )->willReturn(
			array(
				'found'                   => $is_unsubscribed !== null,
				'is_unsubscribed'         => $is_unsubscribed,
				'smaily_rec_profiling'    => null,
				'smaily_rec_profiling_ts' => null,
			)
		);
		return $client;
	}

	public function test_consent_mode_skips_a_first_order_email_to_a_buyer_with_no_consent_anywhere(): void {
		$this->stub_mode( ContactSyncMode::MODE_CONSENT );
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '' );
		$queue     = $this->fake_queue( array( $this->first_order_event( 30, array( 'user_id' => 42 ) ) ) );
		$router    = $this->router_running_before_send();
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->expects( self::never() )->method( 'issue' );
		$client = $this->reading_client( null );

		( new Flusher( $queue, $router, static fn () => $client, $discounts ) )->flush();

		self::assertSame( array(), $router->posted, 'The email would subscribe the buyer.' );
		self::assertSame( array( 30 ), $queue->marked_sent, 'Closed for good, never retried.' );
		$response = (string) $queue->exchanges[30]['response'];
		self::assertSame( AutomationConsent::REASON_NO_MARKETING_CONSENT, EventQueue::skip_reason( $response ) );
		self::assertStringContainsString( Flusher::NOTE_NO_CONSENT, (string) json_decode( $response, true )['note'] );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}>
	 */
	public static function consented_buyers(): array {
		return array(
			'consent: the store record'          => array( ContactSyncMode::MODE_CONSENT, array( 'user_id' => 42 ), null ),
			'consent: this checkout tick'        => array( ContactSyncMode::MODE_CONSENT, array( 'newsletter_optin' => true ), null ),
			'consent: subscribed in Smaily'      => array( ContactSyncMode::MODE_CONSENT, array(), '0' ),
			'checkout opt-in: this checkout tick' => array( ContactSyncMode::MODE_CHECKOUT_OPTIN, array( 'newsletter_optin' => true ), null ),
			'checkout opt-in: subscribed in Smaily' => array( ContactSyncMode::MODE_CHECKOUT_OPTIN, array(), '0' ),
			'all customers: not in Smaily'       => array( ContactSyncMode::MODE_LEGITIMATE_INTEREST, array(), null ),
		);
	}

	/**
	 * @dataProvider consented_buyers
	 *
	 * @param array<string, mixed> $extra
	 */
	public function test_a_first_order_email_goes_out_where_the_mode_allows_it( string $mode, array $extra, ?string $smaily ): void {
		$this->stub_mode( $mode );
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$queue  = $this->fake_queue( array( $this->first_order_event( 31, $extra ) ) );
		$router = $this->router_running_before_send();
		$client = $this->reading_client( $smaily );

		( new Flusher( $queue, $router, static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertCount( 1, $router->posted );
		self::assertSame( '100', $router->posted[0]['order_id'] );
		self::assertSame( array( 31 ), $queue->marked_sent );
	}

	public function test_checkout_only_mode_keeps_no_store_record_for_the_first_order_email(): void {
		$this->stub_mode( ContactSyncMode::MODE_CHECKOUT_OPTIN );
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$queue  = $this->fake_queue( array( $this->first_order_event( 32, array( 'user_id' => 42 ) ) ) );
		$router = $this->router_running_before_send();
		$client = $this->reading_client( null );

		( new Flusher( $queue, $router, static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array(), $router->posted );
		self::assertSame( AutomationConsent::REASON_NO_MARKETING_CONSENT, EventQueue::skip_reason( (string) $queue->exchanges[32]['response'] ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function every_mode(): array {
		return array(
			'consent'             => array( ContactSyncMode::MODE_CONSENT ),
			'checkout_optin'      => array( ContactSyncMode::MODE_CHECKOUT_OPTIN ),
			'legitimate_interest' => array( ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
		);
	}

	/**
	 * @dataProvider every_mode
	 */
	public function test_a_buyer_unsubscribed_in_smaily_gets_no_first_order_email_in_any_mode( string $mode ): void {
		$this->stub_mode( $mode );
		Monkey\Functions\when( 'get_user_meta' )->justReturn( '1' );
		$queue  = $this->fake_queue(
			array(
				$this->first_order_event(
					33,
					array(
						'user_id'          => 42,
						'newsletter_optin' => true,
					)
				),
			)
		);
		$router = $this->router_running_before_send();
		$client = $this->reading_client( '1' );

		( new Flusher( $queue, $router, static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array(), $router->posted );
		self::assertSame( AutomationConsent::REASON_UNSUBSCRIBED, EventQueue::skip_reason( (string) $queue->exchanges[33]['response'] ) );
	}

	public function test_a_first_order_email_waits_for_a_retry_when_smaily_cannot_be_read(): void {
		$this->stub_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		$queue  = $this->fake_queue( array( $this->first_order_event( 34 ) ) );
		$router = $this->router_running_before_send();
		$client = $this->createMock( Client::class );
		$client->method( 'get_contact_consent' )->willThrowException( new ApiException( 'Smaily API returned HTTP 503 for GET contact', 503 ) );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array( 'method' => 'GET', 'endpoint' => 'contact' ),
				'response' => array( 'http' => 503 ),
			)
		);

		$stats = ( new Flusher( $queue, $router, static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array(), $router->posted, 'Nothing is sent blind.' );
		self::assertSame( 1, $stats['retried'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( 34, $queue->attempts[0]['id'] );
		self::assertStringContainsString( '"http":503', (string) $queue->exchanges[34]['response'], 'The failed read is kept for the Event Log (F3-44).' );
	}

	public function test_the_first_order_buyer_is_read_from_the_default_account(): void {
		$this->stub_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		$queue    = $this->fake_queue( array( $this->first_order_event( 35 ) ) );
		$accounts = array();
		$client   = $this->reading_client( '0' );

		( new Flusher(
			$queue,
			$this->router_running_before_send(),
			static function ( string $account_key ) use ( &$accounts, $client ): Client {
				$accounts[] = $account_key;
				return $client;
			},
			$this->no_discounts()
		) )->flush();

		self::assertSame( array( 'default' ), $accounts );
	}

	public function test_a_welcome_reads_no_contact_first(): void {
		$queue  = $this->fake_queue( array( $this->welcome_event( 36 ) ) );
		$client = $this->createMock( Client::class );
		$client->expects( self::never() )->method( 'get_contact_consent' );

		( new Flusher( $queue, $this->router_running_before_send(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array( 36 ), $queue->marked_sent, 'The welcome is out of scope (CON-386).' );
	}

	// --- the abandoned-cart purchase marker never creates a contact (PRO-3627)

	private function marker_queue( int $id ): EventQueue {
		return $this->fake_queue(
			array(
				array(
					'id'         => $id,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'attempts'   => 0,
					'payload'    => json_encode(
						array(
							'email'  => 'a@b.c',
							'fields' => array( AutomationMarker::FIELD_ABANDONED_CART_PURCHASED => '2026-10-05 10:00:00' ),
						)
					),
				),
			)
		);
	}

	public function test_purchase_marker_goes_to_a_contact_smaily_has(): void {
		$queue  = $this->marker_queue( 21 );
		$client = $this->createMock( Client::class );
		$client->expects( self::once() )
			->method( 'has_contact' )
			->with( 'a@b.c' )
			->willReturn( true );
		$client->expects( self::once() )
			->method( 'upsert_subscribers' )
			->with(
				array(
					array(
						'email'                                         => 'a@b.c',
						AutomationMarker::FIELD_ABANDONED_CART_PURCHASED => '2026-10-05 10:00:00',
					),
				)
			)
			->willReturn( array( 'code' => 101 ) );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( array( 21 ), $queue->marked_sent );
	}

	public function test_purchase_marker_for_an_address_smaily_does_not_have_is_skipped(): void {
		$queue  = $this->marker_queue( 22 );
		$client = $this->createMock( Client::class );
		$client->method( 'has_contact' )->willReturn( false );
		// Smaily would create the contact as a subscriber.
		$client->expects( self::never() )->method( 'upsert_subscribers' );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array( 22 ), $queue->marked_sent, 'A skip is terminal — never retried.' );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 0, $stats['failed'] );
		self::assertNull( $queue->exchanges[22]['sent'], 'Nothing was POSTed, so the row claims no request.' );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $queue->exchanges[22]['response'] );
		self::assertStringContainsString( 'Smaily does not have this contact', (string) $queue->exchanges[22]['response'] );
	}

	public function test_purchase_marker_waits_for_a_retry_when_the_contact_check_fails(): void {
		$queue  = $this->marker_queue( 23 );
		$client = $this->createMock( Client::class );
		$client->method( 'has_contact' )->willThrowException( new ApiException( 'server error', 500 ) );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array( 'method' => 'GET', 'endpoint' => 'contact', 'body' => array( 'email' => 'a@b.c' ) ),
				'response' => array( 'http' => 500, 'body' => null ),
			)
		);
		$client->expects( self::never() )->method( 'upsert_subscribers' );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['retried'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( 23, $queue->attempts[0]['id'] );
		self::assertStringContainsString( '"method":"GET"', (string) $queue->exchanges[23]['sent'] );
		self::assertStringContainsString( '"http":500', (string) $queue->exchanges[23]['response'] );
	}

	public function test_an_ordinary_contact_sync_does_not_read_the_contact_first(): void {
		$queue  = $this->fake_queue(
			array(
				array(
					'id'         => 24,
					'event_type' => HookHandler::EVENT_CONTACT_SYNC,
					'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array( 'first_name' => 'Alice' ) ) ),
				),
			)
		);
		$client = $this->createMock( Client::class );
		$client->expects( self::never() )->method( 'has_contact' );
		$client->expects( self::once() )->method( 'upsert_subscribers' )->willReturn( array() );

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array( 24 ), $queue->marked_sent );
	}

	public function test_flush_excludes_the_cart_event_type_from_its_drain(): void {
		// PRO-1195: automation.abandoned_cart rows belong to the CartFlusher
		// (its own AS action); PRO-1504 Stage 2 adds the two transactional
		// types belonging to TransactionalFlusher. The main drain must
		// exclude all three at the pending() query so no flusher consumes
		// another's rows.
		$queue = $this->fake_queue( array() );

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => null, $this->no_discounts() ) )->flush();

		self::assertSame(
			array(
				Flusher::DEFAULT_BATCH_SIZE,
				null,
				array(
					\Smaily\Connect\Smaily\CartFlusher::EVENT_TYPE,
					\Smaily\Connect\Smaily\TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
					\Smaily\Connect\Smaily\TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
					\Smaily\Connect\Smaily\TransactionalFlusher::EVENT_TYPE_FOLLOW_UP,
					\Smaily\Connect\Smaily\TransactionalFlusher::EVENT_TYPE_PAYMENT_REMINDER,
				),
			),
			$queue->last_pending_args
		);
	}

	public function test_a_subscribe_is_skipped_when_a_later_change_of_the_contact_was_delivered(): void {
		// CON-395: the retry of an old subscribe must not undo the shopper's
		// newer choice, which already reached Smaily.
		$queue        = $this->fake_queue( array( $this->consent_event( 3, 0 ) ) );
		$queue->later = array( (string) json_encode( array( 'email' => 'a@b.c', 'is_unsubscribed' => 1 ) ) );

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'upsert_subscribers' );

		$stats = ( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame( array( 3 ), $queue->marked_sent );
		self::assertNull( $queue->exchanges[3]['sent'] );
		self::assertSame( StaleSubscription::REASON, EventQueue::skip_reason( (string) $queue->exchanges[3]['response'] ) );
	}

	public function test_an_unsubscribe_is_sent_whatever_was_delivered_later(): void {
		$queue        = $this->fake_queue( array( $this->consent_event( 3, 1 ) ) );
		$queue->later = array( (string) json_encode( array( 'email' => 'a@b.c', 'is_unsubscribed' => 0 ) ) );

		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )->method( 'upsert_subscribers' );

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array( 3 ), $queue->marked_sent );
	}

	public function test_a_later_sync_without_a_status_makes_no_subscribe_stale(): void {
		$queue        = $this->fake_queue( array( $this->consent_event( 3, 0 ) ) );
		$queue->later = array( (string) json_encode( array( 'email' => 'a@b.c', 'fields' => array( 'first_name' => 'Alice' ) ) ) );

		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )->method( 'upsert_subscribers' );

		( new Flusher( $queue, $this->automation_router_returning_true(), static fn () => $client, $this->no_discounts() ) )->flush();

		self::assertSame( array( 3 ), $queue->marked_sent );
	}

	/**
	 * A contact sync carrying a subscription status, as a consent change queues it.
	 *
	 * @return array<string, mixed>
	 */
	private function consent_event( int $id, int $is_unsubscribed ): array {
		return array(
			'id'         => $id,
			'event_type' => HookHandler::EVENT_CONTACT_SYNC,
			'payload'    => json_encode(
				array(
					'email'           => 'a@b.c',
					'is_unsubscribed' => $is_unsubscribed,
					'fields'          => array(),
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

			/** @var array<int, array<string, mixed>> */
			public array $payloads = array();

			public function update_payload( int $id, array $payload ): void {
				$this->payloads[ $id ] = $payload;
			}

			/** @var array<int, string> What later_delivered_payloads() answers (CON-395). */
			public array $later = array();

			public function later_delivered_payloads( string $event_type, string $email, int $after_id ): array {
				return $this->later;
			}
		};
	}

	/** Discount codes off: every row goes without one, and without a note. */
	private function no_discounts(): AutomationDiscount {
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->method( 'issue' )->willReturn(
			array(
				'fields' => array(),
				'note'   => '',
			)
		);
		return $discounts;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function contact_sync_event( int $id ): array {
		return array(
			'id'         => $id,
			'event_type' => HookHandler::EVENT_CONTACT_SYNC,
			'payload'    => json_encode( array( 'email' => 'a@b.c', 'fields' => array() ) ),
		);
	}

	/**
	 * A Client whose contact upsert gets an HTTP 200 reply carrying $code.
	 */
	private function client_answering( int $code, string $message ): Client {
		$body   = array(
			'code'    => $code,
			'message' => $message,
		);
		$client = $this->createMock( Client::class );
		$client->method( 'upsert_subscribers' )->willReturn( $body );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array( 'method' => 'POST', 'endpoint' => 'contact', 'body' => array( array( 'email' => 'a@b.c' ) ) ),
				'response' => array(
					'http' => 200,
					'body' => $body,
				),
			)
		);

		return $client;
	}

	private function automation_router_returning_true(): AutomationRouter {
		return new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation(
				string $trigger_type,
				array $contact_data,
				array $additional_fields = array(),
				?callable $before_send = null
			): bool {
				return true;
			}
		};
	}
}
