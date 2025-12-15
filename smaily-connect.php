<?php
/*
 * Author URI:        https://smaily.com
 * Author:            Smaily
 * Description:       Smaily integration plugin that includes WooCommerce, Elementor and Contact Form 7 integrations.
 * Domain Path:       /languages
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.en.html
 * License:           GPL-3.0+
 * Requires at least: 6.0
 * Requires PHP:      7.0
 * Plugin Name:       Smaily Connect
 * Plugin URI:        https://smaily.com/help/user-manual/smaily-connect-for-wordpress/
 * Text Domain:       smaily-connect
 * Version:           1.6.0
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current plugin version.
 */
define( 'SMAILY_CONNECT_PLUGIN_VERSION', '1.6.0' );

/**
 * The name of the plugin.
 */
define( 'SMAILY_CONNECT_PLUGIN_NAME', 'smaily-connect' );

/**
 * Absolute URL to the Smaily plugin directory.
 */
define( 'SMAILY_CONNECT_PLUGIN_URL', plugins_url( '', __FILE__ ) );

/**
 * Absolute path to the Smaily plugin directory.
 */
define( 'SMAILY_CONNECT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Absolute path to the core plugin file.
 */
define( 'SMAILY_CONNECT_PLUGIN_FILE', __FILE__ );

// Required to use functions is_plugin_active and deactivate_plugins.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * The plugin lifecycle.
 */
require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-lifecycle.class.php';

/**
 * The core plugin class.
 */
require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily.class.php';

/**
 * Begins execution of the plugin.
 *
 */
if ( class_exists( 'Smaily_Connect' ) ) {
	new Smaily_Connect( SMAILY_CONNECT_PLUGIN_NAME, SMAILY_CONNECT_PLUGIN_VERSION );
}
