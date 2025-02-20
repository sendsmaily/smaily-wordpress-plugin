<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/admin
 */

namespace Smaily_Admin;

use Smaily_Cypher;
use Smaily_Helper;
use Smaily_Options;
use Smaily_Request;
use Smaily_WC\Rss;
use Smaily_Widget;

class Admin {
	/**
	 * The ID of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Smaily_Options Handler for WordPress Options API.
	 */
	private $options;

	/**
	 * Tabs for the settings page.
	 *
	 * @access private
	 * @var    array $tabs The tabs for the settings page.
	 */
	private $tabs;

	/**
	 * Page settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Smaily_Options $options     Reference to option handler class.
	 * @param string         $plugin_name The name of this plugin.
	 * @param string         $version     The version of this plugin.
	 */
	public function __construct( Smaily_Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->settings    = new Settings( $options );
		$tabs              = array(
			'connection' => array(
				'title'              => __( 'Connection', 'smaily' ),
				'submit_button_text' => $options->has_credentials() ? __( 'Disconnect', 'smaily' ) : __( 'Connect', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'connection',
					),
					''
				),
				'register_settings'  => array( $this->settings, 'register_connection_tab_settings' ),
				'option_group'       => 'smaily_settings_connection',
				'page'               => 'smaily_settings_tab_connection',
			),
		);

		if ( Smaily_Helper::is_woocommerce_active() && $this->options->has_credentials() ) {
			$tabs['customer_sync'] = array(
				'title'              => __( 'Customer Synchronization', 'smaily' ),
				'submit_button_text' => __( 'Save', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'customer_sync',
					),
					''
				),
				'register_settings'  => array( $this->settings, 'register_customer_sync_tab_settings' ),
				'option_group'       => 'smaily_settings_customer_sync',
				'page'               => 'smaily_settings_tab_customer_sync',
			);

			$tabs['abandoned_cart'] = array(
				'title'              => __( 'Abandoned Cart', 'smaily' ),
				'submit_button_text' => __( 'Save', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'abandoned_cart',
					),
					''
				),
				'register_settings'  => array( $this->settings, 'register_abandoned_cart_tab_settings' ),
				'option_group'       => 'smaily_settings_abandoned_cart',
				'page'               => 'smaily_settings_tab_abandoned_cart',
			);

			$tabs['rss'] = array(
				'title'              => __( 'RSS', 'smaily' ),
				'submit_button_text' => __( 'Save', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'rss',
					),
					''
				),
				'register_settings'  => array( $this->settings, 'register_rss_tab_settings' ),
				'option_group'       => 'smaily_settings_rss',
				'page'               => 'smaily_settings_tab_rss',
			);
		}

		$this->tabs = $tabs;
	}

	/**
	 * Render Smaily settings page HTML.
	 *
	 * @return void
	 */
	public function settings_page() {
		add_menu_page( 'Smaily Settings', 'Smaily', 'manage_options', 'smaily-settings', array( $this, 'render_admin_page' ), SMAILY_PLUGIN_URL . '/gfx/icon.png' );
	}

	/**
	 * Render admin page HTML content.
	 *
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_die( 'Insufficient permissions' );
		}

		include_once SMAILY_PLUGIN_PATH . '/admin/partials/smaily-admin-page.php';
	}

	/**
	 * Register the settings tabs and add their sections and fields.
	 *
	 */
	public function settings_init() {
		foreach ( $this->tabs as $tab => $options ) {
			$options['register_settings']( $options['option_group'], $options['page'] );
		}
	}

	/**
	 * Validates API credentials by making a request to Smaily before updating settings.
	 * After successful validation stores the credentials with encrypted password.
	 *
	 * https://developer.wordpress.org/reference/hooks/pre_update_option_option/
	 * @param array $new_value
	 * @param array $old_value
	 * @param string $option
	 */
	public function validate_api_credentials_after_save( $new_value, $old_value, $option ) {
		// Using separate function instead of sanitize callback as the sanitize callback is
		// occasionally executed twice.
		// The flow can be sanitize -> validate after save -> sanitize
		// https://core.trac.wordpress.org/ticket/21989

		if ( $new_value['subdomain'] === ''
			&& $new_value['username'] === ''
			&& $new_value['password'] === ''
		) {
			return $new_value;
		}

		$credentials_valid = self::validate_api_credentials( $new_value['subdomain'], $new_value['username'], $new_value['password'] );
		if ( $credentials_valid[0] === true ) {
			add_settings_error(
				'smaily_messages',
				'credentials_validated',
				'API credentials validated successfully!',
				'success'
			);

			return array(
				'subdomain' => $new_value['subdomain'],
				'username'  => $new_value['username'],
				'password'  => Smaily_Cypher::encrypt( $new_value['password'] ),
			);
		} else {
			switch ( $credentials_valid[1] ) {
				case 404:
					add_settings_error(
						'smaily_messages',
						'invalid_api_credentials',
						'Check subdomain. API credentials validation failed.',
						'error'
					);
					break;
				default:
					add_settings_error(
						'smaily_messages',
						'invalid_api_credentials',
						'API credentials validation failed. Please check your details.',
						'error'
					);
					break;
			}

			return $old_value;
		}
	}

	/**
	 * Validates API credentials by making a request to Smaily.
	 *
	 *
	 * @access private
	 * @param  string $subdomain Smaily subdomain.
	 * @param  string $username  Smaily username.
	 * @param  string $password  Smaily password.
	 * @return array{bool,int}  Success of operation and error code.
	 */
	public static function validate_api_credentials( $subdomain, $username, $password ) {
		Smaily_Request::set_credentials(
			array(
				'subdomain' => $subdomain,
				'username'  => $username,
				'password'  => $password,
			)
		);

		// Validate credentials with get request.
		$request = Smaily_Request::get(
			'workflows',
			array(
				'trigger_type' => 'form_submitted',
			)
		);

		$code = isset( $request['code'] ) ? $request['code'] : 0;
		if ( $code !== 200 ) {
			return array( false, $code );
		}

		return array( true, $code );
	}

	/**
	 * Lists available admin page tabs.
	 *
	 * @return array
	 */
	public function list_admin_page_tabs() {
		return $this->tabs;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 */
	public function enqueue_styles() {
		wp_register_style( $this->plugin_name, SMAILY_PLUGIN_URL . '/admin/css/smaily-admin.css', array(), $this->version, 'all' );
		wp_register_style( $this->plugin_name . '-widget', SMAILY_PLUGIN_URL . '/admin/css/smaily-widget-admin.css', array(), $this->version, 'all' );

		wp_enqueue_style( $this->plugin_name );
		wp_enqueue_style( $this->plugin_name . '-widget' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 */
	public function enqueue_scripts() {
		wp_register_script( $this->plugin_name . '-jscolor', SMAILY_PLUGIN_URL . '/admin/js/jscolor.min.js', array(), $this->version, true );
		wp_register_script( $this->plugin_name, SMAILY_PLUGIN_URL . '/admin/js/smaily-admin.js', array( 'jquery', 'jquery-ui-tabs' ), $this->version, true );
		wp_register_script( $this->plugin_name . '-widget', SMAILY_PLUGIN_URL . '/admin/js/admin-widget.js', array( 'jquery', $this->plugin_name . '-jscolor' ), $this->version, true );

		wp_enqueue_script( $this->plugin_name . '-jscolor' );
		wp_enqueue_script( $this->plugin_name );
		wp_enqueue_script( $this->plugin_name . '-widget' );

		if ( Smaily_Helper::is_woocommerce_active() ) {
			// Make RSS URL accessible in admin .js.
			wp_add_inline_script(
				$this->plugin_name,
				'var smaily_settings = ' . wp_json_encode(
					array(
						'rss_feed_url' => Rss::make_rss_feed_url(),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Adds setting link to plugin
	 *
	 * @param array  $links Default links in plugin page.
	 * @return array Updated array of links
	 */
	public function settings_link( $links ) {
		// receive all current links and add custom link to the list.
		$settings_link = '<a href="admin.php?page=smaily-settings">' . esc_html__( 'Settings', 'smaily' ) . '</a>';
		// Settings before disable.
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Load subscribe widget.
	 *
	 */
	public function smaily_subscription_widget_init() {
		$widget = new Smaily_Widget( $this->options, $this );
		register_widget( $widget );
	}
}
