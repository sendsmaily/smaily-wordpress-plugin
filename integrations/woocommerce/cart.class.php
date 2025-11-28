<?php

namespace Smaily_Connect\Integrations\WooCommerce;

use Smaily_Connect\Includes\Helper;

class Cart {
	/**
	 * Abandoned cart table name.
	 */
	const ABANDONED_CART_TABLE_NAME = 'smaily_connect_abandoned_carts';

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Register hooks for the WooCommerce cart integration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_cart_updated', array( $this, 'smaily_update_cart_details' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'smaily_checkout_delete_cart' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'smaily_checkout_delete_cart' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'smaily_checkout_delete_cart' ) );
	}

	/**
	 * Clears cart from abandoned carts table for that user, when customer makes order.
	 */
	public function smaily_checkout_delete_cart() {
		if ( is_user_logged_in() ) {
			global $wpdb;
			$user_id    = get_current_user_id();
			$table_name = $wpdb->prefix . self::ABANDONED_CART_TABLE_NAME;
			$wpdb->delete(
				$table_name,
				array(
					'customer_id' => $user_id,
				)
			);
		}
	}

	/**
	 * Updates abandoned carts table with user data.
	 *
	 * @return void
	 */
	public function smaily_update_cart_details() {

		// Don't run if on admin screen, if user is not logged in or if the request was made by independently by the browser, preventing multiple or false requests when not needed
		if ( Helper::is_admin_screen() || ! is_user_logged_in() || Helper::is_browser_request() ) {
			return;
		}

		// Check if the function has already run in this request.
		if ( get_transient( 'smaily_connect_cart_updated' ) ) {
			return;
		}

		/**
		 * Set a transient to prevent multiple calls in a small duration.
		 */
		set_transient( 'smaily_connect_cart_updated', true, 1 );

		global $wpdb;
		// Customer data.
		$user_id = get_current_user_id();
		// Customer cart.
		$cart = WC()->cart->get_cart();
		// Time.
		$current_time      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$cart_status       = 'open';
		$table             = $wpdb->prefix . self::ABANDONED_CART_TABLE_NAME;
		$has_previous_cart = $this->has_previous_cart( $user_id );
		// If customer doesn't have active cart, create one.
		if ( ! $has_previous_cart ) {
			// Insert new row to table.
			if ( ! WC()->cart->is_empty() ) {
				$insert_query = $wpdb->insert(
					$table,
					array(
						'customer_id'  => $user_id,
						'cart_updated' => $current_time,
						'cart_status'  => $cart_status,
						'cart_content' => serialize( $cart ),
					)
				);
			}
		} else {
			// If customer has items update cart contents and time.
			if ( ! WC()->cart->is_empty() ) {
				$update_query = $wpdb->update(
					$table,
					array(
						'cart_updated' => $current_time,
						'cart_content' => serialize( $cart ),
						'cart_status'  => $cart_status,
					),
					array( 'customer_id' => $user_id )
				);
			} else {
				$wpdb->delete(
					$table,
					array(
						'customer_id' => $user_id,
					)
				);
			}
		}
	}

	/**
	 * Check if customer has active cart in database.
	 *
	 * @param int $user_id Customer id.
	 * @return boolean
	 */
	private function has_previous_cart( $customer_id ) {
		global $wpdb;

		// Get row with user id.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `%1$s` WHERE customer_id = \'%2$d\'',
				$wpdb->prefix . self::ABANDONED_CART_TABLE_NAME,
				$customer_id
			),
			'ARRAY_A'
		);
		if ( empty( $row ) ) {
			return false;
		} else {
			return true;
		}
	}
}
