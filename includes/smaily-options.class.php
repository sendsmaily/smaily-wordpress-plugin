<?php

/**
 * This class is used to work with the plugin's options
 * that take user input e.g API credentials, form settings.
 *
 * @since      1.0.0
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Options
{

	/**
	 * Smaily API credentials
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    array   $api_credentials Smaily API credentials.
	 */
	private $api_credentials;

	/**
	 * Newsletter signup form settings.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    array   $settings Newsletter signup form settings.
	 */
	private $settings;

	/**
	 * Get API credentials.
	 *
	 * @since  1.0.0
	 * @return array   $api_credentials Smaily API credentials
	 */
	public function get_api_credentials()
	{
		if (is_null($this->api_credentials)) {
			$this->api_credentials = $this->get_api_credentials_from_db();
		}
		return $this->api_credentials;
	}

	/**
	 * Get smaily settings.
	 *
	 * @since  1.0.0
	 * @return array   $settings Newsletter signup form settings.
	 */
	public function get_settings()
	{
		if (is_null($this->settings)) {
			$this->settings = $this->get_settings_from_db();
		}
		return $this->settings;
	}

	/**
	 * Get API credentials stored in database.
	 *
	 * @since  1.0.0
	 * @access private
	 * @return array   API credentials in proper format.
	 */
	private function get_api_credentials_from_db()
	{
		$credentials = get_option('smaily_api_credentials', array());

		$credentials = !empty($credentials) ? $credentials : array();

		return array_merge(
			array(
				'subdomain' => '',
				'username'  => '',
				'password'  => '',
			),
			$credentials
		);
	}

	/**
	 * Get form options stored in database.
	 *
	 * @since  1.0.0
	 * @access private
	 * @return array   Form options in proper format
	 */
	private function get_settings_from_db()
	{
		$settings = get_option('smaily_settings', array());
		return array_merge(
			array(
				'form'        => '',
				'is_advanced' => false,
				'woocommerce' => array(
					'customer_sync_enabled' => 0,
					'syncronize_additional' => array(),
					'enable_cart' => 0,
					'cart_autoresponder' => '',
					'cart_autoresponder_id' => 0,
					'cart_cutoff' => 0,
					'cart_options' => array(),
					'checkout_checkbox_enabled' => 0,
					'checkbox_auto_checked' => 0,
					'checkbox_order' => 'after',
					'checkbox_location' => 'checkout_billing_form',
					'rss_limit' => 50,
					'rss_category' => '',
					'rss_order_by' => 'modified',
					'rss_order' => 'DESC'
				)
			),
			$settings
		);
	}

	/**
	 * Overwrite API credentials entry in database with provided parameter.
	 * Disable auto-loading as API credentials are delicate.
	 *
	 * @since 1.0.0
	 * @param array $api_credentials Smaily API credentials.
	 */
	public function update_api_credentials($api_credentials)
	{
		// Update_option will sanitize input before saving. We should sanitize as well.
		if (is_array($api_credentials)) {
			$this->api_credentials = array_map('sanitize_text_field', $api_credentials);
		}
		update_option('smaily_api_credentials', $this->api_credentials, false);
	}

	/**
	 * Sanitize array data for input
	 *
	 * @since 1.0.0
	 * @param array $array.
	 */
	private function sanitize_array(array $array)
	{
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				// Recursively sanitize nested arrays
				$array[$key] = $this->sanitize_array($value);
			} elseif (is_string($value)) {
				// Sanitize strings using WordPress sanitizing functions
				$array[$key] = sanitize_text_field($value);
			} elseif (is_email($value)) {
				// Sanitize email
				$array[$key] = sanitize_email($value);
			} elseif (is_url($value)) {
				// Sanitize URL
				$array[$key] = esc_url_raw($value);
			} else {
				// Use default PHP sanitization for other types
				$array[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
			}
		}
		return $array;
	}

	/**
	 * Overwrite form options entry in database with provided parameter.
	 *
	 * @since 1.0.0
	 * @param array $settings Newsletter form options.
	 */
	public function update_settings($settings)
	{
		if (is_array($settings)) {
			$this->settings = array(
				'form'        => isset($settings['form']) ? esc_textarea($settings['form']) : '',
				'is_advanced' => isset($settings['form']) ? (bool) $settings['is_advanced'] : false,
				'woocommerce' => isset($settings['wooocommerce']) ? $this->sanitize_array($settings['wooocommerce']) : array()
			);
		}
		update_option('smaily_settings', $this->settings);
	}

	/**
	 * Clear Smaily API credentials by deleting its option.
	 *
	 * @since 1.0.0
	 */
	public function remove_api_credentials()
	{
		$this->api_credentials = null;
		delete_option('smaily_api_credentials');
	}

	/**
	 * Clear configurations for newsletter subscription from by deleting its option.
	 *
	 * @since 1.0.0
	 */
	public function remove_settings()
	{
		$this->settings = null;
		delete_option('smaily_settings');
	}

	/**
	 * Has user saved Smaily API credentials to database?
	 *
	 * @since  1.0.0
	 * @return boolean True if $api_credentials has correct key structure and no empty values.
	 */
	public function has_credentials()
	{
		$api_credentials = $this->get_api_credentials();
		return !empty($api_credentials['subdomain']) && !empty($api_credentials['username']) && !empty($api_credentials['password']);
	}
}
