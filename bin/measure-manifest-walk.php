<?php
/**
 * Measurement (PRO-3989): how long the nightly product list's catalog walk
 * takes on a large catalog. Seeds N published simple products straight into
 * the database (no WordPress or WooCommerce hooks fire, so nothing is queued
 * for the engine), times CatalogBackfillJob::manifest_items() and the JSON
 * encode of the request body, prints the numbers, and deletes the seeded
 * products again. Sends nothing.
 *
 *   npx @wordpress/env run tests-cli wp eval-file \
 *     wp-content/plugins/smaily-connect/bin/measure-manifest-walk.php 10000
 *
 * Pass `cleanup` instead of a count to delete products a crashed run left.
 * Dev tooling only: bin/ is not in the release ZIP (.zipignore).
 *
 * @package Smaily\Connect
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.WP.AlternativeFunctions -- dev tooling: a CLI measurement seeding and removing its own rows.
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;

const SMAILY_MEASURE_SLUG = 'smaily-measure-';

/**
 * Deletes every product this script seeded, with its meta and term links.
 */
function smaily_measure_cleanup(): int {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_name LIKE %s", $wpdb->esc_like( SMAILY_MEASURE_SLUG ) . '%' ) ) );
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$in = implode( ',', $chunk );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( {$in} )" );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ( {$in} )" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ( {$in} )" );
	}
	if ( wp_cache_supports( 'flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
	return count( $ids );
}

$arg = isset( $args[0] ) ? (string) $args[0] : '';
if ( $arg === 'cleanup' ) {
	echo 'removed=', smaily_measure_cleanup(), "\n";
	return;
}
$count = (int) $arg;
if ( $count < 1 || $count > CatalogManifest::MAX_PRODUCTS ) {
	fwrite( STDERR, 'Usage: wp eval-file bin/measure-manifest-walk.php <1..' . CatalogManifest::MAX_PRODUCTS . "|cleanup>\n" );
	exit( 2 );
}

global $wpdb;
smaily_measure_cleanup();

$simple = get_term_by( 'slug', 'simple', 'product_type' );
$now    = current_time( 'mysql' );
$gmt    = current_time( 'mysql', true );
$seed   = microtime( true );

for ( $start = 1; $start <= $count; $start += 1000 ) {
	$rows = array();
	$end  = min( $count, $start + 999 );
	for ( $i = $start; $i <= $end; $i++ ) {
		$rows[] = $wpdb->prepare( '(1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $now, $gmt, 'Measure product ' . $i, '', '', 'publish', SMAILY_MEASURE_SLUG . $i, $now, $gmt, 'product', '', '', '' );
	}
	$wpdb->query( "INSERT INTO {$wpdb->posts} ( post_author, post_date, post_date_gmt, post_title, post_content, post_excerpt, post_status, post_name, post_modified, post_modified_gmt, post_type, to_ping, pinged, post_content_filtered ) VALUES " . implode( ',', $rows ) );
}

$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_name LIKE %s", $wpdb->esc_like( SMAILY_MEASURE_SLUG ) . '%' ) ) );
foreach ( array_chunk( $ids, 500 ) as $chunk ) {
	$meta  = array();
	$terms = array();
	foreach ( $chunk as $index => $id ) {
		$price = (string) ( 10 + $index % 90 );
		foreach ( array(
			'_price'         => $price,
			'_regular_price' => $price,
			'_stock_status'  => $index % 10 === 0 ? 'outofstock' : 'instock',
			'_manage_stock'  => 'no',
		) as $key => $value ) {
			$meta[] = $wpdb->prepare( '(%d, %s, %s)', $id, $key, $value );
		}
		if ( $simple instanceof WP_Term ) {
			$terms[] = $wpdb->prepare( '(%d, %d)', $id, $simple->term_taxonomy_id );
		}
	}
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ',', $meta ) );
	if ( $terms !== array() ) {
		$wpdb->query( "INSERT INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id ) VALUES " . implode( ',', $terms ) );
	}
}
$seeded = microtime( true ) - $seed;

if ( wp_cache_supports( 'flush_runtime' ) ) {
	wp_cache_flush_runtime();
}
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$memory = memory_get_usage();

try {
	$walk  = microtime( true );
	$items = Bootstrap::instance()->catalog_backfill_job()->manifest_items( CatalogManifest::MAX_PRODUCTS );
	$built = microtime( true ) - $walk;

	$encode = microtime( true );
	$body   = (string) wp_json_encode( array( 'products' => $items ) );
	$coded  = microtime( true ) - $encode;

	printf(
		"seeded=%d seed_seconds=%.1f items=%d walk_seconds=%.2f encode_seconds=%.3f body_bytes=%d peak_extra_mb=%.1f php=%s max_execution_time=%s memory_limit=%s\n",
		count( $ids ),
		$seeded,
		count( $items ),
		$built,
		$coded,
		strlen( $body ),
		( memory_get_peak_usage() - $memory ) / 1048576,
		PHP_VERSION,
		(string) ini_get( 'max_execution_time' ),
		(string) ini_get( 'memory_limit' )
	);
} finally {
	echo 'removed=', smaily_measure_cleanup(), "\n";
}
