<?php
/**
 * Tests for the Wizard\EnvDetector.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Wizard;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Wizard\EnvDetector;

final class EnvDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DetectorFactory::reset();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $key ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' )
		);
		// Brain Monkey persists eval'd functions across tests — define
		// no-op defaults for every external function snapshot() touches
		// so test order doesn't matter. Individual tests override only
		// the fields they care about.
		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 0 ) );
		Functions\when( 'wp_count_posts' )->justReturn(
			(object) array(
				'publish' => 0,
				'draft'   => 0,
			)
		);
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );

		\Smaily_Connect\Includes\Cypher::$decrypt_return = '';
	}

	protected function tearDown(): void {
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_snapshot_returns_site_locale_fallback_when_no_multilingual_plugin_present(): void {
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame( array( 'et_EE' ), $snapshot['detectedLanguages'] );
		self::assertNull( $snapshot['multilingualPlugin'] );
	}

	// Polylang / WPML detection is covered exhaustively by
	// DetectorFactoryTest. We do NOT mock pll_languages_list here —
	// Brain Monkey can't fully unset an eval'd function between tests,
	// so a single Polylang test would leak DetectorFactory state into
	// the rest of the suite. EnvDetector's job is to surface what the
	// factory returns; that delegation is what the snapshot tests cover.

	public function test_snapshot_reports_wc_inactive_when_woocommerce_class_missing(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertFalse( $snapshot['wcActive'] );
		self::assertFalse( $snapshot['hposActive'] );
		// Order count must be 0 when wc_get_orders is undefined — not a fatal error.
		self::assertSame( 0, $snapshot['storeTotals']['orders'] );
		// No WC → no RSS feed → React hides the builder section.
		self::assertNull( $snapshot['rss'] );
	}

	/**
	 * WC-active positive path. We can't define a global `WooCommerce`
	 * class (it would leak into the wc-inactive test above — one PHPUnit
	 * process), so an anonymous subclass forces the branch instead; this
	 * is why EnvDetector::wc_active() is protected. The legacy Rss +
	 * Options classes are real (require'd below), so the test exercises
	 * the actual make_rss_feed_url() URL shape, not a mock of it.
	 */
	public function test_snapshot_rss_block_carries_base_url_categories_and_legacy_prefill(): void {
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-options.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/rss.class.php';

		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.test/smaily-rss-feed' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $args === array() ? $url : $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'get_terms' )->justReturn(
			array(
				(object) array(
					'slug' => 'hoodies',
					'name' => 'Hoodies',
				),
				(object) array(
					'slug' => 'tshirts',
					'name' => 'T-shirts',
				),
			)
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				// The pilot's previously-saved legacy RSS options must
				// surface as prefill.
				if ( $key === \Smaily_Connect\Includes\Options::RSS_LIMIT_OPTION ) {
					return '25';
				}
				if ( $key === \Smaily_Connect\Includes\Options::RSS_CATEGORY_OPTION ) {
					return 'hoodies';
				}
				return $default;
			}
		);

		global $wp_rewrite;
		$wp_rewrite = new class() {
			public function using_permalinks(): bool {
				return true;
			}
		};

		$detector = new class() extends EnvDetector {
			protected function wc_active(): bool {
				return true;
			}
		};

		$snapshot = $detector->snapshot();
		$rss      = $snapshot['rss'];

		self::assertNotNull( $rss );
		self::assertSame( 'https://shop.test/smaily-rss-feed', $rss['baseUrl'] );
		self::assertSame(
			array(
				array(
					'slug' => 'hoodies',
					'name' => 'Hoodies',
				),
				array(
					'slug' => 'tshirts',
					'name' => 'T-shirts',
				),
			),
			$rss['categories']
		);
		self::assertSame( 25, $rss['defaults']['limit'] );
		self::assertSame( 'hoodies', $rss['defaults']['category'] );
		// Unsaved fields fall back to the legacy defaults.
		self::assertSame( 'modified', $rss['defaults']['sortBy'] );
		self::assertSame( 'DESC', $rss['defaults']['order'] );
		// No wc_tax_enabled() in this env → tax-rate prefill bottoms out at 0.
		self::assertSame( 0.0, $rss['defaults']['taxRate'] );
	}

	/**
	 * Non-permalink installs get the ?smaily-rss-feed=true base form —
	 * the React builder must append its params to an URL that already
	 * has a query string, so the server must emit the honest base.
	 */
	public function test_snapshot_rss_base_url_uses_query_form_without_permalinks(): void {
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'includes/smaily-options.class.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/rss.class.php';

		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.test' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $args === array() ? $url : $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'get_terms' )->justReturn( array() );

		global $wp_rewrite;
		$wp_rewrite = new class() {
			public function using_permalinks(): bool {
				return false;
			}
		};

		$detector = new class() extends EnvDetector {
			protected function wc_active(): bool {
				return true;
			}
		};

		$rss = $detector->snapshot()['rss'];

		self::assertNotNull( $rss );
		self::assertSame( 'https://shop.test?smaily-rss-feed=true', $rss['baseUrl'] );
	}

	public function test_snapshot_counts_users_and_products(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'count_users' )->justReturn( array( 'total_users' => 42 ) );
		Functions\when( 'wp_count_posts' )->justReturn(
			(object) array(
				'publish' => 7,
				'draft'   => 3,
			)
		);

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame( 42, $snapshot['storeTotals']['customers'] );
		self::assertSame( 10, $snapshot['storeTotals']['products'] );
	}

	public function test_snapshot_detects_elementor_via_constant(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0-test' );
		}
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertTrue( $snapshot['elementorPresent'] );
	}

	public function test_snapshot_carries_docs_url_from_constants(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame( \Smaily\Connect\Constants::DOCS_URL, $snapshot['docsUrl'] );
	}

	public function test_saved_settings_omits_password_field(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'plain-pw';

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				if ( $key === \Smaily\Connect\Settings\Credentials::LEGACY_OPTION_KEY ) {
					return array(
						'subdomain' => 'demo',
						'username'  => 'alice',
						'password'  => 'enc-blob',
					);
				}
				return $default;
			}
		);

		$saved = ( new EnvDetector() )->saved_settings();

		self::assertSame( 'demo', $saved['smailyCredentials']['subdomain'] );
		self::assertSame( 'alice', $saved['smailyCredentials']['username'] );
		// Password is BLANK in the boot payload — UI re-enters it manually
		// if changed; no-op save leaves the stored value untouched.
		self::assertSame( '', $saved['smailyCredentials']['password'] );
		// PRO-2286: the wizard is told a usable password EXISTS — never its value.
		self::assertTrue( $saved['smailyHasStoredPassword'] );
	}

	public function test_saved_settings_reports_no_stored_password_on_a_fresh_install(): void {
		$saved = ( new EnvDetector() )->saved_settings();

		self::assertFalse( $saved['smailyHasStoredPassword'] );
	}

	public function test_saved_settings_carries_default_toggle_values_when_options_absent(): void {
		$saved = ( new EnvDetector() )->saved_settings();

		// Sub-PR 2.H.5: multilingualMode default is empty string when
		// the option was never written — hydrate.ts uses the env to
		// decide Mode B vs 'single'. We do NOT default to 'single' here
		// any more.
		self::assertSame( '', $saved['multilingualMode'] );
		self::assertSame( 'default', $saved['defaultFallbackAccountKey'] );
		self::assertTrue( $saved['subscriberSyncEnabled'] );
		self::assertSame( 30, $saved['abandonedCartCutoffMinutes'] );
		self::assertFalse( $saved['welcomeEnabled'] );
	}

	public function test_snapshot_carries_order_statuses_bare_slug(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-completed' => 'Completed',
				'wc-shipped'   => 'Shipped', // custom status
			)
		);

		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame(
			array(
				array(
					'slug' => 'completed',
					'name' => 'Completed',
				),
				array(
					'slug' => 'shipped',
					'name' => 'Shipped',
				),
			),
			$snapshot['orderStatuses']
		);
	}

	public function test_snapshot_order_statuses_empty_when_wc_inactive(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		// wc_get_order_statuses is defined by setUp() as an alias, so
		// function_exists() is true here regardless of WC-active state in
		// this suite — the empty-list default (setUp's justReturn( array() ))
		// covers the "no statuses registered" branch.
		$snapshot = ( new EnvDetector() )->snapshot();

		self::assertSame( array(), $snapshot['orderStatuses'] );
	}

	public function test_saved_settings_carries_transactional_defaults_when_options_absent(): void {
		$saved = ( new EnvDetector() )->saved_settings();

		self::assertFalse( $saved['transactionalEmailsEnabled'] );
		self::assertSame( '', $saved['transactionalCredentials']['subdomain'] );
		self::assertSame( '', $saved['transactionalCredentials']['username'] );
		self::assertSame( '', $saved['transactionalCredentials']['password'] );
		self::assertFalse( $saved['transactionalConnected'] );
		self::assertFalse( $saved['orderConfirmationEnabled'] );
		self::assertFalse( $saved['shippingConfirmationEnabled'] );
		self::assertSame( array( 'completed' ), $saved['shippedOrderStatuses'] );
	}

	public function test_saved_settings_reads_transactional_account_credentials_without_password(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'trx-plain-pw';

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				if ( $key === \Smaily\Connect\Settings\Credentials::PHASE2_OPTION_PREFIX . 'transactional' ) {
					return array(
						'subdomain' => 'trxdemo',
						'username'  => 'bob',
						'password'  => 'enc-blob',
					);
				}
				return $default;
			}
		);

		$saved = ( new EnvDetector() )->saved_settings();

		self::assertSame( 'trxdemo', $saved['transactionalCredentials']['subdomain'] );
		self::assertSame( 'bob', $saved['transactionalCredentials']['username'] );
		self::assertSame( '', $saved['transactionalCredentials']['password'] );
	}
}
