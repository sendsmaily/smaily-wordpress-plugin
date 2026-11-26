<?php
/**
 * The engine's privacy requests (§9 delete, §10 opt-out), queued and paced
 * (CON-431).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Support\DebugLog;

/**
 * Campaign Intelligence takes 10 privacy requests per 60 seconds per store
 * (contract rate-limit table, v1.37.1) and answers more with a 429 and
 * `retry_after_seconds`. A deletion (the personal-data eraser) or an opt-out
 * (ProfilingConsent) therefore never calls the engine in the request that
 * makes it: it is a row in the Campaign Intelligence queue
 * (`smly_rec_event_queue`, event types EVENT_DELETE / EVENT_OPT_OUT), and
 * this class's own Action Scheduler action (FLUSH_HOOK, group AS_GROUP,
 * every 60 seconds) sends it.
 *
 * - The row's `entity_id` is the address's anonymous id (anonymous_id()),
 *   which the Event Log and every debug line show. Only `payload` holds the
 *   address, as long as the request still has to be sent; when it ends the
 *   payload is replaced by `{}`. The stored exchange (F3-44) is the wire
 *   body, which carries no address (the address is in the URL path).
 * - A new request for an address replaces its requests still waiting: a
 *   later opt-out or opt-in replaces an earlier one (the latest choice
 *   wins), a deletion replaces both kinds.
 * - A run sends at most what the window leaves: LIMIT requests in any
 *   WINDOW_SECONDS, counted in OPTION_PACE. The export (§8) is answered in
 *   the WordPress exporter's own request and is counted there
 *   (count_request()), so the queue leaves room for it.
 * - A 429 holds every row until the engine's wait has passed (else
 *   DEFAULT_WAIT_SECONDS), and spends no attempt. No answer or a 5xx keeps
 *   the row and sends it again after RETRY_SECONDS (by its attempts, the
 *   last step repeating) — never dropped. A 401 or 403 (a revoked key, a
 *   deactivated or suspended account) keeps every row, spends no attempt,
 *   and holds them until the store has another connection: a new setup
 *   exchange stores a new key, and the rows go out under it (Erkki,
 *   2026-11-26). Disconnect leaves the rows; uninstall drops the table.
 *   Any other 4xx ends it as done: 404 means nothing to delete or opt out.
 *
 * Not final: tests subclass it for a fake clock (now()).
 */
class PrivacyRequests {

	public const EVENT_DELETE  = 'privacy.delete';
	public const EVENT_OPT_OUT = 'privacy.opt_out';
	public const EVENT_TYPES   = array( self::EVENT_DELETE, self::EVENT_OPT_OUT );

	/** The Action Scheduler hook and group (Deactivation::AS_GROUPS). */
	public const FLUSH_HOOK = 'smly_rec_flush_privacy';
	public const AS_GROUP   = 'smaily-rec-privacy';

	/** The engine's limit: LIMIT privacy requests in WINDOW_SECONDS per store. */
	public const LIMIT          = 10;
	public const WINDOW_SECONDS = 60;

	/** The wait after a 429 that names none, in seconds. */
	public const DEFAULT_WAIT_SECONDS = 60;

	/** The wait before the next try after no answer or a 5xx, by attempts made. */
	public const RETRY_SECONDS = array( 60, 300, 900, 3600 );

	/** The longest one request waits for the engine, in seconds. */
	public const TIMEOUT_SECONDS = 10;

	/**
	 * `{sent: int[], hold_until: int, refused_key: string}` — the moments of
	 * the last window's requests, a 429's hold, and the mark of the key a
	 * 401 / 403 refused (key_mark()).
	 */
	public const OPTION_PACE = 'smly_rec_privacy_pace';

	private IngestQueue $queue;

	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory One attempt per request (Bootstrap::privacy_requests()).
	 */
	public function __construct( IngestQueue $queue, RecEngineSettings $settings, callable $client_factory ) {
		$this->queue          = $queue;
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * The address's id in the queue, the Event Log and the debug log: a
	 * keyed hash of the lowercased address, which names nobody without the
	 * site's salt.
	 */
	public static function anonymous_id( string $email ): string {
		return substr( wp_hash( strtolower( trim( $email ) ) ), 0, 16 );
	}

	/** Queue the §9 deletion of the address. Returns whether it waits now. */
	public function queue_delete( string $email ): bool {
		return $this->queue_request( self::EVENT_DELETE, $email, array(), self::EVENT_TYPES );
	}

	/**
	 * Queue a §10 request for the address — `opt_out` true or false in
	 * `$body`. Returns whether it waits now.
	 *
	 * @param array<string, mixed> $body The §10 body.
	 */
	public function queue_opt_out( string $email, array $body ): bool {
		return $this->queue_request( self::EVENT_OPT_OUT, $email, $body, array( self::EVENT_OPT_OUT ) );
	}

	/** Count one privacy request against the window (the export, and each send here). */
	public function count_request(): void {
		$pace           = $this->pace();
		$pace['sent'][] = $this->now();
		update_option( self::OPTION_PACE, $pace, false );
	}

	/** Action Scheduler callback for FLUSH_HOOK: send what the window leaves room for. */
	public function run(): void {
		if ( ! $this->settings->sending_allowed() ) {
			return;
		}

		$pace = $this->pace();
		if ( $pace['hold_until'] > $this->now() ) {
			return;
		}
		if ( $pace['refused_key'] !== '' && hash_equals( $pace['refused_key'], $this->key_mark() ) ) {
			// The engine refused this connection's key; wait for another.
			return;
		}

		$room = self::LIMIT - count( $pace['sent'] );
		if ( $room <= 0 ) {
			return;
		}

		foreach ( $this->queue->pending( $room, self::EVENT_TYPES ) as $row ) {
			$this->count_request();
			if ( ! $this->send( $row ) ) {
				return;
			}
		}
	}

	/** The clock. Protected: tests move it. */
	protected function now(): int {
		return time();
	}

	/**
	 * @param string             $type     EVENT_DELETE or EVENT_OPT_OUT.
	 * @param array<string, mixed> $body   The wire body to send.
	 * @param array<int, string> $replaces The event types whose waiting rows of the address it replaces.
	 */
	private function queue_request( string $type, string $email, array $body, array $replaces ): bool {
		$id = self::anonymous_id( $email );
		foreach ( $replaces as $replaced ) {
			$this->queue->delete_unsent( $replaced, array( $id ) );
		}

		$row = $this->queue->enqueue(
			$type,
			$id,
			array(
				'email' => $email,
				'body'  => $body,
			),
			null,
			self::FLUSH_HOOK,
			self::AS_GROUP
		);
		DebugLog::write( sprintf( '[smaily-connect privacy] %s %s %s', $type, $id, $row === null ? 'could not be queued' : 'queued' ) );

		return $row !== null;
	}

	/**
	 * Send one row. Returns whether the run goes on: false after a 429, no
	 * answer or a 5xx, which the next rows would meet too.
	 *
	 * @param array<string, mixed> $row A pending() row.
	 */
	private function send( array $row ): bool {
		$id      = (int) $row['id'];
		$type    = (string) $row['event_type'];
		$label   = $type . ' ' . (string) $row['entity_id'];
		$payload = json_decode( (string) $row['payload'], true );
		$email   = is_array( $payload ) && is_string( $payload['email'] ?? null ) ? $payload['email'] : '';
		$wire    = $type === self::EVENT_DELETE
			? array(
				'confirm' => true,
				'reason'  => 'user_request',
			)
			: ( is_array( $payload ) && is_array( $payload['body'] ?? null ) ? $payload['body'] : array() );
		$json    = (string) wp_json_encode( $wire );

		if ( $email === '' ) {
			// Nothing to send it with; it can never be sent.
			$this->queue->mark_failed( $id, 'not_built' );
			$this->queue->store_exchange( $id, null, (string) wp_json_encode( array( 'outcome' => 'not_built' ) ) );
			$this->queue->forget_payload( $id );
			DebugLog::write( '[smaily-connect privacy] ' . $label . ' could not be read' );
			return true;
		}

		try {
			$client = ( $this->client_factory )();
			if ( $type === self::EVENT_DELETE ) {
				$client->customer_delete( $email, $wire );
			} else {
				$client->customer_opt_out( $email, $wire );
			}
			$this->finish(
				$id,
				$json,
				(string) wp_json_encode(
					array(
						'http'    => 200,
						'outcome' => 'accepted',
					)
				)
			);
			DebugLog::write( '[smaily-connect privacy] ' . $label . ' sent' );
			return true;
		} catch ( ApiException $e ) {
			$status = (int) $e->getCode();
			if ( $status === 429 ) {
				$wait = $e->retry_after() ?? self::DEFAULT_WAIT_SECONDS;
				$this->hold( $this->now() + $wait );
				$this->queue->store_exchange( $id, $json, IngestQueue::http_error_response( $e ) );
				DebugLog::write( sprintf( '[smaily-connect privacy] %s waits %ds: %s', $label, $wait, $e->summary() ) );
				return false;
			}
			if ( $status === 401 || $status === 403 ) {
				$pace                = $this->pace();
				$pace['refused_key'] = $this->key_mark();
				update_option( self::OPTION_PACE, $pace, false );
				$this->queue->store_exchange( $id, $json, IngestQueue::http_error_response( $e ) );
				DebugLog::write( '[smaily-connect privacy] ' . $label . ' waits for a new connection: ' . $e->summary() );
				return false;
			}
			if ( $status === 0 || $status >= 500 ) {
				$this->retry_later( $row, $label, $json, IngestQueue::http_error_message( $e ), IngestQueue::http_error_response( $e ), $e->summary() );
				return false;
			}
			// Any other 4xx ends it: 404 = nothing to delete or opt out.
			$this->finish( $id, $json, IngestQueue::http_error_response( $e ) );
			DebugLog::write( '[smaily-connect privacy] ' . $label . ' ended: ' . $e->summary() );
			return true;
		} catch ( \Throwable $e ) {
			$where = DebugLog::where_with_code( $e );
			$this->retry_later( $row, $label, $json, $where, (string) wp_json_encode( array( 'outcome' => 'error' ) ), $where );
			return false;
		}
	}

	/**
	 * Keep the row and park it for RETRY_SECONDS by its attempts.
	 *
	 * @param array<string, mixed> $row
	 */
	private function retry_later( array $row, string $label, string $json, string $error, string $response, string $summary ): void {
		$attempts = (int) ( $row['attempts'] ?? 0 );
		$wait     = self::RETRY_SECONDS[ min( $attempts, count( self::RETRY_SECONDS ) - 1 ) ];
		$this->queue->record_attempt( (int) $row['id'], $error, $wait );
		$this->queue->store_exchange( (int) $row['id'], $json, $response );
		DebugLog::write( sprintf( '[smaily-connect privacy] %s waits %ds: %s', $label, $wait, $summary ) );
	}

	/** The request has ended: mark it sent and drop the address. */
	private function finish( int $id, string $json, string $response ): void {
		$this->queue->mark_sent( $id );
		$this->queue->store_exchange( $id, $json, $response );
		$this->queue->forget_payload( $id );
	}

	/**
	 * The stored key's mark: a keyed hash, never the key. A new setup
	 * exchange stores a new key, so its mark differs.
	 */
	private function key_mark(): string {
		return substr( wp_hash( $this->settings->api_key() ), 0, 16 );
	}

	private function hold( int $until ): void {
		$pace               = $this->pace();
		$pace['hold_until'] = $until;
		update_option( self::OPTION_PACE, $pace, false );
	}

	/**
	 * The window's requests (older ones dropped), the hold and the refused
	 * key's mark.
	 *
	 * @return array{sent: array<int, int>, hold_until: int, refused_key: string}
	 */
	private function pace(): array {
		$stored = get_option( self::OPTION_PACE, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$since  = $this->now() - self::WINDOW_SECONDS;
		$sent   = array();
		foreach ( (array) ( $stored['sent'] ?? array() ) as $moment ) {
			if ( (int) $moment > $since ) {
				$sent[] = (int) $moment;
			}
		}

		return array(
			'sent'        => $sent,
			'hold_until'  => (int) ( $stored['hold_until'] ?? 0 ),
			'refused_key' => (string) ( $stored['refused_key'] ?? '' ),
		);
	}
}
