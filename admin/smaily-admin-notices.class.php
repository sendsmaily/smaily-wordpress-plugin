<?php

namespace Smaily_Connect\Admin;

use Smaily_Connect\Includes\Notice_Registry;

class Notices {

	/**
	 * Register hooks for admin notices.
	 */
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
		add_action( 'wp_ajax_smaily_connect_dismiss_notice', array( $this, 'dismiss_notice' ) );
	}


	/**
	 * Display admin notices.
	 */
	public function display_notices() {
		$notices      = Notice_Registry::get_notices();

		foreach ( $notices as $id => $notice ) {
			if ( ! current_user_can( $notice['capability'] ) ) {
				continue;
			}

			if ( Notice_Registry::is_dismissed( $id ) ) {
				continue;
			}

			$this->render_notice( $id, $notice );
		}
	}


	/**
	 * Render a single admin notice.
	 *
	 * @param string $id The notice ID.
	 * @param array  $notice The notice data.
	 */
	private function render_notice( string $id, array $notice ) {
		?>
		<div
			id="<?php echo esc_attr( $id ); ?>" 
			class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> 
			<?php echo $notice['dismissible'] ? 'is-dismissible' : ''; ?>">
			<p>
				<?php echo esc_html( $notice['message'] ); ?>
			</p>
		</div>
		<script>
			jQuery(document).ready(function($){
				$('#<?php echo esc_attr( $id ); ?>').on('click', '.notice-dismiss', function() {
					// Dismiss the notice via AJAX.
					$.post(
						ajaxurl,
						{
							action: 'smaily_connect_dismiss_notice',
							id: '<?php echo esc_js( $id ); ?>',
							nonce: '<?php echo esc_attr( wp_create_nonce( 'smaily_connect_dismiss_notice' ) ); ?>'
						},
						function(response) {
							if (response.success) {
								$('#<?php echo esc_attr( $id ); ?>').fadeOut();
							}
						}
					);
				});
			});
		</script>
		<?php
	}

	/**
	 * Dismiss an admin notice.
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'smaily_connect_dismiss_notice', 'nonce' );

		if ( ! isset( $_POST['id'] ) ) {
			$err = new WP_Error( 'missing_id', 'The notice ID is missing.' );
			wp_send_json_error( $err );
		}

		$notice_id = sanitize_text_field( wp_unslash( $_POST['id'] ) );
		Notice_Registry::dismiss_notice( $notice_id );

		wp_send_json_success();
	}
}
