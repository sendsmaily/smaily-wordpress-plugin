<?php
/**
 * Test-support helper — the store fixtures the Smaily pipeline tests share.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\Assert;

/**
 * The shopper/product/order the automation-pipeline integration tests build,
 * plus the two seams every one of them needs: the "default" account
 * credentials and a flush through a faked Smaily transport.
 *
 * The instance owns what it created and gives it back in clean_up() — an
 * order goes through wc_get_order()->delete( true ), never wp_delete_post(),
 * which is a silent no-op under HPOS.
 */
final class PipelineFixture {

	/** @var array<int, int> */
	private array $users = array();

	/** @var array<int, int> */
	private array $orders = array();

	/** @var array<int, int> */
	private array $products = array();

	/** LEGACY_OPTION_KEY / "default" account credentials — what every flush dispatches through. */
	public static function seed_credentials(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	/**
	 * Drain a queue through a mocked Smaily transport, collecting EVERY POST
	 * body (one flush can send several rows).
	 *
	 * @return array<int, mixed>
	 */
	public static function flush( string $hook ): array {
		$bodies = array();
		$fake   = static function ( $pre, $args ) use ( &$bodies ) {
			$bodies[] = isset( $args['body'] ) ? $args['body'] : null;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 101,
						'message' => 'OK',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( $hook );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $bodies;
	}

	/** Age every cart-tracker row so the sweeper's cutoff has passed. */
	public static function rewind_tracker_row( int $seconds ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smly_plus_cart_session SET cart_updated = %s",
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	public static function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	public function make_user( string $slug ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_pipeline_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		Assert::assertIsInt( $user_id );
		$this->users[] = $user_id;
		return $user_id;
	}

	/** A shopper account the way WooCommerce creates one (checkout / My Account). */
	public function make_customer( string $slug ): int {
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		try {
			$user_id = wc_create_new_customer( $slug . '-' . wp_generate_password( 6, false ) . '@example.test' );
		} finally {
			remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		}

		Assert::assertIsInt( $user_id );
		$this->users[] = $user_id;
		return $user_id;
	}

	public function make_product( string $name ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '12.00' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();
		Assert::assertGreaterThan( 0, $product_id );
		$this->products[] = $product_id;
		return $product_id;
	}

	public function make_order( int $customer_id ): int {
		$product_id = $this->make_product( 'Pipeline Order Product' );

		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( self::email_of( $customer_id ) );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order_id = (int) $order->save();

		$this->orders[] = $order_id;
		return $order_id;
	}

	/** Give back everything this fixture created. Safe to call twice. */
	public function clean_up(): void {
		foreach ( $this->orders as $order_id ) {
			// NOT wp_delete_post: under HPOS orders live in wc_orders.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		// wp_delete_user() lives in wp-admin/includes/user.php, which nothing in
		// a front-end request loads — a class used to reach it only because some
		// earlier test in the run happened to pull it in (directly, or via
		// dbDelta's upgrade.php). Suite order is filesystem order, so that made
		// the cleanup fail whenever an edit reshuffled the files. Load it here.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->users as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $this->products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->delete( true );
			}
		}

		$this->orders   = array();
		$this->users    = array();
		$this->products = array();
	}
}
