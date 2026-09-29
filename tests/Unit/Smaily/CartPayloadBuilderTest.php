<?php
/**
 * CartPayloadBuilder tests (PRO-1195) — legacy wire parity is the contract.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Smaily\CartPayloadBuilder;

require_once dirname( __DIR__, 3 ) . '/includes/smaily-options.class.php';

final class CartPayloadBuilderTest extends TestCase {

	/** @var array<string, mixed> The fields option served to the builder. */
	private array $fields_option;

	/** @var array<int, object> wc_get_product() fixtures keyed by id. */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DetectorFactory::reset();

		$this->fields_option = \Smaily_Connect\Includes\Options::ABANDONED_CART_DEFAULT_FIELDS;
		$this->products      = array();

		Functions\when( 'get_locale' )->justReturn( 'et_EE' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( false );

		$options = &$this->fields_option;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$options ) {
				if ( $key === \Smaily_Connect\Includes\Options::ABANDONED_CART_FIELDS_OPTION ) {
					return $options;
				}
				return $fallback;
			}
		);

		$products = &$this->products;
		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( &$products ) {
				return $products[ $id ] ?? false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		DetectorFactory::reset();
		parent::tearDown();
	}

	public function test_default_fields_produce_the_legacy_address_shape(): void {
		$payload = $this->builder()->build( $this->row() );

		self::assertNotNull( $payload );
		self::assertSame( 'guest@example.test', $payload['email'] );
		// Default fields: store_url + user_email + language on.
		self::assertSame( 'https://shop.example.test', $payload['fields']['store'] );
		self::assertSame( 'true', $payload['fields']['is_abandoned_cart'], 'The is_abandoned_cart discriminator is a carried-over business requirement.' );
		// PRO-1681: the run marker is additive — is_abandoned_cart above keeps
		// its exact name and meaning.
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$payload['fields']['abandoned_cart_automation_at']
		);
		// The resolver's site default ('et_EE' → 'et') rides both levels:
		// fields (legacy address parity) + top level (router language lookup).
		self::assertSame( 'et', $payload['fields']['language'] );
		self::assertSame( 'et', $payload['language'] );
	}

	public function test_every_product_slot_is_prefilled_empty_for_legacy_template_parity(): void {
		// The legacy Smaily API requires ALL fields updated on every send —
		// the legacy pass prefilled product_<field>_1..10 with '' and
		// merchant templates rely on that. Sending the empty slots is what
		// clears a previously reminded cart from the contact (PRO-1680).
		$payload = $this->builder()->build( $this->row( '[]' ) );

		self::assertNotNull( $payload );
		foreach ( array( 'product_name', 'product_price', 'product_sku', 'product_quantity', 'product_base_price', 'product_description', 'product_image_url', 'product_url' ) as $key ) {
			for ( $i = 1; $i <= 10; $i++ ) {
				self::assertArrayHasKey( $key . '_' . $i, $payload['fields'] );
				self::assertSame( '', $payload['fields'][ $key . '_' . $i ] );
			}
		}
		self::assertArrayNotHasKey( 'over_10_products', $payload['fields'] );
	}

	public function test_fresh_install_defaults_fill_every_product_field_with_escaped_values(): void {
		// PRO-1680: the fields option's product_* keys all default to false and
		// no UI can flip them — a fresh install must still send every product
		// detail, so the selection is not consulted at all.
		$this->products[11] = $this->product( 'Koera & kassi toit' );

		$payload = $this->builder()->build( $this->row( '[{"product_id":11,"variation_id":0,"quantity":3}]' ) );

		self::assertNotNull( $payload );
		self::assertSame( 'Koera &amp; kassi toit', $payload['fields']['product_name_1'], 'Values are htmlspecialchars-escaped — legacy parity.' );
		self::assertSame( '3', $payload['fields']['product_quantity_1'] );
		self::assertSame( '12.40 €', $payload['fields']['product_price_1'], 'Price rides the stubbed display-price seam.' );
		self::assertSame( '15.00 €', $payload['fields']['product_base_price_1'] );
		self::assertSame( 'SKU-1', $payload['fields']['product_sku_1'] );
		self::assertSame( 'desc', $payload['fields']['product_description_1'] );
		self::assertSame( '', $payload['fields']['product_name_2'], 'Unused slots still ride the wire empty — that is what clears the previous cart.' );
	}

	public function test_each_cart_product_links_to_its_plain_product_page(): void {
		// PRO-3335: the same product_url_N tag the order and shipping
		// confirmations carry, so one template convention serves all three.
		$this->products[11] = $this->product( 'Dog food', 'https://shop.example.test/product/dog-food/' );

		$payload = $this->builder()->build( $this->row() );

		self::assertNotNull( $payload );
		self::assertSame( 'https://shop.example.test/product/dog-food/', $payload['fields']['product_url_1'], 'The plain permalink — no tracking or campaign parameters added.' );
		for ( $i = 2; $i <= 10; $i++ ) {
			self::assertSame( '', $payload['fields'][ 'product_url_' . $i ], 'Unused slots carry no link, like every other product field.' );
		}
		self::assertSame( 'Dog food', $payload['fields']['product_name_1'], 'The existing fields keep their names and values.' );
		self::assertSame( 'SKU-1', $payload['fields']['product_sku_1'] );
	}

	public function test_a_cart_variation_links_to_the_variation_in_the_cart(): void {
		$this->products[11] = $this->product( 'Shirt', 'https://shop.example.test/product/shirt/' );
		$this->products[12] = $this->variation( 'https://shop.example.test/product/shirt/?attribute_pa_color=blue' );

		$payload = $this->builder()->build( $this->row( '[{"product_id":11,"variation_id":12,"quantity":1}]' ) );

		self::assertNotNull( $payload );
		self::assertSame( 'https://shop.example.test/product/shirt/?attribute_pa_color=blue', $payload['fields']['product_url_1'] );
		self::assertSame( 'Shirt', $payload['fields']['product_name_1'], 'The existing fields still describe the product, as before.' );
	}

	public function test_an_unpublished_product_has_an_empty_link(): void {
		$this->products[11] = $this->product( 'Trashed', 'https://shop.example.test/?post_type=product&p=11', 'trash' );

		$payload = $this->builder()->build( $this->row() );

		self::assertNotNull( $payload );
		self::assertSame( '', $payload['fields']['product_url_1'], 'No link rather than a dead one.' );
	}

	public function test_an_upgraded_stores_product_field_selection_is_ignored(): void {
		// A store carrying a selection from a version that had the UI: only
		// product_sku ticked. PRO-1680 — the selection is ignored, everything
		// is sent.
		$this->fields_option['product_sku'] = true;
		$this->products[11]                 = $this->product( 'Toode' );

		$payload = $this->builder()->build( $this->row() );

		self::assertNotNull( $payload );
		self::assertSame( 'SKU-1', $payload['fields']['product_sku_1'] );
		self::assertSame( 'Toode', $payload['fields']['product_name_1'], 'An unticked field is sent anyway — there is no merchant-facing choice.' );
		self::assertSame( '12.40 €', $payload['fields']['product_price_1'] );
	}

	public function test_more_than_ten_products_sets_the_over_10_flag(): void {
		$items = array();
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->products[ $i ] = $this->product( 'Toode ' . $i );
			$items[]              = array(
				'product_id' => $i,
				'quantity'   => 1,
			);
		}

		$payload = $this->builder()->build( $this->row( (string) json_encode( $items ) ) );

		self::assertNotNull( $payload );
		self::assertSame( 'true', $payload['fields']['over_10_products'] );
		self::assertSame( 'Toode 10', $payload['fields']['product_name_10'] );
	}

	public function test_poison_items_and_missing_products_are_skipped_item_level(): void {
		// F3-53: stored rows are wire input. A bare-string item or a deleted
		// product skips THAT item; the cart still sends.
		$this->products[11] = $this->product( 'Survivor' );

		$payload = $this->builder()->build(
			$this->row( '["i-am-a-string",{"product_id":404,"quantity":1},{"quantity":2},{"product_id":11,"quantity":1}]' )
		);

		self::assertNotNull( $payload );
		self::assertSame( 'Survivor', $payload['fields']['product_name_1'] );
		self::assertSame( '', $payload['fields']['product_name_2'] );
	}

	public function test_non_json_cart_content_returns_null(): void {
		self::assertNull( $this->builder()->build( $this->row( 'O:8:"stdClass":0:{}' ) ), 'A non-JSON cart_content (e.g. legacy serialize residue) can never be sent — null, never a fatal.' );
	}

	public function test_missing_email_returns_null(): void {
		$row          = $this->row();
		$row['email'] = '';
		self::assertNull( $this->builder()->build( $row ) );
	}

	public function test_guest_names_come_from_the_captured_columns(): void {
		// PRO-1729: fresh-install defaults have first_name/last_name off and no
		// UI can flip them — the checkout-captured name rides the reminder
		// anyway.
		$row               = $this->row();
		$row['first_name'] = 'Mari';
		$row['last_name']  = 'Maasikas';

		$payload = $this->builder()->build( $row );

		self::assertNotNull( $payload );
		self::assertSame( 'Mari', $payload['fields']['first_name'] );
		self::assertSame( 'Maasikas', $payload['fields']['last_name'] );
	}

	public function test_an_unknown_name_is_omitted_rather_than_sent_empty(): void {
		// Contact-level omit rule (F3-47): Smaily leaves an ABSENT field intact
		// and WIPES an empty one, so a shopper we have no name for must not
		// clear the name their contact already carries. (The opposite of the
		// product slots, which are deliberately overwritten empty.)
		$payload = $this->builder()->build( $this->row() );

		self::assertNotNull( $payload );
		self::assertArrayNotHasKey( 'first_name', $payload['fields'] );
		self::assertArrayNotHasKey( 'last_name', $payload['fields'] );
	}

	public function test_an_upgraded_stores_name_field_selection_is_ignored(): void {
		// A store carrying a selection from a version that had the UI: only
		// first_name ticked. PRO-1729 — the selection is ignored, BOTH names
		// are sent.
		$this->fields_option['first_name'] = true;
		$this->fields_option['last_name']  = false;

		$row               = $this->row();
		$row['first_name'] = 'Mari';
		$row['last_name']  = 'Maasikas';

		$payload = $this->builder()->build( $row );

		self::assertNotNull( $payload );
		self::assertSame( 'Mari', $payload['fields']['first_name'] );
		self::assertSame( 'Maasikas', $payload['fields']['last_name'], 'An unticked name is sent anyway — there is no merchant-facing choice.' );
	}

	public function test_registered_user_wins_over_captured_columns_and_uses_for_user_language(): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ) {
				if ( $id !== 42 ) {
					return false;
				}
				return new class( 42, 'user@example.test', 'Jaan', 'Kask' ) extends \WP_User {
					public function __construct( int $id, string $email, string $first, string $last ) {
						$this->ID         = $id;
						$this->user_email = $email;
						$this->first_name = $first;
						$this->last_name  = $last;
					}
				};
			}
		);
		$row               = $this->row();
		$row['user_id']    = 42;
		$row['first_name'] = 'Captured';

		$payload = $this->builder()->build( $row );

		self::assertNotNull( $payload );
		self::assertSame( 'Jaan', $payload['fields']['first_name'], 'A known WP user\'s profile wins over checkout-captured columns.' );
		// Single-language unit env: for_user resolves (and clamps) to the
		// site default; resolver tier behavior is pinned in the resolver's
		// own tests.
		self::assertSame( 'et', $payload['language'] );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function row( string $cart_content = '[{"product_id":11,"variation_id":0,"quantity":1}]' ): array {
		return array(
			'id'           => 1,
			'cart_token'   => 'tok',
			'user_id'      => 0,
			'email'        => 'guest@example.test',
			'first_name'   => '',
			'last_name'    => '',
			'cart_content' => $cart_content,
			'cart_updated' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Builder with the two WC-pricing seams stubbed (unit env has no WC).
	 */
	private function builder(): CartPayloadBuilder {
		return new class extends CartPayloadBuilder {
			protected function sale_price_display( $product ): string {
				return '12.40 €';
			}

			protected function base_price_display( $product ): string {
				return '15.00 €';
			}

			protected function product_image_url( $product ): string {
				return '';
			}
		};
	}

	private function product( string $name, string $url = '', string $status = 'publish' ): \WC_Product {
		return new class( $name, $url, $status ) extends \WC_Product {
			private string $name;
			private string $url;
			private string $status;

			public function __construct( string $name, string $url, string $status ) {
				$this->name   = $name;
				$this->url    = $url;
				$this->status = $status;
			}

			public function get_name( $context = 'view' ): string {
				return $this->name;
			}

			public function get_description( $context = 'view' ): string {
				return 'desc';
			}

			public function get_sku( $context = 'view' ): string {
				return 'SKU-1';
			}

			public function get_status( $context = 'view' ): string {
				return $this->status;
			}

			public function get_permalink(): string {
				return $this->url;
			}
		};
	}

	private function variation( string $url ): \WC_Product {
		if ( ! class_exists( \WC_Product_Variation::class ) ) {
			// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
			eval( 'class WC_Product_Variation extends WC_Product {}' );
		}

		return new class( $url ) extends \WC_Product_Variation {
			private string $url;

			public function __construct( string $url ) {
				$this->url = $url;
			}

			public function get_status( $context = 'view' ): string {
				return 'publish';
			}

			public function get_permalink( $item_object = null ): string {
				return $this->url;
			}
		};
	}
}

// WP_User stub for the anonymous fake above (shared shim pattern — see
// HookHandlerTest). Declared conditionally: another test file may have
// loaded it first.
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}
