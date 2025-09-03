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
		$notices = Notice_Registry::get_notices();

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
		if ( ! isset( $notice['template'] ) || empty( $notice['template'] ) ) {
			return;
		}

		$template_path = SMAILY_CONNECT_PLUGIN_PATH . 'admin/partials/notices/' . $notice['template'] . '.php';
		if ( ! file_exists( $template_path ) ) {
			return;
		}

		$id;
		$notice;
		require SMAILY_CONNECT_PLUGIN_PATH . 'admin/partials/smaily-admin-notice.php';
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
