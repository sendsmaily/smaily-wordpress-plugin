<?php

namespace Smaily_WP_Connect\Admin;

use Smaily_WP_Connect\Includes\Helper;
use Smaily_WP_Connect\Includes\Options;
use Smaily_WP_Connect\Integrations\WooCommerce\Rss;

class Settings {
	/**
	 * Smaily options.
	 * @var Options
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
	 * @param Options $options
	 */
	public function __construct( Options $options ) {
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
			Options::API_CREDENTIALS_OPTION,
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
			__( 'Connection', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_connection_section_header' ),
			$page
		);

		add_settings_field(
			Options::API_CREDENTIALS_OPTION,
			__( 'Credentials', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_credentials_fields' ),
			$page,
			$section
		);
	}

	/**
	 * Registers the tutorial tab sections.
	 *
	 */
	public function register_tutorial_tab_settings( $option_group, $page ) {
		add_settings_section(
			'smaily_settings_subscriber_collection_section',
			__( 'Setting up subscriber collection', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_tutorial_subscriber_collection_section' ),
			$page
		);

		if ( Helper::is_woocommerce_active() ) {
			add_settings_section(
				'smaily_settings_woocommerce_section',
				__( 'WooCommerce Integration', 'smaily-wp-connect' ),
				array( $this->renderer, 'render_tutorial_woocommerce_section' ),
				$page
			);
		}
	}

	/**
	 * Registers subscriber synchronization related configuration options.
	 *
	 * @return void
	 */
	public function register_subscriber_sync_tab_settings( $option_group, $page ) {
		$subscriber_sync_section = 'smaily_settings_subscriber_sync_section';

		register_setting(
			$option_group,
			Options::SUBSCRIBER_SYNC_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			Options::SUBSCRIBER_SYNC_FIELDS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_subscriber_sync_fields' ),
				'default'           => Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS,
			)
		);

		add_settings_section(
			$subscriber_sync_section,
			__( 'Daily Automatic Subscriber Synchronization', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_subscriber_sync_section_header' ),
			$page
		);

		add_settings_field(
			Options::SUBSCRIBER_SYNC_ENABLED_OPTION,
			__( 'Enable Subscriber Synchronization', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_enabled_field' ),
			$page,
			$subscriber_sync_section,
			array(
				'option_name' => Options::SUBSCRIBER_SYNC_ENABLED_OPTION,
				'help'        => __( 'The cron job will run once a day.', 'smaily-wp-connect' ),
			)
		);

		add_settings_field(
			Options::SUBSCRIBER_SYNC_FIELDS_OPTION,
			__( 'Additional Fields', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_sync_additional_fields' ),
			$page,
			$subscriber_sync_section
		);

		$checkout_subscription_section = 'smaily_settings_checkout_subscription_section';
		register_setting(
			$option_group,
			Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Options::CHECKOUT_SUBSCRIPTION_DEFAULT_POSITION,
			)
		);

		register_setting(
			$option_group,
			Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Options::CHECKOUT_SUBSCRIPTION_DEFAULT_LOCATION,
			)
		);

		add_settings_section(
			$checkout_subscription_section,
			__( 'Newsletter Subscription On Checkout', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_checkout_subscription_section_header' ),
			$page
		);

		add_settings_field(
			Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
			__( 'Enable Checkout Subscription', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_enabled_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION,
			)
		);

		add_settings_field(
			Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
			__( 'Position', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION,
				'options'     => Options::get_checkout_subscription_position_options(),
			)
		);

		add_settings_field(
			Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
			__( 'Location', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION,
				'options'     => Options::get_checkout_subscription_location_options(),
			)
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
			Options::ABANDONED_CART_STATUS_OPTION,
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
			Options::ABANDONED_CART_CUTOFF_OPTION,
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Options::ABANDONED_CART_DEFAULT_CUTOFF,
			)
		);

		register_setting(
			$option_group,
			Options::ABANDONED_CART_FIELDS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_abandoned_cart_fields' ),
				'default'           => Options::ABANDONED_CART_DEFAULT_FIELDS,
			)
		);

		add_settings_section(
			$abandoned_cart_section,
			__( 'Abandoned Cart Reminder Emails', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_abandoned_cart_section_header' ),
			$page
		);

		add_settings_field(
			Options::ABANDONED_CART_STATUS_OPTION,
			__( 'Enable Abandoned Cart', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_abandoned_cart_status_field' ),
			$page,
			$abandoned_cart_section
		);

		add_settings_field(
			Options::ABANDONED_CART_CUTOFF_OPTION,
			__( 'Cart cutoff time (minutes)', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_number_field' ),
			$page,
			$abandoned_cart_section,
			array(
				'option_name' => Options::ABANDONED_CART_CUTOFF_OPTION,
				'min'         => Options::ABANDONED_CART_DEFAULT_CUTOFF,
				'help'        => __( 'Time in minutes after which the cart is considered abandoned. Minimum 10 minutes.', 'smaily-wp-connect' ),
			)
		);

		add_settings_field(
			Options::ABANDONED_CART_FIELDS_OPTION,
			__( 'Additional Fields', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_abandoned_additional_fields' ),
			$page,
			$abandoned_cart_section
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
			Options::RSS_LIMIT_OPTION,
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Options::RSS_DEFAULT_LIMIT,
			)
		);

		register_setting(
			$option_group,
			Options::RSS_CATEGORY_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			$option_group,
			Options::RSS_SORT_BY_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Options::RSS_DEFAULT_SORT_BY,
			)
		);

		register_setting(
			$option_group,
			Options::RSS_ORDER_BY_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Options::RSS_DEFAULT_ORDER_BY,
			)
		);

		register_setting(
			$option_group,
			Options::RSS_URL_OPTION,
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Rss::make_rss_feed_url(
					get_option( Options::RSS_CATEGORY_OPTION, null ),
					get_option( Options::RSS_LIMIT_OPTION, null ),
					get_option( Options::RSS_SORT_BY_OPTION, null ),
					get_option( Options::RSS_ORDER_BY_OPTION, null )
				),
			)
		);

		add_settings_section(
			$rss_section,
			__( 'Smaily RSS feed', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_rss_section_header' ),
			$page
		);

		add_settings_field(
			Options::RSS_LIMIT_OPTION,
			__( 'Limit', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_number_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Options::RSS_LIMIT_OPTION,
				'min'         => 1,
				'max'         => 250,
				'help'        => __( 'Limit how many products you will add to your field. Maximum 250.', 'smaily-wp-connect' ),
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
			Options::RSS_CATEGORY_OPTION,
			__( 'Product Category', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Options::RSS_CATEGORY_OPTION,
				'options'     => array(
					'' => __( 'All', 'smaily-wp-connect' ),
				) + wp_list_pluck( $product_categories, 'name', 'slug' ),
				'help'        => __( 'Show products from specific category.', 'smaily-wp-connect' ),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-category',
			)
		);

		add_settings_field(
			Options::RSS_SORT_BY_OPTION,
			__( 'Sort by', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Options::RSS_SORT_BY_OPTION,
				'options'     => array(
					'modified' => __( 'Modified At', 'smaily-wp-connect' ),
					'date'     => __( 'Created At', 'smaily-wp-connect' ),
					'id'       => __( 'ID', 'smaily-wp-connect' ),
					'name'     => __( 'Name', 'smaily-wp-connect' ),
					'rand'     => __( 'Random', 'smaily-wp-connect' ),
					'type'     => __( 'Type', 'smaily-wp-connect' ),
				),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-sort-field',
			)
		);

		add_settings_field(
			Options::RSS_ORDER_BY_OPTION,
			__( 'Order by', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_select_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => Options::RSS_ORDER_BY_OPTION,
				'options'     => array(
					'ASC'  => __( 'Ascending', 'smaily-wp-connect' ),
					'DESC' => __( 'Descending', 'smaily-wp-connect' ),
				),
				'class'       => 'smaily-rss-options',
				'id'          => 'smaily-rss-sort-order',
			)
		);

		add_settings_field(
			Options::RSS_URL_OPTION,
			__( 'Product RSS feed', 'smaily-wp-connect' ),
			array( $this->renderer, 'render_rss_url' ),
			$page,
			$rss_section
		);
	}
}
