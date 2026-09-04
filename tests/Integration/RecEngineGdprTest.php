<?php
/**
 * Integration: GdprHandler (3.8) — the WP Privacy API exporter (Art 15) + eraser
 * (Art 17) for rec-engine personal data. Scope authority: docs/DATA_MODEL_GDPR.md.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * The headline guarantee (the WC boundary): the rec-engine exporter surfaces
 * rec-specific personal data + the plugin's rec-meta, but NOT WooCommerce order
 * / purchase data — WooCommerce's own exporter owns that. The mock §8 body
 * deliberately includes orders + order_items + decision-logic customer fields so
 * the tests can assert the plugin drops them.
 */
final class RecEngineGdprTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_orders = array();
	/** @var int[] */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'customer_export'  => $base . '/api/v1/customer/%s/export',
					'customer_delete'  => $base . '/api/v1/customer/%s',
					'customer_opt_out' => $base . '/api/v1/customer/%s/opt-out',
				),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $id ) {
			wp_delete_user( $id );
		}
		$this->created_orders = array();
		$this->created_users  = array();
		parent::tearDown();
	}

	public function test_export_surfaces_rec_data_and_plugin_meta_but_not_woo(): void {
		$email = 'gdpr-export@example.test';
		$this->make_order_with_rec_meta( $email );

		$names = $this->export_field_names( $this->handler()->export( $email ) );

		// Engine rec activity is exported.
		self::assertContains( 'event_type', $names, 'browse_events surfaced.' );
		// Plugin rec-meta off the order is exported.
		self::assertContains( '_smaily_rec_id', $names, 'plugin order rec-meta surfaced.' );
		// WooCommerce purchase data is NOT (Woo owns it).
		self::assertNotContains( 'total_amount', $names, 'engine order total must NOT be re-exported (Woo).' );
		self::assertNotContains( 'line_total', $names, 'engine order_items must NOT be re-exported (Woo).' );
	}

	public function test_export_strips_customer_decision_logic_fields(): void {
		$email = 'gdpr-strip@example.test';
		$names = $this->export_field_names( $this->handler()->export( $email ) );

		// Identity fields kept.
		self::assertContains( 'email', $names );
		self::assertContains( 'country', $names );
		// Decision-logic / profiling fields stripped (DATA_MODEL_GDPR.md).
		self::assertNotContains( 'segment', $names );
		self::assertNotContains( 'rfm_recency', $names );
		self::assertNotContains( 'engagement_score', $names );
		self::assertNotContains( 'inferred_species', $names );
	}

	public function test_export_404_still_returns_plugin_meta(): void {
		$email = 'notfound-gdpr@example.test'; // mock §8 → 404
		$this->make_order_with_rec_meta( $email );

		$names = $this->export_field_names( $this->handler()->export( $email ) );

		// No engine data (404), but the plugin's own meta is still exported.
		self::assertContains( '_smaily_rec_id', $names );
		self::assertNotContains( 'event_type', $names );
	}

	public function test_erase_deletes_engine_and_plugin_meta(): void {
		$email   = 'gdpr-erase@example.test';
		$order_id = $this->make_order_with_rec_meta( $email );
		$user     = $this->make_user( $email );
		update_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, 'anon-xyz' );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'] );
		// Plugin meta gone.
		$order = wc_get_order( $order_id );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( '', (string) $order->get_meta( '_smaily_rec_id' ) );
		self::assertSame( '', (string) get_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) );
	}

	public function test_erase_is_idempotent_when_already_deleted(): void {
		$email = 'gdpr-idem@example.test';

		$first = $this->handler()->erase( $email );
		self::assertTrue( $first['items_removed'], 'First erase deletes the engine record.' );

		// Second erase → engine 404 (already deleted) → still a success, no throw.
		$second = $this->handler()->erase( $email );
		self::assertTrue( $second['done'] );
	}

	public function test_export_surfaces_cart_session_row(): void {
		$email = 'gdpr-cart-export@example.test';
		$store = new CartSessionStore();
		$store->upsert(
			'tok-' . wp_generate_uuid4(),
			0,
			$email,
			'Jane',
			'Doe',
			array( array( 'product_id' => 123, 'variation_id' => 0, 'quantity' => 2 ) )
		);

		$names = $this->export_field_names( $this->handler()->export( $email ) );

		self::assertContains( 'cart_content', $names, 'cart-session row surfaced (PRO-1343).' );
		self::assertContains( 'cart_token', $names );
	}

	public function test_erase_deletes_cart_session_row(): void {
		$email = 'gdpr-cart-erase@example.test';
		$store = new CartSessionStore();
		$store->upsert( 'tok-' . wp_generate_uuid4(), 0, $email, 'Jane', 'Doe', array() );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'] );
		self::assertSame( array(), $store->rows_for_privacy_request( $email ) );
	}

	public function test_erase_matches_cart_session_by_user_id_when_email_column_drifted(): void {
		$email = 'gdpr-cart-drift@example.test';
		$user  = $this->make_user( $email );
		$store = new CartSessionStore();
		// Defensive case the acceptance criteria names: a row keyed to the WP
		// user but whose email column wasn't populated. Current write paths
		// never produce this (email is always set alongside user_id), but the
		// erase must still find the row via the user's account email.
		$store->upsert( 'tok-' . wp_generate_uuid4(), $user->ID, '', '', '', array() );

		$result = $this->handler()->erase( $email );

		self::assertTrue( $result['items_removed'], 'Row matched via user_id despite an empty email column.' );
		self::assertSame( array(), $store->rows_for_privacy_request( '', $user->ID ) );
	}

	// --- helpers --------------------------------------------------------

	private function handler(): GdprHandler {
		$settings = new RecEngineSettings();
		return new GdprHandler(
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			},
			new CartSessionStore()
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function export_field_names( array $result ): array {
		$names = array();
		foreach ( $result['data'] as $item ) {
			foreach ( $item['data'] as $pair ) {
				$names[] = (string) $pair['name'];
			}
		}
		return $names;
	}

	private function make_order_with_rec_meta( string $email ): int {
		$product = new \WC_Product_Simple();
		$product->set_sku( 'GDPR-' . wp_generate_uuid4() );
		$product->set_regular_price( '67.50' );
		$product->set_price( '67.50' );
		$product->save();

		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( (int) $product->get_id() ), 1 );
		$order->calculate_totals();
		$order->update_meta_data( '_smaily_rec_id', 'rec-abc123' );
		$order->update_meta_data( '_smaily_visitor_token', 'vt_xyz' );
		$id                     = (int) $order->save();
		$this->created_orders[] = $id;
		return $id;
	}

	private function make_user( string $email ): \WP_User {
		$id = wp_insert_user(
			array(
				'user_login' => 'gdpr_' . md5( $email ),
				'user_pass'  => 'x' . wp_generate_password( 12, false ),
				'user_email' => $email,
				'role'       => 'customer',
			)
		);
		self::assertIsInt( $id );
		$this->created_users[] = (int) $id;
		$user = get_userdata( (int) $id );
		self::assertInstanceOf( \WP_User::class, $user );
		return $user;
	}
}
