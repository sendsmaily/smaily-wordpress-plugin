<?php
/**
 * Integration: POST /rec-engine/setup-exchange round-trip.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What Faas-2 / 3.0 lessons this catches:
 *
 *   - save/read key-mismatch (Bug 2.H.19#1 + #2 class). The mock
 *     engine's success response carries a tenant_name; this test
 *     POSTs through /rec-engine/setup-exchange, then reads back via
 *     RecEngineSettings + EnvDetector::saved_settings(). If a future
 *     change diverges the option key the writer uses from the one
 *     the reader looks at, the symmetry-assertion fails loudly.
 *
 *   - api_key must be stored ENCRYPTED. The mock-engine returns a
 *     plaintext "sk_..." style token; assertion below pins that the
 *     raw wp_options row is the Cypher cipher-string, not the plain
 *     value. (Bug 2.H.19#3-class: writer encrypts, reader must
 *     decrypt — round-trip integrity).
 *
 *   - One-time-use token semantics per RECENGINE_API_CONTRACT.md
 *     §7.1. Two consecutive exchanges with the same token: first
 *     succeeds, second returns 400 + error=token_expired_or_used.
 *     This was easy to get wrong if the endpoint didn't propagate
 *     the engine's 410 cleanly — the mock makes it deterministic.
 */
final class RecEngineSetupExchangeTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		RestRequestHelper::login_as_admin();
	}

	public function test_successful_exchange_stores_encrypted_api_key_and_tenant_metadata(): void {
		$setup_url = self::$engine->setup_url( 'tok_success' );

		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => $setup_url )
		);

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertTrue( $data['connected'] );
		self::assertSame( 'Mock Tenant', $data['tenantName'] );
		self::assertNotSame( '', $data['tenantId'] );
		self::assertSame( '1.0.0', $data['engineVersion'] );
		self::assertNotSame( '', $data['issuedAt'] );

		// The endpoint deliberately does NOT echo the api_key into the
		// response body. Even reading the option via wp_options must
		// give us a cipher-string, not the plain sk_* value the engine
		// returned.
		$raw_api_key = get_option( RecEngineSettings::OPTION_API_KEY );
		self::assertIsString( $raw_api_key );
		self::assertNotSame( '', $raw_api_key );
		self::assertStringStartsNotWith(
			'sk_',
			$raw_api_key,
			'api_key stored in wp_options must be encrypted (cipher string), not the plain sk_* token from the engine.'
		);

		// And the value-object decryption path must round-trip back
		// to the original sk_* string the engine returned.
		$settings  = new RecEngineSettings();
		$decrypted = $settings->api_key();
		self::assertStringStartsWith( 'sk_mock_', $decrypted, 'Decryption path did not recover the original api_key.' );

		// connected flag is the gate the boot payload reads.
		self::assertTrue( $settings->is_connected() );

		// Read/write symmetry — the boot payload exposes everything the
		// React layer needs and NOTHING that would leak the key.
		$detector = new \Smaily\Connect\Wizard\EnvDetector();
		$saved    = $detector->saved_settings();
		self::assertArrayHasKey( 'recEngine', $saved );
		self::assertTrue( $saved['recEngine']['connected'] );
		self::assertSame( 'Mock Tenant', $saved['recEngine']['tenantName'] );
		self::assertArrayNotHasKey(
			'apiKey',
			$saved['recEngine'],
			'Boot payload must NOT expose the api_key under any name.'
		);
	}

	public function test_second_use_of_same_token_returns_token_expired(): void {
		$setup_url = self::$engine->setup_url( 'tok_used_twice' );

		// First call — success.
		$first = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => $setup_url )
		);
		self::assertSame( 200, $first->get_status() );
		$stored_api_key_after_first = get_option( RecEngineSettings::OPTION_API_KEY );
		self::assertIsString( $stored_api_key_after_first );
		self::assertNotSame( '', $stored_api_key_after_first );

		// Second call with the same token — engine returns 410, our
		// endpoint translates it to a 400 with the token_expired_or_used
		// code so the React layer can show a tailored "already used"
		// banner.
		$second = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => $setup_url )
		);
		self::assertSame( 400, $second->get_status() );
		$body = $second->get_data();
		self::assertFalse( $body['connected'] );
		self::assertSame( 'token_expired_or_used', $body['error'] );

		// Crucially: the second (failed) call must NOT have wiped the
		// already-stored credential. Persisting state across a failure
		// keeps the merchant working — only an explicit Disconnect
		// clears the api_key.
		$stored_api_key_after_second = get_option( RecEngineSettings::OPTION_API_KEY );
		self::assertSame(
			$stored_api_key_after_first,
			$stored_api_key_after_second,
			'A failed second exchange must not corrupt the stored api_key.'
		);
	}

	public function test_unknown_token_returns_token_not_found(): void {
		$setup_url = self::$engine->setup_url( 'notfound_xyz' );

		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => $setup_url )
		);
		self::assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		self::assertFalse( $body['connected'] );
		self::assertSame( 'token_not_found', $body['error'] );
		self::assertFalse(
			(bool) get_option( RecEngineSettings::OPTION_CONNECTED, false ),
			'A not-found token must NOT flip the connected flag on.'
		);
	}

	public function test_garbled_setup_url_is_rejected_before_calling_engine(): void {
		$response = RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => 'not a url at all' )
		);
		self::assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		self::assertSame( 'invalid_setup_url', $body['error'] );
	}

	public function test_setup_token_does_not_persist_in_wp_options_after_exchange(): void {
		$setup_url = self::$engine->setup_url( 'tok_secret_payload' );

		RestRequestHelper::post(
			'/rec-engine/setup-exchange',
			array( 'setup_url' => $setup_url )
		);

		// Scan wp_options globally — the token must not appear in any
		// row, encrypted or otherwise. RECENGINE_API_CONTRACT.md §7.1:
		// "Plugin peab API-key turvaliselt salvestama esimesest
		// exchange'st" — token is used and discarded.
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$matches = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( 'tok_secret_payload' ) . '%'
			)
		);
		self::assertSame( '0', (string) $matches, 'Setup token leaked into wp_options after exchange.' );
	}
}
