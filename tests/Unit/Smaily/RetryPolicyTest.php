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
use Smaily\Connect\Smaily\TerminalDispatchException;

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

	public function test_an_invalid_data_envelope_is_permanent_with_smailys_answer(): void {
		// PRO-3750: HTTP 200 with Smaily code 203 — the same data is rejected
		// again, so the reason names the class and keeps Smaily's answer.
		$reason = RetryPolicy::permanent_envelope(
			array(
				'request'  => array(),
				'response' => array(
					'http' => 200,
					'body' => array(
						'code'    => 203,
						'message' => 'Invalid data',
					),
				),
			)
		);

		self::assertSame( 'permanent_envelope_203: Smaily API returned code 203: Invalid data', $reason );
	}

	/**
	 * PRO-3862: every Smaily body code other than 101 fails the row at once
	 * with Smaily's message — each code of the response-code table
	 * (https://smaily.com/help/api/general/response-codes/) except 225, and a
	 * code the table does not list.
	 *
	 * @dataProvider permanent_envelope_codes
	 */
	public function test_a_refusing_body_code_fails_the_row_with_smailys_message( int $code ): void {
		$exchange = $this->exchange_answering( $code, 'Smaily said no' );

		$expected = sprintf( 'permanent_envelope_%1$d: Smaily API returned code %1$d: Smaily said no', $code );
		self::assertSame( $expected, RetryPolicy::permanent_envelope( $exchange ) );

		$this->expectException( TerminalDispatchException::class );
		$this->expectExceptionMessage( $expected );
		RetryPolicy::throw_if_refused_envelope( $exchange );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function permanent_envelope_codes(): array {
		$codes = array( 201, 203, 204, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215, 216, 217, 218, 219, 220, 221, 223, 224, 226, 227 );

		$cases = array();
		foreach ( $codes as $code ) {
			$cases[ (string) $code ] = array( $code );
		}
		$cases['a code Smaily does not list'] = array( 299 );

		return $cases;
	}

	public function test_a_database_insert_failure_is_retried_not_failed(): void {
		// PRO-3862: 225 "Database insert failed" is Smaily's own database
		// error — the same data can pass later, so it takes the retry ladder.
		$exchange = $this->exchange_answering( 225, 'Database insert failed' );

		self::assertNull( RetryPolicy::permanent_envelope( $exchange ) );

		try {
			RetryPolicy::throw_if_refused_envelope( $exchange );
			self::fail( 'A refusing body code must never pass as sent.' );
		} catch ( ApiException $e ) {
			self::assertSame( 'Smaily API returned code 225: Database insert failed', $e->getMessage() );
			self::assertSame( 225, $e->smaily_code() );
			self::assertFalse( RetryPolicy::is_permanent( $e ) );

			$queue = $this->fake_queue();
			self::assertSame( 'retried', RetryPolicy::apply( $queue, 15, 0, $e ) );
			self::assertSame( array(), $queue->marked_failed );
		}
	}

	/**
	 * @dataProvider not_refusing_replies
	 *
	 * @param array<string, mixed>|null $exchange
	 */
	public function test_a_reply_without_a_refusing_body_code_passes( ?array $exchange ): void {
		self::assertNull( RetryPolicy::permanent_envelope( $exchange ) );

		RetryPolicy::throw_if_refused_envelope( $exchange );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function not_refusing_replies(): array {
		return array(
			'success'             => array( array( 'response' => array( 'http' => 200, 'body' => array( 'code' => 101, 'message' => 'OK' ) ) ) ),
			'no body code'        => array( array( 'response' => array( 'http' => 200, 'body' => array() ) ) ),
			'transport error'     => array( array( 'response' => array( 'error' => 'timed out' ) ) ),
			'nothing was sent'    => array( null ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function exchange_answering( int $code, string $message ): array {
		return array(
			'request'  => array(),
			'response' => array(
				'http' => 200,
				'body' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
		);
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
