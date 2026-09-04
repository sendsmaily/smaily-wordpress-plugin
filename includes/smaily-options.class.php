<?php

namespace Smaily_Connect\Includes;

defined( 'ABSPATH' ) || exit;

class Options {
	/**
	 * Default values for subscriber sync fields.
	 *
	 * @var array
	 */
	const SUBSCRIBER_SYNC_DEFAULT_FIELDS = array(
		'store_url'        => true,
		'user_email'       => true,
		'language'         => true,
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
		'language'            => true,
		'first_name'          => false,
		'last_name'           => false,
		'product_base_price'  => false,
		'product_description' => false,
		'product_image_url'   => false,
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
	 * The abandoned-cart status option, normalized to the array shape every
	 * consumer can offset into. Single read path (F3-54) — never call
	 * get_option( ABANDONED_CART_STATUS_OPTION ) and offset it raw.
	 *
	 * @return array{enabled: bool, autoresponder_id: int}
	 */
	public static function abandoned_cart_status() {
		return self::normalize_abandoned_cart_status(
			get_option( self::ABANDONED_CART_STATUS_OPTION, self::ABANDONED_CART_DEFAULT_STATUS )
		);
	}

	/**
	 * Normalize a raw abandoned-cart status option value (F3-54).
	 *
	 * Two shapes exist in the wild for ABANDONED_CART_STATUS_OPTION: the
	 * legacy array {enabled, autoresponder_id}, and a bare boolean the
	 * 3.x Settings/wizard wrote until 3.4.3 — which WordPress stores as
	 * the string '1'/'' and which made every array-offset consumer fatal
	 * on PHP 8 ("Cannot access offset of type string on string", the
	 * Prike 15-minute crash loop). This accepts both, and anything else,
	 * and always returns the array shape.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array{enabled: bool, autoresponder_id: int}
	 */
	public static function normalize_abandoned_cart_status( $raw ) {
		if ( is_array( $raw ) ) {
			return array(
				'enabled'          => ! empty( $raw['enabled'] ),
				'autoresponder_id' => isset( $raw['autoresponder_id'] ) && is_scalar( $raw['autoresponder_id'] )
					? (int) $raw['autoresponder_id']
					: 0,
			);
		}

		// Scalar shape (the pre-3.4.3 Settings wrote a bare boolean; WP
		// stores it as '1'/''). The autoresponder id was never part of
		// this shape — the new path resolves the workflow from the
		// automation-mapping table instead.
		return array(
			'enabled'          => ! empty( $raw ),
			'autoresponder_id' => 0,
		);
	}

	/**
	 * Minimum value the abandoned cart cutoff time can be set to in minutes.
	 *
	 * @var integer
	 */
	const ABANDONED_CART_MIN_CUTOFF = 10;

	/**
	 * Default cart cutoff time in minutes.
	 * Best practice, offering largest potential for conversion.
	 *
	 * @var integer
	 */
	const ABANDONED_CART_DEFAULT_CUTOFF = 30;

	/**
	 * Default position for checkout subscription checkbox.
	 *
	 * @var string
	 */
	const CHECKOUT_SUBSCRIPTION_DEFAULT_POSITION = 'before';

	/**
	 * Default location for checkout subscription checkbox.
	 *
	 * @var string
	 */
	const CHECKOUT_SUBSCRIPTION_DEFAULT_LOCATION = 'order';

	/**
	 * Default number of RSS feed items.
	 *
	 * @var integer
	 */
	const RSS_DEFAULT_LIMIT = 50;

	/**
	 * Default RSS feed store tax rate.
	 *
	 * @var integer
	 */
	const RSS_DEFAULT_TAX_RATE = 0;

	/**
	 * Default RSS feed sort by.
	 *
	 * @var string
	 */
	const RSS_DEFAULT_SORT_BY = 'modified';

	/**
	 * Default RSS feed order by.
	 *
	 * @var string
	 */
	const RSS_DEFAULT_ORDER_BY = 'DESC';

	const API_CREDENTIALS_OPTION                = 'smaily_connect_api_credentials';
	const SUBSCRIBER_SYNC_ENABLED_OPTION        = 'smaily_connect_subscriber_sync_enabled';
	const SUBSCRIBER_SYNC_FIELDS_OPTION         = 'smaily_connect_subscriber_sync_fields';
	const ABANDONED_CART_STATUS_OPTION          = 'smaily_connect_abandoned_cart_status';
	const ABANDONED_CART_CUTOFF_OPTION          = 'smaily_connect_abandoned_cart_cutoff';
	const ABANDONED_CART_FIELDS_OPTION          = 'smaily_connect_abandoned_cart_fields';
	const CHECKOUT_SUBSCRIPTION_ENABLED_OPTION  = 'smaily_connect_checkout_subscription_enabled';
	const CHECKOUT_SUBSCRIPTION_POSITION_OPTION = 'smaily_connect_checkout_subscription_position';
	const CHECKOUT_SUBSCRIPTION_LOCATION_OPTION = 'smaily_connect_checkout_subscription_location';
	const RSS_LIMIT_OPTION                      = 'smaily_connect_rss_limit';
	const RSS_CATEGORY_OPTION                   = 'smaily_connect_rss_category';
	const RSS_SORT_BY_OPTION                    = 'smaily_connect_rss_sort_by';
	const RSS_ORDER_BY_OPTION                   = 'smaily_connect_rss_order_by';
	const RSS_TAX_RATE                          = 'smaily_connect_rss_tax_rate';
	const RSS_URL_OPTION                        = 'smaily_connect_rss_url';
	const DATABASE_VERSION_OPTION               = 'smaily_connect_db_version';
	const CONTACT_FORM_7_STATUS_OPTION          = 'smaily_connect_cf7_status';
	const NOTICE_REGISTRY_OPTION                = 'smaily_connect_notices';

	/**
	 * Array of all option fields.
	 *
	 * @var array
	 */
	const OPTION_FIELDS = array(
		self::API_CREDENTIALS_OPTION,
		self::SUBSCRIBER_SYNC_ENABLED_OPTION,
		self::SUBSCRIBER_SYNC_FIELDS_OPTION,
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
		self::RSS_TAX_RATE,
		self::DATABASE_VERSION_OPTION,
		self::CONTACT_FORM_7_STATUS_OPTION,
		self::NOTICE_REGISTRY_OPTION,
	);

	/**
	 * Checkout subscription checkbox possible positions.
	 *
	 * @var array
	 */
	const CHECKOUT_SUBSCRIPTION_POSITIONS = array(
		'before',
		'after',
	);

	/**
	 * Checkout subscription positions with translatable labels.
	 *
	 * @return array{after: string, before: string}
	 */
	public static function get_checkout_subscription_position_options() {
		return array(
			'before' => __( 'Before', 'smaily-connect' ),
			'after'  => __( 'After', 'smaily-connect' ),
		);
	}

	/**
	 * Checkout subscription checkbox possible locations.
	 *
	 * @var array
	 */
	const CHECKOUT_SUBSCRIPTION_LOCATIONS = array(
		'account',
		'billing',
		'order',
		'shipping',
	);

	/**
	 * Checkout subscription locations with translatable labels.
	 *
	 * @return array{account: string, billing: string, order: string, shipping: string}
	 */
	public static function get_checkout_subscription_location_options() {
		return array(
			'account'  => __( 'Registration form', 'smaily-connect' ),
			'billing'  => __( 'Billing form', 'smaily-connect' ),
			'order'    => __( 'Order notes', 'smaily-connect' ),
			'shipping' => __( 'Shipping form', 'smaily-connect' ),
		);
	}

	/**
	 * Get API credentials.
	 *
	 * @return array{subdomain: string, username: string, password: string} Smaily API credentials
	 */
	public function get_api_credentials() {
		$credentials = get_option( Options::API_CREDENTIALS_OPTION, array() );
		$password    = isset( $credentials['password'] ) ? Cypher::decrypt( $credentials['password'] ) : '';
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

		if ( Helper::is_woocommerce_active() ) {
			$settings['woocommerce'] = $this->get_woocommerce_settings_from_db();
		}

		if ( Helper::is_cf7_active() ) {
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
		$cart_status = self::abandoned_cart_status();

		return array(
			'subscriber_sync_enabled'   => get_option( self::SUBSCRIBER_SYNC_ENABLED_OPTION ),
			'synchronize_additional'    => get_option( self::SUBSCRIBER_SYNC_FIELDS_OPTION, self::SUBSCRIBER_SYNC_DEFAULT_FIELDS ),
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
	 * @return array<int, array{is_enabled: bool, autoresponder_id: int}> Dictionary of form settings with form ID as key.
	 *
	 * @since 1.3.0 Changed the settings structure to dictionary format where
	 * each form has its own settings array. This allows users to manage settings
	 * for each form individually.
	 */
	private function get_cf7_settings_from_db() {
		return get_option( self::CONTACT_FORM_7_STATUS_OPTION, array() );
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
