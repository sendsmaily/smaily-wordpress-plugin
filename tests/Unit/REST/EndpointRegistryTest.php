<?php
/**
 * EndpointRegistry tests — the route-list is part of the public contract.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\EndpointRegistry;

final class EndpointRegistryTest extends TestCase {

	public function test_expected_routes_includes_every_faas2_route(): void {
		// The integration suite cross-checks this against the live
		// rest_get_server() route map. The unit test below pins the
		// contract — if Faas 3 forgets to declare a new endpoint here,
		// the integration test would catch it at runtime against
		// wp-env, but only if CI happens to be running the integration
		// suite. This unit assertion catches it during plain
		// `composer run test:php` instead.
		$routes = EndpointRegistry::expected_routes();
		$paths  = array_map(
			static fn ( array $r ): string => $r['method'] . ' ' . $r['path'],
			$routes
		);

		self::assertContains( 'POST /test-smaily', $paths );
		self::assertContains( 'POST /backfill/start', $paths );
		self::assertContains( 'GET /backfill/status', $paths );
		self::assertContains( 'POST /backfill/cancel', $paths );
		self::assertContains( 'GET /workflows', $paths );
		self::assertContains( 'POST /settings', $paths );

		// Event Log (F3-44) — added to expected_routes() in PRO-1258
		// (EventsEndpoint registered them all along; the list under-covered).
		self::assertContains( 'GET /events', $paths );
		self::assertContains( 'GET /events/detail', $paths );
		self::assertContains( 'POST /events/retry', $paths );

		// Sub-PR 3.1 — rec-engine connect/health/disconnect.
		self::assertContains( 'POST /rec-engine/setup-exchange', $paths );
		self::assertContains( 'POST /rec-engine/ping', $paths );
		self::assertContains( 'POST /rec-engine/disconnect', $paths );

		// T2 automations proxy (§11–§13).
		self::assertContains( 'GET /rec-engine/automations/catalog', $paths );
		self::assertContains( 'GET /rec-engine/automations/config', $paths );
		self::assertContains( 'PUT /rec-engine/automations/config', $paths );

		// Public browse-beacon proxy (F3-41: `/relay`, never `/beacon`).
		self::assertContains( 'POST /relay', $paths );

		// Pin the TOTAL so a route can't silently drop out of the list
		// (PRO-1258: the /events triple was missing for months and nothing
		// failed). A new endpoint bumps this number on purpose.
		self::assertCount( 16, $paths );
		self::assertSame( $paths, array_unique( $paths ), 'Duplicate method+path pair in expected_routes()' );
	}

	public function test_every_expected_route_uses_supported_http_method(): void {
		$allowed = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
		foreach ( EndpointRegistry::expected_routes() as $route ) {
			self::assertContains(
				$route['method'],
				$allowed,
				sprintf( 'Unsupported HTTP method on %s: %s', $route['path'], $route['method'] )
			);
			self::assertStringStartsWith( '/', $route['path'], 'Route path must start with /' );
		}
	}
}
