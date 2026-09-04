<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Distinct from tests/bootstrap.php (which loads only the Composer
 * autoloader + WP function shims for Brain\Monkey-style unit tests).
 * This file boots a REAL WordPress request lifecycle inside the
 * wp-env container so the integration tests exercise the same code
 * path Erkki sees on staging:
 *
 *   - real $wpdb against the wp-env MariaDB
 *   - real register_rest_route() into the live WP_REST_Server
 *   - real plugin activation hooks
 *   - real action-scheduler + WP-Cron tables
 *
 * Run-mode: this file expects to live inside the wp-env wordpress
 * container at /var/www/html/wp-content/plugins/smaily-connect/...
 * (the .wp-env.json `mappings` field aliases the project directory
 * to that path). The Bash wrapper `bin/run-integration-tests.sh`
 * shells into the container and invokes `vendor/bin/phpunit
 * --testsuite=integration`; running phpunit on the host directly
 * will fail to require wp-load.php and exit early.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	// wp-load.php sets this; we double-define defensively in case a
	// test class loads before wp-load completes.
	define( 'ABSPATH', '/var/www/html/' );
}

// The wp-env container mounts the WP install at /var/www/html. If a
// developer runs phpunit from the host directly (no docker exec), the
// path won't exist — exit early with a clear hint.
$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite(
		STDERR,
		"Integration tests must run inside the wp-env container.\n" .
		"Use the wrapper:  bash bin/run-integration-tests.sh\n" .
		"or:               docker exec wp-env-connect-<hash>-wordpress-1 \\\n" .
		"                    php /var/www/html/wp-content/plugins/smaily-connect/vendor/bin/phpunit \\\n" .
		"                    --configuration /var/www/html/wp-content/plugins/smaily-connect/phpunit.xml.dist \\\n" .
		"                    --testsuite integration\n"
	);
	exit( 1 );
}

// Ensure WP doesn't try to render a request — we're running in CLI.
$_SERVER['SCRIPT_FILENAME'] = $wp_load;
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['REQUEST_URI']     = '/';

require_once $wp_load;

// Activate the plugin if WP hasn't done so yet. Idempotent — WP's
// activate_plugin() is a no-op for an already-active plugin and we
// suppress the redirect side-effect by passing $silent=true.
if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! is_plugin_active( 'smaily-connect/smaily-connect.php' ) ) {
	activate_plugin( 'smaily-connect/smaily-connect.php', '', false, true );
}

// Activation hooks only fire on the activate-click; if a prior
// integration / staging cycle dropped tables (uninstall.php on a
// still-active plugin row, or wp_env destroy + start) we'd be left
// with `active_plugins` set but the schema missing. Forcing the
// migrator here is idempotent (it keys on smly_plus_schema_version)
// and guarantees a sane starting state for every test run.
if ( class_exists( \Smaily\Connect\DB\Migrator::class ) ) {
	( new \Smaily\Connect\DB\Migrator() )->migrate();
}

// Spin up the REST server once so register_rest_route() calls from
// `rest_api_init` fire. After this, rest_get_server()->get_routes()
// returns the full route map every test asserts against.
do_action( 'rest_api_init' );

// Test-support helpers — autoload-friendly path.
require_once __DIR__ . '/Support/EnvScrub.php';
require_once __DIR__ . '/Support/DebugLogReader.php';
require_once __DIR__ . '/Support/RestRequestHelper.php';
require_once __DIR__ . '/Fixtures/RecEngineMockServer.php';
