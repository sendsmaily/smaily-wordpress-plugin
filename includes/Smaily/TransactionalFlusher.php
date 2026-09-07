<?php
/**
 * Sends + retries transactional-email events (PRO-1504 Stage 2).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are captured to the Event Log / debug log, never echoed to a browser.

/**
 * Single dispatcher for both the SYNCHRONOUS first attempt (design point 3 —
 * called directly from send_now() on the WC hook) and the QUEUED retry
 * (design point 5 — the AS callback for `smly_plus_flush_transactional_events`,
 * draining `transactional.order_confirmation` / `transactional.shipping_
 * confirmation` rows on their OWN hook so the main Flusher and CartFlusher
 * never touch them — event-type scoping, same discipline as the rec-engine
 * flushers). Both paths share process() so there is exactly one place that
 * decides success / terminal / transient.
 *
 * send_now() ALWAYS enqueues the row first (even though it then dispatches
 * it immediately) — this is what gives every attempt, successful or not, a
 * row in the Event Log (F3-44: sent_payload + last_response stored for
 * every outcome, not just failures).
 *
 * Error model (mirrors CartFlusher):
 *   - mark_sent + order-meta 'sent' on success (Smaily {code:101}).
 *   - mark_failed + fail-open (design point 7) on TerminalDispatchException
 *     (a non-101 Smaily body code — deterministic, e.g. 203 validation /
 *     221 invalid autoresponder) and on any other Throwable (F3-53 class:
 *     a deterministic failure must never become an eternal retry loop).
 *   - record_attempt on ApiException (network error / 5xx / 429 — the
 *     recurring AS tick retries; the row stays 'pending', order-meta stays
 *     'queued' so the WC hook can't double-enqueue meanwhile).
 *   - mark_failed + fail-open ALSO once a row is older than
 *     RETRY_CEILING_SECONDS (PRO-1519), even if every failure so far was
 *     transient — unlike the rest of the Smaily EventQueue (unbounded
 *     retry-until-manual-review), a transactional row keeps the customer's
 *     native WC email suppressed the whole time it's pending, so an
 *     unbounded retry would mean no email ever arrives.
 *
 * Fail-open (design point 7, Erkki decision 2026-07-22): a definitive
 * failure re-fires the native WC email this send would have replaced,
 * bypassing TransactionalSuppression for that one call, and records the
 * incident (the mark_failed row IS that record). Guarded by its own
 * order-meta value so a manually-retried failed row can't double-fire it.
 *
 * Not final: tests subclass queue/client doubles in.
 */
class TransactionalFlusher {

	/** Wire event types — canonical values live on TransactionalGate::TRIGGERS; mirrored here as they're this class's own public API. */
	public const EVENT_TYPE_ORDER_CONFIRMATION    = TransactionalGate::TRIGGERS[ TransactionalGate::TRIGGER_ORDER_CONFIRMATION ]['event_type'];
	public const EVENT_TYPE_SHIPPING_CONFIRMATION = TransactionalGate::TRIGGERS[ TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ]['event_type'];

	/**
	 * The pair as a set — the one place "which event types are
	 * transactional" is written. Every scoping site (this flusher's
	 * pending() pull + retry ceiling, the main Flusher's exclusion, the
	 * Event Log's SQL and TransactionalRetryGuard) reads it from here so
	 * a third type can't be added to some of them and not the rest.
	 */
	public const EVENT_TYPES = array(
		self::EVENT_TYPE_ORDER_CONFIRMATION,
		self::EVENT_TYPE_SHIPPING_CONFIRMATION,
	);

	public const FLUSH_HOOK = 'smly_plus_flush_transactional_events';
	public const AS_GROUP   = EventQueue::AS_GROUP;

	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Bounded retry ceiling (PRO-1519): the Smaily EventQueue's default
	 * convention is unbounded retry-until-manual-review, which is right for
	 * marketing rows (cart/welcome/first-order — nothing is suppressed
	 * waiting on them) but wrong here — a customer's native WC email stays
	 * SUPPRESSED (TransactionalSuppression) the whole time a row is pending,
	 * so an unbounded retry means a persistent-but-transient failure
	 * (revoked credentials, a prolonged Smaily outage) leaves the customer
	 * with NO confirmation email at all, forever. Time-based (from the
	 * row's created_at), not attempts-based: the flush AS action runs every
	 * 60s (Bootstrap), so a count ceiling would be a proxy for elapsed time
	 * anyway, and time is what the customer actually experiences. One hour
	 * is long enough to ride out a brief blip (~60 retries at the 60s
	 * cadence) but short enough that fail-open — re-firing the native email
	 * — still lands promptly. Applies ONLY to the two transactional event
	 * types this class owns; the marketing-side Flusher/CartFlusher are
	 * untouched (see DECISIONS.md PRO-1519).
	 */
	public const RETRY_CEILING_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Payload flag marking a row the merchant asked for explicitly —
	 * the Event Log's "Send again" (PRO-2324). It changes nothing about
	 * the send itself; it tells this class that the once-per-order story
	 * is already over for this order+type, so a flagged row:
	 *
	 *  - must NOT move the once-per-order-per-type meta guard. That guard
	 *    stops a status transition from sending twice by accident
	 *    (TransactionalEmailHookHandler::attempt()), and that rule is
	 *    untouched — a merchant flipping the order out of and back into a
	 *    shipped status still sends nothing. This is the one way past it,
	 *    and only because a human asked.
	 *  - must NOT fail open. Fail-open exists so the shopper is never left
	 *    without a confirmation; here they already have one (the row this
	 *    one repeats is the proof), so a failure costs them nothing and
	 *    must not mail them WooCommerce's own copy on top. The mark_failed
	 *    row is still the record.
	 */
	public const PAYLOAD_KEY_RESEND = 'resend';

	/** Order-meta guard values (once-per-order-per-type). */
	public const META_STATUS_QUEUED      = 'queued';
	public const META_STATUS_SENT        = 'sent';
	public const META_STATUS_FAILED_OPEN = 'failed_open';

	/** Cap (chars) on each stored exchange field so the queue stays bounded (F3-44). */
	private const EXCHANGE_MAX = 10000;

	private EventQueue $queue;

	/** @var callable(string $account_key): Client */
	private $client_factory;

	/**
	 * The HTTP exchange of the event currently being dispatched, captured
	 * even when the call throws (try/finally) — F3-44. Null = nothing POSTed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $current_exchange = null;

	/**
	 * @param callable(string $account_key): Client $client_factory
	 */
	public function __construct( EventQueue $queue, callable $client_factory ) {
		$this->queue          = $queue;
		$this->client_factory = $client_factory;
	}

	/**
	 * The order-meta key that guards $trigger_type for one order — checked
	 * by the HookHandler BEFORE calling send_now() ("already attempted/sent/
	 * queued/failed-open for this order+type" => skip; design point 1's
	 * once-per-order-per-email-type rule). Delegates to TransactionalGate's
	 * single TRIGGERS map so this and event_type_for()/trigger_type_for()
	 * can't drift from each other or from the gate's own toggle lookup.
	 */
	public static function meta_key_for( string $trigger_type ): string {
		return TransactionalGate::meta_key_for( $trigger_type );
	}

	public static function event_type_for( string $trigger_type ): string {
		return TransactionalGate::event_type_for( $trigger_type );
	}

	private static function trigger_type_for( string $event_type ): string {
		return TransactionalGate::trigger_type_for_event( $event_type );
	}

	/**
	 * Enqueue + immediately attempt one order's transactional send — the
	 * synchronous-first-attempt design (point 3). Called by the WC hook
	 * handler once TransactionalGate confirms the send is allowed.
	 *
	 * @param WorkflowMatch        $match      The resolved workflow + account.
	 * @param array<string, mixed> $context    The merge-tag payload (TransactionalPayloadBuilder).
	 * @param string                $to_status  The order status that triggered this
	 *                                          (shipping_confirmation only — decides
	 *                                          whether fail-open has a native email
	 *                                          to re-fire; '' for order_confirmation).
	 */
	public function send_now( string $trigger_type, \WC_Order $order, WorkflowMatch $match, array $context, string $to_status = '' ): void {
		$payload = self::build_payload( $order, $match, $context, $to_status );
		if ( $payload === null ) {
			// No recipient — nothing to send or retry; leave no trace (a
			// future hook fire with a since-added email can try again).
			return;
		}

		$order_id   = $order->get_id();
		$event_type = self::event_type_for( $trigger_type );

		$id = $this->queue->enqueue( $event_type, (string) $order_id, $payload );
		if ( $id === null ) {
			// Insert failed (rare infra hiccup) — nothing was attempted and
			// no row exists to retry; leave the meta guard unset so a later
			// hook fire can try again (EventQueue::enqueue()'s own documented
			// silent-failure posture).
			return;
		}

		$this->set_meta( (string) $order_id, $event_type, self::META_STATUS_QUEUED, $order );

		$this->process(
			array(
				'id'         => $id,
				'event_type' => $event_type,
				'entity_id'  => (string) $order_id,
				'payload'    => (string) wp_json_encode( $payload ),
			),
			$order
		);
	}

	/**
	 * Enqueue a DELIBERATE second confirmation for an order that already
	 * got one — the Event Log's "Send again" (PRO-2324). Unlike
	 * send_now() this only queues: the row goes out on this flusher's
	 * next scheduled pass, within about a minute (PRO-2323 wording).
	 *
	 * The row carries PAYLOAD_KEY_RESEND — see that constant for what the
	 * flag buys it (the meta guard and fail-open both step aside).
	 *
	 * @param array<string, mixed> $context   The merge-tag payload, rebuilt
	 *                                        from the order as it is NOW (the
	 *                                        point of a re-send: a corrected
	 *                                        tracking number reaches the shopper).
	 * @param string               $to_status Carried over from the row being
	 *                                        repeated, so the new row keeps the
	 *                                        same shape.
	 *
	 * @return int|null The new row's id, or null when the insert failed.
	 */
	public function enqueue_resend( string $trigger_type, \WC_Order $order, WorkflowMatch $match, array $context, string $to_status = '' ): ?int {
		$payload = self::build_payload( $order, $match, $context, $to_status );
		if ( $payload === null ) {
			return null;
		}

		$payload[ self::PAYLOAD_KEY_RESEND ] = true;

		$id = $this->queue->enqueue(
			self::event_type_for( $trigger_type ),
			(string) $order->get_id(),
			$payload
		);

		if ( $id === null ) {
			return null;
		}

		self::ensure_flush_scheduled();

		return $id;
	}

	/**
	 * AS callback for FLUSH_HOOK — retries rows a prior sync attempt left
	 * `pending` (transient failure).
	 *
	 * @return array{processed: int, sent: int, failed: int, retried: int}
	 */
	public function flush( int $batch_size = self::DEFAULT_BATCH_SIZE ): array {
		$stats = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'retried'   => 0,
		);

		foreach ( $this->queue->pending( $batch_size, self::EVENT_TYPES ) as $event ) {
			++$stats['processed'];
			++$stats[ $this->process( $event ) ];
		}

		return $stats;
	}

	/**
	 * Put already-revived (FAILED→PENDING) transactional rows back in
	 * business (PRO-1733). Two things the caller must not have to know:
	 * the PRO-1519 ceiling runs from created_at, so a row revived after an
	 * hour would terminal-fail on the very next tick unless its age starts
	 * over; and these rows are drained by THIS flusher's own hook, which no
	 * other reset path schedules.
	 *
	 * The send is NOT immediate (PRO-2323). The one-off below is deduplicated
	 * against anything already scheduled on this hook, and the flusher's
	 * recurring action always is — so a revived row goes out on this
	 * flusher's next scheduled pass, within about a minute. That is the
	 * documented behaviour, not a degradation: nothing is lost by waiting.
	 *
	 * @param int[] $ids Row ids in the Smaily queue.
	 */
	public static function revive( EventQueue $queue, array $ids ): void {
		if ( $ids === array() ) {
			return;
		}

		$queue->restart_age( $ids );

		self::ensure_flush_scheduled();
	}

	/**
	 * Make sure a pass over this flusher's own hook is queued — the one no
	 * other reset/enqueue path schedules (EventQueue::enqueue() schedules the
	 * MAIN flush hook, which excludes these event types). Deduplicated
	 * against whatever is already scheduled, and the recurring action always
	 * is, so in practice this is a no-op and the row goes out on the next
	 * scheduled pass (PRO-2323). It is the safety net for a store whose
	 * recurring action has gone missing, not a run-now kick.
	 */
	private static function ensure_flush_scheduled(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( self::FLUSH_HOOK, array(), self::AS_GROUP ) !== false
		) {
			return;
		}

		as_enqueue_async_action( self::FLUSH_HOOK, array(), self::AS_GROUP );
	}

	/**
	 * @param array<string, mixed> $event {id, event_type, entity_id, payload}
	 * @param ?\WC_Order $order Already-loaded order for the synchronous
	 *                          send_now() call — the async flush() retry has
	 *                          none, so set_meta()/fail_open() fall back to
	 *                          loading it by entity_id themselves.
	 *
	 * @return string 'sent' | 'failed' | 'retried'
	 */
	private function process( array $event, ?\WC_Order $order = null ): string {
		$id                     = (int) ( $event['id'] ?? 0 );
		$event_type             = (string) ( $event['event_type'] ?? '' );
		$order_id_str           = (string) ( $event['entity_id'] ?? '' );
		$this->current_exchange = null;

		$payload = array();
		$outcome = 'failed';

		try {
			$payload = $this->decode_payload( (string) ( $event['payload'] ?? '' ) );
			$this->enforce_retry_ceiling( $event_type, $event );
			$this->dispatch( $payload );

			$this->queue->mark_sent( $id );
			if ( ! self::is_resend( $payload ) ) {
				// A re-send must not move the marker — see PAYLOAD_KEY_RESEND.
				$this->set_meta( $order_id_str, $event_type, self::META_STATUS_SENT, $order );
			}
			$outcome = 'sent';
		} catch ( TerminalDispatchException $e ) {
			$this->queue->mark_failed( $id, $e->getMessage() );
			$this->fail_open( $order_id_str, $event_type, $payload, $order );
		} catch ( ApiException $e ) {
			$this->queue->record_attempt( $id, $e->getMessage() );
			$outcome = 'retried';
		} catch ( \Throwable $e ) {
			// Anything else (e.g. the client factory throwing because
			// credentials were removed) is deterministic — terminal, never
			// an eternal retry loop (F3-53).
			$this->queue->mark_failed( $id, get_class( $e ) . ': ' . $e->getMessage() );
			$this->fail_open( $order_id_str, $event_type, $payload, $order );
		}

		$this->record_exchange( $id );

		return $outcome;
	}

	/**
	 * PRO-1519: once a transactional row has been sitting past
	 * RETRY_CEILING_SECONDS, stop retrying — throw the SAME terminal
	 * exception a deterministic Smaily rejection throws, so it flows through
	 * the existing mark_failed + fail-open path with no new fallback logic.
	 * Scoped to the two transactional event types ONLY (defence in depth —
	 * flush() already only ever pulls these two via pending(), but a bad
	 * event_type here must never age out a marketing-side row this class
	 * was never meant to touch).
	 *
	 * $event['created_at'] is absent for the synchronous send_now() call
	 * (the row was just inserted, so it's never past the ceiling) — treated
	 * as "not yet expired".
	 *
	 * @param array<string, mixed> $event
	 *
	 * @throws TerminalDispatchException When the row has aged past the ceiling.
	 */
	private function enforce_retry_ceiling( string $event_type, array $event ): void {
		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
			return;
		}

		$created_at = isset( $event['created_at'] ) ? (string) $event['created_at'] : '';
		if ( $created_at === '' ) {
			return;
		}

		$created_ts = strtotime( $created_at . ' UTC' );
		if ( $created_ts === false ) {
			return;
		}

		if ( ( time() - $created_ts ) >= self::RETRY_CEILING_SECONDS ) {
			throw new TerminalDispatchException( 'retry_ceiling_exceeded' );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws TerminalDispatchException Deterministic failure — never retried.
	 * @throws ApiException              Transient API failure — retried.
	 */
	private function dispatch( array $payload ): void {
		$to          = isset( $payload['to'] ) ? (string) $payload['to'] : '';
		$workflow_id = isset( $payload['workflow_id'] ) ? (int) $payload['workflow_id'] : 0;
		$account_key = isset( $payload['account_key'] ) ? (string) $payload['account_key'] : 'transactional';
		$context     = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();

		if ( $to === '' || $workflow_id <= 0 ) {
			// send_now() never enqueues a row missing either — a retry can't
			// grow a missing recipient/workflow id, so this is terminal.
			throw new TerminalDispatchException( 'payload_missing_recipient_or_workflow' );
		}

		$client = ( $this->client_factory )( $account_key );
		try {
			$response = $client->send_message( $workflow_id, $to, $context );
		} finally {
			$this->current_exchange = $client->last_exchange();
		}

		// Success = HTTP 200 (Client::send_message() throws ApiException
		// otherwise) with body {code:101}. Any other body code — 203
		// validation, 221 invalid autoresponder, or anything else — is a
		// deterministic Smaily-side rejection, terminal (design point 3).
		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		if ( $code !== 101 ) {
			throw new TerminalDispatchException( sprintf( 'smaily_response_code_%d', $code ) );
		}
	}

	/**
	 * Fail-open (design point 7): re-fire the native WC email this send
	 * would have replaced, bypassing suppression for that one call, guarded
	 * so a manually-retried failed row can't double-fire it.
	 *
	 * @param array<string, mixed> $payload
	 * @param ?\WC_Order            $order Already-loaded order (send_now()'s
	 *                                     sync call) — reused instead of a
	 *                                     redundant wc_get_order() when set.
	 */
	private function fail_open( string $order_id_str, string $event_type, array $payload, ?\WC_Order $order = null ): void {
		if ( self::is_resend( $payload ) ) {
			// A re-send must not fail open — see PAYLOAD_KEY_RESEND.
			return;
		}

		$order_id = (int) $order_id_str;

		if ( $order === null ) {
			if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return;
			}
			$maybe = wc_get_order( $order_id );
			$order = $maybe instanceof \WC_Order ? $maybe : null;
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$trigger_type = self::trigger_type_for( $event_type );
		$meta_key     = self::meta_key_for( $trigger_type );

		if ( (string) $order->get_meta( $meta_key ) === self::META_STATUS_FAILED_OPEN ) {
			// Already fired for this order+type — the meta guard (design
			// point 7) stops a manually-reset row from double-firing.
			return;
		}

		$order->update_meta_data( $meta_key, self::META_STATUS_FAILED_OPEN );
		$order->save();

		if ( $trigger_type === TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) {
			TransactionalSuppression::fire_native_bypassing_suppression( TransactionalSuppression::EMAIL_CLASS_ORDER_CONFIRMATION, $order_id );
			return;
		}

		// shipping_confirmation: WC only HAS a native email to re-fire when
		// the transition that triggered this was into 'completed' — a
		// custom shipped status was never suppressed, so there's nothing to
		// re-fire; the mark_failed row above already records the incident.
		$to_status = isset( $payload['to_status'] ) ? (string) $payload['to_status'] : '';
		if ( $to_status === 'completed' ) {
			TransactionalSuppression::fire_native_bypassing_suppression( TransactionalSuppression::EMAIL_CLASS_SHIPPING_CONFIRMATION, $order_id );
		}
	}

	/**
	 * @param ?\WC_Order $order Already-loaded order (send_now()'s sync call)
	 *                          — reused instead of a redundant wc_get_order()
	 *                          when set.
	 */
	private function set_meta( string $order_id_str, string $event_type, string $value, ?\WC_Order $order = null ): void {
		if ( $order === null ) {
			$order_id = (int) $order_id_str;
			if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return;
			}
			$maybe = wc_get_order( $order_id );
			$order = $maybe instanceof \WC_Order ? $maybe : null;
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order->update_meta_data( self::meta_key_for( self::trigger_type_for( $event_type ) ), $value );
		$order->save();
	}

	/**
	 * The five keys every transactional queue row carries, built once for
	 * both producers (send_now() and enqueue_resend()).
	 *
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>|null null when the order has no recipient
	 *                                   address — there is nothing to send.
	 */
	private static function build_payload( \WC_Order $order, WorkflowMatch $match, array $context, string $to_status ): ?array {
		$to = trim( (string) $order->get_billing_email() );
		if ( $to === '' ) {
			return null;
		}

		return array(
			'to'          => $to,
			'workflow_id' => $match->workflow_id,
			'account_key' => $match->account_key,
			'context'     => $context,
			'to_status'   => $to_status,
		);
	}

	/**
	 * Whether this row is a merchant-initiated second confirmation
	 * (enqueue_resend()) rather than the order's first one.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function is_resend( array $payload ): bool {
		return ! empty( $payload[ self::PAYLOAD_KEY_RESEND ] );
	}

	/**
	 * Non-throwing read of a stored row payload: the decoded array, or null
	 * when the JSON isn't one. Shared with TransactionalRetryGuard, which
	 * reads the same payload from the read model where throwing would be
	 * wrong; decode_payload() puts the terminal-failure contract on top.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read_payload( string $json ): ?array {
		if ( $json === '' ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws TerminalDispatchException When the payload isn't a valid JSON-encoded array.
	 */
	private function decode_payload( string $json ): array {
		$decoded = self::read_payload( $json );

		if ( $decoded === null ) {
			throw new TerminalDispatchException( 'payload_decode_failure' );
		}

		return $decoded;
	}

	/**
	 * Persist the just-dispatched row's exchange (F3-44): the request body +
	 * Smaily reply, or a "skipped" marker when nothing was POSTed.
	 */
	private function record_exchange( int $id ): void {
		if ( $this->current_exchange === null ) {
			$this->queue->store_exchange(
				$id,
				null,
				(string) wp_json_encode(
					array(
						'outcome' => EventQueue::OUTCOME_SKIPPED,
						'note'    => 'no API call (payload missing recipient/workflow id, or payload decode failure) — nothing was sent',
					)
				)
			);
			return;
		}

		$request  = $this->current_exchange['request'] ?? null;
		$response = $this->current_exchange['response'] ?? null;
		$this->queue->store_exchange( $id, $this->trim_json( $request ), $this->trim_json( $response ) );
	}

	/**
	 * JSON-encode + cap a value for an exchange column. '' for null / unencodable.
	 *
	 * @param mixed $value
	 */
	private function trim_json( $value ): string {
		if ( $value === null ) {
			return '';
		}
		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) ) {
			return '';
		}
		return strlen( $json ) <= self::EXCHANGE_MAX ? $json : substr( $json, 0, self::EXCHANGE_MAX ) . '…[truncated]';
	}
}
