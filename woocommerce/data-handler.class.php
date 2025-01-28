<?php

namespace Smaily_WC;

/**
 * Handles WooCommerce related data retrieval
 */
class Data_Handler {
	/**
	 * Get published products from WooCommerce database.
	 *
	 * @param string  $category Limit products by category.
	 * @param integer $limit Maximum number of products fetched.
	 * @param string  $order_by Order products by this.
	 * @param string  $order Ascending/Descending.
	 * @return array $products WooCommerce products.
	 */
	public static function get_products( $category, $limit, $order_by, $order ) {
		// Initial query.
		$product = array(
			'status'  => 'publish',
			'limit'   => $limit,
			'orderby' => 'none',
			'order'   => 'DESC',
		);

		if ( ! empty( $order_by ) ) {
			$product['orderby'] = $order_by;
		}

		if ( ! empty( $order ) ) {
			$product['order'] = $order;
		}
		// Get category to limit results if set.
		if ( ! empty( $category ) ) {
			$product['category'] = array( $category );
		}

		return wc_get_products( $product );
	}

	/**
	 * Get WooCommerce user data from database
	 *
	 * @param int $user_id User ID.
	 * @param array array of woocommerce related data from the options table
	 * @return array Available user data.
	 */
	public static function get_user_data( $user_id, array $options ) {
		// Collect user data from database.
		$user_data = get_userdata( $user_id );
		$user_meta = get_user_meta( $user_id );

		// Get admin panel "Syncronize additional fields".
		$syncronize_additional = $options['woocommerce']['syncronize_additional'];

		// Gather user information into variables if available.
		$email          = isset( $user_data->user_email ) ? $user_data->user_email : '';
		$birthday       = isset( $user_meta['user_dob'][0] ) ? $user_meta['user_dob'][0] : '';
		$customer_group = isset( $user_data->roles[0] ) ? $user_data->roles[0] : '';
		$firstname      = isset( $user_meta['first_name'][0] ) ? $user_meta['first_name'][0] : '';
		$gender         = isset( $user_meta['user_gender'][0] ) ? $user_meta['user_gender'][0] : '';
		// User friendly representation of gender.
		if ( $gender === '0' ) {
			$gender = 'Female';
		} elseif ( $gender === '1' ) {
			$gender = 'Male';
		}
		$lastname         = isset( $user_meta['last_name'][0] ) ? $user_meta['last_name'][0] : '';
		$nickname         = isset( $user_meta['nickname'][0] ) ? $user_meta['nickname'][0] : '';
		$first_registered = isset( $user_data->user_registered ) ? $user_data->user_registered : '';
		$phone            = isset( $user_meta['user_phone'][0] ) ? $user_meta['user_phone'][0] : '';
		$site_title       = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : '';
		// All user data.
		$all_user_data = array(
			'email'            => $email,
			'customer_group'   => $customer_group,
			'customer_id'      => $user_id,
			'first_registered' => $first_registered,
			'first_name'       => $firstname,
			'last_name'        => $lastname,
			'nickname'         => $nickname,
			'user_dob'         => $birthday,
			'user_gender'      => $gender,
			'user_phone'       => $phone,
			'site_title'       => $site_title,
		);

		// Default values that are always synced.
		$user_sync_data = array(
			'email' => $email,
			'store' => get_site_url(),
		);

		// Sync also fields selected from admin panel.
		if ( ! empty( $syncronize_additional ) ) {
			foreach ( $syncronize_additional as $sync_option ) {
				$user_sync_data[ $sync_option ] = $all_user_data[ $sync_option ];
			}
		}

		return $user_sync_data;
	}
}
