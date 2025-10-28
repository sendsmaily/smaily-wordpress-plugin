<?php

namespace Smaily_Connect\Integrations\WooCommerce;

use Smaily_Connect\Includes\Helper as Smaily_Base_Helper;
use Smaily_Connect\Includes\Logger;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;
use WC_Product;
use WP_User;

class Cron {
	/**
	 * Service name.
	 * @var string
	 */
	const SERVICE = 'woocommerce_cron';

	/**
	 * @varInstance of Options.
	 */
	private $options;

	/**
	 * Logger
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Options $options Instance of Options.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
		$this->logger  = new Logger( self::SERVICE );
	}

	/**
	 * Register hooks for the cron.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Register the custom schedule early
		add_filter( 'cron_schedules', array( $this, 'smaily_cron_schedules' ) );
		// Action hook for subscriber synchronization.
		add_action( 'smaily_connect_cron_sync_subscribers', array( $this, 'smaily_sync_subscribers' ) );
		// Cron for updating abandoned cart statuses.
		add_action( 'smaily_connect_cron_abandoned_carts_status', array( $this, 'smaily_abandoned_carts_status' ) );
		// Cron for sending abandoned cart emails.
		add_action( 'smaily_connect_cron_abandoned_carts_email', array( $this, 'smaily_abandoned_carts_email' ) );
	}

	/**
	 * Custom cron schedule for smaily Cron.
	 *
	 * @param array $schedules Schedules array.
	 * @return array $schedules Updated array.
	 */
	public function smaily_cron_schedules( $schedules ) {
		$schedules['smaily_connect_15_minutes'] = array(
			'interval' => 900,
			'display'  => esc_html__( 'In every 15 minutes', 'smaily-connect' ),
		);

		return $schedules;
	}

	/**
	 * Synchronizes contact information between Smaily and WooCommerce.
	 * Logs response from Smaily to smaily-cron file.
	 *
	 * @return void
	 */
	public function smaily_sync_subscribers() {
		if ( ! get_option( Options::SUBSCRIBER_SYNC_ENABLED_OPTION ) ) {
			return;
		}

		$request  = new Smaily_Client( $this->options );
		$response = $request->list_unsubscribers();
		if ( empty( $response ) ) {
			return $this->logger->error( 'Failed to get unsubscribers - received an empty response' );
		}

		if ( isset( $response['error'] ) ) {
			return $this->logger->error( sprintf( 'Receiving unsusbsribers failed with an error: %s', $response['error'] ) );
		}

		if ( isset( $response['code'] ) && $response['code'] !== 200 ) {
			return $this->logger->error( sprintf( 'Unable to retrieve unsubscribed users: %s', wp_json_encode( $response ) ) );
		}

		$unsubscribers_emails = array();
		foreach ( $response['body'] as $value ) {
			array_push( $unsubscribers_emails, $value['email'] );
		}

		// Change WooCommerce subscriber status based on Smaily unsubscribers.
		foreach ( $unsubscribers_emails as $user_email ) {
			$wordpress_unsubscriber = get_user_by( 'email', $user_email );
			if ( ! empty( $wordpress_unsubscriber ) ) {
				update_user_meta( $wordpress_unsubscriber->ID, 'user_newsletter', 0, 1 );
			}
		}

		// Get all users with subscribed status.
		$users = get_users(
			array(
				'meta_key'   => 'user_newsletter', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 1, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		if ( empty( $users ) ) {
			return $this->logger->info( 'No subscribers for synchronization!' );
		}

		$list = array();
		foreach ( $users as $user ) {
			$subscriber = Data_Handler::get_user_data( $user->ID );
			array_push( $list, $subscriber );
		}

		$response = $request->update_subscribers( $list );
		if ( empty( $response ) ) {
			return $this->logger->error( 'Failed to send subscribers to Smaily - received an empty response' );
		}

		if ( isset( $response['error'] ) ) {
			return $this->logger->error( sprintf( 'Failed to send subscribers to Smaily with an error: %s', $response['error'] ) );
		}

		if ( isset( $response['body']['code'] ) && $response['body']['code'] !== 101 ) {
			return $this->logger->error( sprintf( 'Unable to send subscribers to Smaily: %s', wp_json_encode( $response ) ) );
		}
	}

	/**
	 * Abandoned carts synchronization to Smaily API
	 *
	 * @return void
	 */
	public function smaily_abandoned_carts_email() {
		$status = get_option(
			Options::ABANDONED_CART_STATUS_OPTION,
			Options::ABANDONED_CART_DEFAULT_STATUS
		);
		if ( ! $status['enabled'] ) {
			return;
		}

		$sync_fields = get_option(
			Options::ABANDONED_CART_FIELDS_OPTION,
			Options::ABANDONED_CART_DEFAULT_FIELDS
		);

		foreach ( $this->get_abandoned_carts() as $cart ) {
			$cart_content = maybe_unserialize( $cart['cart_content'] );
			if ( empty( $cart ) ) {
				continue;
			}

			$user = get_userdata( $cart['customer_id'] );
			if ( empty( $user ) || empty( $user->user_email ) ) {
				continue;
			}

			$addresses = $this->prepare_user_data( $user, $sync_fields );
			$products  = $this->prepare_products_data( $cart_content, $sync_fields );

			$request  = new Smaily_Client( $this->options );
			$response = $request->trigger_automation(
				(int) $status['autoresponder_id'],
				array( array_merge( $addresses, $products ) ),
				false
			);

			if ( empty( $response ) ) {
				return $this->logger->error( 'Failed to trigger abandoned cart email flow - received an empty response' );
			}

			if ( isset( $response['error'] ) ) {
				return $this->logger->error( sprintf( 'Failed to send abandoned cart email with an error: %s', $response['error'] ) );
			}

			if ( isset( $response['body']['code'] ) && $response['body']['code'] !== 101 ) {
				return $this->logger->error( sprintf( 'Failed to send abandoned cart email: %s', wp_json_encode( $response ) ) );
			}

			$this->update_mail_sent_status( $cart['customer_id'] );
		}
	}

	/**
	 * Get product sale display price without html tags.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return string
	 */
	public function get_sale_price_with_tax( $product ) {
		$price = wc_price(
			Helper::get_current_price_with_tax( $product )
		);

		return wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Get product regular display price without html tags.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return string
	 */
	public function get_base_price_with_tax( $product ) {
		$price = wc_price(
			Helper::get_regular_price_with_tax( $product )
		);

		return wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Update mail_sent and mail_sent_time status in abandoned cart table.
	 *
	 * @param int $customer_id Customer ID.
	 * @return void
	 */
	public function update_mail_sent_status( $customer_id ) {
		global $wpdb;

		$table = $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME;
		$wpdb->update(
			$table,
			array(
				'mail_sent'      => 1,
				'mail_sent_time' => gmdate( 'Y-m-d\TH:i:s\Z' ),
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
	public function get_abandoned_carts() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM `%1$s` WHERE cart_status = \'%2$s\' AND mail_sent IS NULL',
				$wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME,
				'abandoned'
			),
			'ARRAY_A'
		);
	}

	/**
	 * Update abandoned cart status based on cutoff time.
	 *
	 * @return void
	 */
	public function smaily_abandoned_carts_status() {
		global $wpdb;
		$results = $this->options->get_settings();

		// Check if abandoned cart is enabled.
		if ( isset( $results['woocommerce']['enable_cart'] ) && (int) $results['woocommerce']['enable_cart'] === 1 ) {
			// Abandoned carts table name.
			$table = $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME;
			// Cart cutoff in seconds.
			$cutoff = (int) $results['woocommerce']['cart_cutoff'] * MINUTE_IN_SECONDS;
			// Current UTC timestamp - cutoff.
			$limit = strtotime( gmdate( 'Y-m-d\TH:i:s\Z' ) ) - $cutoff;
			$time  = gmdate( 'Y-m-d\TH:i:s\Z', $limit );

			// Select all carts before cutoff time.
			$carts = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `%1$s` WHERE cart_status = \'%2$s\' AND mail_sent IS NULL AND cart_updated < \'%3$s\'',
					$table,
					'open',
					$time
				),
				'ARRAY_A'
			);

			foreach ( $carts as $cart ) {
				// Update abandoned status and time.
				$customer_id = $cart['customer_id'];
				$wpdb->update(
					$table,
					array(
						'cart_status'         => 'abandoned',
						'cart_abandoned_time' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					),
					array(
						'customer_id' => $customer_id,
					)
				);
			}
		}
	}

	/**
	 * Prepare user data for Smaily API.
	 *
	 * @param WP_User $user User data.
	 * @param array   $options   User options.
	 * @return array
	 */
	private function prepare_user_data( WP_User $user, array $options ) {
		$addresses = array(
			// is_abandoned_cart field is a business requirement.
			// If same account is used for marketing and abandoned cart, then it is necessary to distinguish
			// between the two. The contact can receive abandoned cart emails, but not marketing emails.
			'is_abandoned_cart' => 'true',
		);

		foreach ( $options as $field => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			switch ( $field ) {
				case 'store_url':
					$addresses['store'] = get_site_url();
					break;
				case 'user_email':
					$addresses['email'] = $user->user_email;
					break;
				case 'language':
					$addresses['language'] = Smaily_Base_Helper::get_user_language_code( $user->ID );
					break;
				case 'first_name':
					$addresses['first_name'] = $user->first_name;
					break;
				case 'last_name':
					$addresses['last_name'] = $user->last_name;
					break;
				default:
					break;
			}
		}

		return $addresses;
	}

	/**
	 * Prepare products data for Smaily API.
	 *
	 * @param array $cart_data Cart data.
	 * @param array $options   Product options.
	 * @return array
	 */
	private function prepare_products_data( array $cart_data, array $options ) {
		$products = array();

		$product_values = array(
			'product_base_price',
			'product_description',
			'product_image_url',
			'product_name',
			'product_price',
			'product_quantity',
			'product_sku',
		);
		// Add empty product data for addresses. Fields available would be filled out later with data.
		// Required for legacy API so that all fields are always updated.
		foreach ( $product_values as $key ) {
			for ( $i = 1; $i < 11; $i++ ) {
				$products[ $key . '_' . $i ] = '';
			}
		}

		$selected_fields = array_intersect( $product_values, array_keys( array_filter( $options ) ) );
		if ( ! empty( $selected_fields ) ) {
			$products_data = array();
			foreach ( $cart_data as $cart_item ) {
				$product = array();

				// Get product details if selected from user settings.
				$details = wc_get_product( $cart_item['product_id'] );
				if ( ! $details ) {
					continue;
				}

				foreach ( $selected_fields as $selected_field ) {
					switch ( $selected_field ) {
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
							$product['product_price'] = $this->get_sale_price_with_tax( $details );
							break;
						case 'product_base_price':
							$product['product_base_price'] = $this->get_base_price_with_tax( $details );
							break;
						case 'product_image_url':
							$url = $this->get_product_image_url( $details );
							if ( ! empty( $url ) ) {
								$product['product_image_url'] = $url;
							}
							break;
					}
				}

				$products_data[] = $product;
			}

			// Append products array to API api call. Up to 10 product details.
			$i = 1;
			foreach ( $products_data as $product ) {
				if ( $i > 10 ) {
					$products['over_10_products'] = 'true';
					break;
				}

				foreach ( $product as $key => $value ) {
					$products[ $key . '_' . $i ] = htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
				}
				++$i;
			}
		}

		return $products;
	}

	/**
	 * Get product images.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return string
	 */
	private function get_product_image_url( WC_Product $product ) {
		$image_url = '';

		if ( $product->get_image_id() ) {
			$image_url = wp_get_attachment_url( $product->get_image_id() );
		}

		// Default to featured image.
		if ( ! empty( $image_url ) ) {
			return $image_url;
		}

		// Try to get first gallery image.
		$gallery_image_ids = $product->get_gallery_image_ids();
		if ( ! empty( $gallery_image_ids ) ) {
			return wp_get_attachment_url( $gallery_image_ids[0] );
		}

		return '';
	}
}
