<?php

namespace Smaily_WC;

/**
 * Class Cron
 * Handles data synchronization between Smaily and WooCommerce.
 */
class Cron
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
		$this->options = $options;
	}

	/**
	 * Custom cron schedule for smaily Cron.
	 *
	 * @param array $schedules Schedules array.
	 * @return aray $schedules Updated array.
	 */
	public function smaily_cron_schedules($schedules)
	{
		$schedules['smaily_15_minutes'] = array(
			'interval' => 900,
			'display'  => esc_html__('In every 15 minutes'),
		);
		return $schedules;
	}

	/**
	 * Synchronizes contact information between Smaily and WooCommerce.
	 * Logs response from Smaily to smaily-cron file.
	 *
	 * @return void
	 */
	public function smaily_sync_contacts()
	{

		$results = $this->options->get_settings();

		// Check if contact sync is enabled.
		if ((int) $results['woocommerce']['customer_sync_enabled'] === 1) {

			// List value 2  = unsubscribers list.
			$data = array(
				'list' => 2,
			);

			// Make API call to Smaily to get unsubscribers.
			$unsubscribers = \Smaily_Request::get('contact', $data);

			if ($unsubscribers['code'] !== 200) {
				\Smaily_Logger::warning('Unable to retrieve unsubsribed users!');
				return;
			}

			$unsubscribers = $unsubscribers['body'];
			// List of unsubscribed emails.
			$unsubscribers_emails = [];
			foreach ($unsubscribers as $value) {
				array_push($unsubscribers_emails, $value['email']);
			}

			// Change WooCommerce subscriber status based on Smaily unsubscribers.
			foreach ($unsubscribers_emails as $user_email) {

				// get user by email from unsubscribers list.
				$wordpress_unsubscriber = get_user_by('email', $user_email);
				// set user subscribed status to 0.
				if (!empty($wordpress_unsubscriber)) {
					update_user_meta($wordpress_unsubscriber->ID, 'user_newsletter', 0, 1);
				}
			}

			update_user_meta(1, 'user_newsletter', 1);

			// Get all users with subscribed status.
			$users = get_users(
				array(
					'meta_key'   => 'user_newsletter',
					'meta_value' => 1,
				)
			);

			// If no subscribers.
			if (empty($users)) {
				\Smaily_Logger::info('No subscribers!');
				return;
			}

			$list = array();
			foreach ($users as $user) {
				$subscriber = Data_Handler::get_user_data($user->ID, $results);
				array_push($list, $subscriber);
			}

			// Update all subscribers to Smaily.
			\Smaily_Request::post('contact', ['body' => $list]);
		}
	}

	/**
	 * Abandoned carts synchronization to Smaily API
	 *
	 * @return void
	 */
	public function smaily_abandoned_carts_email()
	{

		// Get Smaily settings.
		$results = $this->options->get_settings();
		if (!isset($results['woocommerce']['enable_cart'])) {
			// Something wrong with settings. Default value 0.
			return;
		}

		if ((int) $results['woocommerce']['enable_cart'] !== 1) {
			// Not activated.
			return;
		}

		$abandoned_carts = $this->get_abandoned_carts();

		foreach ($abandoned_carts as $cart) {
			// Get cart details and cart data from cart.
			$cart_data = unserialize($cart['cart_content']);
			// Continue with sending data to Smaily if there are items in customer cart.
			if (empty($cart_data)) {
				continue;
			}

			// Customer fields available.
			$customer_id   = $cart['customer_id'];
			$customer_data = get_userdata($customer_id);
			$customer      = [
				'first_name' => !empty($customer_data) ? $customer_data->first_name : '',
				'last_name'  => !empty($customer_data) ? $customer_data->last_name : '',
				'email'      => !empty($customer_data) ? $customer_data->user_email : '',
			];
			// Continue with data gathering only if there is an email value to send data to.
			if (empty($customer['email'])) {
				continue;
			}

			// Data to send to smail API.
			$addresses = [
				'first_name' => '',
				'last_name'  => '',
			];
			// Gather customer data.
			$customer_data = [];
			$sync_values   = ['first_name', 'last_name', 'email'];
			foreach ($sync_values as $sync_value) {
				// Check if user has enabled extra field in settings.
				if (in_array($sync_value, $results['woocommerce']['cart_options'], true) || $sync_value === 'email') {
					// Add extra field if it's available in customer data.
					if (isset($customer[$sync_value])) {
						$addresses[$sync_value] = $customer[$sync_value];
					}
				}
			}

			// Products data values available.
			$cart_sync_values = [
				'product_name',
				'product_description',
				'product_sku',
				'product_quantity',
				'product_base_price',
				'product_price',
				'product_images'
			];
			// Add empty product data for addresses. Fields available would be filled out later with data.
			// Required for legacy API so that all fields are always updated.
			foreach ($cart_sync_values as $key) {
				for ($i = 1; $i < 11; $i++) {
					$addresses[$key . '_' . $i] = '';
				}
			}
			$selected_fields = array_intersect($cart_sync_values, $results['woocommerce']['cart_options']);
			// Gather products data if user has selected at least one of additional product field to sync.

			if (!empty($selected_fields)) {
				$products_data = [];
				foreach ($cart_data as $cart_item) {
					$product = [];

					// Get product details if selected from user settings.
					$details = wc_get_product($cart_item['product_id']);
					if (!$details) {
						continue;
					}

					foreach ($selected_fields as $selected_field) {
						switch ($selected_field) {
							case 'product_name':
								$product['product_name'] = $details->get_name();
								break;
							case 'product_description':
								$product['product_description'] = $details->get_description();
								break;
							case 'product_sku':
								$product['product_sku'] = $details->get_sku();
								break;
							case 'product_quantity':
								$product['product_quantity'] = $cart_item['quantity'];
								break;
							case 'product_price':
								$product['product_price'] = $this->get_sale_price($details);
								break;
							case 'product_base_price':
								$product['product_base_price'] = $this->get_base_price($details);
								break;
							case 'product_images':

								// Initialize an array to hold your image URLs
								$image_urls = [];

								// Get the URL of the main product image
								if ($details->get_image_id()) {
									$image_urls[] = wp_get_attachment_url($details->get_image_id());
								}

								// Get URLs of any additional gallery images
								$gallery_image_ids = $details->get_gallery_image_ids();
								foreach ($gallery_image_ids as $image_id) {
									$image_urls[] = wp_get_attachment_url($image_id);
								}

								$product['product_images'] = implode(',', $image_urls);

								break;
						}
					}

					$products_data[] = $product;
				}

				// Append products array to API api call. Up to 10 product details.
				$i = 1;
				foreach ($products_data as $product) {
					if ($i > 10) {
						$addresses['over_10_products'] = 'true';
						break;
					}

					foreach ($product as $key => $value) {
						$addresses[$key . '_' . $i] = htmlspecialchars($value);
					}
					$i++;
				}
			}

			// Add "abandoned_cart" param to the payload
			$addresses['abandoned_cart'] = 'yes';

			// Query for Smaily autoresponder.
			$query = [
				'autoresponder' => $results['woocommerce']['cart_autoresponder_id'], // autoresponder ID.
				'addresses'     => [$addresses],
				'force_opt_in'	=> 0
			];

			//\Smaily_Logger::error('Mail sent: ' . print_r($query, true));

			// Send data to Smaily.
			$response = \Smaily_Request::post('autoresponder', ['body' => $query]);
			// If data sent successfully update mail_sent status in database.
			if (isset($response['body']['code']) && $response['body']['code'] === 101) {
				$this->update_mail_sent_status($customer_id);
			} else {
				// Log to file if errors.
				\Smaily_Logger::error(wp_json_encode($response));
			}
		}
	}

	/**
	 * Get product sale display price without html tags.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return string
	 */
	public function get_sale_price($product)
	{
		$price = wc_price(
			wc_get_price_to_display(
				$product,
				array(
					'price' => $product->get_sale_price(),
				)
			)
		);

		return wp_strip_all_tags(html_entity_decode($price));
	}

	/**
	 * Get product regular display price without html tags.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return string
	 */
	public function get_base_price($product)
	{

		$price = wc_price(
			wc_get_price_to_display(
				$product,
				array(
					'price' => $product->get_regular_price(),
				)
			)
		);

		return wp_strip_all_tags(html_entity_decode($price));
	}

	/**
	 * Update mail_sent and mail_sent_time status in smaily_abandoned_carts table.
	 *
	 * @param int $customer_id Customer ID.
	 * @return void
	 */
	public function update_mail_sent_status($customer_id)
	{
		// WordPress Database handler.
		global $wpdb;

		$table = $wpdb->prefix . 'smaily_abandoned_carts';
		$wpdb->update(
			$table,
			array(
				'mail_sent'      => 1,
				'mail_sent_time' => gmdate('Y-m-d\TH:i:s\Z'),
			),
			array(
				'customer_id' => $customer_id,
			)
		);
	}

	/**
	 * Get abandoned carts from smaily_abandoned_carts table.
	 *
	 * @return array
	 */
	public function get_abandoned_carts()
	{

		// WordPress Database handler.
		global $wpdb;
		// Get all abandoned carts.
		return $wpdb->get_results(
			"
			SELECT * FROM {$wpdb->prefix}smaily_abandoned_carts
			WHERE cart_status='abandoned'
			AND mail_sent IS NULL
			",
			'ARRAY_A'
		);
	}

	/**
	 * Update abandoned cart status based on cutoff time.
	 *
	 * @return void
	 */
	public function smaily_abandoned_carts_status()
	{

		global $wpdb;
		$results = $this->options->get_settings();

		// Check if abandoned cart is enabled.
		if (isset($results['woocommerce']['enable_cart']) && (int) $results['woocommerce']['enable_cart'] === 1) {
			// Abandoned carts table name.
			$table = $wpdb->prefix . 'smaily_abandoned_carts';
			// Cart cutoff in seconds.
			$cutoff = (int) $results['woocommerce']['cart_cutoff'] * 60;
			// Current UTC timestamp - cutoff.
			$limit = strtotime(gmdate('Y-m-d\TH:i:s\Z')) - $cutoff;
			$time = gmdate('Y-m-d\TH:i:s\Z', $limit);


			// Select all carts before cutoff time.
			$carts = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT * FROM {$wpdb->prefix}smaily_abandoned_carts
					WHERE cart_status='open'
					AND mail_sent IS NULL
					AND cart_updated < %s
					",
					$time
				),
				'ARRAY_A'
			);

			foreach ($carts as $cart) {
				// Update abandoned status and time.
				$customer_id = $cart['customer_id'];
				$wpdb->update(
					$table,
					array(
						'cart_status'         => 'abandoned',
						'cart_abandoned_time' => gmdate('Y-m-d\TH:i:s\Z'),
					),
					array(
						'customer_id' => $customer_id,
					)
				);
			}
		}
	}
}
