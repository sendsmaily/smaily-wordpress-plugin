<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/admin
 */
class Smaily_Admin
{

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
     * Initialize the class and set its properties.
     *
     * @param Smaily_Options $options     Reference to option handler class.
     * @param string                $plugin_name The name of this plugin.
     * @param string                $version     The version of this plugin.
     */
    public function __construct(Smaily_Options $options, $plugin_name, $version)
    {
        $this->options     = $options;
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    /**
     * Register the stylesheets for the admin area.
     *
     */
    public function enqueue_styles()
    {
        wp_register_style($this->plugin_name, SMAILY_PLUGIN_URL . '/admin/css/smaily-admin.css', array(), $this->version, 'all');
        wp_register_style($this->plugin_name . '-widget', SMAILY_PLUGIN_URL . '/admin/css/smaily-widget-admin.css', array(), $this->version, 'all');

        wp_enqueue_style($this->plugin_name);
        wp_enqueue_style($this->plugin_name . '-widget');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     */
    public function enqueue_scripts()
    {
        wp_register_script($this->plugin_name . '-jscolor', SMAILY_PLUGIN_URL . '/admin/js/jscolor.min.js', null, $this->version, true);
        wp_register_script($this->plugin_name, SMAILY_PLUGIN_URL . '/admin/js/smaily-admin.js', array('jquery', 'jquery-ui-tabs'), $this->version, true);
        wp_register_script($this->plugin_name . '-widget', SMAILY_PLUGIN_URL . '/admin/js/admin-widget.js', array('jquery', $this->plugin_name . '-jscolor'), $this->version, true);

        wp_enqueue_script($this->plugin_name . '-jscolor');
        wp_enqueue_script($this->plugin_name);
        wp_enqueue_script($this->plugin_name . '-widget');

        wp_localize_script($this->plugin_name, 'smaily_translations', array(
            'went_wrong' => __('Something went wrong connecting to Smaily!', 'smaily'),
            'validated'  => __('Smaily settings successfully saved!', 'smaily'),
            'data_error' => __('Something went wrong with saving data!', 'smaily'),
        ));

        if (Smaily_Helper::is_woocommerce_active()) {
            // Make rss url accssible in admin js

            wp_add_inline_script(
                $this->plugin_name,
                'var smaily_settings = ' . wp_json_encode([
                    'rss_feed_url' => Smaily_WC\Data_Handler::make_rss_feed_url()
                ]) . ';',
                'before'
            );
        }
    }

    /**
     * Adds setting link to plugin
     *
     * @param array $links Default links in plugin page.
     * @return array    $links Updated array of links
     */
    public function settings_link($links)
    {
        // receive all current links and add custom link to the list.
        $settings_link = '<a href="admin.php?page=smaily-settings">' . esc_html__('Settings', 'smaily') . '</a>';
        // Settings before disable.
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Render admin page.
     *
     */
    public function smaily_admin_render()
    {
        // Load configuration data.
        $credentials        = $this->options->get_api_credentials();
        $settings           = $this->options->get_settings();
        $autoresponders     = $this->get_autoresponders();

        // Create admin template.
        $template = $this->generate_admin_template($credentials, $settings, $autoresponders);

        // Add menu elements.
        add_menu_page('Smaily Settings', 'Smaily', 'manage_options', 'smaily-settings', array($template, 'dispatch'), SMAILY_PLUGIN_URL . '/gfx/icon.png');
    }

    /**
     * Load newsletter subscription block.
     *
     */
    public function smaily_subscription_block_init($screen)
    {
        if (!in_array($screen, array('site-editor.php', 'post.php', 'page.php'), true)) {
            return;
        }

        $autoresponders = array(
            array(
                'label' => __('No autoresponder', 'smaily'),
                'value' => '',
            ),
        );

        foreach ($this->get_autoresponders() as $autoresponder_id => $title) {
            $autoresponders[] = array(
                'label' => $title,
                'value' => (string) $autoresponder_id,
            );
        }

        $blocks_assets = require(SMAILY_PLUGIN_PATH . 'blocks/index.asset.php');
        wp_enqueue_script(
            $this->plugin_name . '-subscription',
            SMAILY_PLUGIN_URL . '/blocks/index.js',
            array(),
            $blocks_assets['version'],
            true
        );

        wp_add_inline_script($this->plugin_name . '-subscription', "window.autoresponders = '" . wp_json_encode($autoresponders) . "';" , 'before');
    }

    /**
     * Load subscribe widget.
     *
     */
    public function smaily_subscription_widget_init()
    {
        $widget = new Smaily_Widget($this->options, $this);
        register_widget($widget);
    }

    /**
     * Function is run when user performs action which is handled Ajax.
     *
     */
    public function smaily_admin_save()
    {

        // Ensure user has necessary permissions.
        if (!current_user_can('manage_options')) {
            echo wp_json_encode(
                array(
                    'error' => __('You are not authorized to edit settings!', 'smaily'),
                )
            );
            wp_die();
        }

        // Allow only posted data.
        if (empty($_POST) || empty($_POST['payload'])) {
            echo wp_json_encode(
                array(
                    'error' => __('Something went wrong, incorrect request method!', 'smaily'),
                )
            );
            wp_die();
        }

        // Parse form data out of the serialization.
        $form_data = array();

        // $form_data values should be sanitized instead of serialized payload.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized 
        parse_str(wp_unslash($_POST['payload']), $form_data);

        // Ensure nonce is valid.
        $nonce = isset($form_data['nonce']) ? $form_data['nonce'] : '';
        if (!wp_verify_nonce(sanitize_key($nonce), 'smaily-settings-nonce')) {
            echo wp_json_encode(
                array(
                    'error' => __('Nonce verification failed!', 'smaily'),
                )
            );
            wp_die();
        }

        // Validate posted operation.
        if (!isset($form_data['op'])) {
            $form_data['op'] = 'save';
        }

        $valid_operations = array('resetForm', 'save');

        if (!in_array($form_data['op'], $valid_operations, true)) {
            echo wp_json_encode(
                array(
                    'error' => __('Something went wrong, incorrect operation!', 'smaily'),
                )
            );
            wp_die();
        }

        $result  = array(
            'message' => '',
            'error'   => true,
            'content' => '',
        );

        // Switch to action.
        switch ($form_data['op']) {
            case 'resetForm':
                $result = array_merge($result, $this->reset_form());
                break;
            case 'save':
                $result = array_merge($result, $this->save($form_data));
                break;
        }

        echo wp_json_encode($result);
        wp_die();
    }

    /**
     * Function is run when user submits Smaily API credentials.
     *
     *
     * @access private
     * @param  array $form_data Posted form data (unserialized).
     * @return array Response of operation.
     */
    private function validate_api_credentials($form_data)
    {
        // Get and sanitize request params.
        $params = array(
            'subdomain' => isset($form_data['subdomain']) ? sanitize_text_field($form_data['subdomain']) : '',
            'username'  => isset($form_data['username']) ? sanitize_text_field($form_data['username']) : '',
            'password'  => isset($form_data['password']) ? sanitize_text_field($form_data['password']) : '',
        );

        $params['subdomain'] = $this->normalize_subdomain($params['subdomain']);

        // Show error messages to user if no data is entered to form.
        if ($params['subdomain'] === '') {
            return __('Please enter subdomain!', 'smaily');
        } elseif ($params['username'] === '') {
            return __('Please enter username!', 'smaily');
        } elseif ($params['password'] === '') {
            return __('Please enter password!', 'smaily');
        }

        Smaily_Request::set_credentials($params);

        // Validate credentials with get request.
        $rqst = Smaily_Request::get('workflows', array(
            'trigger_type' => 'form_submitted'
        ));

        // Error handilng.
        $code = isset($rqst['code']) ? $rqst['code'] : '';
        if ($code !== 200) {
            if ($code === 401) {
                // If wrong credentials.
                return __('Wrong credentials', 'smaily');
            } elseif ($code === 404) {
                // If wrong subdomain.
                return __('Error in subdomain', 'smaily');
            } elseif (array_key_exists('error', $rqst)) {
                // If there is WordPress error message.
                return $rqst['error'];
            }
            // If not determined error.
            return __('Something went wrong with request to Smaily', 'smaily');
        }
        // Insert item to database.
        $this->options->update_api_credentials($params);

        // Return response.
        return true;
    }

    /**
     * Function is run when user regenerates opt-in form.
     *
     *
     * @access private
     * @return array Response of operation.
     */
    private function reset_form()
    {
        $subdomain = $this->options->get_api_credentials()['subdomain'];
        $template  = $this->generate_optin_template('basic.php', $subdomain);

        // Return response.
        return array(
            'message' => __('Newsletter subscription form reset to default.', 'smaily'),
            'error'   => false,
            'content' => $template->render(),
        );
    }

    /**
     * Function is run when user presses save button.
     *
     *
     * @access private
     * @param  array $form_data Posted form data (deserialized).
     * @return array Response of operation.
     */
    private function save($form_data)
    {
        // Validate credentials if needed
        if (isset($form_data['api']['subdomain']) && !empty($form_data['api']['subdomain'])) {
            $validate_credentials = $this->validate_api_credentials($form_data['api']);

            // Incase validation fails
            if (!is_bool($validate_credentials)) {
                echo wp_json_encode(array(
                    'error' => $validate_credentials,
                ));
                wp_die();
            }
        }

        // Get parameters.
        $is_advanced = (isset($form_data['advanced-form']['is_advanced']) && !empty($form_data['advanced-form']['is_advanced'])) ? true : false;
        $form        = (isset($form_data['advanced-form']['form']) && is_string($form_data['advanced-form']['form'])) ? trim($form_data['advanced-form']['form']) : '';

        // Generate new form (if empty).
        if (empty($form) && $is_advanced) {
            echo wp_json_encode(
                array(
                    'error' => __('Disable advanced form option or fill out the form field!', 'smaily'),
                )
            );
            wp_die();
        }

        $this->options->update_settings(
            array(
                'is_advanced' => $is_advanced,
                'form'        => $form
            )
        );

        // Check if wooocommerce is active, if so proccess woocommerce related data
        if (Smaily_Helper::is_woocommerce_active()) {
            $woocommerce = Smaily_WC\Data_Prepare::prepare_form_data($form_data);
            $this->options->update_settings($woocommerce, 'woocommerce_settings');
        }

        $autoresponders = $this->get_autoresponders();

        return array('error' => false, 'autoresponders' => $autoresponders);
    }

    /**
     * Generate newsletter opt-in form template and assign required variables via function parameters.
     *
     *
     * @access private
     * @param  string $template_name            Name of template file to use, without any prefixes (e.g advanced.php).
     * @param  string $subdomain                Smaily API subdomain.
     * @param  string $newsletter_form          HTML of newsletter subscription form.
     * @return Smaily_Template $template Template of admin form.
     */
    private function generate_optin_template($template_name, $subdomain, $newsletter_form = '')
    {
        // Generate form contents.
        $template = new Smaily_Template('public/partials/smaily-public-' . $template_name);

        $template->assign(
            array(
                'domain' => $subdomain,
                'form'   => $newsletter_form,
            )
        );
        return $template;
    }


    /**
     * Generate admin area template and assign required variables via function parameters.
     *
     *
     * @access private
     * @param  string $template_name            Name of template file to use, without any prefixes (e.g form.php).
     * @param  bool   $has_credentials          User has saved valid credentials? Yes/No.
     * @param  string $settings             Newsletter subscription form options.
     * @return Smaily_Template $template Template of admin form.
     */
    private function generate_admin_template($credentials, $settings, $autoresponders = array())
    {
        // Generate form contents.
        $template = new Smaily_Template('admin/template/smaily-admin.php');

        $template->assign(
            array(
                'credentials' => $credentials,
                'settings'    => $settings,
                'autoresponders' => $autoresponders
            )
        );

        return $template;
    }

    /**
     * Normalize subdomain into the bare necessity.
     *
     *
     * @access private
     * @param  string $subdomain Messy subdomain, e.g http://demo.sendsmaily.net
     * @return string Clean subdomain, e.g demo
     */
    private function normalize_subdomain($subdomain)
    {
        // Normalize subdomain.
        // First, try to parse as full URL. If that fails, try to parse as subdomain.sendsmaily.net, and
        // if all else fails, then clean up subdomain and pass as is.
        if (filter_var($subdomain, FILTER_VALIDATE_URL)) {
            $url       = wp_parse_url($subdomain);
            $parts     = explode('.', $url['host']);
            $subdomain = count($parts) >= 3 ? $parts[0] : '';
        } elseif (preg_match('/^[^\.]+\.sendsmaily\.net$/', $subdomain)) {
            $parts     = explode('.', $subdomain);
            $subdomain = $parts[0];
        }

        return preg_replace('/[^a-zA-Z0-9]+/', '', $subdomain);
    }

    /**
     * Make a request to Smaily asking for autoresponders.
     * Request is authenticated via saved credentials.
     *
     * @return array $autoresponder_list List of autoresponders in format [id => title].
     */
    public function get_autoresponders()
    {
        // Load configuration data.
        $api_credentials = $this->options->get_api_credentials();

        if (!$this->options->has_credentials()) {
            return array();
        }

        $result = Smaily_Request::get('workflows', array(
            'trigger_type' => 'form_submitted'
        ));

        if (empty($result['body'])) {
            return array();
        }

        $autoresponder_list = array();
        foreach ($result['body'] as $autoresponder) {
            $id                        = $autoresponder['id'];
            $title                     = $autoresponder['title'];
            $autoresponder_list[$id] = $title;
        }
        return $autoresponder_list;
    }
}
