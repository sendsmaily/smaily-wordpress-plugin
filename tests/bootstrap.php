<?php
/**
 * PHPUnit bootstrap for Smaily Connect.
 *
 * Order matters: WordPress's defined('ABSPATH') || exit guard at the top of
 * every plugin PHP file means class-files will short-circuit (exit) if they
 * are loaded before ABSPATH is defined. We therefore define ABSPATH *before*
 * requiring the Composer autoloader so PSR-4 lazy-loading sees a sane env
 * even if a test triggers a class load during autoload registration.
 *
 * WordPress core functions used inside the code under test are mocked
 * per-test via Brain\Monkey; the bootstrap deliberately does NOT load a
 * real WordPress test installation. That setup belongs to the integration
 * test suite (added in a later phase once we have WP-CLI scaffolding and
 * a database fixture).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

// 1. Constants that gate plugin-file inclusion go FIRST, before autoload.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_FILE', __DIR__ . '/../smaily-connect.php' );
}
if ( ! defined( 'SMAILY_CONNECT_PLUGIN_PATH' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_PATH', __DIR__ . '/../' );
}
if ( ! defined( 'SMAILY_CONNECT_VERSION' ) ) {
	define( 'SMAILY_CONNECT_VERSION', '3.11.2' );
}

// wpdb output-format constants used by $wpdb->get_row / get_results.
// In production these are defined in wp-includes/load.php long before any
// plugin file loads, but unit tests don't bootstrap WP.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}

// Time constants (wp-includes/default-constants.php) — referenced in class
// constant declarations (e.g. NotificationManager grace/cooldown), so they must
// exist at class-load time even in the WP-less unit suite.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// WP REST infrastructure stubs — the unit suite tests endpoint handlers
// in isolation without standing up wp-includes/rest-api.php. The shims
// expose just enough surface (get_param, set_param, response code +
// data) that our endpoints rely on.
// Legacy Cypher shim — the real class lives in
// includes/smaily-cypher.class.php and isn't autoloaded by Composer
// (only the new Smaily\Connect\* namespace is). Tests that call
// encrypt/decrypt rely on this stub. Production callers always have
// the real class because smaily-connect.php's require_once chain runs
// first.
if ( ! class_exists( \Smaily_Connect\Includes\Cypher::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
namespace Smaily_Connect\Includes;

class Cypher {
	public static $decrypt_calls = array();
	public static $encrypt_calls = array();
	public static $decrypt_return = '';
	public static $encrypt_return = '';

	public static function decrypt( $cyphertext ): string {
		self::$decrypt_calls[] = $cyphertext;
		return self::$decrypt_return;
	}

	public static function encrypt( $password ): string {
		self::$encrypt_calls[] = $password;
		return self::$encrypt_return !== ''
			? self::$encrypt_return
			: 'encrypted:' . $password;
	}
}
PHP
	);
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public array $data = array();
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params = array();
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private int $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}

// 2. Composer autoloader — registers PSR-4 mappings. Actual class files
//    load lazily on first reference; by now ABSPATH is set so the
//    direct-access guards in those files won't short-circuit.
require_once __DIR__ . '/../vendor/autoload.php';
