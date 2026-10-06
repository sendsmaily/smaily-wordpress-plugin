<?php
/**
 * A shopper's own recommendations, shown in the store.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\REST\RecommendationsEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\Support\AttributionShape;
use Smaily\Connect\Smaily\RecEngine\Support\RecId;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;
use Smaily\Connect\Support\DebugLog;

/**
 * Asks the engine for a shopper's current recommendations
 * (RECENGINE_API_CONTRACT.md §15 — the same products as in the shopper's
 * Smaily contact fields) and renders them as product cards. Behind the
 * `smaily/recommendations` block and the `[smaily_recommendations]` shortcode;
 * the merchant decides where it appears — nothing is placed automatically.
 *
 * Two steps, so the page never waits for the engine and stays cacheable
 * (PRO-3835):
 *   - the block and the shortcode print placeholder(): an empty container
 *     that is the same for every visitor, and enqueue `sc-recs.js`. No engine
 *     call, no per-shopper data in the page HTML, so a full-page cache may
 *     keep it;
 *   - after the page has loaded, and only with the shopper's marketing
 *     consent (WP Consent API, fail-closed), `sc-recs.js` asks the store's
 *     own GET route (RecommendationsEndpoint), which answers with cards().
 *     The engine key never reaches the browser (§15).
 *
 * Who the engine is asked about (slots()):
 *   - a logged-in shopper by the store customer id (the WP user id), and only
 *     when the shopper has not opted out of profiling (ProfilingConsent). An
 *     opted-out shopper is never asked about — not by the visitor token either;
 *   - a guest by the engine-issued visitor token from the visitor cookie. The
 *     engine enforces a guest's profiling opt-out on the token path (§10);
 *   - nobody else: with neither, nothing is asked.
 * Always only while the engine may be called (`sending_allowed()`, PRO-1893),
 * in one attempt with no Retry-After wait (Bootstrap::storefront_recommendations()).
 * The answer — an empty one included, which §15 says not to retry — is cached
 * for one hour (§15), keyed by tenant + a hash of the identifier type and
 * value. A finite TTL: a no-expiry transient per shopper would be an
 * autoloaded option forever (PRO-2435). An error or timeout shows nothing and
 * is not cached.
 *
 * The cards show the store's own product data (live name, price, stock,
 * image), so only the slot's recommendation id and product id are kept from
 * the answer. A product the store no longer shows (unpublished, hidden, or
 * out of stock while the store hides those) is left out. Each link carries
 * `smaily_rec` + `smaily_ctx=storefront`, so LandingCapture stamps the
 * purchase as a storefront credit (§15 Attribution).
 *
 * Not final: unit tests drive slots() without WooCommerce.
 */
class StorefrontRecommendations {

	/** Product cards per widget. */
	public const LIMIT = 4;

	/**
	 * Client timeout for the §15 call, in seconds. §15 asks for a hard 1 s
	 * because its call used to sit on the page render; since PRO-3835 it runs
	 * in a background request after the page has loaded, so a slow answer
	 * delays only the cards. Still one attempt.
	 */
	public const TIMEOUT_SECONDS = 3;

	/** Per-shopper cache lifetime, in seconds: one hour, as §15 advises (Erkki, 2026-10-05). */
	public const CACHE_TTL = HOUR_IN_SECONDS;

	/** Script handle — neutral, like the other storefront bundles (F3-41). */
	public const HANDLE = 'smaily-connect-recs';

	/** Shipped bundle, relative to the plugin root (vite entry key `public/js/sc-recs`). */
	private const SCRIPT_FILE = 'dist/public/js/sc-recs.js';

	private const CACHE_PREFIX = 'smly_rec_storefront_';

	/** The §15 storefront context the card links carry. */
	private const CONTEXT_STOREFRONT = 'storefront';

	/** Identifier types, part of the cache key. */
	private const ID_CUSTOMER = 'customer';
	private const ID_VISITOR  = 'visitor';

	private RecEngineSettings $settings;

	private ProfilingConsent $profiling;

	/** @var callable():Client */
	private $client_factory;

	/**
	 * @param callable():Client $client_factory A single-attempt engine client.
	 */
	public function __construct( RecEngineSettings $settings, ProfilingConsent $profiling, callable $client_factory ) {
		$this->settings       = $settings;
		$this->profiling      = $profiling;
		$this->client_factory = $client_factory;
	}

	/**
	 * What the block and the shortcode print: an empty container, the same for
	 * every visitor, that `sc-recs.js` fills after the page has loaded. '' when
	 * the store cannot show recommendations at all.
	 */
	public function placeholder(): string {
		if ( ! function_exists( 'wc_get_product' ) || ! $this->settings->sending_allowed() ) {
			return '';
		}
		if ( ! $this->enqueue_script() ) {
			return '';
		}

		return '<div class="smaily-connect-recommendations-slot" data-smaily-connect-recs></div>';
	}

	/**
	 * Enqueue `sc-recs.js` once per page, with its one config object: the
	 * store route to ask and the WP Consent API category to check — the same
	 * category the browse runtime uses. False when the bundle isn't built.
	 */
	private function enqueue_script(): bool {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return true;
		}

		$path = SMAILY_CONNECT_PLUGIN_PATH . self::SCRIPT_FILE;
		if ( ! file_exists( $path ) ) {
			return false;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( self::SCRIPT_FILE, SMAILY_CONNECT_PLUGIN_FILE ),
			array(),
			(string) filemtime( $path ),
			true
		);

		$boot = array(
			'url'     => esc_url_raw( rest_url( Constants::REST_NAMESPACE . RecommendationsEndpoint::ROUTE ) ),
			'consent' => array(
				/** Documented in StorefrontBeacon::enqueue_runtime(). */
				'category' => (string) apply_filters( 'smaily_connect_beacon_consent_category', 'marketing' ),
			),
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.smailyConnectRecs = ' . wp_json_encode( $boot ) . ';',
			'before'
		);

		return true;
	}

	/**
	 * The product cards for one shopper, or '' when there is nothing to show.
	 * See slots() for who is asked about.
	 */
	public function cards( int $user_id, string $visitor_token ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		// One query each for the products and their images, not one per card.
		$slots = $this->slots( $user_id, $visitor_token );
		_prime_post_caches( array_column( $slots, 'product_id' ) );

		$shown     = array();
		$image_ids = array();
		foreach ( $slots as $slot ) {
			$product = wc_get_product( $slot['product_id'] );
			if ( ! $product instanceof \WC_Product || ! $product->is_visible() ) {
				continue;
			}
			$shown[]     = array(
				'rec_id'  => $slot['rec_id'],
				'product' => $product,
			);
			$image_ids[] = (int) $product->get_image_id();
		}
		_prime_post_caches( array_filter( $image_ids ), false );

		$cards = '';
		foreach ( $shown as $card ) {
			$product = $card['product'];
			$url     = add_query_arg(
				array(
					LandingCapture::URL_PARAM_REC_ID  => $card['rec_id'],
					LandingCapture::URL_PARAM_CONTEXT => self::CONTEXT_STOREFRONT,
				),
				$product->get_permalink()
			);

			$cards .= sprintf(
				'<li class="product"><a href="%1$s" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">%2$s<h2 class="woocommerce-loop-product__title">%3$s</h2><span class="price">%4$s</span></a></li>',
				esc_url( $url ),
				$product->get_image(),
				esc_html( $product->get_name() ),
				wp_kses_post( $product->get_price_html() )
			);
		}

		if ( '' === $cards ) {
			return '';
		}

		return sprintf(
			'<section class="smaily-connect-recommendations woocommerce"><h2 class="smaily-connect-recommendations__title">%1$s</h2><ul class="products columns-%2$d">%3$s</ul></section>',
			esc_html__( 'Recommended for you', 'smaily-connect' ),
			self::LIMIT,
			$cards
		);
	}

	/**
	 * The shopper's recommendations as `{rec_id, product_id}` pairs, in the
	 * engine's order — empty whenever the engine may not or need not be asked.
	 *
	 * @param int    $user_id       The logged-in shopper, 0 for a guest.
	 * @param string $visitor_token The visitor cookie's value, '' when absent.
	 * @return array<int, array{rec_id: string, product_id: int}>
	 */
	public function slots( int $user_id, string $visitor_token ): array {
		if ( ! $this->settings->sending_allowed() ) {
			return array();
		}

		$identity = $this->identity( $user_id, $visitor_token );
		if ( null === $identity ) {
			return array();
		}

		$cache_key = self::CACHE_PREFIX . md5( $this->settings->tenant_id() . '|' . $identity['type'] . '|' . $identity['id'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$client = ( $this->client_factory )();
			$answer = self::ID_CUSTOMER === $identity['type']
				? $client->customer_recommendations( $identity['id'], self::LIMIT )
				: $client->visitor_recommendations( $identity['id'], self::LIMIT );
		} catch ( \Throwable $e ) {
			DebugLog::write( sprintf( '[smaily-connect storefront-recs] engine call failed (%s) — showing nothing', get_class( $e ) ) );
			return array();
		}

		$slots = $this->usable_slots( $answer );
		set_transient( $cache_key, $slots, self::CACHE_TTL );

		return $slots;
	}

	/**
	 * Who to ask about: the logged-in shopper by the customer id, else a guest
	 * by a well-formed visitor token, else nobody. A logged-in shopper who
	 * opted out of profiling is nobody — never asked about by the token either.
	 *
	 * @return array{type: string, id: string}|null
	 */
	private function identity( int $user_id, string $visitor_token ): ?array {
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return null;
			}
			$email = strtolower( trim( (string) $user->user_email ) );
			if ( '' === $email || ! $this->profiling->may_profile( $email ) ) {
				return null;
			}
			return array(
				'type' => self::ID_CUSTOMER,
				'id'   => (string) $user_id,
			);
		}

		if ( AttributionShape::is_visitor_token( $visitor_token ) ) {
			return array(
				'type' => self::ID_VISITOR,
				'id'   => $visitor_token,
			);
		}

		return null;
	}

	/**
	 * Keep only the slots with a valid recommendation id and a store product
	 * id: the slot's `external_id`, else its `woo-` sku without the prefix
	 * (§15: "render with external_id, identify with sku").
	 *
	 * @param array<string, mixed> $answer The §15 response body.
	 * @return array<int, array{rec_id: string, product_id: int}>
	 */
	private function usable_slots( array $answer ): array {
		$slots = isset( $answer['slots'] ) && is_array( $answer['slots'] ) ? $answer['slots'] : array();
		$out   = array();

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$rec_id = isset( $slot['rec_id'] ) ? (string) $slot['rec_id'] : '';
			if ( ! RecId::is_valid( $rec_id ) ) {
				continue;
			}

			$product_id = $this->product_id( $slot );
			if ( $product_id <= 0 ) {
				continue;
			}

			$out[] = array(
				'rec_id'     => $rec_id,
				'product_id' => $product_id,
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	private function product_id( array $slot ): int {
		$external_id = isset( $slot['external_id'] ) ? (string) $slot['external_id'] : '';
		if ( ctype_digit( $external_id ) ) {
			return (int) $external_id;
		}

		return SkuResolver::product_id_from_key( isset( $slot['sku'] ) ? (string) $slot['sku'] : '' );
	}
}
