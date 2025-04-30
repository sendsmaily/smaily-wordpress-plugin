<?php

namespace Smaily_Connect\Includes;

use Smaily_Connect\Blocks\Checkout_Optin\Extend_Store_Endpoint;
use Smaily_Connect\Blocks\Checkout_Optin\Integration;

class Blocks {
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
	 * Register hooks for the block functionality of the plugin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_newsletter_signup_block' ) );
		add_action( 'init', array( $this, 'register_landingpage_block' ) );

		if ( Helper::is_woocommerce_active() ) {
			add_action( 'init', array( $this, 'register_checkout_optin_block' ) );
			add_filter( '__experimental_woocommerce_blocks_add_data_attributes_to_block', array( $this, 'add_smaily_checkout_to_allowed_blocks' ) );
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_checkout_block_for_woocommerce' ) );
		}
	}

	/**
	 * Register newsletter signup block.
	 *
	 * @return void
	 */
	public function register_newsletter_signup_block() {
		wp_enqueue_style( 'wp-components' );

		register_block_type(
			SMAILY_CONNECT_PLUGIN_PATH . '/blocks/newsletter-signup/build',
			array(
				'render_callback' => array( 'Smaily_Connect\Blocks\Newsletter_Signup\Integration', 'render' ),
			)
		);
		wp_set_script_translations(
			'smaily-newsletter-block-editor-script',
			'smaily-connect',
			SMAILY_CONNECT_PLUGIN_PATH . 'languages'
		);
	}

	/**
	 * Register checkout optin block.
	 *
	 * @return void
	 */
	public function register_checkout_optin_block() {
		register_block_type( SMAILY_CONNECT_PLUGIN_PATH . '/blocks/checkout-optin/build' );
		wp_set_script_translations(
			'smaily-checkout-optin-editor-script',
			'smaily-connect',
			SMAILY_CONNECT_PLUGIN_PATH . 'languages'
		);
	}

	/**
	 * Add Smaily checkout optin block to allowed blocks.
	 *
	 * @param array $allowed_blocks Allowed blocks.
	 * @return array
	 */
	public function add_smaily_checkout_to_allowed_blocks( $allowed_blocks ) {
		$allowed_blocks[] = 'smaily/checkout-optin';
		return $allowed_blocks;
	}

	/**
	 * Register checkout block for WooCommerce.
	 *
	 * @return void
	 */
	public function register_checkout_block_for_woocommerce() {
		// Load after WooCommerce blocks are loaded.
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'blocks/checkout-optin/smaily-extend-store-endpoint.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'blocks/checkout-optin/smaily-integration.class.php';

		Extend_Store_Endpoint::init();

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new Integration() );
			}
		);
	}

	/**
	 * Register landingpage block.
	 *
	 * @return void
	 */
	public function register_landingpage_block() {
		register_block_type( SMAILY_CONNECT_PLUGIN_PATH . '/blocks/landingpage/build' );
		wp_set_script_translations(
			'smaily-landingpage-block-editor-script',
			'smaily-connect',
			SMAILY_CONNECT_PLUGIN_PATH . 'languages'
		);
	}
}
