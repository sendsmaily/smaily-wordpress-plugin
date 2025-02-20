<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/public
 */

class Smaily_Public {
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
	public function __construct( Smaily_Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register all shortcodes present in the function.
	 *
	 *
	 */
	public function add_shortcodes() {
		add_shortcode( 'smaily_newsletter_form', array( $this, 'smaily_shortcode_render' ) );
	}

	/**
	 * Render Smaily form using shortcode.
	 *
	 * @param  array $attrs Shortcode attributes.
	 */
	public function smaily_shortcode_render( $attrs ) {
		// Allow overriding the template.
		$template_path = locate_template( 'smaily/smaily-public-basic.php' );
		if ( ! $template_path ) {
			$template_path = SMAILY_PLUGIN_PATH . 'public/partials/smaily-public-basic.php';
		}

		$shortcode_attrs = shortcode_atts(
			array(
				'success_url'      => Smaily_Helper::get_current_url(),
				'failure_url'      => Smaily_Helper::get_current_url(),
				'show_name'        => false,
				'autoresponder_id' => '',
			),
			$attrs
		);

		$autoresponder_id = $shortcode_attrs['autoresponder_id'];
		$failure_url      = $shortcode_attrs['failure_url'];
		$has_credentials  = $this->options->has_credentials();
		$language_code    = Smaily_Helper::get_current_language_code();
		$show_name        = $shortcode_attrs['show_name'];
		$subdomain        = $this->options->get_subdomain();
		$success_url      = $shortcode_attrs['success_url'];

		$this->render_template(
			$template_path,
			compact(
				'autoresponder_id',
				'failure_url',
				'has_credentials',
				'language_code',
				'show_name',
				'subdomain',
				'success_url'
			)
		);
	}

	/**
	 * Renders templates and scopes the variables.
	 *
	 * @param array $parameters Parameters that can be accessed through $parameters array in the template.
	 * @return void
	 */
	public static function render_template( $template_path, $parameters = array() ) {
		if ( ! file_exists( $template_path ) ) {
			throw new Exception( 'Template file not found: ' . esc_attr( $template_path ) );
		}

		include $template_path;
	}
}
