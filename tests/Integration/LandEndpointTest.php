<?php
/**
 * Integration: the POST /land route (CON-250) — the store route the
 * storefront scripts post a campaign landing's values to, so the server sets
 * the attribution cookies — against the real REST server, the real connection
 * state and the real stored engine config (cookie names / lifetimes).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\REST\LandEndpoint;
use Smaily\Connect\REST\RequestThrottle;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use WP_REST_Request;

/**
 * What this catches that the unit test can't: the route is registered on the
 * live REST server, a real WP_REST_Request carries the posted form body and
 * the `Sec-Fetch-Site` header the way the handler reads them, and the cookie
 * names and lifetimes come from the seeded setup-exchange config — the same
 * cookies the checkout stamping (HookHandler) reads.
 *
 * The cookie write itself goes through a recorder: PHPUnit's progress output
 * has already sent the headers, so a real Set-Cookie cannot be observed here.
 */
final class LandEndpointTest extends TestCase {

	private const UUID = '11111111-2222-4333-8444-555555555555';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$_COOKIE = array();
		// EnvScrub does not sweep transients; start every test with a fresh window.
		delete_transient( 'smly_land_rl_ip_' . md5( RequestThrottle::client_ip() ) );
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		remove_all_filters( 'smaily_connect_capture_attribution' );
		delete_transient( 'smly_land_rl_ip_' . md5( RequestThrottle::client_ip() ) );
		parent::tearDown();
	}

	public function test_the_live_route_answers_404_on_a_disconnected_store(): void {
		// EnvScrub::reset() left the tenant disconnected.
		$response = rest_get_server()->dispatch( $this->request( 'same-origin', array( 'smaily_rec' => self::UUID ) ) );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
	}

	public function test_the_live_route_refuses_another_site_and_sets_no_cookie(): void {
		EnvSeed::connect();

		$response = rest_get_server()->dispatch( $this->request( 'cross-site', array( 'smaily_vt' => 'vt_Planted01' ) ) );

		self::assertSame( 403, $response->get_status() );
		self::assertArrayNotHasKey( 'smaily_rec_uid', $_COOKIE );
	}

	public function test_a_full_link_sets_the_config_named_cookies_with_the_engine_lifetimes(): void {
		EnvSeed::connect();
		$capture = $this->recorder();

		$response = ( new LandEndpoint( new RecEngineSettings(), $capture ) )->handle(
			$this->request(
				'same-origin',
				array(
					'smaily_rec' => self::UUID,
					'smaily_vt'  => 'vt_8f3k2aBz01',
					'smaily_ctx' => 'cart_abandoned',
				)
			)
		);

		self::assertSame( 204, $response->get_status() );
		// Names come from the seeded engine config (EnvSeed::fixture_config) and
		// MUST match what HookHandler::ORDER_META_COOKIES reads at checkout.
		self::assertSame( self::UUID, $capture->written['smaily_rec_id'] ?? null );
		self::assertSame( 'vt_8f3k2aBz01', $capture->written['smaily_rec_uid'] ?? null );
		self::assertSame( 'cart_abandoned', $capture->written['smaily_rec_ctx'] ?? null );
		$ttl_days = (int) ( ( new RecEngineSettings() )->config()['cookie_ttl_days'] ?? 365 );
		self::assertEqualsWithDelta( time() + $ttl_days * 86400, $capture->expires['smaily_rec_uid'] ?? 0, 5 );
	}

	public function test_a_store_created_token_sets_no_cookie(): void {
		EnvSeed::connect();
		$capture = $this->recorder();

		$response = ( new LandEndpoint( new RecEngineSettings(), $capture ) )->handle(
			$this->request( 'same-origin', array( 'smaily_vt' => 'vs_0123456789ABCDEFabcdef' ) )
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $capture->written );
	}

	public function test_the_capture_switch_turns_the_route_off_too(): void {
		EnvSeed::connect();
		add_filter( 'smaily_connect_capture_attribution', '__return_false' );
		$capture = $this->recorder();

		$response = ( new LandEndpoint( new RecEngineSettings(), $capture ) )->handle(
			$this->request( 'same-origin', array( 'smaily_rec' => self::UUID ) )
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $capture->written );
	}

	/**
	 * @param array<string, string> $body
	 */
	private function request( string $sec_fetch_site, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1' . LandEndpoint::ROUTE );
		$request->set_header( 'Sec-Fetch-Site', $sec_fetch_site );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $body );
		return $request;
	}

	/**
	 * @return LandingCapture&object{written: array<string, string>, expires: array<string, int>}
	 */
	private function recorder(): LandingCapture {
		return new class( new RecEngineSettings() ) extends LandingCapture {
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
	}
}
