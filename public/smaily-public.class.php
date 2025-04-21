<?php

namespace Smaily_Connect;

use Exception;
use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Includes\Options;

class Public_Base {
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
	 * @var    Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Options $options     Reference to options handler class.
	 * @param string                $plugin_name The name of the plugin.
	 * @param string                $version     The version of this plugin.
	 */
	public function __construct( Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register hooks for the public-facing functionality of the plugin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'add_shortcodes' ) );
	}

	/**
	 * Register all shortcodes present in the function.
	 *
	 *
	 */
	public function add_shortcodes() {
		add_shortcode( 'smaily_connect_newsletter_form', array( $this, 'smaily_shortcode_render' ) );
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
			$template_path = SMAILY_CONNECT_PLUGIN_PATH . 'public/partials/smaily-public-basic.php';
		}

		$shortcode_attrs = shortcode_atts(
			array(
				'success_url'      => Helper::get_current_url(),
				'failure_url'      => Helper::get_current_url(),
				'show_name'        => false,
				'autoresponder_id' => '',
			),
			$attrs
		);

		$autoresponder_id = $shortcode_attrs['autoresponder_id'];
		$failure_url      = $shortcode_attrs['failure_url'];
		$has_credentials  = $this->options->has_credentials();
		$language_code    = Helper::get_current_language_code();
		$show_name        = $shortcode_attrs['show_name'];
		$subdomain        = $this->options->get_subdomain();
		$success_url      = $shortcode_attrs['success_url'];

		ob_start();
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
		return ob_get_clean();
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
