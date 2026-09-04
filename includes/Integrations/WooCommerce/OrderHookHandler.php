<?php
/**
 * WC order-status hooks → rec-engine order ingest queue.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

/**
 * Fans WooCommerce order-status changes into the rec-engine ingest queue as
 * `order.upsert` rows (PLUGIN.md §488). Listens to
 * `woocommerce_order_status_changed` — which fires on a new order's first
 * transition AND on every later status change — and enqueues only when the
 * change matters to the engine.
 *
 * Enqueue iff the **mapped engine status** changes to a confirmed purchase:
 *   map_status($to) !== ''            (the target is a sale state)
 *   AND map_status($from) !== map_status($to)   (the engine status actually changed)
 *
 * So (map_status: the sale states + ANY custom status → a sale; pending /
 * on-hold / failed / draft / trash → '' — F3-42):
 *   - pending → processing : enqueue (first time it's a sale)
 *   - pending → label-printed (custom) : enqueue (custom statuses are a sale)
 *   - on-hold → processing : enqueue (on-hold is '' → the engine status changed)
 *   - processing → completed / cancelled / refunded : enqueue
 *   - processing → on-hold / pending : skip (target isn't a sale — don't send)
 *
 * The engine UPSERTs on `(tenant_id, external_order_id)` and fully replaces
 * the order on re-ingest, so a later status change overwrites the stored
 * order. A cancelled / refunded transition from any sale state is an
 * engine-status change → enqueue. (Status mapping itself lives in
 * OrderPayloadBuilder::map_status — F3-22 / F3-42.)
 *
 * Gate: enqueue only while a rec-engine tenant is connected
 * (RecEngineSettings::is_connected), independent of the email-sync wizard
 * (different destination). Per-request `$seen` dedupe keyed by order id.
 * Guest orders work without a payload-carried path — the row carries the
 * order id, and the engine auto-creates the customer from customer_email.
 *
 * Not final: tests subclass to record enqueues through a doubled IngestQueue.
 */
class OrderHookHandler {

	/** @var array<int, bool> per-request dedupe keyed by order id. */
	private static array $seen = array();

	private IngestQueue $queue;
	private OrderPayloadBuilder $builder;
	private RecEngineSettings $settings;

	public function __construct( IngestQueue $queue, OrderPayloadBuilder $builder, RecEngineSettings $settings ) {
		$this->queue    = $queue;
		$this->builder  = $builder;
		$this->settings = $settings;
	}

	/**
	 * `woocommerce_order_status_changed` callback ($order_id, $from, $to, $order).
	 *
	 * @param int|string $order_id
	 * @param string     $from_status
	 * @param string     $to_status
	 */
	public function on_order_status_changed( $order_id, $from_status = '', $to_status = '' ): void {
		if ( ! $this->settings->is_connected() ) {
			return;
		}

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$to_engine = $this->builder->map_status( (string) $to_status );
		if ( $to_engine === '' ) {
			// The new status isn't a confirmed purchase — don't send it.
			return;
		}
		if ( $to_engine === $this->builder->map_status( (string) $from_status ) ) {
			// The mapped engine status didn't change (e.g. on-hold→processing) —
			// skip the redundant UPSERT.
			return;
		}

		if ( isset( self::$seen[ $order_id ] ) ) {
			return;
		}
		self::$seen[ $order_id ] = true;

		// Empty payload — the flusher loads the order fresh by entity_id so the
		// engine gets current state. The order flush hook/group routes the row
		// to OrderFlusher.
		$this->queue->enqueue(
			OrderFlusher::EVENT_ORDER_UPSERT,
			(string) $order_id,
			array(),
			null,
			OrderFlusher::FLUSH_HOOK,
			OrderFlusher::AS_GROUP
		);
	}

	/**
	 * `woocommerce_order_partially_refunded` callback ($order_id, $refund_id).
	 *
	 * A PARTIAL WooCommerce refund is invisible to every other path we have: it
	 * fires no webhook and — unlike a full refund, which flips the order to
	 * `refunded` and so rides `woocommerce_order_status_changed` above — it does
	 * not change the order status at all. Without this hook a returned line
	 * would only reach the engine on some unrelated later re-sync.
	 *
	 * WC fires this from wc_create_refund() after the refund is saved, so the
	 * refund state OrderPayloadBuilder reads is already complete. The row
	 * carries no payload: the flusher loads the order fresh and re-derives the
	 * return signals from its refunds (PRO-1633), which is also why a full
	 * refund needs nothing here.
	 *
	 * Deliberately NOT status-filtered: the flusher re-reads the status at send
	 * time and terminal-skips an order that is no longer a confirmed purchase.
	 *
	 * @param int|string $order_id
	 */
	public function on_order_partially_refunded( $order_id ): void {
		if ( ! $this->settings->is_connected() ) {
			return;
		}

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		if ( isset( self::$seen[ $order_id ] ) ) {
			return;
		}
		self::$seen[ $order_id ] = true;

		$this->queue->enqueue(
			OrderFlusher::EVENT_ORDER_UPSERT,
			(string) $order_id,
			array(),
			null,
			OrderFlusher::FLUSH_HOOK,
			OrderFlusher::AS_GROUP
		);
	}

	/**
	 * Reset the per-request dedupe set. Tests use it between cases; production
	 * never calls it (the static is request-scoped).
	 */
	public static function reset_seen(): void {
		self::$seen = array();
	}
}
