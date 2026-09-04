<?php
/**
 * Detects the WordPress environment for the bootstrap payload that
 * admin/wizard.php and admin/settings.php hand to the React bundle.
 *
 * @package Smaily\Connect\Wizard
 */

declare(strict_types=1);

namespace Smaily\Connect\Wizard;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Smaily\Connect\Constants;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\PolylangAdapter;
use Smaily\Connect\Multilingual\SiteLocaleAdapter;
use Smaily\Connect\Multilingual\TranslatePressAdapter;
use Smaily\Connect\Multilingual\WPMLAdapter;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\SubscriberPayloadBuilder;

/**
 * One-shot snapshot of the WP site state the wizard / settings panels
 * need at boot. Emitted into a single `wp_localize_script` call as
 * `smailyConnectBoot.envSnapshot` (and a parallel `savedSettings` for
 * Mode B/C credentials, persisted toggles, etc).
 *
 * Why a dedicated class instead of inlining the queries into the PHP
 * mount files: each piece of data has its own subtle "what does this
 * mean if X is inactive" question (WC inactive → orders count 0, not
 * the function-doesn't-exist error; Polylang inactive → single-locale
 * fallback, not an empty list). Pulling them together here keeps the
 * mount files small and lets PHPUnit cover the edge cases without
 * standing up admin pages.
 *
 * Result shape — matches WizardState.env in admin/src/state/types.ts:
 *
 *   {
 *     detectedLanguages: string[],
 *     multilingualPlugin: 'wpml'|'polylang'|'translatepress'|null,
 *     elementorPresent:   bool,
 *     cf7Present:         bool,
 *     wcActive:           bool,
 *     hposActive:         bool,
 *     storeTotals: {
 *       customers: int,
 *       orders:    int,
 *       products:  int,
 *     },
 *     rss: { baseUrl, categories[], defaults{} } | null,
 *     docsUrl: string,
 *     orderStatuses: array<{slug, name}>,
 *   }
 *
 * The React bundle reads everything that's not in WizardState.env
 * (`hposActive`, `multilingualPlugin`, `wcActive`) for status
 * banners and conditional UI but doesn't mirror it into reducer
 * state — those values can't change without a page reload.
 */
class EnvDetector {

	/**
	 * Snapshot the full env payload. Pure read — does not write options.
	 *
	 * @return array{
	 *   detectedLanguages: array<int, string>,
	 *   multilingualPlugin: 'wpml'|'polylang'|'translatepress'|null,
	 *   elementorPresent: bool,
	 *   cf7Present: bool,
	 *   wcActive: bool,
	 *   hposActive: bool,
	 *   storeTotals: array{customers: int, orders: int, products: int},
	 *   rss: array{
	 *     baseUrl: string,
	 *     categories: array<int, array{slug: string, name: string}>,
	 *     defaults: array{limit: int, category: string, sortBy: string, order: string, taxRate: float},
	 *   }|null,
	 *   docsUrl: string,
	 *   orderStatuses: array<int, array{slug: string, name: string}>,
	 * }
	 */
	public function snapshot(): array {
		return array(
			'detectedLanguages'  => $this->detected_languages(),
			'multilingualPlugin' => $this->multilingual_plugin(),
			'elementorPresent'   => $this->elementor_present(),
			'cf7Present'         => $this->cf7_present(),
			'wcActive'           => $this->wc_active(),
			'hposActive'         => $this->hpos_active(),
			'storeTotals'        => $this->store_totals(),
			'rss'                => $this->rss_snapshot(),
			// Base merchant-docs URL (PRO-1430) — Constants::docs_url() is the
			// single place this can move/be filtered; the React "How to add a
			// Smaily signup form" section appends its own #anchor client-side.
			'docsUrl'            => Constants::docs_url(),
			// PRO-1504 — registered WooCommerce order statuses (incl. custom
			// ones), choices for the "counts as shipped" multi-select.
			'orderStatuses'      => $this->order_statuses(),
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function detected_languages(): array {
		$languages = DetectorFactory::create()->get_detected_languages();

		// Normalise: drop empties and re-index.
		$languages = array_values(
			array_filter(
				$languages,
				static fn ( $lang ): bool => is_string( $lang ) && $lang !== ''
			)
		);

		return $languages;
	}

	/**
	 * Returns the canonical key for the active multilingual plugin, or
	 * null when on the SiteLocaleAdapter fallback. The React bundle uses
	 * this to decide whether the MultilingualModePicker is meaningful
	 * (single-locale sites stay on Mode "single" and the picker hides).
	 */
	private function multilingual_plugin(): ?string {
		$detector = DetectorFactory::create();

		if ( $detector instanceof WPMLAdapter ) {
			return 'wpml';
		}
		if ( $detector instanceof PolylangAdapter ) {
			return 'polylang';
		}
		if ( $detector instanceof TranslatePressAdapter ) {
			return 'translatepress';
		}
		if ( $detector instanceof SiteLocaleAdapter ) {
			return null;
		}

		// Future detector subclasses get reported as null — the UI then
		// falls back to single-mode behaviour, which is the safe default.
		return null;
	}

	/**
	 * Elementor is detected via its main class constant, mirroring the
	 * legacy admin's check. `class_exists()` triggers autoload, which is
	 * fine here because Elementor's classes are PSR-4 and cheap to probe.
	 */
	private function elementor_present(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Contact Form 7 ships the `WPCF7_VERSION` constant on plugins_loaded.
	 * Some forks load the engine without the constant — `class_exists`
	 * for the main class plugs that gap.
	 */
	private function cf7_present(): bool {
		return defined( 'WPCF7_VERSION' ) || class_exists( '\WPCF7' );
	}

	/**
	 * Protected (not private) so tests can force the WC-active branch via
	 * an anonymous subclass — defining a global `WooCommerce` class in the
	 * unit suite would leak into every other test in the same process.
	 */
	protected function wc_active(): bool {
		return class_exists( '\WooCommerce' );
	}

	/**
	 * High-Performance Order Storage status. We check the OrderUtil
	 * helper rather than reading the wc_orders table directly — WC 7.1+
	 * exposes it as the canonical API and the helper handles the
	 * feature-flag transition correctly when HPOS is "enabled but not yet
	 * synced" (which would otherwise look false).
	 */
	private function hpos_active(): bool {
		if ( ! $this->wc_active() ) {
			return false;
		}
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * @return array{customers: int, orders: int, products: int}
	 */
	private function store_totals(): array {
		return array(
			'customers' => $this->customers_count(),
			'orders'    => $this->orders_count(),
			'products'  => $this->products_count(),
		);
	}

	/**
	 * Registered order statuses (incl. custom ones), for the "counts as
	 * shipped" multi-select (PRO-1504). `wc_get_order_statuses()` keys
	 * are 'wc-'-prefixed; the bare slug (what `$order->get_status()`
	 * returns and what OrderPayloadBuilder::map_status() compares
	 * against) is the one stored in the merchant's selection.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	private function order_statuses(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}
		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug       = ( strpos( (string) $key, 'wc-' ) === 0 ) ? substr( (string) $key, 3 ) : (string) $key;
			$statuses[] = array(
				'slug' => $slug,
				'name' => (string) $label,
			);
		}
		return $statuses;
	}

	private function customers_count(): int {
		if ( ! function_exists( 'count_users' ) ) {
			return 0;
		}
		// Counting users by role: 'customer' (WC) + 'subscriber' (WP) +
		// 'shop_manager' would over-count merchants. Step 2 of the wizard
		// frames the backfill scope as "WordPress users", so the user-table
		// total is the honest number — same that legacy Options uses.
		$counts = count_users();
		return (int) $counts['total_users'];
	}

	/**
	 * Orders count, HPOS-aware. wc_get_orders returns the row count when
	 * we pass `return = 'ids'` and `limit = -1`, with one query against
	 * the active store (wp_wc_orders for HPOS, wp_posts for legacy CPT).
	 * Step 6's Done summary shows this number so the merchant can confirm
	 * the backfill will catch their order history.
	 */
	private function orders_count(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$ids = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'status' => array_keys( wc_get_order_statuses() ),
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	private function products_count(): int {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		if ( ! is_object( $counts ) ) {
			return 0;
		}
		// Sum of published + draft — Step 2's backfill scope includes
		// drafts because the contact-level data doesn't depend on a
		// product being publicly visible.
		$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$draft     = isset( $counts->draft ) ? (int) $counts->draft : 0;
		return $published + $draft;
	}

	/**
	 * Product RSS-feed builder data for the Integrations step/tab.
	 *
	 * NULL when WooCommerce is inactive or the legacy tree isn't loaded —
	 * the React bundle hides the RSS section entirely in that case.
	 *
	 * Background: the legacy "Smaily" menu (which carried the RSS settings
	 * tab) was hidden as part of the 2.H.3 single-menu decision, but the
	 * feed itself never stopped working — the legacy Rss class registers
	 * its rewrite + template whenever WC is active. The old tab was, in
	 * effect, just a URL builder: every parameter travels in the feed
	 * URL's query string, nothing server-side depends on the saved
	 * options. This block hands the React UI the same three ingredients
	 * the old tab had: the permalink-aware base URL, the product-category
	 * list, and the merchant's previously-saved legacy option values as
	 * prefill (so a migrated pilot store sees its old configuration).
	 *
	 * @return array{
	 *   baseUrl: string,
	 *   categories: array<int, array{slug: string, name: string}>,
	 *   defaults: array{limit: int, category: string, sortBy: string, order: string, taxRate: float},
	 * }|null
	 */
	private function rss_snapshot(): ?array {
		if ( ! $this->wc_active() ) {
			return null;
		}
		// Defensive: the legacy loader requires these whenever WC is active,
		// but the unit suite (and any future legacy-tree removal) won't have
		// them — degrade to "no RSS section" rather than fatal.
		if (
			! class_exists( \Smaily_Connect\Integrations\WooCommerce\Rss::class )
			|| ! class_exists( \Smaily_Connect\Includes\Options::class )
		) {
			return null;
		}

		return array(
			'baseUrl'    => \Smaily_Connect\Integrations\WooCommerce\Rss::make_rss_feed_url(),
			'categories' => $this->rss_categories(),
			'defaults'   => array(
				'limit'    => (int) get_option(
					\Smaily_Connect\Includes\Options::RSS_LIMIT_OPTION,
					\Smaily_Connect\Includes\Options::RSS_DEFAULT_LIMIT
				),
				'category' => (string) get_option( \Smaily_Connect\Includes\Options::RSS_CATEGORY_OPTION, '' ),
				// Legacy naming is crossed here on purpose: the *_sort_by
				// option holds the order_by FIELD and *_order_by holds the
				// ASC/DESC direction (see legacy Options::get_settings()).
				'sortBy'   => (string) get_option(
					\Smaily_Connect\Includes\Options::RSS_SORT_BY_OPTION,
					\Smaily_Connect\Includes\Options::RSS_DEFAULT_SORT_BY
				),
				'order'    => (string) get_option(
					\Smaily_Connect\Includes\Options::RSS_ORDER_BY_OPTION,
					\Smaily_Connect\Includes\Options::RSS_DEFAULT_ORDER_BY
				),
				'taxRate'  => $this->rss_tax_rate_default(),
			),
		);
	}

	/**
	 * Product categories for the feed's category filter — same query the
	 * legacy RSS tab ran (alphabetical, empty categories included).
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	private function rss_categories(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'orderby'    => 'name',
				'order'      => 'asc',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
			);
		}
		return $categories;
	}

	/**
	 * Prefill for the tax-rate field, mirroring the legacy tab's default
	 * chain: saved option → store base tax rate (when taxes enabled) →
	 * 0. The value only matters as prefill; the feed reads the rate from
	 * the URL's tax_rate query param.
	 */
	private function rss_tax_rate_default(): float {
		$store_rate = null;
		if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && class_exists( '\WC_Tax' ) ) {
			foreach ( \WC_Tax::get_base_tax_rates() as $rate ) {
				if ( is_array( $rate ) && array_key_exists( 'rate', $rate ) ) {
					$store_rate = (float) $rate['rate'];
					break;
				}
			}
		}
		return (float) get_option(
			\Smaily_Connect\Includes\Options::RSS_TAX_RATE,
			$store_rate ?? (float) \Smaily_Connect\Includes\Options::RSS_DEFAULT_TAX_RATE
		);
	}

	/**
	 * Snapshot the currently-persisted settings so the React bundle
	 * doesn't have to round-trip via the REST API on initial load. The
	 * payload mirrors the per-tab POST body shape so the same field
	 * names land in WizardState on hydrate.
	 *
	 * Credentials are returned WITHOUT the password — security gate. The
	 * UI re-renders the password field as an empty input ("***"
	 * placeholder) and a no-op save leaves the stored value untouched.
	 *
	 * @return array<string, mixed>
	 */
	public function saved_settings(): array {
		$creds             = new Credentials();
		$set               = $creds->get();
		$transactional_set = $creds->get( 'transactional' );

		return array(
			'smailyCredentials'             => array(
				'subdomain' => $set !== null ? $set->subdomain : '',
				'username'  => $set !== null ? $set->username : '',
				// Password intentionally omitted; UI shows it blank.
				'password'  => '',
			),
			// Sub-PR 2.H.15 — "already connected" UX. SettingsEndpoint
			// flips this to true after a Save (which itself implies a
			// successful Test connection had just run). hydrate.ts uses
			// it to render the compact "✓ Connected" view instead of
			// forcing a password re-entry on every wizard pass.
			'smailyConnected'               => (bool) get_option( 'smly_plus_default_connection_verified', false ),
			// See WizardState.smailyHasStoredPassword (PRO-2286). The VALUE
			// never leaves the server — only this flag.
			'smailyHasStoredPassword'       => $set !== null && $set->is_complete(),
			// Sub-PR 2.H.18 — true once Step 6 Finish ran. The wizard
			// uses this to know whether the merchant has completed at
			// least one walk-through; Phase-2 logic doesn't gate on
			// it, but Phase-3 progressive-disclosure (sub-PR 2.I) will.
			'setupCompleted'                => SetupState::completed(),
			// Empty string when the option was never set — lets hydrate
			// pick an env-aware default (Mode B for multilingual sites,
			// 'single' otherwise). Once the merchant explicitly saves a
			// mode via Settings the actual value lands here and wins.
			'multilingualMode'              => (string) get_option( 'smly_plus_multilingual_mode', '' ),
			'defaultFallbackAccountKey'     => (string) get_option( 'smly_plus_default_fallback_account', 'default' ),

			// Step 2: subscribers.
			// Read through the same accessor the sync itself gates on, so the
			// switch the merchant sees is the switch the sync obeys.
			'subscriberSyncEnabled'         => ContactSyncMode::sync_enabled(),
			// The selection the sync is REALLY using, read through the same
			// interpreter — so a store that saved under the pre-PRO-1683
			// wizard names (`phone`/`gender`) or under the legacy settings
			// page's name => bool map (PRO-1684) shows exactly the ticks
			// whose fields are being sent, and can turn any of them off.
			// Always a list: the legacy map would reach the wizard as a JS
			// object, whose missing `.length` reads as "nothing saved".
			'syncFields'                    => SubscriberPayloadBuilder::effective_selection(),
			// Must match the key SettingsEndpoint::save_subscribers() writes
			// to. Previously read from `smaily_connect_wp_subscription_enabled`
			// which was never written anywhere — the checkbox flip in Step 2
			// looked like it didn't persist because the hydrator read a
			// different option than the saver wrote.
			'wordpressSubscriptionCheckbox' => (bool) get_option( 'smly_plus_wordpress_subscription_checkbox', false ),
			'checkoutSubscriptionCheckbox'  => (bool) get_option( 'smaily_connect_checkout_subscription_enabled', false ),

			// Step 2: contact-sync mode (F3-48).
			'contactSyncMode'               => (string) get_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::DEFAULT_MODE ),
			'includeGuests'                 => (bool) get_option( ContactSyncMode::OPTION_INCLUDE_GUESTS, false ),

			// Step 3: WooCommerce automations.
			'abandonedCartCutoffMinutes'    => (int) get_option( 'smaily_connect_abandoned_cart_cutoff', 30 ),
			'welcomeEnabled'                => (bool) get_option( 'smly_plus_welcome_enabled', false ),
			'firstOrderEnabled'             => (bool) get_option( 'smly_plus_first_order_enabled', false ),
			// Normalized read (F3-54): the option holds an array
			// {enabled, autoresponder_id} on upgraded stores — a raw (bool)
			// cast read a disabled array ({enabled: false}) as TRUE.
			'abandonedCartEnabled'          => \Smaily_Connect\Includes\Options::abandoned_cart_status()['enabled'],
			'automationMappings'            => $this->automation_mappings(),

			// Transactional emails (PRO-1504, stage 1 — configuration only,
			// no send path yet). Separate Smaily account bound under
			// account_key='transactional'; credentials mirror the default
			// account's password-omitted shape.
			'transactionalEmailsEnabled'    => (bool) get_option( 'smly_plus_transactional_emails_enabled', false ),
			'transactionalCredentials'      => array(
				'subdomain' => $transactional_set !== null ? $transactional_set->subdomain : '',
				'username'  => $transactional_set !== null ? $transactional_set->username : '',
				'password'  => '',
			),
			'transactionalConnected'        => (bool) get_option( 'smly_plus_transactional_connection_verified', false ),
			'orderConfirmationEnabled'      => (bool) get_option( 'smly_plus_order_confirmation_enabled', false ),
			'shippingConfirmationEnabled'   => (bool) get_option( 'smly_plus_shipping_confirmation_enabled', false ),
			'shippedOrderStatuses'          => (array) get_option( 'smly_plus_shipped_order_statuses', array( 'completed' ) ),

			// Step 4: rec-engine connection state (sub-PR 3.1). The
			// api_key is DELIBERATELY omitted from the boot payload —
			// it stays server-side, encrypted in wp_options. The React
			// layer only needs to know whether we're connected and to
			// what tenant. All engine requests go through the
			// /rec-engine/ping proxy so the key never lands in the
			// browser. See RECENGINE_API_CONTRACT.md §2 ("API-key ei
			// tohi kunagi olla client-side koodis").
			'recEngine'                     => $this->rec_engine_snapshot(),
		);
	}

	/**
	 * Snapshot the merchant-visible portion of the rec-engine tenant
	 * binding for the boot payload. NEVER emits api_key, endpoints,
	 * or the full config map — those stay in wp_options.
	 *
	 * @return array<string, mixed>
	 */
	private function rec_engine_snapshot(): array {
		$settings = new RecEngineSettings();
		return array(
			'connected'     => $settings->is_connected(),
			'tenantName'    => $settings->tenant_name(),
			'tenantId'      => $settings->tenant_id(),
			'engineVersion' => $settings->engine_version(),
			'baseUrl'       => $settings->base_url(),
			'issuedAt'      => $settings->issued_at(),
			// Read INDEPENDENT of the connection: disconnect() wipes the
			// smly_rec_* connection options but deliberately leaves the
			// merchant's browse-tracking preference (smly_plus_rec_track_browsing)
			// intact, so a re-connect restores the saved toggle state. hydrate.ts
			// seeds recEngineFeatures.trackBrowsing from this (the only Step-4
			// toggle left after 3.9 — sync is now unconditional while connected).
			'trackBrowsing' => (bool) get_option( 'smly_plus_rec_track_browsing', false ),
		);
	}

	/**
	 * Read persisted automation mappings so the wizard / Settings tabs
	 * see the user's prior workflow picks on reload. Replaces what was
	 * previously hard-coded to [] in hydrate.ts — the rows are saved via
	 * SettingsEndpoint::replace_automation_mappings() but were never
	 * surfaced back to the React layer, so the dropdown choice looked
	 * lost after every reload.
	 *
	 * @return array<int, array{
	 *   triggerType: string,
	 *   language: string,
	 *   accountKey: string,
	 *   workflowId: string,
	 *   isDefaultFallback: bool,
	 * }>
	 */
	private function automation_mappings(): array {
		/** @var \wpdb|null $wpdb */
		global $wpdb;
		// Defensive guard for unit tests where $wpdb isn't seeded — the
		// real env-bootstrap always sets it.
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'smly_plus_automation_mapping';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT trigger_type, language, account_key, workflow_id, is_default_fallback FROM {$table}",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'triggerType'       => isset( $row['trigger_type'] ) ? (string) $row['trigger_type'] : '',
				'language'          => isset( $row['language'] ) ? (string) $row['language'] : '',
				'accountKey'        => isset( $row['account_key'] ) ? (string) $row['account_key'] : '',
				'workflowId'        => isset( $row['workflow_id'] ) ? (string) $row['workflow_id'] : '',
				'isDefaultFallback' => ! empty( $row['is_default_fallback'] ),
			);
		}
		return $out;
	}
}
