<?php
/**
 * PRO-3620: the `/relay` rate limit cannot be lifted by changing request
 * headers. The per-IP counter keys on the connection's own address
 * (REMOTE_ADDR) and always applies; forwarding headers and a rotated session
 * cookie change nothing about it.
 *
 * The transient store is an in-memory array, so the real rate_limited() /
 * bump() run unchanged. The end-to-end proof through the WP REST stack is in
 * RecEngineBrowseProxyTest.
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class BeaconEndpointRateLimitTest extends TestCase {

	/** @var array<string, mixed> */
	private array $transients = array();

	private int $forwarded = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		$this->forwarded  = 0;
		$_SERVER          = array();
		$_COOKIE          = array();

		Functions\when( 'get_option' )->justReturn( true ); // OPTION_TRACK_BROWSING.
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_SERVER = array();
		$_COOKIE = array();
		parent::tearDown();
	}

	public function test_spoofed_forwarding_headers_and_fresh_cookies_do_not_lift_the_limit(): void {
		$endpoint = $this->endpoint();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		for ( $i = 1; $i <= BeaconEndpoint::RL_MAX_PER_IP; $i++ ) {
			$this->spoof_headers( $i );
			self::assertSame( 200, $endpoint->handle( $this->request() )->get_status(), "Request {$i} is within the per-IP ceiling." );
		}

		$this->spoof_headers( 9999 );
		$response = $endpoint->handle( $this->request() );

		self::assertSame( 429, $response->get_status(), 'A new X-Forwarded-For / X-Real-IP / Forwarded / Client-IP and a new session cookie on every request still hit the connection\'s own limit.' );
		self::assertSame( BeaconEndpoint::RL_MAX_PER_IP, $this->forwarded, 'Nothing past the ceiling reached the engine.' );
	}

	public function test_without_a_usable_address_fresh_cookies_do_not_lift_the_limit(): void {
		// No REMOTE_ADDR the throttle can key on (missing, or a non-IP such as
		// `unix:` behind a socket proxy): the shared bucket still applies, so
		// the client-chosen session cookie is not the only counter left.
		$endpoint = $this->endpoint();

		for ( $i = 1; $i <= BeaconEndpoint::RL_MAX_PER_IP; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = $i % 2 === 0 ? 'unix:' : '';
			$this->spoof_headers( $i );
			self::assertSame( 200, $endpoint->handle( $this->request() )->get_status() );
		}

		unset( $_SERVER['REMOTE_ADDR'] );
		$this->spoof_headers( 9999 );

		self::assertSame( 429, $endpoint->handle( $this->request() )->get_status() );
		self::assertSame( BeaconEndpoint::RL_MAX_PER_IP, $this->forwarded );
	}

	public function test_another_connection_address_has_its_own_window(): void {
		$endpoint = $this->endpoint();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		for ( $i = 1; $i <= BeaconEndpoint::RL_MAX_PER_IP + 1; $i++ ) {
			$this->spoof_headers( $i );
			$endpoint->handle( $this->request() );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->spoof_headers( 1 );

		self::assertSame( 200, $endpoint->handle( $this->request() )->get_status(), 'The limit is per connection address, not global.' );
	}

	/**
	 * Every header a client controls that a naive throttle might key on, plus
	 * a fresh anonymous-session cookie.
	 */
	private function spoof_headers( int $n ): void {
		$fake                           = '192.0.2.' . ( $n % 250 + 1 );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = $fake;
		$_SERVER['HTTP_X_REAL_IP']       = $fake;
		$_SERVER['HTTP_CLIENT_IP']       = $fake;
		$_SERVER['HTTP_FORWARDED']       = 'for=' . $fake;
		$_COOKIE['smaily_anon_sid']      = 'sid-' . $n;
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param(
			'events',
			array(
				array(
					'event_id'   => 'e1',
					'event_type' => 'product_view',
				),
			)
		);
		return $request;
	}

	private function endpoint(): BeaconEndpoint {
		$client = new class( 'sk_unit', 'https://engine.unit' ) extends Client {
			/** @var callable */
			public $on_forward;

			public function ingest_browse( array $events ): array {
				( $this->on_forward )();
				return array(
					'ok'        => true,
					'processed' => count( $events ),
				);
			}
		};
		$client->on_forward = function (): void {
			++$this->forwarded;
		};

		return new class( new FakeRecEngineSettings(), $client ) extends BeaconEndpoint {
			public function __construct( FakeRecEngineSettings $settings, Client $client ) {
				parent::__construct(
					$settings,
					static function () use ( $client ): Client {
						return $client;
					}
				);
			}

			protected function resolve_logged_in_email(): string {
				return '';
			}
		};
	}
}
