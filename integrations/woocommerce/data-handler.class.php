<?php

namespace Smaily_Connect\Integrations\WooCommerce;

use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Helper;

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
	 * Get user data for WooCommerce subscriber sync.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Available user data.
	 */
	public static function get_user_data( $user_id ) {
		$user = get_userdata( $user_id );
		$meta = get_user_meta( $user_id );

		$user_sync_data = array();
		if ( ! isset( $user->user_email ) || empty( $user->user_email ) ) {
			return $user_sync_data;
		}

		$options = get_option(
			Options::SUBSCRIBER_SYNC_FIELDS_OPTION,
			Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS
		);
		foreach ( $options as $field => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			switch ( $field ) {
				case 'language':
					$user_sync_data['language'] = Helper::get_user_language_code( $user_id );
					break;
				case 'user_email':
					$user_sync_data['email'] = $user->user_email;
					break;
				case 'store_url':
					$user_sync_data['store'] = get_site_url();
					break;
				case 'customer_group':
					$group = $user->roles[0] ?? '';
					if ( ! empty( $group ) ) {
						$user_sync_data['customer_group'] = $group;
					}
					break;
				case 'customer_id':
					$user_sync_data['customer_id'] = $user_id;
					break;
				case 'first_registered':
					$registered = $user->user_registered ?? '';
					if ( ! empty( $registered ) ) {
						$user_sync_data['first_registered'] = $registered;
					}
					break;
				case 'user_gender':
					$gender = $meta['user_gender'][0] ?? '';
					if ( ! empty( $gender ) ) {
						$user_sync_data['user_gender'] = $gender === '0' ? 'Female' : 'Male';
					}
					break;
				case 'site_title':
					$title = get_bloginfo( 'name' );
					if ( ! empty( $title ) ) {
						$user_sync_data['site_title'] = $title;
					}
					break;
				case 'user_dob':
					// 2014-03-28 to YYYY-MM-DD format.
					$birthday = $meta['user_dob'][0] ?? '';
					if ( ! empty( $birthday ) ) {
						$user_sync_data['birthday'] = gmdate( 'Y-m-d', strtotime( $birthday ) );
					}
					break;
				case 'first_name':
				case 'last_name':
				case 'nickname':
				case 'user_phone':
					$value = $meta[ $field ][0] ?? '';
					if ( ! empty( $value ) ) {
						$user_sync_data[ $field ] = $value;
					}
					break;
			}
		}
		return $user_sync_data;
	}
}
