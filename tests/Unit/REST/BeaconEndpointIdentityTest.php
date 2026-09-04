<?php
/**
 * PRO-1389: the ongoing-session identity signal — BeaconEndpoint resolves a
 * logged-in visitor's email server-side (from the auth cookie) and attaches
 * it to the events actually forwarded to the engine.
 *
 * The full request/rate-limit/gate chain (real WP REST + real cookie
 * validation) is integration-tested (RecEngineBrowseProxyTest). This unit
 * test isolates the injection logic itself: resolve_logged_in_email() is a
 * protected seam (doubled here, mirroring LandingCaptureTest's
 * headers_already_sent() pattern) so the WP auth-cookie mechanics don't need
 * a real WordPress bootstrap.
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_REST_Request;

final class BeaconEndpointIdentityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( true ); // OPTION_TRACK_BROWSING.
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_SERVER = array();
		$_COOKIE = array();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	private function request( array $events ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'events', $events );
		return $request;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function one_event(): array {
		return array(
			array(
				'event_id'   => 'e1',
				'event_type' => 'product_view',
				'sku'        => 'ACA-1',
				'session_id' => 's1',
				'event_ts'   => '2026-07-21T10:00:00Z',
			),
		);
	}

	public function test_logged_in_and_consenting_gets_email_attached(): void {
		$client   = $this->recording_client();
		$endpoint = $this->endpoint( 'mari@example.com', $client, null );

		$response = $endpoint->handle( $this->request( $this->one_event() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'mari@example.com', $client->received[0]['customer_email'] ?? null );
	}

	public function test_anonymous_visitor_gets_no_email_attached(): void {
		$client   = $this->recording_client();
		$endpoint = $this->endpoint( '', $client, null ); // resolve_logged_in_email() returns '' — no cookie / invalid cookie.

		$response = $endpoint->handle( $this->request( $this->one_event() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertArrayNotHasKey( 'customer_email', $client->received[0] ?? array() );
	}

	public function test_opted_out_logged_in_user_is_forwarded_anonymous_not_dropped(): void {
		$client     = $this->recording_client();
		$profiling  = $this->profiling_stub( array( 'out@example.com' ) );
		$endpoint   = $this->endpoint( 'out@example.com', $client, $profiling );

		$response = $endpoint->handle( $this->request( $this->one_event() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $response->get_data()['processed'], 'The event still reaches the engine — opt-out means anonymous, not dropped.' );
		self::assertArrayNotHasKey( 'customer_email', $client->received[0] ?? array(), 'No identity hint for an opted-out contact.' );
	}

	public function test_consenting_logged_in_user_still_forwards_with_a_profiling_gate_present(): void {
		// The gate exists (unlike the null-gate tests above) but this contact has
		// NOT opted out — attach must still happen.
		$client    = $this->recording_client();
		$profiling = $this->profiling_stub( array( 'someone-else@example.com' ) );
		$endpoint  = $this->endpoint( 'in@example.com', $client, $profiling );

		$response = $endpoint->handle( $this->request( $this->one_event() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'in@example.com', $client->received[0]['customer_email'] ?? null );
	}

	public function test_email_never_appears_in_the_response_sent_back_to_the_browser(): void {
		$client   = $this->recording_client();
		$endpoint = $this->endpoint( 'mari@example.com', $client, null );

		$response = $endpoint->handle( $this->request( $this->one_event() ) );

		$serialised = (string) wp_json_encode( $response->get_data() );
		self::assertStringNotContainsString( 'mari@example.com', $serialised );
		self::assertSame( array( 'ok', 'processed', 'deduplicated', 'errors' ), array_keys( $response->get_data() ) );
	}

	public function test_a_batch_of_multiple_events_all_get_the_same_email(): void {
		$client   = $this->recording_client();
		$endpoint = $this->endpoint( 'mari@example.com', $client, null );

		$events = array(
			array( 'event_id' => 'e1', 'event_type' => 'product_view', 'session_id' => 's1', 'event_ts' => '2026-07-21T10:00:00Z' ),
			array( 'event_id' => 'e2', 'event_type' => 'category_view', 'session_id' => 's1', 'event_ts' => '2026-07-21T10:01:00Z' ),
		);

		$response = $endpoint->handle( $this->request( $events ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'mari@example.com', $client->received[0]['customer_email'] ?? null );
		self::assertSame( 'mari@example.com', $client->received[1]['customer_email'] ?? null );
	}

	/**
	 * PRO-1389 follow-up (efficiency): attach_logged_in_identity() already
	 * verified the resolved email's profiling consent once — filter_by_profiling()
	 * must reuse that decision instead of calling may_profile() again per event.
	 */
	public function test_logged_in_batch_triggers_exactly_one_consent_lookup_for_the_resolved_email(): void {
		$client    = $this->recording_client();
		$profiling = $this->counting_profiling_stub( array() ); // nobody opted out.
		$endpoint  = $this->endpoint( 'mari@example.com', $client, $profiling );

		$events = array(
			array( 'event_id' => 'e1', 'event_type' => 'product_view', 'session_id' => 's1', 'event_ts' => '2026-07-21T10:00:00Z' ),
			array( 'event_id' => 'e2', 'event_type' => 'category_view', 'session_id' => 's1', 'event_ts' => '2026-07-21T10:01:00Z' ),
			array( 'event_id' => 'e3', 'event_type' => 'search', 'session_id' => 's1', 'event_ts' => '2026-07-21T10:02:00Z' ),
		);

		$response = $endpoint->handle( $this->request( $events ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 3, $response->get_data()['processed'] );
		self::assertSame(
			array( 'mari@example.com' ),
			$profiling->calls,
			'One consent lookup for the whole batch, not one per event.'
		);
	}

	/**
	 * PRO-1486: a client-supplied customer_email (spoofed, differing from any
	 * server-resolved identity — here there IS no resolved identity, the
	 * visitor is anonymous) never reaches filter_by_profiling() at all — it is
	 * stripped by validate_batch()'s whitelist before attach_logged_in_identity()
	 * or filter_by_profiling() ever run. No may_profile() lookup happens for it
	 * (there's nothing to check: the event is forwarded anonymous), and the
	 * spoofed email never reaches the engine.
	 */
	public function test_client_supplied_customer_email_is_stripped_and_never_checked(): void {
		$client    = $this->recording_client();
		$profiling = $this->counting_profiling_stub( array() );
		$endpoint  = $this->endpoint( '', $client, $profiling ); // anonymous — nothing resolved server-side.

		$events = array(
			array(
				'event_id'       => 'e1',
				'event_type'     => 'product_view',
				'session_id'     => 's1',
				'event_ts'       => '2026-07-21T10:00:00Z',
				'customer_email' => 'spoofed@example.com',
			),
			array(
				'event_id'       => 'e2',
				'event_type'     => 'category_view',
				'session_id'     => 's1',
				'event_ts'       => '2026-07-21T10:01:00Z',
				'customer_email' => 'spoofed@example.com',
			),
		);

		$response = $endpoint->handle( $this->request( $events ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 2, $response->get_data()['processed'] );
		self::assertArrayNotHasKey( 'customer_email', $client->received[0] ?? array() );
		self::assertArrayNotHasKey( 'customer_email', $client->received[1] ?? array() );
		self::assertSame( array(), $profiling->calls, 'A stripped client-supplied email triggers no profiling lookup at all.' );
	}

	// --- doubles ----------------------------------------------------------

	private function endpoint( string $resolved_email, Client $client, ?ProfilingConsent $profiling ): BeaconEndpoint {
		$settings = new class() extends RecEngineSettings {
			public function is_connected(): bool {
				return true;
			}
			public function api_key(): string {
				return 'sk_unit';
			}
			public function base_url(): string {
				return 'https://engine.unit';
			}
			public function config(): array {
				return array();
			}
		};

		return new class( $settings, $client, $profiling, $resolved_email ) extends BeaconEndpoint {
			private string $test_email;

			public function __construct( RecEngineSettings $settings, Client $client, ?ProfilingConsent $profiling, string $test_email ) {
				parent::__construct(
					$settings,
					static function () use ( $client ): Client {
						return $client;
					},
					$profiling
				);
				$this->test_email = $test_email;
			}

			protected function resolve_logged_in_email(): string {
				return $this->test_email;
			}
		};
	}

	/**
	 * A Client double that records the events it was asked to forward and
	 * replies with a canned all-processed D6 body.
	 */
	private function recording_client(): Client {
		return new class( 'sk_unit', 'https://engine.unit' ) extends Client {
			/** @var array<int, array<string, mixed>> */
			public array $received = array();

			public function ingest_browse( array $events ): array {
				$this->received = $events;
				return array(
					'ok'           => true,
					'processed'    => count( $events ),
					'deduplicated' => 0,
					'errors'       => array(),
				);
			}
		};
	}

	/**
	 * @param array<int, string> $opted_out_emails
	 */
	private function profiling_stub( array $opted_out_emails ): ProfilingConsent {
		return new class( $opted_out_emails ) extends ProfilingConsent {
			/** @var array<int, string> */
			private array $opted_out;

			/** @param array<int, string> $opted_out */
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- test double, skips the real deps.
			public function __construct( array $opted_out ) {
				$this->opted_out = $opted_out;
			}

			public function may_profile( string $email ): bool {
				return ! in_array( $email, $this->opted_out, true );
			}
		};
	}

	/**
	 * Like profiling_stub(), but records every email may_profile() was called
	 * with (in call order) — pins the PRO-1389 follow-up dedup property.
	 *
	 * @param array<int, string> $opted_out_emails
	 */
	private function counting_profiling_stub( array $opted_out_emails ): ProfilingConsent {
		return new class( $opted_out_emails ) extends ProfilingConsent {
			/** @var array<int, string> */
			private array $opted_out;

			/** @var array<int, string> */
			public array $calls = array();

			/** @param array<int, string> $opted_out */
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- test double, skips the real deps.
			public function __construct( array $opted_out ) {
				$this->opted_out = $opted_out;
			}

			public function may_profile( string $email ): bool {
				$this->calls[] = $email;
				return ! in_array( $email, $this->opted_out, true );
			}
		};
	}
}
