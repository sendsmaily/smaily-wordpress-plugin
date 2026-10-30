<?php
/**
 * Thrown when the rec-engine API returns a non-success response.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Surfaces engine-side errors to plugin callers. Mirrors the existing
 * Smaily\Smaily\ApiException pattern (Smaily mailer) so both APIs
 * have a consistent error-handling shape.
 *
 * The contract guarantees a JSON error body (§5):
 *   { error, message, details?, request_id, timestamp }
 *
 * The exception carries the canonical `error` code so callers can
 * branch on it (`api_key_revoked`, `rate_limit_exceeded`, etc.) without
 * re-parsing the body. `request_id` is preserved for admin-notice
 * support-bug-report copy.
 *
 * The body's `details` and `errors` are preserved too (F3-18 / D6): a
 * wrapper-level `400 validation_failed` returns `details.fieldErrors`
 * explaining why the batch was rejected, and an endpoint may carry an
 * `errors[]` array. Earlier this class dropped everything except
 * `request_id`; keeping `details`/`errors` is the precondition for the
 * customer flusher to surface a precise reason instead of a generic
 * "http_400 validation_failed".
 */
final class ApiException extends Exception {

	private string $error_code;
	private ?string $request_id;
	/** @var array<string, mixed> */
	private array $details;
	/** @var array<int, mixed> */
	private array $errors;
	private ?int $retry_after;

	/**
	 * @param array<string, mixed> $body        Parsed JSON body from the engine response, if any.
	 * @param int|null             $retry_after A 429's `Retry-After` in seconds, when the engine sent one.
	 */
	public function __construct(
		int $http_status,
		string $error_code,
		string $message,
		array $body = array(),
		?int $retry_after = null
	) {
		parent::__construct( $message, $http_status );
		$this->error_code  = $error_code;
		$this->request_id  = isset( $body['request_id'] ) ? (string) $body['request_id'] : null;
		$this->details     = ( isset( $body['details'] ) && is_array( $body['details'] ) ) ? $body['details'] : array();
		$this->errors      = ( isset( $body['errors'] ) && is_array( $body['errors'] ) ) ? $body['errors'] : array();
		$this->retry_after = ( $retry_after !== null && $retry_after > 0 ) ? $retry_after : null;
	}

	/**
	 * The `Retry-After` seconds of a final 429, or null when the engine sent
	 * none (or the failure was not a 429). Read by a caller that only tells
	 * the admin when to try again (CON-228); the retry loop reads the header
	 * itself.
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function request_id(): ?string {
		return $this->request_id;
	}

	/**
	 * Preserved `details` object from a validation error body (e.g.
	 * `{formErrors, fieldErrors}` on a 400). Empty array when absent.
	 *
	 * @return array<string, mixed>
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * Preserved `errors[]` array from the response body, when present. Empty
	 * array when absent.
	 *
	 * @return array<int, mixed>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
