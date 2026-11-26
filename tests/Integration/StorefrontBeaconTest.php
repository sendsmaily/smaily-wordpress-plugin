<?php
/**
 * Integration: StorefrontBeacon (3.4.3a) — the storefront enqueue gate and the
 * boot blob it hands the beacon JS.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\StorefrontBeacon;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that a unit test can't: the gate reads real wp_options
 * (connected flag + the track-browsing toggle) and the config blob is built
 * from the real stored setup-exchange config — so it asserts the same data the
 * storefront would actually receive.
 */
final class StorefrontBeaconTest extends TestCase {

	private const TRACK_OPTION = 'smly_plus_rec_track_browsing';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		if ( ! function_exists( 'is_woocommerce' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the storefront beacon gates on it.' );
		}
		update_option( self::TRACK_OPTION, false );
	}

	protected function tearDown(): void {
		update_option( self::TRACK_OPTION, false );
		wp_dequeue_script( StorefrontBeacon::HANDLE );
		wp_dequeue_script( StorefrontBeacon::HANDLE_LANDING );
		wp_deregister_script( StorefrontBeacon::HANDLE );
		wp_deregister_script( StorefrontBeacon::HANDLE_LANDING );
		parent::tearDown();
	}

	private function beacon(): StorefrontBeacon {
		return new StorefrontBeacon( new RecEngineSettings() );
	}

	public function test_disabled_when_not_connected(): void {
		update_option( self::TRACK_OPTION, true );
		self::assertFalse( $this->beacon()->is_enabled(), 'No connected engine ⇒ no beacon.' );
	}

	public function test_disabled_when_tracking_off(): void {
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, false );
		self::assertFalse( $this->beacon()->is_enabled(), 'Browse-tracking off ⇒ no beacon.' );
	}

	public function test_enabled_when_connected_and_tracking_on(): void {
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, true );
		self::assertTrue( $this->beacon()->is_enabled() );
	}

	public function test_beacon_config_carries_beacon_url_and_cookie_names(): void {
		EnvSeed::connect();
		$config = $this->beacon()->beacon_config();

		self::assertStringContainsString( '/smaily-connect/v1/relay', (string) $config['beaconUrl'] );
		self::assertStringNotContainsString( 'beacon', (string) $config['beaconUrl'], 'The proxy URL must not carry "beacon" (ad-block filter lists, F3-41).' );
		// Names come from the seeded engine config (EnvSeed::fixture_config).
		self::assertSame( 'smaily_anon_sid', $config['cookieNames']['session'] );
		self::assertSame( 'smaily_rec_uid', $config['cookieNames']['visitor'] );
		self::assertSame( 'smaily_vt', $config['urlParams']['visitorToken'] );
		self::assertSame( 30, $config['cookieTtlDays']['recId'] );
		self::assertSame( 365, $config['cookieTtlDays']['visitor'] );
		// The runtime posts a landing to the store route too (CON-250).
		self::assertStringEndsWith( '/smaily-connect/v1/land', (string) $config['landUrl'] );
	}

	public function test_beacon_config_falls_back_to_defaults_without_engine_config(): void {
		// Connected but with an empty config map → §6 defaults.
		EnvSeed::connect( array( 'config' => array() ) );
		$config = $this->beacon()->beacon_config();

		self::assertSame( 'smaily_anon_sid', $config['cookieNames']['session'] );
		self::assertSame( 'smaily_rec_id', $config['cookieNames']['recId'] );
		self::assertSame( 30, $config['sessionTtlDays'] );
	}

	/**
	 * PRO-1767: a connected store with browse tracking OFF still needs a
	 * browser-side attribution writer — its landing pages can be served from a
	 * full-page cache, where LandingCapture (PHP) never runs at all.
	 */
	public function test_enqueues_only_the_attribution_writer_when_tracking_is_off(): void {
		$this->require_built_bundles();
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, false );

		$beacon = $this->beacon();
		self::assertTrue( $beacon->is_attribution_only_enabled() );
		$beacon->enqueue();

		self::assertTrue( wp_script_is( StorefrontBeacon::HANDLE_LANDING, 'enqueued' ), 'The attribution writer must load with browse tracking off.' );
		self::assertFalse( wp_script_is( StorefrontBeacon::HANDLE, 'enqueued' ), 'The browse runtime stays behind its own toggle.' );

		$boot = (string) wp_scripts()->get_data( StorefrontBeacon::HANDLE_LANDING, 'before' )[1];
		self::assertStringContainsString( 'window.smailyConnectLanding', $boot );
		self::assertStringContainsString( 'smaily_rec_id', $boot, 'The writer needs the cookie names.' );
		self::assertStringNotContainsString( 'relay', $boot, 'No proxy URL — this bundle sends nothing to the engine.' );
		self::assertStringContainsString( 'smaily-connect\\/v1\\/land', $boot, 'The store route that sets the cookies (CON-250).' );
	}

	public function test_enqueues_only_the_runtime_when_tracking_is_on(): void {
		$this->require_built_bundles();
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, true );

		$beacon = $this->beacon();
		self::assertFalse( $beacon->is_attribution_only_enabled(), 'The runtime captures the same params — never both writers.' );
		$beacon->enqueue();

		self::assertTrue( wp_script_is( StorefrontBeacon::HANDLE, 'enqueued' ) );
		self::assertFalse( wp_script_is( StorefrontBeacon::HANDLE_LANDING, 'enqueued' ) );
	}

	public function test_enqueues_neither_when_not_connected(): void {
		$this->require_built_bundles();
		update_option( self::TRACK_OPTION, false );

		$beacon = $this->beacon();
		self::assertFalse( $beacon->is_attribution_only_enabled() );
		$beacon->enqueue();

		self::assertFalse( wp_script_is( StorefrontBeacon::HANDLE, 'enqueued' ) );
		self::assertFalse( wp_script_is( StorefrontBeacon::HANDLE_LANDING, 'enqueued' ) );
	}

	public function test_attribution_writer_answers_to_the_landing_capture_master_switch(): void {
		EnvSeed::connect();
		update_option( self::TRACK_OPTION, false );

		add_filter( 'smaily_connect_capture_attribution', '__return_false' );
		$enabled = $this->beacon()->is_attribution_only_enabled();
		remove_filter( 'smaily_connect_capture_attribution', '__return_false' );

		self::assertFalse( $enabled, 'A merchant who turned attribution capture off does not get a new writer.' );
	}

	public function test_attribution_config_carries_only_what_the_writer_needs(): void {
		EnvSeed::connect();
		$config = $this->beacon()->attribution_config();

		self::assertSame( array( 'cookieNames', 'urlParams', 'cookieTtlDays', 'landUrl' ), array_keys( $config ) );
		self::assertStringEndsWith( '/smaily-connect/v1/land', (string) $config['landUrl'] );
		self::assertSame( 'smaily_rec_id', $config['cookieNames']['recId'] );
		self::assertArrayNotHasKey( 'session', $config['cookieNames'], 'The session cookie belongs to the browse runtime.' );
		self::assertSame( 'smaily_rec', $config['urlParams']['recId'] );
		self::assertSame( 30, $config['cookieTtlDays']['recId'] );
	}

	private function require_built_bundles(): void {
		$dir = SMAILY_CONNECT_PLUGIN_PATH . 'dist/public/js/';
		if ( ! file_exists( $dir . 'sc-runtime.js' ) || ! file_exists( $dir . 'sc-landing.js' ) ) {
			self::markTestSkipped( 'Storefront bundles not built — run `npm run build:admin`.' );
		}
	}

	public function test_page_context_is_other_without_a_storefront_query(): void {
		EnvSeed::connect();
		$context = $this->beacon()->page_context();
		self::assertSame( 'other', $context['pageType'], 'No product/category/search query ⇒ no page-view event.' );
	}

	public function test_a_variable_product_view_keys_on_the_default_variation(): void {
		// CON-427: the catalog has a row per variation, none for the parent.
		list( $parent, $variations ) = $this->make_variable_product( array( 'S', 'M', 'L' ), 'M' );

		try {
			self::assertSame( 'woo-' . $variations['M'], $this->beacon()->product_context( $parent )['sku'] );
		} finally {
			$this->delete_variable_product( $parent );
		}
	}

	public function test_a_variable_product_view_without_a_default_keys_on_the_first_published_variation(): void {
		// S is disabled (private): the catalog still sends it, the page view
		// never names it.
		list( $parent, $variations ) = $this->make_variable_product( array( 'S', 'M', 'L' ), '' );
		wp_update_post(
			array(
				'ID'          => $variations['S'],
				'post_status' => 'private',
			)
		);

		try {
			self::assertSame( 'woo-' . $variations['M'], $this->beacon()->product_context( wc_get_product( $parent->get_id() ) )['sku'] );
		} finally {
			$this->delete_variable_product( $parent );
		}
	}

	/**
	 * A variable product with one variation per size, in that order.
	 *
	 * @param string[] $sizes
	 * @return array{0: \WC_Product, 1: array<string, int>} The parent and the variation id per size.
	 */
	private function make_variable_product( array $sizes, string $default ): array {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( $sizes );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'CON-427 Page View' );
		$parent->set_attributes( array( $attribute ) );
		if ( $default !== '' ) {
			$parent->set_default_attributes( array( 'size' => $default ) );
		}
		$parent_id = (int) $parent->save();

		$variations = array();
		foreach ( $sizes as $size ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '9.00' );
			$variations[ $size ] = (int) $variation->save();
		}

		$loaded = wc_get_product( $parent_id );
		self::assertInstanceOf( \WC_Product::class, $loaded );
		return array( $loaded, $variations );
	}

	private function delete_variable_product( \WC_Product $parent ): void {
		foreach ( $parent->get_children() as $child_id ) {
			wp_delete_post( (int) $child_id, true );
		}
		wp_delete_post( $parent->get_id(), true );
	}
}
