<?php
/**
 * PHPStan bootstrap — defines plugin constants that smaily-connect.php
 * sets at runtime but that the static analyser can't resolve through
 * its file-isolated walk.
 *
 * These constants are NEVER actually evaluated; PHPStan reads the
 * `define()` calls below to populate its symbol table so references
 * elsewhere in the code base type-check cleanly.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

if ( ! defined( 'SMAILY_CONNECT_PLUGIN_PATH' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_PATH', __DIR__ . '/../' );
}
if ( ! defined( 'SMAILY_CONNECT_PLUGIN_FILE' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_FILE', __DIR__ . '/../smaily-connect.php' );
}
if ( ! defined( 'SMAILY_CONNECT_VERSION' ) ) {
	define( 'SMAILY_CONNECT_VERSION', '3.11.2' );
}
