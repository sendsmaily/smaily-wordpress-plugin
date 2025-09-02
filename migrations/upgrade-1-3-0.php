<?php

/**
 * Upgrade the structure of Contact Form 7 integration.
 *
 * Previously, the integration used a single array to store the
 * integration settings. This upgrade will change the structure to use
 * a dictionary with form IDs as keys and their settings as values.
 *
 * This allows users to add more than one form with its own
 * unique settings.
 *
 * Previous: ['is_enabled' => int, 'autoresponder_id' => int]
 * Current: [form_id => ['is_enabled' => bool, 'autoresponder_id' => int]]
 *
 * @since 1.3.0
 */

require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-options.class.php';

use Smaily_Connect\Includes\Options;

$upgrade = function () {
	$current_settings = get_option( Options::CONTACT_FORM_7_STATUS_OPTION, array() );

	if ( ! is_array( $current_settings ) || empty( $current_settings ) ) {
		// No settings to upgrade.
		return;
	}

	if ( integration_disabled( $current_settings ) ) {
		// We don't have to migrate forms nor show admin notices.
		update_option( Options::CONTACT_FORM_7_STATUS_OPTION, array() );
		return;
	}

	$forms = get_posts(
		array(
			'numberposts' => -1,
			'post_type'   => 'wpcf7_contact_form',
		)
	);

	if ( empty( $forms ) ) {
		// No forms found, but the integration is enabled.
		// Form has been removed.
		update_option( Options::CONTACT_FORM_7_STATUS_OPTION, array() );
		return;
	}

	if ( isset( $current_settings[ $forms[0]->ID ] ) ) {
		// Safeguard: Malformed settings or already migrated.
		return;
	}

	if ( count( $forms ) === 1 ) {
		// We can migrate a single form using previous settings.
		$settings = array(
			$forms[0]->ID => array(
				'enabled'          => isset( $current_settings['is_enabled'] ) ? (bool) $current_settings['is_enabled'] : false,
				'autoresponder_id' => isset( $current_settings['autoresponder_id'] ) ? (int) $current_settings['autoresponder_id'] : 0,
			),
		);

		update_option( Options::CONTACT_FORM_7_STATUS_OPTION, $settings );
	} else {
		// Remove previous settings.
		update_option( Options::CONTACT_FORM_7_STATUS_OPTION, array() );
		// User needs to manually configure each form.
		set_transient( 'smaily_connect_1_3_0_upgrade_notice', true );
	}
};

/**
 * Check if Smaily Contact Form 7 integration is enabled.
 *
 * @param array $settings
 * @return bool
 */
function integration_disabled( array $settings ): bool {
	return empty( $settings ) || ( isset( $settings['is_enabled'] ) && ! (bool) $settings['is_enabled'] );
}

$notice = function () {
	if ( get_transient( 'smaily_connect_1_3_0_upgrade_notice' ) ) {
		add_action( 'admin_notices', 'smaily_connect_1_3_0_upgrade_notice' );
		add_action( 'wp_ajax_smaily_connect_1_3_0_dismiss_upgrade_notice', 'smaily_connect_1_3_0_dismiss_upgrade_notice' );
	}
};

function smaily_connect_1_3_0_upgrade_notice() {
	if ( current_user_can( 'manage_options' ) ) {
		if ( get_user_meta( get_current_user_id(), 'smaily_connect_1_3_0_upgrade_notice_dismissed', true ) ) {
			return;
		}
		?>
		<div id="smaily-connect-1-3-0-upgrade-notice" class="notice notice-warning is-dismissible">
			<p>
				<?php esc_html_e( 'Multiple Contact Form 7 forms detected! Please review Smaily Connect integration settings for your forms. You can now configure each form individually.', 'smaily-connect' ); ?>
			</p>
		</div>
		<script>
			jQuery(document).ready(function($){
				$('#smaily-connect-1-3-0-upgrade-notice').on('click', '.notice-dismiss', function() {
					// Dismiss the notice via AJAX.
					$.post(
						ajaxurl,
						{
							action: 'smaily_connect_1_3_0_dismiss_upgrade_notice',
							nonce: '<?php echo esc_attr( wp_create_nonce( 'smaily_connect_1_3_0_upgrade_notice' ) ); ?>'
						},
						function(response) {
							if (response.success) {
								$('#smaily-connect-1-3-0-upgrade-notice').fadeOut();
							}
						}
					);
				});
			});
		</script>
		<?php
	}
}

function smaily_connect_1_3_0_dismiss_upgrade_notice() {
	check_ajax_referer( 'smaily_connect_1_3_0_upgrade_notice', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		$err = new WP_Error( 'forbidden', 'You do not have permission to perform this action.' );
		wp_send_json_error( $err );
	}

	update_user_meta( get_current_user_id(), 'smaily_connect_1_3_0_upgrade_notice_dismissed', true );

	wp_send_json_success();
}
