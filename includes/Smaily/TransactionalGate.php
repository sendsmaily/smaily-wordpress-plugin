<?php
/**
 * Full gate check for a transactional-email send (PRO-1504 Stage 2).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\Credentials;

/**
 * Single source of truth for "is a transactional send allowed to happen for
 * this trigger right now" — reused by both the WC hook handler (decides
 * whether to attempt a send) and the native-WC-email suppression filters
 * (design point 6: suppression is active ONLY while this SAME gate holds).
 * Duplicating the four conditions in two places would let them drift.
 *
 * Deliberately NO consent/opt-out gate (platform answer Q7, PRO-1380):
 * transactional sends override marketing opt-out, so this never touches
 * ProfilingConsent or any marketing-consent check.
 *
 * All four conditions must hold:
 *   1. the transactional-emails enablement toggle is on;
 *   2. that trigger's own toggle is on;
 *   3. a mapping row exists for the trigger (account_key='transactional',
 *      language='default' — this account has no per-language variant);
 *   4. the mapped account's credentials resolve (subdomain+username+password
 *      all set).
 *
 * Not final: tests inject resolver/credentials doubles.
 */
class TransactionalGate {

	public const TRIGGER_ORDER_CONFIRMATION    = 'order_confirmation';
	public const TRIGGER_SHIPPING_CONFIRMATION = 'shipping_confirmation';

	private const OPTION_ENABLED = 'smly_plus_transactional_emails_enabled';

	/** Bare WC status slugs (no 'wc-' prefix) treated as "shipped" — Settings persists this option. */
	public const OPTION_SHIPPED_STATUSES = 'smly_plus_shipped_order_statuses';

	/**
	 * Single source of truth for everything that varies per trigger type —
	 * was four separate ternaries split across this class and
	 * TransactionalFlusher (trigger_toggle_option / meta_key_for /
	 * event_type_for / trigger_type_for), which a third trigger could add to
	 * only some of them and silently drift. TransactionalFlusher's own
	 * meta_key_for()/event_type_for() delegate to this map instead of
	 * keeping a parallel copy.
	 *
	 * Public (not private) so TransactionalFlusher's own public EVENT_TYPE_*
	 * constants can be defined AS a reference into this map, rather than a
	 * second literal copy of the same wire strings.
	 *
	 * @var array<string, array{toggle_option: string, event_type: string, meta_key: string}>
	 */
	public const TRIGGERS = array(
		self::TRIGGER_ORDER_CONFIRMATION    => array(
			'toggle_option' => 'smly_plus_order_confirmation_enabled',
			'event_type'    => 'transactional.order_confirmation',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- config value only, consumed as a per-order-id meta guard key (get/update_meta_data by order id), never a WP_Query meta_query; WPCS flags any 'meta_key' array key regardless of context.
			'meta_key'      => '_smly_plus_transactional_order_confirmation_status',
		),
		self::TRIGGER_SHIPPING_CONFIRMATION => array(
			'toggle_option' => 'smly_plus_shipping_confirmation_enabled',
			'event_type'    => 'transactional.shipping_confirmation',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- config value only, consumed as a per-order-id meta guard key (get/update_meta_data by order id), never a WP_Query meta_query; WPCS flags any 'meta_key' array key regardless of context.
			'meta_key'      => '_smly_plus_transactional_shipping_confirmation_status',
		),
	);

	private Credentials $credentials;
	private WorkflowResolverInterface $resolver;

	public function __construct( Credentials $credentials, WorkflowResolverInterface $resolver ) {
		$this->credentials = $credentials;
		$this->resolver    = $resolver;
	}

	/**
	 * Returns the matched workflow when every gate condition holds for
	 * $trigger_type, or null when any one of them doesn't — the caller
	 * treats null as "do nothing" (no send, no suppression).
	 */
	public function resolve_if_open( string $trigger_type ): ?WorkflowMatch {
		if ( ! (bool) get_option( self::OPTION_ENABLED, false ) ) {
			return null;
		}

		if ( ! (bool) get_option( self::config( $trigger_type )['toggle_option'], false ) ) {
			return null;
		}

		$match = $this->resolver->resolve_workflow( $trigger_type, null );
		if ( $match === null ) {
			return null;
		}

		$set = $this->credentials->get( $match->account_key );
		if ( $set === null || ! $set->is_complete() ) {
			return null;
		}

		return $match;
	}

	/** The wire event_type $trigger_type's queue rows dispatch under. */
	public static function event_type_for( string $trigger_type ): string {
		return self::config( $trigger_type )['event_type'];
	}

	/** The order-meta guard key for $trigger_type (once-per-order-per-type). */
	public static function meta_key_for( string $trigger_type ): string {
		return self::config( $trigger_type )['meta_key'];
	}

	/** Reverse of event_type_for(): which trigger_type an event_type belongs to. */
	public static function trigger_type_for_event( string $event_type ): string {
		foreach ( self::TRIGGERS as $trigger_type => $row ) {
			if ( $row['event_type'] === $event_type ) {
				return $trigger_type;
			}
		}
		return self::TRIGGER_ORDER_CONFIRMATION;
	}

	/**
	 * Bare WC status slugs (no 'wc-' prefix) treated as "shipped" for the
	 * shipping_confirmation trigger. Single owner for both consumers
	 * (TransactionalEmailHookHandler's fire condition, TransactionalSuppression's
	 * native-email suppression) — they used to carry their own copy of this
	 * option constant + accessor with DIVERGING non-array fallbacks
	 * (['completed'] vs []); ['completed'] wins here to match the Stage 1
	 * read-side default (EnvDetector).
	 *
	 * @return string[]
	 */
	public static function shipped_statuses(): array {
		$statuses = get_option( self::OPTION_SHIPPED_STATUSES, array( 'completed' ) );
		return is_array( $statuses ) ? array_map( 'strval', $statuses ) : array( 'completed' );
	}

	/**
	 * @return array{toggle_option: string, event_type: string, meta_key: string}
	 */
	private static function config( string $trigger_type ): array {
		return self::TRIGGERS[ $trigger_type ] ?? self::TRIGGERS[ self::TRIGGER_ORDER_CONFIRMATION ];
	}
}
