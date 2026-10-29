<?php
/**
 * CON-250: the store route the storefront scripts post a campaign landing's
 * values to — which cookies it sets (LandingCapture's rules), what it refuses
 * before setting any, and that no shared cache may keep its answer.
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\REST\LandEndpoint;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class LandEndpointTest extends TestCase {

	private const UUID = '11111111-2222-4333-8444-555555555555';

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var LandingCapture&object{written: array<string, string>, expires: array<string, int>} */
	private LandingCapture $capture;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		$_SERVER          = array( 'REMOTE_ADDR' => '203.0.113.7' );
		$_COOKIE          = array();

		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
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

	public function test_a_full_link_sets_the_three_cookies_from_the_server(): void {
		$response = $this->endpoint()->handle(
			$this->post(
				array(
					'smaily_rec' => self::UUID,
					'smaily_vt'  => 'vt_8f3k2aBz01',
					'smaily_ctx' => 'welcome',
				)
			)
		);

		self::assertSame( 204, $response->get_status() );
		self::assertNull( $response->get_data() );
		self::assertSame(
			array(
				'smaily_rec_id'  => self::UUID,
				'smaily_rec_uid' => 'vt_8f3k2aBz01',
				'smaily_rec_ctx' => 'welcome',
			),
			$this->capture->written
		);
		// The engine's lifetimes, from the server: the visitor token 365 days.
		self::assertEqualsWithDelta( time() + 365 * 86400, $this->capture->expires['smaily_rec_uid'], 5 );
		self::assertEqualsWithDelta( time() + 30 * 86400, $this->capture->expires['smaily_rec_id'], 5 );
	}

	public function test_a_rec_id_without_a_context_deletes_the_context_cookie(): void {
		// The context cookie rule (contract v1.9.0), as LandingCapture::resolve().
		$response = $this->endpoint()->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) );

		self::assertSame( 204, $response->get_status() );
		self::assertSame( '', $this->capture->written['smaily_rec_ctx'] ?? null );
		self::assertLessThan( time(), $this->capture->expires['smaily_rec_ctx'] );
	}

	public function test_a_store_created_token_is_refused_and_no_cookie_is_set(): void {
		// A link carries the engine's `vt_` token only (PRO-3857).
		$response = $this->endpoint()->handle( $this->post( array( 'smaily_vt' => 'vs_0123456789ABCDEFabcdef' ) ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $this->capture->written );
	}

	public function test_values_without_their_shape_are_refused_and_no_cookie_is_set(): void {
		$response = $this->endpoint()->handle(
			$this->post(
				array(
					'smaily_rec' => 'not-a-uuid',
					'smaily_vt'  => 'not-a-token',
					'smaily_ctx' => 'welcome',
				)
			)
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $this->capture->written );
	}

	public function test_only_the_posted_body_is_read(): void {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/land' );
		$request->set_header( 'Sec-Fetch-Site', 'same-origin' );
		$request->set_query_params( array( 'smaily_rec' => self::UUID ) );
		$request->set_body_params(
			array(
				'utm_source'  => 'smaily',
				'utm_content' => self::UUID,
			)
		);

		$response = $this->endpoint()->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $this->capture->written );
	}

	public function test_a_request_that_is_not_from_the_store_s_own_pages_sets_no_cookie(): void {
		foreach ( array( 'cross-site', 'same-site', 'none', '' ) as $site ) {
			$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/land' );
			if ( '' !== $site ) {
				$request->set_header( 'Sec-Fetch-Site', $site );
			}
			$request->set_body_params( array( 'smaily_vt' => 'vt_Planted' ) );

			$response = $this->endpoint()->handle( $request );

			self::assertSame( 403, $response->get_status(), $site );
			self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null, $site );
		}
		self::assertSame( array(), $this->capture->written, 'Another site must not plant a visitor token.' );
	}

	public function test_a_disconnected_store_answers_404_before_any_work(): void {
		$response = $this->endpoint( false )->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( array(), $this->capture->written );
		self::assertSame( array(), $this->transients, 'Not even a rate-limit counter.' );
	}

	public function test_the_capture_switch_turns_the_route_off_too(): void {
		Functions\when( 'apply_filters' )->justReturn( false );

		$response = $this->endpoint()->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $this->capture->written );
	}

	public function test_every_answer_is_kept_by_no_shared_cache(): void {
		$set     = $this->endpoint()->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) );
		$refused = $this->endpoint()->handle( $this->post( array() ) );

		self::assertSame( 'no-store, private', $set->get_headers()['Cache-Control'] ?? null );
		self::assertSame( 'no-store, private', $refused->get_headers()['Cache-Control'] ?? null );
	}

	public function test_one_connection_address_is_limited_per_window(): void {
		$endpoint = $this->endpoint();
		for ( $i = 1; $i <= LandEndpoint::RL_MAX_PER_IP; $i++ ) {
			self::assertSame( 204, $endpoint->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) )->get_status(), "Request {$i} is within the ceiling." );
		}

		$this->capture->written = array();
		$response               = $endpoint->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) );

		self::assertSame( 429, $response->get_status() );
		self::assertSame( array(), $this->capture->written, 'Nothing past the ceiling was set.' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		self::assertSame( 204, $endpoint->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) )->get_status(), 'Another address has its own window.' );
	}

	public function test_a_forwarding_header_does_not_open_a_new_window(): void {
		// REMOTE_ADDR only (PRO-3620): a client cannot choose its own bucket.
		$endpoint = $this->endpoint();
		for ( $i = 1; $i <= LandEndpoint::RL_MAX_PER_IP; $i++ ) {
			$endpoint->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) );
		}
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		self::assertSame( 429, $endpoint->handle( $this->post( array( 'smaily_rec' => self::UUID ) ) )->get_status() );
	}

	/**
	 * @param array<string, string> $body
	 */
	private function post( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/land' );
		$request->set_header( 'Sec-Fetch-Site', 'same-origin' );
		$request->set_body_params( $body );
		return $request;
	}

	private function endpoint( bool $connected = true ): LandEndpoint {
		$settings      = new FakeRecEngineSettings( $connected );
		$this->capture = new class( $settings ) extends LandingCapture {
			/** @var array<string, string> */
			public array $written = array();

			/** @var array<string, int> */
			public array $expires = array();

			protected function headers_already_sent(): bool {
				return false; // PHPUnit's progress output makes the real one true.
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				$this->written[ $name ] = $value;
				$this->expires[ $name ] = $expires;
			}
		};

		return new LandEndpoint( $settings, $this->capture );
	}
}
