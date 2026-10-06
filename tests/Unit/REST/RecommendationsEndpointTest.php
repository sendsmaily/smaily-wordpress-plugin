<?php
/**
 * PRO-3835: the store's public recommendations route — who it passes on to
 * StorefrontRecommendations, what it refuses before doing any work, and that
 * no shared cache may keep its answer. The cards and the engine call are
 * covered by StorefrontRecommendationsTest and the integration suite.
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\StorefrontRecommendations;
use Smaily\Connect\REST\RecommendationsEndpoint;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use WP_REST_Request;

final class RecommendationsEndpointTest extends TestCase {

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<int, array{user_id: int, visitor_token: string}> */
	private array $asked = array();

	private string $cards = '<section>cards</section>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		$this->asked      = array();
		$_SERVER          = array( 'REMOTE_ADDR' => '203.0.113.7' );
		$_COOKIE          = array();

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

	public function test_a_store_that_may_not_call_the_engine_answers_404_before_any_work(): void {
		$response = $this->endpoint( 0, false )->handle( new WP_REST_Request() );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( array(), $this->asked );
		self::assertSame( array(), $this->transients, 'Not even a rate-limit counter.' );
	}

	public function test_a_logged_in_shopper_is_passed_on_by_the_account_and_the_visitor_cookie(): void {
		$_COOKIE['smaily_rec_uid'] = 'vt_AbC123';

		$response = $this->endpoint( 42 )->handle( new WP_REST_Request() );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'html' => $this->cards ), $response->get_data() );
		self::assertSame(
			array(
				array(
					'user_id'       => 42,
					'visitor_token' => 'vt_AbC123',
				),
			),
			$this->asked
		);
	}

	public function test_a_guest_is_passed_on_by_the_visitor_cookie_only(): void {
		$_COOKIE['smaily_rec_uid'] = 'vt_AbC123';

		$this->endpoint( 0 )->handle( new WP_REST_Request() );

		self::assertSame( 0, $this->asked[0]['user_id'] );
		self::assertSame( 'vt_AbC123', $this->asked[0]['visitor_token'] );
	}

	public function test_a_guest_without_marketing_consent_is_not_asked_about_by_the_visitor_cookie(): void {
		// PRO-3857: the server applies the consent rule too, not only sc-recs.js.
		$_COOKIE['smaily_rec_uid'] = 'vs_0123456789ABCDEFabcdef';

		$response = $this->endpoint( 0, true, false )->handle( new WP_REST_Request() );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( '', $this->asked[0]['visitor_token'], 'No consent: the token is not passed on, so the engine is not asked.' );
	}

	public function test_a_logged_in_shopper_is_passed_on_without_the_marketing_consent_check(): void {
		// The account names a logged-in shopper; the profiling check gates it.
		$_COOKIE['smaily_rec_uid'] = 'vt_AbC123';

		$this->endpoint( 42, true, false )->handle( new WP_REST_Request() );

		self::assertSame( 42, $this->asked[0]['user_id'] );
		self::assertSame( 'vt_AbC123', $this->asked[0]['visitor_token'] );
	}

	public function test_a_visitor_token_in_the_request_itself_is_ignored(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'smaily_visitor_token', 'vt_Someone' );
		$request->set_param( 'smaily_rec_uid', 'vt_Someone' );

		$this->endpoint( 0 )->handle( $request );

		self::assertSame( '', $this->asked[0]['visitor_token'], 'Only the cookie names the visitor.' );
	}

	public function test_the_answer_is_never_kept_by_a_shared_cache(): void {
		$response = $this->endpoint( 0 )->handle( new WP_REST_Request() );

		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
	}

	public function test_another_site_gets_an_empty_answer_and_nobody_is_asked_about(): void {
		$_COOKIE['smaily_rec_uid'] = 'vt_AbC123';

		foreach ( array( 'cross-site', 'same-site' ) as $site ) {
			$request = new WP_REST_Request();
			$request->set_header( 'Sec-Fetch-Site', $site );

			$response = $this->endpoint( 42 )->handle( $request );

			self::assertSame( 200, $response->get_status(), $site );
			self::assertSame( array( 'html' => '' ), $response->get_data(), $site );
			self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null, $site );
		}
		self::assertSame( array(), $this->asked, 'Another site must not read a shopper\'s recommendations with the shopper\'s cookies.' );
	}

	public function test_the_store_s_own_pages_are_answered(): void {
		$request = new WP_REST_Request();
		$request->set_header( 'Sec-Fetch-Site', 'same-origin' );

		$this->endpoint( 0 )->handle( $request );

		self::assertCount( 1, $this->asked );
	}

	public function test_one_connection_address_is_limited_per_window(): void {
		$endpoint = $this->endpoint( 0 );
		for ( $i = 1; $i <= RecommendationsEndpoint::RL_MAX_PER_IP; $i++ ) {
			self::assertSame( 200, $endpoint->handle( new WP_REST_Request() )->get_status(), "Request {$i} is within the ceiling." );
		}

		$response = $endpoint->handle( new WP_REST_Request() );

		self::assertSame( 429, $response->get_status() );
		self::assertCount( RecommendationsEndpoint::RL_MAX_PER_IP, $this->asked, 'Nothing past the ceiling was looked up.' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		self::assertSame( 200, $endpoint->handle( new WP_REST_Request() )->get_status(), 'Another address has its own window.' );
	}

	private function endpoint( int $user_id, bool $sending_allowed = true, bool $consent = true ): RecommendationsEndpoint {
		$test = $this;

		return new class( new FakeRecEngineSettings( $sending_allowed ), $test, $user_id, $consent ) extends RecommendationsEndpoint {
			private int $user_id;

			private bool $consent;

			public function __construct( FakeRecEngineSettings $settings, RecommendationsEndpointTest $test, int $user_id, bool $consent ) {
				$this->user_id = $user_id;
				$this->consent = $consent;
				parent::__construct(
					$settings,
					static function () use ( $test ): StorefrontRecommendations {
						return $test->recommendations();
					}
				);
			}

			protected function logged_in_user_id(): int {
				return $this->user_id;
			}

			protected function marketing_consent_given(): bool {
				return $this->consent;
			}
		};
	}

	/**
	 * A StorefrontRecommendations double that records who it was asked about.
	 */
	public function recommendations(): StorefrontRecommendations {
		$test = $this;

		return new class( $test ) extends StorefrontRecommendations {
			private RecommendationsEndpointTest $test;

			public function __construct( RecommendationsEndpointTest $test ) {
				$this->test = $test;
			}

			public function cards( int $user_id, string $visitor_token ): string {
				return $this->test->cards_for( $user_id, $visitor_token );
			}
		};
	}

	public function cards_for( int $user_id, string $visitor_token ): string {
		$this->asked[] = array(
			'user_id'       => $user_id,
			'visitor_token' => $visitor_token,
		);
		return $this->cards;
	}
}
