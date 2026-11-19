<?php
/**
 * Integration: what the public product RSS feed lists (CON-345).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Integrations\WooCommerce\Rss;

/**
 * The feed at /smaily-rss-feed is public and takes its limit from the
 * address. It must never list a password-protected product, and it lists
 * at most 100 products whatever the address asks for — a missing (0)
 * limit keeps the default 50. Each test seeds products in a category of
 * its own and asks for that category, so the store's other products do
 * not count.
 */
final class RssFeedItemsTest extends TestCase {

	/** @var list<int> */
	private array $product_ids = array();

	/** @var list<int> */
	private array $term_ids = array();

	protected function tearDown(): void {
		foreach ( $this->product_ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		foreach ( $this->term_ids as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}
		$this->product_ids = array();
		$this->term_ids    = array();
		parent::tearDown();
	}

	public function test_a_password_protected_product_is_never_listed(): void {
		$slug = $this->category( 'con345-password' );
		$this->product( $slug, 'CON-345 public product' );
		$protected = $this->product( $slug, 'CON-345 protected product' );
		wp_update_post(
			array(
				'ID'            => $protected,
				'post_password' => 'secret',
			)
		);

		foreach ( array( -1, 0, 500 ) as $limit ) {
			$titles = array_column( Rss::list_rss_feed_items( $slug, $limit, '', '', null ), 'title' );
			self::assertSame( array( 'CON-345 public product' ), $titles, "limit {$limit}" );
		}
	}

	public function test_the_feed_lists_at_most_100_products_and_50_by_default(): void {
		$slug = $this->category( 'con345-limit' );
		for ( $i = 1; $i <= 101; $i++ ) {
			$this->product( $slug, "CON-345 product {$i}" );
		}

		self::assertCount( 100, Rss::list_rss_feed_items( $slug, -1, '', '', null ), 'a negative limit' );
		self::assertCount( 100, Rss::list_rss_feed_items( $slug, 500, '', '', null ), 'a limit over 100' );
		self::assertCount( 50, Rss::list_rss_feed_items( $slug, 0, '', '', null ), 'no limit' );
		self::assertCount( 100, Rss::list_rss_feed_items( $slug, 100, '', '', null ), 'a limit of 100' );
		self::assertCount( 7, Rss::list_rss_feed_items( $slug, 7, 'name', 'ASC', null ), 'a limit inside 1-100' );
	}

	private function category( string $slug ): string {
		$term = wp_insert_term( $slug, 'product_cat', array( 'slug' => $slug ) );
		self::assertIsArray( $term );
		$this->term_ids[] = (int) $term['term_id'];
		return $slug;
	}

	private function product( string $category_slug, string $name ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$term = get_term_by( 'slug', $category_slug, 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $term );
		$product->set_category_ids( array( (int) $term->term_id ) );
		$id                  = $product->save();
		$this->product_ids[] = $id;
		return $id;
	}
}
