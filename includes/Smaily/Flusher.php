<?php
/**
 * Drains the Smaily event queue and dispatches each pending event.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are captured to the Event Log / returned to admin-only read models, never echoed to a browser; output-escaping does not apply.

use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;

/**
 * Action Scheduler callback for `smly_plus_flush_event_queue` and
 * `smly_plus_retry_failed_events`. Reads a batch of pending rows from
 * EventQueue, dispatches each based on `event_type`, and moves the row
 * to its terminal state.
 *
 * Event-type routing:
 *
 *   contact.sync              → Smaily\Client::upsert_subscribers (the row's
 *                               account — ContactAccount, CON-446; sent
 *                               without a status, it carries an unsubscribe
 *                               from another account — UnsubscribeCarryOver,
 *                               CON-480)
 *   automation.welcome        → AutomationRouter::trigger_automation('welcome', …)
 *   automation.first_order    → AutomationRouter::trigger_automation('first_order', …)
 *
 * `automation.abandoned_cart` rows are NOT drained here — the dedicated
 * CartFlusher owns them on its own AS action (PRO-1195); pending() excludes
 * the type so the two drains never consume each other's rows.
 *
 * `transactional.order_confirmation` / `transactional.shipping_confirmation`
 * rows are excluded the same way (PRO-1504 Stage 2) — TransactionalFlusher
 * owns them on its own AS action.
 *
 * Payload shape (what HookHandler enqueues):
 *
 *   {
 *     "email":    "buyer@example.test",
 *     "language": "et_EE",                    // null or empty for single-language sites
 *     "fields":   { "first_name": "Alice", ... }
 *   }
 *
 * `fields` is merged into the Smaily address row for contact.sync and
 * passed as additional_fields to AutomationRouter for automation events.
 *
 * Error model — three terminal states:
 *
 *   - mark_sent on success
 *   - mark_sent on skip (missing email, no workflow mapped, an
 *     abandoned-cart purchase marker for an address Smaily does not have,
 *     or a contact sync that would put a stale subscription into Smaily —
 *     StaleSubscription, asked at every attempt so an automatic retry never
 *     undoes a later delivered change, CON-395; or a first-order email the
 *     contact-sync mode does not allow — AutomationConsent, the
 *     abandoned-cart reminder's rule, asked before the workflow is found,
 *     CON-386; a retry can't recover any of them, so we don't waste retry
 *     attempts)
 *   - mark_failed on TerminalDispatchException (unknown event_type,
 *     payload decode failure, an HTTP 200 reply whose Smaily body code
 *     refuses the request for good, as `permanent_envelope_<code>` with
 *     Smaily's code (CON-301) — RetryPolicy::throw_if_refused_envelope(),
 *     PRO-3750 / PRO-3862; no refused request is marked sent)
 *   - RetryPolicy::apply() on ApiException: a permanent refusal (4xx bar
 *     429) fails the row at once, anything else (incl. a transient Smaily
 *     body code) is retried with backoff until the attempt ceiling
 *     (PRO-1685)
 *   - mark_failed on any other Throwable (a client that cannot be built,
 *     the automation router's included), with the error type and place —
 *     the row fails alone and the rest are sent (CON-481)
 *
 * A first-order email the consent rule stops makes no discount code. A
 * welcome or first-order email whose "Send a discount code" is on gets
 * the shopper's code just before the post, once the workflow is found
 * (AutomationDiscount, CON-199): a retry posts the code its first attempt
 * made, and an email sent without one keeps the reason as the exchange's
 * `note` in the Event Log.
 */
final class Flusher {

	public const DEFAULT_BATCH_SIZE = 50;

	/** Cap (chars) on each stored exchange field so the queue stays bounded (F3-44). */
	private const EXCHANGE_MAX = 10000;

	/** The Event Log note on a purchase-marker row closed without sending (PRO-3627). */
	public const NOTE_NOT_A_CONTACT = 'Smaily does not have this contact, and the abandoned-cart purchase marker would create it as a subscriber — nothing was sent';

	/** The Event Log note on a contact sync closed as a stale subscription (CON-395). */
	public const NOTE_STALE_SUBSCRIPTION = 'a newer subscription change of this contact already reached Smaily — nothing was sent';

	/** The Event Log note on a first-order email the consent rule stopped (CON-386). */
	public const NOTE_NO_CONSENT = 'the contact-sync mode does not allow a marketing email to this contact (see the reason) — nothing was sent';

	/** The Event Log note on an automation with no workflow chosen (CON-431). */
	public const NOTE_NO_WORKFLOW = 'no workflow is chosen for this email — nothing was sent';

	private EventQueue $queue;
	private AutomationRouter $router;

	/** @var callable(string $account_key): Client */
	private $client_factory;

	/**
	 * The HTTP exchange (request + reply) of the event currently being
	 * dispatched, captured even when the call throws (try/finally), for the
	 * Event Log (F3-44). Null when nothing was POSTed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $current_exchange = null;

	/**
	 * Why the event currently being dispatched was closed without a send,
	 * when the generic skip note does not say it. Null otherwise.
	 */
	private ?string $skip_note = null;

	/**
	 * The reason code of that skip, when it has one the Event Log words
	 * (EventQueue::skip_reason()). Null otherwise.
	 */
	private ?string $skip_reason = null;

	/** The contact-sync mode, read afresh for every flush (its own memo must not outlive one). */
	private ?ContactSyncMode $mode = null;

	/** Which account a contact is kept in, read afresh for every flush (CON-446). */
	private ?ContactAccount $accounts = null;

	/** The automations' discount codes (CON-199). */
	private DiscountCodeIssuer $discount_codes;

	/** Carries an unsubscribe into another Smaily account (CON-480); null: never. */
	private ?UnsubscribeCarryOver $carry_over;

	/**
	 * @param callable(string $account_key): Client $client_factory
	 */
	public function __construct( EventQueue $queue, AutomationRouter $router, callable $client_factory, AutomationDiscount $discounts, ?UnsubscribeCarryOver $carry_over = null ) {
		$this->queue          = $queue;
		$this->router         = $router;
		$this->client_factory = $client_factory;
		$this->discount_codes = new DiscountCodeIssuer( $queue, $discounts );
		$this->carry_over     = $carry_over;
	}

	/**
	 * Process up to $batch_size pending events.
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

		$this->mode     = null;
		$this->accounts = null;

		foreach ( $this->queue->pending(
			$batch_size,
			null,
			array_merge( array( CartFlusher::EVENT_TYPE ), TransactionalFlusher::EVENT_TYPES )
		) as $event ) {
			++$stats['processed'];

			$id   = (int) ( $event['id'] ?? 0 );
			$type = (string) ( $event['event_type'] ?? '' );

			// Reset so the stored exchange reflects THIS event (F3-44).
			$this->current_exchange = null;
			$this->skip_note        = null;
			$this->skip_reason      = null;

			try {
				$payload = $this->decode_payload( (string) ( $event['payload'] ?? '' ) );
				$this->dispatch( $id, $type, $payload );

				// An HTTP 200 can still carry a refusal in Smaily's body code —
				// fail or retry it by the code, never mark it sent (PRO-3862).
				RetryPolicy::throw_if_refused_envelope( $this->current_exchange );

				$this->queue->mark_sent( $id );
				++$stats['sent'];
			} catch ( TerminalDispatchException $e ) {
				$this->queue->mark_failed( $id, $e->getMessage() );
				++$stats['failed'];
			} catch ( ApiException $e ) {
				++$stats[ RetryPolicy::apply( $this->queue, $id, (int) ( $event['attempts'] ?? 0 ), $e ) ];
			} catch ( \Throwable $e ) {
				// Anything else is deterministic data or configuration — e.g. a
				// Smaily client that cannot be built for the row's account. It
				// fails this row alone: left to escape, it would stop every
				// flush at the same row (CON-481, F3-53). The error type and
				// where it was thrown, never its text (CON-301).
				$this->queue->mark_failed( $id, \Smaily\Connect\Support\DebugLog::where( $e ) );
				++$stats['failed'];
			}

			// Record what was actually sent + the reply, whatever the outcome —
			// the dispatch captured it (try/finally) even when the call threw (F3-44).
			$this->record_exchange( $id );
		}

		return $stats;
	}

	/**
	 * Run a single event through its handler.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws TerminalDispatchException Unknown event type.
	 * @throws ApiException              Transient API failure — should be retried.
	 */
	private function dispatch( int $id, string $event_type, array $payload ): void {
		$email = isset( $payload['email'] ) ? (string) $payload['email'] : '';
		if ( $email === '' ) {
			// Missing email — terminal skip; mark_sent path handles it
			// by returning early so the row leaves the queue.
			return;
		}

		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] )
			? $payload['fields']
			: array();

		switch ( $event_type ) {
			case HookHandler::EVENT_CONTACT_SYNC:
				if ( StaleSubscription::is_stale( $this->queue, $id, $event_type, $payload ) ) {
					$this->skip_note   = self::NOTE_STALE_SUBSCRIPTION;
					$this->skip_reason = StaleSubscription::REASON;
					return;
				}
				$this->dispatch_contact_sync( $email, $payload, $fields );
				return;

			case HookHandler::EVENT_AUTOMATION_WELCOME:
				$this->dispatch_automation( $id, 'welcome', $payload, $fields );
				return;

			case HookHandler::EVENT_AUTOMATION_FIRST_ORDER:
				$refused = $this->consent_refusal( $email, $payload );
				if ( $refused !== '' ) {
					$this->skip_note   = self::NOTE_NO_CONSENT;
					$this->skip_reason = $refused;
					return;
				}
				$this->dispatch_automation( $id, 'first_order', $payload, $fields );
				return;

			// automation.abandoned_cart deliberately has NO case here: the
			// CartFlusher owns it (PRO-1195) and flush() excludes the type at
			// the pending() query, so such a row can never reach dispatch().

			default:
				throw new TerminalDispatchException( 'unknown_event_type: ' . $event_type );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $fields
	 */
	private function dispatch_contact_sync( string $email, array $payload, array $fields ): void {
		$row = array_merge( array( 'email' => $email ), $fields );

		// Forward the top-level contact fields the Smaily contact API keys on,
		// which the custom-`fields` bag doesn't carry: `language` (F3-47 — this
		// was silently dropped on the live path; only the backfill sent it) and
		// `is_unsubscribed` (F3-48.6 consent opt-in/opt-out propagation).
		if ( isset( $payload['language'] ) && (string) $payload['language'] !== '' ) {
			$row['language'] = (string) $payload['language'];
		}
		if ( isset( $payload['is_unsubscribed'] ) ) {
			$row['is_unsubscribed'] = (int) $payload['is_unsubscribed'];
		}

		$account_key = isset( $payload[ ContactAccount::PAYLOAD_KEY ] ) ? (string) $payload[ ContactAccount::PAYLOAD_KEY ] : 'default';
		$client      = $this->row_client( $payload );

		// Smaily creates a contact sent without a status as SUBSCRIBED, so the
		// abandoned-cart purchase marker goes only to a contact Smaily already
		// has (PRO-3627): the reminder that scopes it (PRO-1723) does not prove
		// Smaily kept the contact. A missing contact closes the row as a skip.
		$marker = isset( $fields[ AutomationMarker::FIELD_ABANDONED_CART_PURCHASED ] );
		if ( $marker && ! $this->smaily_has_contact( $client, $email ) ) {
			$this->skip_note = self::NOTE_NOT_A_CONTACT;
			return;
		}

		// For the same reason a contact sent without a status to an account
		// that does not hold it carries an unsubscribe it made in another
		// account (CON-480).
		$carried = null;
		if ( ! $marker && ! isset( $row['is_unsubscribed'] ) ) {
			$carried = $this->carried_unsubscribe( $client, $account_key, $email );
			if ( $carried === true ) {
				$row['is_unsubscribed'] = 1;
			}
		}

		try {
			$client->upsert_subscribers( array( $row ) );
		} finally {
			// Capture even when upsert throws — the Client records the exchange
			// before throwing (F3-44).
			$this->current_exchange = $client->last_exchange();
		}

		if ( $carried !== null && $this->carry_over !== null && RetryPolicy::envelope_refusal( $this->current_exchange ) === null ) {
			$this->carry_over->remember( $account_key, $email );
		}
	}

	/**
	 * Whether this send must carry an unsubscribe (UnsubscribeCarryOver,
	 * CON-480); null when nothing was read. A read that fails throws, so the
	 * row takes the normal retry path and nothing is sent; its request is
	 * kept for the Event Log (F3-44).
	 *
	 * @throws ApiException When an account cannot be read.
	 */
	private function carried_unsubscribe( Client $client, string $account_key, string $email ): ?bool {
		if ( $this->carry_over === null ) {
			return null;
		}

		try {
			return $this->carry_over->unsubscribed_elsewhere( $client, $account_key, $email );
		} catch ( ApiException $e ) {
			$this->current_exchange = $this->carry_over->exchange_of( $e );
			throw $e;
		}
	}

	/**
	 * The client of the account a contact sync row records (CON-446) —
	 * `default` when it records none. A language account removed since the
	 * row was queued fails the row: a retry would find it gone too.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws TerminalDispatchException When the recorded account has no credentials any more.
	 */
	private function row_client( array $payload ): Client {
		$account_key = isset( $payload[ ContactAccount::PAYLOAD_KEY ] ) ? (string) $payload[ ContactAccount::PAYLOAD_KEY ] : 'default';
		if ( $account_key === 'default' ) {
			return ( $this->client_factory )( 'default' );
		}

		try {
			return ( $this->client_factory )( $account_key );
		} catch ( \RuntimeException $e ) {
			throw new TerminalDispatchException( 'account_not_configured: ' . $account_key );
		}
	}

	/**
	 * Whether Smaily has a contact for this address. A read that fails throws,
	 * so the row takes the normal retry path and nothing is written blind;
	 * its request is kept for the Event Log (F3-44).
	 *
	 * @throws ApiException When Smaily cannot be read.
	 */
	private function smaily_has_contact( Client $client, string $email ): bool {
		try {
			return $client->has_contact( $email );
		} catch ( ApiException $e ) {
			$this->current_exchange = $client->last_exchange();
			throw $e;
		}
	}

	/**
	 * Why the first-order email may not go out, or '' when it may — the
	 * shared AutomationConsent rule, the abandoned-cart reminder's (CON-386).
	 * A read that fails throws, so the row takes the normal retry path and
	 * nothing is sent blind; its request is kept for the Event Log (F3-44).
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws ApiException When Smaily cannot be read.
	 */
	private function consent_refusal( string $email, array $payload ): string {
		if ( $this->mode === null ) {
			$this->mode = new ContactSyncMode();
		}

		if ( $this->accounts === null ) {
			$this->accounts = new ContactAccount();
		}

		// The contact is read where contact sync keeps it (CON-446).
		$language = isset( $payload['language'] ) ? (string) $payload['language'] : '';
		$account  = $this->accounts->for_language( $language );
		$client   = ( $this->client_factory )( $account );
		try {
			return AutomationConsent::refusal( $client, $email, $payload, $this->mode, $this->carry_over, $account );
		} catch ( ApiException $e ) {
			// The read that failed: another account's (CON-482), else the own one.
			$this->current_exchange = ( $this->carry_over !== null ? $this->carry_over->exchange_of( $e ) : null ) ?? $client->last_exchange();
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $fields
	 */
	private function dispatch_automation( int $id, string $trigger, array $payload, array $fields ): void {
		$contact_data = array(
			'email'    => (string) $payload['email'],
			'language' => isset( $payload['language'] ) ? (string) $payload['language'] : '',
		);

		// AutomationRouter::trigger_automation returns false for terminal
		// skips (no workflow mapped); ApiException bubbles for transient.
		// Either return path is acceptable for us — the false case still
		// has mark_sent semantics, which is what flush() does after
		// dispatch() returns without throwing.
		try {
			$sent = $this->router->trigger_automation(
				$trigger,
				$contact_data,
				$fields,
				fn (): array => $this->discount_codes->fields( $id, $trigger, $payload )
			);
			// The email is set (dispatch() checked it), so false means no
			// workflow is chosen — e.g. after the Smaily account was
			// replaced and its workflows removed (CON-431).
			if ( ! $sent ) {
				$this->skip_note   = self::NOTE_NO_WORKFLOW;
				$this->skip_reason = TransactionalGate::REASON_NO_WORKFLOW;
			}
		} finally {
			// null when the router short-circuited before any request (no
			// workflow) — record_exchange() then stores a skip marker (F3-44).
			$this->current_exchange = $this->router->last_exchange();
		}
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws TerminalDispatchException When the payload isn't valid JSON-encoded array.
	 */
	private function decode_payload( string $json ): array {
		if ( $json === '' ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			throw new TerminalDispatchException( 'payload_decode_failure' );
		}

		return $decoded;
	}

	/**
	 * Persist the just-dispatched row's exchange (F3-44): the request body +
	 * Smaily reply captured in $current_exchange, or a "skipped" marker when
	 * nothing was POSTed (missing email, no workflow mapped, a decode failure,
	 * or a purchase marker for an address Smaily does not have).
	 */
	private function record_exchange( int $id ): void {
		// An email that went without its discount code says why (CON-199).
		$response = $this->discount_codes->noted( $this->current_exchange['response'] ?? null );

		if ( $this->current_exchange === null ) {
			$skipped = array(
				'outcome' => EventQueue::OUTCOME_SKIPPED,
				'note'    => $this->skip_note ?? 'no API call (missing email, no workflow mapped, or payload decode failure) — nothing was sent',
			);
			if ( $this->skip_reason !== null ) {
				$skipped['reason'] = $this->skip_reason;
			}
			$this->queue->store_exchange( $id, null, (string) wp_json_encode( $skipped ) );
			return;
		}

		$request = $this->current_exchange['request'] ?? null;
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
