<?php

namespace Smaily_Connect\Integrations\WooCommerce;

use WC_Product;

class Rss {
	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Register hooks for the WooCommerce RSS feed integration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'smaily_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'smaily_register_query_var' ) );
		add_filter( 'template_include', array( $this, 'smaily_rss_feed_template_include' ), 100 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ) );
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
	public static function make_rss_feed_url(
		$rss_category = null,
		$rss_limit = null,
		$rss_order_by = null,
		$rss_order = null,
		$tax_rate = null
	) {
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
		if ( isset( $rss_order ) && $rss_order_by !== 'none' ) {
			$parameters['order'] = $rss_order;
		}
		if ( isset( $tax_rate ) ) {
			$parameters['tax_rate'] = $tax_rate;
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
		$vars[] = 'tax_rate';
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
			return SMAILY_CONNECT_PLUGIN_PATH . 'public/template/smaily-rss-feed.php';
		} elseif ( $pagename === 'smaily-rss-feed' ) {
			return SMAILY_CONNECT_PLUGIN_PATH . 'public/template/smaily-rss-feed.php';
		}

		// Load normal template as a fallback.
		return $template;
	}

	/**
	 * Conditionally flush rewrite rules.
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'smaily_connect_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'smaily_connect_flush_rewrite_rules' );
		}
	}

	/**
	 * List store products as RSS feed items.
	 *
	 * @param string $category
	 * @param int $limit
	 * @param string $order_by
	 * @param string $order
	 * @param float|null $tax_rate
	 * @return array{created_at: string, current_price: string, description: string, discount: float, enclosure_url: string, regular_price: string, title: string, url: string}
	 */
	public static function list_rss_feed_items( $category, $limit, $order_by, $order, $tax_rate ) {
		$products = Data_Handler::get_products( $category, $limit, $order_by, $order );
		$items    = array();
		foreach ( $products as $prod ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $prod->get_id() ) : new WC_Product( $prod->get_id() );

			if ( $product === false || $product === null ) {
				continue;
			}

			$current_price = Helper::get_current_price_with_tax( $product, $tax_rate );
			$regular_price = Helper::get_regular_price_with_tax( $product, $tax_rate );
			$url           = get_permalink( $product->get_id() );

			if ( $url === false ) {
				continue;
			}

			$rss_feed_item = array(
				'current_price' => $current_price,
				'regular_price' => $regular_price,
				'discount'      => Helper::calculate_discount( floatval( $current_price ), floatval( $regular_price ) ),
				'url'           => $url,
				'title'         => $product->get_title(),
				'created_at'    => $product->get_date_created()->format( DATE_RFC822 ),
				'enclosure_url' => self::get_product_image_url( $product->get_id() ),
				'description'   => do_shortcode( $product->get_description() ),
			);

			$items[] = $rss_feed_item;
		}

		return $items;
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
