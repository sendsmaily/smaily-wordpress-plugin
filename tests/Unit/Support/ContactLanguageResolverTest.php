<?php
/**
 * Tests for ContactLanguageResolver — the single source of truth for the
 * Smaily `language` code we send for a contact / order.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Support\ContactLanguageResolver;

/**
 * Prike incident (2026-06-30): the buggy upstream cron pushed the WP site
 * locale (`en`) as every contact's language. This resolver derives the
 * language from the same WPML sources the merchant's (correct) Make
 * automations read — `_user_preferred_language` user meta and `wpml_language`
 * order meta — defaulting to the multilingual plugin's configured default
 * (`et`), never get_locale(). These tests pin that priority order, the
 * normalisation, and the omit-on-empty behaviour.
 */
final class ContactLanguageResolverTest extends TestCase {

	/** @var array<string, string> get_user_meta fixtures keyed "<user_id>:<meta_key>". */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DetectorFactory::reset();

		$fixtures =& $this->user_meta;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$fixtures ) {
				return $fixtures[ $user_id . ':' . $key ] ?? '';
			}
		);

		// Default site locale; individual cases override when they exercise
		// the site-locale fallback tier.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
		$this->user_meta = array();
	}

	// ---- for_user ---------------------------------------------------------

	public function test_for_user_prefers_preferred_language_meta(): void {
		$this->seed_meta( 42, '_user_preferred_language', 'en' );

		// Order language + default must NOT be consulted when the meta is set.
		$resolver = $this->resolver(
			$this->detector( 'et' ),
			static fn ( int $id ): string => 'ru'
		);

		self::assertSame( 'en', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_normalises_locale_shaped_meta(): void {
		$this->seed_meta( 42, '_user_preferred_language', 'en_US' );

		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'en', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_falls_back_to_latest_order_language(): void {
		// No preferred-language meta → most-recent order's wpml_language.
		$resolver = $this->resolver(
			$this->detector( 'et' ),
			static fn ( int $id ): string => $id === 42 ? 'en_GB' : ''
		);

		self::assertSame( 'en', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_falls_back_to_detector_default(): void {
		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'et', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_default_is_normalised(): void {
		$resolver = $this->resolver( $this->detector( 'et_EE' ), static fn ( int $id ): string => '' );

		self::assertSame( 'et', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_falls_back_to_site_locale_when_no_default(): void {
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );

		$resolver = $this->resolver( $this->detector( '' ), static fn ( int $id ): string => '' );

		self::assertSame( 'et', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_returns_empty_when_nothing_resolves(): void {
		Functions\when( 'get_locale' )->justReturn( '' );

		$resolver = $this->resolver( $this->detector( '' ), static fn ( int $id ): string => '' );

		// Caller (HookHandler) omits the field on '' so Smaily keeps the
		// contact's existing language instead of wiping it.
		self::assertSame( '', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_honours_custom_meta_key_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( $hook === ContactLanguageResolver::FILTER_USER_PREF_META ) {
					return 'my_lang_key';
				}
				return $value;
			}
		);
		$this->seed_meta( 42, 'my_lang_key', 'ru' );

		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'ru', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_filter_can_override_resolved_language(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( $hook === ContactLanguageResolver::FILTER_LANGUAGE ) {
					return 'xx';
				}
				return $value;
			}
		);

		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'xx', $resolver->for_user( $this->user( 42 ) ) );
	}

	// ---- allowlist clamp --------------------------------------------------

	public function test_for_user_clamps_out_of_set_language_to_default(): void {
		// A stray `ru` preferred-language meta on an et/en-only store must NOT
		// produce a `ru` contact — it clamps to the default (Erkki's RU-list
		// concern).
		$this->seed_meta( 42, '_user_preferred_language', 'ru' );

		$resolver = $this->resolver(
			$this->detector( 'et', array( 'et', 'en' ) ),
			static fn ( int $id ): string => ''
		);

		self::assertSame( 'et', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_user_keeps_in_set_language(): void {
		$this->seed_meta( 42, '_user_preferred_language', 'en' );

		$resolver = $this->resolver(
			$this->detector( 'et', array( 'et', 'en' ) ),
			static fn ( int $id ): string => ''
		);

		self::assertSame( 'en', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_no_clamp_when_active_set_unknown(): void {
		// Detector can't enumerate languages → no allowlist → trust resolution
		// rather than clamp against an unknown set.
		$this->seed_meta( 42, '_user_preferred_language', 'ru' );

		$resolver = $this->resolver(
			$this->detector( 'et', array() ),
			static fn ( int $id ): string => ''
		);

		self::assertSame( 'ru', $resolver->for_user( $this->user( 42 ) ) );
	}

	public function test_for_order_clamps_out_of_set_wpml_language(): void {
		$resolver = $this->resolver(
			$this->detector( 'et', array( 'et', 'en' ) ),
			static fn ( int $id ): string => ''
		);

		self::assertSame( 'et', $resolver->for_order( $this->order( 'ru', 9 ) ) );
	}

	// ---- for_order --------------------------------------------------------

	public function test_for_order_uses_order_wpml_language(): void {
		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'en', $resolver->for_order( $this->order( 'en', 9 ) ) );
	}

	public function test_for_order_normalises_wpml_language(): void {
		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'en', $resolver->for_order( $this->order( 'EN', 9 ) ) );
	}

	public function test_for_order_falls_back_to_customer_preferred_language(): void {
		$this->seed_meta( 9, '_user_preferred_language', 'ru' );

		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'ru', $resolver->for_order( $this->order( '', 9 ) ) );
	}

	public function test_for_order_guest_falls_back_to_default(): void {
		// Guest order (customer_id 0): no per-user meta to read → default.
		$resolver = $this->resolver( $this->detector( 'et' ), static fn ( int $id ): string => '' );

		self::assertSame( 'et', $resolver->for_order( $this->order( '', 0 ) ) );
	}

	// ---- helpers ----------------------------------------------------------

	private function resolver( DetectorInterface $detector, callable $order_language_provider ): ContactLanguageResolver {
		return new ContactLanguageResolver( $detector, $order_language_provider );
	}

	/**
	 * @param array<int, string> $detected Active site languages (empty = detector can't enumerate).
	 */
	private function detector( string $default_language, array $detected = array() ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( $default_language );
		$detector->method( 'get_detected_languages' )->willReturn( $detected );
		return $detector;
	}

	private function seed_meta( int $user_id, string $key, string $value ): void {
		$this->user_meta[ $user_id . ':' . $key ] = $value;
	}

	private function user( int $id ): \WP_User {
		return new class( $id ) extends \WP_User {
			public function __construct( int $id ) {
				$this->ID = $id;
			}
		};
	}

	private function order( string $wpml_language, int $customer_id ): \WC_Order {
		return new class( $wpml_language, $customer_id ) extends \WC_Order {
			private string $wpml_language;
			private int $customer_id;

			public function __construct( string $wpml_language, int $customer_id ) {
				$this->wpml_language = $wpml_language;
				$this->customer_id   = $customer_id;
			}

			public function get_customer_id( $context = 'view' ): int {
				return $this->customer_id;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $key === 'wpml_language' ? $this->wpml_language : '';
			}
		};
	}
}

// Minimal shims for the WP_User / WC_Order classes the anonymous fakes extend
// (Brain Monkey ships neither). Guarded so they coexist with the identical
// blocks in other unit-test files regardless of load order.
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
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
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
class WC_Order {
	public function get_id(): int { return 0; }
	public function get_billing_email( $context = 'view' ): string { return ''; }
	public function get_billing_first_name( $context = 'view' ): string { return ''; }
	public function get_billing_last_name( $context = 'view' ): string { return ''; }
	public function get_customer_id( $context = 'view' ): int { return 0; }
	public function get_total( $context = 'view' ): string { return '0'; }
	public function get_currency( $context = 'view' ): string { return ''; }
	public function get_status( $context = 'view' ): string { return ''; }
	public function get_total_discount( $ex_tax = true ): string { return '0'; }
	public function get_date_created( $context = 'view' ) { return null; }
	public function get_items( $types = 'line_item' ): array { return array(); }
	public function update_meta_data( $key, $value, $unique_id = 0 ): void {}
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function save() { return 0; }
}
PHP
	);
}
