<?php

namespace Smaily_WC;

use Smaily_Helper;

/**
 * Manages status of user cart in smaily_abandoned_carts table.
 */
class Cart
{

	/**
	 * Clears cart from smaily_abandoned_carts table for that user, when customer makes order.
	 */
	public function smaily_checkout_delete_cart()
	{
		if (is_user_logged_in()) {
			global $wpdb;
			$user_id    = get_current_user_id();
			$table_name = $wpdb->prefix . 'smaily_abandoned_carts';
			$wpdb->delete(
				$table_name,
				array(
					'customer_id' => $user_id,
				)
			);
		}
	}

	/**
	 * Updates smaily_abandoned_carts table with user data.
	 *
	 * @return void
	 */
	public function smaily_update_cart_details()
	{

		// Don't run if on admin screen, if user is not logged in or if the request was made by independently by the browser, preventing multiple or false requests when not needed
		if (Smaily_Helper::is_admin_screen() || !is_user_logged_in() || Smaily_Helper::is_browser_request()) {
			return;
		}

		// Check if the function has already run in this request.
		if (get_transient('smaily_cart_updated')) {
			return;
		}

		/**
		 * Set a transient to prevent multiple calls in a small duration. 
		 */
		set_transient('smaily_cart_updated', true, 1);


		global $wpdb;
		// Customer data.
		$user_id = get_current_user_id();
		// Customer cart.
		$cart = WC()->cart->get_cart();
		// Time.
		$current_time      = gmdate('Y-m-d\TH:i:s\Z');
		$cart_status       = 'open';
		$table             = $wpdb->prefix . 'smaily_abandoned_carts';
		$has_previous_cart = $this->has_previous_cart($user_id);
		// If customer doesn't have active cart, create one.
		if (!$has_previous_cart) {
			// Insert new row to table.
			if (!WC()->cart->is_empty()) {
				$insert_query = $wpdb->insert(
					$table,
					array(
						'customer_id'  => $user_id,
						'cart_updated' => $current_time,
						'cart_status'  => $cart_status,
						'cart_content' => serialize($cart),
					)
				);
			}
		} else {
			// If customer has items update cart contents and time.
			if (!WC()->cart->is_empty()) {
				$update_query = $wpdb->update(
					$table,
					array(
						'cart_updated' => $current_time,
						'cart_content' => serialize($cart),
						'cart_status'  => $cart_status,
					),
					array('customer_id' => $user_id)
				);
			} else {
				// Delete cart if empty.
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
	private function has_previous_cart($customer_id)
	{
		global $wpdb;
		// Get row with user id.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smaily_abandoned_carts WHERE customer_id=%d",
				$customer_id
			),
			'ARRAY_A'
		);
		if (empty($row)) {
			return false;
		} else {
			return true;
		}
	}
}
