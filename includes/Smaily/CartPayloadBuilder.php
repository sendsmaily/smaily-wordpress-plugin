<?php
/**
 * Builds the Smaily automation payload for an abandoned-cart tracker row.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Support\ContactLanguageResolver;

/**
 * Tracker row → queue payload for `automation.abandoned_cart` (PRO-1195).
 *
 * WIRE PARITY IS THE CONTRACT here: the field names this produces feed the
 * SAME merchant-built Smaily autoresponder templates the legacy pass fed
 * (`is_abandoned_cart`, `store`, `first_name`/`last_name`, and the
 * `product_<field>_1..10` matrix with all slots prefilled empty + the
 * `over_10_products` flag). The `store`/`language` selection comes from the
 * same `smaily_connect_abandoned_cart_fields` option, so an upgrading store's
 * templates keep rendering without reconfiguration. PRO-1681 adds the
 * `abandoned_cart_automation_at` run marker on top — purely additive, the
 * legacy field names and meanings are unchanged.
 *
 * PRODUCT details are NOT selectable (PRO-1680) and neither are the NAMES
 * (PRO-1729): every `product_<field>` and both name fields are always sent,
 * whatever that option holds. Its `product_*`/`first_name`/`last_name` keys
 * were unreachable from any UI and default to false, so a fresh install sent a
 * reminder with no product detail and no name at all; a stored selection (from
 * a version that had the UI) is ignored the same way. Templates decide what to
 * RENDER — the wire always carries the full matrix, which is also what CLEARS
 * the previous cart's details from the contact (every slot is overwritten,
 * unused ones with '').
 *
 * The names differ from the products in ONE respect: they are CONTACT-level
 * fields, so the F3-47 omit rule applies — a name we don't know is OMITTED,
 * never sent empty, or the reminder would wipe a name the Smaily contact
 * already has. (A product slot is the opposite on purpose: sending it empty
 * IS the mechanism that clears the previous cart.)
 *
 * Differences from the legacy `Cron::prepare_*` pair, by design:
 *   - input is the tracker row's own JSON shape (scalars), never a
 *     deserialized WC object — product details are fetched fresh via
 *     wc_get_product() at build time (same as legacy);
 *   - language comes ONLY from ContactLanguageResolver (`for_user` for a
 *     known user, `for_guest` otherwise) and is OMITTED when unresolved —
 *     F3-47 rules (absent preserves, '' wipes);
 *   - guests are supported: names fall back to the checkout-captured
 *     columns when there is no WP user.
 *
 * Returns the payload the CartFlusher dispatches:
 *   { email, language?, fields: { ... } }
 * `language` is mirrored into fields when the merchant's field selection has
 * it enabled (legacy address parity) AND at the top level (the router's
 * workflow-language resolution reads it there).
 *
 * Not final: unit tests subclass to stub the two price-display seams (which
 * need a real WC pricing stack).
 */
class CartPayloadBuilder {

	/** Legacy parity: every product key is prefilled '' for slots 1..10 (cap: ProductMatrixBuilder::MAX_PRODUCTS). */
	private const PRODUCT_KEYS = array(
		'product_base_price',
		'product_description',
		'product_image_url',
		'product_name',
		'product_price',
		'product_quantity',
		'product_sku',
	);

	private ?ContactLanguageResolver $language_resolver;

	public function __construct( ?ContactLanguageResolver $language_resolver = null ) {
		$this->language_resolver = $language_resolver;
	}

	/**
	 * Build the queue payload for one tracker row.
	 *
	 * @param array<string, mixed> $row A smly_plus_cart_session row (id,
	 *                                  user_id, email, first_name, last_name,
	 *                                  cart_content JSON, …).
	 *
	 * @return array<string, mixed>|null Null when the row can't produce a
	 *                                   sendable payload (no email, or
	 *                                   cart_content that isn't our JSON
	 *                                   shape — treat as wire input, F3-53).
	 */
	public function build( array $row ): ?array {
		$email = isset( $row['email'] ) ? trim( (string) $row['email'] ) : '';
		if ( $email === '' ) {
			return null;
		}

		$items = json_decode( isset( $row['cart_content'] ) ? (string) $row['cart_content'] : '', true );
		if ( ! is_array( $items ) ) {
			return null;
		}

		$sync_fields = get_option(
			\Smaily_Connect\Includes\Options::ABANDONED_CART_FIELDS_OPTION,
			\Smaily_Connect\Includes\Options::ABANDONED_CART_DEFAULT_FIELDS
		);
		if ( ! is_array( $sync_fields ) ) {
			$sync_fields = \Smaily_Connect\Includes\Options::ABANDONED_CART_DEFAULT_FIELDS;
		}

		$user = null;
		if ( isset( $row['user_id'] ) && (int) $row['user_id'] > 0 && function_exists( 'get_userdata' ) ) {
			$maybe = get_userdata( (int) $row['user_id'] );
			if ( $maybe instanceof \WP_User ) {
				$user = $maybe;
			}
		}

		$language = $user instanceof \WP_User
			? $this->resolver()->for_user( $user )
			: $this->resolver()->for_guest();

		$fields = $this->contact_fields( $row, $user, $sync_fields, $language )
			+ $this->product_fields( $items );

		$payload = array(
			'email'  => $email,
			'fields' => $fields,
		);
		if ( $language !== '' ) {
			// Top level drives the router's per-language workflow lookup;
			// omit-on-empty per F3-47 rule 2.
			$payload['language'] = $language;
		}

		return $payload;
	}

	/**
	 * The non-product address fields, mirroring the legacy
	 * `Cron::prepare_user_data()` selection semantics for `store`/`language`;
	 * the names are unconditional (PRO-1729).
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $sync_fields
	 *
	 * @return array<string, mixed>
	 */
	private function contact_fields( array $row, ?\WP_User $user, array $sync_fields, string $language ): array {
		// Business requirement carried over verbatim: distinguishes
		// abandoned-cart contacts from marketing contacts on shared accounts.
		// The PRO-1681 run marker rides ALONGSIDE it — `is_abandoned_cart`
		// keeps its template meaning untouched.
		$fields = array_merge(
			array( 'is_abandoned_cart' => 'true' ),
			AutomationMarker::stamp( 'abandoned_cart' )
		);

		$first = $user instanceof \WP_User && (string) $user->first_name !== ''
			? (string) $user->first_name
			: ( isset( $row['first_name'] ) ? (string) $row['first_name'] : '' );
		$last  = $user instanceof \WP_User && (string) $user->last_name !== ''
			? (string) $user->last_name
			: ( isset( $row['last_name'] ) ? (string) $row['last_name'] : '' );

		// The shopper's name always rides the reminder (PRO-1729) — the
		// selection's first_name/last_name keys default to false and no UI
		// writes them, so a fresh install sent a reminder a template's
		// first-name merge tag rendered nothing from. Omitted when we don't
		// know it: Smaily leaves an absent field intact and WIPES an empty one
		// (F3-47), so a nameless shopper must not clear the contact's name.
		if ( $first !== '' ) {
			$fields['first_name'] = $first;
		}
		if ( $last !== '' ) {
			$fields['last_name'] = $last;
		}

		foreach ( $sync_fields as $field => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			switch ( $field ) {
				case 'store_url':
					$fields['store'] = function_exists( 'get_site_url' ) ? (string) get_site_url() : '';
					break;
				case 'language':
					// Omit when unresolved — Smaily treats absent as "leave
					// existing intact", empty as "wipe" (F3-47).
					if ( $language !== '' ) {
						$fields['language'] = $language;
					}
					break;
				default:
					// user_email rides the payload's top-level email; the
					// option's product_* keys are ignored — product details are
					// always sent (PRO-1680) — and so are its first_name /
					// last_name keys, handled above (PRO-1729).
					break;
			}
		}

		return $fields;
	}

	/**
	 * The `product_<field>_1..10` matrix, mirroring the legacy
	 * `Cron::prepare_products_data()`: every slot prefilled '' (the legacy
	 * Smaily API requires all fields updated every send — that is what clears
	 * the previous cart from the contact), EVERY product field filled per item
	 * (PRO-1680: no merchant-facing selection), `over_10_products` flagged past
	 * slot 10.
	 *
	 * @param array<int, mixed> $items Own-shape cart items.
	 *
	 * @return array<string, string>
	 */
	private function product_fields( array $items ): array {
		$fields = ProductMatrixBuilder::prefill( self::PRODUCT_KEYS );

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $fields;
		}

		return ProductMatrixBuilder::fill(
			$fields,
			$this->valid_cart_items( $items ),
			function ( array $pair ): array {
				[ $product, $item ] = $pair;

				$slot_fields = array();
				foreach ( self::PRODUCT_KEYS as $field ) {
					$value = $this->product_field_value( $field, $product, $item );
					if ( $value === null ) {
						continue;
					}
					$slot_fields[ $field ] = htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
				}
				return $slot_fields;
			}
		);
	}

	/**
	 * Cart items narrowed to ones that resolve to a live product — the
	 * matrix filler only ever sees slot-eligible items (F3-53: a poison
	 * item or a deleted product is skipped here, before it can consume a
	 * slot).
	 *
	 * @param array<int, mixed> $items
	 *
	 * @return array<int, array{0: \WC_Product, 1: array<string, mixed>}>
	 */
	private function valid_cart_items( array $items ): array {
		$valid = array();
		foreach ( $items as $item ) {
			// Treat stored rows as wire input (F3-53): skip anything that
			// isn't our {product_id, quantity} shape instead of fataling.
			if ( ! is_array( $item ) || ! isset( $item['product_id'] ) || ! is_scalar( $item['product_id'] ) ) {
				continue;
			}

			$product = wc_get_product( (int) $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$valid[] = array( $product, $item );
		}
		return $valid;
	}

	/**
	 * @param \WC_Product          $product
	 * @param array<string, mixed> $item
	 */
	private function product_field_value( string $field, $product, array $item ): ?string {
		switch ( $field ) {
			case 'product_name':
				return (string) $product->get_name();
			case 'product_description':
				return (string) $product->get_description();
			case 'product_sku':
				return (string) $product->get_sku();
			case 'product_quantity':
				return isset( $item['quantity'] ) && is_scalar( $item['quantity'] )
					? (string) $item['quantity']
					: '';
			case 'product_price':
				return $this->sale_price_display( $product );
			case 'product_base_price':
				return $this->base_price_display( $product );
			case 'product_image_url':
				$url = $this->product_image_url( $product );
				return $url !== '' ? $url : null; // Legacy parity: empty image URL keeps the '' prefill.
			default:
				return null;
		}
	}

	/**
	 * Sale display price without HTML tags — same formatting chain as the
	 * legacy pass (Helper price incl. tax → wc_price → strip). Protected
	 * seam: needs a real WC pricing stack, so unit tests stub it.
	 *
	 * @param \WC_Product $product
	 */
	protected function sale_price_display( $product ): string {
		$price = wc_price(
			\Smaily_Connect\Integrations\WooCommerce\Helper::get_current_price_with_tax( $product )
		);

		return wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Regular display price without HTML tags (see sale_price_display).
	 *
	 * @param \WC_Product $product
	 */
	protected function base_price_display( $product ): string {
		$price = wc_price(
			\Smaily_Connect\Integrations\WooCommerce\Helper::get_regular_price_with_tax( $product )
		);

		return wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Featured image, else first gallery image (legacy parity). Thin wrapper
	 * over the shared ProductMatrixBuilder fallback — kept as its own
	 * protected method so unit tests can still stub this exact seam.
	 *
	 * @param \WC_Product $product
	 */
	protected function product_image_url( $product ): string {
		return ProductMatrixBuilder::image_url( $product );
	}

	private function resolver(): ContactLanguageResolver {
		if ( $this->language_resolver === null ) {
			$this->language_resolver = new ContactLanguageResolver();
		}
		return $this->language_resolver;
	}
}
