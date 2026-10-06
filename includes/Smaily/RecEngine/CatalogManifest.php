<?php
/**
 * Sends the store's complete product list to POST
 * /api/v1/ingest/catalog/manifest once a night (§3c).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\RecEngine\Backfill\AbstractBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;
use Smaily\Connect\Support\DebugLog;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_catalog_manifest` — the nightly
 * catalog manifest (contract v1.12.0 §3c, PRO-3859). Once a night, at 03:00
 * store time, it sends every product the catalog import sends, as
 * `{sku, in_stock}` only. The engine tombstones each product missing from the
 * list and takes the list's stock where it differs, so a lost delete or stock
 * change is healed within a night.
 *
 * The list is CatalogBackfillJob::manifest_items(): the import's own walk,
 * multilingual collapse and variation expansion, keyed by SkuResolver through
 * CatalogPayloadBuilder — so a manifest key can never differ from the key the
 * catalog sync sends (a differing key reads to the engine as a deleted
 * product).
 *
 * A partial or premature list would tombstone real products, so the night is
 * skipped — nothing sent — when the engine refuses the store (PRO-1893), the
 * products import runs or waits to start, catalog changes still wait in the
 * queue, or building the list fails. Those skips go to the debug log only. A
 * store with more than MAX_PRODUCTS items also sends nothing (the contract
 * forbids a partial list), and that skip IS an Event Log row, marked failed
 * with a plain reason, because only the merchant can see it there.
 *
 * Each night that gets past the skips is ONE `catalog.manifest` Event Log
 * row: the stored exchange (F3-44) holds the request (trimmed to ~10 KB) and
 * the engine's answer (removed, stock_fixed, guard_tripped, …). A failed row
 * the merchant retries goes back to pending, and the next night's manifest
 * is sent under it instead of a new row.
 *
 * Not final: tests subclass to shrink the limit and to stub the gates.
 */
class CatalogManifest {

	public const HOOK       = 'smly_rec_catalog_manifest';
	public const AS_GROUP   = 'smaily-rec-catalog-manifest';
	public const EVENT_TYPE = 'catalog.manifest';

	/** Contract §3c: at most this many items, in one request. */
	public const MAX_PRODUCTS = 50000;

	/** Store-time hour the manifest runs at. */
	public const RUN_HOUR = 3;

	/**
	 * Engine timeout for the one request. A 50,000-item list is a far bigger
	 * body than a 100-item ingest batch, and the job runs in the background.
	 */
	public const TIMEOUT_SECONDS = 60;

	/** Cap (chars) on each stored exchange field, as the flushers (F3-44). */
	private const EXCHANGE_MAX = 10000;

	/** The engine's answer fields the Event Log keeps. */
	private const RESPONSE_FIELDS = array( 'products_in_manifest', 'removed', 'stock_fixed', 'missing_in_engine', 'guard_tripped', 'guard_reason', 'would_remove' );

	private IngestQueue $queue;
	private RecEngineSettings $settings;
	private CatalogBackfillJob $catalog;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory
	 */
	public function __construct( IngestQueue $queue, RecEngineSettings $settings, callable $client_factory, CatalogBackfillJob $catalog ) {
		$this->queue          = $queue;
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->catalog        = $catalog;
	}

	/**
	 * The next RUN_HOUR:00 in the site's timezone after $now — the first run
	 * of the daily recurring action.
	 */
	public static function next_run_timestamp( int $now ): int {
		$run = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( self::RUN_HOUR, 0 );
		if ( $run->getTimestamp() <= $now ) {
			$run = $run->modify( '+1 day' )->setTime( self::RUN_HOUR, 0 );
		}
		return $run->getTimestamp();
	}

	public function run(): void {
		$skip = $this->skip_reason();
		if ( $skip !== '' ) {
			DebugLog::write( '[smaily-connect catalog.manifest] not sent tonight: ' . $skip );
			return;
		}

		$limit = $this->max_products();
		try {
			$products = $this->catalog->manifest_items( $limit );
		} catch ( \Throwable $e ) {
			DebugLog::write( '[smaily-connect catalog.manifest] not sent tonight: building the product list failed: ' . $e->getMessage() );
			return;
		}

		$id = $this->row_id();
		if ( $id === null ) {
			DebugLog::write( '[smaily-connect catalog.manifest] not sent tonight: the Event Log row could not be written' );
			return;
		}

		if ( count( $products ) > $limit ) {
			$this->queue->mark_failed(
				$id,
				sprintf(
					/* translators: %s: the most products the nightly product list can hold, e.g. "50,000". */
					__( 'Not sent: the store has more than %s products, the most the nightly product list can hold.', 'smaily-connect' ),
					number_format_i18n( $limit )
				)
			);
			$this->queue->store_exchange(
				$id,
				null,
				(string) wp_json_encode(
					array(
						'outcome' => 'skipped',
						'reason'  => 'too_many_products',
						'limit'   => $limit,
					)
				)
			);
			return;
		}

		$sent = $this->trim( (string) wp_json_encode( array( 'products' => $products ) ) );
		try {
			$response = ( $this->client_factory )()->catalog_manifest( $products );
		} catch ( ApiException $e ) {
			$this->queue->mark_failed( $id, sprintf( 'http_%d %s', $e->getCode(), $e->error_code() ) );
			$this->queue->store_exchange(
				$id,
				$sent,
				$this->trim(
					(string) wp_json_encode(
						array(
							'http'       => $e->getCode(),
							'outcome'    => 'http_error',
							'error_code' => $e->error_code(),
							'message'    => $e->getMessage(),
						)
					)
				)
			);
			return;
		}

		$this->queue->mark_sent( $id );
		$this->queue->store_exchange(
			$id,
			$sent,
			(string) wp_json_encode(
				array_merge(
					array(
						'http'    => 200,
						'outcome' => 'accepted',
					),
					array_intersect_key( $response, array_flip( self::RESPONSE_FIELDS ) )
				)
			)
		);
	}

	/** Why tonight's manifest must not be sent, or '' when it may. */
	private function skip_reason(): string {
		if ( ! $this->settings->sending_allowed() ) {
			return 'the engine connection is missing or refuses this store';
		}
		if ( $this->import_active() ) {
			return 'the products import is running or waiting to start';
		}
		if ( $this->catalog_changes_waiting() ) {
			return 'catalog changes still wait in the queue';
		}
		return '';
	}

	/**
	 * A row a merchant retried waits as pending; tonight's manifest goes out
	 * under it. Otherwise a new row.
	 */
	private function row_id(): ?int {
		$pending = $this->queue->pending( 1, array( self::EVENT_TYPE ) );
		if ( $pending !== array() ) {
			return (int) $pending[0]['id'];
		}
		// No flusher drains this row — run() sends it itself. enqueue() still
		// makes sure its default hook (the catalog flush, scheduled anyway)
		// has a pass queued; that flusher leaves this event type alone. Our
		// own HOOK is not passed: an extra manifest run off the 03:00 slot is
		// not something a row write should start.
		return $this->queue->enqueue( self::EVENT_TYPE, '', array() );
	}

	/** The products import's state row is `running` from start() — queued or mid-walk. */
	protected function import_active(): bool {
		$row = AbstractBackfillJob::read_state( 'products' );
		return is_array( $row ) && $row['status'] === BackfillJobInterface::STATUS_RUNNING;
	}

	protected function catalog_changes_waiting(): bool {
		return $this->queue->has_pending(
			array(
				CatalogHookHandler::EVENT_CATALOG_UPSERT,
				CatalogHookHandler::EVENT_CATALOG_DELETE,
				CatalogHookHandler::EVENT_CATALOG_REMOVE,
			)
		);
	}

	/** Seam: tests prove the over-limit path without creating 50,001 products. */
	protected function max_products(): int {
		return self::MAX_PRODUCTS;
	}

	private function trim( string $text ): string {
		return strlen( $text ) <= self::EXCHANGE_MAX
			? $text
			: substr( $text, 0, self::EXCHANGE_MAX ) . '…[truncated]';
	}
}
