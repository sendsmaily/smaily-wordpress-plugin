<?php
/**
 * The contract a backfill job fulfils for the REST endpoint + AS tick.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * One row in `smly_plus_backfill_job` per (job_type, target). The legacy
 * contacts backfill (`BackfillJob`, users → Smaily) and the rec-engine
 * backfills (`AbstractBackfillJob` subclasses, WC records → ingest queue) both
 * implement this so `BackfillEndpoint` + `Bootstrap::on_backfill_tick` drive
 * them through one code path.
 */
interface BackfillJobInterface {

	/**
	 * Seed (or reset) the state row and return its id. status='running',
	 * processed_count=0, cursor cleared.
	 */
	public function start(): int;

	/**
	 * Process the next batch from the saved cursor (resumable — a crashed or
	 * timed-out tick continues where it left off, it does not restart).
	 *
	 * @return array{processed: int, remaining: int, completed: bool}
	 */
	public function process_batch(): array;
}
