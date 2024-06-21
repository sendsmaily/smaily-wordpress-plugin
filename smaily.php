<?php
/*
 * Plugin Name:       Smaily
 * Text Domain:       smaily
 * Description:       Smaily integration plugin that includes WooCommerce and Contact Form 7 implementations.
 * Version:           1.0.0
 * Author:            Sendsmaily LLC
 * Author URI:        https://smaily.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Current plugin version.
 */
define('SMAILY_PLUGIN_VERSION', '1.0.0');

/**
 * Absolute URL to the Smaily plugin directory.
 */
define('SMAILY_PLUGIN_URL', plugins_url('', __FILE__));

/**
 * Absolute path to the Smaily plugin directory.
 */
define('SMAILY_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Absolute path to the core plugin file.
 */
define('SMAILY_PLUGIN_FILE', __FILE__);

// Required to use functions is_plugin_active and deactivate_plugins.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * The core plugin class.
 */
require SMAILY_PLUGIN_PATH . 'includes/smaily-lifecycle.class.php';

/**
 * The core plugin class.
 */
require SMAILY_PLUGIN_PATH . 'includes/smaily.class.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 */
if (!function_exists('run_smaily')) {
    function run_smaily()
    {
        new Smaily();
    }
    run_smaily();
}
