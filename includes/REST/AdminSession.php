<?php
/**
 * The signed-in admin's session check and email, shared by the admin routes
 * the bundle calls with a `wp_rest` nonce.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use WP_Error;
use WP_REST_Request;

/**
 * ConsoleEntryEndpoint, OnboardingEndpoint and NoticesEndpoint: a signed-in
 * user, a valid `wp_rest` nonce in `X-WP-Nonce` (checked here too, not left
 * to cookie auth alone) and `manage_options`. A class says its own
 * no-capability text by overriding capability_error().
 */
trait AdminSession {

	/**
	 * The admin's own session, the CSRF nonce and the capability.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_check( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! is_user_logged_in() || $nonce === '' || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'Your session has expired. Reload the page and try again.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				$this->capability_error(),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/** The 403 text for a signed-in user without the capability. */
	protected function capability_error(): string {
		return __( 'You do not have permission to manage Campaign Intelligence.', 'smaily-connect' );
	}

	/**
	 * The signed-in admin's own email — never a customer's — or ''. 254 is
	 * the engine's limit on it (§21 `admin_email`, §24 `email`).
	 */
	private static function admin_email(): string {
		$email = trim( (string) wp_get_current_user()->user_email );
		return ( $email !== '' && strlen( $email ) <= 254 && is_email( $email ) !== false ) ? $email : '';
	}
}
