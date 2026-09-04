<?php
/**
 * Single source of truth for WC_Order → rec-engine order-object mapping.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\Support\AttributionShape;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;
use Smaily\Connect\Smaily\RecEngine\Support\RecId;
use Smaily\Connect\Smaily\RecEngine\Support\SkuResolver;
use Smaily\Connect\Support\DebugLog;

/**
 * Translates a WC_Order into one entry of the `POST /api/v1/ingest/orders`
 * `orders[]` array (RECENGINE_API_CONTRACT.md §5, W5 batch contract).
 *
 * Order natural key is `(tenant_id, external_order_id)`; the customer is
 * referenced by `customer_email` (lowercased) and **auto-created engine-side**
 * if absent (W4 email identity), so guest orders need no separate
 * customer.upsert — the OrderFlusher is keyed on the order id, and guest
 * orders have order ids. Items are fully replaced on re-ingest (the order is
 * the unit); send the complete item set every time.
 *
 * Status mapping (F3-22): WC order statuses are mapped to the engine's 4-value
 * enum. completed/processing/cancelled/refunded map directly; `on-hold` maps
 * to `processing` (a placed order awaiting payment — BACS/COD/cheque — is a
 * real purchase intent, common in the pilot's market). Anything else
 * (pending / failed / draft / custom) is NOT a confirmed purchase and maps to
 * `''` — the OrderHookHandler skips those orders (and logs unknown statuses).
 * The engine requires the enum, so the builder never sends a raw WC status.
 *
 * Empty-value policy mirrors the other builders (F2-10): an OPTIONAL field
 * whose source is empty is OMITTED, never sent as "" / null. Always-present:
 * event_id, external_order_id, customer_email, ordered_at, total_amount,
 * currency, status, items.
 *
 * Datetime fields go through IsoDate (F3-21) — the engine's strict Zod
 * `.datetime()` requires the `Z` form, not `+00:00`.
 *
 * ALL money fields are GROSS — tax-inclusive, what the customer paid
 * (contract v1.4.0 §5 "Amount semantics", PRO-1241): `total_amount` is the
 * grand total as charged (WC get_total() — products + shipping + tax −
 * discounts, already gross); `items[].line_total` is
 * `get_total() + get_total_tax()` (bare get_total() is NET — the pre-1.4.0
 * bug that understated per-SKU revenue ~24% on the pilot); `unit_price` is
 * the same gross basis ÷ qty; both `discount_amount` fields include the
 * discounted tax. Sender invariant: Σ items[].line_total + shipping ≈
 * total_amount (± rounding). Do NOT reintroduce a bare get_total()/
 * get_total_discount() money read here.
 *
 * Return signals (contract v1.8.0 §5, PRO-1633) are derived from the ORDER'S
 * OWN REFUND STATE on every build — never from a one-shot event payload. The
 * engine fully REPLACES an order's items on re-ingest, so a later sync that
 * omits `returned_at` ERASES the return; deriving it here means the live hook,
 * a flusher retry and the order backfill all re-send it for free (they all
 * build through this class at send time).
 *
 * Attribution is async engine-side (N-10): this builder only forwards the
 * stored attribution signals (smaily_rec_id / smaily_visitor_token /
 * smaily_rec_ctx / session_id), stamped onto order meta at checkout by the
 * email HookHandler. The ingest response carries no attribution counts.
 * Every one of them is shape-checked at SEND time, against the same definitions
 * the capture path applies (Support\RecId for the uuid `smaily_rec_id`,
 * Support\AttributionShape for the other three) — an off-shape value is OMITTED,
 * never truncated, and the order ingests without that signal. Send-time is where
 * this has to happen as well as at capture: a value cookied by a producer that
 * did not check it outlives the fix by the cookie's TTL, it is already sitting on
 * orders placed before the fix, and those orders retry through the flusher for
 * the queue's lifetime (PRO-1710 for the rec_id, PRO-1942 for the rest).
 *
 * Not final: tests subclass to stub WC reads. Same rationale as the other
 * PayloadBuilders.
 */
class OrderPayloadBuilder {

	/**
	 * WC order status → engine status enum, for the statuses with a SPECIFIC
	 * target. A sale status NOT listed here — a merchant's custom fulfilment
	 * status (e.g. a shipping plugin's `label-printed` / `shipped`) — is still a
	 * confirmed purchase: it falls through to DEFAULT_SALE_STATUS rather than
	 * being dropped. Only NON_SALE_STATUSES are excluded. See map_status().
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = array(
		'completed'  => 'completed',
		'processing' => 'processing',
		'cancelled'  => 'cancelled',
		'refunded'   => 'refunded',
	);

	/**
	 * WC statuses that are NOT a confirmed purchase — never ingested (map to '').
	 * This is the DENYLIST: everything else (the STATUS_MAP entries AND any
	 * custom/unknown status) is treated as a sale. This deliberately defaults
	 * custom statuses THROUGH (DECISIONS F3-42) — the old 5-key allowlist
	 * silently dropped every custom status, so the pilot's `label-printed`
	 * orders never reached the engine. `on-hold` is here (NOT a sale — payment
	 * not yet captured), per the engine team's 2026-06-19 brief, reversing
	 * F3-22's on-hold→processing; when payment clears the order moves to
	 * processing/completed and is sent then. Single source (CC-9) for the order
	 * backfill's SQL filter, which enumerates `status NOT IN` this list.
	 *
	 * @var string[]
	 */
	private const NON_SALE_STATUSES = array(
		'pending',         // payment not received yet.
		'on-hold',         // awaiting payment (BACS / cheque) — not yet a sale (F3-42).
		'failed',          // payment failed.
		'checkout-draft',  // WC Blocks draft order, never placed.
		'draft',           // WP draft.
		'auto-draft',      // WP auto-draft (order being created in admin).
		'trash',           // trashed order.
	);

	/**
	 * Engine enum for a sale status with no explicit STATUS_MAP entry — a
	 * merchant's custom fulfilment status. Conservative: `processing` (a
	 * confirmed purchase in progress), NOT `completed`, so a custom state isn't
	 * over-claimed as finished; if the order later truly completes, the live
	 * hook re-sends it as `completed`. The engine `status` is a strict enum, so
	 * a custom WC status can't pass through verbatim — it must resolve to a
	 * valid enum, and this is it. (DECISIONS F3-42.)
	 */
	private const DEFAULT_SALE_STATUS = 'processing';

	/**
	 * Contract §5: `return_reason_raw` is "truncated to 500 chars, never
	 * rejected" — we truncate at the source rather than rely on that.
	 */
	private const RETURN_REASON_MAX = 500;

	/** WC's own meta key linking a refund line back to the order line it refunds. */
	private const META_REFUNDED_ITEM_ID = '_refunded_item_id';

	/** Order-meta keys the email HookHandler stamps the attribution cookies into. */
	private const META_REC_ID        = '_smaily_rec_id';
	private const META_VISITOR_TOKEN = '_smaily_visitor_token';
	private const META_REC_CTX       = '_smaily_rec_ctx';
	private const META_SESSION_ID    = '_smaily_anon_session_id';

	/**
	 * Build the order wire object for one WC_Order + its queue event_uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WC_Order $order, string $event_uuid ): array {
		$payload = array(
			'event_id'          => $event_uuid,
			'external_order_id' => (string) $order->get_id(),
			// Customer reference (W4 email identity); the engine auto-creates
			// the customer from this when it doesn't exist yet.
			'customer_email'    => strtolower( trim( (string) $order->get_billing_email() ) ),
			'ordered_at'        => $this->ordered_at( $order ),
			'total_amount'      => (float) $order->get_total(),
			'currency'          => $this->currency( $order ),
			'status'            => $this->map_status( (string) $order->get_status() ),
			'items'             => $this->items( $order ),
		);

		// GROSS discount (v1.4.0 §5): get_total_discount() defaults to
		// ex-tax; `false` includes the tax share of the discount.
		$discount = (float) $order->get_total_discount( false );
		if ( $discount > 0.0 ) {
			$payload['discount_amount'] = round( $discount, 4 );
		}

		// Attribution signals stamped onto order meta at checkout — omitted
		// when absent (F2-10). The engine stores them; matching is async.
		$rec_id = $this->meta( $order, self::META_REC_ID );
		if ( RecId::is_valid( $rec_id ) ) {
			$payload['smaily_rec_id'] = $rec_id;
		} elseif ( $rec_id !== '' ) {
			// PRO-1710: the engine validates smaily_rec_id as a UUID per ORDER
			// (D6), so a non-UUID value rejects the whole order permanently.
			// The order matters more than the attribution: drop the field and
			// let the order ingest un-attributed. The meta stays on the order
			// (merchant data isn't rewritten), and the send-time exchange
			// stored on the queue row (F3-44) shows exactly what went out.
			DebugLog::write(
				sprintf(
					'[smaily-connect orders] order %d: dropped a non-uuid _smaily_rec_id from the payload — the order ingests without attribution',
					$order->get_id()
				)
			);
		}
		$visitor_token = $this->meta( $order, self::META_VISITOR_TOKEN );
		if ( AttributionShape::is_visitor_token( $visitor_token ) ) {
			$payload['smaily_visitor_token'] = $visitor_token;
		} elseif ( $visitor_token !== '' ) {
			$this->log_off_shape( $order, self::META_VISITOR_TOKEN );
		}
		$rec_ctx = $this->meta( $order, self::META_REC_CTX );
		if ( AttributionShape::is_context( $rec_ctx ) ) {
			$payload['smaily_rec_ctx'] = $rec_ctx;
		} elseif ( $rec_ctx !== '' ) {
			$this->log_off_shape( $order, self::META_REC_CTX );
		}
		$session_id = $this->meta( $order, self::META_SESSION_ID );
		if ( AttributionShape::is_session_id( $session_id ) ) {
			$payload['session_id'] = $session_id;
		} elseif ( $session_id !== '' ) {
			$this->log_off_shape( $order, self::META_SESSION_ID );
		}

		return $payload;
	}

	/**
	 * Map a WC order status to the engine enum. Returns '' ONLY for a NON-sale
	 * status (NON_SALE_STATUSES: pending / failed / draft / trash / …) so the
	 * order is skipped; every other status — the explicit STATUS_MAP entries AND
	 * any custom/unknown status — resolves to a sale enum (custom statuses → the
	 * conservative DEFAULT_SALE_STATUS). The OrderHookHandler uses this to decide
	 * whether to enqueue at all. WC's get_status() returns the un-prefixed slug,
	 * but a 'wc-' prefix is normalised defensively. (DECISIONS F3-42.)
	 */
	public function map_status( string $wc_status ): string {
		$key = ( strpos( $wc_status, 'wc-' ) === 0 ) ? substr( $wc_status, 3 ) : $wc_status;
		if ( $key === '' || in_array( $key, self::NON_SALE_STATUSES, true ) ) {
			return '';
		}
		return self::STATUS_MAP[ $key ] ?? self::DEFAULT_SALE_STATUS;
	}

	/**
	 * The un-prefixed WC statuses that are NOT a confirmed purchase — the cohort
	 * order ingest EXCLUDES. Single source (CC-9) for the order backfill's SQL
	 * status filter (3.5.2): the backfill enumerates orders whose status is NOT
	 * in this list (so it picks up custom sale statuses too), mirroring
	 * map_status()'s denylist rule — the two can't drift. The flusher's own
	 * map_status==='' skip is the safety net for any status the SQL prefixing
	 * doesn't catch. (DECISIONS F3-42.)
	 *
	 * @return string[] e.g. ['pending', 'failed', 'checkout-draft', 'draft', 'auto-draft', 'trash'].
	 */
	public static function non_sale_wc_statuses(): array {
		return self::NON_SALE_STATUSES;
	}

	private function ordered_at( \WC_Order $order ): string {
		$timestamp = $this->order_timestamp( $order );
		return $timestamp > 0 ? IsoDate::to_z( $timestamp ) : '';
	}

	private function order_timestamp( \WC_Order $order ): int {
		$date = $order->get_date_created();
		if ( ! is_object( $date ) || ! method_exists( $date, 'getTimestamp' ) ) {
			return 0;
		}
		return (int) $date->getTimestamp();
	}

	private function currency( \WC_Order $order ): string {
		$currency = trim( (string) $order->get_currency() );
		return $currency !== '' ? $currency : 'EUR';
	}

	/**
	 * Order line items → wire `items[]`. Shipping / fee / tax / coupon lines
	 * are skipped (only product lines). The engine keys order items on SKU
	 * (§5 `items[].sku` is required); SkuResolver (PRO-1224) supplies it — the
	 * `woo-{id}` platform key (never the merchant SKU field), so a store that
	 * never set SKUs stays fully ingestable and the order line joins the catalog
	 * row on the same key. A DELETED product keeps its line: qty / totals come
	 * from the line-item SNAPSHOT (which survives deletion), and the SKU keys
	 * from the stored product/variation id, or — when current WC has zeroed those
	 * (empirical, WC 10.7) — from the order-item id
	 * (`woo-oi-{item_id}`). So a product line is NEVER dropped (F3-43): the order
	 * is never silently lost for one unkeyable line. An order
	 * with NO product lines at all (only shipping/fee) still wires an empty
	 * items[] and OrderFlusher terminal-skips it (engine requires min 1) — that
	 * is the only remaining empty-items case.
	 *
	 * Amounts are GROSS (v1.4.0 §5, PRO-1241): line_total is the charged
	 * amount incl. its tax share, after line discounts —
	 * get_total() + get_total_tax(); unit_price is the same gross basis ÷ qty;
	 * the per-line discount is the gross subtotal-vs-total delta
	 * ((subtotal + subtotal_tax) − (total + total_tax)), omitted when zero.
	 * Rounded to 4 decimals (float-add noise; the contract example itself
	 * carries a 3-decimal unit_price).
	 *
	 * Return signals (v1.8.0 §5, PRO-1633) come from the order's refunds — see
	 * returns_by_item().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items( \WC_Order $order ): array {
		$returns = $this->returns_by_item( $order );
		$items   = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			// SkuResolver never returns '' for an order line (F3-43) — a deleted
			// product whose ids are zeroed keys on the order-item id — so a
			// product line is always serialised, never dropped.
			$product = $item->get_product();
			$sku     = ( $product instanceof \WC_Product )
				? SkuResolver::resolve( $product )
				: SkuResolver::resolve_order_item( $item );

			$qty            = (int) $item->get_quantity();
			$subtotal_gross = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
			$total_gross    = (float) $item->get_total() + (float) $item->get_total_tax();

			$line = array(
				'sku'        => $sku,
				'qty'        => $qty,
				'unit_price' => $qty > 0 ? round( $total_gross / $qty, 4 ) : 0.0,
				'line_total' => round( $total_gross, 4 ),
			);

			$discount = $subtotal_gross - $total_gross;
			if ( $discount > 0.0 ) {
				$line['discount_amount'] = round( $discount, 4 );
			}

			$returned = $returns[ (int) $item->get_id() ] ?? null;
			if ( $returned !== null && $qty > 0 && $returned['qty'] >= $qty && $returned['timestamp'] > 0 ) {
				$line['returned_at'] = IsoDate::to_z( $returned['timestamp'] );
				if ( $returned['reason'] !== '' ) {
					// Diagnostic only, and always the MERCHANT-side note (WC's
					// refund `reason` field) — never buyer-written text (§5).
					$line['return_reason_raw'] = $returned['reason'];
				}
				// `return_reason_standardised` is deliberately never sent:
				// WooCommerce has no structured return taxonomy anywhere, and
				// §5 says keyword-guessing free text into the enum is worse
				// than sending nothing.
			}

			$items[] = $line;
		}

		return $items;
	}

	/**
	 * The order's refund state, collapsed per ORDER line item.
	 *
	 * A WooCommerce partial refund fires no webhook and never changes the order
	 * status, so the refund objects stored on the order are the only record —
	 * and they are queried fresh on every build so a re-sync can't erase a
	 * return the engine already has (§5 "items are fully replaced on re-ingest").
	 *
	 * Each refund line carries `_refunded_item_id` (the order line it refunds)
	 * and a NEGATIVE quantity; quantities accumulate across several refunds of
	 * the same line, and the LATEST contributing refund supplies the date and
	 * the reason — it is the refund that completed the return.
	 *
	 * A line counts as returned only when the FULL line quantity has come back
	 * (see items()). The contract types `returned_at` per LINE with no
	 * per-quantity mechanism, and its consumers ("was it kept?", the 180-day
	 * same-SKU suppression) read it as "the customer does not have this" — so 1
	 * of 3 refunded is conservatively "kept". An amount-only refund (no line
	 * quantity) marks nothing: the money moved, the goods did not.
	 *
	 * @return array<int, array{qty:int, timestamp:int, reason:string}> keyed by order-item id.
	 */
	private function returns_by_item( \WC_Order $order ): array {
		$refunds = $this->order_refunds( $order );
		if ( $refunds === array() ) {
			return array();
		}

		$returns = array();
		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}

			// A refund with no own date falls back to the order date — the same
			// basis the engine's full-refund derivation uses, and stable across
			// re-syncs (a now() fallback would move the date every send).
			$date      = $refund->get_date_created();
			$timestamp = ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) )
				? (int) $date->getTimestamp()
				: 0;
			if ( $timestamp <= 0 ) {
				$timestamp = $this->order_timestamp( $order );
			}
			$reason = $this->clamp_reason( (string) $refund->get_reason() );

			foreach ( $refund->get_items() as $refunded_item ) {
				if ( ! $refunded_item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$item_id = (int) $refunded_item->get_meta( self::META_REFUNDED_ITEM_ID );
				$qty     = abs( (int) $refunded_item->get_quantity() );
				if ( $item_id <= 0 || $qty <= 0 ) {
					continue;
				}

				$current = $returns[ $item_id ] ?? array(
					'qty'       => 0,
					'timestamp' => 0,
					'reason'    => '',
				);

				$current['qty'] += $qty;
				if ( $timestamp >= $current['timestamp'] ) {
					$current['timestamp'] = $timestamp;
					$current['reason']    = $reason;
				}

				$returns[ $item_id ] = $current;
			}
		}

		return $returns;
	}

	/**
	 * The order's refund objects. Protected so unit tests can supply them —
	 * the runtime WC_Order shim the unit suite mocks against is minimal and
	 * carries no get_refunds(). The real read is integration-covered.
	 *
	 * @return array<int, mixed>
	 */
	protected function order_refunds( \WC_Order $order ): array {
		if ( ! method_exists( $order, 'get_refunds' ) ) {
			return array();
		}
		return $order->get_refunds();
	}

	private function clamp_reason( string $raw ): string {
		$text = trim( $raw );
		if ( $text === '' ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::RETURN_REASON_MAX );
		}
		return substr( $text, 0, self::RETURN_REASON_MAX );
	}

	/**
	 * Shape-only log line for an attribution value that did not reach the wire
	 * (PRO-1942). The value itself is never logged — it is untrusted cookie
	 * input, and the meta stays on the order either way (merchant data isn't
	 * rewritten); the send-time exchange stored on the queue row (F3-44) shows
	 * exactly what went out.
	 */
	private function log_off_shape( \WC_Order $order, string $meta_key ): void {
		DebugLog::write(
			sprintf(
				'[smaily-connect orders] order %d: dropped an off-shape %s from the payload — the order ingests without that attribution signal',
				$order->get_id(),
				$meta_key
			)
		);
	}

	private function meta( \WC_Order $order, string $key ): string {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		return trim( (string) $order->get_meta( $key ) );
	}
}
