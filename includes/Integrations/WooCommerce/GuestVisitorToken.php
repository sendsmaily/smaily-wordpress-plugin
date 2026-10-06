<?php
/**
 * Visitor token for a consenting guest buyer, issued at checkout.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Support\MarketingConsent;

/**
 * Gives a guest buyer a visitor token at checkout, so the store can recognise
 * them on a later visit (PRO-3845).
 *
 * The storefront recommendations ask the engine for a guest by the
 * visitor-token cookie, and until now only a Smaily email link wrote that
 * cookie. A guest who came any other way stayed a stranger after buying. Here
 * the store creates the token itself, writes it to the same cookie
 * LandingCapture writes, and stores it on the order, so the order sent to the
 * engine carries it in `smaily_visitor_token` and the engine binds it to that
 * order's customer.
 *
 * Only when all of these hold: the engine is connected; the buyer is a guest
 * (no account on the order, nobody logged in — a registered buyer is named by
 * the account); the request is a shopper's checkout (the two checkout hooks
 * fire for nothing else, and cron / WP-CLI are refused outright); the shopper
 * gave an explicit marketing yes through a consent plugin on the WP Consent
 * API (MarketingConsent — no API, no stored `allow`, no yes: no token); and the
 * browser carries no visitor token yet. An existing token is never replaced.
 */
class GuestVisitorToken {

	/** Same order meta HookHandler stamps the visitor-token cookie into. */
	private const ORDER_META_VISITOR_TOKEN = '_smaily_visitor_token';

	private RecEngineSettings $settings;

	private LandingCapture $cookies;

	public function __construct( RecEngineSettings $settings, LandingCapture $cookies ) {
		$this->settings = $settings;
		$this->cookies  = $cookies;
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_checkout' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_block_checkout' ), 10, 1 );
	}

	public function on_classic_checkout( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$this->maybe_issue( $order );
		}
	}

	public function on_block_checkout( \WC_Order $order ): void {
		$this->maybe_issue( $order );
	}

	public function maybe_issue( \WC_Order $order ): void {
		if ( ! $this->settings->is_connected() ) {
			return;
		}
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( $order->get_customer_id() > 0 || is_user_logged_in() ) {
			return;
		}
		if ( ! $this->marketing_consent_given() ) {
			return;
		}
		if ( '' !== $this->cookies->current_visitor_token() ) {
			return;
		}

		$token = $this->cookies->issue_visitor_token();
		if ( '' === $token ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META_VISITOR_TOKEN, $token );
		$order->save();
	}

	/**
	 * Whether the shopper gave an explicit marketing yes. A seam so tests can
	 * answer it: defining the WP Consent API functions in a test would leak
	 * into every later test of the process.
	 */
	protected function marketing_consent_given(): bool {
		return MarketingConsent::given();
	}
}
