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
use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactSyncMode;
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

		( new CartFlusher( $queue, $this->router_returning( true ), $this->subscribed_factory() ) )->flush();

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

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
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

		$stats = ( new CartFlusher( $queue, $router, $this->subscribed_factory() ) )->flush();

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
		$client   = $this->reading_client( self::smaily_contact( '0' ) );
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

		$client = $this->reading_client( self::smaily_contact( '0' ) );
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

		$body   = array(
			'code'    => 221,
			'message' => 'no such autoresponder',
		);
		$client = $this->reading_client( self::smaily_contact( '0' ) );
		$client->method( 'trigger_automation' )->willReturn( $body );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array(),
				'response' => array(
					'http' => 200,
					'body' => $body,
				),
			)
		);

		$stats = ( new CartFlusher( $queue, $this->router_returning( false ), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( 'permanent_envelope_221: Smaily API returned code 221: no such autoresponder', $queue->marked_failed[0]['error'] );
	}

	public function test_a_refusing_code_on_the_routed_path_is_never_marked_sent(): void {
		// PRO-3862: the router returns true whatever Smaily's HTTP 200 body
		// says. A permanent code fails the row with Smaily's answer; 225
		// (Smaily's own database error) is retried.
		$queue = $this->fake_queue( array( $this->cart_event( 8 ), $this->cart_event( 9 ) ) );

		$router = new class extends AutomationRouter {
			/** @var array<int, array{0: int, 1: string}> */
			public array $answers = array(
				array( 207, 'Following fields are required' ),
				array( 225, 'Database insert failed' ),
			);

			/** @var array{0: int, 1: string} */
			private array $current = array( 0, '' );

			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				$this->current = (array) array_shift( $this->answers );
				return true;
			}

			public function last_exchange(): ?array {
				return array(
					'request'  => array( 'endpoint' => 'autoresponder' ),
					'response' => array(
						'http' => 200,
						'body' => array(
							'code'    => $this->current[0],
							'message' => $this->current[1],
						),
					),
				);
			}
		};

		$stats = ( new CartFlusher( $queue, $router, $this->subscribed_factory() ) )->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( 'permanent_envelope_207: Smaily API returned code 207: Following fields are required', $queue->marked_failed[0]['error'] );
		self::assertSame( 9, $queue->attempts[0]['id'] );
		self::assertSame( 'Smaily API returned code 225: Database insert failed', $queue->attempts[0]['error'] );
	}

	public function test_an_invalid_data_answer_on_the_fallback_names_its_class_and_smailys_answer(): void {
		// PRO-3750: code 203 already failed at once here; it now carries the
		// same class and answer as on the routed path and the main flusher.
		$this->stub_status_option( 77 );

		$queue = $this->fake_queue( array( $this->cart_event( 6 ) ) );

		$body   = array(
			'code'    => 203,
			'message' => 'Invalid data',
		);
		$client = $this->reading_client( self::smaily_contact( '0' ) );
		$client->method( 'trigger_automation' )->willReturn( $body );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array(),
				'response' => array(
					'http' => 200,
					'body' => $body,
				),
			)
		);

		$stats = ( new CartFlusher( $queue, $this->router_returning( false ), static fn () => $client ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 'permanent_envelope_203: Smaily API returned code 203: Invalid data', $queue->marked_failed[0]['error'] );
	}

	public function test_an_invalid_data_answer_on_the_routed_path_fails_on_the_first_attempt(): void {
		// PRO-3750: the router returns true whatever Smaily's HTTP 200 body
		// says, so the flusher reads the code from the stored exchange.
		$queue = $this->fake_queue( array( $this->cart_event( 7 ) ) );

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

		$stats = ( new CartFlusher( $queue, $router, $this->subscribed_factory() ) )->flush();

		self::assertSame( 1, $stats['failed'] );
		self::assertSame( array(), $queue->marked_sent );
		self::assertSame( array(), $queue->attempts );
		self::assertSame( 'permanent_envelope_203: Smaily API returned code 203: Invalid data', $queue->marked_failed[0]['error'] );
	}

	public function test_api_exception_records_attempt_for_retry(): void {
		$queue = $this->fake_queue( array( $this->cart_event( 5 ) ) );

		$router = new class extends AutomationRouter {
			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				throw new ApiException( 'rate limited', 429 );
			}

			public function last_exchange(): ?array {
				return array( 'request' => array( 'endpoint' => 'autoresponder' ), 'response' => array( 'http' => 429 ) );
			}
		};

		$stats = ( new CartFlusher( $queue, $router, $this->subscribed_factory() ) )->flush();

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

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				throw new ApiException( 'Smaily API returned HTTP 403 for POST autoresponder', 403 );
			}

			public function last_exchange(): ?array {
				return array( 'request' => array( 'endpoint' => 'autoresponder' ), 'response' => array( 'http' => 403 ) );
			}
		};

		$stats = ( new CartFlusher( $queue, $router, $this->subscribed_factory() ) )->flush();

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

		$stats = ( new CartFlusher( $queue, $this->router_returning( true ), $this->subscribed_factory() ) )->flush();

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

		$stats = ( new CartFlusher( $queue, $this->router_returning( true ), $this->subscribed_factory() ) )->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $queue->exchanges[8]['response'] );
	}

	/**
	 * CON-196: Smaily creates an address an automation is triggered for as a
	 * subscribed contact. In the two consent modes a shopper with no consent
	 * anywhere gets no reminder; the row closes as skipped with the reason.
	 */
	public function test_consent_mode_skips_a_shopper_with_no_consent_anywhere(): void {
		$queue  = $this->fake_queue( array( $this->cart_event( 20 ) ) );
		$router = $this->recording_router();

		$stats = ( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_unknown() ) ) )->flush();

		self::assertSame( array(), $router->calls, 'The reminder would subscribe the shopper.' );
		self::assertSame( array( 20 => 'no_marketing_consent' ), $queue->marked_skipped );
		self::assertSame( array(), $queue->marked_sent );
		self::assertArrayNotHasKey( 20, $queue->exchanges, 'The skip keeps its reason — no exchange overwrites it.' );
		self::assertSame( 1, $stats['skipped'] );
	}

	public function test_consent_mode_sends_to_a_shopper_the_store_has_as_subscribed(): void {
		Functions\expect( 'get_user_meta' )->once()->with( 42, 'user_newsletter', true )->andReturn( '1' );

		$queue  = $this->fake_queue( array( $this->cart_event( 21, array( 'user_id' => 42 ) ) ) );
		$router = $this->recording_router();

		( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_unknown() ) ) )->flush();

		self::assertCount( 1, $router->calls );
		self::assertSame( array( 21 ), $queue->marked_sent );
	}

	public function test_checkout_only_mode_keeps_no_store_record_for_an_account(): void {
		$this->stub_status_option( 0, ContactSyncMode::MODE_CHECKOUT_OPTIN );
		Functions\when( 'get_user_meta' )->justReturn( '1' );

		$queue  = $this->fake_queue( array( $this->cart_event( 22, array( 'user_id' => 42 ) ) ) );
		$router = $this->recording_router();

		( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_unknown() ) ) )->flush();

		self::assertSame( array(), $router->calls );
		self::assertSame( array( 22 => 'no_marketing_consent' ), $queue->marked_skipped );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function consent_modes(): array {
		return array(
			'consent'        => array( ContactSyncMode::MODE_CONSENT ),
			'checkout_optin' => array( ContactSyncMode::MODE_CHECKOUT_OPTIN ),
		);
	}

	/**
	 * @dataProvider consent_modes
	 */
	public function test_a_tick_in_this_classic_checkout_counts_as_consent( string $mode ): void {
		$this->stub_status_option( 0, $mode );

		$queue  = $this->fake_queue( array( $this->cart_event( 23, array( 'newsletter_optin' => true ) ) ) );
		$router = $this->recording_router();

		( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_unknown() ) ) )->flush();

		self::assertCount( 1, $router->calls );
		self::assertSame( array( 23 ), $queue->marked_sent );
	}

	/**
	 * @dataProvider consent_modes
	 */
	public function test_a_subscribed_smaily_contact_counts_as_consent( string $mode ): void {
		$this->stub_status_option( 0, $mode );

		$queue  = $this->fake_queue( array( $this->cart_event( 24 ) ) );
		$router = $this->recording_router();

		( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_contact( '0' ) ) ) )->flush();

		self::assertCount( 1, $router->calls );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function every_mode(): array {
		return self::consent_modes() + array( 'legitimate_interest' => array( ContactSyncMode::MODE_LEGITIMATE_INTEREST ) );
	}

	/**
	 * @dataProvider every_mode
	 */
	public function test_a_contact_unsubscribed_in_smaily_gets_no_reminder_in_any_mode( string $mode ): void {
		$this->stub_status_option( 0, $mode );
		Functions\when( 'get_user_meta' )->justReturn( '1' );

		$queue  = $this->fake_queue( array( $this->cart_event( 25, array( 'user_id' => 42, 'newsletter_optin' => true ) ) ) );
		$router = $this->recording_router();

		( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_contact( '1' ) ) ) )->flush();

		self::assertSame( array(), $router->calls );
		self::assertSame( array( 25 => 'unsubscribed' ), $queue->marked_skipped );
	}

	public function test_legitimate_interest_sends_to_an_address_smaily_does_not_have(): void {
		$this->stub_status_option( 0, ContactSyncMode::MODE_LEGITIMATE_INTEREST );

		$queue  = $this->fake_queue( array( $this->cart_event( 26 ) ) );
		$router = $this->recording_router();

		( new CartFlusher( $queue, $router, fn () => $this->reading_client( self::smaily_unknown() ) ) )->flush();

		self::assertCount( 1, $router->calls );
		self::assertSame( array( 26 ), $queue->marked_sent );
	}

	public function test_a_failed_read_is_retried_and_nothing_is_sent_blind(): void {
		$queue  = $this->fake_queue( array( $this->cart_event( 27 ) ) );
		$router = $this->recording_router();

		$client = $this->createMock( Client::class );
		$client->method( 'get_contact_consent' )->willThrowException( new ApiException( 'Smaily API returned HTTP 503 for GET contact', 503 ) );
		$client->method( 'last_exchange' )->willReturn(
			array(
				'request'  => array( 'method' => 'GET', 'endpoint' => 'contact' ),
				'response' => array( 'http' => 503 ),
			)
		);

		$stats = ( new CartFlusher( $queue, $router, static fn () => $client ) )->flush();

		self::assertSame( array(), $router->calls );
		self::assertSame( 1, $stats['retried'] );
		self::assertSame( 27, $queue->attempts[0]['id'] );
		self::assertStringContainsString( '"http":503', (string) $queue->exchanges[27]['response'], 'The failed read is kept for the Event Log (F3-44).' );
	}

	public function test_the_contact_is_read_from_the_default_account(): void {
		$queue    = $this->fake_queue( array( $this->cart_event( 28 ) ) );
		$accounts = array();
		$client   = $this->reading_client( self::smaily_contact( '0' ) );

		( new CartFlusher(
			$queue,
			$this->recording_router(),
			static function ( string $account_key ) use ( &$accounts, $client ): Client {
				$accounts[] = $account_key;
				return $client;
			}
		) )->flush();

		self::assertSame( array( 'default' ), $accounts );
	}

	// --- the reminder's discount code (CON-199) --------------------------------

	/**
	 * @param array<string, string> $fields
	 */
	private function discounts_issuing( array $fields, string $note = '' ): AutomationDiscount {
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->method( 'issue' )->willReturn(
			array(
				'fields' => $fields,
				'note'   => $note,
			)
		);
		return $discounts;
	}

	public function test_a_reminder_the_consent_gate_stops_makes_no_discount_code(): void {
		$queue     = $this->fake_queue( array( $this->cart_event( 40 ) ) );
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->expects( self::never() )->method( 'issue' );

		( new CartFlusher( $queue, $this->recording_router(), fn () => $this->reading_client( self::smaily_unknown() ), $discounts ) )->flush();

		self::assertSame( array( 40 => 'no_marketing_consent' ), $queue->marked_skipped );
	}

	public function test_a_routed_reminder_posts_the_discount_code_issued_for_its_row(): void {
		$queue     = $this->fake_queue( array( $this->cart_event( 41 ) ) );
		$discounts = $this->createMock( AutomationDiscount::class );
		$discounts->expects( self::once() )
			->method( 'issue' )
			->with( 'abandoned_cart', self::callback( static fn ( array $p ): bool => $p['email'] === 'buyer@example.test' ), $queue, 41 )
			->willReturn(
				array(
					'fields' => array(
						'abandoned_cart_coupon_code'       => 'P4XK9M2QRT',
						'abandoned_cart_coupon_expires_at' => '2026-10-30',
					),
					'note'   => '',
				)
			);

		$router = new class extends AutomationRouter {
			/** @var array<int, array<string, mixed>> */
			public array $posted = array();

			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				$this->posted[] = array_merge( $additional_fields, $before_send !== null ? $before_send() : array() );
				return true;
			}

			public function last_exchange(): ?array {
				return array( 'response' => array( 'http' => 200, 'body' => array( 'code' => 101 ) ) );
			}
		};

		( new CartFlusher( $queue, $router, $this->subscribed_factory(), $discounts ) )->flush();

		self::assertSame( 'P4XK9M2QRT', $router->posted[0]['abandoned_cart_coupon_code'] );
		self::assertSame( '2026-10-30', $router->posted[0]['abandoned_cart_coupon_expires_at'] );
		self::assertSame( 'true', $router->posted[0]['is_abandoned_cart'] );
		self::assertSame( array( 41 ), $queue->marked_sent );
	}

	public function test_the_legacy_autoresponder_reminder_carries_the_discount_code_too(): void {
		$this->stub_status_option( 88 );
		$queue = $this->fake_queue( array( $this->cart_event( 42 ) ) );

		$posted = array();
		$client = $this->reading_client( self::smaily_contact( '0' ) );
		$client->method( 'trigger_automation' )->willReturnCallback(
			static function ( int $workflow_id, array $addresses ) use ( &$posted ): array {
				$posted = $addresses[0];
				return array( 'code' => 101 );
			}
		);

		( new CartFlusher(
			$queue,
			$this->router_returning( false ),
			static fn () => $client,
			$this->discounts_issuing( array( 'abandoned_cart_coupon_code' => 'P4XK9M2QRT', 'abandoned_cart_coupon_expires_at' => '2026-10-30' ) )
		) )->flush();

		self::assertSame( 'P4XK9M2QRT', $posted['abandoned_cart_coupon_code'] );
		self::assertSame( '2026-10-30', $posted['abandoned_cart_coupon_expires_at'] );
	}

	public function test_a_reminder_sent_without_its_discount_code_says_why_in_the_event_log(): void {
		$queue = $this->fake_queue( array( $this->cart_event( 43 ) ) );

		$stats = ( new CartFlusher(
			$queue,
			$this->recording_router(),
			$this->subscribed_factory(),
			$this->discounts_issuing( array(), 'Sent without a discount code: no WooCommerce coupon is picked.' )
		) )->flush();

		self::assertSame( 1, $stats['sent'] );
		$response = json_decode( (string) $queue->exchanges[43]['response'], true );
		self::assertSame( 'Sent without a discount code: no WooCommerce coupon is picked.', $response['note'] );
		self::assertSame( 200, $response['http'] );
	}

	// --- helpers -------------------------------------------------------------

	private function stub_status_option( int $autoresponder_id, string $mode = ContactSyncMode::MODE_CONSENT ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( $autoresponder_id, $mode ) {
				if ( $key === \Smaily_Connect\Includes\Options::ABANDONED_CART_STATUS_OPTION ) {
					return array(
						'enabled'          => true,
						'autoresponder_id' => $autoresponder_id,
					);
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return $mode;
				}
				return $fallback;
			}
		);
	}

	/**
	 * What Client::get_contact_consent() returns for a contact Smaily has.
	 *
	 * @return array<string, mixed>
	 */
	private static function smaily_contact( string $is_unsubscribed ): array {
		return array(
			'found'                   => true,
			'is_unsubscribed'         => $is_unsubscribed,
			'smaily_rec_profiling'    => null,
			'smaily_rec_profiling_ts' => null,
		);
	}

	/**
	 * What Client::get_contact_consent() returns for an address Smaily does not have.
	 *
	 * @return array<string, mixed>
	 */
	private static function smaily_unknown(): array {
		return array(
			'found'                   => false,
			'is_unsubscribed'         => null,
			'smaily_rec_profiling'    => null,
			'smaily_rec_profiling_ts' => null,
		);
	}

	/**
	 * A "default" account client whose contact read answers $consent.
	 *
	 * @param array<string, mixed> $consent
	 */
	private function reading_client( array $consent ): Client {
		$client = $this->createMock( Client::class );
		$client->method( 'get_contact_consent' )->willReturn( $consent );
		return $client;
	}

	/**
	 * A client factory for the routed path: the contact read finds the
	 * shopper subscribed, so the consent gate lets the reminder through.
	 */
	private function subscribed_factory(): callable {
		$client = $this->reading_client( self::smaily_contact( '0' ) );
		return static fn () => $client;
	}

	/**
	 * @param array<string, mixed> $extra Top-level payload keys (user_id, newsletter_optin).
	 *
	 * @return array<string, mixed>
	 */
	private function cart_event( int $id, array $extra = array() ): array {
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
				) + $extra
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

			/** @var array<int, string> */
			public array $marked_skipped = array();

			public function mark_skipped( int $id, string $reason ): void {
				$this->marked_skipped[ $id ] = $reason;
			}

			public function store_exchange( int $id, ?string $sent_payload, ?string $last_response ): void {
				$this->exchanges[ $id ] = array( 'sent' => $sent_payload, 'response' => $last_response );
			}
		};
	}

	private function recording_router(): AutomationRouter {
		return new class extends AutomationRouter {
			/** @var array<int, array<string, mixed>> */
			public array $calls = array();

			public function __construct() {}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				$this->calls[] = compact( 'trigger_type', 'contact_data', 'additional_fields' );
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

	private function router_returning( bool $result ): AutomationRouter {
		return new class( $result ) extends AutomationRouter {
			private bool $result;

			public function __construct( bool $result ) {
				$this->result = $result;
			}

			public function trigger_automation( string $trigger_type, array $contact_data, array $additional_fields = array(), ?callable $before_send = null ): bool {
				return $this->result;
			}

			public function last_exchange(): ?array {
				return null;
			}
		};
	}
}
