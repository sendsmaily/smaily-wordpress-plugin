<?php
/**
 * The one place that decides what happens to a failed Smaily queue row.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * The retry policy the codebase has always described but never applied on the
 * Smaily side (PRO-1685). PLUGIN.md §8 / the ApiException + Client docblocks:
 *
 *   4xx no-retry, 429 honour Retry-After, 5xx exponential backoff, max 5
 *   attempts.
 *
 * Before this, every ApiException was treated as transient: the flushers
 * called record_attempt() and nothing ever read the counter, so a refusal that
 * could never succeed (401 revoked credentials, 403, 404 workflow gone, 422
 * validation) was re-POSTed every 60s forever. The row never reached `failed`,
 * so it never reached the "N sync events failed" notice either, and — because
 * the drain is oldest-first — a batch of such rows crowded out fresher work.
 *
 * What this class decides, per failure:
 *
 *   - PERMANENT (4xx except 429): stop. mark_failed with a `permanent_http_*`
 *     reason, visible in the Event Log and countable by NotificationManager.
 *   - REFUSING envelope (HTTP 2xx whose `{code, message}` body carries any
 *     code other than 101): throw_if_refused_envelope() decides by the code
 *     (PRO-3750 for 203, PRO-3862 for the rest). A code a retry with the
 *     same data can pass (TRANSIENT_ENVELOPE_CODES) takes the TEMPORARY path
 *     below; every other code, listed by Smaily or not, fails the row at
 *     once with a `permanent_envelope_<code>` reason that keeps Smaily's
 *     message. Never "sent".
 *   - TEMPORARY (5xx, 429, transport error / code 0, a transient envelope
 *     code): retry, spaced by
 *     BACKOFF (1m, 5m, 15m, 1h, 6h) — or by Smaily's own Retry-After when it
 *     sent one — until MAX_ATTEMPTS, then mark_failed with
 *     `retry_limit_exceeded`.
 *
 * Deliberately biased toward retrying: anything not recognisably a permanent
 * refusal (including a transport error that never got a status) is temporary,
 * because mis-classifying a recoverable failure as permanent drops genuine
 * work. The blast radius of a wrong call either way is bounded by the Event
 * Log's recovery path (`POST /events/retry` → EventQueue::reset_failed(),
 * which clears status + attempts + the retry park for ANY row in this queue).
 *
 * Applied by Flusher and CartFlusher. TransactionalFlusher reads envelopes
 * through throw_if_refused_envelope() too, but deliberately keeps its own
 * retry bound — a time ceiling (PRO-1519), because a pending transactional
 * row suppresses the customer's native WooCommerce email while it waits, so
 * elapsed time (not attempt count) is what must be capped there.
 */
final class RetryPolicy {

	/** Attempts a temporary failure gets in total before the row is given up on. */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Spacing per attempt number, in seconds: 1m, 5m, 15m, 1h, 6h — the same
	 * ladder the rec-engine queue uses (AbstractD6Flusher), so an operator
	 * reading either Event Log sees one retry rhythm.
	 *
	 * @var array<int, int>
	 */
	private const BACKOFF = array( 60, 300, 900, 3600, 21600 );

	/** Ceiling on a Smaily-supplied Retry-After, so a bad header can't park a row for days. */
	private const MAX_DELAY = 21600;

	/**
	 * Smaily body codes a retry with the SAME data can pass (PRO-3862). Of
	 * Smaily's response-code table
	 * (https://smaily.com/help/api/general/response-codes/) only 225
	 * "Database insert failed", an internal Smaily database error, is not
	 * about the request. Every other code there names the request itself
	 * (203 invalid data, 204 invalid email, 207 missing fields, 221 workflow
	 * missing / disabled / out of credit, …) and fails at once; so does a
	 * code the table does not list, because its meaning is unknown.
	 *
	 * @var array<int, int>
	 */
	private const TRANSIENT_ENVELOPE_CODES = array( 225 );

	/**
	 * Can this failure ever succeed on a retry? 4xx (bar 429) says no —
	 * the request itself is the problem. A transport error carries code 0
	 * and is treated as temporary.
	 */
	public static function is_permanent( ApiException $e ): bool {
		$status = $e->getCode();

		return $status >= 400 && $status < 500 && 429 !== $status;
	}

	/**
	 * The failure reason when a Smaily reply that arrived as HTTP 2xx refuses
	 * the request for good in its `{code, message}` body, else null — null
	 * also for success (101), a reply with no body code, and a code a retry
	 * can pass (TRANSIENT_ENVELOPE_CODES). The reason keeps Smaily's answer
	 * behind a `permanent_envelope_<code>:` class, the same shape
	 * `permanent_http_<code>:` has.
	 *
	 * @param array<string, mixed>|null $exchange A Client::last_exchange().
	 */
	public static function permanent_envelope( ?array $exchange ): ?string {
		$refusal = self::envelope_refusal( $exchange );
		if ( $refusal === null || in_array( $refusal['code'], self::TRANSIENT_ENVELOPE_CODES, true ) ) {
			return null;
		}

		return sprintf( 'permanent_envelope_%1$d: Smaily API returned code %1$d: %2$s', $refusal['code'], $refusal['message'] );
	}

	/**
	 * Throw when the reply's `{code, message}` body refuses the request: a
	 * TerminalDispatchException (the flusher fails the row) for a permanent
	 * code, an ApiException (the flusher retries it) for a code a retry can
	 * pass. Returns quietly on success and when no body code came back.
	 *
	 * @param array<string, mixed>|null $exchange A Client::last_exchange().
	 *
	 * @throws TerminalDispatchException When Smaily refused the data for good.
	 * @throws ApiException              When Smaily refused it for a reason that can pass.
	 */
	public static function throw_if_refused_envelope( ?array $exchange ): void {
		$permanent = self::permanent_envelope( $exchange );
		if ( $permanent !== null ) {
			throw new TerminalDispatchException( $permanent ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message goes to the Event Log / debug log, never echoed to a browser.
		}

		$refusal = self::envelope_refusal( $exchange );
		if ( $refusal !== null ) {
			throw new ApiException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message goes to the Event Log / debug log, never echoed to a browser.
				sprintf( 'Smaily API returned code %d: %s', $refusal['code'], $refusal['message'] ),
				(int) ( $exchange['response']['http'] ?? 200 ),
				null,
				$refusal['code']
			);
		}
	}

	/**
	 * The refusing `{code, message}` of a reply, or null when the reply is a
	 * success (101), carries no numeric body code, or nothing was sent.
	 *
	 * @param array<string, mixed>|null $exchange A Client::last_exchange().
	 *
	 * @return array{code: int, message: string}|null
	 */
	private static function envelope_refusal( ?array $exchange ): ?array {
		$body = $exchange['response']['body'] ?? null;
		if ( ! is_array( $body ) || ! isset( $body['code'] ) || ! is_numeric( $body['code'] ) ) {
			return null;
		}

		$code = (int) $body['code'];
		if ( $code === Client::CODE_OK ) {
			return null;
		}

		return array(
			'code'    => $code,
			'message' => isset( $body['message'] ) && is_scalar( $body['message'] ) ? (string) $body['message'] : '',
		);
	}

	/**
	 * How long before the next attempt: Smaily's Retry-After when it sent one
	 * (that is the 429 half of the policy), otherwise the backoff ladder.
	 *
	 * @param int      $attempt     1-based number of the attempt just made.
	 * @param int|null $retry_after Seconds Smaily asked for, if any.
	 */
	public static function delay_seconds( int $attempt, ?int $retry_after = null ): int {
		if ( $retry_after !== null && $retry_after > 0 ) {
			return min( $retry_after, self::MAX_DELAY );
		}

		$index = max( 0, min( $attempt - 1, count( self::BACKOFF ) - 1 ) );

		return self::BACKOFF[ $index ];
	}

	/**
	 * Advance one row after an ApiException: give up (mark_failed) or park it
	 * for a spaced retry (record_attempt). Returns the flusher stats key so
	 * the caller can do `++$stats[ RetryPolicy::apply( … ) ]`.
	 *
	 * @param int $attempts The row's attempt count BEFORE this failure.
	 *
	 * @return string 'failed' | 'retried'
	 */
	public static function apply( EventQueue $queue, int $id, int $attempts, ApiException $e ): string {
		if ( self::is_permanent( $e ) ) {
			$queue->mark_failed(
				$id,
				sprintf( 'permanent_http_%d: %s', $e->getCode(), $e->getMessage() )
			);

			return 'failed';
		}

		$attempt = $attempts + 1;

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$queue->mark_failed(
				$id,
				sprintf( 'retry_limit_exceeded after %d attempts: %s', $attempt, $e->getMessage() )
			);

			return 'failed';
		}

		$queue->record_attempt( $id, $e->getMessage(), self::delay_seconds( $attempt, $e->retry_after() ) );

		return 'retried';
	}
}
