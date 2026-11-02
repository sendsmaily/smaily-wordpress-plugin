<?php
/**
 * How fast one run of a Campaign Intelligence history import sends.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\ApiException;

/**
 * One run of an import (AbstractBackfillJob::process_run(), CON-236) sends batch
 * after batch at this pace until its queue is empty, its budget is spent, or
 * the engine answers 429 — then the next run is scheduled. One request at a
 * time: each waits its turn (await_turn()), so the run never sends faster
 * than REQUESTS_PER_SECOND.
 *
 * The time arithmetic (DECISIONS CON-236): a request starts only while the
 * budget lasts, and an import request is one attempt of at most
 * REQUEST_TIMEOUT_SECONDS, so a run takes at most RUN_BUDGET_SECONDS +
 * REQUEST_TIMEOUT_SECONDS (50 s) plus the build of that last batch. The run
 * raises PHP's time limit to RUN_TIME_LIMIT_SECONDS at its start
 * (raise_time_limit()), which also restarts PHP's count.
 *
 * Not final: tests subclass it with a fake clock (now() / pause()).
 */
class ImportPace {

	/**
	 * Engine requests one import run makes in a second, at most. The engine
	 * accepts 100 a second on each ingest route (contract §2 Rate limiting,
	 * §25 sender rule 11); a store's history needs far fewer. The contract
	 * names no target pace below that ceiling — this one number changes when
	 * it does.
	 */
	public const REQUESTS_PER_SECOND = 5;

	/** How long one run starts new requests, in seconds. */
	public const RUN_BUDGET_SECONDS = 40;

	/** The whole-request bound of one import request: one attempt, no in-request retry. */
	public const REQUEST_TIMEOUT_SECONDS = 10;

	/** The PHP time limit a run asks for — over the worst case of a run with room to spare. */
	public const RUN_TIME_LIMIT_SECONDS = 90;

	/**
	 * Rows of the import's kind that may wait in the ingest queue (due now or
	 * parked for a retry) before the run reads another page (the backlog
	 * guard, DECISIONS CON-236). While the engine takes the rows, the queue
	 * holds one page; the guard stops an import that the engine does not
	 * keep up with from piling up rows, and a live row of the same kind waits
	 * behind at most these.
	 */
	public const BACKLOG_ROWS = 2000;

	/** Seconds from the end of a run to the next one, when nothing asks for longer. */
	public const NEXT_RUN_SECONDS = 30;

	/**
	 * The wait after a 429 that names none — the row back-off's first step,
	 * so the throttled rows are due again when the next run starts.
	 */
	public const THROTTLE_WAIT_SECONDS = 60;

	/** The longest wait after a 429 the run honours. */
	public const MAX_THROTTLE_WAIT_SECONDS = 3600;

	private float $deadline = 0.0;

	private float $next_request_at = 0.0;

	private bool $stopped = false;

	private ?int $wait = null;

	/** Start a run's budget. */
	public function start(): void {
		$now                   = $this->now();
		$this->deadline        = $now + self::RUN_BUDGET_SECONDS;
		$this->next_request_at = $now;
		$this->stopped         = false;
		$this->wait            = null;
	}

	/** Whether the run may send another request: not stopped, budget left. */
	public function may_send(): bool {
		return ! $this->stopped && $this->now() < $this->deadline;
	}

	/** Wait until the pace allows the next request. */
	public function await_turn(): void {
		$wait = $this->next_request_at - $this->now();
		if ( $wait > 0 ) {
			$this->pause( $wait );
		}
		$this->next_request_at = $this->now() + 1 / self::REQUESTS_PER_SECOND;
	}

	/**
	 * A request of this run failed as a whole: the run sends nothing more.
	 * A 429 sets the next run after the engine's wait; any other failure
	 * (5xx, network, a whole-batch 4xx) leaves the usual gap, so a failing
	 * engine gets no more requests a minute than before CON-236.
	 */
	public function record_failure( ApiException $e ): void {
		$this->stopped = true;
		if ( $e->getCode() === 429 ) {
			$wait       = $e->retry_after() ?? self::THROTTLE_WAIT_SECONDS;
			$this->wait = min( max( $wait, 1 ), self::MAX_THROTTLE_WAIT_SECONDS );
		}
	}

	/** End the run without a failure (the backlog guard). */
	public function stop(): void {
		$this->stopped = true;
	}

	/** Whether the engine answered 429 in this run. */
	public function throttled(): bool {
		return $this->wait !== null;
	}

	/** Seconds from now to the next run. */
	public function next_run_in(): int {
		return $this->wait ?? self::NEXT_RUN_SECONDS;
	}

	/**
	 * Give the run RUN_TIME_LIMIT_SECONDS of PHP time from here. A limit that
	 * is off (0, e.g. WP-CLI) or higher is left alone.
	 */
	public function raise_time_limit(): void {
		$current = (int) ini_get( 'max_execution_time' );
		if ( $current === 0 || $current >= self::RUN_TIME_LIMIT_SECONDS || ! function_exists( 'set_time_limit' ) ) {
			return;
		}
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A background import run; CatalogManifest and the upgrade do the same.
		set_time_limit( self::RUN_TIME_LIMIT_SECONDS );
	}

	/** @return float Seconds. */
	protected function now(): float {
		return microtime( true );
	}

	protected function pause( float $seconds ): void {
		usleep( (int) round( $seconds * 1000000 ) );
	}
}
