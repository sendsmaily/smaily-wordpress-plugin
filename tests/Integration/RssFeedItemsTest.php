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
 * limit keeps the default 50. Descriptions go out as HTML, titles as
 * plain text. Each test seeds products in a category of its own and
 * asks for that category, so the store's other products do not count.
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

	/**
	 * Smaily renders the description as HTML and shows the title as text:
	 * an escaped description reached the email as visible tags, and a
	 * title with the store's entities in it as `&quot;` (customer report,
	 * 2026-11-20, CON-353). The description keeps its HTML (a script
	 * dropped), the title is plain text, links keep a bare `&`, and the
	 * dates are RFC 822 English, in a feed an XML parser reads.
	 */
	public function test_the_feed_sends_html_descriptions_and_plain_text_titles(): void {
		global $wpdb;
		$slug = $this->category( 'rss-plain-text' );
		$id   = $this->product( $slug, 'Pojeng "Alexander Fleming" & Õie ümbris' );
		// Written past the save filters, as a user who may post unfiltered
		// HTML (or an import) stores it.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content' => '<strong> Üldinfo</strong> <ul><li>Õitseb juunis&nbsp;ja juulis</li>'
					. "<li><span class=\"Y2IQFc\" lang=\"et\">Sort 'Cora Louise'</span></li></ul>"
					. '<p>Märkus: a ]]> b</p><script>alert(1)</script>',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		$add_query = static function ( string $url ): string {
			return add_query_arg( array( 'a' => '1', 'b' => '2' ), $url );
		};
		add_filter( 'post_type_link', $add_query );
		set_query_var( 'category', $slug );
		set_query_var( 'limit', '5' );
		// PHPUnit's output is already out, so the template's header() call
		// only warns; that one warning is expected here.
		set_error_handler(
			static function ( int $errno, string $message ): bool {
				return str_starts_with( $message, 'Cannot modify header information' );
			},
			E_WARNING
		);
		ob_start();
		try {
			include SMAILY_CONNECT_PLUGIN_PATH . 'public/template/smaily-rss-feed.php';
		} finally {
			$xml = (string) ob_get_clean();
			restore_error_handler();
			remove_filter( 'post_type_link', $add_query );
			set_query_var( 'category', '' );
			set_query_var( 'limit', '' );
		}

		$previous = libxml_use_internal_errors( true );
		$feed     = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NOCDATA );
		libxml_use_internal_errors( $previous );
		self::assertInstanceOf( \SimpleXMLElement::class, $feed, "The feed is not well-formed XML:\n{$xml}" );
		self::assertCount( 1, $feed->channel->item );
		$item = $feed->channel->item[0];

		self::assertSame( 'Pojeng "Alexander Fleming" & Õie ümbris', (string) $item->title );
		$description = (string) $item->description;
		self::assertStringStartsWith(
			"<strong> Üldinfo</strong> <ul><li>Õitseb juunis&nbsp;ja juulis</li><li><span class=\"Y2IQFc\" lang=\"et\">Sort 'Cora Louise'</span></li></ul><p>Märkus: a ]]",
			$description
		);
		self::assertStringNotContainsString( '&lt;', $description );
		self::assertStringNotContainsString( '<script', $description );
		self::assertStringEndsWith( '?a=1&b=2', (string) $item->link );
		self::assertSame( (string) $item->link, (string) $item->guid );

		$built = (string) $feed->channel->lastBuildDate;
		self::assertMatchesRegularExpression(
			'/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/',
			$built
		);
		self::assertInstanceOf( \DateTimeImmutable::class, \DateTimeImmutable::createFromFormat( DATE_RSS, $built ) );
		self::assertInstanceOf( \DateTimeImmutable::class, \DateTimeImmutable::createFromFormat( DATE_RSS, (string) $item->pubDate ) );
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
