<?php
/**
 * RetryPolicy — the permanent/temporary split and the spacing it picks.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RetryPolicy;

final class RetryPolicyTest extends TestCase {

	/**
	 * @dataProvider permanent_statuses
	 */
	public function test_a_refusal_that_cannot_succeed_is_permanent( int $status ): void {
		self::assertTrue( RetryPolicy::is_permanent( new ApiException( 'nope', $status ) ) );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function permanent_statuses(): array {
		return array(
			'unauthorized'      => array( 401 ),
			'forbidden'         => array( 403 ),
			'not found'         => array( 404 ),
			'unprocessable'     => array( 422 ),
			'other client side' => array( 400 ),
		);
	}

	/**
	 * @dataProvider temporary_statuses
	 */
	public function test_a_failure_that_could_succeed_later_is_temporary( int $status ): void {
		self::assertFalse( RetryPolicy::is_permanent( new ApiException( 'later', $status ) ) );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function temporary_statuses(): array {
		return array(
			// The bias: anything not recognisably a permanent refusal retries,
			// including a transport error that never received a status.
			'transport error' => array( 0 ),
			'rate limited'    => array( 429 ),
			'server error'    => array( 500 ),
			'bad gateway'     => array( 502 ),
			'unavailable'     => array( 503 ),
		);
	}

	public function test_backoff_follows_the_written_ladder_not_a_fixed_minute(): void {
		self::assertSame( 60, RetryPolicy::delay_seconds( 1 ) );
		self::assertSame( 300, RetryPolicy::delay_seconds( 2 ) );
		self::assertSame( 900, RetryPolicy::delay_seconds( 3 ) );
		self::assertSame( 3600, RetryPolicy::delay_seconds( 4 ) );
		self::assertSame( 21600, RetryPolicy::delay_seconds( 5 ) );
		self::assertSame( 21600, RetryPolicy::delay_seconds( 99 ), 'The ladder tops out, it does not overflow.' );
	}

	public function test_a_retry_after_from_smaily_wins_over_the_ladder(): void {
		self::assertSame( 1800, RetryPolicy::delay_seconds( 1, 1800 ) );
		self::assertSame( 21600, RetryPolicy::delay_seconds( 1, 999999 ), 'A wild Retry-After is capped.' );
		self::assertSame( 60, RetryPolicy::delay_seconds( 1, 0 ), 'A zero/absent Retry-After falls back to the ladder.' );
	}

	public function test_a_permanent_refusal_stops_being_retried_and_is_recorded_as_failed(): void {
		$queue = $this->fake_queue();

		$outcome = RetryPolicy::apply( $queue, 11, 0, new ApiException( 'Smaily API returned HTTP 401 for POST contact', 401 ) );

		self::assertSame( 'failed', $outcome );
		self::assertSame( array(), $queue->attempts, 'A permanent refusal must not consume retry attempts.' );
		self::assertCount( 1, $queue->marked_failed );
		self::assertSame( 11, $queue->marked_failed[0]['id'] );
		self::assertStringContainsString( 'permanent_http_401', $queue->marked_failed[0]['error'] );
		self::assertStringContainsString( 'HTTP 401', $queue->marked_failed[0]['error'], 'The Event Log reason keeps the underlying message.' );
	}

	public function test_a_temporary_failure_is_parked_for_a_spaced_retry(): void {
		$queue = $this->fake_queue();

		$outcome = RetryPolicy::apply( $queue, 12, 1, new ApiException( 'Smaily API returned HTTP 500 for POST contact', 500 ) );

		self::assertSame( 'retried', $outcome );
		self::assertSame( array(), $queue->marked_failed );
		self::assertCount( 1, $queue->attempts );
		self::assertSame( 300, $queue->attempts[0]['retry_in_seconds'], 'Second attempt waits 5 minutes, not a minute.' );
	}

	public function test_a_rate_limit_waits_as_instructed(): void {
		$queue = $this->fake_queue();

		RetryPolicy::apply( $queue, 13, 0, new ApiException( 'Smaily API returned HTTP 429 for POST contact', 429, 120 ) );

		self::assertSame( 120, $queue->attempts[0]['retry_in_seconds'] );
	}

	public function test_the_last_allowed_attempt_fails_the_row_with_the_reason(): void {
		$queue = $this->fake_queue();

		$outcome = RetryPolicy::apply(
			$queue,
			14,
			RetryPolicy::MAX_ATTEMPTS - 1,
			new ApiException( 'Smaily API returned HTTP 503 for POST contact', 503 )
		);

		self::assertSame( 'failed', $outcome );
		self::assertSame( array(), $queue->attempts, 'Past the ceiling the row is given up on, not parked again.' );
		self::assertStringContainsString( 'retry_limit_exceeded after 5 attempts', $queue->marked_failed[0]['error'] );
		self::assertStringContainsString( 'HTTP 503', $queue->marked_failed[0]['error'] );
	}

	private function fake_queue(): EventQueue {
		return new class() extends EventQueue {
			/** @var array<int, array<string, mixed>> */
			public array $marked_failed = array();

			/** @var array<int, array<string, mixed>> */
			public array $attempts = array();

			public function __construct() {}

			public function mark_failed( int $id, string $error ): void {
				$this->marked_failed[] = compact( 'id', 'error' );
			}

			public function record_attempt( int $id, string $error, int $retry_in_seconds = 0 ): void {
				$this->attempts[] = compact( 'id', 'error', 'retry_in_seconds' );
			}
		};
	}
}
