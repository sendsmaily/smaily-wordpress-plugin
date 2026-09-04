<?php
/**
 * Drains the rec-engine ingest queue's catalog.remove rows to POST
 * /api/v1/ingest/catalog/remove (§3b product-level tombstone).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_flush_catalog_remove` — the §3b
 * product-level removal flusher (contract v1.3.0, PRO-1230).
 *
 * A queue row is enqueued by CatalogHookHandler::on_hard_delete_product when a
 * PARENT product post is permanently deleted (before_delete_post; trash keeps
 * the F3-40 in_stock=false soft path). The row payload carries the RAW
 * (un-prefixed) canonical parent product id — the exact `tags.product_id`
 * value the catalog sync emits (SkuResolver::product_group_id()) — because
 * §3b matches removal against `catalog.tags.product_id`, never the
 * `woo-`-prefixed `sku`.
 *
 * Unlike the D6 endpoints, §3b has NO per-item errors[]: the response is
 * {ok, removed_products, rows_tombstoned, not_found} and a wrapper problem is
 * an all-or-nothing 400. So this subclass overrides apply_response(): every
 * batched row on a 2xx is SENT — an id in `not_found` is a contract-defined
 * success ("already removed, or never sent"; the call is idempotent), recorded
 * observably in the row's stored exchange as outcome=not_found (F3-44), never
 * a retry loop. HTTP failures inherit the shared terminal-4xx / transient-
 * retry policy.
 *
 * Its own AS hook/group (like customers/orders) so removal retries drain
 * independently of the catalog upsert cycle, and so IngestFlusher — which
 * scopes its drain to catalog.upsert/catalog.delete — never consumes (or is
 * blocked by) a remove row it cannot send.
 *
 * Not final: tests subclass per the flusher-test convention.
 */
class CatalogRemoveFlusher extends AbstractD6Flusher {

	public const FLUSH_HOOK = 'smly_rec_flush_catalog_remove';
	public const AS_GROUP   = 'smaily-rec-catalog-remove';

	/** Spec-conservative batch ceiling (the §3b wrapper allows up to 1000 ids). */
	public const DEFAULT_BATCH_SIZE = 100;

	protected function event_types(): array {
		return array( CatalogHookHandler::EVENT_CATALOG_REMOVE );
	}

	protected function batch_size(): int {
		return self::DEFAULT_BATCH_SIZE;
	}

	protected function endpoint_label(): string {
		return 'catalog/remove';
	}

	protected function send( array $batch ): array {
		$ids = array();
		foreach ( $batch as $object ) {
			$id = (string) ( $object['product_id'] ?? '' );
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}
		// The engine is idempotent per id, but there is no reason to send a
		// duplicate inside one wrapper (two queued rows for one product).
		$ids = array_values( array_unique( $ids ) );

		return ( $this->client_factory )()->catalog_remove( $ids );
	}

	protected function row_to_object( array $row ): ?array {
		$payload    = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$product_id = is_array( $payload ) ? trim( (string) ( $payload['product_id'] ?? '' ) ) : '';
		if ( $product_id === '' ) {
			// No removal key on the row — terminal skip; the inherited skip
			// exchange keeps it observable (never a silent drop, LESSONS §2.11).
			return null;
		}
		return array( 'product_id' => $product_id );
	}

	/**
	 * §3b is not D6: a 2xx means every id was applied (tombstoned or already
	 * absent). Mark all rows sent; store the per-row outcome — `removed` when
	 * the id matched ≥1 catalog row, `not_found` when it matched none (already
	 * removed / never ingested; a success per the contract, safe to ignore).
	 *
	 * @param array<string, mixed>                                            $response
	 * @param array<int, array<string, mixed>>                                $batch_rows
	 * @param array<int, array<string, mixed>>                                $objects
	 * @param array{processed:int,sent:int,failed:int,retried:int,skipped:int} $stats
	 */
	protected function apply_response( array $response, array $batch_rows, array $objects, array &$stats ): void {
		$not_found = ( isset( $response['not_found'] ) && is_array( $response['not_found'] ) )
			? array_map( 'strval', $response['not_found'] )
			: array();

		foreach ( $batch_rows as $index => $row ) {
			$id         = (int) ( $row['id'] ?? 0 );
			$product_id = (string) ( $objects[ $index ]['product_id'] ?? '' );

			$this->queue->mark_sent( $id );
			$this->queue->store_exchange(
				$id,
				$this->trim_json( $objects[ $index ] ?? null ),
				$this->trim_text(
					(string) wp_json_encode(
						array(
							'http'             => 200,
							'outcome'          => in_array( $product_id, $not_found, true ) ? 'not_found' : 'removed',
							'removed_products' => isset( $response['removed_products'] ) ? (int) $response['removed_products'] : 0,
							'rows_tombstoned'  => isset( $response['rows_tombstoned'] ) ? (int) $response['rows_tombstoned'] : 0,
						)
					)
				)
			);
			++$stats['sent'];
		}
	}
}
