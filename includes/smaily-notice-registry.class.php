<?php

namespace Smaily_Connect\Includes;

class Notice_Registry {

	/**
	 * Add a notice to the notice registry.
	 *
	 * @param string $id
	 * @param string $message
	 * @param array $args
	 *
	 * @since 1.3.0
	 */
	public static function add_notice( string $id, string $message, array $args = array() ) {
		$defaults = array(
			'capability'  => 'manage_options',
			'dismissible' => true,
			'type'        => 'info',
		);

		$args = wp_parse_args( $args, $defaults );

		$notices        = self::get_notices();
		$notices[ $id ] = array(
			'capability'  => $args['capability'],
			'dismissible' => $args['dismissible'],
			'message'     => $message,
			'type'        => $args['type'],
		);

		update_option( Options::NOTICE_REGISTRY_OPTION, $notices );
	}

	/**
	 * Get all notices from the notice registry.
	 *
	 * @since 1.3.0
	 */
	public static function get_notices() {
		return get_option( Options::NOTICE_REGISTRY_OPTION, array() );
	}

	/**
	 * Remove a notice from the notice registry.
	 * This will remove notice for all users.
	 *
	 * @param string $id
	 *
	 * @since 1.3.0
	 */
	public static function remove_notice( string $id ) {
		$notices = self::get_notices();

		if ( isset( $notices[ $id ] ) ) {
			unset( $notices[ $id ] );
			update_option( Options::NOTICE_REGISTRY_OPTION, $notices );
		}
	}

	/**
	 * Dismiss a notice for a user.
	 *
	 * @param string $id
	 * @param int|null $user_id
	 *
	 * @since 1.3.0
	 */
	public static function dismiss_notice( string $id, int $user_id = null ) {
		$current_user = $user_id ? $user_id : get_current_user_id();
		$dismissed    = get_user_meta( $current_user, 'smaily_connect_notice_dismissed', true );

		$dismissed = array_unique( array_merge( (array) $dismissed, array( $id ) ) );
		update_user_meta( $current_user, 'smaily_connect_notice_dismissed', $dismissed );
	}


	/**
	 * Check if a notice is dismissed for a user.
	 *
	 * @param string $id
	 * @param int|null $user_id
	 *
	 * @since 1.3.0
	 */
	public static function is_dismissed( string $id, int $user_id = null ): bool {
		$current_user = $user_id ? $user_id : get_current_user_id();
		$dismissed    = get_user_meta( $current_user, 'smaily_connect_notice_dismissed', true );

		if ( empty( $dismissed ) ) {
			return false;
		}

		if ( ! is_array( $dismissed ) ) {
			return false;
		}

		return in_array( $id, $dismissed, true );
	}
}
