<?php
/**
 * Declarative registry of every REST endpoint the plugin exposes.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Bootstrap;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Smaily\RecEngine\SetupExchange;

/**
 * One source of truth for the plugin's `/wp-json/smaily-connect/v1/*`
 * route surface. Both Bootstrap::register_rest_endpoints() and the
 * Integration\RestRouteRegistrationTest read from this class — adding
 * an endpoint without wiring it into the loop becomes a structural
 * impossibility (no parallel registration path exists).
 *
 * Why a dedicated class instead of an inline array inside Bootstrap:
 *
 *   1. The integration test needs access to the expected-route list
 *      without booting the full plugin (it asserts what `rest_get_server()`
 *      reports against the SAME list Bootstrap uses to register). Pulling
 *      the list into a small class lets the test import it directly.
 *
 *   2. Faas 3 adds ~9 rec-engine endpoints (RECENGINE_API_CONTRACT.md
 *      §7) — without this consolidation each new endpoint would mean
 *      a new line inside register_rest_endpoints(), a new register()
 *      call, and a separate doc-grep for "did we wire it?" The
 *      sub-PR 2.H backfill regression that Erkki hit on staging was
 *      exactly this class of bug: BackfillEndpoint existed but
 *      Bootstrap had silently dropped it from the array during a
 *      constructor-throws refactor.
 *
 *   3. Endpoint objects build with different constructor signatures
 *      (some take Bootstrap services, some are plain). The factory
 *      below keeps that wiring co-located with the route declaration,
 *      so a reviewer looking at "what does /workflows need?" sees the
 *      `WorkflowsEndpoint(...)` constructor right next to the
 *      `/workflows` route declaration.
 */
final class EndpointRegistry {

	/**
	 * Build every endpoint object the plugin exposes, wired with the
	 * Bootstrap-provided services they need at construction.
	 *
	 * Called once per request from Bootstrap::register_rest_endpoints()
	 * inside the `rest_api_init` hook. Each returned object MUST have
	 * a public `register(): void` method that calls register_rest_route.
	 *
	 * @return array<int, object>
	 */
	public static function endpoints( Bootstrap $bootstrap ): array {
		return array(
			new TestConnectionEndpoint(),
			new BackfillEndpoint(
				static function ( string $job_type ) use ( $bootstrap ): ?BackfillJobInterface {
					// One dispatch for all job types (contacts + rec-engine),
					// shared with Bootstrap::on_backfill_tick.
					return $bootstrap->make_backfill_job( $job_type );
				}
			),
			new WorkflowsEndpoint(
				$bootstrap->credentials(),
				static function ( string $subdomain, string $username, string $password ): Client {
					return new Client( $subdomain, $username, $password );
				}
			),
			new SettingsEndpoint(),
			new EventsEndpoint(),
			new RecEngineEndpoint(
				new RecEngineSettings(),
				static function (): SetupExchange {
					return new SetupExchange();
				},
				static function ( string $api_key, string $base_url ): RecEngineClient {
					return new RecEngineClient( $api_key, $base_url );
				}
			),
			new AutomationsEndpoint(
				new RecEngineSettings(),
				// Unlike the ping factory above, this one carries the stored
				// ENDPOINTS MAP — the automations URLs prefer the engine's
				// `automations_*` map keys (fallback PATH_* constants cover
				// pre-v1.1.0 connections whose map lacks them).
				static function ( string $api_key, string $base_url, array $endpoints ): RecEngineClient {
					return new RecEngineClient( $api_key, $base_url, $endpoints );
				}
			),
			new BeaconEndpoint(
				new RecEngineSettings(),
				static function ( string $api_key, string $base_url ): RecEngineClient {
					return new RecEngineClient( $api_key, $base_url );
				},
				// (a).1: the beacon's profiling gate — drop browse events for an
				// opted-out known contact before they reach the engine.
				$bootstrap->profiling_consent()
			),
		);
	}

	/**
	 * The route surface this plugin guarantees, expressed as
	 * (HTTP method, namespaced path) pairs.
	 *
	 * Integration\RestRouteRegistrationTest reads this and asserts the
	 * live `rest_get_server()->get_routes()` map exposes every pair.
	 * It is intentional that this list is HAND-WRITTEN rather than
	 * derived from endpoints() — a hand-written list catches the case
	 * where an endpoint class exists but its register() method has a
	 * typo in the route string (a derivation would silently match the
	 * typo'd actual route).
	 *
	 * @return array<int, array{method: string, path: string}>
	 */
	public static function expected_routes(): array {
		return array(
			array(
				'method' => 'POST',
				'path'   => '/test-smaily',
			),
			array(
				'method' => 'POST',
				'path'   => '/backfill/start',
			),
			array(
				'method' => 'GET',
				'path'   => '/backfill/status',
			),
			array(
				'method' => 'POST',
				'path'   => '/backfill/cancel',
			),
			array(
				'method' => 'GET',
				'path'   => '/workflows',
			),
			array(
				'method' => 'POST',
				'path'   => '/settings',
			),
			// Event Log (F3-44). These three shipped with EventsEndpoint but
			// were missing here until PRO-1258 — the registration test
			// under-covered them (an accidental removal wouldn't have failed).
			array(
				'method' => 'GET',
				'path'   => '/events',
			),
			array(
				'method' => 'GET',
				'path'   => '/events/detail',
			),
			array(
				'method' => 'POST',
				'path'   => '/events/retry',
			),
			array(
				'method' => 'POST',
				'path'   => '/rec-engine/setup-exchange',
			),
			array(
				'method' => 'POST',
				'path'   => '/rec-engine/ping',
			),
			array(
				'method' => 'POST',
				'path'   => '/rec-engine/disconnect',
			),
			array(
				'method' => 'GET',
				'path'   => '/rec-engine/automations/catalog',
			),
			array(
				'method' => 'GET',
				'path'   => '/rec-engine/automations/config',
			),
			array(
				'method' => 'PUT',
				'path'   => '/rec-engine/automations/config',
			),
			// The public browse-beacon proxy. Registered unconditionally; the
			// gate (connected + browse-tracking on) lives in the handler, which
			// 404s when disabled (BeaconEndpoint hard-gates before any work).
			// Path is `/relay`, not `/beacon` — the latter trips ad-block filter
			// lists and was blocked for real users (F3-41).
			array(
				'method' => 'POST',
				'path'   => '/relay',
			),
		);
	}

	private function __construct() {
		// Static-only registry.
	}
}
