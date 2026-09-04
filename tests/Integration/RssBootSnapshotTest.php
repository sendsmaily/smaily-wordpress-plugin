<?php
/**
 * Integration: EnvDetector's rss boot-payload block against real WP + WC.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Wizard\EnvDetector;

/**
 * What this catches:
 *
 *   The RSS section of the Integrations step is fed entirely from
 *   EnvDetector::rss_snapshot(). Its happy path depends on the LEGACY
 *   class tree (Smaily_Connect\Integrations\WooCommerce\Rss +
 *   Smaily_Connect\Includes\Options) being loaded by the legacy
 *   require chain whenever WC is active — a seam the unit suite can
 *   only fake (it require's the files by hand). If a future legacy
 *   cleanup drops either file from the loader, the unit tests stay
 *   green while the real boot payload silently degrades to rss=null
 *   and the RSS builder vanishes from the UI. This test pins the
 *   real-environment behaviour: WC active in wp-env → rss block
 *   present, base URL resolving to the live feed route.
 */
final class RssBootSnapshotTest extends TestCase {

	public function test_rss_block_present_and_shaped_when_wc_active(): void {
		self::assertTrue( class_exists( '\WooCommerce' ), 'wp-env must have WooCommerce active.' );

		$snapshot = ( new EnvDetector() )->snapshot();
		$rss      = $snapshot['rss'];

		self::assertIsArray( $rss, 'rss block must be present when WC is active — legacy Rss/Options classes not loaded?' );

		// Base URL must point at the feed route in one of its two legal
		// forms (pretty permalink path or query-var fallback).
		self::assertMatchesRegularExpression(
			'#(/smaily-rss-feed|[?&]smaily-rss-feed=true)#',
			$rss['baseUrl']
		);

		// Categories: list of {slug, name} string pairs (may be empty on
		// a bare store — shape is what matters).
		self::assertIsArray( $rss['categories'] );
		foreach ( $rss['categories'] as $category ) {
			self::assertIsString( $category['slug'] );
			self::assertIsString( $category['name'] );
		}

		// Prefill defaults mirror the legacy tab's fallback chain.
		self::assertIsInt( $rss['defaults']['limit'] );
		self::assertIsString( $rss['defaults']['category'] );
		self::assertContains( $rss['defaults']['sortBy'], array( 'modified', 'date', 'id', 'name', 'type' ) );
		self::assertContains( $rss['defaults']['order'], array( 'ASC', 'DESC' ) );
		self::assertIsFloat( $rss['defaults']['taxRate'] );
	}

	public function test_saved_legacy_options_surface_as_prefill(): void {
		update_option( 'smaily_connect_rss_limit', '25' );
		update_option( 'smaily_connect_rss_category', 'integration-test-cat' );

		try {
			$rss = ( new EnvDetector() )->snapshot()['rss'];

			self::assertIsArray( $rss );
			self::assertSame( 25, $rss['defaults']['limit'] );
			self::assertSame( 'integration-test-cat', $rss['defaults']['category'] );
		} finally {
			delete_option( 'smaily_connect_rss_limit' );
			delete_option( 'smaily_connect_rss_category' );
		}
	}
}
