<?php
/**
 * Storefront browse-beacon enqueue (3.4.3) — loads the beacon on the shop
 * frontend and hands it its config + the current page's context.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;

/**
 * Enqueues `sc-runtime.js` on storefront pages and prints a `window.smailyConnectBeacon`
 * boot blob (config + page context) just before it, mirroring the admin bundle's
 * `wp_add_inline_script` pattern.
 *
 * Gate: only when the engine is connected AND browse-tracking is enabled AND
 * WooCommerce is active — same condition the beacon proxy (BeaconEndpoint)
 * hard-gates on, so a disabled beacon is never even loaded.
 *
 * A CONNECTED store with browse-tracking OFF gets a different, much smaller
 * script instead: `sc-landing.js`, which only writes the attribution cookies a
 * campaign landing carries (PRO-1767). Without it such a store loses every
 * campaign click that lands on a full-page-cached page — the cached response
 * never executes PHP, so `LandingCapture` (the server-side twin) never runs,
 * and the browser had no writer at all because the runtime was not loaded. The
 * two are mutually exclusive: when the runtime IS loaded it does the same
 * capture itself (`RecEngineClient.captureUrlParams`, shared code), so the
 * cookies are never written twice.
 *
 * The cookie names, URL-param names and TTLs come from the engine setup-exchange
 * config (per-tenant overrides); the page context comes from WooCommerce
 * conditional tags. The beacon JS decides which §6 event to fire from `pageType`.
 *
 * Identity note: the logged-in user's email is deliberately NOT placed in the JS
 * blob (it would expose it in page source). The engine resolves identity from
 * the visitor-token / session cookies for anonymous browsing; for an ongoing
 * logged-in session, `BeaconEndpoint::attach_logged_in_identity()` (PRO-1389)
 * resolves the user server-side from the auth cookie and attaches the email to
 * the outbound engine request only — never to this JS blob or the /relay
 * response.
 */
class StorefrontBeacon {

	/**
	 * Script handle + shipped filename are deliberately NOT "beacon": the word
	 * matches EasyPrivacy ad-block filter lists, which blocked the storefront
	 * request for real users (the proxy route is renamed `/beacon` → `/relay`
	 * for the same reason). Consent gating is unchanged — only the name that
	 * tripped the filter is neutral now (F3-41).
	 */
	public const HANDLE = 'smaily-connect-runtime';

	/**
	 * The attribution-only writer loaded instead of the runtime on a connected
	 * store with browse-tracking off. Same neutral-naming rule (F3-41).
	 */
	public const HANDLE_LANDING = 'smaily-connect-landing';

	/** Shipped bundle basename (vite entry key `public/js/sc-runtime`). */
	private const SCRIPT_FILE = 'sc-runtime.js';

	/** Shipped bundle basename (vite entry key `public/js/sc-landing`). */
	private const SCRIPT_FILE_LANDING = 'sc-landing.js';

	/** Directory of the storefront bundles, relative to the plugin root. */
	private const SCRIPT_DIR = 'dist/public/js';

	private RecEngineSettings $settings;

	public function __construct( RecEngineSettings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The gate: connected engine + browse-tracking enabled + WooCommerce active.
	 */
	public function is_enabled(): bool {
		if ( ! $this->settings->is_connected() ) {
			return false;
		}
		if ( ! (bool) get_option( 'smly_plus_rec_track_browsing', false ) ) {
			return false;
		}
		return function_exists( 'is_woocommerce' );
	}

	/**
	 * Whether the attribution-only writer should load: the engine is connected
	 * but the full runtime is not enqueued (browse-tracking off), so nothing
	 * else would write the cookies on a cached landing page.
	 *
	 * Deliberately NOT gated on the browse toggle or consent — attribution is a
	 * first-party functional signal (F3-46), and this is the browser-side twin
	 * of LandingCapture, so it takes that class's gate rather than the beacon's:
	 * the engine connection plus the same master switch (a merchant who turned
	 * server-side capture off with `smaily_connect_capture_attribution` does not
	 * get a new writer handed to them by an update), and no WooCommerce check —
	 * a campaign link can land on any page of the site.
	 */
	public function is_attribution_only_enabled(): bool {
		if ( $this->is_enabled() ) {
			// The runtime captures the same params itself — never both.
			return false;
		}
		if ( ! $this->settings->is_connected() ) {
			return false;
		}
		/** Documented in LandingCapture::capture(). */
		return (bool) apply_filters( 'smaily_connect_capture_attribution', true );
	}

	public function enqueue(): void {
		if ( $this->is_enabled() ) {
			$this->enqueue_runtime();
			return;
		}
		if ( $this->is_attribution_only_enabled() ) {
			$this->enqueue_attribution_writer();
		}
	}

	private function enqueue_runtime(): void {
		if ( ! $this->enqueue_bundle( self::HANDLE, self::SCRIPT_FILE ) ) {
			return;
		}

		$boot = array(
			'config'  => $this->beacon_config(),
			'context' => $this->page_context(),
			'consent' => array(
				/** Filter the WP-Consent-API category the beacon gates on. */
				'category' => (string) apply_filters( 'smaily_connect_beacon_consent_category', 'marketing' ),
			),
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.smailyConnectBeacon = ' . wp_json_encode( $boot ) . ';',
			'before'
		);
	}

	private function enqueue_attribution_writer(): void {
		if ( ! $this->enqueue_bundle( self::HANDLE_LANDING, self::SCRIPT_FILE_LANDING ) ) {
			return;
		}

		wp_add_inline_script(
			self::HANDLE_LANDING,
			'window.smailyConnectLanding = ' . wp_json_encode( $this->attribution_config() ) . ';',
			'before'
		);
	}

	/** Registers one storefront bundle; false when it isn't built (dist/). */
	private function enqueue_bundle( string $handle, string $file ): bool {
		$path = SMAILY_CONNECT_PLUGIN_PATH . self::SCRIPT_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return false;
		}

		wp_enqueue_script(
			$handle,
			plugins_url( self::SCRIPT_DIR . '/' . $file, SMAILY_CONNECT_PLUGIN_FILE ),
			array(),
			(string) filemtime( $path ),
			true
		);
		return true;
	}

	/**
	 * The AttributionConfig the attribution-only bundle expects — everything
	 * needed to read the campaign params and write the three first-party
	 * cookies, and nothing else (no proxy URL, no session cookie, no page
	 * context). The browse runtime's config is this plus its own fields.
	 * Public so tests can assert it.
	 *
	 * @return array<string, mixed>
	 */
	public function attribution_config(): array {
		$config = $this->settings->config();

		return array(
			'cookieNames'   => array(
				'visitor' => $this->config_string( $config, 'tracking_cookie_name', 'smaily_rec_uid' ),
				'recId'   => $this->config_string( $config, 'rec_id_cookie_name', 'smaily_rec_id' ),
				'context' => $this->config_string( $config, 'context_cookie_name', 'smaily_rec_ctx' ),
			),
			'urlParams'     => array(
				'visitorToken' => $this->config_string( $config, 'url_param_visitor_token', 'smaily_vt' ),
				'recId'        => $this->config_string( $config, 'url_param_rec_id', 'smaily_rec' ),
				'context'      => $this->config_string( $config, 'url_param_context', 'smaily_ctx' ),
			),
			'cookieTtlDays' => array(
				'visitor' => $this->config_int( $config, 'cookie_ttl_days', 365 ),
				'recId'   => $this->config_int( $config, 'rec_id_ttl_days', 30 ),
				'context' => $this->config_int( $config, 'context_ttl_days', 30 ),
			),
		);
	}

	/**
	 * The RecEngineClientConfig the JS expects — the attribution config (cookie
	 * names, URL-param names and TTLs from the engine config, with the §6
	 * defaults as fallback) plus the browse-only fields. Public so the enqueue
	 * can build the boot blob and tests can assert it.
	 *
	 * @return array<string, mixed>
	 */
	public function beacon_config(): array {
		$config      = $this->settings->config();
		$attribution = $this->attribution_config();

		/** @var array<string, string> $cookie_names */
		$cookie_names            = $attribution['cookieNames'];
		$cookie_names['session'] = $this->config_string( $config, 'session_cookie_name', 'smaily_anon_sid' );

		return array(
			'beaconUrl'      => esc_url_raw( rest_url( 'smaily-connect/v1/relay' ) ),
			'cookieNames'    => $cookie_names,
			'urlParams'      => $attribution['urlParams'],
			'cookieTtlDays'  => $attribution['cookieTtlDays'],
			'sessionTtlDays' => $this->config_int( $config, 'session_ttl_days', 30 ),
		);
	}

	/**
	 * Current-page context the JS maps to a §6 event_type. `is_order_received_page`
	 * is checked before `is_checkout` because the order-received page IS a
	 * checkout page (the more specific test must win). Public for testability.
	 *
	 * @return array<string, string>
	 */
	public function page_context(): array {
		$context = array( 'pageType' => 'other' );

		if ( ! function_exists( 'is_product' ) ) {
			return $context;
		}

		if ( is_product() ) {
			$context['pageType'] = 'product';
			$product             = wc_get_product( get_the_ID() );
			if ( $product instanceof \WC_Product ) {
				$context = array_merge( $context, $this->product_context( $product ) );
			}
		} elseif ( is_product_category() ) {
			$context['pageType'] = 'category';
			$term                = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$path = $this->term_slug_path( $term );
				if ( $path !== '' ) {
					$context['categoryPath'] = $path;
				}
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$context['pageType'] = 'category';
		} elseif ( is_search() ) {
			$context['pageType'] = 'search';
			$query               = (string) get_search_query();
			if ( $query !== '' ) {
				$context['searchQuery'] = $query;
			}
		} elseif ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$context['pageType'] = 'order-received';
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$context['pageType'] = 'checkout';
		}

		return $context;
	}

	/**
	 * Product-page context fields for an already-loaded WC_Product: the
	 * canonical `sku` key plus the category path when set. Split out from
	 * page_context() (PRO-1445) so the product→key resolution — the part
	 * PRO-1390 broke (a browse surface reading the merchant SKU instead of
	 * going through SkuResolver) — is unit-testable directly, without driving
	 * WooCommerce's `is_product()` conditional tag (the integration harness is
	 * plain TestCase and can't do that — see CLAUDE.md). Public for testability.
	 *
	 * @return array<string, string>
	 */
	public function product_context( \WC_Product $product ): array {
		$context = array(
			// SkuResolver (PRO-1224): always non-empty — the `woo-{id}`
			// platform key (never the merchant SKU), matching what catalog
			// ingest sent, so the engine joins product_view to the catalog row.
			'sku' => \Smaily\Connect\Smaily\RecEngine\Support\SkuResolver::resolve( $product ),
		);
		$path = ( new CatalogPayloadBuilder() )->primary_category_path( $product );
		if ( $path !== '' ) {
			$context['categoryPath'] = $path;
		}
		return $context;
	}

	/**
	 * Hierarchical slug path for a product_cat term (root-first, e.g. food/dry)
	 * — the category-page counterpart of CatalogPayloadBuilder's product path.
	 */
	private function term_slug_path( \WP_Term $term ): string {
		$slugs     = array( $term->slug );
		$ancestors = get_ancestors( $term->term_id, 'product_cat' );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, 'product_cat' );
			if ( $ancestor instanceof \WP_Term ) {
				array_unshift( $slugs, $ancestor->slug );
			}
		}
		return implode( '/', $slugs );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function config_string( array $config, string $key, string $default ): string {
		$value = isset( $config[ $key ] ) ? (string) $config[ $key ] : '';
		return $value !== '' ? $value : $default;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function config_int( array $config, string $key, int $default ): int {
		return isset( $config[ $key ] ) ? (int) $config[ $key ] : $default;
	}
}
