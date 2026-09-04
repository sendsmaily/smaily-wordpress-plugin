<?php

namespace Smaily_Connect\Admin;

defined( 'ABSPATH' ) || exit;

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
		$notices              = Notice_Registry::get_notices();
		$needs_dismiss_script = false;

		foreach ( $notices as $id => $notice ) {
			if ( ! current_user_can( $notice['capability'] ) ) {
				continue;
			}

			if ( Notice_Registry::is_dismissed( $id ) ) {
				continue;
			}

			if ( ! empty( $notice['dismissible'] ) ) {
				$needs_dismiss_script = true;
			}

			$this->render_notice( $id, $notice );
		}

		// Enqueue the dismissal script only when a dismissible notice is shown.
		// Enqueuing during admin_notices registers it for the footer, which has
		// not printed yet, so a post-loop enqueue is in time.
		if ( $needs_dismiss_script ) {
			$this->enqueue_dismiss_script();
		}
	}

	/**
	 * Enqueue the notice-dismiss script that persists a dismissal via AJAX.
	 *
	 * Replaces the former inline <script> in the notice partial (wordpress.org
	 * plugin-review discourages hardcoded inline scripts).
	 */
	private function enqueue_dismiss_script() {
		$relative = 'admin/js/notice-dismiss.js';
		$path     = SMAILY_CONNECT_PLUGIN_PATH . $relative;

		wp_enqueue_script(
			'smaily-connect-notice-dismiss',
			SMAILY_CONNECT_PLUGIN_URL . '/' . $relative,
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : SMAILY_CONNECT_VERSION,
			true
		);

		wp_localize_script(
			'smaily-connect-notice-dismiss',
			'smailyConnectNoticeDismiss',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'smaily_connect_dismiss_notice' ),
			)
		);
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

		require SMAILY_CONNECT_PLUGIN_PATH . 'admin/partials/smaily-admin-notice.php';
	}

	/**
	 * Dismiss an admin notice.
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'smaily_connect_dismiss_notice', 'nonce' );

		if ( ! isset( $_POST['id'] ) ) {
			$err = new \WP_Error( 'missing_id', 'The notice ID is missing.' );
			wp_send_json_error( $err );
		}

		$notice_id = sanitize_text_field( wp_unslash( $_POST['id'] ) );
		Notice_Registry::dismiss_notice( $notice_id );

		wp_send_json_success();
	}
}
