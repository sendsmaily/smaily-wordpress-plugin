<?php

namespace Smaily_Connect\Includes;

defined( 'ABSPATH' ) || exit;

use Smaily_Connect\Public_Base;
use WP_Widget;

class Widget extends WP_Widget {
	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * Sets up a new instance of the widget.
	 *
	 * @param Options $options     Reference to options handler class.
	 */
	public function __construct( Options $options ) {
		$widget_ops = array( 'description' => __( 'Smaily Classic Subscription Widget', 'smaily-connect' ) );
		parent::__construct( 'smaily_connect_subscription_widget', __( 'Smaily Classic Subscription Widget', 'smaily-connect' ), $widget_ops );

		$this->options = $options;
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
			$template = SMAILY_CONNECT_PLUGIN_PATH . 'public/partials/smaily-public-basic.php';
		}

		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		echo wp_kses_post( $args['before_widget'] );

		if ( $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
		}

		$autoresponder_id = isset( $instance['autoresponder_id'] ) ? $instance['autoresponder_id'] : '';
		$failure_url      = empty( $instance['failure_url'] ) ? Helper::get_current_url() : $instance['failure_url'];
		$language_code    = Helper::get_current_language_code();
		$has_credentials  = $this->options->has_credentials();
		$show_name        = isset( $instance['show_name'] ) ? $instance['show_name'] : false;
		$subdomain        = $this->options->get_subdomain();
		$success_url      = empty( $instance['success_url'] ) ? Helper::get_current_url() : $instance['success_url'];

		Public_Base::render_template(
			$template,
			compact(
				'autoresponder_id',
				'failure_url',
				'has_credentials',
				'language_code',
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
		$instance                     = $old_instance;
		$instance['title']            = sanitize_text_field( $new_instance['title'] );
		$instance['show_name']        = isset( $new_instance['show_name'] ) ? (bool) $new_instance['show_name'] : false;
		$instance['success_url']      = sanitize_url( $new_instance['success_url'] );
		$instance['failure_url']      = sanitize_url( $new_instance['failure_url'] );
		$instance['autoresponder_id'] = sanitize_text_field( $new_instance['autoresponder_id'] );

		return $instance;
	}

	/**
	 * Widget form on widgets page in admin panel.
	 */
	public function form( $instance ) {
		$instance = wp_parse_args(
			(array) $instance,
			array(
				'title'            => '',
				'show_name'        => isset( $instance['show_name'] ) ? (bool) $instance['show_name'] : false,
				'success_url'      => '',
				'failure_url'      => '',
				'autoresponder_id' => '',
			)
		);

		$autoresponders = Helper::get_autoresponders_list( $this->options );
		// PRO-1334: mirrors CF7's PRO-1277 preserve-and-flag — a previously saved
		// binding may point at a workflow since disabled (or removed) in Smaily.
		// Keep it visible/selectable instead of letting the browser silently
		// submit "No autoresponder" on the next save.
		$autoresponder_unavailable = Helper::is_autoresponder_unavailable(
			(int) $instance['autoresponder_id'],
			$autoresponders
		);
		$title_id                  = $this->get_field_id( 'title' );
		$title_name                = $this->get_field_name( 'title' );
		$show_name_id              = $this->get_field_id( 'show_name' );
		$show_name_name            = $this->get_field_name( 'show_name' );
		$success_url_id            = $this->get_field_id( 'success_url' );
		$success_url_name          = $this->get_field_name( 'success_url' );
		$failure_url_id            = $this->get_field_id( 'failure_url' );
		$failure_url_name          = $this->get_field_name( 'failure_url' );
		$autoresponder_id          = $this->get_field_id( 'autoresponder_id' );
		$autoresponder_id_name     = $this->get_field_name( 'autoresponder_id' );

		?>
		<p>
			<label for="<?php echo esc_attr( $title_name ); ?>"><?php esc_html_e( 'Title', 'smaily-connect' ); ?>:</label>
			<input class="widefat" id="<?php echo esc_attr( $title_id ); ?>" name="<?php echo esc_attr( $title_name ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>
		<p>
			<input class="checkbox" id="<?php echo esc_attr( $show_name_id ); ?>" name="<?php echo esc_attr( $show_name_name ); ?>" type="checkbox" <?php echo checked( $instance['show_name'] ); ?> value="1" />
			<label for="<?php echo esc_attr( $show_name_id ); ?>"><?php esc_html_e( 'Display name field', 'smaily-connect' ); ?></label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $success_url_id ); ?>"><?php esc_html_e( 'Success URL', 'smaily-connect' ); ?>:</label>
			<input id="<?php echo esc_attr( $success_url_id ); ?>" name="<?php echo esc_attr( $success_url_name ); ?>" type="text" value="<?php echo esc_url( $instance['success_url'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $failure_url_id ); ?>"><?php esc_html_e( 'Failure URL', 'smaily-connect' ); ?>:</label>
			<input id="<?php echo esc_attr( $failure_url_id ); ?>" name="<?php echo esc_attr( $failure_url_name ); ?>" type="text" value="<?php echo esc_url( $instance['failure_url'] ); ?>" />
		</p>
		<?php esc_html_e( 'Note: URLs are optional. If left empty, the current page URL will be used.', 'smaily-connect' ); ?>
		<p>
			<label for="<?php echo esc_attr( $autoresponder_id ); ?>"><?php esc_html_e( 'Autoresponder ID', 'smaily-connect' ); ?>:</label>
			<select id="<?php echo esc_attr( $autoresponder_id ); ?>" name="<?php echo esc_attr( $autoresponder_id_name ); ?>">
				<option value=""><?php esc_html_e( 'No autoresponder', 'smaily-connect' ); ?></option>
				<?php if ( $autoresponder_unavailable ) : ?>
					<option value="<?php echo esc_attr( $instance['autoresponder_id'] ); ?>" selected="selected">
						<?php
						printf(
							/* translators: %d: Smaily workflow ID. */
							esc_html__( 'Workflow #%d (disabled in Smaily)', 'smaily-connect' ),
							(int) $instance['autoresponder_id']
						);
						?>
					</option>
				<?php endif; ?>
				<?php foreach ( $autoresponders as $id => $title ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $instance['autoresponder_id'], $id ); ?>><?php echo esc_attr( $title ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $autoresponder_unavailable ) : ?>
				<p class="notice notice-warning inline">
					<?php esc_html_e( 'This workflow is disabled in Smaily (or no longer exists) — form submissions will not trigger anything until you enable it in Smaily or pick another workflow.', 'smaily-connect' ); ?>
				</p>
			<?php endif; ?>
		</p>
		<?php
	}
}
