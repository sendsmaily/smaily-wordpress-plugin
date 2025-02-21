<?php

namespace Smaily_CF7;

defined( 'ABSPATH' ) || exit;

use Smaily_Admin\Admin as Smaily_Admin;
use Smaily_Options;
use WPCF7_ContactForm;
use WPCF7_Integration;

/**
 * Class for managing all admin related functionality of Contact Form 7 integration
 */

class Admin {
	/**
	 * @var \Smaily_Options Instance of Smaily_Options.
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param \Smaily_Options $options Instance of Smaily_Options.
	 */
	public function __construct( \Smaily_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Registers the Smaily service.
	 */
	public function register_service() {
		$integration = WPCF7_Integration::get_instance();

		$integration->add_service(
			'smaily',
			Smaily_CF7_Service::get_instance()
		);
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
		$status = isset( $_POST['smailyforcf7']['status'] ) ? 1 : 0;

		$autoresponder = isset( $_POST['smailyforcf7-autoresponder'] ) ? (int) $_POST['smailyforcf7-autoresponder'] : 0;

		update_option(
			Smaily_Options::CONTACT_FORM_7_STATUS_OPTION,
			array(
				'is_enabled'       => $status,
				'autoresponder_id' => $autoresponder,
			)
		);
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
				'title'    => __( 'Smaily for Contact Form 7', 'smaily' ),
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
		// Fetch saved Smaily CF7 option here to pass data along to view.
		$smaily_cf7_settings = $this->options->get_settings()['cf7'];

		$is_enabled            = (bool) esc_html( $smaily_cf7_settings['is_enabled'] );
		$default_autoresponder = (int) esc_html( $smaily_cf7_settings['autoresponder_id'] );

		$has_credentials = $this->options->has_credentials();

		// Fetch autoresponder data here for view.
		$autoresponder_list = Smaily_Admin::get_autoresponders( $this->options );

		$form_tags       = \WPCF7_FormTagsManager::get_instance()->get_scanned_tags();
		$captcha_enabled = $this->is_captcha_enabled( $form_tags );

		require_once SMAILY_PLUGIN_PATH . 'cf7/partials/smaily-cf7-admin.php';
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
