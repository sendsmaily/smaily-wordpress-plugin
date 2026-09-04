<?php
/**
 * GDPR rights for rec-engine personal data, via the WP Privacy API (3.8).
 *
 * @package Smaily\Connect\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Privacy;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;

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

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory, CartSessionStore $cart_store ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->cart_store     = $cart_store;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
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
		$items = $this->engine_export_items( $email );
		foreach ( $this->plugin_meta_export_items( $email ) as $item ) {
			$items[] = $item;
		}
		foreach ( $this->cart_session_export_items( $email ) as $item ) {
			$items[] = $item;
		}

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
		$removed = $this->erase_engine( $email );
		if ( $this->erase_plugin_meta( $email ) ) {
			$removed = true;
		}
		if ( $this->erase_cart_sessions( $email ) ) {
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	// --- export internals --------------------------------------------------

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function engine_export_items( string $email ): array {
		if ( ! $this->settings->is_connected() ) {
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
		}

		$user = get_user_by( 'email', $email );
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

	// --- erase internals ---------------------------------------------------

	private function erase_engine( string $email ): bool {
		if ( ! $this->settings->is_connected() ) {
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

	private function erase_plugin_meta( string $email ): bool {
		$removed = false;

		foreach ( $this->orders_for( $email ) as $order ) {
			$dirty = false;
			foreach ( self::ORDER_META_KEYS as $key ) {
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

		$user = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User && (string) get_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) !== '' ) {
			delete_user_meta( $user->ID, IdentityHookHandler::MERGED_META_KEY );
			$removed = true;
		}

		return $removed;
	}

	private function erase_cart_sessions( string $email ): bool {
		return $this->cart_store->delete_rows_for_privacy_request( $email, $this->user_id_for( $email ) ) > 0;
	}

	// --- helpers -----------------------------------------------------------

	/**
	 * The WP user id for an email, or 0 — used to widen the cart-session match
	 * to a row keyed by user_id (see rows_for_privacy_request()).
	 */
	private function user_id_for( string $email ): int {
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * The customer's orders as WC_Order objects (storage-agnostic — works under
	 * both legacy posts and HPOS, and gives us $order->get_meta for the rec-meta).
	 *
	 * @return \WC_Order[]
	 */
	private function orders_for( string $email ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'limit'         => -1,
				'billing_email' => $email,
			)
		);
		$out    = array();
		foreach ( ( is_array( $orders ) ? $orders : array() ) as $order ) {
			if ( $order instanceof \WC_Order ) {
				$out[] = $order;
			}
		}
		return $out;
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
