<?php

namespace Smaily_Admin;

use Smaily_Options;
use Smaily_Request;
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
			'smaily_api_credentials',
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
			'api_credentials',
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
			'smaily_customer_sync_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			'smaily_customer_sync_fields',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_customer_sync_fields' ),
				'default'           => array(
					'user_email'       => true,
					'store_url'        => true,
					'customer_group'   => false,
					'customer_id'      => false,
					'user_dob'         => false,
					'first_registered' => false,
					'first_name'       => false,
					'user_gender'      => false,
					'last_name'        => false,
					'nickname'         => false,
					'user_phone'       => false,
					'site_title'       => false,
				),
			)
		);

		add_settings_section(
			$customer_sync_section,
			__( 'Customer Synchronization', 'smaily' ),
			array( $this->renderer, 'render_customer_sync_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_customer_sync_enabled',
			__( 'Enable Customer Synchronization', 'smaily' ),
			array( $this->renderer, 'render_enabled_field' ),
			$page,
			$customer_sync_section,
			array(
				'option_name' => 'smaily_customer_sync_enabled',
			)
		);

		add_settings_field(
			'smaily_customer_sync_fields',
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
			'smaily_abandoned_cart_status',
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
			'smaily_abandoned_cart_cutoff',
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 10,
			)
		);

		register_setting(
			$option_group,
			'smaily_abandoned_cart_fields',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_abandoned_cart_fields' ),
				'default'           => array(
					'user_email'          => true,
					'store_url'           => true,
					'first_name'          => false,
					'last_name'           => false,
					'product_name'        => false,
					'product_description' => false,
					'product_sku'         => false,
					'product_quantity'    => false,
					'product_base_price'  => false,
					'product_price'       => false,
					'product_images'      => false,
				),
			)
		);

		add_settings_section(
			$abandoned_cart_section,
			__( 'Abandoned Cart', 'smaily' ),
			array( $this->renderer, 'render_abandoned_cart_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_abandoned_cart_status',
			__( 'Enable Abandoned Cart', 'smaily' ),
			array( $this->renderer, 'render_abandoned_cart_status_field' ),
			$page,
			$abandoned_cart_section,
			array(
				'autoresponders' => $this->get_autoresponders(),
			)
		);

		add_settings_field(
			'smaily_abandoned_cart_cutoff',
			__( 'Cart cutoff time (minutes)', 'smaily' ),
			array( $this->renderer, 'render_number_field' ),
			$page,
			$abandoned_cart_section,
			array(
				'option_name' => 'smaily_abandoned_cart_cutoff',
				'min'         => 10,
				'help'        => __( 'Minimum 10 minutes', 'smaily' ),
			)
		);

		add_settings_field(
			'smaily_abandoned_cart_fields',
			__( 'Additional Fields', 'smaily' ),
			array( $this->renderer, 'render_abandoned_additional_fields' ),
			$page,
			$abandoned_cart_section
		);

		$checkout_subscription_section = 'smaily_settings_checkout_subscription_section';
		register_setting(
			$option_group,
			'smaily_checkout_subscription_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			'smaily_checkout_subscription_position',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'before',
			)
		);

		register_setting(
			$option_group,
			'smaily_checkout_subscription_location',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'order_notes',
			)
		);

		add_settings_section(
			$checkout_subscription_section,
			__( 'Checkout subscription', 'smaily' ),
			array( $this->renderer, 'render_checkout_subscription_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_checkout_subscription_enabled',
			__( 'Enable Checkout Subscription', 'smaily' ),
			array( $this->renderer, 'render_enabled_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => 'smaily_checkout_subscription_enabled',
			)
		);

		add_settings_field(
			'smaily_checkout_subscription_position',
			__( 'Position', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => 'smaily_checkout_subscription_position',
				'options'     => array(
					'before' => __( 'Before', 'smaily' ),
					'after'  => __( 'After', 'smaily' ),
				),
			)
		);

		add_settings_field(
			'smaily_checkout_subscription_location',
			__( 'Location', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => 'smaily_checkout_subscription_location',
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
			'smaily_rss_limit',
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 50,
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_category',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_sort_by',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'modified',
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_order_by',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'DESC',
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_url',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Rss::make_rss_feed_url(
					get_option( 'smaily_rss_category' ),
					get_option( 'smaily_rss_limit' ),
					get_option( 'smaily_rss_sort_by' ),
					get_option( 'smaily_rss_order_by' )
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
			'smaily_rss_limit',
			__( 'Limit', 'smaily' ),
			array( $this->renderer, 'render_number_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => 'smaily_rss_limit',
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
			'smaily_rss_category',
			__( 'Product Category', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => 'smaily_rss_category',
				'options'     => array(
					'' => __( 'All', 'smaily' ),
				) + wp_list_pluck( $product_categories, 'name', 'slug' ),
				'help'        => __( 'Show products from specific category.', 'smaily' ),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-category',
			)
		);

		add_settings_field(
			'smaily_rss_sort_by',
			__( 'Sort by', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => 'smaily_rss_sort_by',
				'options'     => array(
					'date'     => __( 'Created At', 'smaily' ),
					'id'       => __( 'ID', 'smaily' ),
					'modified' => __( 'Modified At', 'smaily' ),
					'name'     => __( 'Name', 'smaily' ),
					'rand'     => __( 'Random', 'smaily' ),
					'type'     => __( 'Type', 'smaily' ),
				),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-sort-field',
			)
		);

		add_settings_field(
			'smaily_rss_order_by',
			__( 'Order by', 'smaily' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => 'smaily_rss_order_by',
				'options'     => array(
					'ASC'  => __( 'Ascending', 'smaily' ),
					'DESC' => __( 'Descending', 'smaily' ),
				),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-sort-order',
			)
		);

		add_settings_field(
			'smaily_rss_url',
			__( 'Product RSS feed', 'smaily' ),
			array( $this->renderer, 'render_rss_url' ),
			$page,
			$rss_section
		);
	}

	/**
	 * Make a request to Smaily asking for autoresponders.
	 * Request is authenticated via saved credentials.
	 *
	 * @return array List of autoresponders in format [id => title].
	 */
	private function get_autoresponders() {
		// TODO: Refactor this Request class.
		Smaily_Request::set_credentials( $this->options->get_api_credentials() );

		if ( ! $this->options->has_credentials() ) {
			return array();
		}

		$result = Smaily_Request::get(
			'workflows',
			array(
				'trigger_type' => 'form_submitted',
			)
		);

		if ( empty( $result['body'] ) ) {
			return array();
		}

		$autoresponder_list = array();
		foreach ( $result['body'] as $autoresponder ) {
			$id                        = $autoresponder['id'];
			$title                     = $autoresponder['title'];
			$autoresponder_list[ $id ] = $title;
		}
		return $autoresponder_list;
	}
}
