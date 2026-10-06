<?php
/**
 * A logged-in shopper's own recommendations, shown in the store.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\Support\RecId;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;
use Smaily\Connect\Support\DebugLog;

/**
 * Asks the engine for the logged-in shopper's current recommendations
 * (RECENGINE_API_CONTRACT.md §15 — the same products as in the shopper's
 * Smaily contact fields) and renders them as product cards. Behind the
 * `smaily/recommendations` block and the `[smaily_recommendations]` shortcode;
 * the merchant decides where it appears — nothing is placed automatically.
 *
 * Rendered on the SERVER, because the engine key never reaches the browser
 * (§15). So the call sits on the page render, and everything about it is
 * built to cost the shopper nothing when it can't help:
 *   - asked only for a logged-in shopper, only while the engine may be called
 *     (`sending_allowed()`, PRO-1893), and only when the shopper has not
 *     opted out of profiling (ProfilingConsent — the engine also answers an
 *     opted-out customer with nothing, this just skips the call);
 *   - one attempt with a short timeout and no Retry-After wait (the Client is
 *     built that way by Bootstrap::storefront_recommendations());
 *   - the answer — an empty one included, which §15 says not to retry — is
 *     cached per shopper for one hour (§15), keyed by tenant + a hash of the
 *     user id. A finite TTL: a no-expiry transient per shopper would be an
 *     autoloaded option forever (PRO-2435);
 *   - an error or timeout renders nothing and is not cached.
 *
 * The cards show the store's own product data (live name, price, stock,
 * image), so only the slot's recommendation id and product id are kept from
 * the answer. A product the store no longer shows (unpublished, hidden, or
 * out of stock while the store hides those) is left out. Each link carries
 * `smaily_rec` + `smaily_ctx=storefront`, so LandingCapture stamps the
 * purchase as a storefront credit (§15 Attribution). A page that shows cards
 * is marked not to be cached (PRO-3832).
 *
 * Not final: unit tests drive slots() without WooCommerce.
 */
class StorefrontRecommendations {

	/** Product cards per widget. */
	public const LIMIT = 4;

	/** Hard client timeout for the §15 call, in seconds. */
	public const TIMEOUT_SECONDS = 1;

	/** Per-shopper cache lifetime, in seconds: one hour, as §15 advises (Erkki, 2026-10-05). */
	public const CACHE_TTL = HOUR_IN_SECONDS;

	private const CACHE_PREFIX = 'smly_rec_storefront_';

	/** The §15 storefront context the card links carry. */
	private const CONTEXT_STOREFRONT = 'storefront';

	private RecEngineSettings $settings;

	private ProfilingConsent $profiling;

	/** @var callable():Client */
	private $client_factory;

	/**
	 * @param callable():Client $client_factory A single-attempt, short-timeout engine client.
	 */
	public function __construct( RecEngineSettings $settings, ProfilingConsent $profiling, callable $client_factory ) {
		$this->settings       = $settings;
		$this->profiling      = $profiling;
		$this->client_factory = $client_factory;
	}

	/**
	 * The product cards for one shopper, or '' when there is nothing to show.
	 */
	public function render( int $user_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		// One query each for the products and their images, not one per card.
		$slots = $this->slots( $user_id );
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

		$this->mark_not_cacheable();

		return sprintf(
			'<section class="smaily-connect-recommendations woocommerce"><h2 class="smaily-connect-recommendations__title">%1$s</h2><ul class="products columns-%2$d">%3$s</ul></section>',
			esc_html__( 'Recommended for you', 'smaily-connect' ),
			self::LIMIT,
			$cards
		);
	}

	/**
	 * Tell page caches not to store this page: its cards belong to one
	 * shopper (PRO-3832). `DONOTCACHEPAGE` is honoured by the common WordPress
	 * page caches; the no-cache headers cover a proxy or CDN in front. Called
	 * only when cards are shown, so a page with nothing in the slot stays
	 * cacheable.
	 */
	protected function mark_not_cacheable(): void {
		wc_maybe_define_constant( 'DONOTCACHEPAGE', true );
		if ( ! $this->headers_already_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Whether the response headers have already been sent. A seam so tests can
	 * exercise the header path — PHPUnit's own progress output makes the bare
	 * headers_sent() true mid-suite (the LandingCapture pattern).
	 */
	protected function headers_already_sent(): bool {
		return headers_sent();
	}

	/**
	 * The shopper's recommendations as `{rec_id, product_id}` pairs, in the
	 * engine's order — empty whenever the engine may not or need not be asked.
	 *
	 * @return array<int, array{rec_id: string, product_id: int}>
	 */
	public function slots( int $user_id ): array {
		if ( $user_id <= 0 || ! $this->settings->sending_allowed() ) {
			return array();
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$email = strtolower( trim( (string) $user->user_email ) );
		if ( '' === $email || ! $this->profiling->may_profile( $email ) ) {
			return array();
		}

		$cache_key = self::CACHE_PREFIX . md5( $this->settings->tenant_id() . '|' . $user_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$answer = ( $this->client_factory )()->customer_recommendations( (string) $user_id, self::LIMIT );
		} catch ( \Throwable $e ) {
			DebugLog::write( sprintf( '[smaily-connect storefront-recs] engine call failed (%s) — rendering nothing', get_class( $e ) ) );
			return array();
		}

		$slots = $this->usable_slots( $answer );
		set_transient( $cache_key, $slots, self::CACHE_TTL );

		return $slots;
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
