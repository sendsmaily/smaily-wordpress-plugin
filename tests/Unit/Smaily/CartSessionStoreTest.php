<?php
/**
 * CartSessionStore tests — the `cart_content` shapes and the storefront
 * language a cart change records (PRO-4253).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\CartSessionStore;

final class CartSessionStoreTest extends TestCase {

	private const ITEMS = array(
		array(
			'product_id'   => 11,
			'variation_id' => 0,
			'quantity'     => 2,
		),
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-11-13 10:00:00' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- encode / decode -----------------------------------------------------

	public function test_a_cart_with_no_tick_and_no_language_keeps_the_plain_list(): void {
		self::assertSame( (string) json_encode( self::ITEMS ), CartSessionStore::encode_content( self::ITEMS, false, '' ) );
	}

	public function test_a_recorded_language_is_written_into_the_content_and_read_back(): void {
		$json = (string) CartSessionStore::encode_content( self::ITEMS, false, 'lt' );

		self::assertSame( '{"items":[{"product_id":11,"variation_id":0,"quantity":2}],"language":"lt"}', $json );
		self::assertSame(
			array(
				'items'            => self::ITEMS,
				'newsletter_optin' => false,
				'language'         => 'lt',
			),
			CartSessionStore::decode_content( $json )
		);
	}

	public function test_a_ticked_cart_with_a_language_carries_both(): void {
		$decoded = CartSessionStore::decode_content( (string) CartSessionStore::encode_content( self::ITEMS, true, 'lt' ) );

		self::assertNotNull( $decoded );
		self::assertTrue( $decoded['newsletter_optin'] );
		self::assertSame( 'lt', $decoded['language'] );
		self::assertSame( self::ITEMS, $decoded['items'] );
	}

	public function test_a_plain_list_saved_before_the_change_reads_with_no_language(): void {
		self::assertSame(
			array(
				'items'            => self::ITEMS,
				'newsletter_optin' => false,
				'language'         => '',
			),
			CartSessionStore::decode_content( '[{"product_id":11,"variation_id":0,"quantity":2}]' )
		);
	}

	public function test_a_ticked_cart_saved_before_the_change_reads_with_no_language(): void {
		self::assertSame(
			array(
				'items'            => self::ITEMS,
				'newsletter_optin' => true,
				'language'         => '',
			),
			CartSessionStore::decode_content( '{"items":[{"product_id":11,"variation_id":0,"quantity":2}],"newsletter_optin":true}' )
		);
	}

	public function test_a_language_that_is_not_a_string_reads_as_none(): void {
		$decoded = CartSessionStore::decode_content( '{"items":[],"language":["lt"]}' );

		self::assertNotNull( $decoded );
		self::assertSame( '', $decoded['language'] );
	}

	// --- upsert --------------------------------------------------------------

	public function test_a_new_cart_stores_the_language_it_was_made_on(): void {
		$wpdb = $this->fake_wpdb( null );

		( new CartSessionStore() )->upsert( 'tok', 0, '', '', '', self::ITEMS, null, 'lt' );

		self::assertSame( 'lt', $this->stored_language( $wpdb->inserts[0]['cart_content'] ) );
	}

	public function test_a_change_on_another_storefront_replaces_the_recorded_language(): void {
		$wpdb = $this->fake_wpdb( (string) CartSessionStore::encode_content( self::ITEMS, false, 'lt' ) );

		( new CartSessionStore() )->upsert( 'tok', 0, '', '', '', self::ITEMS, null, 'et' );

		self::assertSame( 'et', $this->stored_language( $wpdb->updates[0]['cart_content'] ), 'The language of the last change wins.' );
	}

	public function test_a_change_where_no_language_is_known_keeps_the_recorded_one(): void {
		$wpdb = $this->fake_wpdb( (string) CartSessionStore::encode_content( self::ITEMS, true, 'lt' ) );

		( new CartSessionStore() )->upsert( 'tok', 0, '', '', '', self::ITEMS );

		$decoded = CartSessionStore::decode_content( $wpdb->updates[0]['cart_content'] );
		self::assertNotNull( $decoded );
		self::assertSame( 'lt', $decoded['language'] );
		self::assertTrue( $decoded['newsletter_optin'], 'The tick is kept as before (CON-196).' );
	}

	public function test_the_newsletter_tick_keeps_the_recorded_language(): void {
		$wpdb = $this->fake_wpdb( (string) CartSessionStore::encode_content( self::ITEMS, false, 'lt' ) );

		( new CartSessionStore() )->set_newsletter_optin( 'tok', true );

		$decoded = CartSessionStore::decode_content( $wpdb->updates[0]['cart_content'] );
		self::assertNotNull( $decoded );
		self::assertTrue( $decoded['newsletter_optin'] );
		self::assertSame( 'lt', $decoded['language'] );
	}

	// --- helpers -------------------------------------------------------------

	private function stored_language( string $json ): string {
		$decoded = CartSessionStore::decode_content( $json );
		self::assertNotNull( $decoded );

		return $decoded['language'];
	}

	/**
	 * A $wpdb holding at most one tracker row, with this `cart_content`.
	 */
	private function fake_wpdb( ?string $existing_content ): object {
		$wpdb = new class( $existing_content ) {
			public string $prefix = 'wp_';

			/** @var array<int, array<string, mixed>> */
			public array $inserts = array();

			/** @var array<int, array<string, mixed>> */
			public array $updates = array();

			private ?string $content;

			public function __construct( ?string $content ) {
				$this->content = $content;
			}

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $sql, string $output = 'OBJECT' ): ?array {
				return $this->content === null ? null : array(
					'id'           => '7',
					'cart_content' => $this->content,
				);
			}

			public function get_var( string $sql ): ?string {
				return $this->content;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<int, string>   $formats
			 */
			public function insert( string $table, array $data, array $formats = array() ): int {
				$this->inserts[] = $data;
				return 1;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 * @param array<int, string>   $formats
			 * @param array<int, string>   $where_formats
			 */
			public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int {
				$this->updates[] = $data;
				return 1;
			}
		};

		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}
}
