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

namespace Smaily_Connect\Migrations;

use Smaily_Connect\Includes\Notice_Registry;
use Smaily_Connect\Includes\Options;

$upgrade = function () {
	$current_settings = get_option( Options::CONTACT_FORM_7_STATUS_OPTION, array() );

	if ( ! is_array( $current_settings ) || empty( $current_settings ) ) {
		// No settings to upgrade.
		return;
	}

	if ( integration_disabled( $current_settings ) ) {
		// We don't have to migrate forms nor show admin notices.
		return remove_legacy_keys( $current_settings );
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
		return remove_legacy_keys( $current_settings );
	}

	if ( forms_migrated( $forms, $current_settings ) ) {
		// Safeguard.
		return remove_legacy_keys( $current_settings );
	}

	if ( count( $forms ) === 1 ) {
		// We can migrate a single form using previous settings.
		$settings = array(
			$forms[0]->ID => array(
				'is_enabled'       => isset( $current_settings['is_enabled'] ) ? (bool) $current_settings['is_enabled'] : false,
				'autoresponder_id' => isset( $current_settings['autoresponder_id'] ) ? (int) $current_settings['autoresponder_id'] : 0,
			),
		);

		update_option( Options::CONTACT_FORM_7_STATUS_OPTION, $settings );
	} else {
		// Remove previous settings.
		remove_legacy_keys( $current_settings );
		Notice_Registry::add_notice(
			'smaily_connect_1_3_0_upgrade',
			'1_3_0_upgrade_cf7_notice'
		);
	}
};

/**
 * Check if Smaily Contact Form 7 integration was previously enabled.
 *
 * @param array $settings
 * @return bool
 */
function integration_disabled( array $settings ): bool {
	return empty( $settings ) || ( isset( $settings['is_enabled'] ) && ! (bool) $settings['is_enabled'] );
}

/**
 * Check if forms have already been migrated.
 *
 * @param array $forms
 * @param array $settings
 * @return bool
 */
function forms_migrated( array $forms, array $settings ): bool {
	foreach ( $forms as $form ) {
		if ( isset( $settings[ $form->ID ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Remove legacy keys from the settings.
 *
 * @param array $settings
 */
function remove_legacy_keys( array $settings ) {
	unset( $settings['is_enabled'] );
	unset( $settings['autoresponder_id'] );
	update_option( Options::CONTACT_FORM_7_STATUS_OPTION, $settings );
}
