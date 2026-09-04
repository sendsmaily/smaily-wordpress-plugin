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

use Smaily\Connect\Integrations\WooCommerce\HookHandler;

/**
 * Action Scheduler callback for `smly_plus_flush_event_queue` and
 * `smly_plus_retry_failed_events`. Reads a batch of pending rows from
 * EventQueue, dispatches each based on `event_type`, and moves the row
 * to its terminal state.
 *
 * Event-type routing:
 *
 *   contact.sync              → Smaily\Client::upsert_subscribers (default account)
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
 *   - mark_sent on skip (missing email or no workflow mapped — a retry
 *     can't recover either, so we don't waste retry attempts)
 *   - mark_failed on TerminalDispatchException (unknown event_type,
 *     payload decode failure)
 *   - RetryPolicy::apply() on ApiException: a permanent refusal (4xx bar
 *     429) fails the row at once, anything else is retried with backoff
 *     until the attempt ceiling (PRO-1685)
 */
final class Flusher {

	public const DEFAULT_BATCH_SIZE = 50;

	/** Cap (chars) on each stored exchange field so the queue stays bounded (F3-44). */
	private const EXCHANGE_MAX = 10000;

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
	 * @param callable(string $account_key): Client $client_factory
	 */
	public function __construct( EventQueue $queue, AutomationRouter $router, callable $client_factory ) {
		$this->queue          = $queue;
		$this->router         = $router;
		$this->client_factory = $client_factory;
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

		foreach ( $this->queue->pending(
			$batch_size,
			null,
			array(
				CartFlusher::EVENT_TYPE,
				TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
				TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
			)
		) as $event ) {
			++$stats['processed'];

			$id   = (int) ( $event['id'] ?? 0 );
			$type = (string) ( $event['event_type'] ?? '' );

			// Reset so the stored exchange reflects THIS event (F3-44).
			$this->current_exchange = null;

			try {
				$payload = $this->decode_payload( (string) ( $event['payload'] ?? '' ) );
				$this->dispatch( $type, $payload );

				$this->queue->mark_sent( $id );
				++$stats['sent'];
			} catch ( TerminalDispatchException $e ) {
				$this->queue->mark_failed( $id, $e->getMessage() );
				++$stats['failed'];
			} catch ( ApiException $e ) {
				++$stats[ RetryPolicy::apply( $this->queue, $id, (int) ( $event['attempts'] ?? 0 ), $e ) ];
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
	private function dispatch( string $event_type, array $payload ): void {
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
				$this->dispatch_contact_sync( $email, $payload, $fields );
				return;

			case HookHandler::EVENT_AUTOMATION_WELCOME:
				$this->dispatch_automation( 'welcome', $payload, $fields );
				return;

			case HookHandler::EVENT_AUTOMATION_FIRST_ORDER:
				$this->dispatch_automation( 'first_order', $payload, $fields );
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

		$client = ( $this->client_factory )( 'default' );
		try {
			$client->upsert_subscribers( array( $row ) );
		} finally {
			// Capture even when upsert throws — the Client records the exchange
			// before throwing (F3-44).
			$this->current_exchange = $client->last_exchange();
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $fields
	 */
	private function dispatch_automation( string $trigger, array $payload, array $fields ): void {
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
			$this->router->trigger_automation( $trigger, $contact_data, $fields );
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
	 * nothing was POSTed (missing email, no workflow mapped, or a decode failure).
	 */
	private function record_exchange( int $id ): void {
		if ( $this->current_exchange === null ) {
			$this->queue->store_exchange(
				$id,
				null,
				(string) wp_json_encode(
					array(
						'outcome' => 'skipped',
						'note'    => 'no API call (missing email, no workflow mapped, or payload decode failure) — nothing was sent',
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
