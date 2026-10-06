<?php
/**
 * Integration: the `smaily/recommendations` block and the
 * [smaily_recommendations] shortcode show a logged-in shopper's own
 * recommendations (contract v1.9.0, §15) as product cards, and show nothing
 * to anyone the engine must not be asked about.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

final class StorefrontRecommendationsRenderTest extends TestCase {

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

		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'sf_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 16, false ),
				'user_email' => 'shopper-' . wp_generate_password( 6, false, false ) . '@example.test',
				'role'       => 'customer',
			)
		);

		self::$engine->set_storefront_slots(
			(string) $this->user_id,
			array(
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
			)
		);
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
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

	public function test_the_shortcode_shows_the_logged_in_shopper_s_recommendations(): void {
		wp_set_current_user( $this->user_id );

		$html = do_shortcode( '[smaily_recommendations]' );

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

	public function test_the_block_renders_the_same_cards(): void {
		wp_set_current_user( $this->user_id );

		$html = do_blocks( '<!-- wp:smaily/recommendations /-->' );

		self::assertStringContainsString( 'smaily-connect-recommendations', $html );
		self::assertStringContainsString( 'Grain-free adult food 3 kg', $html );
	}

	public function test_a_logged_out_visitor_sees_nothing_and_the_engine_is_not_asked(): void {
		self::$engine->reset_request_count();

		self::assertSame( '', do_shortcode( '[smaily_recommendations]' ) );
		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_a_shopper_who_opted_out_of_profiling_sees_nothing_and_the_engine_is_not_asked(): void {
		$email = (string) get_userdata( $this->user_id )->user_email;
		Bootstrap::instance()->profiling_consent()->opt_out( $email );
		wp_set_current_user( $this->user_id );
		self::$engine->reset_request_count();

		self::assertSame( '', do_shortcode( '[smaily_recommendations]' ) );
		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_a_product_the_store_no_longer_shows_is_left_out(): void {
		wp_update_post(
			array(
				'ID'          => $this->product_id,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $this->user_id );

		self::assertSame( '', do_shortcode( '[smaily_recommendations]' ), 'With no card left, nothing renders.' );
	}

	public function test_an_empty_engine_answer_renders_nothing(): void {
		self::$engine->set_storefront_slots( (string) $this->user_id, array() );
		wp_set_current_user( $this->user_id );

		self::assertSame( '', do_shortcode( '[smaily_recommendations]' ) );
	}
}
