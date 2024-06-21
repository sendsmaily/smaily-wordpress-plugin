<?php

/**
 * This class is used to work with the plugin's options
 * that take user input e.g API credentials, form settings.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Options
{

	/**
	 * Smaily API credentials
	 *
	 *
	 * @access private
	 * @var    array   $api_credentials Smaily API credentials.
	 */
	private $api_credentials;

	/**
	 * Newsletter signup form settings.
	 *
	 *
	 * @access private
	 * @var    array   $settings Newsletter signup form settings.
	 */
	private $settings;

	/**
	 * Get API credentials.
	 *
	 *
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
	 *
	 * @return array   $settings Newsletter signup form settings.
	 */
	public function get_settings()
	{
		if (is_null($this->settings)) {
			$this->settings = $this->get_form_options_from_db();

			if (Smaily_Helper::is_woocommerce_active()) {
				$this->settings['woocommerce'] = $this->get_woocommerce_settings_from_db();
			}
		}
		return $this->settings;
	}

	/**
	 * Get API credentials stored in database.
	 *
	 *
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
	 * Get smaily form options stored in database.
	 *
	 *
	 * @access private
	 * @return array   Smaily form options in proper format
	 */
	private function get_form_options_from_db()
	{
		$settings = get_option('smaily_form_options', array());
		return array_merge(
			array(
				'form'        => '',
				'is_advanced' => false
			),
			$settings
		);
	}

	/**
	 * Get smaily woocommerce settings stored in database.
	 *
	 *
	 * @access private
	 * @return array   Smaily woocommerce settings in proper format
	 */
	private function get_woocommerce_settings_from_db()
	{
		$settings = get_option('smaily_woocommerce_settings', array());
		return array_merge(
			array(
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
			),
			$settings
		);
	}

	/**
	 * Overwrite API credentials entry in database with provided parameter.
	 * Disable auto-loading as API credentials are delicate.
	 *
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
	 * Overwrite settings in the database.
	 *
	 * @param array $settings Smaily settings.
	 */
	public function update_settings($settings, $settings_type = 'form_options')
	{
		if (is_array($settings)) {
			$settings = Smaily_Helper::sanitize_array($settings);
			$this->settings = $settings;
		}
		update_option('smaily_' . $settings_type, $settings);
	}

	/**
	 * Has user saved Smaily API credentials to database?
	 *
	 *
	 * @return boolean True if $api_credentials has correct key structure and no empty values.
	 */
	public function has_credentials()
	{
		$api_credentials = $this->get_api_credentials();
		return !empty($api_credentials['subdomain']) && !empty($api_credentials['username']) && !empty($api_credentials['password']);
	}
}
