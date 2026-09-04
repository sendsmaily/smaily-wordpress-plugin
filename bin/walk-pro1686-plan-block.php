<?php
/**
 * Live walk (PRO-1686): what a merchant is told when the Smaily account's
 * package does not include the API.
 *
 * Runs the REAL plugin path — `Smaily\Client::check_connection()`, the REAL
 * `NotificationManager::run_health_check()`, and the REAL admin-notice render
 * — against the LIVE Smaily API, and prints the merchant-visible sentence.
 * Read-only: the only request it makes is the workflow listing the connection
 * check already uses. Nothing is sent, no contact is written.
 *
 * Run INSIDE the wp-env dev cli container via `wp eval-file`, with the
 * credentials piped over STDIN as JSON so they never reach a command line
 * (the CLAUDE.md secret-safe mechanic):
 *
 *   echo '{"subdomain":"…","username":"…","password":"…"}' | \
 *     sg docker -c "docker exec -i wp-env-connect-<hash>-cli-1 \
 *       wp eval-file wp-content/plugins/smaily-connect/bin/walk-pro1686-plan-block.php --allow-root"
 *
 * Prints ONLY the classification and the rendered notice — never the
 * credentials. It restores the health-check options it touched, so running it
 * does not leave the dev site wearing a notice about someone else's account.
 *
 * @package Smaily\Connect
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- dev tooling: the output is a CLI transcript, never a browser response.

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\REST\TestConnectionEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;

$raw   = stream_get_contents( STDIN );
$creds = json_decode( (string) $raw, true );

if ( ! is_array( $creds )
	|| ! isset( $creds['subdomain'], $creds['username'], $creds['password'] )
) {
	echo "ERROR: expected {subdomain, username, password} JSON on STDIN\n";
	exit( 1 );
}

$client = new SmailyClient(
	(string) $creds['subdomain'],
	(string) $creds['username'],
	(string) $creds['password']
);

echo 'connection_check: ' . $client->check_connection() . "\n";

// The Test connection button (wizard step 1 / Settings → Connection), through
// the real REST handler.
$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/test-smaily' );
$request->set_param( 'subdomain', (string) $creds['subdomain'] );
$request->set_param( 'username', (string) $creds['username'] );
$request->set_param( 'password', (string) $creds['password'] );
$test = ( new TestConnectionEndpoint() )->handle( $request )->get_data();
echo 'test_connection: connected=' . ( $test['connected'] ? 'true' : 'false' ) . ' error=' . (string) $test['error'] . "\n";

// The health check, exactly as the recurring tick runs it.
$before_notices   = get_option( NotificationManager::OPTION_NOTICES, array() );
$before_downsince = get_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE, false );

$manager = new NotificationManager(
	new RecEngineSettings(),
	static fn (): RecEngineClient => new RecEngineClient( 'sk_unused', 'https://unused.test' ),
	static fn (): ?SmailyClient => $client
);
$manager->run_health_check();

$notices = get_option( NotificationManager::OPTION_NOTICES, array() );
echo 'notice_keys: ' . implode( ',', array_keys( is_array( $notices ) ? $notices : array() ) ) . "\n";

// The sentence the merchant actually reads, straight out of admin_notices.
wp_set_current_user( 1 );
ob_start();
$manager->render();
$html = (string) ob_get_clean();
echo 'rendered: ' . trim( wp_strip_all_tags( $html ) ) . "\n";

// Leave the dev site as we found it.
update_option( NotificationManager::OPTION_NOTICES, $before_notices, false );
if ( false === $before_downsince ) {
	delete_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE );
} else {
	update_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE, $before_downsince, false );
}
echo "restored dev-site health options\n";
