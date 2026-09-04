<?php
/**
 * Outcome of a `POST /setup/exchange` round-trip.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Discriminated union of the four shapes the setup-exchange can
 * produce, per RECENGINE_API_CONTRACT.md §7.1:
 *
 *   - success           HTTP 200 — tenant config + api_key
 *   - token_expired     HTTP 410 — already used or expired
 *   - token_not_found   HTTP 404 — invalid token string
 *   - engine_unreachable network failure / 5xx / unparseable body
 *
 * The class is intentionally a plain final value-object rather than
 * a hierarchy of subclasses — match-by-`kind` reads more linearly in
 * REST handlers, and the per-kind field set is small enough that
 * one shape (with nullable fields) is clearer than five.
 *
 * Successful results are the only kind callers persist; the others
 * surface verbatim to the React layer as the REST endpoint's error
 * body, where they translate to specific UI messages (see the Step 4
 * "token already used" copy in Step4Recommendations.tsx).
 */
final class ExchangeResult {

	public const KIND_SUCCESS            = 'success';
	public const KIND_TOKEN_EXPIRED      = 'token_expired';
	public const KIND_TOKEN_NOT_FOUND    = 'token_not_found';
	public const KIND_ENGINE_UNREACHABLE = 'engine_unreachable';

	public string $kind;

	/** Populated on success only. */
	public string $tenant_id       = '';
	public string $tenant_name     = '';
	public string $api_key         = '';
	public string $engine_base_url = '';
	public string $engine_version  = '';
	public string $issued_at       = '';
	/** @var array<string, string> */
	public array $endpoints = array();
	/** @var array<string, mixed> */
	public array $config = array();

	/** Populated on token_expired only. */
	public string $regenerate_url = '';

	/** Populated on engine_unreachable / network errors. */
	public string $reason = '';

	private function __construct( string $kind ) {
		$this->kind = $kind;
	}

	/**
	 * @param array<string, mixed> $body Parsed response JSON.
	 */
	public static function success( array $body ): self {
		$r = new self( self::KIND_SUCCESS );

		$r->tenant_id       = isset( $body['tenant_id'] ) ? (string) $body['tenant_id'] : '';
		$r->tenant_name     = isset( $body['tenant_name'] ) ? (string) $body['tenant_name'] : '';
		$r->api_key         = isset( $body['api_key'] ) ? (string) $body['api_key'] : '';
		$r->engine_base_url = isset( $body['engine_base_url'] ) ? (string) $body['engine_base_url'] : '';
		$r->engine_version  = isset( $body['engine_version'] ) ? (string) $body['engine_version'] : '';
		$r->issued_at       = isset( $body['issued_at'] ) ? (string) $body['issued_at'] : '';

		if ( isset( $body['endpoints'] ) && is_array( $body['endpoints'] ) ) {
			foreach ( $body['endpoints'] as $key => $url ) {
				$r->endpoints[ (string) $key ] = (string) $url;
			}
		}
		if ( isset( $body['config'] ) && is_array( $body['config'] ) ) {
			$r->config = $body['config'];
		}

		return $r;
	}

	public static function token_expired( string $regenerate_url = '' ): self {
		$r                 = new self( self::KIND_TOKEN_EXPIRED );
		$r->regenerate_url = $regenerate_url;
		return $r;
	}

	public static function token_not_found(): self {
		return new self( self::KIND_TOKEN_NOT_FOUND );
	}

	public static function engine_unreachable( string $reason ): self {
		$r         = new self( self::KIND_ENGINE_UNREACHABLE );
		$r->reason = $reason;
		return $r;
	}
}
