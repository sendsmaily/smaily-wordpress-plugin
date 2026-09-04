<?php
/**
 * WC cart hooks → the namespaced abandoned-cart tracker (PRO-1195).
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Smaily\CartSessionStore;

/**
 * Tracks live cart sessions into `smly_plus_cart_session` — the capture side
 * of the abandoned-cart rewrite. Replaces the legacy
 * `Smaily_Connect\Integrations\WooCommerce\Cart` (which stored
 * `serialize(get_cart())` and only saw logged-in users).
 *
 * What it captures:
 *   - every cart change (`woocommerce_cart_updated`) upserts one row per WC
 *     session (`cart_token` = the session customer id — the user id for
 *     logged-in customers, an opaque hash for GUESTS), with our own minimal
 *     item shape [{product_id, variation_id, quantity}];
 *   - an email identity as soon as one is known: the logged-in user, a
 *     session-persisted billing email, or a checkout-entered email (classic
 *     `woocommerce_checkout_update_order_review` POST / the Store API
 *     update-customer route). A cart without an email is tracked but never
 *     synced (design rule: sync only once an identity is known).
 *
 * What clears a row: a completed order (classic + Store API + thank-you,
 * the legacy hook set) or emptying the cart. Clearing runs UNGATED — it is
 * data hygiene, and rows must not linger after the merchant toggles the
 * feature off mid-flight.
 *
 * Tracking gate: the merchant's abandoned-cart toggle (normalized F3-54
 * read) AND `smly_plus_setup_completed` — an un-wizarded store can never
 * send, so it shouldn't accumulate cart PII either.
 *
 * Not final: tests inject a recording store double.
 */
class CartHookHandler {

	/** @var bool Per-request guard: woocommerce_cart_updated can fire several times per request. */
	private static bool $tracked_this_request = false;

	private CartSessionStore $store;

	public function __construct( CartSessionStore $store ) {
		$this->store = $store;
	}

	/** Reset the per-request guard — tests only. */
	public static function reset_request_guard(): void {
		self::$tracked_this_request = false;
	}

	/**
	 * `woocommerce_cart_updated` — upsert the session's tracker row.
	 */
	public function on_cart_updated(): void {
		if ( self::$tracked_this_request || ! $this->tracking_enabled() ) {
			return;
		}

		if ( $this->is_admin_context() ) {
			return;
		}

		$cart  = $this->wc_cart();
		$token = $this->session_token();
		if ( $cart === null || $token === '' ) {
			return;
		}

		self::$tracked_this_request = true;

		if ( $cart->is_empty() ) {
			// Legacy parity: an emptied cart leaves the tracker (a later cart
			// starts a fresh row — and may earn a fresh reminder).
			$this->store->delete_by_token( $token );
			return;
		}

		$items = array();
		foreach ( (array) $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) || ! isset( $cart_item['product_id'] ) || ! is_scalar( $cart_item['product_id'] ) ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $cart_item['product_id'],
				'variation_id' => isset( $cart_item['variation_id'] ) && is_scalar( $cart_item['variation_id'] )
					? (int) $cart_item['variation_id']
					: 0,
				'quantity'     => isset( $cart_item['quantity'] ) && is_scalar( $cart_item['quantity'] )
					? (int) $cart_item['quantity']
					: 1,
			);
		}

		$identity = $this->current_identity();

		$this->store->upsert(
			$token,
			$identity['user_id'],
			$identity['email'],
			$identity['first_name'],
			$identity['last_name'],
			$items
		);

		// A login migrates the guest cart onto a new session token; drop any
		// old row carrying the same email so one shopper never gets two
		// reminders for one cart.
		if ( $identity['email'] !== '' ) {
			$this->store->delete_other_rows_for_email( $identity['email'], $token );
		}
	}

	/**
	 * Classic checkout AJAX (`woocommerce_checkout_update_order_review`) —
	 * the moment a GUEST's typed billing email becomes visible server-side.
	 *
	 * @param string $posted_data The serialized checkout form data WC posts.
	 */
	public function on_checkout_update_order_review( $posted_data ): void {
		if ( ! $this->tracking_enabled() || ! is_string( $posted_data ) ) {
			return;
		}

		$token = $this->session_token();
		if ( $token === '' ) {
			return;
		}

		parse_str( $posted_data, $form );

		$email = isset( $form['billing_email'] ) && is_string( $form['billing_email'] )
			? sanitize_email( wp_unslash( $form['billing_email'] ) )
			: '';
		if ( $email === '' || ! is_email( $email ) ) {
			return;
		}

		$first = isset( $form['billing_first_name'] ) && is_string( $form['billing_first_name'] )
			? sanitize_text_field( wp_unslash( $form['billing_first_name'] ) )
			: '';
		$last  = isset( $form['billing_last_name'] ) && is_string( $form['billing_last_name'] )
			? sanitize_text_field( wp_unslash( $form['billing_last_name'] ) )
			: '';

		$this->store->set_identity( $token, $email, $first, $last );
	}

	/**
	 * Store API checkout (`woocommerce_store_api_cart_update_customer_from_request`)
	 * — the block-checkout twin of the classic identity capture.
	 *
	 * @param mixed $customer WC_Customer being updated from the request.
	 * @param mixed $request  The Store API request (unused).
	 */
	public function on_store_api_update_customer( $customer, $request = null ): void {
		unset( $request );

		if ( ! $this->tracking_enabled() || ! is_object( $customer ) || ! method_exists( $customer, 'get_billing_email' ) ) {
			return;
		}

		$token = $this->session_token();
		if ( $token === '' ) {
			return;
		}

		$email = sanitize_email( (string) $customer->get_billing_email() );
		if ( $email === '' || ! is_email( $email ) ) {
			return;
		}

		$first = method_exists( $customer, 'get_billing_first_name' )
			? sanitize_text_field( (string) $customer->get_billing_first_name() )
			: '';
		$last  = method_exists( $customer, 'get_billing_last_name' )
			? sanitize_text_field( (string) $customer->get_billing_last_name() )
			: '';

		$this->store->set_identity( $token, $email, $first, $last );
	}

	/**
	 * Classic checkout completed (`woocommerce_checkout_order_processed`) /
	 * thank-you page (`woocommerce_thankyou`).
	 *
	 * @param int|string $order_id
	 */
	public function on_order_processed( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : false;
		$this->clear_for_order( $order instanceof \WC_Order ? $order : null );
	}

	/**
	 * Store API checkout completed (`woocommerce_store_api_checkout_order_processed`).
	 *
	 * @param mixed $order WC_Order.
	 */
	public function on_store_api_order_processed( $order ): void {
		$this->clear_for_order( $order instanceof \WC_Order ? $order : null );
	}

	/**
	 * An order clears every tracker row the buyer could own: the current
	 * session token, the order's customer id, and the billing email (the
	 * token can rotate between guest and logged-in sessions). Runs UNGATED —
	 * hygiene must survive a mid-flight feature toggle.
	 */
	private function clear_for_order( ?\WC_Order $order ): void {
		$token = $this->session_token();
		if ( $token !== '' ) {
			$this->store->delete_by_token( $token );
		}

		if ( $order === null ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$this->store->delete_by_user( $customer_id );
		}

		$email = (string) $order->get_billing_email();
		if ( $email !== '' ) {
			$this->store->delete_by_email( $email );
		}
	}

	/**
	 * The identity we can attach right now: the logged-in user, else whatever
	 * billing details WC has persisted on the session customer.
	 *
	 * @return array{user_id: int, email: string, first_name: string, last_name: string}
	 */
	private function current_identity(): array {
		$identity = array(
			'user_id'    => 0,
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
		);

		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user instanceof \WP_User && (int) $user->ID > 0 ) {
				$identity['user_id']    = (int) $user->ID;
				$identity['email']      = (string) $user->user_email;
				$identity['first_name'] = (string) $user->first_name;
				$identity['last_name']  = (string) $user->last_name;
				return $identity;
			}
		}

		$customer = $this->wc_customer();
		if ( $customer !== null ) {
			$email = sanitize_email( (string) $customer->get_billing_email() );
			if ( $email !== '' && is_email( $email ) ) {
				$identity['email']      = $email;
				$identity['first_name'] = sanitize_text_field( (string) $customer->get_billing_first_name() );
				$identity['last_name']  = sanitize_text_field( (string) $customer->get_billing_last_name() );
			}
		}

		return $identity;
	}

	/**
	 * Tracking gate: merchant toggle (F3-54 normalized read) + wizard gate
	 * (contact-path rule — an un-wizarded store can never send, so it
	 * shouldn't collect cart PII either).
	 */
	private function tracking_enabled(): bool {
		if ( ! SetupState::completed() ) {
			return false;
		}

		return \Smaily_Connect\Includes\Options::abandoned_cart_status()['enabled'];
	}

	/**
	 * Admin screens save carts too (e.g. an admin editing their own session)
	 * — same exclusion the legacy tracker applied.
	 */
	protected function is_admin_context(): bool {
		return function_exists( 'is_admin' ) && is_admin()
			&& ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );
	}

	/**
	 * The WC session's customer id — one stable token per cart session.
	 * Protected seams: unit tests stub these three accessors.
	 *
	 * The stubs type WC()'s properties non-nullable, but at runtime they ARE
	 * null before woocommerce_init / in CLI — keep the guards, quiet the
	 * analyser per-line.
	 *
	 * @return string
	 */
	protected function session_token(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		// @phpstan-ignore-next-line -- runtime-nullable despite the stubs (see docblock).
		if ( ! isset( WC()->session ) || ! is_callable( array( WC()->session, 'get_customer_id' ) ) ) {
			return '';
		}

		return (string) WC()->session->get_customer_id();
	}

	/** @return object|null The WC_Cart, when available. */
	protected function wc_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		// @phpstan-ignore-next-line -- runtime-nullable despite the stubs (see session_token()).
		return isset( WC()->cart ) && is_object( WC()->cart ) ? WC()->cart : null;
	}

	/** @return object|null The session WC_Customer, when available. */
	protected function wc_customer() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		// @phpstan-ignore-next-line -- runtime-nullable despite the stubs (see session_token()).
		if ( ! isset( WC()->customer ) || ! is_callable( array( WC()->customer, 'get_billing_email' ) ) ) {
			return null;
		}

		return WC()->customer;
	}
}
