<?php
/**
 * Test-support helper — the inverse of EnvScrub. Seeds a fully
 * "connected" rec-engine state into wp_options so integration tests can
 * exercise authenticated ingest paths without a real setup-exchange.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ExchangeResult;

/**
 * Why this exists: wp-env recreates the WordPress database on some
 * restarts, so the rec-engine connection established by a real
 * setup-exchange (smly_rec_api_key et al.) does NOT survive between
 * sessions. Any integration test that needs a "connected" tenant must
 * therefore reconstruct that state from a regeneratable fixture rather
 * than depend on persisted DB rows. EnvSeed::connect() is that fixture.
 *
 * It drives the SAME path production uses — RecEngineSettings::store(
 * ExchangeResult::success( ... ) ) — so the stored shape (encrypted
 * api_key, endpoints map, config) is byte-for-byte what a live exchange
 * would write. Only the values are fake.
 *
 * The endpoints map intentionally uses the engine's real wire keys
 * (`ingest_catalog`, `ingest_ping`, …, with the `ingest_` prefix) so a
 * test that reads RecEngineSettings::endpoints()['ingest_catalog'] is
 * pinned to the exact key the engine returns. The plugin once read the
 * wrong key (`catalog`) and silently got a null URL — this fixture locks
 * the contract (LESSONS.md read/write-symmetry rule).
 *
 * Real api_key vs fixture: the live walk-3.2 harness (RECENGINE_LIVE=1)
 * uses whatever real, encrypted api_key the DB holds after Erkki's
 * setup-exchange. EnvSeed is for the mock/integration suite only — its
 * api_key never authenticates against the real engine.
 */
final class EnvSeed {

	public const FIXTURE_API_KEY     = 'sk_test_fixture_0000000000000000000000';
	public const FIXTURE_BASE_URL    = 'https://re-fixture.test';
	public const FIXTURE_TENANT_ID   = '55cf5b85-50fe-4e00-af2c-33c5ec278210';
	public const FIXTURE_TENANT_NAME = 'MiuMjau';
	public const FIXTURE_VERSION     = '1.0.0';

	/**
	 * Seed a connected tenant. Pass $overrides to replace any top-level
	 * exchange-body field (e.g. ['endpoints' => [...]] to test a map that
	 * omits a key, or ['api_key' => '...'] to point at a different tenant).
	 *
	 * @param array<string, mixed> $overrides Replaces default body fields.
	 */
	public static function connect( array $overrides = array() ): void {
		$body = array_merge( self::default_body(), $overrides );

		( new RecEngineSettings() )->store( ExchangeResult::success( $body ) );

		// store() writes smly_rec_connected with autoload=true; flush the
		// alloptions cache so an in-process is_connected() read in the same
		// test reflects the seed immediately rather than a stale warm cache.
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function default_body(): array {
		return array(
			'tenant_id'       => self::FIXTURE_TENANT_ID,
			'tenant_name'     => self::FIXTURE_TENANT_NAME,
			'api_key'         => self::FIXTURE_API_KEY,
			'engine_base_url' => self::FIXTURE_BASE_URL,
			'engine_version'  => self::FIXTURE_VERSION,
			'issued_at'       => '2026-06-02T00:00:00.000Z',
			'endpoints'       => self::fixture_endpoints(),
			'config'          => self::fixture_config(),
		);
	}

	/**
	 * Engine endpoints map — real wire keys (`ingest_` prefix), full URLs,
	 * mirroring the live setup-exchange response (all 11 endpoints).
	 *
	 * @return array<string, string>
	 */
	public static function fixture_endpoints(): array {
		$base = self::FIXTURE_BASE_URL;

		return array(
			'ingest_ping'             => $base . '/api/v1/ingest/ping',
			'ingest_catalog'          => $base . '/api/v1/ingest/catalog',
			'ingest_customers'        => $base . '/api/v1/ingest/customers',
			'ingest_orders'           => $base . '/api/v1/ingest/orders',
			'ingest_browse'           => $base . '/api/v1/ingest/browse',
			'identity_merge'          => $base . '/api/v1/identity/merge',
			'customer_export'         => $base . '/api/v1/customer/%s/export',
			'customer_delete'         => $base . '/api/v1/customer/%s',
			'customer_opt_out'        => $base . '/api/v1/customer/%s/opt-out',
			'recommendations_preview' => $base . '/api/v1/recommendations/preview',
			'recommendations_issue'   => $base . '/api/v1/recommendations/issue',
		);
	}

	/**
	 * Engine config map — subset of the 15 keys the live exchange returns,
	 * enough for downstream sub-PRs to read batch sizing, languages, cookie
	 * names, and rate limits from the engine rather than constants.
	 *
	 * @return array<string, mixed>
	 */
	public static function fixture_config(): array {
		return array(
			'batch_size_max'        => 100,
			'supported_languages'   => array( 'et', 'en' ),
			'rate_limit_browse'     => 500,
			'rate_limit_other'      => 100,
			'tracking_cookie_name'  => 'smaily_rec_uid',
			'session_cookie_name'   => 'smaily_anon_sid',
			'rec_id_cookie_name'    => 'smaily_rec_id',
			'context_cookie_name'   => 'smaily_rec_ctx',
			'cookie_ttl_days'       => 365,
			'session_ttl_days'      => 30,
		);
	}

	private function __construct() {
		// Static-only helper.
	}
}
