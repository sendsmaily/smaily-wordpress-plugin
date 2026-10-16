<?php
/**
 * Full gate check for a transactional-email send (PRO-1504 Stage 2).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Support\ContactLanguageResolver;

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
 *   3. a mapping row exists for the trigger (account_key='transactional').
 *      On a store with more than one language the row is picked by the
 *      order's language (PRO-3187) — see resolve_if_open();
 *   4. the mapped account's credentials resolve (subdomain+username+password
 *      all set).
 *
 * Not final: tests inject resolver/credentials doubles.
 */
class TransactionalGate {

	public const TRIGGER_ORDER_CONFIRMATION    = 'order_confirmation';
	public const TRIGGER_SHIPPING_CONFIRMATION = 'shipping_confirmation';
	public const TRIGGER_FOLLOW_UP             = 'follow_up';

	/**
	 * Why the gate is closed — closed_reason()'s answer, one per condition
	 * below (PRO-4091). The admin screen words them; stored in an Event Log
	 * skipped row's last_response, so they are stable wire values.
	 */
	public const REASON_SWITCHED_OFF          = 'switched_off';
	public const REASON_TRIGGER_OFF           = 'trigger_off';
	public const REASON_NO_WORKFLOW           = 'no_workflow';
	public const REASON_CONNECTION_INCOMPLETE = 'connection_incomplete';
	public const REASON_PASSWORD_UNREADABLE   = 'password_unreadable';

	/**
	 * Why a follow-up that fell due may not go out although the gate is
	 * open (PRO-4095) — follow_up_due_reason()'s answer: the order was
	 * cancelled/refunded/failed (or trashed), or it no longer sits in a
	 * status ticked for the follow-up.
	 */
	public const REASON_ORDER_CLOSED        = 'order_closed';
	public const REASON_STATUS_NOT_COUNTING = 'status_not_counting';

	/** Order statuses a follow-up never goes out on, whatever is ticked. */
	private const FOLLOW_UP_CLOSED_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );

	private const OPTION_ENABLED = 'smly_plus_transactional_emails_enabled';

	/** Bare WC status slugs (no 'wc-' prefix) treated as "shipped" — Settings persists this option. */
	public const OPTION_SHIPPED_STATUSES = 'smly_plus_shipped_order_statuses';

	/** Bare WC status slugs (no 'wc-' prefix) a follow-up email counts — Settings persists this option (PRO-4095). */
	public const OPTION_FOLLOW_UP_STATUSES = 'smly_plus_follow_up_order_statuses';

	/** Days the follow-up email waits after an order reaches a counting status (PRO-4095). */
	public const OPTION_FOLLOW_UP_DELAY_DAYS = 'smly_plus_follow_up_delay_days';

	public const FOLLOW_UP_DELAY_DEFAULT = 7;
	public const FOLLOW_UP_DELAY_MIN     = 1;
	public const FOLLOW_UP_DELAY_MAX     = 90;

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
	 * `statuses_option` names the option holding the bare WC status slugs the
	 * trigger counts (read by counting_statuses()); null for a trigger that
	 * counts no status.
	 *
	 * @var array<string, array{toggle_option: string, event_type: string, meta_key: string, statuses_option: ?string}>
	 */
	public const TRIGGERS = array(
		self::TRIGGER_ORDER_CONFIRMATION    => array(
			'toggle_option'   => 'smly_plus_order_confirmation_enabled',
			'event_type'      => 'transactional.order_confirmation',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- config value only, consumed as a per-order-id meta guard key (get/update_meta_data by order id), never a WP_Query meta_query; WPCS flags any 'meta_key' array key regardless of context.
			'meta_key'        => '_smly_plus_transactional_order_confirmation_status',
			'statuses_option' => null,
		),
		self::TRIGGER_SHIPPING_CONFIRMATION => array(
			'toggle_option'   => 'smly_plus_shipping_confirmation_enabled',
			'event_type'      => 'transactional.shipping_confirmation',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- config value only, consumed as a per-order-id meta guard key (get/update_meta_data by order id), never a WP_Query meta_query; WPCS flags any 'meta_key' array key regardless of context.
			'meta_key'        => '_smly_plus_transactional_shipping_confirmation_status',
			'statuses_option' => self::OPTION_SHIPPED_STATUSES,
		),
		self::TRIGGER_FOLLOW_UP             => array(
			'toggle_option'   => 'smly_plus_follow_up_enabled',
			'event_type'      => 'transactional.follow_up',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- config value only, consumed as a per-order-id meta guard key (get/update_meta_data by order id), never a WP_Query meta_query; WPCS flags any 'meta_key' array key regardless of context.
			'meta_key'        => '_smly_plus_transactional_follow_up_status',
			'statuses_option' => self::OPTION_FOLLOW_UP_STATUSES,
		),
	);

	private Credentials $credentials;
	private WorkflowResolverInterface $resolver;
	private ?DetectorInterface $detector;
	private ?ContactLanguageResolver $languages;

	/**
	 * @param DetectorInterface|null       $detector  Multilingual detector; defaults to the active one (built lazily, only when an order is passed).
	 * @param ContactLanguageResolver|null $languages Order-language source; defaults to one over $detector.
	 */
	public function __construct( Credentials $credentials, WorkflowResolverInterface $resolver, ?DetectorInterface $detector = null, ?ContactLanguageResolver $languages = null ) {
		$this->credentials = $credentials;
		$this->resolver    = $resolver;
		$this->detector    = $detector;
		$this->languages   = $languages;
	}

	/**
	 * Returns the matched workflow when every gate condition holds for
	 * $trigger_type, or null when any one of them doesn't — the caller
	 * treats null as "do nothing" (no send, no suppression).
	 *
	 * Pass the order whenever there is one: on a store with more than one
	 * language it picks that language's workflow (PRO-3187). Without an
	 * order (or on a one-language store) the lookup is language-less.
	 */
	public function resolve_if_open( string $trigger_type, ?\WC_Order $order = null ): ?WorkflowMatch {
		return $this->evaluate( $trigger_type, $order )[1];
	}

	/**
	 * Why the gate is closed for $trigger_type — the FIRST condition that
	 * fails, as a REASON_* code — or '' when it is open (PRO-4091). The
	 * same evaluation resolve_if_open() runs, so the merchant-facing status
	 * and the Event Log's skipped rows can never state a rule the send
	 * itself doesn't follow.
	 */
	public function closed_reason( string $trigger_type, ?\WC_Order $order = null ): string {
		return $this->evaluate( $trigger_type, $order )[0];
	}

	/**
	 * The four conditions, in order — the reason and the match from ONE
	 * evaluation, for a caller that needs both (the hook handler sends on
	 * the match and logs the reason) without running the gate twice.
	 *
	 * @return array{0: string, 1: ?WorkflowMatch} [ REASON_* or '', the match when open ]
	 */
	public function evaluate( string $trigger_type, ?\WC_Order $order = null ): array {
		if ( ! (bool) get_option( self::OPTION_ENABLED, false ) ) {
			return array( self::REASON_SWITCHED_OFF, null );
		}

		if ( ! (bool) get_option( self::config( $trigger_type )['toggle_option'], false ) ) {
			return array( self::REASON_TRIGGER_OFF, null );
		}

		$match = $this->resolver->resolve_workflow( $trigger_type, $this->order_language( $order ) );
		if ( $match === null ) {
			return array( self::REASON_NO_WORKFLOW, null );
		}

		$set = $this->credentials->get( $match->account_key );
		if ( $set === null || ! $set->is_complete() ) {
			return array(
				$this->credentials->password_unreadable( $match->account_key ) ? self::REASON_PASSWORD_UNREADABLE : self::REASON_CONNECTION_INCOMPLETE,
				null,
			);
		}

		return array( '', $match );
	}

	/**
	 * The order-status rules a due follow-up checks after evaluate() opens
	 * (PRO-4095): never for a cancelled/refunded/failed/trashed order, and
	 * only while the order still sits in a status ticked for the follow-up.
	 * Returns the REASON_* code, or '' when the follow-up may go out.
	 */
	public function follow_up_due_reason( \WC_Order $order ): string {
		$status = (string) $order->get_status();
		if ( in_array( $status, self::FOLLOW_UP_CLOSED_STATUSES, true ) ) {
			return self::REASON_ORDER_CLOSED;
		}
		if ( ! in_array( $status, self::follow_up_statuses(), true ) ) {
			return self::REASON_STATUS_NOT_COUNTING;
		}
		return '';
	}

	/**
	 * Whether a closed gate's $reason earns an Event Log skipped row
	 * (PRO-4091). A switched-off email stays silent — a row per order on
	 * every store that doesn't use it would flood the Event Log; a
	 * switched-on one that can't send says why. '' (open) is no skip.
	 */
	public static function is_loggable_reason( string $reason ): bool {
		return ! in_array( $reason, array( '', self::REASON_SWITCHED_OFF, self::REASON_TRIGGER_OFF ), true );
	}

	/**
	 * Whether an evaluation that ended with $reason got past the workflow
	 * lookup — open, or closed only on the account's credentials. The admin
	 * screen checks that the mapped workflow is active in Smaily exactly
	 * then, so it does not depend on the order of the conditions above.
	 */
	public static function workflow_resolved( string $reason ): bool {
		return in_array( $reason, array( '', self::REASON_CONNECTION_INCOMPLETE, self::REASON_PASSWORD_UNREADABLE ), true );
	}

	/**
	 * The order's language when the store has more than one — the same
	 * condition under which Settings shows per-language rows — else null.
	 */
	private function order_language( ?\WC_Order $order ): ?string {
		if ( $order === null ) {
			return null;
		}

		$this->detector ??= DetectorFactory::create();
		if ( count( $this->detector->get_detected_languages() ) < 2 ) {
			return null;
		}

		$this->languages ??= new ContactLanguageResolver( $this->detector );
		$language          = $this->languages->for_order( $order );

		return $language !== '' ? $language : null;
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
		return self::counting_statuses( self::TRIGGER_SHIPPING_CONFIRMATION );
	}

	/**
	 * Bare WC status slugs (no 'wc-' prefix) that start the follow-up
	 * email's delay (PRO-4095). Defaults to ['completed'], like the
	 * shipped set.
	 *
	 * @return string[]
	 */
	public static function follow_up_statuses(): array {
		return self::counting_statuses( self::TRIGGER_FOLLOW_UP );
	}

	/**
	 * The statuses a trigger counts — what the card's "no recent order
	 * reached a ticked status" warning checks. Empty for the order
	 * confirmation, which counts no status. The one reader of every
	 * trigger's `statuses_option`, so all of them share the ['completed']
	 * default.
	 *
	 * @return string[]
	 */
	public static function counting_statuses( string $trigger_type ): array {
		$option = self::config( $trigger_type )['statuses_option'];
		if ( $option === null ) {
			return array();
		}

		$statuses = get_option( $option, array( 'completed' ) );
		return is_array( $statuses ) ? array_map( 'strval', $statuses ) : array( 'completed' );
	}

	/**
	 * The follow-up delay in whole days, FOLLOW_UP_DELAY_MIN..MAX. Settings
	 * validates on save; a stored value outside the range (or none) reads
	 * as the default, so a bad option can never send at once.
	 */
	public static function follow_up_delay_days(): int {
		return self::delay_days_or_null( get_option( self::OPTION_FOLLOW_UP_DELAY_DAYS, self::FOLLOW_UP_DELAY_DEFAULT ) ) ?? self::FOLLOW_UP_DELAY_DEFAULT;
	}

	/**
	 * A whole number of days within FOLLOW_UP_DELAY_MIN..MAX, or null —
	 * the one rule the save route and the reader share.
	 *
	 * @param mixed $days
	 */
	public static function delay_days_or_null( $days ): ?int {
		if ( is_int( $days ) ) {
			$value = $days;
		} elseif ( is_string( $days ) && preg_match( '/^\d+$/', trim( $days ) ) === 1 ) {
			$value = (int) trim( $days );
		} else {
			return null;
		}

		return $value >= self::FOLLOW_UP_DELAY_MIN && $value <= self::FOLLOW_UP_DELAY_MAX ? $value : null;
	}

	/**
	 * @return array{toggle_option: string, event_type: string, meta_key: string, statuses_option: ?string}
	 */
	private static function config( string $trigger_type ): array {
		return self::TRIGGERS[ $trigger_type ] ?? self::TRIGGERS[ self::TRIGGER_ORDER_CONFIRMATION ];
	}
}
