<?php

namespace Smaily_Connect;

use Smaily_Connect\Admin\Settings;
use Smaily_Connect\Includes\Cypher;
use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;
use Smaily_Connect\Includes\Widget;
use Smaily_Connect\Integrations\WooCommerce\Rss;

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
	 * @var    Options Handler for WordPress Options API.
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
	 * @param Options $options     Reference to option handler class.
	 * @param string         $plugin_name The name of this plugin.
	 * @param string         $version     The version of this plugin.
	 */
	public function __construct( Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->settings    = new Settings( $options );
	}

	/**
	 * Register all of the hooks related to the admin area functionality.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_menu', array( $this, 'settings_page' ) );
		add_action( 'pre_update_option_' . Options::API_CREDENTIALS_OPTION, array( $this, 'validate_api_credentials_after_save' ), 10, 3 );
		add_action( 'widgets_init', array( $this, 'smaily_subscription_widget_init' ) );
		add_action( 'wp_ajax_smaily_admin_save', array( $this, 'smaily_admin_save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SMAILY_CONNECT_PLUGIN_FILE ), array( $this, 'settings_link' ) );
	}

	/**
	 * Render Smaily settings page HTML.
	 *
	 * @return void
	 */
	public function settings_page() {
		add_menu_page(
			__( 'Smaily Settings', 'smaily-connect' ),
			'Smaily',
			'manage_options',
			$this->plugin_name,
			array( $this, 'render_admin_page' ),
			'data:image/svg+xml;base64,' . base64_encode( file_get_contents( SMAILY_CONNECT_PLUGIN_PATH . '/gfx/icon.svg' ) )
		);
	}

	/**
	 * Render admin page HTML content.
	 *
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_die( 'Insufficient permissions' );
		}

		include_once SMAILY_CONNECT_PLUGIN_PATH . '/admin/partials/smaily-admin-page.php';
	}

	/**
	 * Register the settings tabs and add their sections and fields.
	 *
	 */
	public function settings_init() {
		foreach ( $this->list_admin_page_tabs() as $tab => $options ) {
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

		$credentials_valid = $this->validate_api_credentials( $new_value['subdomain'], $new_value['username'], $new_value['password'] );
		if ( $credentials_valid[0] === true ) {
			add_settings_error(
				'smaily_connect_messages',
				'credentials_validated',
				__( 'API credentials validated successfully!', 'smaily-connect' ),
				'success'
			);

			return array(
				'subdomain' => $new_value['subdomain'],
				'username'  => $new_value['username'],
				'password'  => Cypher::encrypt( $new_value['password'] ),
			);
		} else {
			switch ( $credentials_valid[1] ) {
				case 404:
					add_settings_error(
						'smaily_connect_messages',
						'invalid_api_credentials',
						__( 'Check subdomain. API credentials validation failed.', 'smaily-connect' ),
						'error'
					);
					break;
				default:
					add_settings_error(
						'smaily_connect_messages',
						'invalid_api_credentials',
						__( 'API credentials validation failed. Please check your details.', 'smaily-connect' ),
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
	public function validate_api_credentials( $subdomain, $username, $password ) {
		$request  = new Smaily_Client( $subdomain, $username, $password );
		$response = $request->list_autoresponders();

		$code = isset( $response['code'] ) ? $response['code'] : 0;
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
		if ( ! isset( $this->tabs ) ) {
			$tabs = array(
				'connection' => array(
					'title'              => __( 'Connection', 'smaily-connect' ),
					'submit_button_text' => $this->options->has_credentials() ? __( 'Disconnect', 'smaily-connect' ) : __( 'Make a connection', 'smaily-connect' ),
					'url'                => add_query_arg(
						array(
							'page' => $this->plugin_name,
							'tab'  => 'connection',
						),
						''
					),
					'register_settings'  => array( $this->settings, 'register_connection_tab_settings' ),
					'option_group'       => 'smaily_connect_connection',
					'page'               => 'smaily_connect_tab_connection',
				),
			);

			if ( $this->options->has_credentials() ) {
				$tabs['tutorial'] = array(
					'title'             => __( 'Getting started', 'smaily-connect' ),
					'url'               => add_query_arg(
						array(
							'page' => $this->plugin_name,
							'tab'  => 'tutorial',
						),
						''
					),
					'register_settings' => array( $this->settings, 'register_tutorial_tab_settings' ),
					'option_group'      => 'smaily_connect_tutorial',
					'page'              => 'smaily_connect_tab_tutorial',
				);
			}

			if ( Helper::is_woocommerce_active() && $this->options->has_credentials() ) {
				$tabs['subscriber_sync'] = array(
					'title'              => __( 'Subscriber Synchronization', 'smaily-connect' ),
					'submit_button_text' => __( 'Save', 'smaily-connect' ),
					'url'                => add_query_arg(
						array(
							'page' => $this->plugin_name,
							'tab'  => 'subscriber_sync',
						),
						''
					),
					'register_settings'  => array( $this->settings, 'register_subscriber_sync_tab_settings' ),
					'option_group'       => 'smaily_connect_subscriber_sync',
					'page'               => 'smaily_connect_tab_subscriber_sync',
				);

				$tabs['abandoned_cart'] = array(
					'title'              => __( 'Abandoned Cart', 'smaily-connect' ),
					'submit_button_text' => __( 'Save', 'smaily-connect' ),
					'url'                => add_query_arg(
						array(
							'page' => $this->plugin_name,
							'tab'  => 'abandoned_cart',
						),
						''
					),
					'register_settings'  => array( $this->settings, 'register_abandoned_cart_tab_settings' ),
					'option_group'       => 'smaily_connect_abandoned_cart',
					'page'               => 'smaily_connect_tab_abandoned_cart',
				);

				$tabs['rss'] = array(
					'title'              => __( 'RSS', 'smaily-connect' ),
					'submit_button_text' => __( 'Save', 'smaily-connect' ),
					'url'                => add_query_arg(
						array(
							'page' => $this->plugin_name,
							'tab'  => 'rss',
						),
						''
					),
					'register_settings'  => array( $this->settings, 'register_rss_tab_settings' ),
					'option_group'       => 'smaily_connect_rss',
					'page'               => 'smaily_connect_tab_rss',
				);
			}

			$this->tabs = $tabs;
		}

		return $this->tabs;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 */
	public function enqueue_styles() {
		wp_register_style( $this->plugin_name, SMAILY_CONNECT_PLUGIN_URL . '/admin/css/smaily-admin.css', array(), $this->version, 'all' );
		wp_register_style( $this->plugin_name . '-widget', SMAILY_CONNECT_PLUGIN_URL . '/admin/css/smaily-widget-admin.css', array(), $this->version, 'all' );

		wp_enqueue_style( $this->plugin_name . '-fonts', SMAILY_CONNECT_PLUGIN_URL . '/admin/css/fonts.css', array(), null );
		wp_enqueue_style( $this->plugin_name );
		wp_enqueue_style( $this->plugin_name . '-widget' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 */
	public function enqueue_scripts() {
		wp_register_script( $this->plugin_name . '-jscolor', SMAILY_CONNECT_PLUGIN_URL . '/admin/js/jscolor.min.js', array(), $this->version, true );
		wp_register_script( $this->plugin_name, SMAILY_CONNECT_PLUGIN_URL . '/admin/js/smaily-admin.js', array( 'jquery', 'jquery-ui-tabs' ), $this->version, true );
		wp_register_script( $this->plugin_name . '-widget', SMAILY_CONNECT_PLUGIN_URL . '/admin/js/admin-widget.js', array( 'jquery', $this->plugin_name . '-jscolor' ), $this->version, true );

		wp_enqueue_script( $this->plugin_name . '-jscolor' );
		wp_enqueue_script( $this->plugin_name );
		wp_enqueue_script( $this->plugin_name . '-widget' );

		if ( Helper::is_woocommerce_active() ) {
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
		$settings_link = '<a href="admin.php?page=' . esc_attr( $this->plugin_name ) . '">' . esc_html__( 'Settings', 'smaily-connect' ) . '</a>';
		// Settings before disable.
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Load subscribe widget.
	 *
	 */
	public function smaily_subscription_widget_init() {
		$widget = new Widget( $this->options );
		register_widget( $widget );
	}
}
