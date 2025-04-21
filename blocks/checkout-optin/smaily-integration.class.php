<?php

namespace Smaily_Connect\Blocks\Checkout_Optin;

use Smaily_Connect\Includes\Options;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

define( 'SMAILY_CHECKOUT_OPTIN_VERSION', '1.0.0' );

/**
 * Class for integrating with WooCommerce Blocks
 */
class Integration implements IntegrationInterface {
	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'smaily-checkout-optin';
	}

	/**
	 * When called invokes any initialization/setup for the integration.
	 */
	public function initialize() {
		$this->register_frontend_scripts();
		$this->register_editor_scripts();
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'smaily-checkout-optin-block-frontend' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'smaily-checkout-optin-block-editor' );
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		$enabled = get_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION );

		$data = array(
			'smailyCheckoutOptinActive' => $enabled,
			'optInDefaultText'          => __( 'Subscribe to newsletter', 'smaily-connect' ),
		);

		return $data;
	}

	public function register_editor_scripts() {
		$script_path       = '/build/smaily-checkout-optin-block.js';
		$script_url        = plugins_url( $script_path, __FILE__ );
		$script_asset_path = __DIR__ . '/build/smaily-checkout-optin-block.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'smaily-checkout-optin-block-editor',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_set_script_translations(
			'smaily-checkout-optin-block-editor', // script handle
			'smaily-connect', // text domain
			__DIR__ . '/languages'
		);
	}

	public function register_frontend_scripts() {
		$script_path       = '/build/smaily-checkout-optin-block-frontend.js';
		$script_url        = plugins_url( $script_path, __FILE__ );
		$script_asset_path = __DIR__ . '/build/newsletter-block-frontend.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'smaily-checkout-optin-block-frontend',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
		wp_set_script_translations(
			'smaily-checkout-optin-block-frontend', // script handle
			'smaily-connect', // text domain
			__DIR__ . '/languages'
		);
	}

	/**
	 * Get the file modified time as a cache buster if we're in dev mode.
	 *
	 * @param string $file Local path to the file.
	 * @return string The cache buster value to use for the given file.
	 */
	protected function get_file_version( $file ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return filemtime( $file );
		}
		return SMAILY_CHECKOUT_OPTIN_VERSION;
	}
}
