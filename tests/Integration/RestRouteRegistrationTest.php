<?php
/**
 * Integration: live WP_REST_Server exposes every route the plugin claims.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\EndpointRegistry;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * What Faas-2 bug this catches:
 *
 *   Sub-PR 2.H's backfill regression: BackfillEndpoint::register() was
 *   correct, the route declarations were correct, but Bootstrap had
 *   silently dropped the endpoint from its hand-maintained array
 *   during a constructor-throws refactor. The REST namespace lookup
 *   returned 404 for /backfill/start; the unit tests (which call the
 *   endpoint class directly, not via the server) all passed.
 *
 *   This test asserts that what EndpointRegistry::expected_routes()
 *   declares is what the live WP_REST_Server actually serves. The
 *   refactor in this sub-PR makes Bootstrap loop over the SAME list,
 *   so the only way to forget a route now is to declare it without
 *   adding an endpoint object — and rest_get_server() catches that
 *   here.
 */
final class RestRouteRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
	}

	public function test_every_expected_route_is_registered(): void {
		$server     = rest_get_server();
		$registered = $server->get_routes();

		foreach ( EndpointRegistry::expected_routes() as $expected ) {
			$full_path = '/smaily-connect/v1' . $expected['path'];

			self::assertArrayHasKey(
				$full_path,
				$registered,
				sprintf(
					'Route %s declared in EndpointRegistry::expected_routes() but missing from rest_get_server()->get_routes(). Did Bootstrap::register_rest_endpoints() drop it?',
					$full_path
				)
			);

			$methods_advertised = array();
			foreach ( $registered[ $full_path ] as $route_definition ) {
				if ( isset( $route_definition['methods'] ) && is_array( $route_definition['methods'] ) ) {
					$methods_advertised = array_merge(
						$methods_advertised,
						array_keys(
							array_filter( $route_definition['methods'] )
						)
					);
				}
			}

			self::assertContains(
				$expected['method'],
				$methods_advertised,
				sprintf(
					'Route %s exists but does NOT advertise %s. Found: %s',
					$full_path,
					$expected['method'],
					implode( ', ', $methods_advertised )
				)
			);
		}
	}

	public function test_namespace_index_lists_the_plugin_namespace(): void {
		// `GET /wp-json/` returns the namespace index. Our plugin must
		// appear so external diagnostic tools (Erkki's "is the plugin
		// even there?" curl) see it without a manual route guess.
		$req      = new \WP_REST_Request( 'GET', '/' );
		$response = rest_get_server()->dispatch( $req );
		$data     = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'namespaces', $data );
		self::assertContains( 'smaily-connect/v1', $data['namespaces'] );
	}
}
