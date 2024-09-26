<?php

namespace Smaily_WC;

/**
 * Handles woocommerce related data retrieval
 */
class Data_Handler {


	/**
	 * Generates RSS-feed based on products in WooCommerce store.
	 *
	 * @param string  $category Filter by products category.
	 * @param integer $limit Default value 50.
	 * @return void $rss Rss-feed for Smaily template.
	 */
	public static function generate_rss_feed( $category, $limit, $order_by, $order ) {
		$products       = self::get_products( $category, $limit, $order_by, $order );
		$base_url       = get_site_url();
		$currencysymbol = get_woocommerce_currency_symbol();
		$items          = array();
		foreach ( $products as $prod ) {
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $prod->get_id() );
			} else {
				$product = new \WC_Product( $prod->get_id() );
			}

			$price = floatval( $product->get_price() );
			$price = number_format( floatval( $price ), 2, '.', ',' ) . html_entity_decode( $currencysymbol );

			$discount = 0;
			// Get product price when on sale.
			if ( $product->is_on_sale() ) {
				// Regular price.
				$regular_price = (float) $product->get_regular_price();
				if ( $regular_price > 0 ) {
					// Active price (the "Sale price" when on-sale).
					$sale_price   = (float) $product->get_price();
					$saving_price = $regular_price - $sale_price;
					// Discount precentage.
					$discount = round( 100 - ( $sale_price / $regular_price * 100 ), 2 );
				}
				// Format price and add currency symbol.
				$regular_price = number_format( floatval( $regular_price ), 2, '.', ',' ) . html_entity_decode( $currencysymbol );
			}

			$url   = get_permalink( $prod->get_id() );
			$image = wp_get_attachment_image_src( get_post_thumbnail_id( $prod->get_id() ), 'single-post-thumbnail' );

			$image        = $image[0] ?? '';
			$create_time  = strtotime( $prod->get_date_created() );
			$price_fields = '';
			if ( $discount > 0 ) {
				$price_fields = '
			  <smly:old_price>' . esc_attr( $regular_price ) . '</smly:old_price>
			  <smly:discount>-' . esc_attr( $discount ) . '%</smly:discount>';
			}
			// Parse image to form element.
			$description = do_shortcode( $prod->get_description() );

			$items[] = '<item>
			  <title><![CDATA[' . $prod->get_title() . ']]></title>
			  <link>' . esc_url( $url ) . '</link>
			  <guid isPermaLink="True">' . esc_url( $url ) . '</guid>
			  <pubDate>' . wp_date( 'D, d M Y H:i:s', $create_time ) . '</pubDate>
			  <description><![CDATA[' . $description . ']]></description>
			  <enclosure url="' . esc_url( $image ) . '" />
			  <smly:price>' . esc_attr( $price ) . '</smly:price>' . $price_fields . '
			</item>
			';
		}
		$rss  = '<?xml version="1.0" encoding="utf-8"?><rss xmlns:smly="https://sendsmaily.net/schema/editor/rss.xsd" version="2.0"><channel><title>Store</title><link>' . esc_url( $base_url ) . '</link><description>Product Feed</description><lastBuildDate>' . wp_date( 'D, d M Y H:i:s' ) . '</lastBuildDate>';
		$rss .= implode( ' ', $items );
		$rss .= '</channel></rss>';
		header( 'Content-Type: application/xml' );

		// All values escaped before.
		// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $rss;
	}

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
		$wprod = wc_get_products( $product );
		return $wprod;
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

	/**
	 * Get Product RSS Feed URL.
	 *
	 * @param string $rss_category Category slug.
	 * @param int $rss_limit Limit of products.
	 * @param string $rss_order_by Order products by.
	 * @param string $rss_order ASC/DESC order
	 * @return string
	 */
	public static function make_rss_feed_url( $rss_category = null, $rss_limit = null, $rss_order_by = null, $rss_order = null ) {
		global $wp_rewrite;

		$site_url   = get_site_url( null, 'smaily-rss-feed' );
		$parameters = array();

		if ( isset( $rss_category ) && $rss_category !== '' ) {
			$parameters['category'] = $rss_category;
		}
		if ( isset( $rss_limit ) ) {
			$parameters['limit'] = $rss_limit;
		}
		if ( isset( $rss_order_by ) && $rss_order_by !== 'none' ) {
			$parameters['order_by'] = $rss_order_by;
		}
		if ( isset( $rss_order ) && $rss_order_by !== 'none' && $rss_order_by !== 'rand' ) {
			$parameters['order'] = $rss_order;
		}

		// Handle URL when permalinks have not been enabled.
		if ( $wp_rewrite->using_permalinks() === false ) {
			$site_url                      = get_site_url();
			$parameters['smaily-rss-feed'] = 'true';
		}

		return add_query_arg( $parameters, $site_url );
	}
}
