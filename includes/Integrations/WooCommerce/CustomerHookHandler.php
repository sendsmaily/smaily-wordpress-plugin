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
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * Fans WP/WC user changes into the rec-engine ingest queue as
 * `customer.upsert` rows (PLUGIN.md §488): user registration, profile
 * update, WooCommerce customer creation, WC account-details save, and a
 * change of the newsletter opt-in meta. The CustomerFlusher later loads each
 * user FRESH and turns it into a /api/v1/ingest/customers object — so the row
 * carries only the user id, no payload (current state is read at flush time,
 * not capture time).
 *
 * Enqueues only a user in the engine's customer scope (contract v1.20.0 §4,
 * CON-246 — supersedes the 3.3.3 "every registered user" A-filter): a user the
 * plugin's own contact sync puts into Smaily, or one with a sale order inside
 * the history horizon (CustomerScope::includes_user(), the same rule the
 * customer import's page query applies). No role filter — a role is not part
 * of the rule. A user who enters the scope later gets in through the event
 * that put them there: the opt-in meta change below, or their order
 * (OrderHookHandler queues the buyer once per order). Guest buyers (no WP
 * user) arrive with their orders, not here.
 *
 * Gate: enqueue only while a rec-engine tenant is connected
 * (RecEngineSettings::is_connected). This is independent of the email-sync
 * wizard's `smly_plus_setup_completed` flag — different destination
 * (rec-engine, not the Smaily contact API), so the two never conflict. Same
 * gate shape as CatalogHookHandler.
 *
 * Per-request dedupe: profile_update in particular can fire several times in
 * one request; a static $seen set collapses repeats to a single row per user.
 * A user is marked seen only when a row is queued, so an opt-in written after
 * `user_register` in the same request still queues them.
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
	private CustomerScope $scope;

	public function __construct( IngestQueue $queue, RecEngineSettings $settings, ?CustomerScope $scope = null ) {
		$this->queue    = $queue;
		$this->settings = $settings;
		$this->scope    = $scope ?? new CustomerScope( $settings );
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
	 * `added_user_meta` / `updated_user_meta` ($meta_id, $user_id, $meta_key)
	 * — the newsletter opt-in can put a user into the contact-sync audience,
	 * and with it into the engine's scope, without any of the hooks above
	 * (the registration form writes it after `user_register`). Every other
	 * meta key is ignored.
	 *
	 * @param int|string $meta_id  Unused.
	 * @param int|string $user_id  The user whose meta changed.
	 * @param string     $meta_key The meta key.
	 */
	public function on_user_meta_changed( $meta_id, $user_id, $meta_key = '' ): void {
		if ( $meta_key !== ContactAudience::OPTIN_META ) {
			return;
		}
		$this->enqueue_upsert( (int) $user_id );
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
		if ( ! $this->scope->includes_user( $user_id ) ) {
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
