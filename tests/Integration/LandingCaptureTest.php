<?php
/**
 * Integration: LandingCapture — the server-side recommendation-attribution
 * capture gate + the cookies it writes, against the real connection state and
 * the real stored engine config (cookie names / TTLs).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that a unit test can't: the capture gate reads the real
 * `smly_rec_connected` flag from wp_options and resolves cookie names from the
 * real stored setup-exchange config — so it asserts the same cookies the
 * checkout stamping (HookHandler) will actually look for.
 */
final class LandingCaptureTest extends TestCase {

	private const UUID = '11111111-2222-4333-8444-555555555555';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$_GET    = array();
		$_COOKIE = array();
	}

	protected function tearDown(): void {
		$_GET    = array();
		$_COOKIE = array();
		remove_all_filters( 'smaily_connect_capture_attribution' );
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $written
	 */
	private function recorder(): LandingCapture {
		return new class( new RecEngineSettings() ) extends LandingCapture {
			/** @var array<string, string> */
			public array $written = array();

			protected function headers_already_sent(): bool {
				return false; // PHPUnit's progress output makes the real one true.
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				$this->written[ $name ] = $value;
			}
		};
	}

	public function test_no_capture_when_engine_not_connected(): void {
		// EnvScrub::reset() left the tenant disconnected.
		$_GET    = array( 'smaily_rec' => self::UUID );
		$capture = $this->recorder();

		$capture->capture();

		self::assertSame( array(), $capture->written, 'Disconnected tenant ⇒ no attribution cookie.' );
	}

	public function test_captures_full_link_into_config_named_cookies(): void {
		EnvSeed::connect();
		$_GET = array(
			'smaily_rec' => self::UUID,
			'smaily_vt'  => 'vt_8f3k2aBz01',
			'smaily_ctx' => 'cart_abandoned',
			'utm_source' => 'smaily',
		);
		$capture = $this->recorder();

		$capture->capture();

		// Names come from the seeded engine config (EnvSeed::fixture_config) and
		// MUST match what HookHandler::ORDER_META_COOKIES reads at checkout.
		self::assertSame( self::UUID, $capture->written['smaily_rec_id'] ?? null );
		self::assertSame( 'vt_8f3k2aBz01', $capture->written['smaily_rec_uid'] ?? null );
		self::assertSame( 'cart_abandoned', $capture->written['smaily_rec_ctx'] ?? null );
		// And $_COOKIE is kept coherent within the request.
		self::assertSame( self::UUID, $_COOKIE['smaily_rec_id'] );
	}

	public function test_captures_utm_content_only_with_utm_source_smaily(): void {
		EnvSeed::connect();
		$_GET    = array(
			'utm_content' => self::UUID,
			'utm_source'  => 'smaily',
		);
		$capture = $this->recorder();

		$capture->capture();

		self::assertSame( self::UUID, $capture->written['smaily_rec_id'] ?? null );
	}

	public function test_ignores_utm_content_from_a_non_smaily_campaign(): void {
		EnvSeed::connect();
		$_GET    = array(
			'utm_content' => self::UUID,
			'utm_source'  => 'google',
		);
		$capture = $this->recorder();

		$capture->capture();

		self::assertSame( array(), $capture->written, 'utm_content from a non-Smaily source must not be captured.' );
	}

	public function test_filter_can_disable_capture(): void {
		EnvSeed::connect();
		add_filter( 'smaily_connect_capture_attribution', '__return_false' );
		$_GET    = array( 'smaily_rec' => self::UUID );
		$capture = $this->recorder();

		$capture->capture();

		self::assertSame( array(), $capture->written, 'The escape-hatch filter must suppress capture.' );
	}
}
