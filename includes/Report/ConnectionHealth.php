<?php
/**
 * The last answer each connection got — what the installation report says
 * about a connection's health (CON-290).
 *
 * @package Smaily\Connect\Report
 */

declare(strict_types=1);

namespace Smaily\Connect\Report;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as EngineClient;

/**
 * The installation report (contract installation-report-v1 §"Connections")
 * tells, for each Smaily account and for Campaign Intelligence, whether the
 * most recent request failed (with its error CODE) and when the last request
 * that wrote data succeeded. Nothing else in the plugin keeps that, so the
 * two HTTP chokepoints announce every answer:
 *
 *   - `Smaily\Client::request()` fires its RESULT_HOOK with the account's
 *     subdomain, '' or the RefusalReason code, the method and the endpoint;
 *     a 2xx POST is a sync (every POST writes contacts, triggers a workflow
 *     or sends an email) unless its body code refused the write — that
 *     answer carries a code (pre-3.22.0 audit L2);
 *   - `RecEngine\Client::request_url()` fires its RESULT_HOOK with '' or
 *     the engine's error code (`network_error` when no answer came), the
 *     method and the `/ingest/` path ('' for any other path); a 2xx POST
 *     to an `/ingest/` path is a sync.
 *
 * This class decides what counts as a sync and records them in one option.
 * It stores codes only, never a message text (CON-301). A shopper's `/relay`
 * request reaches the engine chokepoint, so a success is written at most
 * once per WRITE_EVERY_SECONDS; a change of status or error code is written
 * at once.
 */
class ConnectionHealth {

	public const OPTION = 'smly_plus_connection_health';

	public const STATUS_OK      = 'ok';
	public const STATUS_FAILING = 'failing';

	/** A success that changes nothing but the time is written at most this often. */
	public const WRITE_EVERY_SECONDS = 300;

	/** The contract's code rule; anything else is stored as UNKNOWN_CODE. */
	public const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';

	private const UNKNOWN_CODE = 'unknown_error';

	public function register(): void {
		add_action( SmailyClient::RESULT_HOOK, array( $this, 'record_smaily' ), 10, 4 );
		add_action( EngineClient::RESULT_HOOK, array( $this, 'record_engine' ), 10, 3 );
	}

	/**
	 * @param mixed $subdomain  The account's subdomain.
	 * @param mixed $error_code '' for a 2xx answer, else the RefusalReason code.
	 * @param mixed $method     The HTTP method; a POST writes data.
	 * @param mixed $endpoint   The API endpoint.
	 */
	public function record_smaily( $subdomain, $error_code = '', $method = '', $endpoint = '' ): void {
		$key = strtolower( trim( (string) $subdomain ) );
		if ( $key === '' ) {
			return;
		}
		$this->record( 'smaily', $key, (string) $error_code, $method === 'POST' );
	}

	/**
	 * @param mixed $error_code '' for a 2xx answer, else the engine's error code.
	 * @param mixed $method     The HTTP method.
	 * @param mixed $path       The `/ingest/` path, '' for any other; a POST to one writes data.
	 */
	public function record_engine( $error_code = '', $method = '', $path = '' ): void {
		$this->record( 'engine', null, (string) $error_code, $method === 'POST' && strpos( (string) $path, '/ingest/' ) !== false );
	}

	/**
	 * The health of one Smaily account, in the report's shape.
	 *
	 * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
	 */
	public function smaily( string $subdomain ): array {
		$all = $this->all();
		$key = strtolower( trim( $subdomain ) );

		return self::shape( isset( $all['smaily'][ $key ] ) && is_array( $all['smaily'][ $key ] ) ? $all['smaily'][ $key ] : array() );
	}

	/**
	 * The health of the Campaign Intelligence connection, in the report's shape.
	 *
	 * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
	 */
	public function engine(): array {
		$all = $this->all();

		return self::shape( isset( $all['engine'] ) && is_array( $all['engine'] ) ? $all['engine'] : array() );
	}

	protected function now(): int {
		return time();
	}

	/**
	 * @param string      $group 'smaily' or 'engine'.
	 * @param string|null $key   The subdomain within 'smaily'; null for 'engine'.
	 */
	private function record( string $group, ?string $key, string $error_code, bool $wrote ): void {
		$all    = $this->all();
		$parent = isset( $all[ $group ] ) && is_array( $all[ $group ] ) ? $all[ $group ] : array();
		$entry  = $key === null ? $parent : ( isset( $parent[ $key ] ) && is_array( $parent[ $key ] ) ? $parent[ $key ] : array() );

		$now  = $this->now();
		$code = $error_code === '' ? '' : ( preg_match( self::CODE_PATTERN, $error_code ) ? $error_code : self::UNKNOWN_CODE );
		$old  = isset( $entry['error_code'] ) ? (string) $entry['error_code'] : null;
		$last = isset( $entry['last_sync_at'] ) ? (int) $entry['last_sync_at'] : 0;

		$changed = $old !== $code;
		$synced  = $code === '' && $wrote && $last <= $now - self::WRITE_EVERY_SECONDS;
		if ( ! $changed && ! $synced ) {
			return;
		}

		$entry['error_code'] = $code;
		if ( $code === '' && $wrote ) {
			$entry['last_sync_at'] = $now;
		}

		if ( $key === null ) {
			$all[ $group ] = $entry;
		} else {
			$parent[ $key ] = $entry;
			$all[ $group ]  = $parent;
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * @param array<string, mixed> $entry
	 *
	 * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
	 */
	private static function shape( array $entry ): array {
		$code = isset( $entry['error_code'] ) ? (string) $entry['error_code'] : '';
		$last = isset( $entry['last_sync_at'] ) ? (int) $entry['last_sync_at'] : 0;

		return array(
			'status'          => $code === '' ? self::STATUS_OK : self::STATUS_FAILING,
			'last_sync_at'    => $last > 0 ? $last : null,
			'last_error_code' => $code === '' ? null : $code,
		);
	}
}
