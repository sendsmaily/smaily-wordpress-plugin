<?php
/**
 * The import pace on a fake clock (CON-236): a pause moves the clock instead
 * of sleeping, so a test of a run's pace and budget takes no real time.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Smaily\Connect\Smaily\RecEngine\Backfill\ImportPace;

final class FakeClockImportPace extends ImportPace {

	/** Seconds since the test began. */
	public float $clock = 0.0;

	/** Seconds each request moves the clock on, as if it took that long. */
	public float $request_seconds = 0.0;

	/** @var array<int, float> Every pause, in seconds. */
	public array $pauses = array();

	/** Requests that waited their turn. */
	public int $turns = 0;

	public function await_turn(): void {
		parent::await_turn();
		++$this->turns;
		$this->clock += $this->request_seconds;
	}

	protected function now(): float {
		return $this->clock;
	}

	protected function pause( float $seconds ): void {
		$this->pauses[] = $seconds;
		$this->clock   += $seconds;
	}
}
