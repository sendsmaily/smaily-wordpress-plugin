<?php

/**
 * This class is used to work with the plugin's options
 * that take user input e.g API credentials, form settings.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Options {
	/**
	 * Default values for customer sync fields.
	 *
	 * @var array
	 */
	const CUSTOMER_SYNC_DEFAULT_FIELDS = array(
		'store_url'        => true,
		'user_email'       => true,
		'customer_group'   => false,
		'customer_id'      => false,
		'first_name'       => false,
		'first_registered' => false,
		'last_name'        => false,
		'nickname'         => false,
		'site_title'       => false,
		'user_dob'         => false,
		'user_gender'      => false,
		'user_phone'       => false,
	);

	/**
	 * Default values for abandoned cart fields.
	 *
	 * @var array
	 */
	const ABANDONED_CART_DEFAULT_FIELDS = array(
		'store_url'           => true,
		'user_email'          => true,
		'first_name'          => false,
		'last_name'           => false,
		'product_base_price'  => false,
		'product_description' => false,
		'product_images'      => false,
		'product_name'        => false,
		'product_price'       => false,
		'product_quantity'    => false,
		'product_sku'         => false,
	);

	/**
	 * Get API credentials.
	 *
	 * @return array{subdomain: string, username: string, password: string} Smaily API credentials
	 */
	public function get_api_credentials() {
		$credentials = get_option( 'smaily_api_credentials', array() );
		$password    = isset( $credentials['password'] ) ? Smaily_Cypher::decrypt( $credentials['password'] ) : '';
		unset( $credentials['password'] );

		return array_merge(
			array(
				'subdomain' => '',
				'username'  => '',
				'password'  => $password,
			),
			$credentials
		);
	}

	/**
	 * Get smaily settings.
	 *
	 *
	 * @return array Smaily module settings.
	 */
	public function get_settings() {
		$settings = array();

		if ( Smaily_Helper::is_woocommerce_active() ) {
			$settings['woocommerce'] = $this->get_woocommerce_settings_from_db();
		}

		if ( Smaily_Helper::is_cf7_active() ) {
			$settings['cf7'] = $this->get_cf7_settings_from_db();
		}

		return $settings;
	}

	/**
	 * Get smaily woocommerce settings stored in database.
	 *
	 *
	 * @access private
	 * @return array   Smaily woocommerce settings in proper format
	 */
	private function get_woocommerce_settings_from_db() {
		$cart_status = get_option( 'smaily_abandoned_cart_status' );

		return array(
			'customer_sync_enabled'     => get_option( 'smaily_customer_sync_enabled' ),
			'synchronize_additional'    => get_option( 'smaily_customer_sync_fields' ),
			'enable_cart'               => $cart_status['enabled'],
			'cart_autoresponder_id'     => $cart_status['autoresponder_id'],
			'cart_cutoff'               => (int) get_option( 'smaily_abandoned_cart_cutoff' ),
			'cart_options'              => get_option( 'smaily_abandoned_cart_fields' ),
			'checkout_checkbox_enabled' => get_option( 'smaily_checkout_subscription_enabled' ),
			'checkbox_order'            => get_option( 'smaily_checkout_subscription_position' ),
			'checkbox_location'         => get_option( 'smaily_checkout_subscription_location' ),
			'rss_limit'                 => get_option( 'smaily_rss_limit' ),
			'rss_category'              => get_option( 'smaily_rss_category' ),
			'rss_order_by'              => get_option( 'smaily_rss_sort_by' ),
			'rss_order'                 => get_option( 'smaily_rss_order_by' ),
		);
	}

	/**
	 * Get Contact Form 7 settings stored in database.
	 *
	 *
	 * @access private
	 * @return array   Smaily Contact Form 7 settings in proper format
	 */
	private function get_cf7_settings_from_db() {
		$settings = get_option( 'smaily_cf7_settings', array() );
		return array_merge(
			array(
				'autoresponder_id' => 0,
				'is_enabled'       => 0,
			),
			$settings
		);
	}

	/**
	 * Update module settings. For updating credentials use update_api_credentials function.
	 *
	 * @param array $settings Array of settings for the setting type.
	 * @param string $settings_type woocommerce_settings | cf7_settings
	 * @return void
	 */
	public function update_settings( $settings, $settings_type ) {
		$allowed_setting_types = array( 'woocommerce_settings', 'cf7_settings' );

		if ( ! in_array( $settings_type, $allowed_setting_types, true ) ) {
			throw new InvalidArgumentException( 'Updating Smaily with unknown settings type: ' . esc_textarea( $settings_type ) );
		}

		if ( is_array( $settings ) ) {
			$settings       = Smaily_Helper::sanitize_array( $settings );
			$this->settings = $settings;
		}

		update_option( 'smaily_' . $settings_type, $settings );
	}

	/**
	 * Has user saved Smaily API credentials to database?
	 *
	 *
	 * @return boolean True if $api_credentials has correct key structure and no empty values.
	 */
	public function has_credentials() {
		$api_credentials = $this->get_api_credentials();
		return ! empty( $api_credentials['subdomain'] ) && ! empty( $api_credentials['username'] ) && ! empty( $api_credentials['password'] );
	}
}
