<?php

/**
 * Defines the widget functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Widget extends WP_Widget {


	/**
	 * Admin model.
	 *
	 *
	 * @access private
	 * @var    Smaily_Admin
	 */
	private $admin_model;

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Smaily_Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * Sets up a new instance of the widget.
	 *
	 * @param Smaily_Options $options     Reference to options handler class.
	 * @param Smaily_Admin   $admin_model Reference to admin class.
	 */
	public function __construct( Smaily_Options $options, Smaily_Admin $admin_model ) {
		$widget_ops = array( 'description' => __( 'Smaily newsletter subscription form', 'smaily' ) );
		parent::__construct( 'smaily_subscription_widget', __( 'Smaily Newsletter Subscription', 'smaily' ), $widget_ops );

		$this->options     = $options;
		$this->admin_model = $admin_model;
	}

	/**
	 * Outputs the content for the current widget instance.
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title',
	 *                        'before_widget', and 'after_widget'.
	 * @param array $instance Settings for the current Search widget instance.
	 */
	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		$show_name     = isset( $instance['show_name'] ) ? $instance['show_name'] : false;
		$success_url   = isset( $instance['success_url'] ) ? $instance['success_url'] : '';
		$failure_url   = isset( $instance['failure_url'] ) ? $instance['failure_url'] : '';
		$autoresponder = isset( $instance['autoresponder'] ) ? $instance['autoresponder'] : '';

		echo wp_kses_post( $args['before_widget'] );
		if ( $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
		}

		// Load configuration data.
		$api_credentials = $this->options->get_api_credentials();
		$settings        = $this->options->get_settings();

		// Create admin template.
		$file     = $settings['is_advanced'] === true ? 'advanced.php' : 'basic.php';
		$template = new Smaily_Template( 'public/partials/smaily-public-' . $file );
		$template->assign(
			array(
				'domain'           => $api_credentials['subdomain'],
				'form'             => $settings['form'],
				'is_advanced'      => $settings['is_advanced'],
				'show_name'        => $show_name,
				'success_url'      => $success_url,
				'failure_url'      => $failure_url,
				'autoresponder_id' => $autoresponder,
			)
		);

		// Display responses on Smaily subscription form.
		$form_has_response  = false;
		$form_is_successful = false;
		$response_message   = null;

		if ( ! $this->options->has_credentials() ) {
			$form_has_response = true;
			$response_message  = __( 'Smaily credentials not validated. Subscription form will not work!', 'smaily' );
		} elseif ( isset( $_GET['code'] ) && (int) $_GET['code'] === 101 ) { // phpcs:ignore  WordPress.Security.NonceVerification.Recommended
			$form_is_successful = true;
		} elseif ( isset( $_GET['code'] ) || ! empty( $_GET['code'] ) ) { // phpcs:ignore  WordPress.Security.NonceVerification.Recommended
			$form_has_response = true;
			switch ( (int) $_GET['code'] ) { // phpcs:ignore  WordPress.Security.NonceVerification.Recommended
				case 201:
					$response_message = __( 'Form was not submitted using POST method.', 'smaily' );
					break;
				case 204:
					$response_message = __( 'Input does not contain a recognizable email address.', 'smaily' );
					break;
				default:
					$response_message = __( 'Could not add to subscriber list for an unknown reason. Probably something in Smaily.', 'smaily' );
					break;
			}
		}
		$template->assign(
			array(
				'form_has_response'  => $form_has_response,
				'response_message'   => $response_message,
				'form_is_successful' => $form_is_successful,
			)
		);

		// Render template.
		// Values are escaped in the template itself.
		// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $template->render();

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * This function should check that $new_instance is set correctly. The newly
	 * calculated value of $instance should be returned. If "false" is returned,
	 * the instance won't be saved/updated.
	 *
	 *
	 * @param  array $new_instance New instance.
	 * @param  array $old_instance Old instance.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance                  = $old_instance;
		$instance['title']         = sanitize_text_field( $new_instance['title'] );
		$instance['show_name']     = isset( $new_instance['show_name'] ) ? (bool) $new_instance['show_name'] : false;
		$instance['success_url']   = esc_url_raw( $new_instance['success_url'] );
		$instance['failure_url']   = esc_url_raw( $new_instance['failure_url'] );
		$instance['autoresponder'] = sanitize_text_field( $new_instance['autoresponder'] );

		return $instance;
	}

	/**
	 * Widget form on widgets page in admin panel.
	 *
	 *
	 * @param  array $instance Widget fields array.
	 */
	public function form( $instance ) {
		$instance = wp_parse_args(
			(array) $instance,
			array(
				'title'         => '',
				'show_name'     => isset( $instance['show_name'] ) ? (bool) $instance['show_name'] : false,
				'success_url'   => '',
				'failure_url'   => '',
				'autoresponder' => '',
			)
		);

		// Widget title.
		$title_id   = $this->get_field_id( 'title' );
		$title_name = $this->get_field_name( 'title' );
		echo '<p>
			<label for="' . esc_attr( $title_id ) . '">' . esc_html__( 'Title', 'smaily' ) . ':</label>
			<input class="widefat" id="' . esc_attr( $title_id ) . '" name="' . esc_attr( $title_name ) . '" type="text" value="' . esc_attr( $instance['title'] ) . '" />
		</p>';

		// Display checkbox for name field.
		$show_name_id          = $this->get_field_id( 'show_name' );
		$show_name_name        = $this->get_field_name( 'show_name' );
		$instance['show_name'] = esc_attr( $instance['show_name'] );
		echo '<p>
			<input class="checkbox" id="' . esc_attr( $show_name_id ) . '" name="' . esc_attr( $show_name_name ) . '" type="checkbox"' . ( $instance['show_name'] ? 'checked' : '' ) . ' />
			<label for="' . esc_attr( $show_name_id ) . '">' . esc_html__( 'Display name field?', 'smaily' ) . '</label>' .
			'</p>';

		// Display inputs for success/failure URLs.
		$success_url_id   = $this->get_field_id( 'success_url' );
		$success_url_name = $this->get_field_name( 'success_url' );
		echo '<p>
			<label for="' . esc_attr( $success_url_id ) . '">' . esc_html__( 'Success URL', 'smaily' ) . ':</label>
			<input id="' . esc_attr( $success_url_id ) . '" name="' . esc_attr( $success_url_name ) . '" type="text" value="' . esc_url( $instance['success_url'] ) . '" />
		</p>';

		$failure_url_id   = $this->get_field_id( 'failure_url' );
		$failure_url_name = $this->get_field_name( 'failure_url' );
		echo '<p>
			<label for="' . esc_attr( $failure_url_id ) . '">' . esc_html__( 'Failure URL', 'smaily' ) . ':</label>
			<input id="' . esc_attr( $failure_url_id ) . '" name="' . esc_attr( $failure_url_name ) . '" type="text" value="' . esc_url( $instance['failure_url'] ) . '" />
		</p>';

		// Display autoresponder select menu.
		$autoresponder_id = $this->get_field_id( 'autoresponder' );
		$autoresponder    = $this->get_field_name( 'autoresponder' );
		echo '<p>
			<label for="' . esc_attr( $autoresponder_id ) . '">' . esc_html__( 'Autoresponders', 'smaily' ) . ':</label>
			<select id="' . esc_attr( $autoresponder_id ) . '" name="' . esc_attr( $autoresponder ) . '">
			<option value="">' . esc_html__( 'No autoresponder', 'smaily' ) . '</option>';
		foreach ( $this->admin_model->get_autoresponders() as $id => $title ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $instance['autoresponder'], $id, false ) . '>' . esc_attr( $title ) . '</option>';
		}
		echo '</select></p>';
	}
}
