<?php
/**
 * Restores the dev site's `smly_rec_*` connection options from a snapshot
 * piped over STDIN (PRO-1240).
 *
 * Run INSIDE the wp-env dev cli container via `wp eval-file` with the
 * snapshot JSON on STDIN — the secret-safe mechanic from CLAUDE.md: the
 * options JSON (which contains the encrypted API key) never appears on a
 * command line or in process args:
 *
 *   cat ~/.local/state/smaily-connect/smly_rec_snapshot.json | \
 *     sg docker -c "docker exec -i wp-env-connect-<hash>-cli-1 \
 *       wp eval-file wp-content/plugins/smaily-connect/bin/restore-smly-rec-options.php --allow-root"
 *
 * Snapshot format: the output of
 *   wp option list --search='smly_rec_*' --fields=option_name,option_value,autoload --format=json
 *
 * Restores EXACTLY the snapshot state: every snapshot row is written back
 * (delete + add, preserving the autoload flag), and any `smly_rec_*` option
 * present in the DB but absent from the snapshot (e.g. fixture residue the
 * integration suite wrote) is deleted.
 *
 * Prints ONLY non-secret verification fields (tenant_name, base URL,
 * connected flag, row count). Never the API key. `bin/run-integration-tests.sh`
 * parses the tenant_name line to verify the restored connection.
 *
 * @package Smaily\Connect
 */

// wp eval-file runs inside a booted WordPress; ABSPATH is defined. The
// guard keeps a stray web-request include from doing anything.
defined( 'ABSPATH' ) || exit;

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$raw  = stream_get_contents( STDIN );
$rows = json_decode( (string) $raw, true );
if ( ! is_array( $rows ) || array() === $rows ) {
	fwrite( STDERR, "restore-smly-rec-options: no snapshot rows on STDIN — nothing restored.\n" );
	exit( 1 );
}

$snapshot_names = array();
$restored       = 0;

foreach ( $rows as $row ) {
	if ( ! is_array( $row ) || ! isset( $row['option_name'] ) || ! array_key_exists( 'option_value', $row ) ) {
		continue;
	}
	$name = (string) $row['option_name'];
	if ( 0 !== strpos( $name, 'smly_rec_' ) ) {
		// Never write anything outside the smly_rec_* namespace, whatever
		// the piped file claims.
		continue;
	}
	$snapshot_names[] = $name;

	// `wp option list` emits the RAW DB string; a serialized value passed
	// to add_option() would be double-serialized (maybe_serialize guards
	// against re-serializing), so round-trip through maybe_unserialize.
	$value = maybe_unserialize( (string) $row['option_value'] );

	// WP >= 6.6 reports autoload as on/off/auto-on/auto-off; older as yes/no.
	$autoload_raw = isset( $row['autoload'] ) ? (string) $row['autoload'] : 'no';
	$autoload     = in_array( $autoload_raw, array( 'yes', 'on', 'auto-on', '1', 'true' ), true );

	// delete + add (not update_option) so the autoload flag is restored too.
	delete_option( $name );
	add_option( $name, $value, '', $autoload );
	++$restored;
}

// Delete smly_rec_* options the suite (or a fixture) created that were NOT
// in the snapshot — restore means the exact pre-suite option set.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off dev-tooling sweep of the options table.
$current = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'smly\\_rec\\_%'" );
$deleted = 0;
foreach ( (array) $current as $name ) {
	if ( ! in_array( (string) $name, $snapshot_names, true ) ) {
		delete_option( (string) $name );
		++$deleted;
	}
}
wp_cache_flush();

// Non-secret verification output only — NEVER the API key. CLI stdout for
// the wrapper to parse, not a browser context, so HTML-escaping does not
// apply — but satisfy the sniff anyway (esc_html is a no-op on these values).
echo 'restored_options=' . (int) $restored . "\n";
echo 'deleted_extras=' . (int) $deleted . "\n";
echo 'tenant_name=' . esc_html( (string) get_option( 'smly_rec_tenant_name', '' ) ) . "\n";
echo 'engine_base_url=' . esc_html( (string) get_option( 'smly_rec_engine_base_url', '' ) ) . "\n";
echo 'connected=' . ( get_option( 'smly_rec_connected' ) ? '1' : '0' ) . "\n";
