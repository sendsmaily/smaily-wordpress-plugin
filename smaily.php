<?php
/*
 * Plugin Name:       Smaily
 * Text Domain:       smaily
 * Description:       Smaily integration plugin that includes WooCommerce and Contact Form 7 integrations.
 * Version:           1.0.0
 * Author:            Sendsmaily LLC
 * Author URI:        https://smaily.com
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.en.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Current plugin version.
 */
define( 'SMAILY_PLUGIN_VERSION', '1.0.0' );

/**
 * Absolute URL to the Smaily plugin directory.
 */
define( 'SMAILY_PLUGIN_URL', plugins_url( '', __FILE__ ) );

/**
 * Absolute path to the Smaily plugin directory.
 */
define( 'SMAILY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Absolute path to the core plugin file.
 */
define( 'SMAILY_PLUGIN_FILE', __FILE__ );

// Required to use functions is_plugin_active and deactivate_plugins.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * The plugin lifecycle.
 */

require_once SMAILY_PLUGIN_PATH . 'includes/smaily-lifecycle.class.php';

/**
 * The core plugin class.
 */
require_once SMAILY_PLUGIN_PATH . 'includes/smaily.class.php';

/**
 * Begins execution of the plugin.
 *
 */
if ( class_exists( 'Smaily' ) ) {
	new Smaily();
}
