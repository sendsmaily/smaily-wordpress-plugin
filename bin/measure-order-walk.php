<?php
/**
 * Measurement (CON-236): how long one page of the orders import takes on a
 * large LEGACY order table (`wp_posts`, newest first by `post_date_gmt`, then
 * id — CON-245). Seeds N completed shop_order posts spread over the last 60
 * months straight into the database (no WordPress or WooCommerce hooks fire,
 * so nothing is queued for the engine), walks every page of the import's
 * window with OrderBackfillJob::next_page() — the import's own query, start
 * part and background part — times each page, prints the numbers and the
 * query plan, and deletes the seeded orders again. Sends nothing; reads no
 * customer data (the seeded orders carry none).
 *
 *   npx @wordpress/env run tests-cli wp eval-file \
 *     wp-content/plugins/smaily-connect/bin/measure-order-walk.php 100000
 *
 * The site must be on legacy order storage (wp wc hpos disable). Pass
 * `cleanup` instead of a count to delete orders a crashed run left.
 * Dev tooling only: bin/ is not in the release ZIP (.zipignore).
 *
 * @package Smaily\Connect
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.WP.AlternativeFunctions -- dev tooling: a CLI measurement seeding and removing its own rows.
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;

const SMAILY_MEASURE_ORDER_SLUG = 'smaily-measure-order-';

/** Deletes every order this script seeded. */
function smaily_measure_order_cleanup(): int {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_name LIKE %s", $wpdb->esc_like( SMAILY_MEASURE_ORDER_SLUG ) . '%' ) ) );
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ( " . implode( ',', $chunk ) . ' )' );
	}
	if ( wp_cache_supports( 'flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
	return count( $ids );
}

$arg = isset( $args[0] ) ? (string) $args[0] : '';
if ( $arg === 'cleanup' ) {
	echo 'removed=', smaily_measure_order_cleanup(), "\n";
	return;
}
$count = (int) $arg;
if ( $count < 1 || $count > 500000 ) {
	fwrite( STDERR, "Usage: wp eval-file bin/measure-order-walk.php <1..500000|cleanup>\n" );
	exit( 2 );
}
if ( OrderBackfillJob::uses_hpos() ) {
	fwrite( STDERR, "The site uses HPOS: this measures the legacy walk. Run wp wc hpos disable first.\n" );
	exit( 2 );
}

global $wpdb;
smaily_measure_order_cleanup();

$seed    = microtime( true );
$now     = time();
$history = array(
	'start_months'   => 18,
	'horizon_months' => 60,
	'order'          => 'newest_first',
);
$span    = $now - (int) strtotime( '-60 months', $now );
for ( $start = 1; $start <= $count; $start += 1000 ) {
	$rows = array();
	$end  = min( $count, $start + 999 );
	for ( $i = $start; $i <= $end; $i++ ) {
		// Spread over the window; every 50th shares its second with the
		// one before, as busy stores do (the `date|id` tie-break).
		$at     = $now - 60 - (int) floor( $span * ( $i - ( $i % 50 === 0 ? 1 : 0 ) ) / ( $count + 1 ) );
		$gmt    = gmdate( 'Y-m-d H:i:s', $at );
		$status = $i % 20 === 0 ? 'wc-pending' : ( $i % 15 === 0 ? 'wc-processing' : 'wc-completed' );
		$rows[] = $wpdb->prepare( '(1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $gmt, $gmt, 'Order', '', '', $status, SMAILY_MEASURE_ORDER_SLUG . $i, $gmt, $gmt, 'shop_order', '', '', '' );
	}
	$wpdb->query( "INSERT INTO {$wpdb->posts} ( post_author, post_date, post_date_gmt, post_title, post_content, post_excerpt, post_status, post_name, post_modified, post_modified_gmt, post_type, to_ping, pinged, post_content_filtered ) VALUES " . implode( ',', $rows ) );
}
$seeded = microtime( true ) - $seed;
$wpdb->query( "ANALYZE TABLE {$wpdb->posts}" );

$bootstrap = Bootstrap::instance();
$job       = new class( $bootstrap->ingest_queue(), $bootstrap->order_flusher(), $bootstrap->history_config() ) extends OrderBackfillJob {
	/** @return array{ids: int[], cursor: string, completed: bool} */
	public function page_after( string $cursor ): array {
		return $this->next_page( $cursor, $this->batch_size() );
	}
};

$last_sql = '';
$capture  = static function ( string $query ) use ( &$last_sql ): string {
	if ( strpos( $query, 'ORDER BY post_date_gmt DESC' ) !== false ) {
		$last_sql = $query;
	}
	return $query;
};
add_filter( 'query', $capture );

try {
	$cursor = (string) wp_json_encode( OrderBackfillJob::window( $history, $now ) );
	$times  = array();
	$read   = 0;
	$plan   = array();
	$walk   = microtime( true );
	$guard  = 0;
	do {
		$t       = microtime( true );
		$page    = $job->page_after( $cursor );
		$times[] = ( microtime( true ) - $t ) * 1000;
		$read   += count( $page['ids'] );
		$cursor  = $page['cursor'];
		if ( count( $times ) === 2 && $last_sql !== '' ) {
			$plan = $wpdb->get_results( 'EXPLAIN ' . $last_sql, ARRAY_A );
		}
	} while ( ! $page['completed'] && ++$guard < 20000 );
	$walked = microtime( true ) - $walk;

	sort( $times );
	$pages = count( $times );
	printf(
		"seeded=%d seed_seconds=%.1f read=%d pages=%d walk_seconds=%.1f ms_per_page_avg=%.1f p50=%.1f p95=%.1f max=%.1f mysql=%s\n",
		$count,
		$seeded,
		$read,
		$pages,
		$walked,
		array_sum( $times ) / max( 1, $pages ),
		$times[ (int) floor( $pages * 0.5 ) ] ?? 0,
		$times[ (int) floor( $pages * 0.95 ) ] ?? 0,
		end( $times ),
		(string) $wpdb->db_version()
	);
	foreach ( $plan as $step ) {
		printf( "plan table=%s type=%s key=%s rows=%s extra=%s\n", $step['table'] ?? '', $step['type'] ?? '', $step['key'] ?? '', $step['rows'] ?? '', $step['Extra'] ?? '' );
	}
} finally {
	remove_filter( 'query', $capture );
	echo 'removed=', smaily_measure_order_cleanup(), "\n";
}
