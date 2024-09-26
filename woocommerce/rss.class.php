<?php

namespace Smaily_WC;

/**
 * Handles RSS generation for Smaily newsletter
 */
class Rss {


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
}
