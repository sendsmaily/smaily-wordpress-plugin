<?php
/**
 * GDPR rights for rec-engine personal data, via the WP Privacy API (3.8).
 *
 * @package Smaily\Connect\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Privacy;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;

/**
 * Registers a WP Privacy API exporter (Art 15) + eraser (Art 17) so the rec
 * data shows up in WordPress's own Tools → Export / Erase Personal Data.
 *
 * Scope is the single authority in docs/DATA_MODEL_GDPR.md — do NOT re-derive:
 *   - EXPORT (conservative, personal data only): engine browse_events,
 *     visitor_tokens, recommendations, email_events, and the engine customer
 *     record MINUS its decision-logic fields (segment / RFM / engagement etc.
 *     are the engine's classification, a trade secret — not subject-access
 *     data); plus the plugin's own rec-meta markers. NOT WooCommerce order /
 *     purchase data (Woo's own exporter owns that — we read rec-meta OFF an
 *     order, we never re-export the order), and NOT rec_attribution (the engine
 *     omits it from §8 — decision logic).
 *   - ERASE (complete, asymmetric to export): engine §9 DELETE (CASCADE incl.
 *     rec_attribution + visitor_tokens; 404 = already gone = success) PLUS the
 *     plugin's rec-meta markers.
 *   - Also covers the local abandoned-cart session tracker
 *     (`smly_plus_cart_session`, PRO-1195) — not rec-engine data, but the
 *     plugin's only other local PII store (PRO-1343), so it rides the same
 *     exporter/eraser. Independent of the rec-engine connection.
 *   - And the Smaily event queue (`smly_plus_event_queue`, PRO-2383) — the
 *     other local store that holds a contact's address, inside the queued
 *     payload and the F3-44 send-time exchange. Export lists what was queued
 *     and when; erase deletes what could still send and redacts what already
 *     did (EventQueue::erase_for_privacy_request()).
 *   - And the Campaign Intelligence ingest queue (`smly_rec_event_queue`,
 *     PRO-2384) — its F3-44 copy of a sent customer or order carries the
 *     address and the customer fields. Erase deletes every row whose copy
 *     carries the address (IngestQueue::delete_for_privacy_request()),
 *     independent of the engine connection. It also deletes the customer's
 *     customer and order updates still waiting to be sent, so none of them
 *     sends the customer to the engine again (PRO-3906) — once in our eraser,
 *     before the engine call, and once more after every eraser has run,
 *     because WooCommerce's customer eraser saves the profile after ours and
 *     that save queues a new customer update (PRO-3986).
 *   - And the block-checkout newsletter consent marker
 *     (`_smaily_newsletter_optin` order meta, PRO-3406/PRO-3426) — exported as
 *     the consent given on that order, removed on erasure. The Smaily contact
 *     keeps its own consent history.
 *
 * The engine call is injected via a closure so tests stand up a mock engine.
 */
class GdprHandler {

	private const GROUP_ID    = 'smaily-connect-rec-engine';
	private const ERASER_ID   = 'smaily-connect-rec-engine';
	private const EXPORTER_ID = 'smaily-connect-rec-engine';

	/** The plugin's rec-specific order meta (read off an order, never the order itself). */
	private const ORDER_META_KEYS = array(
		'_smaily_rec_id',
		'_smaily_visitor_token',
		'_smaily_rec_ctx',
		'_smaily_anon_session_id',
	);

	/**
	 * Engine customer-record fields that are DECISION LOGIC, not subject-access
	 * personal data (DATA_MODEL_GDPR.md): the engine's classification of the
	 * customer. Stripped from the export; the rest of the record is surfaced.
	 *
	 * @var string[]
	 */
	private const CUSTOMER_DECISION_FIELDS = array(
		'rfm_recency',
		'rfm_frequency',
		'rfm_monetary',
		'segment',
		'segment_confidence',
		'engagement_state',
		'engagement_state_since',
		'engagement_score',
		'engagement_trajectory',
		'loyalty_signals',
		'discount_sensitivity',
		'preferred_send_window',
		'exploration_credits',
		'exploration_credits_at',
		'cold_start_tier',
		'inferred_species',
		'inferred_attributes',
	);

	private RecEngineSettings $settings;
	private CartSessionStore $cart_store;
	private EventQueue $event_queue;
	private IngestQueue $ingest_queue;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory, CartSessionStore $cart_store, EventQueue $event_queue, IngestQueue $ingest_queue ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->cart_store     = $cart_store;
		$this->event_queue    = $event_queue;
		$this->ingest_queue   = $ingest_queue;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'wp_privacy_personal_data_erased', array( $this, 'after_erasure' ) );
	}

	/**
	 * `wp_privacy_personal_data_erased` — WordPress fires it once every eraser
	 * of the request has finished. WooCommerce's customer eraser runs after
	 * ours and saves the profile; the save fires `profile_update`, which
	 * queues a new customer update (CustomerHookHandler). Dropping the
	 * waiting updates again here keeps that row, or one any other eraser
	 * queued, from sending the erased customer to the engine (PRO-3986).
	 *
	 * @param int|string $request_id The erasure request's post id.
	 */
	public function after_erasure( $request_id ): void {
		$request = wp_get_user_request( (int) $request_id );
		if ( ! $request instanceof \WP_User_Request || $request->action_name !== 'remove_personal_data' ) {
			return;
		}

		$this->drop_waiting_updates( $request->email, $this->orders_for( $request->email ) );
	}

	/**
	 * @param array<string, mixed> $exporters
	 *
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'Smaily Connect data', 'smaily-connect' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers
	 *
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Smaily Connect data', 'smaily-connect' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Art 15 exporter callback.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$items = array_merge(
			$this->engine_export_items( $email ),
			$this->plugin_meta_export_items( $email ),
			$this->cart_session_export_items( $email ),
			$this->event_queue_export_items( $email )
		);

		return array(
			'data' => $items,
			'done' => true, // Single page — pilot volumes are modest (paginate later if needed).
		);
	}

	/**
	 * Art 17 eraser callback.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$orders = $this->orders_for( $email );

		// Before the engine call, so no update still waiting in the queue
		// sends the customer to the engine again after it (PRO-3906).
		$removed = $this->drop_waiting_updates( $email, $orders );
		if ( $this->erase_engine( $email ) ) {
			$removed = true;
		}
		if ( $this->erase_plugin_meta( $email, $orders ) ) {
			$removed = true;
		}
		if ( $this->erase_cart_sessions( $email ) ) {
			$removed = true;
		}
		if ( $this->ingest_queue->delete_for_privacy_request( $email ) > 0 ) {
			$removed = true;
		}

		$queue    = $this->event_queue->erase_for_privacy_request( $email );
		$messages = $this->event_queue_messages( $queue );
		$removed  = $removed || $messages !== array();

		return array(
			'items_removed'  => $removed,
			// Nothing personal is kept back: the rows that stay are anonymised.
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	// --- export internals --------------------------------------------------

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function engine_export_items( string $email ): array {
		if ( ! $this->settings->sending_allowed() ) {
			return array();
		}

		try {
			$export = ( $this->client_factory )()->customer_export( $email );
		} catch ( ApiException $e ) {
			if ( $e->getCode() !== 404 ) {
				\Smaily\Connect\Support\DebugLog::write( '[smaily-connect gdpr.export] ' . $e->getMessage() );
			}
			return array(); // 404 = no engine record; other errors must not fail the whole WP export.
		}

		$items = array();

		// Customer record minus decision-logic fields.
		if ( isset( $export['customer'] ) && is_array( $export['customer'] ) && $export['customer'] !== array() ) {
			$items[] = $this->group_item(
				'Recommendation profile',
				'engine-customer',
				$this->strip_decision_fields( $export['customer'] )
			);
		}

		// Activity arrays — one item per row.
		$sections = array(
			'browse_events'   => 'Browse events (Campaign Intelligence)',
			'recommendations' => 'Recommendations shown',
			'email_events'    => 'Email interaction signals',
			'visitor_tokens'  => 'Visitor tokens',
		);
		foreach ( $sections as $key => $label ) {
			if ( ! isset( $export[ $key ] ) || ! is_array( $export[ $key ] ) ) {
				continue;
			}
			foreach ( $export[ $key ] as $index => $row ) {
				if ( is_array( $row ) ) {
					$items[] = $this->group_item( $label, $key . '-' . $index, $row );
				}
			}
		}
		// NOTE: orders / order_items are deliberately NOT exported — WooCommerce's
		// own exporter owns purchase data (DATA_MODEL_GDPR.md).

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function plugin_meta_export_items( string $email ): array {
		$items = array();

		foreach ( $this->orders_for( $email ) as $order ) {
			$pairs = array();
			foreach ( self::ORDER_META_KEYS as $key ) {
				// $order->get_meta is storage-agnostic (HPOS-safe); get_post_meta
				// would miss it under HPOS, where order meta is in wc_orders_meta.
				$value = (string) $order->get_meta( $key );
				if ( $value !== '' ) {
					$pairs[ $key ] = $value;
				}
			}
			if ( $pairs !== array() ) {
				// Only the rec-meta off the order — NOT the order's line items / totals.
				$items[] = $this->group_item( 'Recommendation attribution (order meta)', 'order-' . $order->get_id(), $pairs );
			}

			if ( (string) $order->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) !== '' ) {
				// The consent evidence the block checkout kept on the order (PRO-3426).
				$items[] = $this->group_item(
					'Newsletter consent (order meta)',
					'newsletter-optin-order-' . $order->get_id(),
					array(
						'Order' => $order->get_order_number(),
						'Newsletter consent given at checkout' => 'Yes',
					)
				);
			}
		}

		$user = $this->user_for( $email );
		if ( $user instanceof \WP_User ) {
			$merged = (string) get_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, true );
			if ( $merged !== '' ) {
				$items[] = $this->group_item(
					'Recommendation identity marker',
					'user-merge',
					array( IdentityHookHandler::MERGED_META_KEY => $merged )
				);
			}
		}

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function cart_session_export_items( string $email ): array {
		$items = array();

		foreach ( $this->cart_store->rows_for_privacy_request( $email, $this->user_id_for( $email ) ) as $row ) {
			$pairs = array();
			foreach ( $row as $column => $value ) {
				if ( $column !== 'id' && $value !== null && $value !== '' ) {
					$pairs[ $column ] = $value;
				}
			}
			$items[] = $this->group_item( 'Abandoned-cart session', 'cart-session-' . $row['id'], $pairs );
		}

		return $items;
	}

	/**
	 * The Smaily queue rows queued for this address (PRO-2383). What the plugin
	 * queued and when — never the payload: it is the store's own message body,
	 * built from data WooCommerce and Smaily already own, and the row is here
	 * as a delivery record.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function event_queue_export_items( string $email ): array {
		$items = array();

		foreach ( $this->event_queue->rows_for_privacy_request( $email ) as $row ) {
			$items[] = $this->group_item(
				'Queued Smaily message',
				'event-queue-' . $row['id'],
				array(
					'event_type' => $row['event_type'],
					'created_at' => $row['created_at'],
				)
			);
		}

		return $items;
	}

	// --- erase internals ---------------------------------------------------

	private function erase_engine( string $email ): bool {
		if ( ! $this->settings->sending_allowed() ) {
			return false;
		}
		try {
			( $this->client_factory )()->customer_delete(
				$email,
				array(
					'confirm' => true,
					'reason'  => 'user_request',
				)
			);
			return true;
		} catch ( ApiException $e ) {
			if ( $e->getCode() === 404 ) {
				return true; // Already deleted — idempotent success (§9).
			}
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect gdpr.erase] ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete the customer's customer and order updates that are still waiting
	 * in the Campaign Intelligence queue (PRO-3906): the WP user with this
	 * address, and the orders billed to it. A row not yet attempted holds no
	 * copy, so IngestQueue::delete_for_privacy_request() cannot see it.
	 *
	 * @param \WC_Order[] $orders The orders billed to the address.
	 */
	private function drop_waiting_updates( string $email, array $orders ): bool {
		$deleted = 0;

		$user_id = $this->user_id_for( $email );
		if ( $user_id > 0 ) {
			$deleted += $this->ingest_queue->delete_unsent( CustomerFlusher::EVENT_CUSTOMER_UPSERT, array( $user_id ) );
		}

		$order_ids = array_map( static fn ( \WC_Order $order ): int => (int) $order->get_id(), $orders );
		if ( $order_ids !== array() ) {
			$deleted += $this->ingest_queue->delete_unsent( OrderFlusher::EVENT_ORDER_UPSERT, $order_ids );
		}

		return $deleted > 0;
	}

	/**
	 * @param \WC_Order[] $orders The orders billed to the address.
	 */
	private function erase_plugin_meta( string $email, array $orders ): bool {
		$removed = false;

		// The rec markers plus the newsletter consent marker (PRO-3426).
		$order_keys = array_merge( self::ORDER_META_KEYS, array( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) );

		foreach ( $orders as $order ) {
			$dirty = false;
			foreach ( $order_keys as $key ) {
				if ( (string) $order->get_meta( $key ) !== '' ) {
					$order->delete_meta_data( $key ); // HPOS-safe (vs delete_post_meta).
					$dirty   = true;
					$removed = true;
				}
			}
			if ( $dirty ) {
				$order->save();
			}
		}

		$user = $this->user_for( $email );
		if ( $user instanceof \WP_User && (string) get_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) !== '' ) {
			delete_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY );
			$removed = true;
		}

		return $removed;
	}

	private function erase_cart_sessions( string $email ): bool {
		return $this->cart_store->delete_rows_for_privacy_request( $email, $this->user_id_for( $email ) ) > 0;
	}

	/**
	 * Say what happened to the Smaily queue rows — WordPress shows an eraser's
	 * messages next to its result, and "removed" and "anonymised" are two
	 * different outcomes the requester is entitled to be told apart.
	 *
	 * @param array{removed: int, redacted: int} $queue
	 *
	 * @return array<int, string>
	 */
	private function event_queue_messages( array $queue ): array {
		$messages = array();

		if ( $queue['removed'] > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of queued Smaily messages deleted. */
				_n(
					'Removed %d Smaily message that was still queued for this address.',
					'Removed %d Smaily messages that were still queued for this address.',
					$queue['removed'],
					'smaily-connect'
				),
				$queue['removed']
			);
		}

		if ( $queue['redacted'] > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of already-sent Smaily event-log records anonymised. */
				_n(
					'Anonymised %d already-sent Smaily record in the event log.',
					'Anonymised %d already-sent Smaily records in the event log.',
					$queue['redacted'],
					'smaily-connect'
				),
				$queue['redacted']
			);
		}

		return $messages;
	}

	// --- helpers -----------------------------------------------------------

	/**
	 * The WP user id for an email, or 0 — used to widen the cart-session match
	 * to a row keyed by user_id (see rows_for_privacy_request()).
	 */
	private function user_id_for( string $email ): int {
		$user = $this->user_for( $email );
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * The WP user with exactly this address (any letter case), or null.
	 * get_user_by() compares under the column's collation, which on the usual
	 * databases ignores accents too, so a request for jane@… would get the
	 * account of jäne@… — another person (PRO-3986).
	 */
	private function user_for( string $email ): ?\WP_User {
		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		return strtolower( trim( (string) $user->user_email ) ) === strtolower( trim( $email ) ) ? $user : null;
	}

	/**
	 * The customer's orders as WC_Order objects (storage-agnostic — works under
	 * both legacy posts and HPOS, and gives us $order->get_meta for the rec-meta).
	 * The ids come from the active order table with no status filter, so an
	 * order with a custom status (registered or not) or in the trash is found
	 * too, and the address matches in any letter case (PRO-3908) but not
	 * across accents (PRO-3986) —
	 * `wc_get_orders()` sees only the registered statuses.
	 * Protected as a unit-test seam: defining `wc_get_order` in the unit suite
	 * would leak into every later test that relies on it being absent.
	 *
	 * @return \WC_Order[]
	 */
	protected function orders_for( string $email ): array {
		global $wpdb;
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$hpos = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL holds table names only; the address is prepare()d.
		$ids = $wpdb->get_col( $wpdb->prepare( self::order_ids_sql( $hpos, $wpdb->prefix ), $email ) );
		$out = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof \WC_Order ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * The query for the ids of the orders billed to one address (one `%s`) in
	 * the active order table: `wc_orders.billing_email` under HPOS, the
	 * `_billing_email` post meta under legacy storage. No status filter. Both
	 * sides are lowercased and then compared as bytes: `LOWER( col ) =
	 * LOWER( %s )` alone still compares under the column's collation, which on
	 * the usual databases ignores accents, so jane@… would find jäne@…'s
	 * orders (PRO-3986). PURE (no DB) so both storage paths are unit-testable.
	 */
	public static function order_ids_sql( bool $hpos, string $prefix ): string {
		$spec = OrderBackfillJob::table_spec( $hpos, $prefix );
		if ( $hpos ) {
			return "SELECT {$spec['id_col']} FROM {$spec['table']} WHERE CAST( LOWER( billing_email ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY ) ORDER BY {$spec['id_col']} ASC";
		}
		return "SELECT p.{$spec['id_col']} FROM {$spec['table']} p INNER JOIN {$prefix}postmeta m ON m.post_id = p.{$spec['id_col']} WHERE m.meta_key = '_billing_email' AND CAST( LOWER( m.meta_value ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY ) ORDER BY p.{$spec['id_col']} ASC";
	}

	/**
	 * @param array<string, mixed> $customer
	 *
	 * @return array<string, mixed>
	 */
	private function strip_decision_fields( array $customer ): array {
		foreach ( self::CUSTOMER_DECISION_FIELDS as $field ) {
			unset( $customer[ $field ] );
		}
		return $customer;
	}

	/**
	 * Build one WP Privacy export item from a flat associative array.
	 *
	 * @param array<string, mixed> $pairs
	 *
	 * @return array<string, mixed>
	 */
	private function group_item( string $group_label, string $item_id, array $pairs ): array {
		$data = array();
		foreach ( $pairs as $name => $value ) {
			$data[] = array(
				'name'  => (string) $name,
				'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
			);
		}
		return array(
			'group_id'    => self::GROUP_ID,
			'group_label' => $group_label,
			'item_id'     => self::GROUP_ID . '-' . $item_id,
			'data'        => $data,
		);
	}
}
