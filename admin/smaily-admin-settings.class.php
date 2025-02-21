<?php
/**
 * Register the settings tabs and add their sections and fields.
 *
 * @package    Smaily
 * @subpackage Smaily/admin
 */

namespace Smaily_Admin;

use Smaily_Options;
use Smaily_WC\Rss;

class Settings {
	/**
	 * Smaily options.
	 * @var Smaily_Options
	 */
	private $options;

	/**
	 * HTML renderer.
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * User input sanitizer.
	 * @var Sanitizer
	 */
	private $sanitizer;

	/**
	 * Class constructor.
	 *
	 * @param \Smaily_Options $options
	 */
	public function __construct( Smaily_Options $options ) {
		$this->options   = $options;
		$this->renderer  = new Renderer( $options );
		$this->sanitizer = new Sanitizer();
	}

	/**
	 * Register the connection tab settings fields.
	 *
	 */
	public function register_connection_tab_settings( $option_group, $page ) {
		$section = 'smaily_settings_connection_section';

		register_setting(
			$option_group,
			Smaily_Options::API_CREDENTIALS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_api_credentials' ),
				'default'           => array(
					'subdomain' => '',
					'username'  => '',
					'password'  => '',
				),
			)
		);

		add_settings_section(
			$section,
			__( 'Connection', 'smaily' ),
			array( $this->renderer, 'render_connection_section_header' ),
			$page
		);

		add_settings_field(
			Smaily_Options::API_CREDENTIALS_OPTION,
			__( 'Credentials', 'smaily' ),
			array( $this->renderer, 'render_credentials_fields' ),
			$page,
			$section
		);
	}

	/**
	 * Registers customer synchronization related configuration options.
	 *
	 * @return void
	 */
	public function register_customer_sync_tab_settings( $option_group, $page ) {
		$customer_sync_section = 'smaily_settings_customer_sync_section';

		register_setting(
			$option_group,
			Smaily_Options::CUSTOMER_SYNC_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_customer_sync_fields' ),
				'default'           => Smaily_Options::CUSTOMER_SYNC_DEFAULT_FIELDS,
			)
		);

		add_settings_section(
			$customer_sync_section,
			__( 'Customer Synchronization', 'smaily' ),
			array( $this->renderer, 'render_customer_sync_section_header' ),
			$page
		);

		add_settings_field(
			Smaily_Options::CUSTOMER_SYNC_ENABLED_OPTION,
			__( 'Enable Customer Synchronization', 'smaily' ),
			array( $this->renderer, 'render_enabled_field' ),
			$page,
			$customer_sync_section,
			array(
				'option_name' => Smaily_Options::CUSTOMER_SYNC_ENABLED_OPTION,
			)
		);

		add_settings_field(
			Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION,
			__( 'Additional Fields', 'smaily' ),
			array( $this->renderer, 'render_sync_additional_fields' ),
			$page,
			$customer_sync_section
		);
	}

	/**
	 * Registers abandoned cart related configuration options.
	 *
	 * @return void
	 */
	public function register_abandoned_cart_tab_settings( $option_group, $page ) {
		$abandoned_cart_section = 'smaily_settings_abandoned_cart_section';

		register_setting(
			$option_group,
			Smaily_Options::ABANDONED_CART_STATUS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_abandoned_cart_status' ),
				'default'           => array(
					'enabled'          => false,
					'autoresponder_id' => '',
				),
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::ABANDONED_CART_CUTOFF_OPTION,
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Smaily_Options::ABANDONED_CART_DEFAULT_CUTOFF,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::ABANDONED_CART_FIELDS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_abandoned_cart_fields' ),
				'default'           => Smaily_Options::ABANDONED_CART_DEFAULT_FIELDS,
			)
		);

		add_settings_section(
			$abandoned_cart_section,
			__( 'Abandoned Cart', 'smaily' ),
			array( $this->renderer, 'render_abandoned_cart_section_header' ),
			$page
		);

		add_settings_field(
			Smaily_Options::ABANDONED_CART_STATUS_OPTION,
			__( 'Enable Abandoned Cart', 'smaily' ),
			array( $this->renderer, 'render_abandoned_cart_status_field' ),
			$page,
			$abandoned_cart_section,
			array(
				'autoresponders' => Admin::get_autoresponders( $this->options ),
			)
		);

		add_settings_field(
			Smaily_Options::ABANDONED_CART_CUTOFF_OPTION,
			__( 'Cart cutoff time (minutes)', 'smaily' ),
			array( $this->renderer, 'render_number_field' ),
			$page,
			$abandoned_cart_section,
			array(
				'option_name' => Smaily_Options::ABANDONED_CART_CUTOFF_OPTION,
				'min'         => Smaily_Options::ABANDONED_CART_DEFAULT_CUTOFF,
				'help'        => __( 'Minimum 10 minutes', 'smaily' ),
			)
		);

		add_settings_field(
			Smaily_Options::ABANDONED_CART_FIELDS_OPTION,
			__( 'Additional Fields', 'smaily' ),
			array( $this->renderer, 'render_abandoned_additional_fields' ),
			$page,
			$abandoned_cart_section
		);

		$checkout_subscription_section = 'smaily_settings_checkout_subscription_section';
		register_setting(
			$option_group,
			Smaily_Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Smaily_Options::CHECKOUT_SUBSCRIPTION_DEFAULT_POSITION,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Smaily_Options::CHECKOUT_SUBSCRIPTION_DEFAULT_LOCATION,
			)
		);

		add_settings_section(
			$checkout_subscription_section,
			__( 'Checkout subscription', 'smaily' ),
			array( $this->renderer, 'render_checkout_subscription_section_header' ),
			$page
		);

		add_settings_field(
			Smaily_Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
			__( 'Enable Checkout Subscription', 'smaily' ),
			array( $this->renderer, 'render_enabled_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => Smaily_Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
			)
		);

		add_settings_field(
			Smaily_Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
			__( 'Position', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => Smaily_Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
				'options'     => array(
					'before' => __( 'Before', 'smaily' ),
					'after'  => __( 'After', 'smaily' ),
				),
			)
		);

		add_settings_field(
			Smaily_Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
			__( 'Location', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => Smaily_Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
				'options'     => array(
					'order_notes'                => __( 'Order notes', 'smaily' ),
					'checkout_billing_form'      => __( 'Billing form', 'smaily' ),
					'checkout_shipping_form'     => __( 'Shipping form', 'smaily' ),
					'checkout_registration_form' => __( 'Registration form', 'smaily' ),
				),
			)
		);
	}

	/**
	 * Registers RSS-feed related configuration options.
	 *
	 * @return void
	 */
	public function register_rss_tab_settings( $option_group, $page ) {
		$rss_section = 'smaily_settings_rss_section';

		register_setting(
			$option_group,
			Smaily_Options::RSS_LIMIT_OPTION,
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Smaily_Options::RSS_DEFAULT_LIMIT,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::RSS_CATEGORY_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::RSS_SORT_BY_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Smaily_Options::RSS_DEFAULT_SORT_BY,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::RSS_ORDER_BY_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Smaily_Options::RSS_DEFAULT_ORDER_BY,
			)
		);

		register_setting(
			$option_group,
			Smaily_Options::RSS_URL_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Rss::make_rss_feed_url(
					get_option( Smaily_Options::RSS_CATEGORY_OPTION, null ),
					get_option( Smaily_Options::RSS_LIMIT_OPTION, null ),
					get_option( Smaily_Options::RSS_SORT_BY_OPTION, null ),
					get_option( Smaily_Options::RSS_ORDER_BY_OPTION, null )
				),
			)
		);

		add_settings_section(
			$rss_section,
			__( 'Smaily RSS feed', 'smaily' ),
			array( $this->renderer, 'render_rss_section_header' ),
			$page
		);

		add_settings_field(
			Smaily_Options::RSS_LIMIT_OPTION,
			__( 'Limit', 'smaily' ),
			array( $this->renderer, 'render_number_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Smaily_Options::RSS_LIMIT_OPTION,
				'min'         => 1,
				'max'         => 250,
				'help'        => __( 'Limit how many products you will add to your field. Maximum 250.', 'smaily' ),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-limit',
			)
		);

		$product_categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'orderby'    => 'name',
				'order'      => 'asc',
				'hide_empty' => false,
			)
		);

		add_settings_field(
			Smaily_Options::RSS_CATEGORY_OPTION,
			__( 'Product Category', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Smaily_Options::RSS_CATEGORY_OPTION,
				'options'     => array(
					'' => __( 'All', 'smaily' ),
				) + wp_list_pluck( $product_categories, 'name', 'slug' ),
				'help'        => __( 'Show products from specific category.', 'smaily' ),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-category',
			)
		);

		add_settings_field(
			Smaily_Options::RSS_SORT_BY_OPTION,
			__( 'Sort by', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Smaily_Options::RSS_SORT_BY_OPTION,
				'options'     => array(
					'modified' => __( 'Modified At', 'smaily' ),
					'date'     => __( 'Created At', 'smaily' ),
					'id'       => __( 'ID', 'smaily' ),
					'name'     => __( 'Name', 'smaily' ),
					'rand'     => __( 'Random', 'smaily' ),
					'type'     => __( 'Type', 'smaily' ),
				),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-sort-field',
			)
		);

		add_settings_field(
			Smaily_Options::RSS_ORDER_BY_OPTION,
			__( 'Order by', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Smaily_Options::RSS_ORDER_BY_OPTION,
				'options'     => array(
					'ASC'  => __( 'Ascending', 'smaily' ),
					'DESC' => __( 'Descending', 'smaily' ),
				),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-sort-order',
			)
		);

		add_settings_field(
			Smaily_Options::RSS_URL_OPTION,
			__( 'Product RSS feed', 'smaily' ),
			array( $this->renderer, 'render_rss_url' ),
			$page,
			$rss_section
		);
	}
}
