<?php
/**
 * Drains the rec-engine ingest queue's order rows to POST
 * /api/v1/ingest/orders.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Settings\RecEngineSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_flush_orders`. The D6 batch
 * machinery lives in AbstractD6Flusher; this subclass supplies the order
 * specifics: the event type, the batch cap (50 — orders carry nested line
 * items), the Client call, and the row → wire object mapping.
 *
 * Each order.upsert row is keyed on the WC order id (entity_id): the flusher
 * loads the WC_Order FRESH and builds it. Order-id keying means guest orders
 * work without a payload-carried path — the engine auto-creates the customer
 * from the order's customer_email. Three terminal skips: the order vanished;
 * its CURRENT status is no longer a confirmed purchase (map_status === '' —
 * e.g. it moved back to pending after enqueue; the hook re-enqueues if it
 * becomes mappable again, so skipping is safe); OR the built payload has an
 * empty items[] (every line dropped — only non-product lines, or product
 * lines with no usable id). The engine requires items min 1, so sending such
 * an order can never succeed; before this guard (F3-36) it was sent anyway
 * and D6-failed on every retry, flooding the Event Log on the pilot.
 *
 * Kept separate from the catalog/customer flushers (its own AS hook/group) so
 * the retry cycles are independent; the shared D6 logic is inherited.
 *
 * Not final: tests subclass to stub get_order().
 */
class OrderFlusher extends AbstractD6Flusher {

	/** Queue event type for an order upsert (created / status-changed). */
	public const EVENT_ORDER_UPSERT = 'order.upsert';

	/** Action Scheduler hook + group, separate from catalog/customer cycles. */
	public const FLUSH_HOOK = 'smly_rec_flush_orders';
	public const AS_GROUP   = 'smaily-rec-orders';

	/** Orders are heavier (nested items) — the engine caps the batch at 50. */
	public const DEFAULT_BATCH_SIZE = 50;

	private OrderPayloadBuilder $builder;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored config.
	 */
	public function __construct(
		IngestQueue $queue,
		OrderPayloadBuilder $builder,
		RecEngineSettings $settings,
		callable $client_factory
	) {
		parent::__construct( $queue, $settings, $client_factory );
		$this->builder = $builder;
	}

	protected function event_types(): array {
		return array( self::EVENT_ORDER_UPSERT );
	}

	protected function batch_size(): int {
		return self::DEFAULT_BATCH_SIZE;
	}

	protected function endpoint_label(): string {
		return 'orders';
	}

	protected function send( array $batch ): array {
		return ( $this->client_factory )()->ingest_orders( $batch );
	}

	protected function row_to_object( array $row ): ?array {
		$order = $this->get_order( (int) ( $row['entity_id'] ?? 0 ) );
		if ( $order === null ) {
			return null;
		}
		// Status may have changed since enqueue; read it fresh. If it's no
		// longer mappable (e.g. moved to pending), don't send — the hook
		// re-enqueues when it returns to a confirmed-purchase status.
		if ( $this->builder->map_status( (string) $order->get_status() ) === '' ) {
			return null;
		}
		$payload = $this->builder->build( $order, (string) ( $row['event_uuid'] ?? '' ) );
		// Engine requires items min 1 — an all-lines-dropped order can never
		// succeed, so it's a terminal skip, not a send-and-fail.
		if ( ( $payload['items'] ?? array() ) === array() ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Load a WC order by id. Protected so tests can stub the lookup.
	 */
	protected function get_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
