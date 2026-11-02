<?php
/**
 * Integration: a store-created visitor token at login (CON-251, contract
 * v1.22.0 §7) — login creates the token and merges it, and after logout the
 * same browser gets the customer's storefront recommendations by that token.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Integrations\WooCommerce\StorefrontRecommendations;
use Smaily\Connect\REST\RecommendationsEndpoint;
use Smaily\Connect\REST\RequestThrottle;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that the unit test can't: the real handler reads the
 * engine config's cookie name and lifetime, the real Client posts the §7 body,
 * the mock binds the new token to the customer, and the real recommendations
 * route — with no account cookie, as after logout — asks the engine by that
 * token and renders the customer's cards. Only the consent answer is a seam
 * (the test site has no WP Consent API), and the cookie write is recorded
 * instead of sent (PHPUnit's output makes the real headers_sent() true).
 */
final class RecEngineLoginVisitorTokenTest extends TestCase {

	/** A custom engine-config cookie name and lifetime, to prove both are read. */
	private const VISITOR_COOKIE = 'acme_vt';
	private const COOKIE_DAYS    = 400;

	private const SESSION_COOKIE = 'smaily_anon_sid';

	private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

	private static ?RecEngineMockServer $engine = null;

	private int $user_id = 0;

	private int $product_id = 0;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce is not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		$this->forget_browser();
		delete_transient( 'smly_recs_rl_ip_' . md5( RequestThrottle::client_ip() ) );
		delete_transient( StorefrontRecommendations::PAUSE_KEY );

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'identity_merge'           => $base . '/api/v1/identity/merge',
					'recommendations_customer' => $base . '/api/v1/recommendations/customer',
				),
				'config'          => array_merge(
					EnvSeed::fixture_config(),
					array(
						'tracking_cookie_name' => self::VISITOR_COOKIE,
						'cookie_ttl_days'      => self::COOKIE_DAYS,
					)
				),
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Grain-free adult food 3 kg' );
		$product->set_regular_price( '29.99' );
		$product->set_status( 'publish' );
		$this->product_id = (int) $product->save();

		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'lvt_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 16, false ),
				'user_email' => 'login-token-' . wp_generate_password( 6, false, false ) . '@example.test',
				'role'       => 'customer',
			)
		);
		self::$engine->set_storefront_slots( (string) $this->user_id, $this->slots() );
	}

	protected function tearDown(): void {
		delete_transient( StorefrontRecommendations::PAUSE_KEY );
		wp_set_current_user( 0 );
		$this->forget_browser();
		$product = wc_get_product( $this->product_id );
		if ( $product ) {
			$product->delete( true );
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( $this->user_id > 0 ) {
			wp_delete_user( $this->user_id );
		}
		parent::tearDown();
	}

	public function test_login_creates_and_merges_a_token_and_after_logout_the_browser_gets_the_customers_recommendations(): void {
		$cookies = $this->recording_cookies();
		$user    = get_userdata( $this->user_id );
		self::assertInstanceOf( \WP_User::class, $user );

		// Login: no visitor-token cookie yet, marketing consent given.
		$this->handler( $cookies, true )->on_login( $user->user_login, $user );

		$token = $cookies->written[ self::VISITOR_COOKIE ] ?? '';
		self::assertMatchesRegularExpression( '/^vs_[A-Za-z0-9]{22}$/', $token, 'A store token in the contract format, under the engine-config cookie name.' );
		self::assertEqualsWithDelta( time() + self::COOKIE_DAYS * 86400, $cookies->expires[ self::VISITOR_COOKIE ] ?? 0, 10, 'The engine-config cookie lifetime.' );
		$received = self::$engine->state()['last_merge_received'] ?? null;
		self::assertIsArray( $received );
		self::assertSame( $token, $received['smaily_visitor_token'], 'The merge carries the new token (§7).' );
		self::assertSame( (string) $this->user_id, self::$engine->state()['visitor_token_bindings'][ $token ]['customer_external_id'] ?? null, 'The engine bound it to this customer.' );

		// Logout: no account cookie, nobody logged in; the token cookie stays.
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$_COOKIE = array( self::VISITOR_COOKIE => $token );

		$html = $this->consenting_guest_cards();

		self::assertStringContainsString( 'Grain-free adult food 3 kg', $html, 'The logged-out browser sees the customer\'s recommendations.' );
		self::assertSame(
			array(
				'smaily_visitor_token' => $token,
				'limit'                => 4,
			),
			self::$engine->state()['last_recommendations_request'] ?? null,
			'Asked by the token alone — no customer id, no email.'
		);
	}

	public function test_the_next_login_renews_the_cookie_and_the_merge_renews_the_token(): void {
		$cookies = $this->recording_cookies();
		$user    = get_userdata( $this->user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-repeat';

		$this->handler( $cookies, true )->on_login( $user->user_login, $user );
		$token = $cookies->written[ self::VISITOR_COOKIE ] ?? '';
		$this->handler( $cookies, true )->on_login( $user->user_login, $user );

		// Erkki, 2026-11-02: the second login writes the same cookie again,
		// same value, with a fresh engine-config lifetime from now.
		self::assertCount( 2, $cookies->writes );
		self::assertSame( array( self::VISITOR_COOKIE, $token ), array_slice( $cookies->writes[1], 0, 2 ), 'The second login keeps the token value.' );
		self::assertEqualsWithDelta( time() + self::COOKIE_DAYS * 86400, $cookies->writes[1][2], 10, 'A fresh lifetime from now.' );
		self::assertSame( 2, self::$engine->state()['merge_count'] ?? null, 'One merge per login.' );
		self::assertSame( 2, self::$engine->state()['visitor_token_bindings'][ $token ]['merges'] ?? null, 'The engine renewed the token at the second login.' );
		$received = self::$engine->state()['last_merge_received'] ?? null;
		self::assertIsArray( $received );
		self::assertSame( $token, $received['smaily_visitor_token'] );
		self::assertSame( '', $received['anon_session_id'], 'The session merged at the first login is left out.' );
	}

	public function test_without_marketing_consent_no_token_is_created_and_nothing_is_merged(): void {
		// The real consent check: the test site has no WP Consent API, so it
		// fails closed.
		self::assertFalse( function_exists( 'wp_has_consent' ), 'Precondition: no WP Consent API on the test site.' );
		$cookies = $this->recording_cookies();
		$user    = get_userdata( $this->user_id );
		self::assertInstanceOf( \WP_User::class, $user );

		$this->handler( $cookies, null )->on_login( $user->user_login, $user );

		self::assertSame( array(), $cookies->written, 'No cookie is written.' );
		self::assertNull( self::$engine->state()['last_merge_received'] ?? null, 'Nothing is merged.' );

		// After logout the browser has no token, so the engine is not asked.
		wp_set_current_user( 0 );
		self::$engine->reset_request_count();
		self::assertSame( '', $this->consenting_guest_cards() );
		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_without_marketing_consent_an_engine_token_is_merged_but_its_cookie_is_not_renewed(): void {
		self::assertFalse( function_exists( 'wp_has_consent' ), 'Precondition: no WP Consent API on the test site.' );
		$cookies                         = $this->recording_cookies();
		$user                            = get_userdata( $this->user_id );
		$_COOKIE[ self::VISITOR_COOKIE ] = 'vt_fromemaillink123';
		self::assertInstanceOf( \WP_User::class, $user );

		$this->handler( $cookies, null )->on_login( $user->user_login, $user );

		self::assertSame( array(), $cookies->writes, 'No renewal without consent.' );
		$received = self::$engine->state()['last_merge_received'] ?? null;
		self::assertIsArray( $received );
		self::assertSame( 'vt_fromemaillink123', $received['smaily_visitor_token'], 'A `vt_` token keeps today\'s rules: merged without consent.' );
	}

	// ---- Helpers ---------------------------------------------------------

	/**
	 * The real handler and Client; `$consent` null keeps the real consent
	 * check, a bool answers it.
	 */
	private function handler( LandingCapture $cookies, ?bool $consent ): IdentityHookHandler {
		$settings = new RecEngineSettings();
		$client   = static function () use ( $settings ): Client {
			return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
		};
		if ( null === $consent ) {
			return new IdentityHookHandler( $settings, $client, null, $cookies );
		}

		return new class( $settings, $client, $cookies, $consent ) extends IdentityHookHandler {
			private bool $test_consent;

			public function __construct( RecEngineSettings $settings, callable $client, LandingCapture $cookies, bool $consent ) {
				parent::__construct( $settings, $client, null, $cookies );
				$this->test_consent = $consent;
			}

			protected function marketing_consent_given(): bool {
				return $this->test_consent;
			}
		};
	}

	/**
	 * A LandingCapture that records cookie writes instead of sending headers.
	 */
	private function recording_cookies(): LandingCapture {
		return new class( new RecEngineSettings() ) extends LandingCapture {
			/** @var array<string, string> */
			public array $written = array();

			/** @var array<string, int> */
			public array $expires = array();

			/** @var array<int, array{0: string, 1: string, 2: int}> name, value, expires */
			public array $writes = array();

			protected function headers_already_sent(): bool {
				return false; // PHPUnit's progress output makes the real one true.
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				$this->written[ $name ] = $value;
				$this->expires[ $name ] = $expires;
				$this->writes[]         = array( $name, $value, $expires );
			}
		};
	}

	/**
	 * The recommendations route as a logged-out shopper with a marketing yes
	 * sees it: the real handler, engine call and cards, with only the consent
	 * answer given.
	 */
	private function consenting_guest_cards(): string {
		$endpoint = new class(
			new RecEngineSettings(),
			static function (): StorefrontRecommendations {
				return Bootstrap::instance()->storefront_recommendations();
			}
		) extends RecommendationsEndpoint {
			protected function marketing_consent_given(): bool {
				return true;
			}
		};

		$response = $endpoint->handle( new \WP_REST_Request( 'GET', '/smaily-connect/v1' . RecommendationsEndpoint::ROUTE ) );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		return is_array( $data ) ? (string) ( $data['html'] ?? '' ) : '';
	}

	/** @return array<int, array<string, mixed>> */
	private function slots(): array {
		return array(
			array(
				'position'    => 1,
				'rec_id'      => self::REC_ID,
				'sku'         => 'woo-' . $this->product_id,
				'external_id' => (string) $this->product_id,
				'name'        => 'Grain-free adult food 3 kg',
				'price'       => 29.99,
				'in_stock'    => true,
				'product_url' => 'https://shop.example/product?smaily_rec=' . self::REC_ID . '&smaily_ctx=storefront',
			),
		);
	}

	private function forget_browser(): void {
		unset( $_COOKIE[ self::VISITOR_COOKIE ], $_COOKIE[ self::SESSION_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
	}
}
