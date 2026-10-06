<?php
/**
 * Asks the engine whether it still accepts the dev site's stored connection
 * (PRO-3888) — one read-only ping (contract §7.2) through the plugin's own
 * rec-engine Client.
 *
 * `bin/lib-smly-snapshot.sh` pipes this file over STDIN into the wp-env dev
 * cli container before it saves the durable snapshot, so an API key the
 * engine refuses never replaces the last good snapshot:
 *
 *   docker exec -i wp-env-connect-<hash>-cli-1 wp eval-file - --allow-root \
 *     < bin/probe-smly-rec-connection.php
 *
 * Prints ONE line: `probe=ok`, `probe=refused http=<status>` (401/403) or
 * `probe=unreachable http=<status>` (0 = no answer). Never the API key.
 *
 * @package Smaily\Connect
 */

// wp eval-file runs inside a booted WordPress; ABSPATH is defined. The guard
// keeps a stray web-request include from doing anything.
defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Bootstrap;

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

try {
	// One attempt, 10 s: the guard must not hold the guarded run for long.
	Bootstrap::instance()->rec_client( 1, 10 )->ping();
	echo "probe=ok\n";
} catch ( \Throwable $e ) {
	$status = (int) $e->getCode();
	$word   = in_array( $status, array( 401, 403 ), true ) ? 'refused' : 'unreachable';
	echo 'probe=' . esc_html( $word ) . ' http=' . (int) $status . "\n";
}
