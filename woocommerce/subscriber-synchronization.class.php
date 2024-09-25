<?php

namespace Smaily_WC;

/**
 * Newsletter subscriber sync with Smaily contacts
 * Send subscriber to Smaily mailing list when user updates profile
 */
class Subscriber_Synchronization
{
	/**
	 * @var \Smaily_Options Instance of Smaily_Options.
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param \Smaily_Options $options Instance of Smaily_Options.
	 */
	public function __construct(\Smaily_Options $options)
	{
		$this->options = $options->get_settings();
	}

	/**
	 * Make API call with subscriber data when updating user profile in admin page settings.
	 *
	 * @param int $user_id ID of the user being updated.
	 * @return void
	 */
	public function smaily_newsletter_subscribe_update($user_id)
	{
		$nonce_val = isset($_POST['_wpnonce']) ? sanitize_key(wp_unslash($_POST['_wpnonce'])) : '';
		if (! wp_verify_nonce($nonce_val , 'update-user_' . $user_id ) ) {
			return;
		}

		// Make API call for user transfer only if user is subscribed.
		if (!isset($_POST['user_newsletter'])) {
			return;
		}

		$this->update_subscriber($user_id);
	}

	/**
	 * Make API call with subscriber data when customer account is created.
	 * 
	 * @param integer $customer_id New customer ID.
	 *
	 * @return void
	 */
	public function smaily_wc_created_customer_update($customer_id)
	{
		$nonce_val = isset($_POST['woocommerce-process-checkout-nonce']) ? sanitize_key(wp_unslash($_POST['woocommerce-process-checkout-nonce'])) : '';
		if (! wp_verify_nonce($nonce_val , 'woocommerce-process_checkout' ) ) {
			return;
		}

		// Make API call for user transfer only if user is subscribed.
		if (!isset($_POST['user_newsletter'])) {
			return;
		}

		$this->update_subscriber($customer_id);
	}

	/**
	 * Make API call with subscriber data when customer account account details are updated.
	 *
	 * @param int $customer_ir ID of the customer.
	 * @return void
	 */
	public function smaily_wc_newsletter_subscribe_update($customer_id)
	{
		$nonce_val = isset($_POST['save-account-details-nonce']) ? sanitize_key(wp_unslash($_POST['save-account-details-nonce'])) : '';
		if (! wp_verify_nonce($nonce_val , 'save_account_details' ) ) {
			return;
		}

		// Make API call for user transfer only if user is subscribed.
		if (!isset($_POST['user_newsletter'])) {
			return;
		}

		$this->update_subscriber($customer_id);
	}

	/**
	 * Subscribes customer in checkout form when subscribe newsletter box is checked.
	 *
	 * @param int $order_id Order ID
	 * @return void
	 */
	public function smaily_checkout_subscribe_customer($order_id)
	{
		$nonce_val = isset($_POST['woocommerce-process-checkout-nonce']) ? sanitize_key(wp_unslash($_POST['woocommerce-process-checkout-nonce'])) : '';
		if (! wp_verify_nonce($nonce_val , 'woocommerce-process_checkout' ) ) {
			return;
		}

		if (!isset($_POST['user_newsletter'])) {
			return;
		}

		// Data to sent to Smaily API.
		$data = [];

		// Ensure subscriber's unsubscribed status is reset.
		// Note! We are using 'user_newsletter' property value just a precaution to cover
		// cases where site provides a default value for the field.
		$data['is_unsubscribed'] = (int) $_POST['user_newsletter'] === 1 ? 0 : 1;

		// Add store url for refrence in Smaily database.
		$data['store'] = get_site_url();

		// Language code if using WPML.
		$lang = '';
		if (defined('ICL_LANGUAGE_CODE')) {
			$lang = ICL_LANGUAGE_CODE;
			// Language code if using polylang.
		} elseif (function_exists('pll_current_language')) {
			$lang = pll_current_language();
		} else {
			$lang = get_locale();
			if (strlen($lang) > 0) {
				// Remove any value past underscore if exists.
				$lang = explode('_', $lang)[0];
			}
		}
		// Add language code.
		$data['language'] = $lang;

		// Append fields to data array when available.
		// Add first name.
		if (isset($_POST['billing_first_name'])) {
			$data['first_name'] = sanitize_text_field(wp_unslash($_POST['billing_first_name']));
		}
		// Add last name.
		if (isset($_POST['billing_last_name'])) {
			$data['last_name'] = sanitize_text_field(wp_unslash($_POST['billing_last_name']));
		}
		// Add email.
		if (isset($_POST['billing_email'])) {
			$data['email'] = sanitize_text_field(wp_unslash($_POST['billing_email']));
		}

		// Make API call  to Smaily for subscriber update.
		if (isset($data['email'])) {
			\Smaily_Request::post('contact', ['body' => $data]);
		}
	}

	/**
	 * Update subscriber with data defined by synchronize additional field options.
	 * 
	 * @param int $user_id
	 * @return void
	 */
	private function update_subscriber($user_id)
	{
		// Get user data from WordPress, WooCommerce and Custom fields.
		$data = Data_Handler::get_user_data($user_id, $this->options);

		// Make API call to Smaily for subscriber update.
		$response = \Smaily_Request::post('contact', ['body' => $data]);

		if (empty($response)) {
			\Smaily_Logger::error(sprintf('Updating subscriber with id "%d" failed with unknown error', $user_id));
		}

		if (isset($response['error'])) {
			\Smaily_Logger::error(sprintf('Updating subscriber with id "%d" failed with an error: %s', $user_id, $response['error']));
		}

		if (isset($response['body']['code']) && $response['body']['code'] !== 101) {
			\Smaily_Logger::error( sprintf("Updating subscriber failed: %s", wp_json_encode($response)));
		}
	}
}
