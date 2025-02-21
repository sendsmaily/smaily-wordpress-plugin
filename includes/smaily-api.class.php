<?php
/**
 * Defines the API functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

use Smaily_Admin\Admin;

class Smaily_API {
	/**
	 * API namespace.
	 */
	const NAMESPACE = 'smaily';

	/**
	 * Admin model.
	 *
	 *
	 * @access private
	 * @var    Admin
	 */
	private $admin_model;

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Smaily_Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * Sets up a new instance of the API.
	 *
	 * @param Smaily_Options $options     Reference to options handler class.
	 * @param Admin   $admin_model Reference to admin class.
	 */
	public function __construct( Smaily_Options $options, Admin $admin_model ) {
		$this->options     = $options;
		$this->admin_model = $admin_model;
	}

	/**
	 * Registers Smaily API endpoints.
	 *
	 * @return void
	 */
	public function register_endpoints() {
		$this->register_endpoint( 'v1', '/autoresponders', 'GET', 'list_autoresponders' );
		$this->register_endpoint( 'v1', '/configuration', 'GET', 'get_configuration' );
	}

	/**
	 * Get plugin configuration.
	 *
	 * @return array
	 */
	public function get_configuration() {
		$configuration = array(
			'subdomain'    => '',
			'settings_url' => '',
		);

		$credentials = $this->options->get_api_credentials();

		return array_merge(
			$configuration,
			array(
				'subdomain'    => $credentials['subdomain'],
				'settings_url' => admin_url( 'admin.php?page=smaily-settings' ),
			)
		);
	}

	/**
	 * List available autoresponders to be used as block options.
	 *
	 * @return array{label: string, value: string}
	 */
	public function list_autoresponders() {
		$autoresponders = Admin::get_autoresponders( $this->options );

		$response = array();
		foreach ( $autoresponders as $id => $title ) {
			$response[] = array(
				'value' => strval( $id ),
				'label' => $title,
			);
		}

		return $response;
	}

	/**
	 * Registers endpoint under smaily namespace providing default arguments.
	 * Ref: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints
	 *
	 * @param string $version  Version of the endpoint.
	 * @param string $path     Endpoint URL path.
	 * @param string $methods  Methods accepted by the endpoint.
	 * @param string $callback Function name for the endpoint logic.
	 * @param array $args      Extra arguments for register_rest_route.
	 */
	private function register_endpoint( $version, $path, $methods, $callback, $args = array() ) {
		$defaults = array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		);

		register_rest_route(
			sprintf( '%s/%s', self::NAMESPACE, $version ),
			$path,
			array_merge(
				$defaults,
				$args
			)
		);
	}
}
