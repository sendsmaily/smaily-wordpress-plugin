<?php

use Smaily_Connect\Integrations\WooCommerce\Rss;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$category       = sanitize_text_field( get_query_var( 'category' ) );
$limit          = (int) sanitize_text_field( get_query_var( 'limit' ) );
$order_by       = sanitize_text_field( get_query_var( 'order_by' ) );
$rss_order      = sanitize_text_field( get_query_var( 'order' ) );
$tax_rate       = sanitize_text_field( get_query_var( 'tax_rate' ) );
$tax_rate       = $tax_rate !== '' ? (float) $tax_rate : null;
$currencysymbol = get_woocommerce_currency_symbol();

// Default to 50 products.
$limit = $limit === 0 ? 50 : $limit;
$items = Rss::list_rss_feed_items( $category, $limit, $order_by, $rss_order, $tax_rate );

header( 'Content-Type: application/xml' );
?>

<rss xmlns:smly="https://sendsmaily.net/schema/editor/rss.xsd" version="2.0">
	<channel>
		<title><![CDATA[Store]]></title>
		<link><![CDATA[<?php echo esc_url( get_site_url() ); ?>]]></link>
		<description><![CDATA[Product Feed]]></description>
		<lastBuildDate><![CDATA[<?php echo esc_html( wp_date( 'D, d M Y H:i:s' ) ); ?>]]></lastBuildDate>
		<?php foreach ( $items as $item ) : ?>
			<item>
				<title><![CDATA[<?php echo esc_html( $item['title'] ); ?>]]></title>
				<link><![CDATA[<?php echo esc_url( $item['url'] ); ?>]]></link>
				<guid isPermaLink="True"><![CDATA[<?php echo esc_url( $item['url'] ); ?>]]></guid>
				<pubDate><![CDATA[<?php echo esc_html( $item['created_at'] ); ?>]]></pubDate>
				<description><![CDATA[<?php echo esc_html( $item['description'] ); ?>]]></description>
				<enclosure url="<?php echo esc_html( $item['enclosure_url'] ); ?>"/>
				<smly:price><![CDATA[<?php echo esc_html( number_format( floatval( $item['current_price'] ), 2, '.', ',' ) . html_entity_decode( $currencysymbol, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ); ?>]]></smly:price>
				<?php if ( $item['discount'] > 0 ) : ?>
					<smly:old_price><![CDATA[<?php echo esc_html( number_format( floatval( $item['regular_price'] ), 2, '.', ',' ) . html_entity_decode( $currencysymbol, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ); ?>]]></smly:old_price>
					<smly:discount><![CDATA[-<?php echo esc_html( $item['discount'] ); ?>%]]></smly:discount>
				<?php endif ?>
			</item>
		<?php endforeach ?>
	</channel>
</rss>
