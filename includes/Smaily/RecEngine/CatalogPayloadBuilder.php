<?php
/**
 * Single source of truth for WC product → rec-engine catalog-object mapping.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;

/**
 * Translates a WC_Product into one entry of the
 * `POST /api/v1/ingest/catalog` `products[]` array
 * (RECENGINE_API_CONTRACT.md §3, mapping per PLUGIN_IMPLEMENTATION_WP.md
 * §552 — the surrounding doc's queue prose is stale (AS-native vs our
 * variant-A queue), but the field mapping itself is stable).
 *
 * Two responsibilities, deliberately split:
 *
 *   - expand()  turns a merchant-facing product into the list of
 *     *ingestable units*. A simple product is itself; a variable product
 *     fans out into its variations. Each unit is enqueued as its own
 *     queue row with its own event_uuid, so the engine dedups each
 *     variation independently (a price change on one variation must not
 *     be masked by another variation's idempotency key). SKU-less units
 *     key on `woo-{id}` from SkuResolver (PRO-1224) — the platform id, never
 *     the merchant SKU field — so a SKU-less store still keys consistently
 *     (units used to be dropped when SKU-less, silently emptying the whole
 *     catalog with zero Event Log trace).
 *
 *   - build()   maps ONE unit + its queue-row event_uuid into the wire
 *     object. The event_uuid → `event_id` field rename is the single
 *     conversion that makes queue.event_uuid == HTTP body.event_id hold
 *     (a pinned read/write-symmetry invariant; the integration test
 *     asserts the exact field name).
 *
 * Empty-value policy mirrors SubscriberPayloadBuilder: an OPTIONAL field
 * whose source is empty is OMITTED rather than sent as "" / null. The
 * engine UPSERTs on SKU, so an absent field leaves the engine's existing
 * value intact while an empty one would clobber it — absent != empty.
 * REQUIRED fields (sku, name, category_path, price, in_stock,
 * product_url) plus event_id and external_id are always present.
 *
 * Multilingual (catalog-correctness CC.3, model B): name/description/
 * product_url are sent as a `{lang: value}` object when the site is
 * multilingual (the DetectorInterface returns per-language translations),
 * else as a plain string (single-language stores degrade to model A — the
 * engine wraps a bare string as {default: "..."} internally). The detector
 * runs on the CANONICAL product (catalog enumeration already collapsed
 * translations, CC.2), so get_translations() returns every language's
 * title/excerpt/permalink keyed by locale. description is clamped to 500
 * chars PER LANGUAGE (contract §3).
 *
 * Not final: tests subclass to stub the WP/WC taxonomy reads. Same
 * rationale as SubscriberPayloadBuilder and Smaily\Client.
 */
class CatalogPayloadBuilder {

	/** Contract caps `description` at 500 chars. */
	private const DESCRIPTION_MAX = 500;

	/**
	 * Generic, honest placeholder for category_path when even the store's
	 * default_product_cat can't be resolved. The engine only requires a
	 * non-empty string; these rows carry no merchandising meaning to fake.
	 */
	private const PLACEHOLDER_CATEGORY = 'uncategorized';

	/** Multilingual detector; lazily the active one when not injected. */
	private ?DetectorInterface $detector;

	public function __construct( ?DetectorInterface $detector = null ) {
		$this->detector = $detector;
	}

	private function detector(): DetectorInterface {
		if ( $this->detector === null ) {
			$this->detector = DetectorFactory::create();
		}
		return $this->detector;
	}

	/**
	 * Expand a product into the ingestable units it represents.
	 *
	 * @return array<int, \WC_Product> Simple → [itself]; variable → its
	 *         loadable variations. Every unit is ingestable: build() keys
	 *         SKU-less units synthetically via SkuResolver (F3-36).
	 */
	public function expand( \WC_Product $product ): array {
		if ( $product->is_type( 'variable' ) ) {
			$units = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = $this->get_product( (int) $variation_id );
				if ( $variation === null ) {
					continue;
				}
				$units[] = $variation;
			}
			return $units;
		}

		return array( $product );
	}

	/**
	 * Build the catalog wire object for one product + its queue event_uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WC_Product $product, string $event_uuid ): array {
		$translations = $this->detector()->get_translations( (int) $product->get_id() );

		$category_defaulted = false;
		$category_path      = $this->primary_category_path( $product, $category_defaulted );

		$payload = array(
			// queue.event_uuid → wire body.event_id. The one rename that
			// carries the row's idempotency key to the engine, per-product.
			'event_id'      => $event_uuid,
			'sku'           => SkuResolver::resolve( $product, $this->detector() ),
			'name'          => $this->localized( $translations['name'], (string) $product->get_name() ),
			'category_path' => $category_path,
			'price'         => (float) $product->get_price(),
			'in_stock'      => (bool) $product->is_in_stock(),
			'product_url'   => $this->localized( $translations['product_url'], $this->product_url( $product ) ),
			'external_id'   => (string) $product->get_id(),
			// Structural signal (CC.4, contract §3): the engine derives its
			// `recommendable` exclusion from product_type (gift-card types →
			// excluded); is_virtual/is_downloadable are stored but never
			// auto-exclude (a digital-goods store sells those). The plugin
			// supplies the signal and never makes the exclusion call itself
			// (DECISIONS F3-38). Always present — every product has a type.
			'product_type'  => (string) $product->get_type(),
			'is_virtual'    => (bool) $product->is_virtual(),
			'is_downloadable' => (bool) $product->is_downloadable(),
		);

		$compare_price = $this->compare_price( $product );
		if ( $compare_price !== null ) {
			$payload['compare_price'] = $compare_price;
		}

		$on_sale_until = $this->on_sale_until( $product );
		if ( $on_sale_until !== '' ) {
			$payload['on_sale_until'] = $on_sale_until;
		}

		$description = $this->localized_description( $product, $translations['description'] );
		if ( $description !== '' && $description !== array() ) {
			$payload['description'] = $description;
		}

		$image_url = $this->image_url( $product );
		if ( $image_url !== '' ) {
			$payload['image_url'] = $image_url;
		}

		$tags = $this->tags( $product, $payload['category_path'], $category_defaulted );
		if ( $tags !== array() ) {
			$payload['tags'] = $tags;
		}

		$raw_attributes = $this->raw_attributes( $product );
		if ( $raw_attributes !== array() ) {
			$payload['raw_attributes'] = $raw_attributes;
		}

		return $payload;
	}

	/**
	 * Build a minimal, always-valid catalog.delete tombstone for a product id
	 * that no longer resolves to a WC_Product at all (PRO-1498) — e.g. its
	 * product_type came from a since-deactivated plugin (a gift-card add-on),
	 * or its WC data is already stripped by the time the delete hook runs.
	 * There is no product to read, so every field is derived from the bare
	 * id, but the result still satisfies contract §3's REQUIRED, non-empty
	 * fields — the tombstone must always reach the engine so a
	 * synced-then-vanished product never lingers `in_stock=true` (extends
	 * F3-43's order-item never-drop principle to catalog tombstones).
	 *
	 * @return array<string, mixed>
	 */
	public function build_unresolvable( int $product_id, string $event_uuid ): array {
		$category_path = $this->default_category_path();

		return array(
			'event_id'      => $event_uuid,
			'sku'           => SkuResolver::resolve_id( $product_id, $this->detector() ),
			'name'          => 'Unavailable product #' . $product_id,
			'category_path' => $category_path !== '' ? $category_path : self::PLACEHOLDER_CATEGORY,
			'price'         => 0.0,
			'in_stock'      => false,
			'product_url'   => $this->fallback_product_url( $product_id ),
			'external_id'   => (string) $product_id,
			// There is no real product to derive a category from — this row's
			// category_path is always a placeholder (contract §"category-defaulted",
			// PRO-1499). tags.product_id is omitted here (no product to resolve
			// a group id from), unlike build()'s tags().
			'tags'          => array( 'category_defaulted' => 'true' ),
		);
	}

	/**
	 * Force a captured catalog.delete object into an always-sendable shape.
	 * build() already derives category_path/product_url with real fallbacks
	 * (the store's own default_product_cat term, the real permalink) for any
	 * product WooCommerce can still load — this only overrides the residual
	 * case where even that comes back blank (a broken store, or a product
	 * whose permalink can't be resolved at delete time).
	 *
	 * Delete-only, deliberately: the live catalog.upsert path keeps failing
	 * loud on the same gap (F3-39/PRO-1491) because there an empty required
	 * field is a genuine merchant-data-gap signal worth surfacing. A
	 * tombstone has no such signal to protect — its only job is "mark this
	 * SKU unavailable" (contract §3's soft-removal semantics) — so it must
	 * always reach the engine rather than being silently skipped (PRO-1498,
	 * extends F3-43's order-item never-drop principle).
	 *
	 * @param array<string, mixed> $object
	 * @return array<string, mixed>
	 */
	public function ensure_valid_removal( array $object ): array {
		if ( (string) ( $object['category_path'] ?? '' ) === '' ) {
			$object['category_path'] = self::PLACEHOLDER_CATEGORY;
			// Forcing a placeholder here is exactly the substitution
			// tags.category_defaulted (PRO-1499) exists to mark.
			$tags                       = is_array( $object['tags'] ?? null ) ? $object['tags'] : array();
			$tags['category_defaulted'] = 'true';
			$object['tags']             = $tags;
		}

		$product_url = $object['product_url'] ?? '';
		$has_url     = is_array( $product_url ) ? $product_url !== array() : (string) $product_url !== '';
		if ( ! $has_url ) {
			$object['product_url'] = $this->fallback_product_url( (int) ( $object['external_id'] ?? 0 ) );
		}

		return $object;
	}

	/**
	 * A syntactically valid placeholder URL for a product whose real
	 * permalink can't be derived (already gone / never routable). Honest for
	 * a tombstone — the row's only meaning is "no longer available"
	 * (in_stock=false), so it is never shown or clicked; it exists solely to
	 * satisfy the engine's REQUIRED non-empty product_url (contract §3).
	 * Deliberately NOT the historical `product_base_url + sku` shape the
	 * contract calls out as rejected for a LIVE product (F3-17) — this is a
	 * clearly-synthetic marker, never a plausible real product page.
	 */
	private function fallback_product_url( int $product_id ): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}
		return home_url( '/?smaily_connect_removed_product=' . $product_id );
	}

	/**
	 * compare_price = the pre-discount price, sent only when the product
	 * is genuinely marked down (regular > current). Returned null (→
	 * omitted) otherwise so a non-sale product doesn't carry a redundant
	 * compare_price equal to its price.
	 */
	private function compare_price( \WC_Product $product ): ?float {
		$regular = (float) $product->get_regular_price();
		$price   = (float) $product->get_price();

		return ( $regular > 0.0 && $regular > $price ) ? $regular : null;
	}

	private function on_sale_until( \WC_Product $product ): string {
		$date = $product->get_date_on_sale_to();
		if ( ! is_object( $date ) || ! method_exists( $date, 'getTimestamp' ) ) {
			return '';
		}
		// IsoDate emits the `Z`-suffix form the engine's strict Zod requires.
		// Using the timestamp (not format('c')) also keeps it UTC rather than
		// the WC_DateTime's local offset. (3.3.4 datetime-Z fix.)
		return IsoDate::to_z( (int) $date->getTimestamp() );
	}

	/**
	 * A REQUIRED string field (name, product_url) as the engine's `{lang: value}`
	 * object when the detector supplied per-language translations, else the
	 * single scalar. Empty per-language entries are dropped; an all-empty map
	 * falls back to the scalar so a required field is never sent empty
	 * (RECENGINE_API_CONTRACT.md §3 — `name`/`product_url` non-empty).
	 *
	 * @param array<string, string>|string $translated get_translations() field.
	 *
	 * @return array<string, string>|string
	 */
	private function localized( $translated, string $fallback ) {
		if ( is_array( $translated ) ) {
			$map = array_filter(
				array_map( 'strval', $translated ),
				static fn ( string $value ): bool => $value !== ''
			);
			if ( $map !== array() ) {
				return $map;
			}
		}
		return $fallback;
	}

	/**
	 * description as a `{lang: value}` object (each language clamped to 500
	 * chars) when multilingual, else the single clamped string. Returns '' /
	 * array() when there's nothing to send so build() omits the OPTIONAL field.
	 *
	 * @param array<string, string>|string $translated get_translations() value.
	 *
	 * @return array<string, string>|string
	 */
	private function localized_description( \WC_Product $product, $translated ) {
		if ( is_array( $translated ) ) {
			$map = array();
			foreach ( $translated as $lang => $raw ) {
				$clamped = $this->clamp_description( (string) $raw );
				if ( $clamped !== '' ) {
					$map[ (string) $lang ] = $clamped;
				}
			}
			return $map;
		}
		return $this->description( $product );
	}

	private function description( \WC_Product $product ): string {
		return $this->clamp_description( (string) $product->get_short_description() );
	}

	/**
	 * Strip tags, trim, and cap at the contract's 500-char ceiling so the
	 * engine doesn't reject or silently truncate. Applied per language.
	 */
	private function clamp_description( string $raw ): string {
		$text = trim( (string) wp_strip_all_tags( $raw ) );
		if ( $text === '' ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::DESCRIPTION_MAX );
		}
		return substr( $text, 0, self::DESCRIPTION_MAX );
	}

	private function image_url( \WC_Product $product ): string {
		if ( ! function_exists( 'wp_get_attachment_url' ) ) {
			return '';
		}
		$image_id = (int) $product->get_image_id();
		if ( $image_id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_url( $image_id );
		return is_string( $url ) ? $url : '';
	}

	private function product_url( \WC_Product $product ): string {
		if ( ! function_exists( 'get_permalink' ) ) {
			return '';
		}
		$url = get_permalink( (int) $product->get_id() );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Best-effort tag map the engine consumes immediately (vs
	 * raw_attributes, which feeds the mapping wizard). Always carries
	 * `product_id` — the RAW canonical PARENT product id (SkuResolver::
	 * product_group_id) that groups this variation's `woo-<variation_id>`
	 * row back to its product for the engine's cross-variant grouping
	 * (PRO-1227) and product-level removal (`catalog/remove` §3b, PRO-1230).
	 * Brand is pulled from a `brand` / `pa_brand` attribute when present;
	 * category_path is echoed so the engine can group without re-deriving.
	 *
	 * @return array<string, string>
	 */
	private function tags( \WC_Product $product, string $category_path, bool $category_defaulted = false ): array {
		// product_id: parent id shared by all variations of one product, RAW
		// (no `woo-` prefix) for Shopify parity + the §3b remove-by-id shape.
		$tags = array( 'product_id' => SkuResolver::product_group_id( $product, $this->detector() ) );

		$brand = $this->attribute_value( $product, 'brand' );
		if ( $brand === '' ) {
			$brand = $this->attribute_value( $product, 'pa_brand' );
		}
		if ( $brand !== '' ) {
			$tags['brand'] = $brand;
		}

		if ( $category_path !== '' ) {
			$tags['category_path'] = $category_path;
			// Omit-on-false (contract §"category-defaulted", PRO-1499): only
			// stamp the marker when a real (non-empty) category_path was
			// actually substituted — a defaulted-but-still-empty category_path
			// (unresolvable store default) fails the engine's REQUIRED-field
			// check regardless, so there is no placeholder value to flag.
			if ( $category_defaulted ) {
				$tags['category_defaulted'] = 'true';
			}
		}

		return $tags;
	}

	/**
	 * Raw platform attributes for the engine's mapping wizard. Reads the
	 * product's attribute objects defensively — WC_Product::get_attributes()
	 * returns WC_Product_Attribute instances on a product and a flat
	 * name => value map on a variation.
	 *
	 * Values are term LABELS, never term ids (engine ask, 2026-06-12): for a
	 * TAXONOMY attribute WC_Product_Attribute::get_options() returns term
	 * IDS (`pa_kaubamargid: ["398"]`) and a variation's scalar value is the
	 * term SLUG — both were forwarded as-is, and the engine cannot derive
	 * life_stage / brand / pack_size rules from numbers. Contract §3's
	 * raw_attributes examples are labels. Custom (non-taxonomy) attributes
	 * already carry their literal values and pass through unchanged. The
	 * original unit test faked an attribute whose options were already
	 * strings — mirroring the wrong assumption (LESSONS §2.4 shape), which
	 * is why the id leak shipped; the integration test now uses a REAL
	 * taxonomy attribute.
	 *
	 * @return array<string, mixed>
	 */
	private function raw_attributes( \WC_Product $product ): array {
		if ( ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$attributes = $product->get_attributes();
		if ( ! is_array( $attributes ) || $attributes === array() ) {
			return array();
		}

		$raw = array();
		foreach ( $attributes as $key => $attribute ) {
			$name = (string) $key;
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) && method_exists( $attribute, 'get_options' ) ) {
				$name    = (string) $attribute->get_name();
				$options = $attribute->get_options();
				$options = is_array( $options ) ? $options : array( $options );

				if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
					$raw[ $name ] = $this->term_labels( $product, $name, $options );
				} else {
					$raw[ $name ] = array_map( 'strval', $options );
				}
				continue;
			}
			// Variation form: name => scalar value (term slug for taxonomy attributes).
			if ( is_scalar( $attribute ) ) {
				$raw[ $name ] = $this->variation_term_label( $name, (string) $attribute );
			}
		}

		return $raw;
	}

	/**
	 * Resolve a taxonomy attribute's selected terms to their NAMES. Falls
	 * back to the raw option values (stringified ids) when the lookup is
	 * unavailable or empty — a degraded value beats a dropped attribute,
	 * and the engine's mapping wizard surfaces it either way.
	 *
	 * @param array<int, mixed> $fallback get_options() values (term ids).
	 *
	 * @return array<int, string>
	 */
	private function term_labels( \WC_Product $product, string $taxonomy, array $fallback ): array {
		if ( function_exists( 'wc_get_product_terms' ) ) {
			$names = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
			if ( is_array( $names ) && $names !== array() ) {
				return array_map( 'strval', array_values( $names ) );
			}
		}
		return array_map( 'strval', $fallback );
	}

	/**
	 * Resolve a variation attribute's term SLUG to the term name. Returns
	 * the raw value when it isn't a taxonomy term (custom attribute values
	 * and the "any" empty string pass through).
	 */
	private function variation_term_label( string $taxonomy, string $value ): string {
		if ( $value === ''
			|| ! function_exists( 'taxonomy_exists' )
			|| ! function_exists( 'get_term_by' )
			|| ! taxonomy_exists( $taxonomy )
		) {
			return $value;
		}
		$term = get_term_by( 'slug', $value, $taxonomy );
		return ( $term instanceof \WP_Term ) ? (string) $term->name : $value;
	}

	private function attribute_value( \WC_Product $product, string $name ): string {
		if ( ! method_exists( $product, 'get_attribute' ) ) {
			return '';
		}
		return trim( (string) $product->get_attribute( $name ) );
	}

	/**
	 * Hierarchical category path (e.g. "food/dry"). WooCommerce has no
	 * native "primary category" without an SEO plugin, so we take the
	 * deepest assigned product_cat term — a product tagged Food > Dry
	 * should map to food/dry, not the broad food — and join its ancestor
	 * slugs root-first.
	 *
	 * F3-39 revision (PRO-1491, 2026-07-21): a PUBLISHED product whose
	 * product_cat relationship was never established at all (bulk import /
	 * WPML stand-in row bypassing WC_Product::save(), DECISIONS F3-39
	 * addendum) falls back to the store's OWN `default_product_cat` term
	 * name — WooCommerce's own semantics for an uncategorized product,
	 * not an invented value. Only when even that default is unresolvable
	 * (a broken store) does this return "" so the engine's REQUIRED-field
	 * rejection still surfaces the data gap (fail-loud preserved).
	 *
	 * Public so the storefront browse beacon (3.4.3) emits the SAME
	 * category_path for a product_view as catalog ingest does for the product
	 * — one source, so the engine can correlate browse against catalog.
	 *
	 * @param bool $defaulted Out-param (PRO-1499): set true when the returned
	 *        path came from the store's default-category fallback rather than
	 *        the product's own terms — build() uses this to decide whether to
	 *        stamp tags.category_defaulted. Callers that don't care (the
	 *        browse beacon) simply omit the argument.
	 */
	public function primary_category_path( \WC_Product $product, ?bool &$defaulted = null ): string {
		$defaulted = false;
		if ( ! function_exists( 'get_the_terms' ) ) {
			return '';
		}

		// Variations carry no product_cat terms of their own — inherit the
		// parent's. The engine requires a non-empty category_path (the 3.2.4
		// live probe: variations with "" 400'd "String must contain at least
		// 1 character"), so without this every variation would be rejected.
		$category_source_id = (int) $product->get_id();
		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			$category_source_id = (int) $product->get_parent_id();
		}

		$terms = get_the_terms( $category_source_id, 'product_cat' );
		if ( ! is_array( $terms ) || $terms === array() ) {
			$defaulted = true;
			return $this->default_category_path();
		}

		// $terms is a non-empty array<WP_Term> here (is_array + non-empty
		// checked above), so reset() yields the first assigned category.
		/** @var \WP_Term $primary */
		$primary       = reset( $terms );
		$primary_depth = count( $this->ancestor_ids( (int) $primary->term_id ) );
		foreach ( $terms as $term ) {
			$depth = count( $this->ancestor_ids( (int) $term->term_id ) );
			if ( $depth > $primary_depth ) {
				$primary       = $term;
				$primary_depth = $depth;
			}
		}

		$slugs = array();
		foreach ( array_reverse( $this->ancestor_ids( (int) $primary->term_id ) ) as $ancestor_id ) {
			$slug = $this->term_slug( $ancestor_id );
			if ( $slug !== '' ) {
				$slugs[] = $slug;
			}
		}
		$slugs[] = (string) $primary->slug;

		return implode( '/', array_filter( $slugs, static fn( string $s ): bool => $s !== '' ) );
	}

	/**
	 * WooCommerce's own "uncategorized" resolution: the store's
	 * `default_product_cat` option is the term_id every zero-category
	 * product is supposed to carry (`WC_Post_Data::force_default_term()`
	 * self-heals any active category-clear back to this term — it only
	 * ever leaves a product with zero terms when product_cat was never
	 * touched at all, F3-39 addendum). Resolved AT BUILD TIME so it reads
	 * the store's actual (possibly renamed/localized) term name, never a
	 * hardcoded English literal. Returns "" when the option or the term
	 * itself can't be resolved (a broken store) so primary_category_path()
	 * still falls through to the engine's fail-loud REQUIRED-field rejection.
	 */
	private function default_category_path(): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'get_term' ) ) {
			return '';
		}
		$default_term_id = (int) get_option( 'default_product_cat', 0 );
		if ( $default_term_id <= 0 ) {
			return '';
		}
		$term = get_term( $default_term_id, 'product_cat' );
		return ( is_object( $term ) && isset( $term->name ) && (string) $term->name !== '' )
			? (string) $term->name
			: '';
	}

	/**
	 * @return array<int, int> Ancestor term ids, immediate-parent-first.
	 */
	protected function ancestor_ids( int $term_id ): array {
		if ( ! function_exists( 'get_ancestors' ) ) {
			return array();
		}
		// get_ancestors() always returns an array (empty for a root term).
		return array_map( 'intval', get_ancestors( $term_id, 'product_cat' ) );
	}

	protected function term_slug( int $term_id ): string {
		if ( ! function_exists( 'get_term' ) ) {
			return '';
		}
		$term = get_term( $term_id, 'product_cat' );
		return ( is_object( $term ) && isset( $term->slug ) ) ? (string) $term->slug : '';
	}

	/**
	 * Load a product by id. Protected so tests can stub variation lookups
	 * without a real WC_Product_Factory.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}
}
