<?php

/**
 * Define the internationalization functionality.
 *
 * @since      1.0.0
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_I18n
{

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since 1.0.0
	 */
	public function load_plugin_textdomain()
	{
		load_plugin_textdomain(
			'smaily',
			false,
			plugin_basename(SMAILY_PLUGIN_PATH) . '/lang/'
		);
	}
}
