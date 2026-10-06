<?php
/**
 * Live walk (PRO-3859): send the nightly §3c catalog manifest ONCE, through the
 * real CatalogManifest::run(), to the dev site's engine connection. It REALLY
 * sends: the engine tombstones that tenant's products missing from the dev
 * store (unless its 20 % guard trips). Aborts unless the connection is the
 * "Beauty Synthetic (live-walk)" sandbox on intelligence.smaily.com. Prints the
 * gates and the Event Log row's numbers only — never the list, keys or tokens.
 *
 *   docker exec wp-env-connect-<hash>-cli-1 wp eval-file \
 *     wp-content/plugins/smaily-connect/bin/walk-pro3859-manifest.php --allow-root
 *
 * Then `bash bin/lib-smly-snapshot.sh snapshot`.
 *
 * @package Smaily\Connect
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dev tooling: a CLI transcript reading the plugin's own queue table.
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\CatalogManifest;

$settings = new RecEngineSettings();
if ( $settings->tenant_name() !== 'Beauty Synthetic (live-walk)' || strpos( $settings->base_url(), 'https://intelligence.smaily.com' ) !== 0 ) {
	fwrite( STDERR, "TENANT_GUARD_ABORT\n" );
	exit( 2 );
}

global $wpdb;
$queue  = $wpdb->prefix . 'smly_rec_event_queue';
$before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$queue}" );
$import = AbstractBackfillJob::read_state( 'products' );
echo 'sending_allowed=', $settings->sending_allowed() ? 1 : 0, ' import=', is_array( $import ) ? $import['status'] : 'none', "\n";

Bootstrap::instance()->catalog_manifest()->run();

$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_error, last_response FROM {$queue} WHERE event_type = %s AND id > %d ORDER BY id DESC LIMIT 1", CatalogManifest::EVENT_TYPE, $before ), ARRAY_A );
if ( ! is_array( $row ) ) {
	exit( "row=none (the night was skipped; see the debug log)\n" );
}
echo 'status=', $row['status'], ' error=', (string) $row['last_error'], "\n";
foreach ( (array) json_decode( (string) $row['last_response'], true ) as $key => $value ) {
	echo $key, '=', wp_json_encode( $value ), "\n";
}
