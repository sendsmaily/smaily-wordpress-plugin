<?php
/**
 * Sends the installation report to Smaily's subscription service (CON-290).
 *
 * @package Smaily\Connect\Report
 */

declare(strict_types=1);

namespace Smaily\Connect\Report;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Support\DebugLog;

/**
 * Contract installation-report-v1 (`erkkimarkus/subscription`,
 * docs/contracts/installation-report-v1.md): the store tells Smaily which
 * product runs where, on which versions and Smaily accounts, with which
 * features on, and whether each connection works. ReportBuilder makes the
 * body; this class decides when it goes and sends it.
 *
 * Only while a Smaily marketing account is connected (wordpress.org
 * guideline 7) — ReportBuilder answers null otherwise and nothing is sent.
 *
 * Snapshots go from the Action Scheduler action HOOK (group AS_GROUP), never
 * from a page request:
 *   - `setup_saved` DEBOUNCE_SECONDS after a settings save; more saves in
 *     that window add no second report;
 *   - `daily` 24 hours after the last snapshot attempt; the first one at a
 *     random moment within 24 hours, so stores spread across the day.
 * A 429, a 5xx, a timeout or a network error is retried after 60, 120 and
 * 240 seconds plus 0–30 seconds, or after a longer Retry-After; a
 * Retry-After over an hour ends the try. Any other answer is logged once
 * and not retried. A failed report is never queued: the next one carries
 * the current state.
 *
 * Events go inline, once, without a retry, and whatever the answer the
 * caller goes on: `disconnected` before a connection's credentials or key
 * are deleted, `deactivated` from the deactivation hook.
 *
 * Nothing it does is shown to the merchant; a log line carries the HTTP
 * status and the service's error code, never a message, a body or a
 * credential.
 */
class InstallationReport {

	/** The receiver. Tests and the dev sites never reach it (CLAUDE.md). */
	public const ENDPOINT = 'https://subscription.smaily.com/v1/reports';

	public const HOOK     = 'smly_plus_installation_report';
	public const AS_GROUP = 'smaily-connect-installation-report';

	public const DEBOUNCE_SECONDS        = 300;
	public const CONNECT_TIMEOUT_SECONDS = 3;
	public const TIMEOUT_SECONDS         = 5;
	public const MAX_BODY_BYTES          = 65536;

	/** The waits before the three retries. */
	public const RETRY_DELAYS            = array( 60, 120, 240 );
	public const RETRY_JITTER_SECONDS    = 30;
	public const MAX_RETRY_AFTER_SECONDS = 3600;

	private const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';

	private ReportBuilder $builder;

	public function __construct( ?ReportBuilder $builder = null ) {
		$this->builder = $builder ?? new ReportBuilder();
	}

	/**
	 * A settings save: one `setup_saved` report DEBOUNCE_SECONDS from now,
	 * unless one already waits.
	 */
	public function setup_saved(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || $this->builder->main_credentials() === null ) {
			return;
		}

		$args = array( ReportBuilder::TRIGGER_SETUP_SAVED, 0 );
		if ( $this->pending( $args ) ) {
			return;
		}

		as_schedule_single_action( $this->now() + self::DEBOUNCE_SECONDS, self::HOOK, $args, self::AS_GROUP );
	}

	/**
	 * Arm the daily report on a connected store that has none — the first
	 * one at a random moment within 24 hours. Called from the recurring-jobs
	 * check; a store with no connected account gets none.
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) || $this->builder->main_credentials() === null ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK, array( ReportBuilder::TRIGGER_DAILY, 0 ), self::AS_GROUP ) ) {
			return;
		}

		as_schedule_single_action( $this->now() + $this->random( 0, DAY_IN_SECONDS ), self::HOOK, array( ReportBuilder::TRIGGER_DAILY, 0 ), self::AS_GROUP );
	}

	/**
	 * The HOOK action: send one snapshot, then set the next daily report and
	 * any retry.
	 *
	 * @param mixed $trigger `setup_saved` or `daily`.
	 * @param mixed $attempt 0 for the first try, 1–3 for a retry.
	 */
	public function run( $trigger = '', $attempt = 0 ): void {
		$trigger = (string) $trigger;
		$attempt = (int) $attempt;
		if ( ! in_array( $trigger, array( ReportBuilder::TRIGGER_SETUP_SAVED, ReportBuilder::TRIGGER_DAILY ), true ) ) {
			return;
		}

		try {
			$credentials = $this->builder->main_credentials();
			$body        = $this->builder->build( ReportBuilder::EVENT_SNAPSHOT, $trigger );
			if ( $credentials === null || $body === null ) {
				return;
			}

			$result = $this->send( $body, $credentials );
		} catch ( \Throwable $e ) {
			DebugLog::write( sprintf( '[smaily-connect installation-report] trigger=%s failed: %s', $trigger, DebugLog::where( $e ) ) );
			return;
		}

		$this->schedule_next_daily( $trigger );
		if ( $result['retry'] ) {
			$this->schedule_retry( $trigger, $attempt, $result['retry_after'] );
		}
	}

	/**
	 * Send a `disconnected` or `deactivated` event now, once. Never throws;
	 * the caller goes on whatever happens.
	 *
	 * @param string             $event            ReportBuilder::EVENT_DISCONNECTED or EVENT_DEACTIVATED.
	 * @param array<int, string> $removed_accounts The Smaily accounts a disconnect removes.
	 * @param bool               $engine_removed   True when a disconnect removes Campaign Intelligence.
	 */
	public function send_event( string $event, array $removed_accounts = array(), bool $engine_removed = false ): void {
		try {
			$credentials = $this->builder->main_credentials();
			$body        = $this->builder->build( $event, '', $removed_accounts, $engine_removed );
			if ( $credentials !== null && $body !== null ) {
				$this->send( $body, $credentials );
			}
		} catch ( \Throwable $e ) {
			DebugLog::write( sprintf( '[smaily-connect installation-report] event=%s failed: %s', $event, DebugLog::where( $e ) ) );
		}
	}

	/**
	 * The http_api_curl action, while a report is sent: the contract's
	 * connect timeout. WordPress's HTTP API sets only the total timeout.
	 *
	 * @param mixed $handle      The cURL handle.
	 * @param mixed $parsed_args The request arguments.
	 * @param mixed $url         The request URL.
	 */
	public static function limit_connect_time( $handle, $parsed_args = array(), $url = '' ): void {
		if ( $url === self::ENDPOINT && $handle instanceof \CurlHandle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress's HTTP API exposes no connect timeout; this sets one on its own cURL handle, for the report request only.
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS );
		}
	}

	protected function now(): int {
		return time();
	}

	protected function random( int $min, int $max ): int {
		return wp_rand( $min, $max );
	}

	/**
	 * POST the body. Answers whether the try may be repeated and the wait
	 * the service asked for.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array{retry: bool, retry_after: int}
	 */
	private function send( array $body, CredentialSet $credentials ): array {
		$label = 'event=' . (string) $body['event'] . ( isset( $body['trigger'] ) ? ' trigger=' . (string) $body['trigger'] : '' );
		$json  = wp_json_encode( $body );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BODY_BYTES ) {
			DebugLog::write( sprintf( '[smaily-connect installation-report] %s not sent: error=body_too_large', $label ) );
			return array(
				'retry'       => false,
				'retry_after' => 0,
			);
		}

		add_action( 'http_api_curl', array( self::class, 'limit_connect_time' ), 10, 3 );
		try {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout'     => self::TIMEOUT_SECONDS,
					'redirection' => 0,
					'sslverify'   => true,
					'user-agent'  => 'SmailyConnect-WooPlugin/' . Constants::version(),
					'headers'     => array(
						'Authorization' => 'Basic ' . base64_encode( $credentials->username . ':' . $credentials->password ),
						'Content-Type'  => 'application/json; charset=utf-8',
					),
					'body'        => $json,
				)
			);
		} finally {
			remove_action( 'http_api_curl', array( self::class, 'limit_connect_time' ), 10 );
		}

		if ( is_wp_error( $response ) ) {
			DebugLog::write( sprintf( '[smaily-connect installation-report] %s http=0 error=%s', $label, $response->get_error_code() ) );
			return array(
				'retry'       => true,
				'retry_after' => 0,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'retry'       => false,
				'retry_after' => 0,
			);
		}

		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$error  = is_array( $answer ) && isset( $answer['error'] ) && is_string( $answer['error'] ) && preg_match( self::CODE_PATTERN, $answer['error'] )
			? $answer['error']
			: '';
		DebugLog::write( sprintf( '[smaily-connect installation-report] %s http=%d error=%s', $label, $status, $error !== '' ? $error : '-' ) );

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;

		return array(
			'retry'       => $status === 429 || $status >= 500,
			'retry_after' => is_numeric( $retry_after ) ? max( 0, (int) $retry_after ) : 0,
		);
	}

	/**
	 * The next daily report: 24 hours after this attempt — or, after a
	 * setup-saved report on a store that has no daily one yet, at a random
	 * moment within 24 hours.
	 */
	private function schedule_next_daily( string $trigger ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$args = array( ReportBuilder::TRIGGER_DAILY, 0 );
		if ( $trigger !== ReportBuilder::TRIGGER_DAILY && ! $this->pending( $args ) ) {
			as_schedule_single_action( $this->now() + $this->random( 0, DAY_IN_SECONDS ), self::HOOK, $args, self::AS_GROUP );
			return;
		}

		as_unschedule_all_actions( self::HOOK, $args, self::AS_GROUP );
		as_schedule_single_action( $this->now() + DAY_IN_SECONDS, self::HOOK, $args, self::AS_GROUP );
	}

	/**
	 * Up to three retries; none when the service asks to wait over an hour.
	 */
	private function schedule_retry( string $trigger, int $attempt, int $retry_after ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || $attempt >= count( self::RETRY_DELAYS ) || $retry_after > self::MAX_RETRY_AFTER_SECONDS ) {
			return;
		}

		$wait = max( self::RETRY_DELAYS[ $attempt ] + $this->random( 0, self::RETRY_JITTER_SECONDS ), $retry_after );
		as_schedule_single_action( $this->now() + $wait, self::HOOK, array( $trigger, $attempt + 1 ), self::AS_GROUP );
	}

	/**
	 * Whether this report waits as a pending action — the one running now
	 * is not pending (CON-285).
	 *
	 * @param array<int, mixed> $args
	 */
	private function pending( array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => $args,
				'group'    => self::AS_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);

		return is_array( $ids ) && $ids !== array();
	}
}
