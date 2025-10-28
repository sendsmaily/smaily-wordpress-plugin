<?php

use Smaily_Connect\Admin;
use Smaily_Connect\Admin\Notices;
use Smaily_Connect\Includes\API;
use Smaily_Connect\Includes\Blocks;
use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Includes\Lifecycle;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\CF7\Admin as Smaily_CF7_Admin;
use Smaily_Connect\Integrations\CF7\Public_Base as Smaily_CF7_Public;
use Smaily_Connect\Integrations\Elementor\Admin as Elementor_Admin;
use Smaily_Connect\Integrations\WooCommerce\Cart;
use Smaily_Connect\Integrations\WooCommerce\Cron;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;
use Smaily_Connect\Integrations\WooCommerce\Rss;
use Smaily_Connect\Integrations\WooCommerce\Subscriber_Synchronization;
use Smaily_Connect\Public_Base;

class Smaily_Connect {
	/**
	 * The unique identifier of this plugin.
	 *
	 *
	 * @access protected
	 * @var    string    $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 *
	 * @access protected
	 * @var    string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Admin class instance.
	 *
	 *
	 * @access private
	 * @var    Admin $admin Admin class instance.
	 */
	protected $admin;

	/**
	 * Admin_Notices class instance.
	 *
	 *
	 * @access private
	 * @var    Notices $notices Admin_Notices class instance.
	 */
	protected $admin_notices;

	/**
	 * Blocks class instance.
	 *
	 *
	 * @access private
	 * @var    Blocks $blocks Blocks class instance.
	 */
	protected $blocks;

	/**
	 * API class instance.
	 *
	 *
	 * @access private
	 * @var    API $api API class instance.
	 */
	protected $api;

	/**
	 * Lifecycle class instance.
	 *
	 *
	 * @access private
	 * @var    Lifecycle $lifecycle Lifecycle class instance.
	 */
	protected $lifecycle;

	/**
	 * Public_Base class instance.
	 *
	 *
	 * @access private
	 * @var    Public_Base $public_base Public_Base class instance.
	 */
	protected $public_base;

	/**
	 * WooCommerce Cart class instance.
	 *
	 *
	 * @access private
	 * @var    Cart $wc_cart WooCommerce Cart class instance.
	 */
	protected $wc_cart;

	/**
	 * WooCommerce Cron class instance.
	 *
	 *
	 * @access private
	 * @var    Cron $wc_cron WooCommerce Cron class instance.
	 */
	protected $wc_cron;

	/**
	 * WooCommerce Profile_Settings class instance.
	 *
	 *
	 * @access private
	 * @var    Profile_Settings $wc_profile_settings WooCommerce Profile_Settings class instance.
	 */
	protected $wc_profile_settings;


	/**
	 * WooCommerce Rss class instance.
	 * @access private
	 * @var    Rss $wc_rss WooCommerce Rss class instance.
	 */
	protected $wc_rss;

	/**
	 * WooCommerce Subscriber_Synchronization class instance.
	 *
	 *
	 * @access private
	 * @var    Subscriber_Synchronization $wc_subscriber_synchronization WooCommerce Subscriber_Synchronization class instance.
	 */
	protected $wc_subscriber_synchronization;

	/**
	 * Contact Form 7 Admin class instance.
	 *
	 *
	 * @access private
	 * @var    Smaily_CF7_Admin $cf7_admin Contact Form 7 Admin class instance.
	 */
	protected $cf7_admin;

	/**
	 * Contact Form 7 Public_Base class instance.
	 *
	 *
	 * @access private
	 * @var    Smaily_CF7_Public $cf7_public Contact Form 7 Public_Base class instance.
	 */
	protected $cf7_public;

	/**
	 * Elementor Admin class instance.
	 *
	 *
	 * @access private
	 * @var    Elementor_Admin $elementor Contact Form 7 Public_Base class instance.
	 */
	protected $elementor;

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access protected
	 * @var    Options Handler for WordPress Options API.
	 */
	private $options;


	/**
	 * Constructor.
	 *
	 * @param string $name    The name of the plugin.
	 * @param string $version The version of the plugin.
	 */
	public function __construct( $name, $version ) {
		$this->plugin_name = $name;
		$this->version     = $version;
		$this->load_dependencies();
		$this->init_classes();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Admin_Notices. Defines the notice management functionality on the admin side.
	 * - Admin.         Defines all hooks for the admin area.
	 * - Block.         Define the Gutenberg newsletter subscription block functionality.
	 * - Helper.        Defines helper methods for various purposes.
	 * - Logger.        Defines the logging functionality.
	 * - Options.       Defines the database related queries of Options API.
	 * - Public_Base.   Defines all hooks for the public side of the site.
	 * - Widget.        Defines the widget functionality.
	 *
	 * Woocommerce related dependencies
	 *
	 * - Integrations\WooCommerce\Cart                         Manages status of user cart in abandoned carts table.
	 * - Integrations\WooCommerce\Cron.                        Handles data synchronization between Smaily and WooCommerce.
	 * - Integrations\WooCommerce\Data_Handler.                Handles woocommerce related data retrieval
	 * - Integrations\WooCommerce\Data_Prepare.                Class for preparing Woocommerce related data
	 * - Integrations\WooCommerce\Profile_Settings.            Adds and controls WordPress/Woocommerce fields.
	 * - Integrations\WooCommerce\Rss.                         Handles RSS generation for Smaily newsletter.
	 * - Integrations\WooCommerce\Subscriber_Synchronization   Defines functionality for user subscriptions
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * Contact Form 7 related dependencies
	 *
	 * - Integrations\CF7\Admin            Defines all hooks for the admin area of contact form 7
	 * - Integrations\CF7\Public_Base      Defines the public facing functionality
	 * - Integrations\CF7\Service          Defines the logic to display a block under Contact Form 7 integration section.
	 *
	 * @access private
	 */
	private function load_dependencies() {
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/smaily-admin-notices.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/smaily-admin-renderer.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/smaily-admin-sanitizer.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/smaily-admin-settings.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/smaily-admin.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'blocks/newsletter-signup/smaily-integration.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-api.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-blocks.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-client.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-cypher.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-helper.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-logger.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-notice-registry.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-options.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-widget.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'public/smaily-public.class.php';

		$this->options = new Options();

		if ( Helper::is_woocommerce_active() ) {
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/cart.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/cron.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/data-handler.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/profile-settings.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/rss.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/subscriber-synchronization.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/helper.class.php';
		}

		if ( Helper::is_cf7_active() ) {
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/cf7/admin.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/cf7/public.class.php';
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/cf7/service.class.php';
		}

		if ( Helper::is_elementor_active() ) {
			require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/elementor/admin.class.php';
		}
	}

	/**
	 * Load the classes for the plugin and initialize them.
	 *
	 * @access private
	 */
	private function init_classes() {
		$this->admin = new Admin( $this->options, $this->plugin_name, $this->version );
		$this->admin->register_hooks();

		$this->admin_notices = new Notices();
		$this->admin_notices->register_hooks();

		$this->api = new API( $this->options, $this->plugin_name );
		$this->api->register_hooks();

		$this->blocks = new Blocks( $this->options, $this->plugin_name, $this->version );
		$this->blocks->register_hooks();

		$this->lifecycle = new Lifecycle();
		$this->lifecycle->register_hooks();

		$this->public_base = new Public_Base( $this->options, $this->plugin_name, $this->version );
		$this->public_base->register_hooks();

		if ( Helper::is_woocommerce_active() ) {
			$this->wc_cart = new Cart();
			$this->wc_cart->register_hooks();

			$this->wc_cron = new Cron( $this->options );
			$this->wc_cron->register_hooks();

			$this->wc_rss = new Rss();
			$this->wc_rss->register_hooks();

			$this->wc_subscriber_synchronization = new Subscriber_Synchronization( $this->options );
			$this->wc_subscriber_synchronization->register_hooks();

			if ( $this->options->has_credentials() ) {
				$this->wc_profile_settings = new Profile_Settings();
				$this->wc_profile_settings->register_hooks();
			}
		}

		if ( Helper::is_cf7_active() ) {
			$this->cf7_admin = new Smaily_CF7_Admin( $this->options, $this->plugin_name, $this->version );
			$this->cf7_admin->register_hooks();
		}

		if ( Helper::is_cf7_active() ) {
			$this->cf7_public = new Smaily_CF7_Public( $this->options );
			$this->cf7_public->register_hooks();
		}

		if ( Helper::is_elementor_active() ) {
			$this->elementor = new Elementor_Admin( $this->plugin_name );
			$this->elementor->register_hooks();
		}
	}
}
