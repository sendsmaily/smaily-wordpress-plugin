<?php

namespace Smaily_Connect\Integrations\CF7;

use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Includes\Options;
use WPCF7_ContactForm;
use WPCF7_Integration;

class Admin {
	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * @var Options Instance of Smaily Options.
	 */
	private $options;

	/**
	 * @var string Plugin version.
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param Options $options Instance of Smaily Options.
	 */
	public function __construct( Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register hooks for the Smaily for Contact Form 7 integration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wpcf7_editor_panels', array( $this, 'add_tab' ), -1 );
		add_action( 'wpcf7_after_save', array( $this, 'save' ) );
		add_action( 'wpcf7_init', array( $this, 'register_service' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Registers the Smaily service.
	 */
	public function register_service() {
		$integration = WPCF7_Integration::get_instance();

		$integration->add_service(
			$this->plugin_name,
			Service::get_instance( $this->options, $this->plugin_name )
		);
	}

	/**
	 * Enqueue admin styles.
	 * @return void
	 */
	public function enqueue_admin_styles() {
		wp_register_style(
			$this->plugin_name . '_cf7_styles',
			SMAILY_CONNECT_PLUGIN_URL . '/integrations/cf7/css/smaily-cf7-admin.css',
			array(),
			$this->version,
			'all'
		);
		wp_enqueue_style( $this->plugin_name . '_cf7_styles' );
	}

	/**
	 * Save the connected form ID (name of the option),
	 * Smaily credentials & autoresponder data to database.
	 *
	 * @param WPCF7_ContactForm $args Arguments of form.
	 */
	public function save( $args ) {
		$nonce_val = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce_val, 'wpcf7-save-contact-form_' . $args->id() ) ) {
			return;
		}

		$can_user_edit = current_user_can( 'wpcf7_edit_contact_form', $args->id() );

		if ( empty( $_POST ) || ! $can_user_edit ) {
			return;
		}

		// Validation and sanitization.
		$status = isset( $_POST['smailyforcf7']['status'] ) ? true : false;

		$autoresponder = isset( $_POST['smailyforcf7-autoresponder'] ) ? (int) $_POST['smailyforcf7-autoresponder'] : 0;

		$this->save_form_settings( $args->id(), $status, $autoresponder );
	}


	/**
	 * Saves Smaily settings for specific form.
	 * @param int $form_id
	 * @param bool $is_enabled
	 * @param int $autoresponder_id
	 * @return void
	 *
	 * @since 1.3.0
	 */
	private function save_form_settings( $form_id, $is_enabled, $autoresponder_id ) {
		$settings = $this->options->get_settings()['cf7'];

		$settings[ $form_id ] = array(
			'is_enabled'       => $is_enabled,
			'autoresponder_id' => $autoresponder_id,
		);

		update_option( Options::CONTACT_FORM_7_STATUS_OPTION, $settings );
	}

	/**
	 * Add Smaily configuration panel to Contact Form 7 panels array.
	 *
	 * @param array $panels Contact Form 7's panels.
	 * @return array $merged_panels Array of CF7 tabs, including a Smaily tab.
	 */
	public function add_tab( $panels ) {
		$panel = array(
			'smailyforcf7' => array(
				'title'    => __( 'Smaily for Contact Form 7', 'smaily-connect' ),
				'callback' => array( $this, 'panel_content' ),
			),
		);

		$merged_panels = array_merge( $panels, $panel );
		return $merged_panels;
	}

	/**
	 * Content of 'Smaily for Contact Form 7' tab
	 *
	 * @param WPCF7_ContactForm $args Contact Form 7 tab arguments.
	 */
	public function panel_content( $args ) {
		$smaily_cf7_settings = $this->options->get_settings()['cf7'];
		$form_id             = $args->id();

		$template_variables = array(
			'autoresponder_id'   => 0,
			'autoresponders'     => array(),
			'has_credentials'    => false,
			'is_captcha_enabled' => false,
			'is_enabled'         => false,
		);

		if ( isset( $smaily_cf7_settings[ $form_id ] ) ) {
			$template_variables = array_merge( $template_variables, $smaily_cf7_settings[ $form_id ] );
		}

		$template_variables['has_credentials']    = $this->options->has_credentials();
		$template_variables['autoresponders']     = Helper::get_autoresponders_list( $this->options );
		$template_variables['is_captcha_enabled'] = $this->is_captcha_enabled(
			\WPCF7_FormTagsManager::get_instance()->get_scanned_tags()
		);

		require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/cf7/partials/smaily-cf7-admin.php';
	}

	/**
	 * Search provided tags for Really Simple Captcha tags.
	 *
	 * Loops through all lists of tags until it finds 'basetype' key with value
	 * 'captchac' or 'captchar'. If found, sets a var true and evaluates both for response.
	 *
	 * @param array $form_tags All Contact Form 7 tags in current form.
	 * @return bool $simple_captcha_enabled
	 */
	private function search_for_cf7_captcha( $form_tags ) {
		// Check if Really Simple Captcha is actually enabled.
		if ( ! class_exists( 'ReallySimpleCaptcha' ) ) {
			return false;
		}
		$has_captcha_image = false;
		$has_captcha_input = false;
		foreach ( (array) $form_tags as $tag ) {
			foreach ( $tag as $key => $value ) {
				if ( 'basetype' === $key && 'captchac' === $value ) {
					$has_captcha_image = true;
				} elseif ( 'basetype' === $key && 'captchar' === $value ) {
					$has_captcha_input = true;
				}
			}
		}
		return ( $has_captcha_image && $has_captcha_input );
	}

	/**
	 * Checks is either captcha is enabled.
	 *
	 * @param array $form_tags Tags in the current form.
	 * @return boolean
	 */
	private function is_captcha_enabled( $form_tags ) {
		$isset_captcha   = $this->search_for_cf7_captcha( $form_tags );
		$isset_recaptcha = isset( get_option( 'wpcf7' )['recaptcha'] );
		if ( $isset_captcha || $isset_recaptcha ) {
			return true;
		}
		return false;
	}
}
