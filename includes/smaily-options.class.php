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
	 * Default values for abandoned cart status.
	 *
	 * @var array
	 */
	const ABANDONED_CART_DEFAULT_STATUS = array(
		'enabled'          => false,
		'autoresponder_id' => 0,
	);

	/**
	 * Default cart cutoff time in minutes.
	 *
	 * @var array
	 */
	const ABANDONED_CART_DEFAULT_CUTOFF = 10;

	/**
	 * Default position for checkout subscription checkbox.
	 *
	 * @var array
	 */
	const CHECKOUT_SUBSCRIPTION_DEFAULT_POSITION = 'before';

	/**
	 * Default location for checkout subscription checkbox.
	 *
	 * @var array
	 */
	const CHECKOUT_SUBSCRIPTION_DEFAULT_LOCATION = 'order_notes';

	/**
	 * Default number of RSS feed items.
	 *
	 * @var array
	 */
	const RSS_DEFAULT_LIMIT = 50;

	/**
	 * Default RSS feed sort by.
	 *
	 * @var array
	 */
	const RSS_DEFAULT_SORT_BY = 'modified';

	/**
	 * Default RSS feed order by.
	 *
	 * @var array
	 */
	const RSS_DEFAULT_ORDER_BY = 'DESC';


	const API_CREDENTIALS_OPTION                = 'smaily_api_credentials';
	const CUSTOMER_SYNC_ENABLED_OPTION          = 'smaily_customer_sync_enabled';
	const CUSTOMER_SYNC_FIELDS_OPTION           = 'smaily_customer_sync_fields';
	const ABANDONED_CART_STATUS_OPTION          = 'smaily_abandoned_cart_status';
	const ABANDONED_CART_CUTOFF_OPTION          = 'smaily_abandoned_cart_cutoff';
	const ABANDONED_CART_FIELDS_OPTION          = 'smaily_abandoned_cart_fields';
	const CHECKOUT_SUBSCRIPTION_ENABLED_OPTION  = 'smaily_checkout_subscription_enabled';
	const CHECKOUT_SUBSCRIPTION_POSITION_OPTION = 'smaily_checkout_subscription_position';
	const CHECKOUT_SUBSCRIPTION_LOCATION_OPTION = 'smaily_checkout_subscription_location';
	const RSS_LIMIT_OPTION                      = 'smaily_rss_limit';
	const RSS_CATEGORY_OPTION                   = 'smaily_rss_category';
	const RSS_SORT_BY_OPTION                    = 'smaily_rss_sort_by';
	const RSS_ORDER_BY_OPTION                   = 'smaily_rss_order_by';
	const RSS_URL_OPTION                        = 'smaily_rss_url';
	const DATABASE_VERSION_OPTION               = 'smaily_db_version';
	const CONTACT_FORM_7_STATUS_OPTION          = 'smaily_cf7_status';

	/**
	 * Array of all option fields.
	 *
	 * @var array
	 */
	const OPTION_FIELDS = array(
		self::API_CREDENTIALS_OPTION,
		self::CUSTOMER_SYNC_ENABLED_OPTION,
		self::CUSTOMER_SYNC_FIELDS_OPTION,
		self::ABANDONED_CART_STATUS_OPTION,
		self::ABANDONED_CART_CUTOFF_OPTION,
		self::ABANDONED_CART_FIELDS_OPTION,
		self::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
		self::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
		self::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
		self::RSS_LIMIT_OPTION,
		self::RSS_CATEGORY_OPTION,
		self::RSS_SORT_BY_OPTION,
		self::RSS_ORDER_BY_OPTION,
		self::RSS_URL_OPTION,
		self::DATABASE_VERSION_OPTION,
		self::CONTACT_FORM_7_STATUS_OPTION,
	);

	/**
	 * Get API credentials.
	 *
	 * @return array{subdomain: string, username: string, password: string} Smaily API credentials
	 */
	public function get_api_credentials() {
		$credentials = get_option( Smaily_Options::API_CREDENTIALS_OPTION, array() );
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
	 * Get subdomain from API credentials.
	 *
	 *
	 * @return string Smaily subdomain.
	 */
	public function get_subdomain() {
		$credentials = get_option( self::API_CREDENTIALS_OPTION, array() );
		return isset( $credentials['subdomain'] ) ? $credentials['subdomain'] : '';
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
		$cart_status = get_option( self::ABANDONED_CART_STATUS_OPTION, self::ABANDONED_CART_DEFAULT_STATUS );

		return array(
			'customer_sync_enabled'     => get_option( self::CUSTOMER_SYNC_ENABLED_OPTION ),
			'synchronize_additional'    => get_option( self::CUSTOMER_SYNC_FIELDS_OPTION, self::CUSTOMER_SYNC_DEFAULT_FIELDS ),
			'enable_cart'               => $cart_status['enabled'],
			'cart_autoresponder_id'     => $cart_status['autoresponder_id'],
			'cart_cutoff'               => (int) get_option( self::ABANDONED_CART_CUTOFF_OPTION, self::ABANDONED_CART_DEFAULT_CUTOFF ),
			'cart_options'              => get_option( self::ABANDONED_CART_FIELDS_OPTION, self::ABANDONED_CART_DEFAULT_FIELDS ),
			'checkout_checkbox_enabled' => get_option( self::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION ),
			'checkbox_order'            => get_option( self::CHECKOUT_SUBSCRIPTION_POSITION_OPTION, self::CHECKOUT_SUBSCRIPTION_DEFAULT_POSITION ),
			'checkbox_location'         => get_option( self::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION, self::CHECKOUT_SUBSCRIPTION_DEFAULT_LOCATION ),
			'rss_limit'                 => get_option( self::RSS_LIMIT_OPTION, self::RSS_DEFAULT_LIMIT ),
			'rss_category'              => get_option( self::RSS_CATEGORY_OPTION, '' ),
			'rss_order_by'              => get_option( self::RSS_SORT_BY_OPTION, self::RSS_DEFAULT_SORT_BY ),
			'rss_order'                 => get_option( self::RSS_ORDER_BY_OPTION, self::RSS_DEFAULT_ORDER_BY ),
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
		$settings = get_option( self::CONTACT_FORM_7_STATUS_OPTION, array() );
		return array_merge(
			array(
				'autoresponder_id' => 0,
				'is_enabled'       => 0,
			),
			$settings
		);
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

	public static function delete_all_options() {
		foreach ( self::OPTION_FIELDS as $option ) {
			delete_option( $option );
		}
	}
}
