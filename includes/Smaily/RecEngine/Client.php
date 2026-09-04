<?php
/**
 * HTTP client for the Smaily Recommendation Engine REST API.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are captured to the Event Log / returned to admin-only read models, never echoed to a browser; output-escaping does not apply.

/**
 * Authenticated calls to https://<engine_base_url>/api/v1/... using
 * the Bearer api_key the merchant exchanged during setup.
 *
 * Scope in sub-PR 3.1:
 *   - Constructor + private request() helper with retry policy.
 *   - ping() — GET /api/v1/ingest/ping (the only feature method).
 *
 * Scaffold methods (ingest_catalog, ingest_customers, ingest_orders,
 * ingest_browse, identity_merge, customer_export, customer_delete,
 * customer_opt_out) throw a NotImplementedException so callers see a
 * clear "added later" message. Sub-PRs 3.2+ replace the throw with
 * the real wp_remote_post body for each.
 *
 * Retry policy (RECENGINE_API_CONTRACT.md §6):
 *   - 429 + Retry-After header → respect the header, exponential
 *     backoff (1s, 2s, 4s, 8s, 16s), max 5 attempts.
 *   - 5xx (500/502/503/504) → same backoff, max 5 attempts.
 *   - 4xx (non-429) → no retry, throw ApiException with the body's
 *     error_code so the caller can branch on `api_key_revoked` etc.
 *
 * Version handling (contract §3): every response carries
 * `X-Engine-Version`. The client warns (error_log) on a major-version
 * jump beyond what the plugin supports but does NOT refuse to work —
 * graceful degradation is explicit in the contract.
 *
 * Non-final: Bootstrap-injection tests double the class through
 * subclassing the request() method, the same pattern Smaily\Client
 * uses.
 */
class Client {

	/**
	 * Plugin's supported engine major version range. Bumps land in
	 * the same commit that handles a breaking-change migration.
	 */
	public const SUPPORTED_MAJOR = 1;

	// ---------------------------------------------------------------
	// Path constants — every URL the plugin sends to the engine flows
	// through these. Single source of truth: sub-PR 3.1.2 split out
	// after an integration bug where SetupExchange called
	// /setup/exchange (wrong) while Client::ping happened to call
	// /api/v1/ingest/ping (right). Different call sites, no shared
	// definition, drift.
	//
	// Engine actually serves SETUP_EXCHANGE under /api/setup/exchange
	// (the /api prefix is consistent across the entire route
	// surface). Spec doc updated in the same commit to match.
	//
	// Where the engine returns its own endpoints map in the
	// setup-exchange response (RECENGINE_API_CONTRACT.md §7.1), Faas
	// 3.2's ingest path should prefer that map; these constants are
	// the fallback / type-safety anchor.
	// ---------------------------------------------------------------
	public const PATH_SETUP_EXCHANGE = '/api/setup/exchange';
	public const PATH_PING           = '/api/v1/ingest/ping';
	public const PATH_INGEST_CATALOG = '/api/v1/ingest/catalog';
	// §3b product-level soft removal (contract v1.3.0). The endpoints map does
	// NOT advertise a key for it yet (the v1.3.0 map is unchanged), so this
	// fallback is load-bearing for EVERY connection today, not just pre-exchange
	// calls — the "Map age" note in §1 is exactly this case.
	public const PATH_INGEST_CATALOG_REMOVE = '/api/v1/ingest/catalog/remove';
	public const PATH_INGEST_CUSTOMERS      = '/api/v1/ingest/customers';
	public const PATH_INGEST_ORDERS         = '/api/v1/ingest/orders';
	public const PATH_INGEST_BROWSE         = '/api/v1/ingest/browse';
	public const PATH_IDENTITY_MERGE        = '/api/v1/identity/merge';
	// GDPR customer endpoints carry the email in the URL PATH. The engine's
	// endpoints-map advertises these with a literal `{email}` placeholder (see
	// the contract endpoints map), so the substitution convention is `{email}`,
	// NOT sprintf `%s` — feeding a `{email}` URL through sprintf leaves it
	// unchanged and the engine receives the literal string `{email}`. These
	// fallbacks use the same `{email}` placeholder so map-path and fallback-path
	// substitute identically via customer_url().
	public const PATH_CUSTOMER_EXPORT_TMPL  = '/api/v1/customer/{email}/export';
	public const PATH_CUSTOMER_DELETE_TMPL  = '/api/v1/customer/{email}';
	public const PATH_CUSTOMER_OPT_OUT_TMPL = '/api/v1/customer/{email}/opt-out';
	// Automations config API (contract v1.1.0, §11–§13). The endpoints map
	// advertises these under the `automations_*` keys — but a connection
	// exchanged BEFORE v1.1.0 keeps its exchange-time map without them
	// (the "Map age" note in §1), so these fallbacks are load-bearing for
	// every existing connection, not just the rare pre-exchange call.
	public const PATH_AUTOMATIONS_CATALOG = '/api/v1/automations/catalog';
	public const PATH_AUTOMATIONS_CONFIG  = '/api/v1/automations/config';

	/** Default in-request retry ceiling — generous for one-shot calls (ping, setup). */
	public const DEFAULT_MAX_ATTEMPTS = 5;

	private string $api_key;
	private string $base_url;

	/** @var array<string, string> Engine-returned endpoint URL map (keys like "ingest_catalog"). */
	private array $endpoints;

	private int $max_attempts;

	/**
	 * @param string                $api_key      Bearer key, e.g. "sk_8f3k2a...".
	 * @param string                $base_url     Engine origin, e.g. "https://intelligence.smaily.com".
	 * @param array<string, string> $endpoints    RecEngineSettings::endpoints() — the engine's own
	 *                                            URL map (source of truth for paths). Empty when a
	 *                                            caller only needs base_url + PATH_* constants (ping).
	 * @param int                   $max_attempts In-request retry ceiling. The flush job passes a
	 *                                            small value (1-2): a long sleep-backoff would block
	 *                                            the Action Scheduler worker, and the durable queue
	 *                                            already retries at the row level via next_retry_at.
	 */
	public function __construct( string $api_key, string $base_url, array $endpoints = array(), int $max_attempts = self::DEFAULT_MAX_ATTEMPTS ) {
		$this->api_key      = $api_key;
		$this->base_url     = rtrim( $base_url, '/' );
		$this->endpoints    = $endpoints;
		$this->max_attempts = max( 1, $max_attempts );
	}

	/**
	 * Health-check the configured tenant. Used by the Settings UI
	 * "Test connection" button.
	 *
	 * Return shape per contract §7.2:
	 *   ok, tenant_id, tenant_name, engine_version, tenant_status,
	 *   endpoints_available, server_time.
	 *
	 * Declared as array<string, mixed> rather than a strict shape so
	 * the caller can defensively isset() each field — the engine
	 * version could change the response shape under minor-bump rules
	 * and we want graceful degradation, not phpstan exceptions.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ping(): array {
		return $this->request( 'GET', self::PATH_PING );
	}

	// ---------------------------------------------------------------
	// Scaffold methods — Faas 3.2+ fills these in.
	// ---------------------------------------------------------------

	/**
	 * Batch-upsert catalog products. The engine UPSERTs per SKU and
	 * deduplicates per-product on (tenant_id, event_id), so a row resent
	 * by a flush-job retry — same products[].event_id — comes back 200
	 * {"deduplicated": true} rather than double-applied. That body is a
	 * SUCCESS to the caller (the flush job marks the row sent, never
	 * retries); request() only throws on real 4xx/5xx failures.
	 *
	 * URL comes from the engine's endpoints map (PATH preferred from the
	 * map's "ingest_catalog" key — full absolute URL), falling back to
	 * base_url + PATH_INGEST_CATALOG when the map isn't available.
	 *
	 * Wire wrapper key is `products` (§5). History is a flip-flop: the doc
	 * originally said `products`; the 3.2.4 live probe found the deployed
	 * engine then wanted `items` (we switched); W2 (engine `b5b1295`) renamed
	 * it back to `products` — a clean break, an `items`-wrapped payload now
	 * 400s `validation_failed` (fieldErrors: products Required). The W2 sync
	 * updated the doc but NOT this code; the mock (still enforcing `items`)
	 * hid the drift until the N-7.1 catalog live-walk — the first catalog
	 * live-request after W2 — caught it (LESSONS §2.6, the sync-vs-code gap).
	 *
	 * @param array<int, array<string, mixed>> $products
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_catalog( array $products ): array {
		$url = $this->resolve_url( 'ingest_catalog', self::PATH_INGEST_CATALOG );
		return $this->request_url( 'POST', $url, array( 'products' => $products ) );
	}

	/**
	 * Product-level soft removal (tombstone) — POST /api/v1/ingest/catalog/remove
	 * (contract v1.3.0 §3b, PRO-1230). Tombstones EVERY catalog row whose
	 * `tags.product_id` matches (in_stock=false + recommendable=false; the rows
	 * are kept — the engine never hard-deletes catalog). The path for a platform
	 * HARD-delete, where the product's variants/SKUs are no longer enumerable.
	 *
	 * `$product_ids` carry the RAW (un-prefixed) canonical PARENT product ids —
	 * exactly what the catalog sync emits as `tags.product_id`
	 * (SkuResolver::product_group_id()), NEVER the `woo-`-prefixed `sku`.
	 *
	 * Idempotent: a re-removed or never-ingested id lands in the response's
	 * `not_found` (a success, not an error). Response:
	 * {ok, removed_products, rows_tombstoned, not_found}. A malformed wrapper
	 * (empty / >1000 / non-array) is a 400 → ApiException.
	 *
	 * Endpoint key `ingest_catalog_remove` is in the live setup-exchange map
	 * since engine 6b225fb — the PATH_INGEST_CATALOG_REMOVE fallback serves
	 * connections whose stored exchange-time map predates it.
	 *
	 * @param array<int, string> $product_ids 1..1000 raw parent product ids.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function catalog_remove( array $product_ids ): array {
		$url = $this->resolve_url( 'ingest_catalog_remove', self::PATH_INGEST_CATALOG_REMOVE );
		return $this->request_url( 'POST', $url, array( 'product_ids' => $product_ids ) );
	}

	/**
	 * Batch-upload customers to POST /api/v1/ingest/customers.
	 *
	 * Wrapper key is `customers` (W4 §4 email-first contract), live-verified
	 * against the deployed engine in the 3.3.1 wrapper probe: a single
	 * {customers:[...]} item returned 200 processed:1 — no products→items-style
	 * surprise like catalog had (LESSONS §2.4). Identity is email; the engine
	 * UPSERTs on (tenant_id, email), case-insensitive.
	 *
	 * Per-item D6 partial success: a 2xx body carries
	 * {ok, processed, deduplicated, errors:[{index, email?, field, message}]};
	 * the customer flusher (3.3.2) reads errors[] from this return value and
	 * splits the batch. A wrapper-level failure (non-array / empty / >100) is a
	 * 400 and throws ApiException carrying the body's `details` (F3-18).
	 *
	 * @param array<int, array<string, mixed>> $customers 1..100 customer objects.
	 *
	 * @return array<string, mixed> The decoded engine response (D6 shape).
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_customers( array $customers ): array {
		$url = $this->resolve_url( 'ingest_customers', self::PATH_INGEST_CUSTOMERS );
		return $this->request_url( 'POST', $url, array( 'customers' => $customers ) );
	}

	/**
	 * Batch-upload orders to POST /api/v1/ingest/orders.
	 *
	 * Wrapper key is `orders` (W5 §5); the batch cap is **50** orders per
	 * request (lower than the 100 for catalog/customers — orders carry nested
	 * line items and are heavier). Order natural key is
	 * `(tenant_id, external_order_id)`; the customer is referenced by
	 * `customer_email` and auto-created engine-side. Items are fully replaced
	 * on re-ingest of an existing order.
	 *
	 * Per-item D6 partial success: a 2xx body carries
	 * {ok, processed, deduplicated, errors:[{index, external_order_id?, field,
	 * message}]}; the order flusher (3.3-orders.2) splits the batch from
	 * errors[]. Attribution is async — the response carries no attribution
	 * counts. A wrapper-level failure (non-array / empty / >50) is a 400 and
	 * throws ApiException carrying the body's `details` (F3-18).
	 *
	 * @param array<int, array<string, mixed>> $orders 1..50 order objects.
	 *
	 * @return array<string, mixed> The decoded engine response (D6 shape).
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_orders( array $orders ): array {
		$url = $this->resolve_url( 'ingest_orders', self::PATH_INGEST_ORDERS );
		return $this->request_url( 'POST', $url, array( 'orders' => $orders ) );
	}

	/**
	 * Batch-upload browse events to POST /api/v1/ingest/browse.
	 *
	 * Wrapper key is `events` (§6); the batch cap is **100** events per
	 * request and the engine rate limit is higher (500 req/sec — browse is the
	 * highest-volume endpoint). Browse has **no Layer-1 natural key**, so a
	 * **missing `event_id` is a per-item error** (not a silent no-dedup insert);
	 * the plugin-side beacon proxy already rejects event_id-less events before
	 * they reach here, but the engine is the strict validator (D6).
	 *
	 * Per-item D6 partial success: a 2xx body carries
	 * {ok, processed, deduplicated, errors:[{index, field, message}]} plus the
	 * informational sub-counts {with_customer_match, anonymous, retroactive_bound,
	 * duplicates_skipped}. Unlike the server-originated ingest domains
	 * (catalog/customers/orders), browse is NOT drained through the
	 * IngestQueue/Flusher — it is client-buffered telemetry forwarded
	 * synchronously by the beacon proxy (best-effort; a lost batch is
	 * acceptable). A wrapper-level failure (non-array / empty / >100) is a 400
	 * and throws ApiException carrying the body's `details` (F3-18).
	 *
	 * @param array<int, array<string, mixed>> $events 1..100 browse-event objects.
	 *
	 * @return array<string, mixed> The decoded engine response (D6 shape).
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function ingest_browse( array $events ): array {
		$url = $this->resolve_url( 'ingest_browse', self::PATH_INGEST_BROWSE );
		return $this->request_url( 'POST', $url, array( 'events' => $events ) );
	}

	/**
	 * Bind an anonymous session's browse history to a known customer (§7) —
	 * the explicit login-driven counterpart to the engine's automatic
	 * browse-event retroactive binding (§6). The body is a single object (NOT a
	 * batch wrapper): `{ anon_session_id?, smaily_visitor_token?, customer_email
	 * (required), customer_external_id?, merge_ts (required), merge_reason? }`,
	 * with at least one of anon_session_id / smaily_visitor_token present.
	 *
	 * The customer must already exist (the A-filter ingests every registered
	 * user) — a 404 `customer_not_found` means it doesn't yet; the caller logs
	 * + skips, since the next email-carrying browse event binds it retroactively
	 * anyway. Idempotent: the same merge twice is a no-op engine-side.
	 *
	 * @param array<string, mixed> $merge
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (incl. 404 customer_not_found) or network failure.
	 */
	public function merge_identity( array $merge ): array {
		$url = $this->resolve_url( 'identity_merge', self::PATH_IDENTITY_MERGE );
		return $this->request_url( 'POST', $url, $merge );
	}

	/**
	 * GDPR Art 15 export (§8) — all engine-held rec data for a customer. The
	 * caller (the WP exporter) selects which sections are personal data to
	 * surface (DATA_MODEL_GDPR.md); this just returns the raw §8 body. A 404
	 * means the customer has no engine record yet — the exporter treats that as
	 * "no engine data", not an error.
	 *
	 * @param array<string, string> $query Optional `format` / `since`.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (incl. 404 not_found) or network failure.
	 */
	public function customer_export( string $email, array $query = array() ): array {
		$url = $this->customer_url( 'customer_export', self::PATH_CUSTOMER_EXPORT_TMPL, $email );
		if ( $query !== array() ) {
			$url .= '?' . http_build_query( $query );
		}
		return $this->request_url( 'GET', $url );
	}

	/**
	 * GDPR Art 17 erase (§9) — full deletion (CASCADE incl. rec_attribution +
	 * visitor_tokens). Idempotent: a second DELETE returns 404 (already gone),
	 * which the eraser treats as success.
	 *
	 * @param array<string, mixed> $body Optional { confirm, reason }.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (incl. 404) or network failure.
	 */
	public function customer_delete( string $email, array $body = array() ): array {
		$url = $this->customer_url( 'customer_delete', self::PATH_CUSTOMER_DELETE_TMPL, $email );
		return $this->request_url( 'DELETE', $url, $body !== array() ? $body : null );
	}

	/**
	 * GDPR Art 21 opt-out (§10) — exclude the customer from future
	 * recommendations (data retained, reversible). The body carries
	 * { opt_out, reason?, opted_out_at? }.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx or network failure.
	 */
	public function customer_opt_out( string $email, array $body ): array {
		$url = $this->customer_url( 'customer_opt_out', self::PATH_CUSTOMER_OPT_OUT_TMPL, $email );
		return $this->request_url( 'POST', $url, $body );
	}

	/**
	 * Trigger catalog for engine-run automations (§11) — the automation
	 * triggers available FOR THIS STORE (sector-filtered per tenant). Returns
	 * `{triggers: [{key, name_et, name_en, description_et, description_en,
	 * recipe_et}], language_modes, docs}`. The list changes with engine
	 * deploys, so callers render it dynamically — never hardcode trigger keys
	 * or a count — and link the merchant to the response's `docs` URL rather
	 * than a hardcoded one. Read-only; never cached plugin-side (F3-51).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function automations_catalog(): array {
		$url = $this->resolve_url( 'automations_catalog', self::PATH_AUTOMATIONS_CATALOG );
		return $this->request_url( 'GET', $url );
	}

	/**
	 * Current automation configuration rows (§12) — `{configs: [...]}`, each
	 * row the eight §13 fields plus the read-only `configured_via` +
	 * `updated_at` (engine-written; never round-tripped into a PUT). Rows
	 * exist only for triggers configured at least once — an empty `configs`
	 * on a fresh tenant means everything is off (fail-closed). The engine's
	 * GET is the source of truth; the plugin stores no local copy (F3-51).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On 4xx (non-429) or unrecoverable network failure.
	 */
	public function automations_config(): array {
		$url = $this->resolve_url( 'automations_config', self::PATH_AUTOMATIONS_CONFIG );
		return $this->request_url( 'GET', $url );
	}

	/**
	 * Save the merchant's automation configuration (§13) — full-selection
	 * UPSERT on `(tenant_id, trigger_key)`, wrapper `{configs: [...]}`,
	 * 1..50 rows, all 8 row fields required on every row. PUT never deletes:
	 * a trigger absent from the body keeps its stored config (disable =
	 * send the row with `enabled: false`). Validation is ALL-OR-NOTHING
	 * (unlike ingest's per-item D6 partial success): a 422 means NOTHING was
	 * written — the thrown ApiException carries the body's indexed D6-style
	 * `errors[]` (`{index?, trigger_key?, field, message}`) via `errors()`,
	 * so the caller can bind each error to its row/field and retry the whole
	 * corrected selection.
	 *
	 * @param array<int, array<string, mixed>> $configs 1..50 config rows.
	 *
	 * @return array<string, mixed> `{ok: true, upserted: N}` on success.
	 *
	 * @throws ApiException On 4xx (non-429, incl. the 422 above) or unrecoverable network failure.
	 */
	public function put_automations_config( array $configs ): array {
		$url = $this->resolve_url( 'automations_config', self::PATH_AUTOMATIONS_CONFIG );
		return $this->request_url( 'PUT', $url, array( 'configs' => $configs ) );
	}

	// ---------------------------------------------------------------
	// Private request engine.
	// ---------------------------------------------------------------

	/**
	 * Resolve a wire endpoint URL. The engine-returned endpoints map is the
	 * source of truth — its values are full absolute URLs keyed like
	 * "ingest_catalog" (the engine's own naming; the plugin once read the
	 * unprefixed "catalog" and got a null URL, the 3.1.2-class bug). The
	 * PATH_* constant is the fallback for the rare pre-setup-exchange call
	 * where no map is stored. A relative map value (legacy shape) is
	 * defensively re-based on base_url.
	 */
	private function resolve_url( string $endpoint_key, string $fallback_path ): string {
		$mapped = isset( $this->endpoints[ $endpoint_key ] ) ? trim( (string) $this->endpoints[ $endpoint_key ] ) : '';
		if ( $mapped !== '' ) {
			if ( preg_match( '#^https?://#i', $mapped ) === 1 ) {
				return $mapped;
			}
			return $this->base_url . '/' . ltrim( $mapped, '/' );
		}
		return $this->base_url . $fallback_path;
	}

	/**
	 * Build a GDPR customer-endpoint URL by substituting the `{email}`
	 * placeholder. The engine advertises these URLs with a literal `{email}`
	 * token (in both the endpoints-map value and our fallback template), so the
	 * substitution is a str_replace — NOT sprintf, which would silently leave a
	 * `{email}`-style URL unchanged and send the literal placeholder to the
	 * engine. The email is rawurlencoded for the path segment. As a defensive
	 * fallback, a resolved URL that carries no `{email}` token but still has a
	 * legacy `%s` is handled too.
	 */
	private function customer_url( string $endpoint_key, string $fallback_template, string $email ): string {
		$url = $this->resolve_url( $endpoint_key, $fallback_template );
		$enc = rawurlencode( $email );
		if ( strpos( $url, '{email}' ) !== false ) {
			return str_replace( '{email}', $enc, $url );
		}
		if ( strpos( $url, '%s' ) !== false ) {
			return sprintf( $url, $enc );
		}
		return $url;
	}

	/**
	 * Issue a request against a path relative to base_url. Thin wrapper over
	 * request_url() for the constant-path callers (ping).
	 *
	 * @param array<string, mixed>|null $body Request body for non-GET methods.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	protected function request( string $method, string $path, ?array $body = null ): array {
		return $this->request_url( $method, $this->base_url . $path, $body );
	}

	/**
	 * Issue a request against a fully-resolved URL, with the retry policy.
	 *
	 * @param array<string, mixed>|null $body Request body for non-GET methods.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	protected function request_url( string $method, string $url, ?array $body = null ): array {
		$attempts = 0;
		$backoff  = 1;

		while ( true ) {
			++$attempts;

			$args = array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'User-Agent'    => sprintf(
						'SmailyConnect-WooPlugin/%s',
						defined( 'SMAILY_CONNECT_VERSION' ) ? (string) SMAILY_CONNECT_VERSION : '0.0.0'
					),
				),
			);
			if ( $body !== null ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = (string) wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				if ( $attempts >= $this->max_attempts ) {
					throw new ApiException(
						0,
						'network_error',
						sprintf( 'Engine unreachable after %d attempts: %s', $attempts, $response->get_error_message() )
					);
				}
				$this->sleep_with_backoff( $backoff );
				$backoff *= 2;
				continue;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$raw_body = (string) wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $raw_body, true );
			$decoded  = is_array( $decoded ) ? $decoded : array();

			$this->check_engine_version( wp_remote_retrieve_header( $response, 'x-engine-version' ) );

			if ( $status >= 200 && $status < 300 ) {
				return $decoded;
			}

			$is_retryable = ( $status === 429 || ( $status >= 500 && $status < 600 ) );
			if ( $is_retryable && $attempts < $this->max_attempts ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$sleep_for   = $retry_after > 0 ? $retry_after : $backoff;
				$this->sleep_with_backoff( $sleep_for );
				$backoff *= 2;
				continue;
			}

			$error_code = isset( $decoded['error'] ) ? (string) $decoded['error'] : 'http_' . $status;
			$message    = isset( $decoded['message'] ) ? (string) $decoded['message'] : sprintf( 'Engine returned HTTP %d', $status );
			throw new ApiException( $status, $error_code, $message, $decoded );
		}
	}

	/**
	 * Engine version compatibility check. Contract §3 instructs us to
	 * NEVER refuse on version mismatch — data loss is a bigger risk
	 * than compatibility issues — so we log and continue. A future
	 * sub-PR can surface admin notices when the gap is too wide.
	 *
	 * @param string|mixed $header_value
	 */
	private function check_engine_version( $header_value ): void {
		$version = is_string( $header_value ) ? trim( $header_value ) : '';
		if ( $version === '' ) {
			return;
		}
		if ( ! preg_match( '/^(\d+)\./', $version, $m ) ) {
			return;
		}
		$major = (int) $m[1];
		if ( $major > self::SUPPORTED_MAJOR ) {
			\Smaily\Connect\Support\DebugLog::write(
				sprintf(
					'[smaily-connect rec-engine] Engine version %s is beyond plugin support range (<=%d.x). Continuing in graceful-degradation mode.',
					$version,
					self::SUPPORTED_MAJOR
				)
			);
		}
	}

	/**
	 * Real sleep in production; protected so tests can subclass and
	 * fast-forward.
	 */
	protected function sleep_with_backoff( int $seconds ): void {
		if ( $seconds > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_sleep
			sleep( $seconds );
		}
	}
}
