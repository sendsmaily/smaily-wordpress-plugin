<?php

/**
 * Defines the widget functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

use Smaily_Admin\Admin;

class Smaily_Widget extends WP_Widget {
	/**
	 * Admin model.
	 *
	 *
	 * @access private
	 * @var    Admin
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
	 * @param Admin   $admin_model Reference to admin class.
	 */
	public function __construct( Smaily_Options $options, Admin $admin_model ) {
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
		// Allow overriding the template.
		$template = locate_template( 'smaily/smaily-public-basic.php' );
		if ( ! $template ) {
			$template = SMAILY_PLUGIN_PATH . 'public/partials/smaily-public-basic.php';
		}

		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		echo wp_kses_post( $args['before_widget'] );

		if ( $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
		}

		$autoresponder_id   = isset( $instance['autoresponder'] ) ? $instance['autoresponder'] : '';
		$failure_url        = empty( $instance['failure_url'] ) ?  Smaily_Helper::get_current_url(): $instance['failure_url'];
		$form_has_response  = false;
		$form_is_successful = false;
		$language_code      = Smaily_Helper::get_current_language_code();
		$response_message   = null;
		$show_name          = isset( $instance['show_name'] ) ? $instance['show_name'] : false;
		$subdomain          = $this->options->get_subdomain();
		$success_url        = empty( $instance['success_url'] ) ? Smaily_Helper::get_current_url() : $instance['success_url'];

		if ( ! $this->options->has_credentials() ) {
			$form_has_response = true;
			$response_message  = __( 'Smaily credentials not validated. Subscription form will not work!', 'smaily' );
		}

		$code = isset( $_GET['code'] ) ? intval( $_GET['code'] ) : null;
		switch ( $code ) {
			case null:
				break;
			case 101:
				$form_is_successful = true;
				break;
			case 201:
				$form_has_response = true;
				$response_message  = __( 'Form was not submitted using POST method.', 'smaily' );
				break;
			case 204:
				$form_has_response = true;
				$response_message  = __( 'Input does not contain a recognizable email address.', 'smaily' );
				break;
			default:
				$form_has_response = true;
				$response_message  = __( 'Could not add to subscriber list for an unknown reason. Probably something in Smaily.', 'smaily' );
				break;
		}

		Smaily_Public::render_basic_form(
			compact(
				'autoresponder_id',
				'failure_url',
				'form_has_response',
				'form_is_successful',
				'language_code',
				'response_message',
				'show_name',
				'subdomain',
				'success_url'
			)
		);

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
		foreach ( $this->get_autoresponders() as $id => $title ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $instance['autoresponder'], $id, false ) . '>' . esc_attr( $title ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * Make a request to Smaily asking for autoresponders.
	 * Request is authenticated via saved credentials.
	 *
	 * @return array List of autoresponders in format [id => title].
	 */
	private function get_autoresponders() {
		if ( ! $this->options->has_credentials() ) {
			return array();
		}

		$request = new Smaily_Request( $this->options );
		$result  = $request->list_autoresponders();

		if ( empty( $result['body'] ) ) {
			return array();
		}

		$autoresponder_list = array();
		foreach ( $result['body'] as $autoresponder ) {
			$id                        = $autoresponder['id'];
			$title                     = $autoresponder['title'];
			$autoresponder_list[ $id ] = $title;
		}
		return $autoresponder_list;
	}
}
