<?php
/*
 * Author URI:        https://smaily.com
 * Author:            Sendsmaily LLC
 * Description:       Smaily integration plugin that includes WooCommerce and Contact Form 7 integrations.
 * Domain Path:       /languages
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.en.html
 * License:           GPL-3.0+
 * Plugin Name:       Smaily WP Connect
 * Plugin URI:        https://github.com/sendsmaily/smaily-wordpress-plugin
 * Text Domain:       smaily
 * Version:           1.0.0
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current plugin version.
 */
define( 'SMAILY_WP_CONNECT_PLUGIN_VERSION', '1.0.0' );

/**
 * Absolute URL to the Smaily plugin directory.
 */
define( 'SMAILY_WP_CONNECT_PLUGIN_URL', plugins_url( '', __FILE__ ) );

/**
 * Absolute path to the Smaily plugin directory.
 */
define( 'SMAILY_WP_CONNECT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Absolute path to the core plugin file.
 */
define( 'SMAILY_WP_CONNECT_PLUGIN_FILE', __FILE__ );

// Required to use functions is_plugin_active and deactivate_plugins.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * The plugin lifecycle.
 */

require_once SMAILY_WP_CONNECT_PLUGIN_PATH . 'includes/smaily-lifecycle.class.php';

/**
 * The core plugin class.
 */
require_once SMAILY_WP_CONNECT_PLUGIN_PATH . 'includes/smaily.class.php';

/**
 * Begins execution of the plugin.
 *
 */
if ( class_exists( 'Smaily_WP_Connect' ) ) {
	new Smaily_WP_Connect();
}
