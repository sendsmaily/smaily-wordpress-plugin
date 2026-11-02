<?php
/**
 * ImportPace (CON-236) — one import run sends at most REQUESTS_PER_SECOND,
 * starts no request after its budget, and a whole-request failure ends it:
 * a 429 with the engine's wait as the gap to the next run.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Backfill\ImportPace;
use Smaily\Connect\Tests\Unit\Support\FakeClockImportPace;

final class ImportPaceTest extends TestCase {

	public function test_the_pace_and_the_budget_are_the_decided_numbers(): void {
		self::assertSame( 5, ImportPace::REQUESTS_PER_SECOND );
		self::assertSame( 40, ImportPace::RUN_BUDGET_SECONDS );
		self::assertSame( 2000, ImportPace::BACKLOG_ROWS );
		self::assertLessThan(
			60,
			ImportPace::RUN_BUDGET_SECONDS + ImportPace::REQUEST_TIMEOUT_SECONDS,
			'The budget plus one request stays under a 60 s host limit.'
		);
		self::assertGreaterThan( ImportPace::RUN_BUDGET_SECONDS + ImportPace::REQUEST_TIMEOUT_SECONDS, ImportPace::RUN_TIME_LIMIT_SECONDS );
	}

	public function test_requests_are_spaced_to_five_a_second(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		for ( $i = 0; $i < 6; $i++ ) {
			$pace->await_turn();
		}

		self::assertEqualsWithDelta( 1.0, $pace->clock, 0.0001, 'Six requests that take no time start over one second.' );
		self::assertCount( 5, $pace->pauses, 'The first request goes at once.' );
		foreach ( $pace->pauses as $pause ) {
			self::assertEqualsWithDelta( 0.2, $pause, 0.0001 );
		}
	}

	public function test_a_slow_request_needs_no_wait_before_the_next(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		$pace->await_turn();
		$pace->clock += 0.5; // The request took half a second.
		$pace->await_turn();

		self::assertSame( array(), $pace->pauses );
	}

	public function test_no_request_starts_after_the_budget(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		$pace->clock = ImportPace::RUN_BUDGET_SECONDS - 0.01;
		self::assertTrue( $pace->may_send() );

		$pace->clock = ImportPace::RUN_BUDGET_SECONDS;
		self::assertFalse( $pace->may_send() );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $pace->next_run_in(), 'A spent budget keeps the usual gap.' );
	}

	public function test_a_429_ends_the_run_and_the_next_run_waits_what_the_engine_asked(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		$pace->record_failure( new ApiException( 429, 'rate_limit_exceeded', 'slow down', array(), 17 ) );

		self::assertFalse( $pace->may_send() );
		self::assertTrue( $pace->throttled() );
		self::assertSame( 17, $pace->next_run_in() );
	}

	public function test_a_429_without_a_wait_waits_the_first_row_back_off(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		$pace->record_failure( new ApiException( 429, 'rate_limit_exceeded', 'slow down' ) );

		self::assertSame( ImportPace::THROTTLE_WAIT_SECONDS, $pace->next_run_in() );
	}

	public function test_an_engine_asking_for_more_than_an_hour_gets_an_hour(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		$pace->record_failure( new ApiException( 429, 'rate_limit_exceeded', 'slow down', array(), 86400 ) );

		self::assertSame( ImportPace::MAX_THROTTLE_WAIT_SECONDS, $pace->next_run_in() );
	}

	public function test_another_failure_ends_the_run_with_the_usual_gap(): void {
		$pace = new FakeClockImportPace();
		$pace->start();

		$pace->record_failure( new ApiException( 503, 'unavailable', 'down' ) );

		self::assertFalse( $pace->may_send() );
		self::assertFalse( $pace->throttled() );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $pace->next_run_in() );
	}

	public function test_a_new_start_clears_the_last_runs_stop(): void {
		$pace = new FakeClockImportPace();
		$pace->start();
		$pace->record_failure( new ApiException( 429, 'rate_limit_exceeded', 'slow down', array(), 17 ) );

		$pace->start();

		self::assertTrue( $pace->may_send() );
		self::assertSame( ImportPace::NEXT_RUN_SECONDS, $pace->next_run_in() );
	}
}
