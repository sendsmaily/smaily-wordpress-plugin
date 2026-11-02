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
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
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
 * A registered buyer's own `customer` row is queued with the order, once
 * per order (enqueue_buyer(), CON-246).
 *
 * Not final: tests subclass to record enqueues through a doubled IngestQueue.
 */
class OrderHookHandler {

	/** Order meta: this order's registered buyer was queued as a customer (CON-246). */
	public const BUYER_QUEUED_META = '_smly_rec_buyer_queued';

	/** @var array<int, bool> per-request dedupe keyed by order id. */
	private static array $seen = array();

	private IngestQueue $queue;
	private OrderPayloadBuilder $builder;
	private RecEngineSettings $settings;
	private CustomerScope $scope;

	public function __construct( IngestQueue $queue, OrderPayloadBuilder $builder, RecEngineSettings $settings, ?CustomerScope $scope = null ) {
		$this->queue    = $queue;
		$this->builder  = $builder;
		$this->settings = $settings;
		$this->scope    = $scope ?? new CustomerScope( $settings );
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

		$this->enqueue_buyer( $order_id );
	}

	/**
	 * Queue the registered buyer's `customer` record once per order (contract
	 * v1.20.0 §4, CON-246): a buyer with a sale order inside the horizon is in
	 * the engine's customer scope, and an account that only now bought — out
	 * of scope until this order — would otherwise never reach the engine. The
	 * row carries the WP user id, the `external_id` the customer builder sends.
	 *
	 * Once per order, not per status change: the order meta BUYER_QUEUED_META
	 * is written the first time, whatever happens next. No row when a pending
	 * customer row for that user already waits (it is built fresh at send and
	 * covers this order), for a guest order, or for a user out of scope (an
	 * order placed before the horizon, a deleted account).
	 */
	private function enqueue_buyer( int $order_id ): void {
		if ( $this->buyer_marked( $order_id ) ) {
			return;
		}

		$customer_id = $this->order_customer_id( $order_id );
		if ( $customer_id <= 0 ) {
			return;
		}
		$this->mark_buyer( $order_id );

		if ( $this->queue->has_pending_for( CustomerFlusher::EVENT_CUSTOMER_UPSERT, (string) $customer_id ) ) {
			return;
		}
		if ( ! $this->scope->includes_user( $customer_id ) ) {
			return;
		}

		$this->queue->enqueue(
			CustomerFlusher::EVENT_CUSTOMER_UPSERT,
			(string) $customer_id,
			array(),
			null,
			CustomerFlusher::FLUSH_HOOK,
			CustomerFlusher::AS_GROUP
		);
	}

	/** The order's WP customer id, 0 for a guest or an order that cannot be loaded. Test seam. */
	protected function order_customer_id( int $order_id ): int {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		return $order instanceof \WC_Order ? (int) $order->get_customer_id() : 0;
	}

	/** Has this order's buyer already been queued? Test seam. */
	protected function buyer_marked( int $order_id ): bool {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		return $order instanceof \WC_Order && (string) $order->get_meta( self::BUYER_QUEUED_META ) !== '';
	}

	/** Record that this order's buyer was queued. Test seam. */
	protected function mark_buyer( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( self::BUYER_QUEUED_META, '1' );
			$order->save_meta_data();
		}
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
