<?php
/**
 * Test-support helper — dispatches REST requests through the live
 * WP_REST_Server instance, the same way an HTTP client would.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Why a helper: integration tests want to assert "the server reports
 * route X" + "a POST to route X with payload Y returns 200" without
 * shelling out to curl from inside the PHP runtime. WP's
 * rest_get_server()->dispatch() is the in-process equivalent of curl
 * — it walks the full permission_callback + arg-validation + handler
 * chain, so the route assertions match what a real HTTP client would
 * see.
 */
final class RestRequestHelper {

	/**
	 * Authenticate the current request as the wp-env default admin
	 * user so manage_options permission checks pass. wp-env seeds an
	 * `admin` user at install; we look it up by ID 1 (always the
	 * primary admin per WP convention).
	 */
	public static function login_as_admin(): void {
		if ( get_current_user_id() === 1 ) {
			return;
		}
		wp_set_current_user( 1 );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function post( string $route, array $body = array() ): WP_REST_Response {
		self::mark_rest_request();
		$req = new WP_REST_Request( 'POST', '/smaily-connect/v1' . $route );
		foreach ( $body as $key => $value ) {
			$req->set_param( $key, $value );
		}
		$req->set_header( 'Content-Type', 'application/json' );
		// rest_get_server is a global helper that returns the active
		// WP_REST_Server. dispatch() walks the full route chain.
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Simulate the WP_REST_Server::serve_request() side-effect of
	 * defining REST_REQUEST. Plain dispatch() doesn't set this — but
	 * the legacy `pre_update_option_smaily_connect_api_credentials`
	 * hook bails out only when the constant is true, so without this
	 * the legacy hook wipes credential writes during integration tests
	 * even though the same request from a real HTTP client persists
	 * correctly. Mirrors the production HTTP path so the round-trip
	 * test asserts real-world behaviour, not the in-process quirk.
	 *
	 * Note for Faas-4: the legacy hook's REST_REQUEST gate is brittle
	 * — any future caller that updates the credentials option via
	 * dispatch() instead of HTTP would be wiped. Replacing that gate
	 * with a current_filter() / context-arg check is the right long
	 * term fix.
	 */
	private static function mark_rest_request(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function put( string $route, array $body = array() ): WP_REST_Response {
		self::mark_rest_request();
		$req = new WP_REST_Request( 'PUT', '/smaily-connect/v1' . $route );
		foreach ( $body as $key => $value ) {
			$req->set_param( $key, $value );
		}
		$req->set_header( 'Content-Type', 'application/json' );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * @param array<string, mixed> $query
	 */
	public static function get( string $route, array $query = array() ): WP_REST_Response {
		self::mark_rest_request();
		$req = new WP_REST_Request( 'GET', '/smaily-connect/v1' . $route );
		foreach ( $query as $key => $value ) {
			$req->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $req );
	}

	private function __construct() {
		// Static-only helper.
	}
}
