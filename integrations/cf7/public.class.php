<?php

namespace Smaily_Connect\Integrations\CF7;

use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;
use Transliterator;
use WPCF7_ContactForm;
use WPCF7_Submission;

class Public_Base {
	/**
	 * The transliterator instance.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      Transliterator    $transliterator    The transliterator instance.
	 */
	private $transliterator;

	/**
	 * @var Options Instance of Options.
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options Instance of Options.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
		if ( ! class_exists( 'Transliterator' ) ) {
			wp_die( ( 'Smaily for CF7 requires Transliterator extension. Please install php-intl package and try again.' ) );
		}
		$this->transliterator = Transliterator::create( 'Any-Latin; Latin-ASCII' );
	}

	/**
	 * Register hooks for the Smaily for Contact Form 7 integration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wpcf7_submit', array( $this, 'submit' ), 10, 2 );
	}

	/**
	 * Callback for wpcf7_submit hook
	 * Is activated on submitting the form.
	 *
	 * @param WPCF7_ContactForm $instance Current instance.
	 * @param array             $result Result of submit.
	 */
	public function submit( $instance, $result ) {
		// Check if Contact Form 7 validation has passed.
		$submission_instance = WPCF7_Submission::get_instance();
		if ( $submission_instance->get_status() !== 'mail_sent' ) {
			return;
		}

		// Don't continue if no posted data or no saved credentials.
		$posted_data  = $submission_instance->get_posted_data();
		$cf7_settings = $this->options->get_settings()['cf7'];
		$form_id      = $instance->id();

		$form_settings = isset( $cf7_settings[ $form_id ] ) ? $cf7_settings[ $form_id ] : null;

		if ( ! $form_settings || ! $this->options->has_credentials() ) {
			return;
		}

		if ( isset( $form_settings['is_enabled'] ) && ! $form_settings['is_enabled'] ) {
			return;
		}

		$disallowed_tag_types = array( 'submit' );
		$payload              = array();
		foreach ( $instance->scan_form_tags() as $tag ) {
			$is_allowed_type = in_array( $tag->basetype, $disallowed_tag_types, true ) === false;
			$skip_smaily     = strtolower( $tag->get_option( 'skip_smaily', '', true ) ) === 'on';
			if ( ! $is_allowed_type || $skip_smaily ) {
				continue;
			}

			$posted_value = isset( $posted_data[ $tag->name ] ) ? $posted_data[ $tag->name ] : null;

			$is_single_option_menu  = $tag->basetype === 'select' && ! $tag->has_option( 'multiple' );
			$is_single_option_radio = $tag->basetype === 'radio' && count( $tag->values ) === 1;

			// Email field should always be named email.
			if ( $tag->basetype === 'email' ) {
				$payload['email'] = ! is_null( $posted_value ) ? $posted_value : '';
			} elseif ( $is_single_option_radio || $is_single_option_menu ) {
				// Single option dropdown menu and radio button can only have one value.
				$payload[ $this->format_field( $tag->name ) ] = $posted_value[0] ?? '';
			} elseif ( $tag->basetype === 'select' || $tag->basetype === 'radio' || $tag->basetype === 'checkbox' ) {
				// Tags with multiple options need to have default values, because browsers do not send values of unchecked inputs.
				foreach ( $tag->values as $value ) {
					$is_selected = is_array( $posted_value ) ? in_array( $value, $posted_value, true ) : false;
					$payload[ $this->format_field( $tag->name . '_' . $value ) ] = $is_selected ? '1' : '0';
				}
			} else {
				// Pass rest of the tag values as is.
				$payload[ $this->format_field( $tag->name ) ] = ! is_null( $posted_value ) ? $posted_value : '';
			}
		}

		$payload = Helper::sanitize_array( $payload );

		$request          = new Smaily_Client( $this->options );
		$autoresponder_id = isset( $form_settings['autoresponder_id'] ) ? (int) $form_settings['autoresponder_id'] : 0;
		$response         = $request->trigger_automation( $autoresponder_id, array( $payload ) );

		if ( empty( $response['body'] ) ) {
			$error_message = esc_html__( 'Something went wrong', 'smaily-connect' );
		} elseif ( 101 !== (int) $response['body']['code'] ) {
			switch ( $response['body']['code'] ) {
				case 201:
					$error_message = esc_html__( 'Form was not submitted using POST method.', 'smaily-connect' );
					break;
				case 203:
					$error_message = esc_html__( 'Invalid data submitted.', 'smaily-connect' );
					break;
				case 204:
					$error_message = esc_html__( 'Input does not contain a valid email address.', 'smaily-connect' );
					break;
				default:
					$error_message = esc_html__( 'Subscribing failed with unknown reason.', 'smaily-connect' );
					break;
			}
		}
		// If error_message set, continue to replace Contact Form 7's response with Smaily's.
		if ( isset( $error_message ) ) {
			$this->set_wpcf7_error( $error_message );
		}
	}

	/**
	 * Transliterate string to Latin and format field.
	 *
	 * @param string $unformatted_field "Лanгuaгe_Vene mõös" for example.
	 * @return string $formatted_field language_venemoos
	 */
	private function format_field( $unformatted_field ) {
		$formatted_field = $this->transliterator->transliterate( $unformatted_field );
		$formatted_field = trim( $formatted_field );
		$formatted_field = strtolower( $formatted_field );
		$formatted_field = str_replace( array( '-', ' ' ), '_', $formatted_field );
		$formatted_field = str_replace( array( 'ä', 'ö', 'ü', 'õ' ), array( 'a', 'o', 'u', 'o' ), $formatted_field );
		$formatted_field = preg_replace( '/([^a-z0-9_]+)/', '', $formatted_field );
		return $formatted_field;
	}

	/**
	 * Function to set wpcf7 error message
	 *
	 * @param string $error_message The error message.
	 */
	private function set_wpcf7_error( $error_message ) {
		// wpcf7_ajax_json_echo was deprecated in 5.2, try wpcf7_feedback_response and if unavailable
		// fall back to wpcf7_ajax_json_echo. No difference between them, they behave and function in the same way.
		if ( has_filter( 'wpcf7_feedback_response' ) ) {
			add_filter(
				'wpcf7_feedback_response',
				function ( $response ) use ( $error_message ) {
					$response['status']  = 'validation_failed';
					$response['message'] = $error_message;
					return $response;
				}
			);
			return;
		}
		add_filter(
			'wpcf7_ajax_json_echo',
			function ( $response ) use ( $error_message ) {
				$response['status']  = 'validation_failed';
				$response['message'] = $error_message;
				return $response;
			}
		);
	}
}
