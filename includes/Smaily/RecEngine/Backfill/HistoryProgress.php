<?php
/**
 * Where the store's history import to Campaign Intelligence stands, for the
 * engine (contract v1.20.0 §25) and for the Orders card.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Support\DebugLog;

/**
 * CON-248, the Magento extension's `HistoryProgress` + `HistoryImport` as
 * the model. One history import is one `import_id`:
 *   - a connection's sequence (ImportsOnConnect: products → customers →
 *     orders) begins one, before its first import starts, so the engine
 *     hears `started` before the catalog;
 *   - the Orders card's Import now begins a new one ("send history again",
 *     §25 rule 5). Import now on the Products or Customers card does not:
 *     it sends no order history, and a new id would wait at `started`
 *     for orders that never come.
 * The import's orders part is the orders import run that started at or
 * after the import began; the window it walks (OrderBackfillJob's cursor)
 * is the import's window.
 *
 * Phases count rows the engine accepted (ImportRows, `sent_count`), never
 * rows only queued:
 *   - `started` until the orders part has begun and its start part is in;
 *   - `start_part_complete` once every start-part order row is delivered
 *     (none waits with an id up to the cursor's `start_part_queued_to`),
 *     the latest catalog and customers imports are not running, and none
 *     of their rows waits. A row the engine refused for good does not hold
 *     a phase back, nor does a failed catalog or customers import (CON-247:
 *     a failure does not hold back the imports after it). Once reached it
 *     stays reached;
 *   - `complete` once the orders import has completed and none of its rows
 *     waits.
 *
 * The check action (HOOK, group AS_GROUP) runs every CHECK_EVERY_SECONDS
 * while the import is in progress: a phase change is reported at once, the
 * counts at most every REPORT_EVERY_SECONDS within a phase. A report that
 * fails is logged and tried again REPORT_EVERY_SECONDS later; it never
 * stops or slows the import — it runs on its own action, one engine
 * attempt bounded by a short timeout (Client::history_progress()).
 *
 * The import ends — no more reports, no more checks — once `complete` is
 * in, when its orders import was cancelled, failed or stalled, or when its
 * orders part never started and no sequence waits to start it (Hold back,
 * a cancel). Nothing is sent while sending is not allowed (PRO-1893), and
 * Hold back keeps its promise: the first report of a connection waits for
 * the hold-back window, and a held-back import ends without one.
 *
 * CON-282: before the update reaches the schema (Migrator::schema_ready()),
 * or while an import's state row cannot be read, the check decides and
 * reports nothing — it never ends the import on what it could not read; it
 * comes back at the next check.
 *
 * Not final: tests subclass to stub the order counts and the stall check.
 */
class HistoryProgress {

	public const OPTION = 'smly_rec_history_import';

	public const HOOK = 'smly_rec_history_progress';

	public const AS_GROUP = 'smaily-rec-history-progress';

	public const PHASE_STARTED = 'started';

	public const PHASE_START_PART_COMPLETE = 'start_part_complete';

	public const PHASE_COMPLETE = 'complete';

	/** At most this often within a phase (§25 "When to call"), and after a failed call. */
	public const REPORT_EVERY_SECONDS = 300;

	/** How often the check runs while the import is in progress. */
	public const CHECK_EVERY_SECONDS = 60;

	/** The queue's event types of the imports ahead of the orders part. */
	private const BEFORE_ORDERS = array(
		'products'  => array( CatalogHookHandler::EVENT_CATALOG_UPSERT, CatalogHookHandler::EVENT_CATALOG_DELETE ),
		'customers' => array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ),
	);

	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	private IngestQueue $queue;

	/** @var callable(string): (array<string, mixed>|false|null) */
	private $state_reader;

	/**
	 * @param callable(): Client                              $client_factory The engine client the report goes through.
	 * @param (callable(string): (array<string, mixed>|false|null))|null $state_reader An import's state row (false: unreadable); default AbstractBackfillJob::read_state().
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory, IngestQueue $queue, ?callable $state_reader = null ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->queue          = $queue;
		$this->state_reader   = $state_reader ?? static function ( string $job_type ): array|false|null {
			return AbstractBackfillJob::read_state( $job_type );
		};
	}

	/**
	 * Begin a new history import and arm its first check at $first_check_at.
	 * Call it BEFORE the orders import of this history import starts: the
	 * orders run counts as this import's when it started at or after now.
	 *
	 * @return string The new import id.
	 */
	public function begin( int $first_check_at ): string {
		$now     = $this->now();
		$history = HistoryConfig::stored( $this->settings );
		$window  = OrderBackfillJob::window( $history, $now );
		$id      = sprintf( 'woo-%s-%s', gmdate( 'Ymd\THis\Z', $now ), bin2hex( random_bytes( 3 ) ) );

		update_option(
			self::OPTION,
			array(
				'import_id'       => $id,
				'begun_at'        => gmdate( 'Y-m-d H:i:s', $now ),
				'orders_run'      => '',
				'until'           => $window['until'],
				'start_from'      => $window['start_from'],
				'horizon'         => $window['horizon'],
				'start_months'    => $history['start_months'],
				'horizon_months'  => $history['horizon_months'],
				'start_expected'  => null,
				'buyers_expected' => null,
				'buyers_horizon'  => '',
				'start_part_done' => false,
				'reported_phase'  => '',
				'reported_at'     => 0,
				'failed_at'       => 0,
				'ended'           => false,
			),
			false
		);
		$this->schedule( $first_check_at, true );

		return $id;
	}

	/**
	 * The check action: report when due, and arm the next check while the
	 * import is in progress. Never throws.
	 */
	public function run(): void {
		try {
			if ( $this->check() ) {
				$this->schedule( $this->now() + self::CHECK_EVERY_SECONDS );
			} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
				// Nothing more to report: no check is left behind.
				as_unschedule_all_actions( self::HOOK, array(), self::AS_GROUP );
			}
		} catch ( \Throwable $e ) {
			DebugLog::write( sprintf( '[smaily-connect history.progress] check failed: %s at %s:%d', get_class( $e ), basename( $e->getFile() ), $e->getLine() ) );
		}
	}

	/**
	 * Arm the check when an import is in progress and none is queued.
	 * Called from the hourly-verified recurring-job registration, so a
	 * deactivate/reactivate re-arms it.
	 */
	public function ensure_scheduled(): void {
		$state = $this->state();
		if ( $state === null || $state['ended'] || $state['reported_phase'] === self::PHASE_COMPLETE || ! $this->settings->sending_allowed() ) {
			return;
		}

		$this->schedule( $this->now() + self::CHECK_EVERY_SECONDS );
	}

	/**
	 * What the Orders card shows of this orders run: the part being sent,
	 * how much of it the engine has, and the window's months; null when the
	 * run is not the current history import's orders part.
	 *
	 * @param array<string, mixed>|null $orders The orders import's state row.
	 * @return array{part: string, sent: int, expected: int, percent: int, start_months: int, horizon_months: int}|null
	 */
	public function card( ?array $orders ): ?array {
		$state = $this->state();
		if ( $state === null || ! $this->is_ours( $state, $orders ) || $orders === null ) {
			return null;
		}

		$state = $this->with_orders_window( $state, $orders );
		$sent  = (int) ( $orders['sent_count'] ?? 0 );
		if ( $this->phase( $state, $orders ) === self::PHASE_STARTED ) {
			$part     = 'start';
			$expected = (int) $state['start_expected'];
			$sent     = min( $sent, $expected );
		} else {
			$part     = 'background';
			$expected = (int) ( $orders['total_count'] ?? 0 );
		}

		return array(
			'part'           => $part,
			'sent'           => $sent,
			'expected'       => $expected,
			'percent'        => $expected > 0 ? (int) floor( min( 100, $sent / $expected * 100 ) ) : 100,
			'start_months'   => (int) $state['start_months'],
			'horizon_months' => (int) $state['horizon_months'],
		);
	}

	/**
	 * The current (or last) history import, null when none has begun.
	 *
	 * @return array<string, mixed>|null
	 */
	public function state(): ?array {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || ! is_string( $state['import_id'] ?? null ) || ! is_string( $state['begun_at'] ?? null ) ) {
			return null;
		}

		return array_merge(
			array(
				'orders_run'      => '',
				'until'           => '',
				'start_from'      => '',
				'horizon'         => '',
				'start_months'    => HistoryConfig::FALLBACK['start_months'],
				'horizon_months'  => HistoryConfig::FALLBACK['horizon_months'],
				'start_expected'  => null,
				'buyers_expected' => null,
				'buyers_horizon'  => '',
				'start_part_done' => false,
				'reported_phase'  => '',
				'reported_at'     => 0,
				'failed_at'       => 0,
				'ended'           => false,
			),
			$state
		);
	}

	/**
	 * Forget the history import (disconnect): nothing more is reported.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::AS_GROUP );
		}
	}

	// --- seams (tests override) ---------------------------------------------

	protected function now(): int {
		return time();
	}

	protected function count_orders( string $from, string $until ): int {
		return OrderBackfillJob::count_orders( $from, $until );
	}

	protected function count_buyers( string $from, string $until ): int {
		return OrderBackfillJob::count_buyers( $from, $until );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	protected function is_stalled( string $job_type, array $row ): bool {
		return AbstractBackfillJob::is_stalled( $job_type, $row );
	}

	// --- the check ------------------------------------------------------------

	/**
	 * Report when due. True while the import is in progress (the next check
	 * is wanted).
	 */
	private function check(): bool {
		$state = $this->state();
		if ( $state === null || $state['ended'] || $state['reported_phase'] === self::PHASE_COMPLETE || ! $this->settings->sending_allowed() ) {
			return false;
		}

		// Not readable yet (CON-282): wait for the next check.
		if ( ! Migrator::schema_ready() ) {
			return true;
		}
		foreach ( array( 'orders', 'products', 'customers' ) as $job_type ) {
			if ( ( $this->state_reader )( $job_type ) === false ) {
				return true;
			}
		}

		$orders = ( $this->state_reader )( 'orders' );
		if ( $this->is_ours( $state, $orders ) && is_array( $orders ) ) {
			$status = (string) ( $orders['status'] ?? '' );
			if ( $status === BackfillJobInterface::STATUS_CANCELLED || $status === BackfillJobInterface::STATUS_FAILED || $this->is_stalled( 'orders', $orders ) ) {
				$this->end( $state );
				return false;
			}
			$state = $this->with_orders_window( $state, $orders );
		} else {
			$orders = null;
			if ( ! $this->sequence_waits_for_orders() ) {
				// Held back or cancelled before its orders part began.
				$this->end( $state );
				return false;
			}
		}

		if ( $state['buyers_horizon'] !== $state['horizon'] ) {
			$state['buyers_expected'] = $this->count_buyers( (string) $state['horizon'], (string) $state['until'] );
			$state['buyers_horizon']  = $state['horizon'];
		}

		$phase = $this->phase( $state, $orders );
		if ( $phase !== self::PHASE_STARTED ) {
			$state['start_part_done'] = true;
		}

		$now = $this->now();
		$due = $now - (int) $state['failed_at'] >= self::REPORT_EVERY_SECONDS
			&& ( $phase !== $state['reported_phase'] || $now - (int) $state['reported_at'] >= self::REPORT_EVERY_SECONDS );
		if ( $due ) {
			try {
				( $this->client_factory )()->history_progress( $this->body( $state, $orders, $phase ) );
				$state['reported_phase'] = $phase;
				$state['reported_at']    = $now;
				$state['failed_at']      = 0;
			} catch ( \Throwable $e ) {
				$state['failed_at'] = $now;
				DebugLog::write(
					sprintf(
						'[smaily-connect history.progress] report failed import=%s phase=%s: %s',
						(string) $state['import_id'],
						$phase,
						$e instanceof \Smaily\Connect\Smaily\RecEngine\ApiException ? sprintf( 'http=%d error=%s', $e->getCode(), $e->error_code() ) : get_class( $e )
					)
				);
			}
		}

		update_option( self::OPTION, $state, false );

		return $state['reported_phase'] !== self::PHASE_COMPLETE;
	}

	/**
	 * Whether this orders import run is the history import's orders part:
	 * it started at or after the import began.
	 *
	 * @param array<string, mixed>      $state
	 * @param array<string, mixed>|null $orders
	 */
	private function is_ours( array $state, ?array $orders ): bool {
		if ( ! is_array( $orders ) ) {
			return false;
		}
		$started = (string) ( $orders['started_at'] ?? '' );

		return $started !== '' && strcmp( $started, (string) $state['begun_at'] ) >= 0;
	}

	/**
	 * The state with its window taken from the orders run's cursor — the
	 * window that run walks — and the start part's order count for it.
	 *
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $orders
	 * @return array<string, mixed>
	 */
	private function with_orders_window( array $state, array $orders ): array {
		$run = (string) ( $orders['started_at'] ?? '' );
		if ( $state['orders_run'] === $run ) {
			return $state;
		}

		$window = OrderBackfillJob::decode_window( (string) ( $orders['cursor_value'] ?? '' ) );
		if ( $window === null ) {
			return $state;
		}

		$state['orders_run']      = $run;
		$state['until']           = $window['until'];
		$state['start_from']      = $window['start_from'];
		$state['horizon']         = $window['horizon'];
		$state['start_months']    = self::months_between( $window['start_from'], $window['until'] ) ?? $state['start_months'];
		$state['horizon_months']  = self::months_between( $window['horizon'], $window['until'] ) ?? $state['horizon_months'];
		$state['start_expected']  = $this->count_orders( $window['start_from'], $window['until'] );
		$state['start_part_done'] = false;

		return $state;
	}

	/**
	 * The phase the import has reached (see the class docblock).
	 *
	 * @param array<string, mixed>      $state
	 * @param array<string, mixed>|null $orders The orders part's state row, null before it began.
	 */
	private function phase( array $state, ?array $orders ): string {
		if ( $orders === null ) {
			return self::PHASE_STARTED;
		}

		if ( (string) ( $orders['status'] ?? '' ) === BackfillJobInterface::STATUS_COMPLETED
			&& ! $this->queue->has_pending_before( array( OrderFlusher::EVENT_ORDER_UPSERT ), $this->ended_at( $orders ) )
		) {
			return self::PHASE_COMPLETE;
		}

		if ( $state['start_part_done'] ) {
			return self::PHASE_START_PART_COMPLETE;
		}

		$window    = OrderBackfillJob::decode_window( (string) ( $orders['cursor_value'] ?? '' ) );
		$queued_to = $window['start_part_queued_to'] ?? null;
		if ( $queued_to === null || $this->queue->has_pending_before( array( OrderFlusher::EVENT_ORDER_UPSERT ), null, $queued_to ) ) {
			return self::PHASE_STARTED;
		}

		foreach ( self::BEFORE_ORDERS as $job_type => $event_types ) {
			$row = ( $this->state_reader )( $job_type );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (string) ( $row['status'] ?? '' ) === BackfillJobInterface::STATUS_RUNNING && ! $this->is_stalled( $job_type, $row ) ) {
				return self::PHASE_STARTED;
			}
			if ( $this->queue->has_pending_before( $event_types, $this->ended_at( $row ) ) ) {
				return self::PHASE_STARTED;
			}
		}

		return self::PHASE_START_PART_COMPLETE;
	}

	/**
	 * The §25 request: counts and dates only, never a person's data.
	 *
	 * @param array<string, mixed>      $state
	 * @param array<string, mixed>|null $orders
	 * @return array<string, mixed>
	 */
	private function body( array $state, ?array $orders, string $phase ): array {
		$orders_entity = array(
			'expected'       => null,
			'sent'           => 0,
			'oldest_sent_at' => null,
		);
		if ( $orders !== null ) {
			$window        = OrderBackfillJob::decode_window( (string) ( $orders['cursor_value'] ?? '' ) );
			$after         = explode( '|', (string) ( $window['after'] ?? '' ) );
			$orders_entity = array(
				'expected'       => isset( $orders['total_count'] ) ? (int) $orders['total_count'] : null,
				'sent'           => (int) ( $orders['sent_count'] ?? 0 ),
				'oldest_sent_at' => count( $after ) === 2 ? substr( $after[0], 0, 10 ) : null,
			);
		}

		return array(
			'import_id'     => (string) $state['import_id'],
			'phase'         => $phase,
			'horizon_start' => substr( (string) $state['horizon'], 0, 10 ),
			'entities'      => array(
				// The catalog's total counts parent products; the engine gets
				// one row per variation. No cheap count matches what is sent,
				// so `expected` is null (§25: omit it when counting is too
				// expensive).
				'catalog'   => array(
					'expected' => null,
					'sent'     => $this->sent( 'products' ),
				),
				'customers' => array(
					'expected' => $this->expected( 'customers' ),
					'sent'     => $this->sent( 'customers' ),
				),
				'orders'    => $orders_entity,
				'buyers'    => array( 'expected' => $state['buyers_expected'] === null ? null : (int) $state['buyers_expected'] ),
			),
		);
	}

	/** The latest run's accepted rows; a run from before they were counted reports the rows it read. */
	private function sent( string $job_type ): int {
		$row = ( $this->state_reader )( $job_type );
		if ( ! is_array( $row ) ) {
			return 0;
		}

		return isset( $row['sent_count'] ) ? (int) $row['sent_count'] : (int) ( $row['processed_count'] ?? 0 );
	}

	private function expected( string $job_type ): ?int {
		$row = ( $this->state_reader )( $job_type );

		return is_array( $row ) && isset( $row['total_count'] ) ? (int) $row['total_count'] : null;
	}

	/**
	 * When an import run ended — its rows queued after that are the live
	 * sync's — or now while it has no end time.
	 *
	 * @param array<string, mixed> $row
	 */
	private function ended_at( array $row ): string {
		$completed = (string) ( $row['completed_at'] ?? '' );

		return $completed !== '' ? $completed : gmdate( 'Y-m-d H:i:s', $this->now() );
	}

	private function sequence_waits_for_orders(): bool {
		$sequence = get_option( ImportsOnConnect::OPTION, array() );

		return is_array( $sequence ) && in_array( 'orders', array_map( 'strval', $sequence ), true );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function end( array $state ): void {
		$state['ended'] = true;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Arm the check at $at — replacing a queued one when $replace, else only
	 * when none is queued. The check that runs now is in progress, not
	 * queued, so it re-arms itself.
	 */
	private function schedule( int $at, bool $replace = false ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		if ( $replace ) {
			as_unschedule_all_actions( self::HOOK, array(), self::AS_GROUP );
		} elseif ( is_int( as_next_scheduled_action( self::HOOK, array(), self::AS_GROUP ) ) ) {
			return;
		}

		as_schedule_single_action( $at, self::HOOK, array(), self::AS_GROUP );
	}

	/**
	 * The calendar months from $from to $until (`Y-m-d H:i:s`), or null when
	 * unreadable. The window rolls a day the earlier month lacks forward
	 * (31 August − 6 months is 3 March), so a part month of half a month or
	 * more counts as one.
	 */
	private static function months_between( string $from, string $until ): ?int {
		try {
			$diff = ( new \DateTimeImmutable( $from . ' UTC' ) )->diff( new \DateTimeImmutable( $until . ' UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $diff->y * 12 + $diff->m + ( $diff->d >= 15 ? 1 : 0 );
	}
}
