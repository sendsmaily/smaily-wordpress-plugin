<?php
/**
 * Tests for the server-side recommendation-attribution landing capture.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Settings\RecEngineSettings;

final class LandingCaptureTest extends TestCase {

	private const UUID = '11111111-2222-4333-8444-555555555555';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_GET    = array();
		$_COOKIE = array();
		parent::tearDown();
	}

	private function capture(): LandingCapture {
		return new LandingCapture( new RecEngineSettings() );
	}

	// ---- resolve(): rec_id source precedence + validation -------------------

	public function test_resolve_takes_smaily_rec_as_the_rec_id(): void {
		$out = $this->capture()->resolve( array( 'smaily_rec' => self::UUID ) );
		self::assertSame( self::UUID, $out['rec_id'] );
	}

	public function test_resolve_rejects_a_non_uuid_smaily_rec_token(): void {
		// PRO-1710: the engine validates smaily_rec_id as a uuid PER ORDER (D6),
		// so a bounded-but-not-uuid token (hand-typed, truncated, crafted) would
		// ride the order and get that order permanently rejected. Not captured.
		$out = $this->capture()->resolve( array( 'smaily_rec' => 'rec_abc123' ) );
		self::assertArrayNotHasKey( 'rec_id', $out );
	}

	public function test_resolve_rejects_a_truncated_uuid(): void {
		$out = $this->capture()->resolve( array( 'smaily_rec' => substr( self::UUID, 0, 30 ) ) );
		self::assertArrayNotHasKey( 'rec_id', $out );
	}

	public function test_resolve_rejects_a_malformed_smaily_rec(): void {
		$out = $this->capture()->resolve( array( 'smaily_rec' => 'has space <script>' ) );
		self::assertArrayNotHasKey( 'rec_id', $out );
	}

	public function test_resolve_uses_utm_content_only_with_utm_source_smaily(): void {
		$with = $this->capture()->resolve(
			array(
				'utm_content' => self::UUID,
				'utm_source'  => 'smaily',
			)
		);
		self::assertSame( self::UUID, $with['rec_id'], 'utm_source=smaily + uuid utm_content ⇒ captured.' );

		$without = $this->capture()->resolve( array( 'utm_content' => self::UUID ) );
		self::assertArrayNotHasKey( 'rec_id', $without, 'utm_content without utm_source=smaily must be ignored (shared marketing param).' );
	}

	public function test_resolve_requires_uuid_shape_for_utm_content(): void {
		$out = $this->capture()->resolve(
			array(
				'utm_content' => 'gclid_or_other_marketing_value',
				'utm_source'  => 'smaily',
			)
		);
		self::assertArrayNotHasKey( 'rec_id', $out, 'A non-uuid utm_content must not be credited even with utm_source=smaily.' );
	}

	public function test_resolve_prefers_smaily_rec_over_utm_content(): void {
		$out = $this->capture()->resolve(
			array(
				'smaily_rec'  => self::UUID,
				'utm_content' => '99999999-2222-4333-8444-555555555555',
				'utm_source'  => 'smaily',
			)
		);
		self::assertSame( self::UUID, $out['rec_id'] );
	}

	// ---- resolve(): visitor token + context ---------------------------------

	public function test_resolve_captures_a_valid_visitor_token(): void {
		$out = $this->capture()->resolve( array( 'smaily_vt' => 'vt_8f3k2aBz01' ) );
		self::assertSame( 'vt_8f3k2aBz01', $out['visitor'] );
	}

	public function test_resolve_rejects_a_visitor_token_without_the_vt_prefix(): void {
		$out = $this->capture()->resolve( array( 'smaily_vt' => 'deadbeef' ) );
		self::assertArrayNotHasKey( 'visitor', $out );
	}

	public function test_resolve_captures_a_context_slug(): void {
		$out = $this->capture()->resolve( array( 'smaily_ctx' => 'cart_abandoned' ) );
		self::assertSame( 'cart_abandoned', $out['context'] );
	}

	public function test_resolve_rejects_a_malformed_context(): void {
		$out = $this->capture()->resolve( array( 'smaily_ctx' => 'bad value!' ) );
		self::assertArrayNotHasKey( 'context', $out );
	}

	public function test_resolve_returns_empty_for_an_unrelated_url(): void {
		$out = $this->capture()->resolve( array( 'utm_source' => 'newsletter', 'page' => '2' ) );
		self::assertSame( array(), $out );
	}

	public function test_resolve_captures_all_three_slots_from_a_full_link(): void {
		$out = $this->capture()->resolve(
			array(
				'smaily_rec' => self::UUID,
				'smaily_vt'  => 'vt_abc123',
				'smaily_ctx' => 'cross_sell',
				'utm_source' => 'smaily',
			)
		);
		self::assertSame(
			array(
				'rec_id'  => self::UUID,
				'visitor' => 'vt_abc123',
				'context' => 'cross_sell',
			),
			$out
		);
	}

	// ---- capture(): gating + cookie effect ----------------------------------

	public function test_capture_writes_cookies_on_a_connected_rec_landing(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 ); // default (true) passes through.
		$_GET   = array( 'smaily_rec' => self::UUID, 'smaily_vt' => 'vt_abc123' );
		$writer = $this->recording_capture( true, array() );

		$writer->capture();

		self::assertSame( self::UUID, $writer->written['smaily_rec_id'] ?? null );
		self::assertSame( 'vt_abc123', $writer->written['smaily_rec_uid'] ?? null );
		// $_COOKIE is kept coherent within the request.
		self::assertSame( self::UUID, $_COOKIE['smaily_rec_id'] );
	}

	public function test_capture_honours_engine_config_cookie_names(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_GET   = array( 'smaily_rec' => self::UUID );
		$writer = $this->recording_capture( true, array( 'rec_id_cookie_name' => 'custom_rec' ) );

		$writer->capture();

		self::assertArrayHasKey( 'custom_rec', $writer->written );
		self::assertArrayNotHasKey( 'smaily_rec_id', $writer->written );
	}

	public function test_capture_does_nothing_when_not_connected(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_GET   = array( 'smaily_rec' => self::UUID );
		$writer = $this->recording_capture( false, array() );

		$writer->capture();

		self::assertSame( array(), $writer->written );
	}

	public function test_capture_does_nothing_when_filter_disables_it(): void {
		Functions\when( 'apply_filters' )->justReturn( false );
		$_GET   = array( 'smaily_rec' => self::UUID );
		$writer = $this->recording_capture( true, array() );

		$writer->capture();

		self::assertSame( array(), $writer->written );
	}

	public function test_capture_fast_path_skips_an_unrelated_request(): void {
		// No trigger param ⇒ the filter/connection gates are never even consulted.
		Functions\expect( 'apply_filters' )->never();
		$_GET   = array( 'page' => '3' );
		$writer = $this->recording_capture( true, array() );

		$writer->capture();

		self::assertSame( array(), $writer->written );
	}

	/**
	 * A LandingCapture whose settings are doubled (connected flag + config) and
	 * whose cookie write is captured into a public `$written` map instead of a
	 * real Set-Cookie header.
	 *
	 * @param array<string, mixed> $config
	 */
	private function recording_capture( bool $connected, array $config ): LandingCapture {
		$settings = new class( $connected, $config ) extends RecEngineSettings {
			private bool $connected;
			private array $cfg;

			public function __construct( bool $connected, array $config ) {
				$this->connected = $connected;
				$this->cfg       = $config;
			}

			public function is_connected(): bool {
				return $this->connected;
			}

			public function config(): array {
				return $this->cfg;
			}
		};

		return new class( $settings ) extends LandingCapture {
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
}
