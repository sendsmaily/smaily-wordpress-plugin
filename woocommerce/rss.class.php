<?php

namespace Smaily_WC;

use Smaily_WC\Data_Handler;
use WC_Product;

/**
 * Handles RSS generation for Smaily newsletter
 */
class Rss {
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

	/**
	 * Rewrite rule for url-handling
	 */
	public function smaily_rewrite_rules() {
		add_rewrite_rule(
			'smaily-rss-feed/?$',
			'index.php?smaily-rss-feed=true',
			'top'
		);
	}

	/**
	 * Adds query variable to list of query variables
	 *
	 * @param array $vars Current list of query variables.
	 * @return array $vars Updated list of query variables
	 */
	public function smaily_register_query_var( $vars ) {
		$vars[] = 'smaily-rss-feed';
		$vars[] = 'category';
		$vars[] = 'limit';
		$vars[] = 'order_by';
		$vars[] = 'order';
		return $vars;
	}

	/**
	 * Loads template file for RSS-feed page
	 *
	 * @param string $template Normal template.
	 * @return string Updated template location
	 */
	public function smaily_rss_feed_template_include( $template ) {
		$render_rss_feed = get_query_var( 'smaily-rss-feed', false );
		$render_rss_feed = $render_rss_feed === 'true' ? '1' : $render_rss_feed;
		$render_rss_feed = (bool) (int) $render_rss_feed;

		$pagename = get_query_var( 'pagename' );

		// Render products RSS feed, if requested.
		if ( $render_rss_feed === true ) {
			return SMAILY_PLUGIN_PATH . 'public/template/smaily-rss-feed.php';
		} elseif ( $pagename === 'smaily-rss-feed' ) {
			return SMAILY_PLUGIN_PATH . 'public/template/smaily-rss-feed.php';
		}

		// Load normal template as a fallback.
		return $template;
	}

	/**
	 * Conditionally flush rewrite rules.
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'smaily_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'smaily_flush_rewrite_rules' );
		}
	}

	/**
	 * List store products as RSS feed items.
	 *
	 * @param string $category
	 * @param int $limit
	 * @param string $order_by
	 * @param string $order
	 * @return array{created_at: string, current_price: string, description: string, discount: float, enclosure_url: string, regular_price: string, title: string, url: string}
	 */
	public static function list_rss_feed_items( $category, $limit, $order_by, $order ) {
		$products = Data_Handler::get_products( $category, $limit, $order_by, $order );
		$items    = array();
		foreach ( $products as $prod ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $prod->get_id() ) : new WC_Product( $prod->get_id() );

			if ( $product === false || $product === null ) {
				continue;
			}

			$current_price = $product->get_price();
			$regular_price = $product->get_regular_price();
			$url           = get_permalink( $product->get_id() );

			if ( $url === false ) {
				continue;
			}

			$rss_feed_item = array(
				'current_price' => $current_price,
				'regular_price' => $product->is_on_sale() ? $regular_price : $current_price,
				'discount'      => self::calculate_discount( floatval( $current_price ), floatval( $regular_price ) ),
				'url'           => $url,
				'title'         => $product->get_title(),
				'created_at'    => $product->get_date_created()->date_i18n( 'D, d M Y H:i:s' ),
				'enclosure_url' => self::get_product_image_url( $product->get_id() ),
				'description'   => do_shortcode( $product->get_description() ),
			);

			$items[] = $rss_feed_item;
		}

		return $items;
	}

	/**
	 * Calculates discount percentage between the current price and the regular price.
	 *
	 * @param float $current_price
	 * @param float $regular_price
	 * @return float
	 */
	private static function calculate_discount( $current_price, $regular_price ) {
		if ( $current_price > $regular_price ) {
			return 0.0;
		}

		if ( $regular_price > 0 ) {
			return round( 100 - ( $current_price / $regular_price * 100 ), 2 );
		}

		return 0.0;
	}

	/**
	 * Get the thumbnail image URL for the product.
	 *
	 * @param int $product_id
	 * @return string
	 */
	private static function get_product_image_url( $product_id ) {
		$thumbnail_id = get_post_thumbnail_id( $product_id );

		if ( $thumbnail_id === false ) {
			return '';
		}

		$image_data = wp_get_attachment_image_src(
			$thumbnail_id,
			'single-post-thumbnail'
		);

		if ( $image_data === false ) {
			return '';
		}

		return $image_data[0];
	}
}
