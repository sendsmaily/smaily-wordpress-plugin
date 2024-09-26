<?php

/**
 * Define the internationalization functionality.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_I18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'smaily',
			false,
			plugin_basename( SMAILY_PLUGIN_PATH ) . '/languages/'
		);
	}
}
