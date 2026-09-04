<?php
/**
 * CatalogPayloadBuilder tests — WC_Product → rec-engine catalog object,
 * variable-product expansion, event_uuid → event_id symmetry, and the
 * absent != empty omission policy.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;

final class CatalogPayloadBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// No multilingual plugin in this process → DetectorFactory resolves the
		// single-language SiteLocale fallback, so get_translations() returns
		// scalars and build() uses the product's own fields (the single-language
		// behaviour these tests assert). Reset to stay deterministic.
		DetectorFactory::reset();

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/acana-3kg' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		// No resolvable store default_product_cat by default (PRO-1491 fix A) —
		// individual tests override this to exercise the fallback itself.
		Functions\when( 'get_option' )->justReturn( 0 );
		// SiteLocaleAdapter::get_translations() reads these — stub so they don't
		// trip Brain\Monkey; their scalar return keeps build() on the
		// single-language (product-field) path.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( '' );
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_build_maps_required_fields_and_event_id(): void {
		$product = $this->fake_product(
			array(
				'id'       => 12345,
				'sku'      => 'ACA-DOG-3KG',
				'name'     => 'Acana Adult Dog 3kg',
				'price'    => '22.99',
				'in_stock' => true,
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'evt-uuid-aaaa' );

		self::assertSame( 'evt-uuid-aaaa', $payload['event_id'] );
		self::assertSame( 'woo-12345', $payload['sku'] );
		self::assertSame( 'Acana Adult Dog 3kg', $payload['name'] );
		self::assertSame( 22.99, $payload['price'] );
		self::assertTrue( $payload['in_stock'] );
		self::assertSame( 'https://shop.test/p/acana-3kg', $payload['product_url'] );
		self::assertSame( '12345', $payload['external_id'] );
	}

	public function test_event_id_equals_supplied_queue_uuid(): void {
		// Unit-level guard of the queue.event_uuid == body.event_id invariant
		// (the integration test pins it end-to-end against the mock engine).
		$product = $this->fake_product( array( 'sku' => 'X', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'the-exact-row-uuid' );

		self::assertSame( 'the-exact-row-uuid', $payload['event_id'] );
	}

	public function test_optional_fields_omitted_when_source_empty(): void {
		$product = $this->fake_product(
			array(
				'id'                => 88,
				'sku'               => 'BARE-1',
				'name'              => 'Bare product',
				'price'             => '10.00',
				'regular_price'     => '10.00', // == price → no compare_price
				'short_description' => '',
				'image_id'          => 0,
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u1' );

		self::assertArrayNotHasKey( 'compare_price', $payload );
		self::assertArrayNotHasKey( 'on_sale_until', $payload );
		self::assertArrayNotHasKey( 'description', $payload );
		self::assertArrayNotHasKey( 'image_url', $payload );
		// tags is no longer fully optional: product_id is ALWAYS present (PRO-1224);
		// only brand + category_path are omitted when empty.
		self::assertSame( array( 'product_id' => '88' ), $payload['tags'], 'No brand + no category → tags carries product_id only.' );
		self::assertArrayNotHasKey( 'raw_attributes', $payload );
	}

	public function test_compare_price_present_only_on_genuine_discount(): void {
		$on_sale = $this->fake_product(
			array( 'sku' => 'S', 'price' => '22.99', 'regular_price' => '25.99' )
		);
		$full_price = $this->fake_product(
			array( 'sku' => 'F', 'price' => '25.99', 'regular_price' => '25.99' )
		);

		$builder = new CatalogPayloadBuilder();

		self::assertSame( 25.99, $builder->build( $on_sale, 'u' )['compare_price'] );
		self::assertArrayNotHasKey( 'compare_price', $builder->build( $full_price, 'u' ) );
	}

	public function test_on_sale_until_serialised_iso8601_with_z_suffix(): void {
		$date    = new class() {
			public function getTimestamp(): int {
				return (int) strtotime( '2026-06-01 00:00:00 UTC' );
			}
		};
		$product = $this->fake_product(
			array( 'sku' => 'S', 'price' => '1.00', 'date_on_sale_to' => $date )
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		// `Z`, not `+00:00` — the engine's strict Zod .datetime() rejects an
		// offset (3.3.4 live-walk found the sibling first_seen_at bug).
		self::assertSame( '2026-06-01T00:00:00Z', $payload['on_sale_until'] );
	}

	public function test_description_is_stripped_and_capped_at_500(): void {
		$product = $this->fake_product(
			array(
				'sku'               => 'D',
				'price'             => '1.00',
				'short_description' => '<p>' . str_repeat( 'a', 600 ) . '</p>',
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertArrayHasKey( 'description', $payload );
		self::assertSame( 500, strlen( $payload['description'] ) );
		self::assertStringNotContainsString( '<', $payload['description'] );
	}

	public function test_image_url_resolved_from_attachment(): void {
		Functions\when( 'wp_get_attachment_url' )->alias(
			static fn( $id ) => 7 === $id ? 'https://cdn.test/img-7.jpg' : false
		);
		$product = $this->fake_product( array( 'sku' => 'I', 'price' => '1.00', 'image_id' => 7 ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( 'https://cdn.test/img-7.jpg', $payload['image_url'] );
	}

	public function test_category_path_uses_deepest_term_with_ancestor_slugs(): void {
		$food = (object) array( 'term_id' => 10, 'slug' => 'food' );
		$dry  = (object) array( 'term_id' => 20, 'slug' => 'dry' );

		Functions\when( 'get_the_terms' )->justReturn( array( $food, $dry ) );
		Functions\when( 'get_ancestors' )->alias(
			static function ( int $term_id ): array {
				return 20 === $term_id ? array( 10 ) : array();
			}
		);
		Functions\when( 'get_term' )->alias(
			static fn( int $id ) => 10 === $id ? (object) array( 'slug' => 'food' ) : null
		);

		$product = $this->fake_product( array( 'sku' => 'C', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( 'food/dry', $payload['category_path'] );
	}

	public function test_category_path_falls_back_to_store_default_category_when_product_has_no_categories(): void {
		// F3-39 REVISION (PRO-1491, 2026-07-21): get_the_terms() returning
		// nothing (false/empty — the default setUp() stub) now falls back to
		// the store's OWN default_product_cat term name — WooCommerce's own
		// "uncategorized" semantics, resolved at build time (never a
		// hardcoded English literal). MiuMjau's 253 real published products
		// were being silently excluded from the engine's catalog by the
		// REQUIRED-field rejection this fixes.
		Functions\when( 'get_option' )->justReturn( 7 ); // default_product_cat term_id.
		Functions\when( 'get_term' )->alias(
			static fn ( int $id ) => 7 === $id ? (object) array( 'name' => 'Muu' ) : null
		);
		$product = $this->fake_product( array( 'sku' => 'NOCAT-1', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( 'Muu', $payload['category_path'], 'The store default term NAME is used — its localized value, not "uncategorized".' );
		self::assertSame( 'Muu', $payload['tags']['category_path'], 'tags.category_path echoes the same resolved value.' );
		// PRO-1499: the store-default substitution is flagged so the engine
		// skips category-slug-keyed derivation for this placeholder row.
		self::assertSame( 'true', $payload['tags']['category_defaulted'] );
	}

	public function test_category_path_is_empty_string_when_default_category_is_unresolvable(): void {
		// F3-39's original fail-loud behavior is preserved for a genuinely
		// broken store: get_the_terms() empty AND the store's own
		// default_product_cat option unresolvable (the default setUp() stub,
		// get_option → 0) yields the bare empty string, never an invented
		// value. category_path is a contract-REQUIRED, non-empty field
		// (RECENGINE_API_CONTRACT.md §3) — the engine's resulting
		// `d6_item_error field=category_path` is the intended data-gap
		// signal, not a bug to mask.
		$product = $this->fake_product( array( 'sku' => 'NOCAT-1', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( '', $payload['category_path'] );
		// PRO-1499: an unresolvable (still-empty) category_path is not a
		// substituted VALUE, so it must not be flagged — the row fails the
		// engine's REQUIRED-field check regardless, with no placeholder to mark.
		self::assertArrayNotHasKey( 'category_defaulted', $payload['tags'] );
	}

	// --- always-sendable catalog.delete tombstones (PRO-1498) ---------------

	public function test_ensure_valid_removal_fills_blank_category_path_with_placeholder(): void {
		// A tombstone has no merchant-data-gap signal to protect (unlike
		// build()'s upsert-shared fail-loud policy) — its only job is "mark
		// this SKU unavailable", so a still-blank category_path gets a
		// generic placeholder rather than being left empty for the engine to
		// 400 (PRO-1498, extends F3-43's never-drop principle).
		$builder = new CatalogPayloadBuilder();

		$object = $builder->ensure_valid_removal(
			array(
				'category_path' => '',
				'product_url'   => 'https://shop.test/p/1',
				'external_id'   => '1',
			)
		);

		self::assertSame( 'uncategorized', $object['category_path'] );
		self::assertSame( 'https://shop.test/p/1', $object['product_url'], 'A valid product_url is left untouched.' );
		// PRO-1499: forcing the placeholder is exactly the substitution
		// tags.category_defaulted marks.
		self::assertSame( 'true', $object['tags']['category_defaulted'] );
	}

	public function test_ensure_valid_removal_fills_blank_product_url_with_fallback(): void {
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test' . $path );
		$builder = new CatalogPayloadBuilder();

		$object = $builder->ensure_valid_removal(
			array(
				'category_path' => 'food/dry',
				'product_url'   => '',
				'external_id'   => '42',
			)
		);

		self::assertSame( 'food/dry', $object['category_path'], 'A valid category_path is left untouched.' );
		self::assertSame( 'https://shop.test/?smaily_connect_removed_product=42', $object['product_url'] );
		// PRO-1499: only the product_url was force-filled, not category_path —
		// no substitution to flag.
		self::assertArrayNotHasKey( 'tags', $object );
	}

	public function test_ensure_valid_removal_treats_empty_multilingual_product_url_map_as_blank(): void {
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test' . $path );
		$builder = new CatalogPayloadBuilder();

		$object = $builder->ensure_valid_removal(
			array(
				'category_path' => 'food/dry',
				'product_url'   => array(),
				'external_id'   => '7',
			)
		);

		self::assertSame( 'https://shop.test/?smaily_connect_removed_product=7', $object['product_url'] );
	}

	public function test_build_unresolvable_produces_all_required_fields_non_empty(): void {
		// PRO-1498: a product id that no longer resolves to a WC_Product at
		// all (e.g. its product_type came from a since-deactivated plugin)
		// still gets a minimal, contract-valid tombstone from the bare id.
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test' . $path );
		$builder = new CatalogPayloadBuilder();

		$object = $builder->build_unresolvable( 999, 'evt-unresolvable' );

		self::assertSame( 'evt-unresolvable', $object['event_id'] );
		self::assertSame( 'woo-999', $object['sku'] );
		self::assertSame( 'Unavailable product #999', $object['name'] );
		self::assertSame( 'uncategorized', $object['category_path'], 'No resolvable store default in this test (get_option → 0, the setUp() default).' );
		self::assertSame( 0.0, $object['price'] );
		self::assertFalse( $object['in_stock'] );
		self::assertSame( 'https://shop.test/?smaily_connect_removed_product=999', $object['product_url'] );
		self::assertSame( '999', $object['external_id'] );
		// PRO-1499: a tombstone always syncs SOME category_path — never real
		// taxonomy — so it always carries the flag.
		self::assertSame( 'true', $object['tags']['category_defaulted'] );
	}

	public function test_build_unresolvable_uses_store_default_category_when_resolvable(): void {
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'get_option' )->justReturn( 7 ); // default_product_cat term_id.
		Functions\when( 'get_term' )->alias(
			static fn ( int $id ) => 7 === $id ? (object) array( 'name' => 'Muu' ) : null
		);
		$builder = new CatalogPayloadBuilder();

		$object = $builder->build_unresolvable( 999, 'u' );

		self::assertSame( 'Muu', $object['category_path'], 'Prefers the real store default term over the last-resort literal.' );
		// PRO-1499: still a tombstone — the flag doesn't depend on which
		// placeholder text was used.
		self::assertSame( 'true', $object['tags']['category_defaulted'] );
	}

	public function test_expand_simple_product_returns_itself(): void {
		$product = $this->fake_product( array( 'sku' => 'SIMPLE-1', 'type' => 'simple' ) );

		$units = ( new CatalogPayloadBuilder() )->expand( $product );

		self::assertCount( 1, $units );
		self::assertSame( $product, $units[0] );
	}

	public function test_expand_simple_without_sku_returns_itself(): void {
		// F3-36: SKU-less units are no longer dropped — build() keys them
		// synthetically. Dropping here silently emptied a SKU-less store's
		// whole catalog (the pilot).
		$product = $this->fake_product( array( 'sku' => '', 'type' => 'simple' ) );

		$units = ( new CatalogPayloadBuilder() )->expand( $product );

		self::assertCount( 1, $units );
		self::assertSame( $product, $units[0] );
	}

	public function test_build_skuless_product_gets_woo_id_key(): void {
		$product = $this->fake_product( array( 'id' => 77, 'sku' => '', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'uuid-77' );

		self::assertSame( 'woo-77', $payload['sku'] );
	}

	public function test_build_ignores_merchant_sku_keys_on_woo_id(): void {
		// PRO-1224: a product WITH a merchant SKU still keys `woo-<id>` — the
		// merchant SKU field is never emitted as `sku` (the raw id rides in
		// external_id instead). Reverses F3-36.
		$product = $this->fake_product( array( 'id' => 77, 'sku' => 'REAL-1', 'price' => '1.00' ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'uuid-77' );

		self::assertSame( 'woo-77', $payload['sku'] );
		self::assertSame( '77', $payload['external_id'], 'The raw platform id rides in external_id.' );
	}

	public function test_expand_variable_fans_out_to_all_variations(): void {
		$v1 = $this->fake_product( array( 'id' => 101, 'sku' => 'V-1' ) );
		$v2 = $this->fake_product( array( 'id' => 102, 'sku' => '' ) ); // skuless → synthetic key in build() (F3-36)
		$v3 = $this->fake_product( array( 'id' => 103, 'sku' => 'V-3' ) );

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $v1, $v2, $v3 ) {
				return array( 101 => $v1, 102 => $v2, 103 => $v3 )[ $id ] ?? null;
			}
		);

		$parent = $this->fake_product(
			array( 'sku' => 'PARENT', 'type' => 'variable', 'children' => array( 101, 102, 103 ) )
		);

		$units = ( new CatalogPayloadBuilder() )->expand( $parent );

		self::assertCount( 3, $units, 'Every loadable variation is its own ingest unit — SKU-less ones included (F3-36).' );
		self::assertSame( 'V-1', $units[0]->get_sku() );
		self::assertSame( '', $units[1]->get_sku() );
		self::assertSame( 'V-3', $units[2]->get_sku() );
	}

	public function test_tags_carry_product_id_brand_and_category_path(): void {
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'term_id' => 5, 'slug' => 'toys' ) ) );

		$product = $this->fake_product(
			array(
				'id'            => 555,
				'sku'           => 'T',
				'price'         => '1.00',
				'attribute_map' => array( 'brand' => 'Acana' ),
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		// product_id (PRO-1224) is the RAW canonical parent id — always present —
		// alongside brand + category_path. A real (non-defaulted) category_path
		// omits category_defaulted entirely (PRO-1499) — the exact array match
		// below proves it, no separate assertion needed.
		self::assertSame(
			array( 'product_id' => '555', 'brand' => 'Acana', 'category_path' => 'toys' ),
			$payload['tags']
		);
	}

	public function test_raw_attributes_custom_attribute_values_pass_through(): void {
		// Non-taxonomy (custom) attribute: options ARE the literal values.
		$attr = new class() {
			/** @return string */
			public function get_name() {
				return 'flavor';
			}
			/** @return array<int, string> */
			public function get_options() {
				return array( 'chicken' );
			}
			/** @return bool */
			public function is_taxonomy() {
				return false;
			}
		};

		$product = $this->fake_product(
			array( 'sku' => 'R', 'price' => '1.00', 'attributes' => array( 'flavor' => $attr ) )
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( array( 'flavor' => array( 'chicken' ) ), $payload['raw_attributes'] );
	}

	public function test_raw_attributes_taxonomy_term_ids_resolve_to_labels(): void {
		// Engine ask 2026-06-12: a REAL taxonomy attribute's get_options()
		// returns term IDS — the wire must carry term NAMES. (The previous
		// version of this test faked options that were already strings,
		// mirroring the wrong assumption — LESSONS §2.4.)
		Functions\when( 'wc_get_product_terms' )->alias(
			static function ( int $product_id, string $taxonomy, array $args = array() ) {
				return ( 'pa_kaubamargid' === $taxonomy && ( $args['fields'] ?? '' ) === 'names' )
					? array( 'Brit Care' )
					: array();
			}
		);

		$attr = new class() {
			/** @return string */
			public function get_name() {
				return 'pa_kaubamargid';
			}
			/** @return array<int, int> */
			public function get_options() {
				return array( 398 ); // term id, the pilot's exact symptom.
			}
			/** @return bool */
			public function is_taxonomy() {
				return true;
			}
		};

		$product = $this->fake_product(
			array( 'sku' => 'R2', 'price' => '1.00', 'attributes' => array( 'pa_kaubamargid' => $attr ) )
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame(
			array( 'pa_kaubamargid' => array( 'Brit Care' ) ),
			$payload['raw_attributes'],
			'Term ids must resolve to term labels on the wire.'
		);
	}

	public function test_raw_attributes_variation_slug_resolves_to_label(): void {
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $tax ): bool => 'pa_vali-kaal' === $tax
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( string $by, string $value, string $tax ) {
				return ( 'slug' === $by && '3kg' === $value && 'pa_vali-kaal' === $tax )
					? new \WP_Term( '3 kg' )
					: false;
			}
		);

		$product = $this->fake_product(
			array(
				'sku'        => 'V',
				'price'      => '1.00',
				'attributes' => array(
					'pa_vali-kaal' => '3kg',     // taxonomy slug → label
					'engraving'    => 'Muki',    // custom value → unchanged
				),
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		self::assertSame( '3 kg', $payload['raw_attributes']['pa_vali-kaal'] );
		self::assertSame( 'Muki', $payload['raw_attributes']['engraving'] );
	}

	public function test_variation_inherits_parent_category_path(): void {
		// Variations carry no product_cat terms; the engine requires a
		// non-empty category_path, so the builder must fall back to the parent.
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $id ) {
				return 50 === $id ? array( (object) array( 'term_id' => 9, 'slug' => 'food' ) ) : false;
			}
		);
		$variation = $this->fake_product( array( 'id' => 101, 'sku' => 'V-1', 'price' => '1.00', 'parent_id' => 50 ) );

		$payload = ( new CatalogPayloadBuilder() )->build( $variation, 'u' );

		self::assertSame( 'food', $payload['category_path'], 'A variation must inherit its parent product category.' );
		// The variation keys on its OWN id (woo-101) while tags.product_id groups
		// it to the RAW parent id (50) — PRO-1224 / PRO-1227 grouping key.
		self::assertSame( 'woo-101', $payload['sku'] );
		self::assertSame( '50', $payload['tags']['product_id'] );
	}

	// --- structural signal (product_type / virtual / downloadable, CC.4) -----

	public function test_build_emits_structural_signal_fields(): void {
		$product = $this->fake_product(
			array(
				'id'           => 7,
				'sku'          => 'GC-1',
				'type'         => 'pw-gift-card',
				'virtual'      => true,
				'downloadable' => true,
			)
		);

		$payload = ( new CatalogPayloadBuilder() )->build( $product, 'u' );

		// The engine derives `recommendable` from these — the plugin only
		// supplies the signal, it never excludes (DECISIONS F3-38).
		self::assertSame( 'pw-gift-card', $payload['product_type'] );
		self::assertTrue( $payload['is_virtual'] );
		self::assertTrue( $payload['is_downloadable'] );
	}

	public function test_simple_product_signal_defaults(): void {
		$payload = ( new CatalogPayloadBuilder() )->build( $this->fake_product( array( 'id' => 1, 'sku' => 'S' ) ), 'u' );

		self::assertSame( 'simple', $payload['product_type'] );
		self::assertFalse( $payload['is_virtual'] );
		self::assertFalse( $payload['is_downloadable'] );
	}

	// --- multilingual model B ({lang:value} objects, CC.3) -------------------

	public function test_multilingual_fields_are_sent_as_lang_value_objects(): void {
		$builder = new CatalogPayloadBuilder(
			$this->multilingual_detector(
				array(
					'name'        => array(
						'et' => 'Acana Koer 3kg',
						'en' => 'Acana Adult Dog 3kg',
					),
					'description' => array(
						'et' => '<p>Premium kuivtoit</p>',
						'en' => '<p>Premium dry food</p>',
					),
					'product_url' => array(
						'et' => 'https://shop.test/et/acana-3kg',
						'en' => 'https://shop.test/en/acana-3kg',
					),
				)
			)
		);

		$payload = $builder->build( $this->fake_product( array( 'id' => 12345, 'sku' => 'ACA-3KG' ) ), 'u' );

		self::assertSame( array( 'et' => 'Acana Koer 3kg', 'en' => 'Acana Adult Dog 3kg' ), $payload['name'] );
		self::assertSame(
			array( 'et' => 'https://shop.test/et/acana-3kg', 'en' => 'https://shop.test/en/acana-3kg' ),
			$payload['product_url']
		);
		// Each language is tag-stripped independently.
		self::assertSame( array( 'et' => 'Premium kuivtoit', 'en' => 'Premium dry food' ), $payload['description'] );
	}

	public function test_description_is_clamped_to_500_per_language(): void {
		$builder = new CatalogPayloadBuilder(
			$this->multilingual_detector(
				array(
					'name'        => array( 'et' => 'Nimi', 'en' => 'Name' ),
					'description' => array(
						'et' => str_repeat( 'a', 600 ),
						'en' => 'short',
					),
					'product_url' => array( 'et' => 'https://s/et', 'en' => 'https://s/en' ),
				)
			)
		);

		$payload = $builder->build( $this->fake_product( array( 'id' => 1, 'sku' => 'S' ) ), 'u' );

		self::assertSame( 500, strlen( $payload['description']['et'] ) );
		self::assertSame( 'short', $payload['description']['en'] );
	}

	public function test_empty_language_values_are_dropped_from_the_object(): void {
		$builder = new CatalogPayloadBuilder(
			$this->multilingual_detector(
				array(
					'name'        => array( 'et' => 'Nimi', 'en' => '' ),
					'description' => array( 'et' => '', 'en' => '' ),
					'product_url' => array( 'et' => 'https://s/et', 'en' => '' ),
				)
			)
		);

		$payload = $builder->build( $this->fake_product( array( 'id' => 1, 'sku' => 'S' ) ), 'u' );

		self::assertSame( array( 'et' => 'Nimi' ), $payload['name'], 'The empty en title is dropped.' );
		self::assertSame( array( 'et' => 'https://s/et' ), $payload['product_url'] );
		self::assertArrayNotHasKey( 'description', $payload, 'All-empty description object → field omitted.' );
	}

	public function test_empty_name_object_falls_back_to_the_product_name(): void {
		// A REQUIRED field whose every language is empty must not be sent empty —
		// fall back to the product's own scalar name.
		$builder = new CatalogPayloadBuilder(
			$this->multilingual_detector(
				array(
					'name'        => array( 'et' => '', 'en' => '' ),
					'description' => array(),
					'product_url' => array( 'et' => '', 'en' => '' ),
				)
			)
		);

		$payload = $builder->build(
			$this->fake_product( array( 'id' => 1, 'sku' => 'S', 'name' => 'Fallback Name' ) ),
			'u'
		);

		self::assertSame( 'Fallback Name', $payload['name'] );
		self::assertSame( 'https://shop.test/p/acana-3kg', $payload['product_url'], 'Falls back to get_permalink scalar.' );
	}

	/**
	 * @param array{name: array<string,string>, description: array<string,string>, product_url: array<string,string>} $translations
	 */
	private function multilingual_detector( array $translations ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_translations' )->willReturn( $translations );
		$detector->method( 'get_canonical_post_id' )->willReturnArgument( 0 );
		return $detector;
	}

	/**
	 * @param array<string, mixed> $p
	 */
	private function fake_product( array $p ): \WC_Product {
		return new class( $p ) extends \WC_Product {
			/** @var array<string, mixed> */
			private array $p;

			/** @param array<string, mixed> $p */
			public function __construct( array $p ) {
				$this->p = $p;
			}

			public function get_id( $context = 'view' ) {
				return (int) ( $this->p['id'] ?? 0 );
			}
			public function get_parent_id( $context = 'view' ) {
				return (int) ( $this->p['parent_id'] ?? 0 );
			}
			public function get_sku( $context = 'view' ) {
				return (string) ( $this->p['sku'] ?? '' );
			}
			public function get_name( $context = 'view' ) {
				return (string) ( $this->p['name'] ?? '' );
			}
			public function get_price( $context = 'view' ) {
				return (string) ( $this->p['price'] ?? '' );
			}
			public function get_regular_price( $context = 'view' ) {
				return (string) ( $this->p['regular_price'] ?? '' );
			}
			public function is_in_stock() {
				return (bool) ( $this->p['in_stock'] ?? true );
			}
			public function get_date_on_sale_to( $context = 'view' ) {
				return $this->p['date_on_sale_to'] ?? null;
			}
			public function get_short_description( $context = 'view' ) {
				return (string) ( $this->p['short_description'] ?? '' );
			}
			public function get_image_id( $context = 'view' ) {
				return (int) ( $this->p['image_id'] ?? 0 );
			}
			public function is_type( $type ) {
				return ( $this->p['type'] ?? 'simple' ) === $type;
			}
			public function get_type() {
				return (string) ( $this->p['type'] ?? 'simple' );
			}
			public function is_virtual() {
				return (bool) ( $this->p['virtual'] ?? false );
			}
			public function is_downloadable() {
				return (bool) ( $this->p['downloadable'] ?? false );
			}
			public function get_children( $context = 'view' ) {
				return $this->p['children'] ?? array();
			}
			public function get_attributes( $context = 'view' ) {
				return $this->p['attributes'] ?? array();
			}
			public function get_attribute( $name ) {
				return (string) ( ( $this->p['attribute_map'] ?? array() )[ $name ] ?? '' );
			}
		};
	}
}

// Minimal WC_Product shim for the anonymous fakes to extend — Brain Monkey
// doesn't load WooCommerce. Declares only the surface CatalogPayloadBuilder
// touches.
if ( ! class_exists( \WC_Product::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WC_Product {
			public function get_id( $context = 'view' ) { return 0; }
			public function get_parent_id( $context = 'view' ) { return 0; }
			public function get_sku( $context = 'view' ) { return ''; }
			public function get_name( $context = 'view' ) { return ''; }
			public function get_price( $context = 'view' ) { return ''; }
			public function get_regular_price( $context = 'view' ) { return ''; }
			public function is_in_stock() { return true; }
			public function get_date_on_sale_to( $context = 'view' ) { return null; }
			public function get_short_description( $context = 'view' ) { return ''; }
			public function get_image_id( $context = 'view' ) { return 0; }
			public function is_type( $type ) { return false; }
			public function get_type() { return 'simple'; }
			public function is_virtual() { return false; }
			public function is_downloadable() { return false; }
			public function get_children( $context = 'view' ) { return array(); }
			public function get_attributes( $context = 'view' ) { return array(); }
			public function get_attribute( $name ) { return ''; }
		}
PHP
	);
}

// WP_Term shim — variation_term_label() type-checks `instanceof WP_Term`
// (PHPStan: the stubs' WP_Term::$name is non-nullable, so isset() is
// rejected), and Brain Monkey doesn't load WP core classes.
if ( ! class_exists( \WP_Term::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
		class WP_Term {
			public $name = '';
			public function __construct( $name = '' ) { $this->name = $name; }
		}
PHP
	);
}
