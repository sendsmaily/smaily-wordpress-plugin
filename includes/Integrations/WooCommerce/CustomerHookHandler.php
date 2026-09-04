<?php
/**
 * WP/WC user hooks → rec-engine customer ingest queue.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * Fans WP/WC user changes into the rec-engine ingest queue as
 * `customer.upsert` rows (PLUGIN.md §488): user registration, profile
 * update, WooCommerce customer creation, and WC account-details save. The
 * CustomerFlusher later loads each user FRESH and turns it into a
 * /api/v1/ingest/customers object — so the row carries only the user id, no
 * payload (current state is read at flush time, not capture time).
 *
 * Enqueues every registered user (A-filter), NOT only the `customer` role.
 * Rationale (DECISIONS, 3.3.3): both existing sync paths are broader than a
 * role filter — the legacy subscriber-sync keys on the newsletter opt-in and
 * the new email HookHandler syncs every registered user — so a `customer`-only
 * filter would be narrower than both AND would drop custom-role shoppers (VIP,
 * wholesale, member). Guest buyers (no WP user) are captured by the W5 order
 * path, not here. Admin "noise" is small and self-resolving (a user with no
 * purchase history gets no recommendations).
 *
 * Gate: enqueue only while a rec-engine tenant is connected
 * (RecEngineSettings::is_connected). This is independent of the email-sync
 * wizard's `smly_plus_setup_completed` flag — different destination
 * (rec-engine, not the Smaily contact API), so the two never conflict. Same
 * gate shape as CatalogHookHandler.
 *
 * Per-request dedupe: profile_update in particular can fire several times in
 * one request; a static $seen set collapses repeats to a single row per user.
 *
 * One-way by construction: the flusher only advances queue rows
 * (mark_sent / mark_failed) and never writes user meta, so an ingest can't
 * re-trigger profile_update — no enqueue loop is possible.
 *
 * Not final: tests subclass to record enqueues through a doubled IngestQueue.
 */
class CustomerHookHandler {

	/** @var array<int, bool> per-request dedupe keyed by user id. */
	private static array $seen = array();

	private IngestQueue $queue;
	private RecEngineSettings $settings;

	public function __construct( IngestQueue $queue, RecEngineSettings $settings ) {
		$this->queue    = $queue;
		$this->settings = $settings;
	}

	/** `user_register` — a new WP user (any role). */
	public function on_user_register( int $user_id ): void {
		$this->enqueue_upsert( $user_id );
	}

	/** `profile_update` — an existing user's profile changed. */
	public function on_profile_update( int $user_id ): void {
		$this->enqueue_upsert( $user_id );
	}

	/**
	 * `woocommerce_created_customer` — WooCommerce's checkout-creates-account
	 * / registration flow. Same effect as on_user_register.
	 */
	public function on_woocommerce_created_customer( int $customer_id ): void {
		$this->enqueue_upsert( $customer_id );
	}

	/** `woocommerce_save_account_details` — WC My Account page save. */
	public function on_save_account_details( int $user_id ): void {
		$this->enqueue_upsert( $user_id );
	}

	/**
	 * Reset the per-request dedupe set. Tests use it between cases; production
	 * never calls it (the static is request-scoped).
	 */
	public static function reset_seen(): void {
		self::$seen = array();
	}

	private function enqueue_upsert( int $user_id ): void {
		if ( ! $this->settings->is_connected() ) {
			return;
		}
		if ( $user_id <= 0 ) {
			return;
		}
		if ( isset( self::$seen[ $user_id ] ) ) {
			return;
		}
		self::$seen[ $user_id ] = true;

		// Empty payload — the flusher loads the user fresh by entity_id so the
		// engine gets current state at send time. The customer flush hook/group
		// route this row to CustomerFlusher (not the catalog flusher), which
		// shares the queue table.
		$this->queue->enqueue(
			CustomerFlusher::EVENT_CUSTOMER_UPSERT,
			(string) $user_id,
			array(),
			null,
			CustomerFlusher::FLUSH_HOOK,
			CustomerFlusher::AS_GROUP
		);
	}
}
