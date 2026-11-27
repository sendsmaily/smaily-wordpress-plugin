<?php
/**
 * Tests for the Polylang for WooCommerce order language in
 * ContactLanguageResolver (CON-457).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Support\ContactLanguageResolver;

/**
 * Polylang for WooCommerce keeps an order's language behind
 * `PLLWC_Data_Store::load( 'order_language' )->get_language( $order_id )`
 * (its vendor stubs). The double below has that shape. It is a global
 * class, so every case that declares it runs in its own process: the rest
 * of the unit suite keeps running without the add-on.
 */
final class ContactLanguageResolverPolylangWcTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_latvian_order_gives_lv(): void {
		self::declare_polylang_wc();
		\PLLWC_Data_Store::$languages = array( 9 => 'lv' );
		$order = $this->order( 9 );
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

		$resolver = new ContactLanguageResolver( $this->detector() );

		self::assertSame( 'lv', $resolver->for_order( $order ) );
		self::assertSame( 'lv', $resolver->for_user( $this->user( 5 ) ), 'The latest order of the customer.' );
		self::assertSame( array( 'order_language' ), array_unique( \PLLWC_Data_Store::$loaded ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_without_a_language_leaves_the_next_source(): void {
		self::declare_polylang_wc();
		$order = $this->order( 9 );
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

		$resolver = new ContactLanguageResolver( $this->detector() );

		self::assertSame( 'et', $resolver->for_order( $order ), 'get_language() answers false: the store default.' );
		self::assertSame( 'et', $resolver->for_user( $this->user( 5 ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_add_on_that_throws_leaves_the_next_source_and_logs_no_message(): void {
		self::declare_polylang_wc();
		\PLLWC_Data_Store::$throws = true;
		define( 'WP_DEBUG', true );
		$log = (string) tempnam( sys_get_temp_dir(), 'smly' );
		$old = ini_set( 'error_log', $log );

		try {
			$language = ( new ContactLanguageResolver( $this->detector() ) )->for_order( $this->order( 9 ) );
		} finally {
			ini_set( 'error_log', (string) $old );
		}
		$written = (string) file_get_contents( $log );
		unlink( $log );

		self::assertSame( 'et', $language );
		self::assertStringContainsString( 'RuntimeException at ', $written );
		self::assertStringNotContainsString( 'buyer@example.test', $written, 'Class and file:line only.' );
	}

	public function test_without_the_add_on_the_next_source_applies_as_before(): void {
		self::assertFalse( class_exists( 'PLLWC_Data_Store', false ), 'Free Polylang only.' );

		$resolver = new ContactLanguageResolver( $this->detector() );

		self::assertSame( 'et', $resolver->for_order( $this->order( 9 ) ) );
	}

	/**
	 * Shaped like Polylang for WooCommerce's public API (vendor stubs):
	 * a static load() and get_language( $id ) answering a slug or false.
	 */
	private static function declare_polylang_wc(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test double of a third-party global class, declared in its own process.
		eval(
			<<<'PHP'
class PLLWC_Data_Store {
	public static array $languages = array();
	public static bool $throws = false;
	public static array $loaded = array();
	public static function load( $object_type ) {
		self::$loaded[] = $object_type;
		return new class {
			public function get_language( $id, $field = 'slug' ) {
				if ( \PLLWC_Data_Store::$throws ) {
					throw new \RuntimeException( 'No language for buyer@example.test' );
				}
				return \PLLWC_Data_Store::$languages[ $id ] ?? false;
			}
		};
	}
}
PHP
		);
	}

	private function detector(): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( 'et' );
		$detector->method( 'get_detected_languages' )->willReturn( array( 'et', 'lv' ) );
		return $detector;
	}

	private function user( int $id ): \WP_User {
		return new class( $id ) extends \WP_User {
			public function __construct( int $id ) {
				$this->ID = $id;
			}
		};
	}

	private function order( int $id ): \WC_Order {
		return new class( $id ) extends \WC_Order {
			private int $order_id;

			public function __construct( int $order_id ) {
				$this->order_id = $order_id;
			}

			public function get_id(): int {
				return $this->order_id;
			}

			public function get_customer_id( $context = 'view' ): int {
				return 5;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return '';
			}
		};
	}
}

// The WP_User / WC_Order shims the fakes extend, guarded like the identical
// blocks in other unit-test files (a separate process loads only this file).
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test shim.
	eval(
		<<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}

if ( ! class_exists( \WC_Order::class ) ) {
	// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test shim.
	eval(
		<<<'PHP'
class WC_Order {
	public function get_id(): int { return 0; }
	public function get_customer_id( $context = 'view' ): int { return 0; }
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
}
PHP
	);
}
