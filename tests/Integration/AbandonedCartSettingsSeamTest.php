<?php
/**
 * Integration: the Settings-writer ↔ reader seam for the abandoned-cart
 * status option (F3-54, consumers now the PRO-1195 pipeline).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily\Connect\Wizard\EnvDetector;

/**
 * What F3-54 bug class this pins (Prike, 2026-07-08):
 *
 *   The new Settings/wizard wrote `smaily_connect_abandoned_cart_status`
 *   as a BARE BOOLEAN (stored by WP as '1'/''), while consumers read it
 *   as an ARRAY — `$status['enabled']` on the stored string is a PHP 8
 *   TypeError that fataled the abandoned-cart tick every 15 minutes, and
 *   toggling the feature off just wrote the other string. The old guard
 *   test never saw it because it seeded the option ITSELF in the array
 *   shape — the fixture's shape, not the real writer's shape.
 *
 *   The legacy email pass is retired (PRO-1195); the option's consumers
 *   are now the new pipeline's gates (CartAbandonmentSweeper /
 *   CartHookHandler / CartFlusher fallback) — ALL reading through the one
 *   normalized `Options::abandoned_cart_status()`. Pinned here, writer
 *   and reader in the same test:
 *   - the REAL SettingsEndpoint save produces the array shape on disk and
 *     the normalized read agrees;
 *   - a pre-3.4.3 corrupted value ('1'/'') neither fatals the new sweep
 *     tick nor mis-reads (EnvDetector hydrate included);
 *   - a Settings re-save PRESERVES the legacy autoresponder_id — it is
 *     the CartFlusher's no-mapping fallback (upgrade continuity).
 */
final class AbandonedCartSettingsSeamTest extends TestCase {

	private const STATUS_OPTION = 'smaily_connect_abandoned_cart_status';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
	}

	public function test_settings_save_writes_the_array_shape_and_the_normalized_read_agrees(): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array( 'abandonedCartEnabled' => true ),
			)
		);
		self::assertSame( 200, $response->get_status() );

		$stored = get_option( self::STATUS_OPTION );
		self::assertIsArray( $stored, 'The Settings writer must produce the array shape every consumer offsets into.' );
		self::assertTrue( $stored['enabled'] );
		self::assertArrayHasKey( 'autoresponder_id', $stored );

		self::assertSame(
			array(
				'enabled'          => true,
				'autoresponder_id' => 0,
			),
			\Smaily_Connect\Includes\Options::abandoned_cart_status(),
			'The one normalized read path must agree with the real writer\'s output.'
		);
	}

	public function test_pre_343_boolean_shapes_no_longer_fatal_the_tick(): void {
		// What the pre-3.4.3 Settings actually left behind: true → '1'.
		// The consumer is now the PRO-1195 sweep tick — reaching the asserts
		// proves the normalized read healed the corrupt shape (no PHP 8
		// string-offset fatal anywhere on the tick).
		update_option( self::STATUS_OPTION, true );
		update_option( 'smly_plus_setup_completed', true );
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame(
			array(
				'enabled'          => true,
				'autoresponder_id' => 0,
			),
			\Smaily_Connect\Includes\Options::abandoned_cart_status()
		);

		// The failed "turn it off in admin" path: false → ''.
		update_option( self::STATUS_OPTION, false );
		do_action( 'smly_plus_abandoned_cart' );
		self::assertFalse( \Smaily_Connect\Includes\Options::abandoned_cart_status()['enabled'] );

		// Hydrate pin: EnvDetector must read the normalized value too.
		self::assertFalse( ( new EnvDetector() )->saved_settings()['abandonedCartEnabled'] );
	}

	public function test_settings_resave_preserves_the_legacy_autoresponder_id_and_hydrate_reads_the_array(): void {
		update_option(
			self::STATUS_OPTION,
			array(
				'enabled'          => false,
				'autoresponder_id' => 55,
			)
		);

		// Hydrate pin: a DISABLED array used to (bool)-cast to TRUE.
		$saved = ( new EnvDetector() )->saved_settings();
		self::assertFalse( $saved['abandonedCartEnabled'], 'A disabled legacy array must hydrate as false — (bool) on a non-empty array is always true.' );

		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array( 'abandonedCartEnabled' => true ),
			)
		);
		self::assertSame( 200, $response->get_status() );

		self::assertSame(
			array(
				'enabled'          => true,
				'autoresponder_id' => 55,
			),
			get_option( self::STATUS_OPTION ),
			'A Settings save must not destroy the upgraded store\'s legacy autoresponder id — it is the CartFlusher\'s no-mapping fallback.'
		);
	}
}
