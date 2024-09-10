<?php

namespace Smaily_CF7;

/**
 * The public-facing functionality of the plugin.
 */

class Smaily_Public
{

    /**
     * The transliterator instance.
     *
     * @since    1.0.0
     * @access   public
     * @var      Transliterator    $transliterator    The transliterator instance.
     */
    private $transliterator;

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
        if (!class_exists('Transliterator')) {
            wp_die(esc_html__('Smaily for CF7 requires Transliterator extension. Please install php-intl package and try again.'));
        }
        $this->transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
    }

    /**
     * Callback for wpcf7_submit hook
     * Is activated on submitting the form.
     *
     * @param WPCF7_ContactForm $instance Current instance.
     * @param array             $result Result of submit.
     */
    public function submit($instance, $result)
    {
        // Check if Contact Form 7 validation has passed.
        $submission_instance = \WPCF7_Submission::get_instance();
        if ($submission_instance->get_status() !== 'mail_sent') {
            return;
        }

        // Don't continue if no posted data or no saved credentials.
        $posted_data         = $submission_instance->get_posted_data();
        $cf7_settings = $this->options->get_settings()['cf7'];

        if (!$this->options->has_credentials() || !$cf7_settings['is_enabled']) {
            return;
        }

        $disallowed_tag_types = array('submit');
        $payload              = array();
        foreach ($instance->scan_form_tags() as $tag) {
            $is_allowed_type = in_array($tag->basetype, $disallowed_tag_types, true) === false;
            $skip_smaily     = strtolower($tag->get_option('skip_smaily', '', true)) === 'on';
            if (!$is_allowed_type || $skip_smaily) {
                continue;
            }

            $posted_value = isset($posted_data[$tag->name]) ? $posted_data[$tag->name] : null;

            $is_single_option_menu  = $tag->basetype === 'select' && !$tag->has_option('multiple');
            $is_single_option_radio = $tag->basetype === 'radio' && count($tag->values) === 1;

            // Email field should always be named email.
            if ($tag->basetype === 'email') {
                $payload['email'] = !is_null($posted_value) ? $posted_value : '';
            }
            // Single option dropdown menu and radio button can only have one value.
            elseif ($is_single_option_radio || $is_single_option_menu) {
                $payload[$this->format_field($tag->name)] = $tag->values[0];
            }

            // Tags with multiple options need to have default values, because browsers do not send values of unchecked inputs.
            elseif ($tag->basetype === 'select' || $tag->basetype === 'radio' || $tag->basetype === 'checkbox') {
                foreach ($tag->values as $value) {
                    $is_selected = is_array($posted_value) ? in_array($value, $posted_value, true) : false;
                    $payload[$this->format_field($tag->name . '_' . $value)] = $is_selected ? '1' : '0';
                }
            }
            // Pass rest of the tag values as is.
            else {
                $payload[$this->format_field($tag->name)] = !is_null($posted_value) ? $posted_value : '';
            }
        }

        $payload = \Smaily_Helper::sanitize_array($payload);

        $request = \Smaily_Request::post('autoresponder', array('body' => [
            'autoresponder' => (int)$cf7_settings['autoresponder_id'],
            'addresses'     => array($payload),
        ]));

        if (empty($request['body'])) {
            $error_message = esc_html__('Something went wrong', 'smaily');
        } elseif (101 !== (int) $request['body']['code']) {
            switch ($request['body']['code']) {
                case 201:
                    $error_message = esc_html__('Form was not submitted using POST method.', 'smaily');
                    break;
                case 204:
                    $error_message = esc_html__('Input does not contain a valid email address.', 'smaily');
                    break;
                case 205:
                    $error_message = esc_html__('Could not add to subscriber list for an unknown reason.', 'smaily');
                    break;
                default:
                    $error_message = esc_html__('Something went wrong', 'smaily');
                    break;
            }
        }
        // If error_message set, continue to replace Contact Form 7's response with Smaily's.
        if (isset($error_message)) {
            $this->set_wpcf7_error($error_message);
        }
    }

    /**
     * Transliterate string to Latin and format field.
     *
     * @param string $unformatted_field "Лanгuaгe_Vene mõös" for example.
     * @return string $formatted_field language_venemoos
     */
    private function format_field($unformatted_field)
    {
        $formatted_field = $this->transliterator->transliterate($unformatted_field);
        $formatted_field = trim($formatted_field);
        $formatted_field = strtolower($formatted_field);
        $formatted_field = str_replace(array('-', ' '), '_', $formatted_field);
        $formatted_field = str_replace(array('ä', 'ö', 'ü', 'õ'), array('a', 'o', 'u', 'o'), $formatted_field);
        $formatted_field = preg_replace('/([^a-z0-9_]+)/', '', $formatted_field);
        return $formatted_field;
    }

    /**
     * Function to set wpcf7 error message
     *
     * @param string $error_message The error message.
     */
    private function set_wpcf7_error($error_message)
    {
        // wpcf7_ajax_json_echo was deprecated in 5.2, try wpcf7_feedback_response and if unavailable
        // fall back to wpcf7_ajax_json_echo. No difference between them, they behave and function in the same way.
        if (has_filter('wpcf7_feedback_response')) {
            add_filter(
                'wpcf7_feedback_response',
                function ($response) use ($error_message) {
                    $response['status']  = 'validation_failed';
                    $response['message'] = $error_message;
                    return $response;
                }
            );
            return;
        }
        add_filter(
            'wpcf7_ajax_json_echo',
            function ($response) use ($error_message) {
                $response['status']  = 'validation_failed';
                $response['message'] = $error_message;
                return $response;
            }
        );
    }
}
