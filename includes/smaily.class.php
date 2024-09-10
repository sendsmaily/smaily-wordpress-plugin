<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily
{

    /**
     * Handler for storing/retrieving data via Options API.
     *
     *
     * @access protected
     * @var    Smaily_Options Handler for WordPress Options API.
     */
    private $options;

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     *
     * @access protected
     * @var    Smaily_Loader  $loader Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * Class responsible for all API requests to Smaily servers
     *
     *
     * @access protected
     * @var    Smaily_Request  $request Manages all http requests.
     */
    protected $request;

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
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     */
    public function __construct()
    {
        $this->version     = SMAILY_PLUGIN_VERSION;
        $this->plugin_name = 'smaily';
        $this->load_dependencies();
        $this->set_locale();
        $this->define_hooks();
        add_action('init', [$this, 'init_blocks']);
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - Smaily_Helper.    Defines helper methods for various purposes.
     * - Smaily_Logger.    Defines the logging functionality.
     * - Smaily_Admin.     Defines all hooks for the admin area.
     * - Smaily_Block.     Define the Gutenberg newsletter subscription block functionality.
     * - Smaily_i18n.      Defines internationalization functionality.
     * - Smaily_Options.   Defines the database related queries of Options API.
     * - Smaily_Request.   Defines the request making functionality.
     * - Smaily_Template.  Defines the templating making functionality.
     * - Smaily_Widget.    Defines the widget functionality.
     * - Smaily_Public.    Defines all hooks for the public side of the site.
     * 
     * Woocommerce related dependencies
     * 
     * - Smaily_WC\Data_Handler.                Handles woocommerce related data retrieval
     * - Smaily_WC\Data_Prepare.                Class for preparing Woocommerce related data
     * - Smaily_WC\Cron.                        Handles data synchronization between Smaily and WooCommerce.
     * - Smaily_WC\Cart                         Manages status of user cart in smaily_abandoned_carts table.
     * - Smaily_WC\Subscriber_Synchronization   Defines functionality for user subscriptions 
     * - Smaily_WC\Profile_Settings.            Adds and controlls WordPress/Woocommerce fields.
     * - Smaily_WC\Smaily_Rss.                  Handles RSS generation for Smaily newsletter.
     * 
     * Create an instance of the loader which will be used to register the hooks
     * with WordPress.
     *
     * Coctact Form 7 related dependencied
     * 
     * - Smaily_CF7\Admin                       Defines all hooks for the admin area of contact form 7
     * - Smaily_CF7\Smaily_Public               Defines the public facing functionality
     *
     * @access private
     */
    private function load_dependencies()
    {
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-helper.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-logger.class.php';
        require_once SMAILY_PLUGIN_PATH . 'admin/smaily-admin.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-block.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-i18n.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-options.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-request.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-template.class.php';
        require_once SMAILY_PLUGIN_PATH . 'includes/smaily-widget.class.php';
        require_once SMAILY_PLUGIN_PATH . 'public/smaily-public.class.php';

        $this->options = new Smaily_Options();

        // Set credentials for API requests to smaily servers
        $credentials = $this->options->get_api_credentials();

        Smaily_Request::set_credentials($credentials);

        if (Smaily_Helper::is_woocommerce_active()) {

            require_once SMAILY_PLUGIN_PATH . 'woocommerce/data-handler.class.php';
            require_once SMAILY_PLUGIN_PATH . 'woocommerce/data-prepare.class.php';
            require_once SMAILY_PLUGIN_PATH . 'woocommerce/cron.class.php';
            require_once SMAILY_PLUGIN_PATH . 'woocommerce/cart.class.php';
            require_once SMAILY_PLUGIN_PATH . 'woocommerce/subscriber-synchronization.class.php';
            require_once SMAILY_PLUGIN_PATH . 'woocommerce/rss.class.php';
            require_once SMAILY_PLUGIN_PATH . 'woocommerce/profile-settings.class.php';
        }

        if (Smaily_Helper::is_cf7_active()) {
            require_once SMAILY_PLUGIN_PATH . 'cf7/admin.class.php';
            require_once SMAILY_PLUGIN_PATH . 'cf7/public.class.php';
        }
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the Smaily_I18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     *
     * @access private
     */
    private function set_locale()
    {
        $plugin_i18n = new Smaily_I18n();
        add_action('plugins_loaded', array($plugin_i18n,  'load_plugin_textdomain'));
    }

    /**
     * Initialize Gutenberg blocks.
     *
     * @access private
     */
    public function init_blocks()
    {
        $plugin_block = new Smaily_Block($this->options, $this->get_plugin_name(), $this->get_version());

        register_block_type(
            SMAILY_PLUGIN_PATH . '/blocks',
            array(
                'render_callback' => array($plugin_block, 'render'),
            )
        );
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     *
     * @access private
     */
    private function define_admin_hooks()
    {
        $plugin_name = $this->get_plugin_name();
        $plugin_admin = new Smaily_Admin($this->options, $plugin_name, $this->get_version());
        add_action('admin_enqueue_scripts', array($plugin_admin,  'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($plugin_admin,  'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($plugin_admin,  'smaily_subscription_block_init'));
        add_action('wp_ajax_smaily_admin_save', array($plugin_admin,  'smaily_admin_save'));
        add_action('widgets_init', array($plugin_admin,  'smaily_subscription_widget_init'));
        add_action('admin_menu', array($plugin_admin,  'smaily_admin_render'));
        add_filter('plugin_action_links_' . plugin_basename(SMAILY_PLUGIN_FILE), array($plugin_admin,  'settings_link'));

        if (Smaily_Helper::is_woocommerce_active()) {

            $smaily_sub_sync = new \Smaily_WC\Subscriber_Synchronization($this->options);

            add_action('personal_options_update', array($smaily_sub_sync, 'smaily_newsletter_subscribe_update'), 11); // edit own account admin.

            add_action('edit_user_profile_update', array($smaily_sub_sync, 'smaily_newsletter_subscribe_update'), 11); // edit other account admin.

            // No subdomain before successful credential validation.
            if ($this->options->has_credentials()) {

                $smaily_profile_settings = new Smaily_WC\Profile_Settings($this->options);

                add_action('personal_options_update', array($smaily_profile_settings, 'smaily_save_account_fields'), 10); // edit own account admin.

                add_action('edit_user_profile_update', array($smaily_profile_settings, 'smaily_save_account_fields'), 10); // edit other account admin.

                // Add fields to admin area.
                add_action('show_user_profile', array($smaily_profile_settings, 'smaily_print_user_admin_fields'), 30); // admin: edit profile.
                add_action('edit_user_profile', array($smaily_profile_settings, 'smaily_print_user_admin_fields'), 30); // admin: edit other users.

            }
        }

        if (Smaily_Helper::is_cf7_active()) {
            $smaily_cf7_admin = new Smaily_CF7\Admin($this->options);
            add_action('wpcf7_editor_panels', array($smaily_cf7_admin, 'add_tab'), -1);
            add_action('wpcf7_after_save', array($smaily_cf7_admin, 'save'));
        }
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     *
     *
     * @access private
     */
    private function define_public_hooks()
    {

        $plugin_public = new Smaily_Public($this->options, $this->get_plugin_name(), $this->get_version());
        add_action('init', array($plugin_public,  'add_shortcodes'));

        if (Smaily_Helper::is_woocommerce_active()) {

            $smaily_cart = new \Smaily_WC\Cart();
            // Update cart status.
            add_action('woocommerce_cart_updated', array($smaily_cart, 'smaily_update_cart_details'));
            // Delete cart when customer orders.
            add_action('woocommerce_checkout_order_processed', array($smaily_cart, 'smaily_checkout_delete_cart'));

            $smaily_sub_sync = new \Smaily_WC\Subscriber_Synchronization($this->options);

            add_action('woocommerce_created_customer', array($smaily_sub_sync, 'smaily_newsletter_subscribe_update'), 11); // register/checkout.
            add_action('woocommerce_save_account_details', array($smaily_sub_sync, 'smaily_newsletter_subscribe_update'), 11); // edit WC account.
            add_action('woocommerce_checkout_order_processed', array($smaily_sub_sync, 'smaily_checkout_subscribe_customer')); // Checkout newsletter checkbox.


            // No subdomain before successful credential validation.
            if ($this->options->has_credentials()) {

                $smaily_profile_settings = new Smaily_WC\Profile_Settings($this->options);

                // Add fields to registration form and account area.
                add_action('woocommerce_register_form', array($smaily_profile_settings, 'smaily_print_user_frontend_fields'), 10);
                add_action('woocommerce_edit_account_form', array($smaily_profile_settings, 'smaily_print_user_frontend_fields'), 10);

                // Show fields in checkout area.
                add_filter('woocommerce_checkout_fields', array($smaily_profile_settings, 'smaily_checkout_fields'), 10, 1);

                // Add checkbox to admin preferred location.
                $settings = $this->options->get_settings();
                $order    = $settings['woocommerce']['checkbox_order'];
                $location = $settings['woocommerce']['checkbox_location'];

                $location = 'woocommerce_' . $order . '_' . $location;
                add_action($location, array($smaily_profile_settings, 'smaily_checkout_newsletter_checkbox'));

                // Save registration fields.
                add_action('woocommerce_created_customer', array($smaily_profile_settings, 'smaily_save_account_fields'), 10); // register/checkout.

                add_action('woocommerce_save_account_details', array($smaily_profile_settings, 'smaily_save_account_fields'), 10); // edit WC account.

            }
        }

        if (Smaily_Helper::is_cf7_active()) {
            $smaily_cf7_public = new Smaily_CF7\Smaily_Public($this->options);
            add_action('wpcf7_submit', array($smaily_cf7_public, 'submit'), 10, 2);
        }
    }

    /**
     * Register all hooks related to the lifecycle of the plugin.
     *
     * Uses the Smaily_Lifecycle class in order to
     * activate or upgrade the plugin within WordPress.
     *
     *
     * @access private
     */
    private function define_lifecycle_hooks()
    {
        $plugin_lifecycle = new Smaily_Lifecycle();
        register_activation_hook(SMAILY_PLUGIN_FILE, array($plugin_lifecycle, 'activate'));
        register_deactivation_hook(SMAILY_PLUGIN_FILE, array($plugin_lifecycle, 'deactivate'));
        register_uninstall_hook(SMAILY_PLUGIN_FILE, array('\Smaily_Lifecycle', 'uninstall'));
        add_action('plugins_loaded', array($plugin_lifecycle, 'update'));
        add_action('upgrader_process_complete', array($plugin_lifecycle, 'check_for_update'), 10, 2);
        add_action('activated_plugin', [$plugin_lifecycle, 'check_for_dependency'], 10, 2);
    }

    /**
     * Register all of the hooks 
     *
     * @access private
     */
    private function define_hooks()
    {
        $this->define_lifecycle_hooks();
        $this->define_admin_hooks();
        $this->define_public_hooks();

        if (Smaily_Helper::is_woocommerce_active()) {

            // Cron specific hooks

            $smaily_cron = new Smaily_WC\Cron($this->options);

            // Register the custom schedule early
            add_filter('cron_schedules', [$smaily_cron, 'smaily_cron_schedules']);
            // Action hook for contact syncronization.
            add_action('smaily_cron_sync_contacts', array($smaily_cron,  'smaily_sync_contacts'));
            // Cron for updating abandoned cart statuses.
            add_action('smaily_cron_abandoned_carts_status', array($smaily_cron,  'smaily_abandoned_carts_status'));
            // Cron for sending abandoned cart emails.
            add_action('smaily_cron_abandoned_carts_email', array($smaily_cron,  'smaily_abandoned_carts_email'));

            // Rss hooks
            $smaily_rss = new Smaily_WC\Rss();
            add_action('init', array($smaily_rss, 'smaily_rewrite_rules'));
            add_filter('query_vars', array($smaily_rss, 'smaily_register_query_var'));
            add_filter('template_include', array($smaily_rss, 'smaily_rss_feed_template_include'), 100);

            add_action('init', array($smaily_rss, 'maybe_flush_rewrite_rules'));
        }
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     *
     * @return string The name of the plugin.
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     *
     * @return Smaily_Loader Orchestrates the hooks of the plugin.
     */
    public function get_loader()
    {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     *
     * @return string The version number of the plugin.
     */
    public function get_version()
    {
        return $this->version;
    }
}
