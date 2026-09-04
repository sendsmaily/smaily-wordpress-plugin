<?php
/**
 * Removes the legacy Smaily_Connect subscriber-sync hooks once the new
 * wizard is finished, so contact sync is never owned by both code paths.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Backward-compat bridge (P1 #1, audit). The BETA fork loads the legacy
 * Smaily_Connect plugin verbatim alongside the new namespaced code; the
 * legacy Subscriber_Synchronization service registers its own WooCommerce
 * contact-sync hooks (woocommerce_created_customer, save_account_details,
 * checkout_order_processed, the user-profile updates) at plugin load.
 *
 * The new HookHandler binds the SAME events. To guarantee "never both":
 *   - Before wizard Finish: HookHandler::gate_closed() keeps the new path
 *     dormant; only the legacy service syncs.
 *   - After Finish (smly_plus_setup_completed === true): this bridge strips
 *     the legacy service's hooks so only the new path syncs.
 *
 * Why strip by class match through $wp_filter rather than remove_action()
 * with the original callable: the legacy service instance lives privately
 * inside the Smaily_Connect object created in smaily-connect.php and isn't
 * exposed, so we can't hand remove_action() the exact ($object, 'method')
 * pair. Matching on the legacy class via the global hook registry removes
 * every one of its callbacks without needing the reference.
 *
 * Why this must run every request (not just in save_finish): WordPress
 * hook registrations are per-request — the legacy service re-adds its
 * hooks on every load. A one-time remove_action() in the Finish REST call
 * would lapse on the very next page view. Bootstrap calls deregister() on
 * `init` (after the legacy registered at load, before WC events fire);
 * save_finish() also calls it for immediate effect within the Finish
 * request.
 *
 * Deliberately leaves NON-sync legacy hooks alone — e.g. cart.class.php's
 * woocommerce_checkout_order_processed → smaily_checkout_delete_cart is
 * cart-abandonment cleanup, not contact sync, and stripping it would break
 * legacy abandoned-cart behaviour. Only Subscriber_Synchronization is
 * targeted.
 */
final class LegacyHookBridge {

	/**
	 * Fully-qualified legacy service whose hooks we strip. Kept as a string
	 * (not a ::class ref) because the legacy class lives in the legacy
	 * namespace that new code never imports.
	 */
	public const LEGACY_SUBSCRIBER_SYNC = 'Smaily_Connect\\Integrations\\WooCommerce\\Subscriber_Synchronization';

	/**
	 * Remove every callback registered by the legacy subscriber-sync service.
	 *
	 * @return array<int, string> "{hook}:{priority}" entries removed — used
	 *                            by the integration test + debug logging.
	 */
	public static function deregister_subscriber_sync(): array {
		global $wp_filter;

		$removed = array();

		if ( ! is_array( $wp_filter ) ) {
			return $removed;
		}

		foreach ( $wp_filter as $hook_name => $hook ) {
			if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				continue;
			}

			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) || ! is_object( $function[0] ) ) {
						continue;
					}
					// is_a() with the class as a string: the legacy class lives
					// outside the namespaced tree phpstan analyses, so a
					// get_class() === comparison reads as always-false to the
					// analyser. is_a() is a runtime check it doesn't fold away,
					// and it matches subclasses too (harmless here).
					if ( ! is_a( $function[0], self::LEGACY_SUBSCRIBER_SYNC ) ) {
						continue;
					}

					remove_action( (string) $hook_name, $function, (int) $priority );
					$removed[] = $hook_name . ':' . $priority;
				}
			}
		}

		return $removed;
	}
}
