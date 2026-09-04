<?php
/**
 * Integration: PRO-1517 — mock rec-engine GDPR-path fidelity.
 *
 * Pins two pieces of the real engine's server-side behaviour that the
 * INTEGRATION MOCK (tests/Integration/Fixtures/mock-rec-engine/router.php)
 * previously modeled too loosely — a plugin test exercising either path
 * would have false-greened against a mock that was more permissive than
 * the live engine:
 *
 *   1. §10 opt-out 404s for an unknown customer (the mock accepted any
 *      email before this).
 *   2. §6/§10 Art 21 engine-side binding gate: an opted-out customer is
 *      never bound at browse ingest, on ANY resolution path — including
 *      smaily_visitor_token-only (no customer_email on the event), which
 *      the engine resolves server-side via its own visitor_token→customer
 *      registry. The PHP mock had NO opt-out state tracking at all before
 *      this (unlike the sibling Shopify-repo mock, which already gated the
 *      email path pre-PRO-1477) — closing the token path required adding
 *      the underlying opt-out registry for both paths.
 *
 * Uses Client directly (bypassing BeaconEndpoint's own client-side consent
 * gates and ProfilingConsent's cached decision) because this suite pins the
 * MOCK's simulated ENGINE behaviour, not a plugin code path — the plugin's
 * own opt-out enforcement is covered elsewhere (RecEngineBrowseProxyTest,
 * ProfilingConsentTest).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

final class RecEngineMockFidelityTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'customer_opt_out' => $base . '/api/v1/customer/{email}/opt-out',
					'identity_merge'   => $base . '/api/v1/identity/merge',
					'ingest_browse'    => $base . '/api/v1/ingest/browse',
				),
			)
		);
	}

	// --- gap 1: §10 opt-out 404 for an unknown customer ------------------

	public function test_opt_out_404s_for_unknown_customer(): void {
		try {
			$this->client()->customer_opt_out( 'notfound-optout@example.test', array( 'opt_out' => true ) );
			self::fail( 'Expected ApiException for an unknown customer.' );
		} catch ( ApiException $e ) {
			self::assertSame( 404, $e->getCode() );
			self::assertSame( 'not_found', $e->error_code() );
		}
	}

	public function test_opt_out_succeeds_for_a_known_customer(): void {
		$result = $this->client()->customer_opt_out( 'known-optout@example.test', array( 'opt_out' => true ) );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'known-optout@example.test', $result['customer_email'] );
		self::assertTrue( $result['opt_out_status'] );
	}

	// --- gap 2: browse ingest opt-out gate, both resolution paths --------

	public function test_visitor_token_bound_to_an_opted_out_customer_is_forced_anonymous(): void {
		$email = 'token-optout@example.test';
		$token = 'vt_pro1517_optout';

		$this->client()->merge_identity(
			array(
				'smaily_visitor_token' => $token,
				'customer_email'       => $email,
				'merge_ts'             => '2026-07-22T10:00:00Z',
				'merge_reason'         => 'user_logged_in',
			)
		);
		$this->client()->customer_opt_out( $email, array( 'opt_out' => true ) );

		$result = $this->client()->ingest_browse(
			array(
				array(
					'event_id'             => 'pro1517-token-optout-1',
					'event_type'           => 'product_view',
					'session_id'           => 's-pro1517-1',
					'event_ts'             => '2026-07-22T10:01:00Z',
					'smaily_visitor_token' => $token,
				),
			)
		);

		self::assertSame( 1, $result['processed'] );
		self::assertSame( 0, $result['with_customer_match'], 'a customer opted out via §10 must not be bound via a resolved visitor_token' );
		self::assertSame( 1, $result['anonymous'] );
	}

	public function test_unbound_visitor_token_still_resolves_as_identified(): void {
		$result = $this->client()->ingest_browse(
			array(
				array(
					'event_id'             => 'pro1517-token-unbound-1',
					'event_type'           => 'product_view',
					'session_id'           => 's-pro1517-2',
					'event_ts'             => '2026-07-22T10:02:00Z',
					'smaily_visitor_token' => 'vt_pro1517_unbound',
				),
			)
		);

		self::assertSame( 1, $result['processed'] );
		self::assertSame( 1, $result['with_customer_match'], 'a visitor_token with no merge/opt-out record still counts as identified' );
		self::assertSame( 0, $result['anonymous'] );
	}

	public function test_email_carrying_event_for_an_opted_out_customer_is_forced_anonymous(): void {
		$email = 'email-optout@example.test';
		$this->client()->customer_opt_out( $email, array( 'opt_out' => true ) );

		$result = $this->client()->ingest_browse(
			array(
				array(
					'event_id'       => 'pro1517-email-optout-1',
					'event_type'     => 'product_view',
					'session_id'     => 's-pro1517-3',
					'event_ts'       => '2026-07-22T10:03:00Z',
					'customer_email' => $email,
				),
			)
		);

		self::assertSame( 1, $result['processed'] );
		self::assertSame( 0, $result['with_customer_match'] );
		self::assertSame( 1, $result['anonymous'] );
	}

	// --- helpers ----------------------------------------------------------

	private function client(): Client {
		$settings = new RecEngineSettings();
		return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
	}
}
