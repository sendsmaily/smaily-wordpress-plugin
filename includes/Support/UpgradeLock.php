<?php
/**
 * Mutual exclusion for the inline upgrade routine (PRO-2434).
 *
 * @package Smaily\Connect\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A one-row lock in wp_options so only ONE request runs Activation::run()
 * when the stored plugin version trails the code.
 *
 * Bootstrap::maybe_run_upgrade fires on admin_init — which every
 * admin-ajax.php request (heartbeat, WooCommerce admin AJAX) also fires — and
 * the version is stamped only at the END of the run. Migration 011's
 * `ALTER TABLE` on a large event queue can outlive max_execution_time; the
 * request dies unstamped and, without this lock, the next N admin requests
 * all start the same DDL concurrently, each holding metadata locks on the
 * queue table every flusher then waits on.
 *
 * `add_option()` is the atomic primitive (INSERT IGNORE on the unique
 * option_name): the second caller gets false. A lock older than TTL is
 * treated as abandoned (the holder died mid-DDL) and taken over.
 */
class UpgradeLock {

	public const OPTION = 'smly_plus_upgrade_lock';

	/** Seconds after which an unreleased lock is considered abandoned. */
	public const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * @return bool True when this caller now holds the lock.
	 */
	public function acquire(): bool {
		if ( add_option( self::OPTION, (string) time(), '', false ) ) {
			return true;
		}

		$held_since = (int) get_option( self::OPTION, 0 );
		if ( $held_since > 0 && ( time() - $held_since ) < self::TTL ) {
			return false;
		}

		// Abandoned (or unreadable) lock: clear it and try exactly once more.
		delete_option( self::OPTION );

		return (bool) add_option( self::OPTION, (string) time(), '', false );
	}

	public function release(): void {
		delete_option( self::OPTION );
	}
}
