<?php
/**
 * Exception raised when a Smaily API request fails.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status code in $code (0 for transport-level errors that
 * never received a response) so callers can branch on the PLUGIN.md §8
 * retry policy: 4xx no-retry, 429 honour Retry-After, 5xx exponential
 * backoff. That branching lives in RetryPolicy; the row-level orchestration
 * (attempt counter, retry parking) lives in EventQueue. This class only
 * carries the facts those need: the status code, how long Smaily asked us to
 * wait when it sent a `Retry-After` header, and Smaily's own response code from
 * the body (the `{code, message}` envelope every endpoint answers with,
 * https://smaily.com/help/api/general/response-codes/), which says WHY it
 * refused where the HTTP status only says that it did.
 */
final class ApiException extends \RuntimeException {

	/** Seconds Smaily asked the caller to wait, or null when it didn't say. */
	private ?int $retry_after;

	/** Smaily's own response code from the body, or null when the body carried none. */
	private ?int $smaily_code;

	public function __construct( string $message = '', int $code = 0, ?int $retry_after = null, ?int $smaily_code = null ) {
		parent::__construct( $message, $code );

		$this->retry_after = $retry_after;
		$this->smaily_code = $smaily_code;
	}

	public function retry_after(): ?int {
		return $this->retry_after;
	}

	public function smaily_code(): ?int {
		return $this->smaily_code;
	}
}
