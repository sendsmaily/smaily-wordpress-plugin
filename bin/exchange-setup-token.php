<?php
/**
 * Exchanges a rec-engine setup token (or full setup URL) piped over STDIN and
 * stores the resulting connection — the CC.3 secret-safe mechanic from CLAUDE.md.
 *
 * Run INSIDE the wp-env dev cli container via `wp eval-file`, with the token on
 * STDIN so it never reaches a command line, a process arg or the shell history:
 *
 *   cat /tmp/smaily_re_setup_token | \
 *     sg docker -c "docker exec -i wp-env-connect-<hash>-cli-1 \
 *       wp eval-file wp-content/plugins/smaily-connect/bin/exchange-setup-token.php --allow-root"
 *
 * Accepts either a full setup URL (`https://<engine>/setup/<token>`) or a bare
 * token — a bare token reuses the currently stored engine base URL, because the
 * token alone carries no host.
 *
 * The setup token is ONE-TIME (contract §7.1): a successful exchange consumes it
 * engine-side, so delete the temp file afterwards and mint a new one for the
 * next run. Mint it on the "Smaily Connect test" SANDBOX tenant — never on the
 * production tenant MiuMjau (CLAUDE.md "Live-walk needs a fresh setup-token").
 *
 * Prints ONLY non-secret fields (kind, tenant_name, connected, base URL host).
 * Never the token and never the API key.
 *
 * @package Smaily\Connect
 */

// wp eval-file runs inside a booted WordPress; ABSPATH is defined. The guard
// keeps a stray web-request include from doing anything.
defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Bootstrap;
use Smaily\Connect\Smaily\RecEngine\ExchangeResult;
use Smaily\Connect\Smaily\RecEngine\SetupExchange;

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$raw = (string) fgets( STDIN );
$raw = trim( $raw );
if ( '' === $raw ) {
	fwrite( STDERR, "exchange-setup-token: nothing on STDIN — pipe the setup token or URL in.\n" );
	exit( 1 );
}

$parsed   = SetupExchange::parse_setup_url( $raw );
$settings = Bootstrap::instance()->rec_engine_settings();
$base     = '' !== $parsed['base'] ? $parsed['base'] : $settings->base_url();
unset( $raw );

if ( '' === $parsed['token'] ) {
	fwrite( STDERR, "exchange-setup-token: input is not a recognisable setup URL or token.\n" );
	exit( 1 );
}
if ( '' === $base ) {
	fwrite( STDERR, "exchange-setup-token: a bare token needs a stored engine base URL — paste the full setup URL instead.\n" );
	exit( 1 );
}

$result = ( new SetupExchange() )->exchange( $parsed['token'], $base );
unset( $parsed );

echo 'kind=' . esc_html( $result->kind ) . "\n";

if ( ExchangeResult::KIND_SUCCESS !== $result->kind ) {
	// reason / regenerate_url are engine-authored, non-secret diagnostics.
	if ( '' !== $result->reason ) {
		echo 'reason=' . esc_html( $result->reason ) . "\n";
	}
	if ( '' !== $result->regenerate_url ) {
		echo 'regenerate_url=' . esc_html( $result->regenerate_url ) . "\n";
	}
	echo "connected=0\n";
	exit( 1 );
}

$settings->store( $result );

$host = (string) wp_parse_url( $settings->base_url(), PHP_URL_HOST );

echo 'tenant_name=' . esc_html( $settings->tenant_name() ) . "\n";
echo 'engine_host=' . esc_html( $host ) . "\n";
echo 'engine_version=' . esc_html( $settings->engine_version() ) . "\n";
echo 'connected=' . esc_html( $settings->is_connected() ? '1' : '0' ) . "\n";

// Loud, machine-greppable guard: this script must never be used to point the
// dev environment at the production tenant.
if ( 'MiuMjau' === $settings->tenant_name() ) {
	echo "PRODUCTION_TENANT_ABORT\n";
	exit( 2 );
}
