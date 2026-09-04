<?php
/**
 * HTTP client for the Smaily marketing API.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are captured to the Event Log / returned to admin-only read models, never echoed to a browser; output-escaping does not apply.

use Smaily\Connect\Constants;

/**
 * Thin HTTP wrapper around https://{subdomain}.sendsmaily.net/api/*.
 *
 * The legacy Smaily_Connect\Includes\Smaily_Client class stays in place to
 * serve CF7 / Elementor integrations that already call it. This namespaced
 * Client exists so 2.0 code paths get a typed API with explicit
 * Smaily\Connect\Smaily\ApiException error semantics and dependency-injectable
 * HTTP arguments (for tests).
 *
 * This client does not retry: every failure surfaces as an ApiException
 * carrying the status code and any `Retry-After` Smaily sent, and the queue
 * flushers apply the PLUGIN.md §8 policy to it through RetryPolicy (4xx
 * no-retry, 429 honour Retry-After, 5xx exponential backoff). Retrying here
 * as well would both duplicate that and block the Action Scheduler worker.
 *
 * Note: deliberately NOT declared final so tests can mock it directly. The
 * collaborator boundaries (AutomationRouter, BackfillJob) already typehint
 * the concrete class, so allowing extension here costs nothing architectural.
 */
class Client {

	private string $subdomain;
	private string $username;
	private string $password;

	/**
	 * The last HTTP exchange — request {method, endpoint, body} + reply
	 * {http, body} — for the Event Log "Details" (F3-44). NEVER holds the
	 * Authorization header. Null until the first request() in this instance.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_exchange = null;

	public function __construct( string $subdomain, string $username, string $password ) {
		$this->subdomain = $subdomain;
		$this->username  = $username;
		$this->password  = $password;
	}

	/**
	 * The last HTTP exchange this Client made (request body + reply), or null
	 * if it hasn't sent anything. The Smaily Flusher reads this after a dispatch
	 * to record what was sent + what came back (F3-44). Excludes the auth header.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last_exchange(): ?array {
		return $this->last_exchange;
	}

	/**
	 * Checks the connection by hitting the workflows listing endpoint, and
	 * names the cause when it fails: RefusalReason::OK on any 2xx (the body
	 * content doesn't matter — an empty list is still a valid auth), otherwise
	 * the classified refusal, so the caller can tell a package block from
	 * wrong credentials from Smaily being down (PRO-1686).
	 *
	 * @return string One of the RefusalReason constants.
	 */
	public function check_connection(): string {
		try {
			$this->list_autoresponders();
			return RefusalReason::OK;
		} catch ( ApiException $e ) {
			return RefusalReason::classify( $e );
		}
	}

	/**
	 * GET /api/autoresponder.php — list automation workflows.
	 *
	 * Spec: https://smaily.com/help/api/automations-2/list-automation-workflows/
	 * Returns a JSON array of rows shaped roughly:
	 *   [ { "id": 1, "name": "Welcome series",
	 *       "status": "ACTIVE"|"INACTIVE",
	 *       "sections": [...], "tags": [...],
	 *       "created_at": ..., "activated_at": ... }, … ]
	 *
	 * We pass `status=ACTIVE` so the dropdown only carries automations
	 * the merchant can actually use; legacy mappings that point at an
	 * INACTIVE workflow are surfaced as warnings by the React layer.
	 *
	 * Sub-PR 2.H.14 — previously this method called `workflows.php`
	 * with `trigger_type=form_submitted`, which isn't a real Smaily
	 * route. Smaily returned an empty body, the normaliser fell back
	 * to `#{id}` placeholder names, and Erkki's "#3 / #4" surfaced.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_autoresponders(): array {
		$body = $this->request( 'GET', 'autoresponder', array( 'status' => 'ACTIVE' ) );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * POST /api/autoresponder.php — fires a Smaily workflow.
	 *
	 * @param int                              $workflow_id   Autoresponder ID.
	 * @param array<int, array<string, mixed>> $addresses     Recipient address rows.
	 * @param bool                             $force_opt_in  Whether to re-subscribe a contact
	 *                                                        who unsubscribed in Smaily. Both
	 *                                                        callers pass false; the default
	 *                                                        matches them so a new trigger
	 *                                                        can't opt into re-subscribing by
	 *                                                        omission (PRO-1716).
	 *
	 * @return array<string, mixed>
	 */
	public function trigger_automation( int $workflow_id, array $addresses, bool $force_opt_in = false ): array {
		$body = $this->request(
			'POST',
			'autoresponder',
			array(
				'autoresponder' => $workflow_id,
				'addresses'     => $addresses,
				'force_opt_in'  => $force_opt_in,
			)
		);

		return is_array( $body ) ? $body : array();
	}

	/**
	 * POST /api/contact.php — upsert a single subscriber.
	 *
	 * @param array<int, array<string, mixed>> $subscribers
	 *
	 * @return array<string, mixed>
	 */
	public function upsert_subscribers( array $subscribers ): array {
		$body = $this->request( 'POST', 'contact', $subscribers );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Read a single contact's consent signals back by email (profiling-consent
	 * wiring, (a).0). Smaily returns the contact's fields on a hit, or a
	 * `{code:206, message:"Could not find requested email address"}` status on a
	 * miss (HTTP 200 either way — probe-confirmed against the live API).
	 *
	 * @return array{found: bool, is_unsubscribed: ?string, smaily_rec_profiling: ?string}
	 *         Values come back as STRINGS ("0"/"1") — callers compare as strings.
	 */
	public function get_contact_consent( string $email ): array {
		$body = $this->request( 'GET', 'contact', array( 'email' => $email ) );

		// A status payload ({code, message}) = not-found / error, never a contact.
		if ( ! is_array( $body ) || isset( $body['code'] ) ) {
			return array(
				'found'                => false,
				'is_unsubscribed'      => null,
				'smaily_rec_profiling' => null,
			);
		}

		// The hit is a contact object, or a single-element list of one.
		$contact = isset( $body[0] ) && is_array( $body[0] ) ? $body[0] : $body;

		return array(
			'found'                => true,
			'is_unsubscribed'      => isset( $contact['is_unsubscribed'] ) ? (string) $contact['is_unsubscribed'] : null,
			'smaily_rec_profiling' => isset( $contact['smaily_rec_profiling'] ) ? (string) $contact['smaily_rec_profiling'] : null,
		);
	}

	/**
	 * Write the profiling-consent fields onto a contact via the existing upsert
	 * (the custom fields auto-create on Smaily's side — probe-confirmed). The
	 * boolean drives enforcement; the ISO-8601 timestamp is the Art 7 audit trail.
	 *
	 * @return array<string, mixed> The Smaily response ({code:101} on success).
	 */
	public function write_profiling_consent( string $email, bool $may_profile, string $changed_at ): array {
		return $this->upsert_subscribers(
			array(
				array(
					'email'                   => $email,
					'smaily_rec_profiling'    => $may_profile ? 1 : 0,
					'smaily_rec_profiling_ts' => $changed_at,
				),
			)
		);
	}

	/**
	 * Poll the subscriber action log (`GET /api/history.php`) for events newer
	 * than $since_seq_id. Smaily is pull-only (no webhooks); the caller tracks
	 * the max seq_id as a durable cursor and loops while a full page returns.
	 * Returns ONE page of action rows (each `{seq_id, email, action, time, …}`);
	 * an error/status payload comes back as an empty list.
	 *
	 * @param array<int, string> $actions Action types to filter (optin/optout/delete/complaint/…).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_action_log( int $since_seq_id, array $actions = array(), int $limit = 10000 ): array {
		$params = array(
			'since_seq_id' => $since_seq_id,
			'limit'        => $limit,
		);
		if ( $actions !== array() ) {
			$params['actions'] = array_values( $actions );
		}

		$body = $this->request( 'GET', 'history', $params );

		if ( ! is_array( $body ) || isset( $body['code'] ) ) {
			return array();
		}

		return array_values( array_filter( $body, 'is_array' ) );
	}

	/**
	 * Page the full subscriber list (`GET /api/contact.php?list=1`) — email +
	 * is_unsubscribed only, to keep the pull lean. Used ONLY for the occasional
	 * reconcile re-baseline (onboarding / stale cursor); the standing reconcile
	 * uses the action log. `$offset` is the 0-indexed PAGE; the last page returns
	 * fewer than `$limit` rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_contacts( int $offset = 0, int $limit = 25000 ): array {
		$body = $this->request(
			'GET',
			'contact',
			array(
				'list'   => 1,
				'fields' => 'email,is_unsubscribed',
				'offset' => $offset,
				'limit'  => $limit,
			)
		);

		if ( ! is_array( $body ) || isset( $body['code'] ) ) {
			return array();
		}

		return array_values( array_filter( $body, 'is_array' ) );
	}

	/**
	 * POST /api/message/send.php — a synchronous single-message transactional
	 * send (PRO-1504 Stage 2, Erkki design 2026-07-22). Distinct wire shape
	 * from trigger_automation(): a JSON body (not form-encoded), and the
	 * response is the SAME Smaily {code} envelope (101 = success) — the
	 * caller (TransactionalFlusher) maps 101 to success and any other code
	 * (203 validation / 221 invalid autoresponder / other) to a terminal
	 * failure, matching the general Smaily response-codes convention
	 * (https://smaily.com/help/api/general/response-codes/).
	 *
	 * @param int                  $autoresponder_id Smaily workflow id to fire.
	 * @param string               $to               Recipient email address.
	 * @param array<string, mixed> $context           Merge-tag values for the workflow template.
	 *
	 * @return array<string, mixed> Decoded JSON body ({code:101,...} on success).
	 */
	public function send_message( int $autoresponder_id, string $to, array $context ): array {
		$body = $this->request(
			'POST',
			'message/send',
			array(
				'autoresponder_id' => $autoresponder_id,
				'to'               => array( $to ),
				'context'          => $context,
			),
			true
		);

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Performs a single HTTP request against the configured Smaily subdomain.
	 *
	 * @param string                                   $method   "GET" or "POST".
	 * @param string                                   $endpoint Smaily endpoint slug,
	 *                                                           e.g. "workflows".
	 * @param array<int|string, mixed>                 $data     Request payload —
	 *                                                           query-string for GET,
	 *                                                           form body for POST
	 *                                                           (or JSON-encoded body
	 *                                                           when $json is true).
	 * @param bool                                      $json     True sends $data as a
	 *                                                           JSON-encoded POST body
	 *                                                           (message/send.php) instead
	 *                                                           of the default form encoding
	 *                                                           every other endpoint uses.
	 *
	 * @throws ApiException On HTTP transport errors and on non-2xx responses.
	 *
	 * @return mixed Decoded JSON body.
	 */
	private function request( string $method, string $endpoint, array $data, bool $json = false ) {
		$url = sprintf( 'https://%s.sendsmaily.net/api/%s.php', $this->subdomain, $endpoint );

		// Record the request for the Event Log (F3-44) — method/endpoint/body
		// only, NEVER the Authorization header. The reply is filled in below.
		$this->last_exchange = array(
			'request'  => array(
				'method'   => $method,
				'endpoint' => $endpoint,
				'body'     => $data,
			),
			'response' => null,
		);

		$args = array(
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
			),
			'user-agent' => $this->user_agent(),
			'timeout'    => 30,
		);

		if ( $method === 'GET' ) {
			$response = wp_remote_get( $url . '?' . http_build_query( $data ), $args );
		} elseif ( $json ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $data );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$args['body'] = $data;
			$response     = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$this->last_exchange['response'] = array( 'error' => $response->get_error_message() );
			throw new ApiException(
				'Smaily HTTP transport error: ' . $response->get_error_message(),
				0
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		$this->last_exchange['response'] = array(
			'http' => $code,
			'body' => $body,
		);

		if ( $code < 200 || $code >= 300 ) {
			// Smaily answers a refusal with its own {code, message} envelope —
			// carry the code, since it says WHY where the HTTP status only says
			// that (227 = the account's package doesn't include this API).
			$smaily_code = is_array( $body ) && isset( $body['code'] ) && is_numeric( $body['code'] )
				? (int) $body['code']
				: null;

			throw new ApiException(
				sprintf(
					'Smaily API returned HTTP %d for %s %s%s',
					$code,
					$method,
					$endpoint,
					$smaily_code !== null ? sprintf( ' (Smaily code %d)', $smaily_code ) : ''
				),
				$code,
				$this->retry_after_seconds( $response ),
				$smaily_code
			);
		}

		return $body;
	}

	/**
	 * The `Retry-After` header as whole seconds, or null when absent /
	 * expressed as an HTTP-date (Smaily sends the delta-seconds form; a date
	 * falls back to the caller's own backoff rather than being mis-parsed).
	 *
	 * @param array<string, mixed> $response A successful wp_remote_* reply (the
	 *                                       transport-error path throws earlier).
	 */
	private function retry_after_seconds( array $response ): ?int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		$header = is_array( $header ) ? (string) reset( $header ) : (string) $header;

		if ( ! is_numeric( $header ) ) {
			return null;
		}

		$seconds = (int) $header;

		return $seconds > 0 ? $seconds : null;
	}

	private function user_agent(): string {
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unknown';
		$site_url   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'url' ) : '';

		return sprintf(
			'%s/%s (WordPress/%s%s)',
			Constants::SLUG,
			Constants::version(),
			$wp_version,
			$site_url !== '' ? '; +' . $site_url : ''
		);
	}
}
