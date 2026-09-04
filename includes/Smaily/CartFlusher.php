<?php
/**
 * Drains abandoned-cart events from the Smaily event queue.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are captured to the Event Log / debug log, never echoed to a browser.

use Smaily\Connect\Support\DebugLog;

/**
 * Action Scheduler callback for `smly_plus_flush_cart_events` (PRO-1195).
 * Drains ONLY `automation.abandoned_cart` rows from the shared Smaily
 * EventQueue (the main Flusher excludes them — event-type scoping keeps the
 * two drains from consuming each other's rows, same discipline as the
 * rec-engine flushers).
 *
 * Dispatch, mirroring the retired legacy pass's F3-54 router-first order —
 * the SAME Smaily autoresponder destination, so an upgrading store needs
 * zero reconfiguration:
 *
 *   1. AutomationRouter::trigger_automation('abandoned_cart', …) — the
 *      wizard's automation-mapping row is the workflow source (multilingual
 *      modes, the F3-48 force_opt_in policy, F3-44 exchange capture).
 *   2. No mapping row → the legacy `autoresponder_id` still stored in the
 *      normalized `smaily_connect_abandoned_cart_status` option (F3-54:
 *      pre-wizard-era config carried over), sent through the default-account
 *      Client with force_opt_in=false — the legacy pass's exact posture.
 *   3. Neither source → terminal skip (mark_sent + a "skipped" exchange
 *      marker in the Event Log, logged once per flush) — consistent with the
 *      main Flusher's no-workflow semantics.
 *
 * Error model:
 *   - mark_sent on success and on terminal skips;
 *   - RetryPolicy::apply() on ApiException — a permanent refusal (4xx bar
 *     429) fails the row at once, anything else is retried with backoff
 *     until the attempt ceiling (PRO-1685);
 *   - mark_failed on TerminalDispatchException (payload decode failure,
 *     a non-101 Smaily body code on the fallback path — deterministic) and
 *     on any other Throwable (F3-53: a deterministic throw must never become
 *     an eternal retry loop; failed rows stay observable + manually
 *     retryable in the Event Log).
 *
 * Every row stores its send-time exchange per F3-44 (sent_payload +
 * last_response, NEVER the Authorization header — the Client captures the
 * exchange from method/endpoint/body only).
 *
 * Not final: tests subclass queue/router/client doubles in.
 */
class CartFlusher {

	public const EVENT_TYPE = 'automation.abandoned_cart';

	public const FLUSH_HOOK = 'smly_plus_flush_cart_events';
	public const AS_GROUP   = EventQueue::AS_GROUP;

	public const DEFAULT_BATCH_SIZE = 50;

	/** Cap (chars) on each stored exchange field so the queue stays bounded (F3-44). */
	private const EXCHANGE_MAX = 10000;

	private EventQueue $queue;
	private AutomationRouter $router;

	/** @var callable(string $account_key): Client */
	private $client_factory;

	/**
	 * The HTTP exchange of the event currently being dispatched, captured
	 * even when the call throws (try/finally) — F3-44. Null = nothing POSTed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $current_exchange = null;

	/** @var bool One "no workflow configured" log line per flush, not per cart (F3-54 parity). */
	private bool $unconfigured_logged = false;

	/**
	 * @param callable(string $account_key): Client $client_factory
	 */
	public function __construct( EventQueue $queue, AutomationRouter $router, callable $client_factory ) {
		$this->queue          = $queue;
		$this->router         = $router;
		$this->client_factory = $client_factory;
	}

	/**
	 * Process up to $batch_size pending abandoned-cart events.
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

		$this->unconfigured_logged = false;

		foreach ( $this->queue->pending( $batch_size, array( self::EVENT_TYPE ) ) as $event ) {
			++$stats['processed'];

			$id = (int) ( $event['id'] ?? 0 );

			$this->current_exchange = null;

			try {
				$payload = $this->decode_payload( (string) ( $event['payload'] ?? '' ) );
				$this->dispatch( $payload );

				$this->queue->mark_sent( $id );
				++$stats['sent'];
			} catch ( TerminalDispatchException $e ) {
				$this->queue->mark_failed( $id, $e->getMessage() );
				++$stats['failed'];
			} catch ( ApiException $e ) {
				++$stats[ RetryPolicy::apply( $this->queue, $id, (int) ( $event['attempts'] ?? 0 ), $e ) ];
			} catch ( \Throwable $e ) {
				// Anything else thrown by a cart dispatch is deterministic
				// data/config — terminal, never an eternal 60s retry loop
				// (F3-53). Observable + manually retryable in the Event Log.
				$this->queue->mark_failed( $id, get_class( $e ) . ': ' . $e->getMessage() );
				++$stats['failed'];
			}

			$this->record_exchange( $id );
		}

		return $stats;
	}

	/**
	 * Router-first, legacy-autoresponder fallback (F3-54 order).
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws TerminalDispatchException Deterministic failure — never retried.
	 * @throws ApiException              Transient API failure — retried.
	 */
	private function dispatch( array $payload ): void {
		$email = isset( $payload['email'] ) ? (string) $payload['email'] : '';
		if ( $email === '' ) {
			// Terminal skip — the sweeper only enqueues rows with an email,
			// so this is a defensive path; a retry can't grow an email.
			return;
		}

		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();

		$contact = array(
			'email'    => $email,
			'language' => isset( $payload['language'] ) ? (string) $payload['language'] : '',
		);

		try {
			if ( $this->router->trigger_automation( 'abandoned_cart', $contact, $fields ) ) {
				return;
			}
		} finally {
			$this->current_exchange = $this->router->last_exchange();
		}

		// No mapping row — the legacy autoresponder id an upgraded store still
		// carries is the fallback workflow source (F3-54).
		$autoresponder_id = \Smaily_Connect\Includes\Options::abandoned_cart_status()['autoresponder_id'];
		if ( $autoresponder_id <= 0 ) {
			if ( ! $this->unconfigured_logged ) {
				$this->unconfigured_logged = true;
				DebugLog::write(
					'[smaily-connect cart.flush] Abandoned cart is enabled but no workflow is configured (no automation mapping, no legacy autoresponder id) - reminder(s) skipped. Map an abandoned-cart workflow in the plugin settings.'
				);
			}
			// Terminal skip: mark_sent semantics with a "skipped" exchange
			// marker — the row is visible in the Event Log, never eternally
			// retried.
			return;
		}

		$address = array_merge( array( 'email' => $email ), $fields );

		$client = ( $this->client_factory )( 'default' );
		try {
			// force_opt_in=false — the legacy fallback's exact posture.
			$response = $client->trigger_automation( $autoresponder_id, array( $address ), false );
		} finally {
			$this->current_exchange = $client->last_exchange();
		}

		// The legacy Smaily API signals failure inside an HTTP 200 body
		// (code 101 = success). A non-101 (e.g. a deleted autoresponder id)
		// is deterministic — terminal, not an eternal retry (F3-53 class).
		if ( isset( $response['code'] ) && (int) $response['code'] !== 101 ) {
			throw new TerminalDispatchException(
				sprintf( 'smaily_response_code_%d', (int) $response['code'] )
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws TerminalDispatchException When the payload isn't a valid JSON-encoded array.
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
	 * Smaily reply, or a "skipped" marker when nothing was POSTed.
	 */
	private function record_exchange( int $id ): void {
		if ( $this->current_exchange === null ) {
			$this->queue->store_exchange(
				$id,
				null,
				(string) wp_json_encode(
					array(
						'outcome' => 'skipped',
						'note'    => 'no API call (missing email, no workflow mapped + no legacy autoresponder id, or payload decode failure) — nothing was sent',
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
