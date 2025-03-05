<?php
/**
 * Synchronize WooCommerce subscribers with Smaily contacts.
 *
 * @package Smaily_WC
 */

namespace Smaily_WC;

use Smaily_Helper;
use Smaily_Logger;
use Smaily_Options;
use Smaily_Request;
use WC_Order;
use WP_REST_Request;

class Subscriber_Synchronization {
	/**
	 * Service name.
	 * @var string
	 */
	const SERVICE = 'woocommerce_subscriber_synchronization';

	/**
	 * @var \Smaily_Options Instance of Smaily_Options.
	 */
	private $options;

	/**
	 * Logger.
	 * @var Smaily_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param \Smaily_Options $options Instance of Smaily_Options.
	 */
	public function __construct( \Smaily_Options $options ) {
		$this->options = $options;
		$this->logger  = new Smaily_Logger( self::SERVICE );
	}

	/**
	 * Make API call with subscriber data when updating user profile in admin page settings.
	 *
	 * @param int $user_id ID of the user being updated.
	 * @return void
	 */
	public function smaily_newsletter_subscribe_update( $user_id ) {
		$nonce_val = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce_val, 'update-user_' . $user_id ) ) {
			return;
		}

		// Make API call for user transfer only if user is subscribed.
		if ( ! isset( $_POST['user_newsletter'] ) || (int) $_POST['user_newsletter'] !== 1 ) {
			return;
		}

		$this->update_subscriber( $user_id );
	}

	/**
	 * Make API call with subscriber data when customer account is created.
	 *
	 * @param integer $customer_id New customer ID.
	 *
	 * @return void
	 */
	public function smaily_wc_created_customer_update( $customer_id ) {
		$nonce_val = isset( $_POST['woocommerce-process-checkout-nonce'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce_val, 'woocommerce-process_checkout' ) ) {
			return;
		}

		// Make API call for user transfer only if user is subscribed.
		if ( ! isset( $_POST['user_newsletter'] ) || (int) $_POST['user_newsletter'] !== 1 ) {
			return;
		}

		$this->update_subscriber( $customer_id );
	}

	/**
	 * Make API call with subscriber data when customer account account details are updated.
	 *
	 * @param int $customer_ir ID of the customer.
	 * @return void
	 */
	public function smaily_wc_newsletter_subscribe_update( $customer_id ) {
		$nonce_val = isset( $_POST['save-account-details-nonce'] ) ? sanitize_key( wp_unslash( $_POST['save-account-details-nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce_val, 'save_account_details' ) ) {
			return;
		}

		// Make API call for user transfer only if user is subscribed.
		if ( ! isset( $_POST['user_newsletter'] ) || (int) $_POST['user_newsletter'] !== 1 ) {
			return;
		}

		$this->update_subscriber( $customer_id );
	}

	/**
	 * Subscribes customer in checkout form when subscribe newsletter box is checked.
	 *
	 * @param int       $order_id Order ID
	 * @param array     $posted_data Data
	 * @param \WC_Order $order Order
	 * @return void
	 */
	public function smaily_checkout_subscribe_customer( $order_id, $posted_data, $order ) {
		if ( ! isset( $posted_data['user_newsletter'] ) || (int) $posted_data['user_newsletter'] !== 1 ) {
			return;
		}

		if ( ! get_option( Smaily_Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION ) ) {
			return;
		}

		$this->order_optin_subscriber( $order );
	}

	/**
	 * Subscribes customer in checkout form when subscribe newsletter box is checked.
	 *
	 * @param WC_Order        $order Order
	 * @param WP_REST_Request $request Request
	 * @return void
	 */
	public function smaily_checkout_subscribe_block_customer( WC_Order $order, WP_REST_Request $request ) {
		if ( ! isset( $request['extensions'][ \Smaily_Checkout_Optin_Extend_Store_Endpoint::IDENTIFIER ]['user_newsletter'] ) ) {
			return;
		}

		if ( $request['extensions'][ \Smaily_Checkout_Optin_Extend_Store_Endpoint::IDENTIFIER ]['user_newsletter'] !== true ) {
			return;
		}

		if ( ! get_option( Smaily_Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION ) ) {
			return;
		}

		$this->order_optin_subscriber( $order );
	}

	private function order_optin_subscriber( WC_Order $order ) {
		$sync_options        = get_option( Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION, Smaily_Options::CUSTOMER_SYNC_DEFAULT_FIELDS );
		$enabled_sync_fields = array_keys( array_filter( $sync_options ) );

		// Order is made by a registered user.
		$user_id = $order->get_customer_id();
		if ( $user_id !== 0 ) {
			return $this->update_subscriber( $user_id );
		}

		// Order is made by a guest user.
		$posted_data = array(
			// Ensure subscriber's unsubscribed status is reset.
			'is_unsubscribed' => 0,
		);
		foreach ( $enabled_sync_fields as $field ) {
			switch ( $field ) {
				case 'store_url':
					$posted_data['store'] = get_site_url();
					break;
				case 'user_email':
					$posted_data['email'] = $order->get_billing_email();
					break;
				case 'language':
					$posted_data['language'] = Smaily_Helper::get_current_language_code();
					break;
				case 'customer_group':
					$posted_data['customer_group'] = 'guest';
					break;
				case 'customer_id':
					$posted_data['customer_id'] = 0;
					break;
				case 'first_name':
					$posted_data['first_name'] = $order->get_billing_first_name();
					break;
				case 'last_name':
					$posted_data['last_name'] = $order->get_billing_last_name();
					break;
				case 'site_title':
					$posted_data['site_title'] = get_bloginfo( 'name' );
					break;
			}
		}

		$request  = new Smaily_Request( $this->options );
		$response = $request->update_subscribers( $posted_data );
		if ( empty( $response ) ) {
			return $this->logger->error( sprintf( 'Failed to subscribe customer during checkout. The order with id "%d" failed with unknown error.', $order->get_id() ) );
		}

		if ( isset( $response['error'] ) ) {
			return $this->logger->error( sprintf( 'Failed to subscribe customer during checkout. The order with id "%d" failed with an error: %s', $order->get_id(), $response['error'] ) );
		}

		if ( isset( $response['body']['code'] ) && $response['body']['code'] !== 101 ) {
			return $this->logger->error( sprintf( 'Failed to subscribe customer during checkout: %s', wp_json_encode( $response ) ) );
		}
	}

	/**
	 * Update subscriber with data defined by synchronize additional field options.
	 *
	 * @param int $user_id
	 * @return void
	 */
	private function update_subscriber( $user_id ) {
		// Set user language code. This is used during synchronization where context is not available.
		Smaily_Helper::set_user_language_code( $user_id );

		$posted_data = array(
			// Ensure subscriber's unsubscribed status is reset.
			'is_unsubscribed' => 0,
		);

		$request  = new Smaily_Request( $this->options );
		$response = $request->update_subscribers( array_merge( $posted_data, Data_Handler::get_user_data( $user_id ) ) );
		if ( empty( $response ) ) {
			return $this->logger->error( sprintf( 'Updating subscriber with id "%d" failed with unknown error', $user_id ) );
		}

		if ( isset( $response['error'] ) ) {
			return $this->logger->error( sprintf( 'Updating subscriber with id "%d" failed with an error: %s', $user_id, $response['error'] ) );
		}

		if ( isset( $response['body']['code'] ) && $response['body']['code'] !== 101 ) {
			return $this->logger->error( sprintf( 'Updating subscriber failed: %s', wp_json_encode( $response ) ) );
		}
	}
}
