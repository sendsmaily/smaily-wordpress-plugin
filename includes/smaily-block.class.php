<?php

/**
 * Define the Gutenberg newsletter subscription block functionality.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Block
{

	/**
	 * The ID of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Smaily_Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Smaily_Options $options     Reference to options handler class.
	 * @param string                $plugin_name The name of the plugin.
	 * @param string                $version     The version of this plugin.
	 */
	public function __construct(Smaily_Options $options, $plugin_name, $version)
	{
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Render Gutenberg block using the widget shortcode renderer.
	 *
	 * @param array $attributes
	 * @param string $content
	 * @access public
	 */
	public function render($attributes, $content)
	{
		$plugin_public = new Smaily_Public($this->options, $this->plugin_name, $this->version);
		return $plugin_public->smaily_shortcode_render($attributes);
	}
}
