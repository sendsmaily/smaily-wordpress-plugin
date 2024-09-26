<?php

/**
 * Generates RSS-feed based on url-vars or gets last 50 products updated.
 */

// Get variables from url.
$category  = sanitize_text_field( get_query_var( 'category' ) );
$limit     = (int) sanitize_text_field( get_query_var( 'limit' ) );
$order_by  = sanitize_text_field( get_query_var( 'order_by' ) );
$rss_order = sanitize_text_field( get_query_var( 'order' ) );
// Generate RSS.feed. If no limit provided generates 50 products.
if ( $limit === 0 ) {
	Smaily_WC\Data_Handler::generate_rss_feed( $category, 50, $order_by, $rss_order );
} else {
	Smaily_WC\Data_Handler::generate_rss_feed( $category, $limit, $order_by, $rss_order );
}
