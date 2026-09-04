<?php
/**
 * Drains the rec-engine ingest queue's catalog rows to POST
 * /api/v1/ingest/catalog.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_flush_ingest` — the catalog flusher.
 * The D6 batch machinery lives in AbstractD6Flusher; this subclass supplies the
 * catalog specifics: the event types, the batch cap (100), the Client call,
 * and the row → wire object mapping.
 *
 * Per-row → wire object:
 *   - catalog.upsert : load the product FRESH by entity_id and build it, so the
 *     engine always gets the latest state. A product that vanished since
 *     enqueue is a terminal skip — a catalog.delete row will follow.
 *   - catalog.delete : the product is already gone, so its full object was
 *     captured at delete time in the row payload; here we stamp in_stock=false
 *     and the row's event_uuid as event_id. The captured object is also run
 *     through CatalogPayloadBuilder::ensure_valid_removal() HERE, at send
 *     time (PRO-1506) — not just at enqueue (PRO-1498) — so a row captured
 *     BEFORE the PRO-1498 fix (a stuck pre-3.8.1 blank category_path/
 *     product_url) is repaired on Retry instead of resending the same
 *     blank the engine already rejected once. A row with no captured object
 *     at all (corrupt/missing payload) falls back to build_unresolvable()
 *     from the row's entity id, same as the enqueue-time unresolvable path.
 *
 * **N-7: catalog is now D6** (was all-or-nothing). The engine returns
 * `200 {processed, deduplicated, errors:[{index, sku?, field, message}]}` and
 * the inherited split marks a per-item-rejected product FAILED (it used to mark
 * the whole batch sent on any 2xx, which after the engine's N-7 retrofit would
 * have silently lost a rejected product). The catalog-extra fields the old
 * response carried (created / updated / skipped / unmapped_attributes) were
 * never consumed plugin-side, so dropping them is lossless.
 *
 * Kept separate from the customer/order flushers (its own AS hook/group) so the
 * retry cycles are independent; the D6 logic is inherited, not copied.
 *
 * Not final: tests subclass to stub get_product().
 */
class IngestFlusher extends AbstractD6Flusher {

	/** Spec-conservative batch ceiling (engine tolerates far more). */
	public const DEFAULT_BATCH_SIZE = 100;

	private CatalogPayloadBuilder $builder;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored config.
	 */
	public function __construct(
		IngestQueue $queue,
		CatalogPayloadBuilder $builder,
		RecEngineSettings $settings,
		callable $client_factory
	) {
		parent::__construct( $queue, $settings, $client_factory );
		$this->builder = $builder;
	}

	protected function event_types(): array {
		return array( CatalogHookHandler::EVENT_CATALOG_UPSERT, CatalogHookHandler::EVENT_CATALOG_DELETE );
	}

	protected function batch_size(): int {
		return self::DEFAULT_BATCH_SIZE;
	}

	protected function endpoint_label(): string {
		return 'catalog';
	}

	protected function send( array $batch ): array {
		return ( $this->client_factory )()->ingest_catalog( $batch );
	}

	protected function row_to_object( array $row ): ?array {
		$event_uuid = (string) ( $row['event_uuid'] ?? '' );
		$event_type = (string) ( $row['event_type'] ?? '' );

		if ( $event_type === CatalogHookHandler::EVENT_CATALOG_DELETE ) {
			$payload = $this->decode_payload( (string) ( $row['payload'] ?? '' ) );
			$object  = ( isset( $payload['object'] ) && is_array( $payload['object'] ) ) ? $payload['object'] : null;
			if ( $object === null ) {
				// No captured object at all — build a minimal always-valid
				// tombstone from the row's entity id rather than a terminal
				// skip with nothing sent (PRO-1506).
				return $this->builder->build_unresolvable( (int) ( $row['entity_id'] ?? 0 ), $event_uuid );
			}
			// PRO-1506: repair a still-blank required field on the STORED
			// object too — idempotent on an already-valid (post-PRO-1498)
			// capture, and heals a pre-3.8.1 stuck row on Retry.
			$object = $this->builder->ensure_valid_removal( $object );

			$object['event_id'] = $event_uuid;
			$object['in_stock'] = false;
			return $object;
		}

		// catalog.upsert — load fresh so the engine gets current state.
		$product = $this->get_product( (int) ( $row['entity_id'] ?? 0 ) );
		if ( $product === null ) {
			return null;
		}
		return $this->builder->build( $product, $event_uuid );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode_payload( string $json ): array {
		if ( $json === '' ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Load a product by id. Protected so tests can stub the lookup.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}
}
