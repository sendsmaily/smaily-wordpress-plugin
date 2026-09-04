<?php
/**
 * Detects abandoned carts and enqueues their reminder events.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Support\DebugLog;

/**
 * Action Scheduler callback logic for `smly_plus_abandoned_cart` (PRO-1195).
 * One pass per 15-minute tick, replacing the legacy status+email double pass:
 *
 *   1. Backlog guard (F3-37 carried over): un-reminded tracker rows older
 *      than `smaily_connect_abandoned_cart_max_age_seconds` (filterable,
 *      default 24h) are EXPIRED without emailing — a stale reminder is
 *      worthless and a re-armed scheduler must never mass-mail history.
 *      Already-reminded rows past the same window are pruned as dead weight.
 *   2. Rows past the merchant's cutoff (`smaily_connect_abandoned_cart_cutoff`
 *      minutes — the SAME option the legacy pass read, so an upgrading store
 *      keeps its configured delay) whose email identity is known get ONE
 *      `automation.abandoned_cart` event enqueued into the Smaily EventQueue
 *      and are stamped `reminder_enqueued_at` (legacy `mail_sent` parity —
 *      never a second reminder for the same cart row).
 *
 * Timestamps: the tracker writes and compares UTC 'Y-m-d H:i:s' on BOTH
 * sides, so the F3-37 Z-form-vs-MySQL string-compare seam cannot recur.
 *
 * Gates: the merchant's abandoned-cart toggle (normalized F3-54 read) AND
 * `smly_plus_setup_completed` — the send path needs the wizard's Smaily
 * credentials, so an un-wizarded store never enqueues (contact-path rule).
 * The expiry/prune housekeeping still runs while gated so tracked rows never
 * outlive the backlog window.
 *
 * Per-row Throwable backstop (F3-53 carried over): a row whose processing
 * throws is deterministic — it is terminal-marked (logged, observable) and
 * the pass continues; one poison row never aborts the sweep.
 *
 * Not final: tests inject store/queue/builder doubles.
 */
class CartAbandonmentSweeper {

	/** Same filter name as the legacy pass — merchant overrides carry over. */
	public const FILTER_MAX_AGE = 'smaily_connect_abandoned_cart_max_age_seconds';

	private const BATCH_SIZE = 200;

	private CartSessionStore $store;
	private CartPayloadBuilder $builder;
	private EventQueue $queue;

	public function __construct( CartSessionStore $store, CartPayloadBuilder $builder, EventQueue $queue ) {
		$this->store   = $store;
		$this->builder = $builder;
		$this->queue   = $queue;
	}

	/**
	 * Run one sweep pass.
	 *
	 * @return array{expired: int, pruned: int, enqueued: int, skipped: int}
	 */
	public function sweep(): array {
		$stats = array(
			'expired'  => 0,
			'pruned'   => 0,
			'enqueued' => 0,
			'skipped'  => 0,
		);

		$now = time();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant value IS the plugin prefix (smaily_connect_*); PCP can't resolve self::CONST.
		$max_age = (int) apply_filters( self::FILTER_MAX_AGE, DAY_IN_SECONDS );
		$floor   = gmdate( 'Y-m-d H:i:s', $now - $max_age );

		// Housekeeping runs even when the feature is gated off, so tracker
		// rows (PII) never outlive the backlog window.
		$stats['expired'] = $this->store->delete_expired( $floor );
		$stats['pruned']  = $this->store->prune_notified( $floor );

		if ( $stats['expired'] > 0 ) {
			DebugLog::write(
				sprintf(
					'[smaily-connect cart.sweep] Backlog guard: expired %d abandoned cart(s) older than the reminder window (%d s) without emailing (filter: %s).',
					$stats['expired'],
					$max_age,
					self::FILTER_MAX_AGE
				)
			);
		}

		if ( ! $this->enabled() ) {
			return $stats;
		}

		$cutoff    = max( 1, $this->cutoff_minutes() ) * MINUTE_IN_SECONDS;
		$threshold = gmdate( 'Y-m-d H:i:s', $now - $cutoff );

		foreach ( $this->store->due_rows( $threshold, $floor, self::BATCH_SIZE ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			try {
				$payload = $this->builder->build( $row );

				if ( $payload === null ) {
					// Not our JSON shape / no email — can never send; terminal
					// so it isn't re-read every tick (F3-53: observable, never
					// an eternal retry).
					DebugLog::write(
						sprintf( '[smaily-connect cart.sweep] Cart row %d has an unreadable payload - marked without emailing.', $id )
					);
					$this->store->mark_reminder_enqueued( $id );
					++$stats['skipped'];
					continue;
				}

				$queued = $this->queue->enqueue(
					CartFlusher::EVENT_TYPE,
					'cart:' . $id,
					$payload
				);
				if ( $queued === null ) {
					// Queue insert failed (transient) — leave the row for the
					// next tick.
					continue;
				}

				$this->store->mark_reminder_enqueued( $id );
				++$stats['enqueued'];
			} catch ( \Throwable $e ) {
				// Deterministic data-shape throw would recur every tick —
				// terminal-mark THIS row and continue (F3-53 backstop).
				DebugLog::write(
					sprintf(
						'[smaily-connect cart.sweep] Cart row %d failed with %s: %s - marked without emailing.',
						$id,
						get_class( $e ),
						$e->getMessage()
					)
				);
				$this->store->mark_reminder_enqueued( $id );
				++$stats['skipped'];
			}
		}

		if ( $stats['enqueued'] > 0 ) {
			$this->kick_cart_flush();
		}

		return $stats;
	}

	/**
	 * Master gate: the merchant toggle (F3-54 normalized read — same option
	 * an upgrading store already carries) AND the wizard gate (the dispatch
	 * path requires wizard credentials).
	 */
	private function enabled(): bool {
		if ( ! SetupState::completed() ) {
			return false;
		}

		return \Smaily_Connect\Includes\Options::abandoned_cart_status()['enabled'];
	}

	private function cutoff_minutes(): int {
		return (int) get_option(
			\Smaily_Connect\Includes\Options::ABANDONED_CART_CUTOFF_OPTION,
			\Smaily_Connect\Includes\Options::ABANDONED_CART_DEFAULT_CUTOFF
		);
	}

	/**
	 * Ask AS to drain the just-enqueued rows promptly instead of waiting for
	 * the CartFlusher's next 60s recurring tick. Deduplicated.
	 */
	private function kick_cart_flush(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( CartFlusher::FLUSH_HOOK, array(), CartFlusher::AS_GROUP ) !== false
		) {
			return;
		}

		as_enqueue_async_action( CartFlusher::FLUSH_HOOK, array(), CartFlusher::AS_GROUP );
	}
}
