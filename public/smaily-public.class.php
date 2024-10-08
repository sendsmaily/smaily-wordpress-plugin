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
	 * @param  array $atts Shortcode attributes.
	 * @return string
	 */
	public function smaily_shortcode_render( $atts ) {
		// Load configuration data.
		$api_credentials = $this->options->get_api_credentials();
		$settings        = $this->options->get_settings();
		// Data to be assigned to template.
		$config                = array();
		$config['domain']      = $api_credentials['subdomain'];
		$config['form']        = $settings['form'];
		$config['is_advanced'] = $settings['is_advanced'];

		// Parse attributes out of shortcode tag.
		$shortcode_atts             = shortcode_atts(
			array(
				'success_url'      => get_site_url(),
				'failure_url'      => get_site_url(),
				'show_name'        => false,
				'autoresponder_id' => '',
			),
			$atts
		);
		$config['success_url']      = $shortcode_atts['success_url'];
		$config['failure_url']      = $shortcode_atts['failure_url'];
		$config['show_name']        = $shortcode_atts['show_name'];
		$config['autoresponder_id'] = $shortcode_atts['autoresponder_id'];

		// Create admin template.
		$file     = $config['is_advanced'] === true ? 'advanced.php' : 'basic.php';
		$template = new Smaily_Template( 'public/partials/smaily-public-' . $file );
		$template->assign( $config );
		// Display responses on Smaily subscription form.
		$form_has_response  = false;
		$form_is_successful = false;
		$response_message   = null;

		$credentials_not_valid = empty( $api_credentials['subdomain'] ) || empty( $api_credentials['username'] ) || empty( $api_credentials['password'] );
		if ( $credentials_not_valid ) {
			$form_has_response = true;
			$response_message  = __( 'Smaily credentials not validated. Subscription form will not work!', 'smaily' );
		} elseif ( isset( $_GET['code'] ) && (int) $_GET['code'] === 101 ) { // phpcs:ignore WordPress.Security.NonceVerification
			$form_is_successful = true;
		} elseif ( isset( $_GET['code'] ) || ! empty( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$form_has_response = true;
			switch ( (int) $_GET['code'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				case 201:
					$response_message = __( 'Form was not submitted using POST method.', 'smaily' );
					break;
				case 204:
					$response_message = __( 'Input does not contain a recognizable email address.', 'smaily' );
					break;
				default:
					$response_message = __( 'Could not add to subscriber list for an unknown reason. Probably something in Smaily.', 'smaily' );
					break;
			}
		}

		$template->assign(
			array(
				'form_has_response'  => $form_has_response,
				'response_message'   => $response_message,
				'form_is_successful' => $form_is_successful,
			)
		);
		// Render template.
		return $template->render();
	}
}
