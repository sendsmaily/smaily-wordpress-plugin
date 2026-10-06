<?php

namespace Smaily_Connect\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Action_Base;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\Elementor\FormSubscription;

/**
 * The "Smaily" action under Elementor Pro's Actions After Submit (PRO-3806).
 *
 * Loaded only from Admin::register_form_actions(), once Elementor Pro's Forms
 * module exists. It adapts Elementor's record and ajax-handler objects to
 * FormSubscription, which decides what reaches Smaily. Elementor runs the
 * actions only after its own validation and spam protection (honeypot,
 * reCAPTCHA) have passed.
 *
 * No credential is a control: the action uses the plugin's saved Smaily
 * connection, so nothing secret reaches the form markup or an export.
 */
class Form_Action extends Action_Base {

	/**
	 * @var FormSubscription
	 */
	private $subscription;

	/**
	 * @param FormSubscription|null $subscription Injected in tests; defaults to the saved Smaily connection.
	 */
	public function __construct( $subscription = null ) {
		$this->subscription = $subscription ?? new FormSubscription(
			static function () {
				return Bootstrap::instance()->smaily_client();
			}
		);
	}

	public function get_name(): string {
		return 'smaily';
	}

	public function get_label(): string {
		return __( 'Smaily', 'smaily-connect' );
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       The submission.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Elementor's response.
	 */
	public function run( $record, $ajax_handler ): void {
		$settings = (array) $record->get( 'form_settings' );

		$values = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$value                  = is_array( $field ) && isset( $field['value'] ) ? $field['value'] : '';
			$values[ (string) $id ] = is_scalar( $value ) ? (string) $value : '';
		}

		$meta     = $record->get_form_meta( array( 'page_url' ) );
		$page_url = isset( $meta['page_url']['value'] ) && is_scalar( $meta['page_url']['value'] ) ? (string) $meta['page_url']['value'] : '';

		switch ( $this->subscription->submit( $settings, $values, $page_url ) ) {
			case FormSubscription::OUTCOME_INVALID_EMAIL:
				$ajax_handler->add_error_message( __( 'Please enter a valid email address.', 'smaily-connect' ) );
				break;
			case FormSubscription::OUTCOME_FAILED:
				$ajax_handler->add_error_message( __( 'Sorry, we could not sign you up right now. Please try again later.', 'smaily-connect' ) );
				// Elementor shows admin errors only to a user who can edit the page.
				$ajax_handler->add_admin_error_message( 'Smaily: ' . $this->subscription->error() );
				break;
		}
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget The form widget.
	 */
	public function register_settings_section( $widget ): void {
		$widget->start_controls_section(
			'section_smaily',
			array(
				'label'     => __( 'Smaily', 'smaily-connect' ),
				'condition' => array(
					'submit_actions' => $this->get_name(),
				),
			)
		);

		$widget->add_control(
			'smaily_mode',
			array(
				'label'   => __( 'Mode', 'smaily-connect' ),
				'type'    => Controls_Manager::SELECT,
				'default' => FormSubscription::MODE_NEWSLETTER,
				'options' => array(
					FormSubscription::MODE_NEWSLETTER => __( 'Newsletter signup', 'smaily-connect' ),
					FormSubscription::MODE_CONTACT    => __( 'Contact form with marketing consent', 'smaily-connect' ),
				),
			)
		);

		$widget->add_control(
			'smaily_email_field',
			array(
				'label'       => __( 'Email field ID', 'smaily-connect' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'email',
				'description' => __( 'Required. The ID of the form\'s email field (the field\'s Advanced tab).', 'smaily-connect' ),
			)
		);

		$widget->add_control(
			'smaily_name_field',
			array(
				'label'       => __( 'Name field ID', 'smaily-connect' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Optional. Sent to the Smaily field "name".', 'smaily-connect' ),
			)
		);

		$widget->add_control(
			'smaily_consent_field',
			array(
				'label'       => __( 'Consent field ID', 'smaily-connect' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'The acceptance or checkbox field the visitor ticks to agree to marketing emails. Nothing is sent to Smaily unless it is ticked.', 'smaily-connect' ),
				'condition'   => array(
					'smaily_mode' => FormSubscription::MODE_CONTACT,
				),
			)
		);

		$widget->add_control(
			'smaily_fields',
			array(
				'label'       => __( 'Other fields', 'smaily-connect' ),
				'type'        => Controls_Manager::REPEATER,
				'description' => __( 'Optional. Only the fields listed here are sent. Smaily field names use lowercase letters, digits and _.', 'smaily-connect' ),
				'fields'      => array(
					array(
						'name'  => 'form_field',
						'label' => __( 'Form field ID', 'smaily-connect' ),
						'type'  => Controls_Manager::TEXT,
					),
					array(
						'name'  => 'smaily_field',
						'label' => __( 'Smaily field', 'smaily-connect' ),
						'type'  => Controls_Manager::TEXT,
					),
				),
				'title_field' => '{{{ smaily_field }}}',
			)
		);

		$widget->add_control(
			'smaily_workflow_id',
			array(
				'label'       => __( 'Workflow', 'smaily-connect' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => $this->subscription->workflow_options(),
				'description' => __( 'Optional. The Smaily workflow to trigger after the signup.', 'smaily-connect' ),
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * The workflow belongs to this site's Smaily account, so an exported form
	 * does not carry it to another site.
	 *
	 * @param array $element The exported form element; its controls are under `settings`.
	 * @return array
	 */
	public function on_export( $element ): array {
		unset( $element['settings']['smaily_workflow_id'] );
		return $element;
	}
}
