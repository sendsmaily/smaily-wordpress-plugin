<?php
/**
 * Raised when a Flusher dispatch is non-retryable.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Distinct from ApiException: a retry won't change the outcome.
 *
 * Examples:
 *   - unknown event_type (the queue contains a row we don't know how to
 *     dispatch — a code-version mismatch on rolling deploys)
 *   - payload couldn't be decoded
 *
 * Flusher catches this separately to call mark_failed() instead of
 * record_attempt(), so the row exits the retry loop immediately.
 */
final class TerminalDispatchException extends \RuntimeException {
}
