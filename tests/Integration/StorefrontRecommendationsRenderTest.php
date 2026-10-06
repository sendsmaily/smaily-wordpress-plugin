<?php
/**
 * Integration: the `smaily/recommendations` block and the
 * [smaily_recommendations] shortcode print the same empty container for every
 * visitor, and the store's GET /recommendations route answers a shopper's own
 * recommendations (contract v1.9.0, §15) as product cards — by the store
 * customer id for a logged-in shopper, by the visitor-token cookie for a
 * returning guest (PRO-3835) — and nothing to anyone the engine must not be
 * asked about.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\StorefrontRecommendations;
use Smaily\Connect\REST\RequestThrottle;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class StorefrontRecommendationsRenderTest extends TestCase {

	private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

	/** The engine config's default visitor-token cookie. */
	private const VISITOR_COOKIE = 'smaily_rec_uid';

	private static ?RecEngineMockServer $engine = null;

	private int $user_id = 0;

	private int $product_id = 0;

	private string $token = '';

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
		$this->forget_visitor();
		delete_transient( 'smly_recs_rl_ip_' . md5( RequestThrottle::client_ip() ) );

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array( 'recommendations_customer' => $base . '/api/v1/recommendations/customer' ),
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Grain-free adult food 3 kg' );
		$product->set_regular_price( '29.99' );
		$product->set_status( 'publish' );
		$this->product_id = (int) $product->save();

		$this->user_id = $this->make_shopper();
		$this->token   = 'vt_' . wp_generate_password( 16, false, false );

		self::$engine->set_storefront_slots( (string) $this->user_id, $this->slots() );
		self::$engine->set_storefront_visitor_slots( $this->token, $this->slots() );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		$this->forget_visitor();
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
		delete_option( 'smly_profiling_optouts' );
		parent::tearDown();
	}

	// ---- The page --------------------------------------------------------

	public function test_the_page_is_the_same_for_every_visitor_and_asks_the_engine_nothing(): void {
		$other = $this->make_shopper();
		self::$engine->reset_request_count();

		$pages = array();
		foreach ( array( $this->user_id, $other, 0 ) as $user_id ) {
			wp_set_current_user( $user_id );
			$_COOKIE[ self::VISITOR_COOKIE ] = 0 === $user_id ? $this->token : '';
			$pages[]                         = do_shortcode( '[smaily_recommendations]' ) . do_blocks( '<!-- wp:smaily/recommendations /-->' );
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $other );

		self::assertStringContainsString( 'data-smaily-connect-recs', $pages[0] );
		self::assertStringNotContainsString( 'Grain-free', $pages[0], 'No card is in the page HTML.' );
		self::assertSame( $pages[0], $pages[1], 'Two logged-in shoppers get the same page.' );
		self::assertSame( $pages[0], $pages[2], 'A guest gets the same page.' );
		self::assertSame( 0, self::$engine->request_count(), 'The page render never waits for the engine.' );
	}

	public function test_the_page_loads_the_script_with_the_store_route_and_the_consent_category(): void {
		do_shortcode( '[smaily_recommendations]' );

		self::assertTrue( wp_script_is( StorefrontRecommendations::HANDLE, 'enqueued' ) );
		$boot = implode( "\n", (array) wp_scripts()->get_data( StorefrontRecommendations::HANDLE, 'before' ) );
		self::assertStringContainsString( 'window.smailyConnectRecs', $boot );
		self::assertStringContainsString( 'smaily-connect\/v1\/recommendations', $boot );
		self::assertStringContainsString( '"category":"marketing"', $boot );
	}

	public function test_a_store_without_an_engine_connection_prints_nothing(): void {
		EnvScrub::reset();

		self::assertSame( '', do_shortcode( '[smaily_recommendations]' ) );
	}

	// ---- The route -------------------------------------------------------

	public function test_a_logged_in_shopper_gets_their_cards_by_the_store_customer_id(): void {
		$this->log_in( $this->user_id );

		$html = $this->cards();

		self::assertStringContainsString( 'Grain-free adult food 3 kg', $html );
		self::assertStringContainsString( 'smaily_rec=' . self::REC_ID, $html );
		self::assertStringContainsString( 'smaily_ctx=storefront', $html, 'A storefront click must be credited to the store (§15).' );
		self::assertSame(
			array(
				'customer_external_id' => (string) $this->user_id,
				'limit'                => 4,
			),
			self::$engine->state()['last_recommendations_request'] ?? null,
			'The shopper is named by the WordPress user id only — no email reaches the engine.'
		);
	}

	public function test_a_returning_guest_gets_their_cards_by_the_visitor_token_cookie(): void {
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;

		$html = $this->cards();

		self::assertStringContainsString( 'Grain-free adult food 3 kg', $html );
		self::assertSame(
			array(
				'visitor_token' => $this->token,
				'limit'         => 4,
			),
			self::$engine->state()['last_recommendations_request'] ?? null
		);
	}

	public function test_a_visitor_with_neither_an_account_nor_a_token_gets_nothing_and_the_engine_is_not_asked(): void {
		self::$engine->reset_request_count();

		self::assertSame( '', $this->cards( array( 'visitor_token' => $this->token ) ), 'A token in the query does not count.' );
		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_a_shopper_who_opted_out_of_profiling_gets_nothing_and_the_engine_is_not_asked(): void {
		$email = (string) get_userdata( $this->user_id )->user_email;
		Bootstrap::instance()->profiling_consent()->opt_out( $email );
		$this->log_in( $this->user_id );
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;
		self::$engine->reset_request_count();

		self::assertSame( '', $this->cards() );
		self::assertSame( 0, self::$engine->request_count(), 'Not by the account, and not by the visitor token either.' );
	}

	public function test_the_answer_is_cached_per_shopper(): void {
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;
		self::$engine->reset_request_count();

		$first  = $this->cards();
		$second = $this->cards();

		self::assertNotSame( '', $first );
		self::assertSame( $first, $second );
		self::assertSame( 1, self::$engine->request_count(), 'The second request is served from the store\'s cache.' );
	}

	public function test_an_engine_that_refuses_the_guest_is_asked_again_only_after_a_while(): void {
		self::$engine->set_storefront_visitor_token_unsupported( true );
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;
		self::$engine->reset_request_count();

		self::assertSame( '', $this->cards(), 'A 400 shows nothing.' );
		self::assertSame( 1, self::$engine->request_count() );

		self::assertSame( '', $this->cards() );
		self::assertSame( 1, self::$engine->request_count(), 'The failure is cached: the second request makes no engine call.' );
	}

	public function test_no_shared_cache_may_keep_the_answer(): void {
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;

		$response = RestRequestHelper::get( '/recommendations' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
	}

	public function test_a_product_the_store_no_longer_shows_is_left_out(): void {
		wp_update_post(
			array(
				'ID'          => $this->product_id,
				'post_status' => 'draft',
			)
		);
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;

		self::assertSame( '', $this->cards(), 'With no card left, nothing is shown.' );
	}

	public function test_an_empty_engine_answer_shows_nothing(): void {
		self::$engine->set_storefront_visitor_slots( $this->token, array() );
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;

		self::assertSame( '', $this->cards() );
	}

	public function test_a_store_without_an_engine_connection_answers_404(): void {
		EnvScrub::reset();
		$_COOKIE[ self::VISITOR_COOKIE ] = $this->token;

		self::assertSame( 404, RestRequestHelper::get( '/recommendations' )->get_status() );
	}

	// ---- Helpers ---------------------------------------------------------

	/**
	 * @param array<string, mixed> $query
	 */
	private function cards( array $query = array() ): string {
		$response = RestRequestHelper::get( '/recommendations', $query );
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

	private function make_shopper(): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'sf_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 16, false ),
				'user_email' => 'shopper-' . wp_generate_password( 6, false, false ) . '@example.test',
				'role'       => 'customer',
			)
		);
	}

	/**
	 * A real `logged_in` auth cookie — the route validates it directly, the
	 * way a shopper's browser presents it.
	 */
	private function log_in( int $user_id ): void {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
	}

	private function forget_visitor(): void {
		unset( $_COOKIE[ self::VISITOR_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
	}
}
