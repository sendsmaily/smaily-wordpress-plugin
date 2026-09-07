<?php

namespace Smaily_Connect\Includes;

defined( 'ABSPATH' ) || exit;

use Smaily_Connect\Includes\Options;

class API {
	/**
	 * API namespace.
	 */
	const NAMESPACE = 'smaily';

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * The ID of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * Sets up a new instance of the API.
	 *
	 * @param Options $options     Reference to options handler class.
	 * @param string  $plugin_name The ID of this plugin.
	 */
	public function __construct( Options $options, string $plugin_name ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
	}

	/**
	 * Registers hooks for the API.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Registers Smaily API endpoints.
	 *
	 * @return void
	 */
	public function register_endpoints() {
		// The newsletter-signup block fills its automation dropdown from here on
		// every mount, so anyone who may edit content must be able to call it —
		// an Editor (the usual marketing role) has no `manage_options` and the
		// block was stuck on its loading spinner for them, with no automation to
		// pick, on a perfectly connected store (PRO-2347). The response carries
		// only the automations' names and ids; the store's Smaily credentials
		// stay server-side, so `edit_posts` is the honest gate.
		$this->register_endpoint(
			'v1',
			'/autoresponders',
			'GET',
			'list_autoresponders',
			array(
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		// The landing-page block reads the account subdomain from here on every
		// mount, so anyone who may edit content must be able to call it — an
		// Editor (the usual marketing role) has no `manage_options` and the
		// block failed silently for them: the fetch 403'd, the subdomain stayed
		// empty and the block showed "Please configure the plugin first" with no
		// URL field, on a perfectly connected store (PRO-2346). The response
		// carries no secret — the subdomain is already public in the signup
		// form's action URL — so `edit_posts` is the honest gate.
		$this->register_endpoint(
			'v1',
			'/configuration',
			'GET',
			'get_configuration',
			array(
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
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
				'settings_url' => admin_url( 'admin.php?page=' . $this->plugin_name ),
			)
		);
	}

	/**
	 * List available autoresponders to be used as block options.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	public function list_autoresponders() {
		$autoresponders = Helper::get_autoresponders_list( $this->options );

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
	 * @param array  $args      Extra arguments for register_rest_route.
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
