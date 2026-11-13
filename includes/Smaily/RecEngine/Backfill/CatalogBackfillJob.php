<?php
/**
 * Backfill published (and trashed) WooCommerce products into the rec-engine
 * catalog.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Support\DebugLog;

/**
 * The cursor walks PARENT products (post_type=product, status publish OR trash)
 * by ascending ID; each is expanded into its ingest units the same way
 * CatalogHookHandler::on_save_product does (a variable product fans out to its
 * variations), so backfill and live ingest enqueue identical rows. Products
 * live in wp_posts regardless of HPOS (HPOS is orders-only), so a direct
 * id-cursor query is safe here.
 *
 * Trashed products are enumerated too (not just publish): a product a customer
 * once bought but the merchant later trashed must STAY in the engine catalog so
 * its order-history join (and model training) survives — sent as in_stock=false
 * so it can't be recommended, never dropped (the engine has no delete-by-absence;
 * RECENGINE_API_CONTRACT.md §3). A published post enqueues a catalog.upsert (the
 * flusher loads it fresh, real stock); a trashed post enqueues a catalog.delete
 * carrying its captured object (the flusher stamps in_stock=false),
 * force-filled valid via CatalogPayloadBuilder::ensure_valid_removal() when
 * category_path / product_url come back blank (PRO-1498) — a removal is never
 * silently skipped, since the engine has no delete-by-key and a skipped row
 * would leave a synced product stuck in_stock=true forever (F3-43 never-drop
 * principle). Permanently-deleted products can't be recovered here — their
 * data is gone from WC — so this closes the trash gap, not the hard-delete one.
 *
 * Multilingual collapse (catalog-correctness P1): the WHERE clause still
 * enumerates every translation post (WPML/Polylang store each as its own
 * product row), but enqueue_record COLLAPSES them — a translation whose
 * canonical (default-language) post is itself a PUBLISHED product is SKIPPED,
 * so each real product is enqueued exactly once under its stable
 * `wc-{canonical_id}` key. The collapse keys on a *published* canonical only: a
 * still-published translation of a trashed default-language product is therefore
 * kept (it stands in as in_stock=true), never masked by the trashed canonical.
 * A fully-trashed multilingual product may emit a harmless duplicate
 * in_stock=false per language (idempotent on the engine's SKU upsert). The
 * cursor/processed_count still counts every enumerated post (so progress reaches
 * 100%); the SENT count is lower by design — that gap IS the collapse. Stateless:
 * each post decides independently, so it holds across batches without a dedupe set.
 */
class CatalogBackfillJob extends AbstractBackfillJob {

	private CatalogPayloadBuilder $builder;
	private DetectorInterface $detector;

	public function __construct( IngestQueue $queue, IngestFlusher $flusher, CatalogPayloadBuilder $builder, DetectorInterface $detector ) {
		parent::__construct( $queue, $flusher );
		$this->builder  = $builder;
		$this->detector = $detector;
	}

	public function job_type(): string {
		return 'products';
	}

	protected function batch_size(): int {
		return 100;
	}

	protected function count_total(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( %s, %s )",
				'product',
				'publish',
				'trash'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return int[]
	 */
	protected function fetch_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( %s, %s ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				'product',
				'publish',
				'trash',
				$after_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * A product that throws while it is loaded or built here fails alone
	 * (CON-304): its row is queued already failed, as the flusher fails a
	 * row it cannot build (CON-302), and the import goes on. A Throwable
	 * after a failed database query (`$wpdb->last_error` set) is a database
	 * error, not a broken product: it is thrown on and stops the import
	 * (PRO-3890). The queue writes run outside the catch.
	 */
	protected function enqueue_record( int $entity_id ): void {
		try {
			$rows = $this->rows_to_queue( $entity_id );
		} catch ( \Throwable $e ) {
			if ( $this->database_failed() ) {
				throw $e;
			}
			$this->enqueue_not_built( $entity_id, $e );
			return;
		}

		foreach ( $rows as list( $event_type, $unit_id, $payload ) ) {
			$this->queue->enqueue( $event_type, $unit_id, $this->import_payload( $payload ) );
		}
	}

	/**
	 * The rows one enumerated parent post queues, as [event_type, entity_id,
	 * payload]; none for a post this import does not send.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function rows_to_queue( int $entity_id ): array {
		$post = $this->units_to_send( $entity_id );
		if ( $post === null ) {
			return array();
		}
		[ $is_trashed, $units ] = $post;

		if ( $units === null ) {
			// A published product WooCommerce cannot load is not sent (CC.4).
			return $is_trashed ? array( $this->unavailable_unresolvable_row( $entity_id ) ) : array();
		}

		$rows = array();
		foreach ( array_filter( $units ) as $unit ) {
			$rows[] = $is_trashed
				? $this->unavailable_row( $unit )
				: array( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $unit->get_id(), array() );
		}
		return $rows;
	}

	/**
	 * Queue the post's row already failed: a catalog.delete for a trashed
	 * post, else a catalog.upsert, with no captured object. It stores the
	 * error type and where it was thrown, never its text (CON-301), and
	 * `last_response {outcome: "not_built"}`. It carries the run's mark like
	 * every import row but is never sent, so the run's sent_count leaves it
	 * out (CON-248).
	 */
	private function enqueue_not_built( int $entity_id, \Throwable $e ): void {
		$event_type = $this->post_status( $entity_id ) === 'trash'
			? CatalogHookHandler::EVENT_CATALOG_DELETE
			: CatalogHookHandler::EVENT_CATALOG_UPSERT;
		$id         = $this->queue->enqueue( $event_type, (string) $entity_id, $this->import_payload() );
		if ( $id === null ) {
			return;
		}
		$this->queue->mark_failed( $id, DebugLog::where( $e ) );
		$this->queue->store_exchange( $id, null, (string) wp_json_encode( array( 'outcome' => 'not_built' ) ) );
	}

	/** Whether the last database query failed. */
	private function database_failed(): bool {
		global $wpdb;
		return (string) ( $wpdb->last_error ?? '' ) !== '';
	}

	/**
	 * The §3c nightly manifest (PRO-3859): one `{sku, in_stock}` item per unit
	 * this import sends — the same walk, the same multilingual collapse, the
	 * same expansion into variations and the same keys — so the manifest can
	 * never name a product the import would not send, nor leave one out. A
	 * trashed product is in it as in_stock=false; drafts, private and pending
	 * products are not, because the import does not enumerate them. Stops once
	 * the list holds more than $limit items: the caller sends nothing then.
	 * Each batch's loaded posts are released before the next one is read.
	 *
	 * A product that cannot be loaded is still listed, out of stock, from its
	 * database ids (CON-312) — see post_manifest_items(). A database error
	 * is thrown on: the caller sends nothing then.
	 *
	 * @return array<int, array{sku: string, in_stock: bool}>
	 */
	public function manifest_items( int $limit ): array {
		$items = array();
		$after = 0;

		do {
			$ids = $this->fetch_ids_after( $after, $this->batch_size() );
			if ( function_exists( '_prime_post_caches' ) ) {
				// One query for the batch's posts and meta instead of one per product.
				_prime_post_caches( $ids, false, true );
			}
			$loaded = $ids;
			foreach ( $ids as $entity_id ) {
				foreach ( $this->post_manifest_items( $entity_id, $loaded ) as $item ) {
					$items[] = $item;
				}

				if ( count( $items ) > $limit ) {
					return $items;
				}
			}
			$this->release_loaded_posts( $loaded );
			// An empty batch ends the loop below before $after is read again.
			$after = (int) end( $ids );
		} while ( count( $ids ) === $this->batch_size() );

		return $items;
	}

	/**
	 * The manifest items of one enumerated parent post (CON-312). A product
	 * that throws while it is loaded or listed, or a published one that loads
	 * as nothing (the CC.4 gap), is listed from its database ids instead, out
	 * of stock (not_loaded_items()), and a debug line names its id with the
	 * error type and place, never the message (CON-301). A product left out
	 * would be tombstoned by the engine (§3c) — `recommendable=false`, which
	 * only a later §3 upsert recomputes. A Throwable after a failed database
	 * query (`$wpdb->last_error` set, CON-304's rule) is thrown on: the night
	 * is not sent.
	 *
	 * @param int[] $loaded The batch's loaded post ids, for release_loaded_posts().
	 * @return array<int, array{sku: string, in_stock: bool}>
	 */
	private function post_manifest_items( int $entity_id, array &$loaded ): array {
		try {
			$items = $this->loaded_manifest_items( $entity_id, $loaded );
			if ( $items !== null ) {
				return $items;
			}
			$why = 'it loaded as nothing';
		} catch ( \Throwable $e ) {
			if ( $this->database_failed() ) {
				throw $e;
			}
			$why = DebugLog::where( $e );
		}

		self::log_listed_out_of_stock( $entity_id, $why );
		return $this->not_loaded_items( $entity_id );
	}

	/**
	 * The manifest items of a post as the import sends it; null for a
	 * published product WooCommerce cannot load. A variation that loads as
	 * nothing is listed out of stock under its own id.
	 *
	 * @param int[] $loaded
	 * @return array<int, array{sku: string, in_stock: bool}>|null
	 */
	private function loaded_manifest_items( int $entity_id, array &$loaded ): ?array {
		$post = $this->units_to_send( $entity_id );
		if ( $post === null ) {
			return array();
		}
		[ $is_trashed, $units ] = $post;

		if ( $units === null ) {
			return $is_trashed ? array( $this->builder->manifest_item_unresolvable( $entity_id ) ) : null;
		}

		$items = array();
		foreach ( $units as $unit_id => $unit ) {
			if ( $unit === null ) {
				self::log_listed_out_of_stock( $unit_id, 'it loaded as nothing' );
				$items[] = $this->builder->manifest_item_unresolvable( $unit_id );
				continue;
			}
			$items[]  = $this->builder->manifest_item( $unit, $is_trashed );
			$loaded[] = (int) $unit->get_id();
		}
		return $items;
	}

	/**
	 * The manifest items of a product that could not be loaded, from its
	 * database ids alone, all out of stock (CON-312): what expand() would list
	 * — its published and private variations (the `product_variation`
	 * children get_children() reads) when its `product_type` term is
	 * `variable`, else the post itself. Each sku comes from
	 * SkuResolver::resolve_id(), which needs only the id and the detector; a
	 * detector that throws is thrown on, and the night is not sent. A
	 * translation whose canonical is enumerated lists nothing, as in
	 * units_to_send(), so its out-of-stock item never overrides the
	 * canonical's. A failed database read here is thrown on too: a list
	 * without the product's variations would tombstone them.
	 *
	 * @return array<int, array{sku: string, in_stock: bool}>
	 */
	private function not_loaded_items( int $entity_id ): array {
		if ( $this->is_collapsed_translation( $entity_id ) ) {
			return array();
		}
		$ids = $this->is_variable_post( $entity_id ) ? $this->variation_ids( $entity_id ) : array( $entity_id );
		if ( $this->database_failed() ) {
			throw new \RuntimeException( 'A database read failed while a product that cannot be loaded was listed.' );
		}

		$items = array();
		foreach ( $ids as $id ) {
			$items[] = $this->builder->manifest_item_unresolvable( $id );
		}
		return $items;
	}

	private static function log_listed_out_of_stock( int $post_id, string $why ): void {
		DebugLog::write( sprintf( '[smaily-connect catalog.manifest] product %d could not be loaded (%s); listed out of stock', $post_id, $why ) );
	}

	/**
	 * Whether the post's `product_type` term is `variable`. Protected so
	 * tests can stand in for the term read.
	 */
	protected function is_variable_post( int $post_id ): bool {
		return has_term( 'variable', 'product_type', $post_id );
	}

	/**
	 * The ids of the post's published and private variations — what
	 * WC_Product_Variable::get_children() reads. Protected so tests can stand
	 * in for the query.
	 *
	 * @return int[]
	 */
	protected function variation_ids( int $parent_id ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status IN ( %s, %s ) ORDER BY menu_order ASC, ID ASC",
				$parent_id,
				'product_variation',
				'publish',
				'private'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * Drops the posts one manifest batch loaded from this process's object
	 * cache (PRO-3899). WordPress keeps every post and its meta it reads until
	 * the request ends, so a walk over a whole large catalog in one Action
	 * Scheduler run can exhaust memory before it reaches the send.
	 *
	 * A cache that can flush its in-memory copy alone (WordPress's own cache,
	 * current persistent drop-ins) does exactly that. A drop-in without that
	 * support gets the batch's `posts` and `post_meta` entries deleted instead:
	 * WordPress's wp_cache_flush_runtime() fallback there only reports
	 * _doing_it_wrong, and a full wp_cache_flush() would empty the shared
	 * cache for the whole site. clean_post_cache() is not used either: it
	 * announces a changed post (WooCommerce and other plugins invalidate
	 * their own caches on it) for products that did not change.
	 *
	 * @param int[] $post_ids The batch's parent posts and their expanded units.
	 */
	private function release_loaded_posts( array $post_ids ): void {
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}
		wp_cache_delete_multiple( $post_ids, 'posts' );
		wp_cache_delete_multiple( $post_ids, 'post_meta' );
	}

	/**
	 * What this import sends for one enumerated parent post — the single
	 * decision enqueue_record() and manifest_items() share.
	 *
	 * @return array{0: bool, 1: array<int, \WC_Product|null>|null}|null Null
	 *         when nothing is sent for the post; else [is_trashed, units],
	 *         units by id (CatalogPayloadBuilder::expand_by_id(), a variation
	 *         that does not load kept as null), or null for a product
	 *         WooCommerce cannot load.
	 */
	private function units_to_send( int $entity_id ): ?array {
		if ( $this->is_collapsed_translation( $entity_id ) ) {
			return null;
		}

		// A trashed product stays in the catalog as in_stock=false (kept for the
		// order-history join / training) instead of an upsert; a published one
		// upserts with its real stock. Decided per parent post — every expanded
		// unit inherits it. Computed before the product load below so the
		// unresolvable-product fallback (PRO-1498) can still tell trash from
		// publish.
		$is_trashed = $this->post_status( $entity_id ) === 'trash';

		// Not skipped → ingest THIS post: either it IS the canonical (the common
		// multilingual case — translations were skipped above), or its canonical
		// isn't separately enumerable (draft / trashed default-language post) so
		// this published post stands in rather than being dropped (LESSONS
		// §2.11). The SKU is canonicalized by SkuResolver regardless, so the row
		// still keys on `wc-{canonical_id}`.
		$product = $this->get_product( $entity_id );
		if ( $product === null ) {
			// The SQL cursor confirmed this id IS a product/trash post row, so a
			// failed load here means WC couldn't classify it (e.g. a since-
			// deactivated gift-card plugin's product_type) — a real gap, not "not
			// ours". The import tombstones a trashed one (PRO-1498, F3-43
			// never-drop) and sends nothing for a published one (CC.4); the
			// manifest lists either out of stock (CON-312).
			return array( $is_trashed, null );
		}

		// Same fan-out as the live hook: a variable product enqueues one row per
		// variation unit; a simple product enqueues itself.
		return array( $is_trashed, $this->builder->expand_by_id( $product ) );
	}

	/**
	 * Whether the post is a translation whose canonical (default-language)
	 * post is itself a published product in this walk — the canonical sends
	 * itself on its own cursor step, so this one is skipped to avoid a
	 * duplicate SKU.
	 */
	private function is_collapsed_translation( int $entity_id ): bool {
		$canonical_id = $this->detector->get_canonical_post_id( $entity_id );
		return $canonical_id > 0 && $canonical_id !== $entity_id && $this->canonical_is_enumerated( $canonical_id );
	}

	/**
	 * The row of a trashed unit, an in_stock=false removal: capture its object now
	 * (a trashed post is still loadable) and route it as catalog.delete — the
	 * flusher stamps in_stock=false, so the engine keeps the SKU (no delete-by-key)
	 * and the order-history join survives. ensure_valid_removal() force-fills
	 * category_path/product_url with a sane fallback if they still come back
	 * blank (PRO-1498) — the removal must always reach the engine, never be
	 * silently skipped (extends F3-43's never-drop principle).
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}
	 */
	private function unavailable_row( \WC_Product $unit ): array {
		$object = $this->builder->ensure_valid_removal( $this->builder->build( $unit, '' ) );
		return array( CatalogHookHandler::EVENT_CATALOG_DELETE, (string) $unit->get_id(), array( 'object' => $object ) );
	}

	/**
	 * Same as unavailable_row(), for a trashed product id that no longer
	 * resolves to a WC_Product at all — build a minimal tombstone from the
	 * bare id (PRO-1498) so a previously-synced product still gets marked
	 * unavailable.
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}
	 */
	private function unavailable_unresolvable_row( int $product_id ): array {
		$object = $this->builder->build_unresolvable( $product_id, '' );
		return array( CatalogHookHandler::EVENT_CATALOG_DELETE, (string) $product_id, array( 'object' => $object ) );
	}

	/**
	 * Whether $canonical_id is itself a published product — i.e. it appears in
	 * this backfill's enumeration and will enqueue itself. Only then is it safe
	 * to skip a translation pointing at it; otherwise skipping would drop the
	 * product entirely (the default-language post is draft/trashed but a
	 * translation is published).
	 */
	protected function canonical_is_enumerated( int $canonical_id ): bool {
		return get_post_status( $canonical_id ) === 'publish'
			&& get_post_type( $canonical_id ) === 'product';
	}

	/**
	 * Load a product by id (publish or trash — both are loadable). Protected so
	 * tests can stub the lookup without a real WC_Product_Factory.
	 */
	protected function get_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * The post's status slug ('publish' / 'trash'). Protected so tests can drive
	 * the upsert-vs-unavailable branch without WordPress.
	 */
	protected function post_status( int $post_id ): string {
		return (string) get_post_status( $post_id );
	}
}
