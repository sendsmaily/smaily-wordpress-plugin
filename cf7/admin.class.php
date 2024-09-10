<?php

namespace Smaily_CF7;

/**
 * Class for managing all admin related functionality of Contact Form 7 integration
 */

class Admin
{

    /**
     * @var \Smaily_Options Instance of Smaily_Options.
     */
    private $options;

    /**
     * Constructor.
     *
     * @param \Smaily_Options $options Instance of Smaily_Options.
     */
    public function __construct(\Smaily_Options $options)
    {
        $this->options = $options;
    }

    /**
     * Save the connected form ID (name of the option),
     * Smaily credentials & autoresponder data to database.
     *
     * @param WPCF7_ContactForm $args Arguments of form.
     */
    public function save($args)
    {
        $can_user_edit = current_user_can('wpcf7_edit_contact_form', $args->id());

        if (empty($_POST) || !$can_user_edit) {
            return;
        }

        // Validation and sanitization.
        $status = isset($_POST['smailyforcf7']['status']) ? 1 : 0;

        $autoresponder = isset($_POST['smailyforcf7-autoresponder']) ? (int) $_POST['smailyforcf7-autoresponder'] : 0;

        $this->options->update_settings(array(
            'is_enabled' => $status,
            'autoresponder_id' => $autoresponder
        ), 'cf7_settings');
    }

    /**
     * Add Smaily configuration panel to Contact Form 7 panels array.
     *
     * @param array $panels Contact Form 7's panels.
     * @return array $merged_panels Array of CF7 tabs, including a Smaily tab.
     */
    public function add_tab($panels)
    {
        $panel = array(
            'smailyforcf7' => array(
                'title'    => __('Smaily for Contact Form 7', 'smaily'),
                'callback' => array($this, 'panel_content'),
            ),
        );

        $merged_panels = array_merge($panels, $panel);
        return $merged_panels;
    }

    /**
     * Content of 'Smaily for Contact Form 7' tab
     *
     * @param WPCF7_ContactForm $args Contact Form 7 tab arguments.
     */
    public function panel_content($args)
    {
        // Fetch saved Smaily CF7 option here to pass data along to view.
        $smaily_cf7_settings = $this->options->get_settings()['cf7'];

        $is_enabled = (bool)esc_html($smaily_cf7_settings['is_enabled']);
        $default_autoresponder = (int)esc_html($smaily_cf7_settings['autoresponder_id']);

        $has_credentials = $this->options->has_credentials();

        // Fetch autoresponder data here for view.
        $autoresponder_list = $this->get_autoresponders();

        $form_tags       = \WPCF7_FormTagsManager::get_instance()->get_scanned_tags();
        $captcha_enabled = $this->is_captcha_enabled($form_tags);

        require_once SMAILY_PLUGIN_PATH . 'cf7/partials/smaily-cf7-admin.php';
    }

    /**
     * Search provided tags for Really Simple Captcha tags.
     *
     * Loops through all lists of tags until it finds 'basetype' key with value
     *  'captchac' or 'captchar'. If found, sets a var true and evaluates both for response.
     *
     * @param array $form_tags All Contact Form 7 tags in current form.
     * @return bool $simple_captcha_enabled
     */
    private function search_for_cf7_captcha($form_tags)
    {
        // Check if Really Simple Captcha is actually enabled.
        if (!class_exists('ReallySimpleCaptcha')) {
            return false;
        }
        $has_captcha_image = false;
        $has_captcha_input = false;
        foreach ((array) $form_tags as $tag) {
            foreach ($tag as $key => $value) {
                if ('basetype' === $key && 'captchac' === $value) {
                    $has_captcha_image = true;
                } elseif ('basetype' === $key && 'captchar' === $value) {
                    $has_captcha_input = true;
                }
            }
        }
        return ($has_captcha_image && $has_captcha_input);
    }

    /**
     * Checks is either captcha is enabled.
     *
     * @param array $form_tags Tags in the current form.
     * @return boolean
     */
    private function is_captcha_enabled($form_tags)
    {
        $isset_captcha   = $this->search_for_cf7_captcha($form_tags);
        $isset_recaptcha = isset(get_option('wpcf7')['recaptcha']);
        if ($isset_captcha || $isset_recaptcha) {
            return true;
        }
        return false;
    }

    /**
     * Make a request to Smaily asking for autoresponders.
     * Request is authenticated via saved credentials.
     *
     * @return array $autoresponder_list List of autoresponders in format [id => title].
     */
    private function get_autoresponders()
    {
        // Load configuration data.
        $api_credentials = $this->options->get_api_credentials();

        if (!$this->options->has_credentials()) {
            return array();
        }

        $result = \Smaily_Request::get('workflows', array(
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
