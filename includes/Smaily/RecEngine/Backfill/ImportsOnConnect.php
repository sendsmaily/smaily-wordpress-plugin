<?php
/**
 * Connecting Campaign Intelligence starts the catalog, customers and orders
 * imports, one after another.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\EventQueue;

/**
 * CON-247 (supersedes PRO-3743's "customers and orders start only by hand"),
 * the Magento extension's `ImportsOnConnect` as the model: a successful setup
 * exchange starts the products import (CatalogImportOnConnect — its first
 * batch held back DELAY_SECONDS), then the customers import, then the orders
 * import. Each part starts when the one before it has ended.
 *
 * The sequence is one stored list of import types (OPTION); its first entry
 * is the part that runs now. Two things move it on (advance()):
 *   - the backfill tick, when a batch ends its import (Bootstrap::
 *     on_backfill_tick) — the next part starts at once;
 *   - the check action (HOOK), every CHECK_INTERVAL while a sequence is
 *     stored — it finds a part that stalled (PRO-3886: nothing drives it any
 *     more), which no tick will ever report.
 *
 * When a part ends:
 *   - completed, failed or stalled → the next part starts. A failed or
 *     stalled import does not hold back the ones after it, as in the Magento
 *     extension (its tick runs the next queued import; PRO-3927 sets a
 *     stalled one aside). The failed card shows its failure and Import now.
 *   - cancelled → the sequence ends: the merchant stopped it (Hold back or
 *     Cancel), so nothing else starts by itself. BackfillEndpoint's cancel
 *     ends it at once (forget()); advance() only catches a cancel it missed.
 * A part that is already running when its turn comes (an Import now) is not
 * restarted; the sequence waits for it.
 *
 * Nothing moves while sending is not allowed (RecEngineSettings::
 * sending_allowed(): a refused tenant, PRO-1893). Disconnect deletes the
 * sequence (RecEngineSettings::disconnect()); a new connection replaces it.
 * "Import now" on one card starts that import only — it never starts or
 * extends a sequence.
 *
 * A sequence that sends the orders is one §25 history import (HistoryProgress,
 * CON-248): it begins one before its first part starts, so the engine hears
 * `started` before the catalog arrives.
 *
 * A store connected before this release starts the sequence once after the
 * update (start_after_update()): only when sending is allowed, never when the
 * merchant held back or cancelled the products import, and only when its
 * customers or orders import never completed and was not cancelled. It starts
 * from the first part that never completed; a completed or cancelled part is
 * skipped, a failed or stalled one runs again (Erkki, 2026-11-02: "Respect
 * Hold back"). OPTION_UPDATE_CHECKED makes it one-time, whatever the outcome.
 *
 * CON-282: 3.21.0's code could run on a schema without migration 013, where
 * every state read and start failed — the after-update start was spent and
 * sequences were forgotten with nothing sent. So:
 *   - the check does nothing until the schema is ready (Migrator::
 *     schema_ready()); it comes back later;
 *   - a state row the database could not read, or an import that could not
 *     be started, is never taken as ended: nothing moves, nothing is
 *     forgotten, the next check tries again;
 *   - rerun_once(): a store 3.21.0 ran on whose history import never reached
 *     its end gets the whole sequence once more (Erkki, 2026-11-04).
 */
final class ImportsOnConnect {

	/** The order the imports run in. */
	public const PARTS = array( 'products', 'customers', 'orders' );

	/** The stored sequence: a list of import types, the running part first. */
	public const OPTION = 'smly_rec_import_sequence';

	/** Set once the after-update start was decided (CON-247 one-shot). */
	public const OPTION_UPDATE_CHECKED = 'smly_rec_import_sequence_update_checked';

	/** Set once the CON-282 re-run was decided (one-shot). */
	public const OPTION_RERUN_CHECKED = 'smly_rec_import_sequence_rerun_checked';

	/** The check action (Action Scheduler, group EventQueue::AS_GROUP). */
	public const HOOK = 'smly_rec_import_sequence_check';

	/** Seconds between two checks while a sequence is stored. */
	public const CHECK_INTERVAL = 900;

	/** Seconds from a part's start to its first batch. */
	public const NEXT_PART_DELAY = 5;

	/** Seconds after the first check is armed (registration on `init`). */
	public const FIRST_CHECK_DELAY = 60;

	/** One advance at a time — the tick and the check can meet. */
	private const LOCK = 'smly_rec_import_sequence_lock';

	private const LOCK_TTL = 300;

	private RecEngineSettings $settings;

	/** @var callable(string): ?AbstractBackfillJob */
	private $job_factory;

	/** @var callable(string): (array<string, mixed>|false|null) */
	private $state_reader;

	/** The §25 history import a sequence with an orders part begins (CON-248); null = none. */
	private ?HistoryProgress $history;

	/**
	 * @param callable(string): ?AbstractBackfillJob     $job_factory  The import of a type.
	 * @param (callable(string): (array<string, mixed>|false|null))|null $state_reader A type's state row (false: unreadable); default AbstractBackfillJob::read_state().
	 * @param HistoryProgress|null                       $history      Begins the history import of a sequence.
	 */
	public function __construct( RecEngineSettings $settings, callable $job_factory, ?callable $state_reader = null, ?HistoryProgress $history = null ) {
		$this->settings     = $settings;
		$this->job_factory  = $job_factory;
		$this->history      = $history;
		$this->state_reader = $state_reader ?? static function ( string $job_type ): array|false|null {
			return AbstractBackfillJob::read_state( $job_type );
		};
	}

	/**
	 * A successful setup exchange: start the products import (held back
	 * DELAY_SECONDS) and store the whole sequence behind it.
	 *
	 * @return bool True when a new products import was started, false when
	 *              one was already running (the sequence waits for it).
	 */
	public function start(): bool {
		// One history import for the whole sequence (CON-248); its first
		// report waits for the hold-back window, like the first batch.
		if ( $this->history !== null ) {
			$this->history->begin( time() + CatalogImportOnConnect::DELAY_SECONDS );
		}

		$started = ( new CatalogImportOnConnect( $this->job( self::PARTS[0] ) ) )->start();

		$this->save( self::PARTS );
		$this->schedule_check();

		return $started;
	}

	/**
	 * The check action: the one-time after-update start, the one-time CON-282
	 * re-run, then advance(), then the next check while a sequence remains.
	 * Before the update reaches the schema it only comes back later.
	 */
	public function run_check(): void {
		if ( ! Migrator::schema_ready() ) {
			$this->schedule_next_check();
			return;
		}

		// Read before start_after_update() decides it in this very check.
		$update_spent = (string) get_option( self::OPTION_UPDATE_CHECKED, '' ) !== '';
		$this->start_after_update();
		$this->rerun_once( $update_spent );
		$this->advance();
		$this->schedule_check();
	}

	/**
	 * A store connected before CON-247 starts the sequence once (see the
	 * class docblock).
	 *
	 * @return bool True when this call started a sequence.
	 */
	public function start_after_update(): bool {
		if ( (string) get_option( self::OPTION_UPDATE_CHECKED, '' ) !== '' ) {
			return false;
		}

		if ( ! $this->settings->sending_allowed() || $this->stored() !== array() ) {
			update_option( self::OPTION_UPDATE_CHECKED, '1', false );
			return false;
		}

		// A state row the database could not read decides nothing: the next
		// check decides (CON-282).
		$statuses = $this->last_statuses();
		if ( $statuses === null ) {
			return false;
		}
		// Written before anything starts: the decision is made once.
		update_option( self::OPTION_UPDATE_CHECKED, '1', false );

		// Hold back promised that nothing is sent: a store whose merchant
		// held back or cancelled the catalog import gets no import at all.
		if ( $statuses[ self::PARTS[0] ] === BackfillJobInterface::STATUS_CANCELLED ) {
			return false;
		}

		// The parts nobody finished and nobody stopped: a completed or a
		// cancelled part is skipped; a failed, stalled or never-run one runs.
		$parts = array();
		foreach ( self::PARTS as $part ) {
			$status = $statuses[ $part ];
			if ( $status !== BackfillJobInterface::STATUS_COMPLETED && $status !== BackfillJobInterface::STATUS_CANCELLED ) {
				$parts[] = $part;
			}
		}

		if ( array_intersect( $parts, array( 'customers', 'orders' ) ) === array() ) {
			return false;
		}

		if ( ! $this->acquire_lock() ) {
			return false;
		}
		try {
			// A sequence that sends the orders is a history import (CON-248),
			// begun before any of its parts starts.
			if ( $this->history !== null && in_array( 'orders', $parts, true ) ) {
				$this->history->begin( time() + self::NEXT_PART_DELAY );
			}
			$started = $this->start_part( $parts[0] );
			if ( $started === null ) {
				// It could not start (CON-282): the next check decides again.
				delete_option( self::OPTION_UPDATE_CHECKED );
			}
			if ( $started !== true ) {
				return false;
			}
			$this->save( $parts );
		} finally {
			$this->release_lock();
		}

		return true;
	}

	/**
	 * CON-282, approved by Erkki 2026-11-04: a store 3.21.0 ran on — its
	 * after-update start was already spent ($update_spent) — whose history
	 * import never reached its end starts the whole sequence once more, by
	 * itself. "Never reached its end": no orders run of this schema (one that
	 * counts `sent_count`, which 3.21.0 added) completed. Nothing is sent
	 * while sending is not allowed, and a merchant's cancel or hold-back of
	 * any part is respected. While a sequence waits or any import runs,
	 * nothing is decided — the check comes back, so no second chain runs
	 * beside it. A state row the database could not read decides nothing
	 * either. OPTION_RERUN_CHECKED makes it one-time.
	 *
	 * @return bool True when this call started the sequence.
	 */
	public function rerun_once( bool $update_spent ): bool {
		if ( (string) get_option( self::OPTION_RERUN_CHECKED, '' ) !== '' ) {
			return false;
		}

		// A store 3.21.0 never ran on — its after-update start is decided by
		// this release — or one that cannot send gets nothing.
		if ( ! $update_spent || ! $this->settings->sending_allowed() ) {
			update_option( self::OPTION_RERUN_CHECKED, '1', false );
			return false;
		}

		if ( $this->stored() !== array() ) {
			return false;
		}

		$rows = array();
		foreach ( self::PARTS as $part ) {
			$row = ( $this->state_reader )( $part );
			if ( $row === false || $this->live( $part, $row ) ) {
				return false;
			}
			$rows[ $part ] = $row;
		}

		if ( ! self::history_unfinished( $rows ) ) {
			update_option( self::OPTION_RERUN_CHECKED, '1', false );
			return false;
		}

		if ( ! $this->acquire_lock() ) {
			return false;
		}
		try {
			if ( $this->history !== null ) {
				$this->history->begin( time() + self::NEXT_PART_DELAY );
			}
			if ( $this->start_part( self::PARTS[0] ) !== true ) {
				return false;
			}
			$this->save( self::PARTS );
			update_option( self::OPTION_RERUN_CHECKED, '1', false );
		} finally {
			$this->release_lock();
		}

		return true;
	}

	/**
	 * Move the sequence on when its running part has ended.
	 */
	public function advance(): void {
		if ( $this->stored() === array() || ! $this->settings->sending_allowed() || ! $this->acquire_lock() ) {
			return;
		}

		try {
			$parts   = $this->stored();
			$current = $parts[0] ?? '';
			if ( $current === '' ) {
				return;
			}

			$row = ( $this->state_reader )( $current );
			// Unreadable (CON-282) or still running: nothing has ended yet.
			if ( $row === false || $this->live( $current, $row ) ) {
				return;
			}

			if ( is_array( $row ) && (string) ( $row['status'] ?? '' ) === BackfillJobInterface::STATUS_CANCELLED ) {
				self::forget();
				return;
			}

			array_shift( $parts );
			$started = $parts === array() ? false : $this->start_part( $parts[0] );
			if ( $started === null ) {
				// The next part could not start (CON-282): the sequence stays
				// as it is, and the next check tries again.
				return;
			}
			if ( ! $started ) {
				self::forget();
				return;
			}

			$this->save( $parts );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Arm the check when the one-time after-update start is still to decide,
	 * or a sequence waits for a part to end. Called from the hourly-verified
	 * recurring-job registration, so a deactivate/reactivate re-arms it.
	 */
	public function ensure_check_scheduled(): void {
		$pending_update = (string) get_option( self::OPTION_UPDATE_CHECKED, '' ) === ''
			|| (string) get_option( self::OPTION_RERUN_CHECKED, '' ) === '';
		$waiting        = $this->stored() !== array() && $this->settings->sending_allowed();
		if ( ( ! $pending_update && ! $waiting ) || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( as_next_scheduled_action( self::HOOK, array(), EventQueue::AS_GROUP ) !== false ) {
			return;
		}

		as_schedule_single_action( time() + self::FIRST_CHECK_DELAY, self::HOOK, array(), EventQueue::AS_GROUP );
	}

	/**
	 * End the sequence: nothing more starts by itself. The import that runs
	 * now is left alone.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The stored sequence, the running part first; empty when none.
	 *
	 * @return string[]
	 */
	public function stored(): array {
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_intersect( array_map( 'strval', $value ), self::PARTS ) );
	}

	/**
	 * Start one part, unless it already runs: then the sequence waits for it.
	 *
	 * @return bool|null True when it runs now; false when there is no job to
	 *                   start; null when its state row could not be read or
	 *                   written (CON-282) — no batch is scheduled, and the
	 *                   caller waits for the next check.
	 */
	private function start_part( string $job_type ): ?bool {
		$job = $this->job( $job_type );
		if ( $job === null || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		$row = ( $this->state_reader )( $job_type );
		if ( $row === false ) {
			return null;
		}
		if ( $this->live( $job_type, $row ) ) {
			return true;
		}

		if ( $job->start() === 0 ) {
			return null;
		}

		$args = array( 'job_type' => $job_type );
		if ( ! as_has_scheduled_action( BackfillJobInterface::TICK_HOOK, $args, EventQueue::AS_GROUP ) ) {
			as_schedule_single_action( time() + self::NEXT_PART_DELAY, BackfillJobInterface::TICK_HOOK, $args, EventQueue::AS_GROUP );
		}

		return true;
	}

	/**
	 * The next check, CHECK_INTERVAL out, while a sequence waits — unless one
	 * is already pending. The check that runs now is in progress, not
	 * pending, so it re-arms itself.
	 */
	private function schedule_check(): void {
		$rerun_pending = (string) get_option( self::OPTION_RERUN_CHECKED, '' ) === '';
		if ( ( $this->stored() === array() && ! $rerun_pending ) || ! $this->settings->sending_allowed() ) {
			return;
		}

		$this->schedule_next_check();
	}

	/**
	 * The next check, CHECK_INTERVAL out, unless one is already pending.
	 */
	private function schedule_next_check(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( is_int( as_next_scheduled_action( self::HOOK, array(), EventQueue::AS_GROUP ) ) ) {
			return;
		}

		as_schedule_single_action( time() + self::CHECK_INTERVAL, self::HOOK, array(), EventQueue::AS_GROUP );
	}

	/**
	 * Whether a state row is an import that runs now (queued or mid-walk) and
	 * has not stalled.
	 *
	 * @param array<string, mixed>|false|null $row
	 */
	private function live( string $job_type, array|false|null $row ): bool {
		return is_array( $row )
			&& ( $row['status'] ?? '' ) === BackfillJobInterface::STATUS_RUNNING
			&& ! AbstractBackfillJob::is_stalled( $job_type, $row );
	}

	/**
	 * Each part's last status ('' when it never ran), or null when a state
	 * row could not be read (CON-282).
	 *
	 * @return array<string, string>|null
	 */
	private function last_statuses(): ?array {
		$statuses = array();
		foreach ( self::PARTS as $part ) {
			$row = ( $this->state_reader )( $part );
			if ( $row === false ) {
				return null;
			}
			$statuses[ $part ] = is_array( $row ) ? (string) ( $row['status'] ?? '' ) : '';
		}

		return $statuses;
	}

	/**
	 * The CON-282 re-run rule over the three parts' state rows: no part was
	 * cancelled (Hold back and Cancel are kept), and no orders run of this
	 * schema — one with a `sent_count` — completed.
	 *
	 * @param array<string, array<string, mixed>|null> $rows
	 */
	private static function history_unfinished( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['status'] ?? '' ) === BackfillJobInterface::STATUS_CANCELLED ) {
				return false;
			}
		}

		$orders = $rows['orders'] ?? null;

		return ! ( is_array( $orders )
			&& (string) ( $orders['status'] ?? '' ) === BackfillJobInterface::STATUS_COMPLETED
			&& isset( $orders['sent_count'] ) );
	}

	private function job( string $job_type ): ?AbstractBackfillJob {
		$job = ( $this->job_factory )( $job_type );

		return $job instanceof AbstractBackfillJob ? $job : null;
	}

	/**
	 * @param string[] $parts
	 */
	private function save( array $parts ): void {
		update_option( self::OPTION, array_values( $parts ), false );
	}

	private function acquire_lock(): bool {
		if ( add_option( self::LOCK, (string) time(), '', false ) ) {
			return true;
		}

		$held_since = (int) get_option( self::LOCK, 0 );
		if ( $held_since > 0 && ( time() - $held_since ) < self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK );

		return (bool) add_option( self::LOCK, (string) time(), '', false );
	}

	private function release_lock(): void {
		delete_option( self::LOCK );
	}
}
