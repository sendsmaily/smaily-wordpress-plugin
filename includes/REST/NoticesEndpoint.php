<?php
/**
 * REST route the admin bundle closes a banner through (CON-350).
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Notifications\NotificationManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 *   POST /notices/dismiss   { key }
 *     → 200 { dismissed: true } — the dismissal is stamped in the same
 *       per-key map the admin notices' Dismiss links write
 *       (NotificationManager::OPTION_DISMISSED); each key's own cooldown
 *       decides when it returns.
 *     → 400 `unknown_key` for a key the admin bundle does not close.
 *
 * Only the Campaign Intelligence banner on top of Settings is closed here;
 * the PHP-rendered notices keep their admin-post link.
 *
 * Auth: a signed-in user, a valid `wp_rest` nonce in `X-WP-Nonce` (checked
 * here too, not left to cookie auth alone) and `manage_options`.
 */
class NoticesEndpoint {

	use AdminSession;

	public const ROUTE = '/notices/dismiss';

	/** The keys this route may stamp. */
	private const KEYS = array( NotificationManager::CI_BANNER_KEY );

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => array( $this, 'admin_check' ),
			)
		);
	}

	/** This route closes a banner; it manages no Campaign Intelligence. */
	protected function capability_error(): string {
		return __( 'You do not have permission to view this page.', 'smaily-connect' );
	}

	public function dismiss( WP_REST_Request $request ): WP_REST_Response {
		$key = $request->get_param( 'key' );
		if ( ! is_string( $key ) || ! in_array( $key, self::KEYS, true ) ) {
			return new WP_REST_Response( array( 'error' => 'unknown_key' ), 400 );
		}

		NotificationManager::record_dismissal( $key );

		return new WP_REST_Response( array( 'dismissed' => true ), 200 );
	}
}
