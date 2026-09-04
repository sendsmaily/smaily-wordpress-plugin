<?php
/**
 * Spins up `php -S` to host the mock rec-engine router for integration tests.
 *
 * @package Smaily\Connect\Tests\Integration\Fixtures
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Fixtures;

defined( 'ABSPATH' ) || exit;

/**
 * Boots tests/Integration/Fixtures/mock-rec-engine/router.php behind
 * a random local port. Returns the base URL the test should hand to
 * SetupExchange / RecEngineEndpoint.
 *
 * Why a real HTTP server rather than monkey-patching wp_remote_post:
 *   - Brain\Monkey can't touch the integration suite (we boot real
 *     WP). wp_remote_post calls go through curl bindings; the only
 *     way to intercept is at the HTTP transport layer.
 *   - A real server exercises everything from header serialisation
 *     to status-code parsing the way production would — catches
 *     classes of bugs that mock-doubles miss.
 *
 * The router uses a `/tmp/smaily-rec-mock-state.json` state file so
 * one-time-use semantics survive across multiple HTTP requests
 * from the same PHPUnit test method. `RecEngineMockServer::reset()`
 * wipes the file between tests.
 */
final class RecEngineMockServer {

	private static ?self $running = null;

	private int $port;

	/** @var resource|null */
	private $process = null;

	private string $base_url;

	private function __construct( int $port ) {
		$this->port     = $port;
		$this->base_url = sprintf( 'http://127.0.0.1:%d', $port );
	}

	public static function start(): self {
		if ( self::$running !== null ) {
			// Reuse the running process across multiple tests in the
			// same phpunit run — boot cost is ~100ms and there's no
			// point paying it per-test.
			self::reset();
			return self::$running;
		}

		$port      = self::find_open_port();
		$instance  = new self( $port );
		$router    = realpath( __DIR__ . '/mock-rec-engine/router.php' );
		if ( $router === false ) {
			throw new \RuntimeException( 'Mock rec-engine router.php missing from Fixtures/.' );
		}

		// Launch php -S in a child process. proc_open lets us capture
		// the PID for shutdown; stdout/stderr go to /dev/null so the
		// router's notices don't bleed into phpunit output.
		$cmd  = sprintf(
			'exec php -S 127.0.0.1:%d %s > /dev/null 2>&1',
			$port,
			escapeshellarg( $router )
		);
		$proc = proc_open( $cmd, array(), $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new \RuntimeException( 'Failed to start mock rec-engine via php -S.' );
		}
		$instance->process = $proc;

		// Wait up to 5s for the port to accept connections.
		$start = microtime( true );
		while ( microtime( true ) - $start < 5 ) {
			$sock = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_resource( $sock ) ) {
				fclose( $sock );
				self::$running = $instance;
				self::reset();
				register_shutdown_function( array( self::class, 'stop' ) );
				return $instance;
			}
			usleep( 100000 );
		}

		// Server failed to come up.
		$instance->terminate();
		throw new \RuntimeException( "Mock rec-engine on port {$port} did not become reachable within 5s." );
	}

	public static function stop(): void {
		if ( self::$running === null ) {
			return;
		}
		self::$running->terminate();
		self::$running = null;
	}

	public static function reset(): void {
		$state_file = sys_get_temp_dir() . '/smaily-rec-mock-state.json';
		if ( file_exists( $state_file ) ) {
			unlink( $state_file );
		}
	}

	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * Read the router's shared state file (the same /tmp file the router
	 * writes during a request). Lets a test assert what the mock engine
	 * actually received — e.g. the per-product event_ids of the last
	 * catalog batch — without the response having to echo them back.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$state_file = sys_get_temp_dir() . '/smaily-rec-mock-state.json';
		if ( ! file_exists( $state_file ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $state_file ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build a setup URL the same shape the engine renders. Tests pass
	 * this string straight to the REST setup-exchange endpoint.
	 */
	public function setup_url( string $token = 'tok_test_abc' ): string {
		return $this->base_url . '/setup/' . $token;
	}

	private function terminate(): void {
		if ( is_resource( $this->process ) ) {
			$status = proc_get_status( $this->process );
			if ( isset( $status['pid'] ) && $status['pid'] > 0 ) {
				// SIGTERM the constant comes from pcntl, which the wp-env CLI
				// PHP doesn't load — fall back to the numeric signal.
				posix_kill( (int) $status['pid'], defined( 'SIGTERM' ) ? SIGTERM : 15 );
			}
			proc_close( $this->process );
			$this->process = null;
		}
	}

	private static function find_open_port(): int {
		$sock = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		if ( ! is_resource( $sock ) ) {
			throw new \RuntimeException( "Couldn't bind a local port for the mock engine: $errstr" );
		}
		$name = stream_socket_get_name( $sock, false );
		fclose( $sock );
		if ( ! is_string( $name ) || ! preg_match( '/:(\d+)$/', $name, $m ) ) {
			throw new \RuntimeException( "Couldn't parse mock-server port from '$name'." );
		}
		return (int) $m[1];
	}
}
