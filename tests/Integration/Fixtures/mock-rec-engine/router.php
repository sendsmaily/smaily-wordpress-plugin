<?php
/**
 * Mock rec-engine router script for `php -S`.
 *
 * Loaded by RecEngineMockServer when an integration test needs the
 * engine but a live deploy isn't available (every CI run, plus
 * local runs without RECENGINE_LIVE=1). Implements just enough of
 * RECENGINE_API_CONTRACT.md to exercise the plugin's connect /
 * ping / disconnect cycle:
 *
 *   POST /setup/exchange       one-time use; first call returns 200
 *                              + tenant config; subsequent calls
 *                              with the same token return 410.
 *   GET  /api/v1/ingest/ping   requires Authorization: Bearer sk_*;
 *                              401 without, 200 + tenant info with.
 *
 * Token state is persisted to /tmp/<state_file> so the "second use
 * returns 410" assertion works across multiple HTTP requests from
 * within a single test method.
 */

// Standard headers per contract §1.
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
header( 'X-Engine-Version: 1.0.0' );

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/';

$state_file = sys_get_temp_dir() . '/smaily-rec-mock-state.json';
$state      = file_exists( $state_file )
	? json_decode( (string) file_get_contents( $state_file ), true )
	: array();
if ( ! is_array( $state ) ) {
	$state = array();
}

function reply( int $status, array $body ): void {
	http_response_code( $status );
	echo json_encode( $body );
	exit;
}

function save_state( string $path, array $state ): void {
	file_put_contents( $path, json_encode( $state ) );
}

/**
 * Automation trigger catalog (§11) — shared by GET /automations/catalog and
 * the PUT /automations/config trigger_key validation ("tundmatu trigger").
 * Mirrors the live shape exactly: all seven per-trigger fields (recipe_en
 * added in contract v1.2.0). Keys are mock-stable so tests
 * can PUT against them; the CONTRACT says render dynamically, so plugin
 * tests must not assert this exact key list as "the" catalog.
 */
function automations_catalog_triggers(): array {
	return array(
		array(
			'key'            => 'replenish_due',
			'name_et'        => 'Taastäitumine',
			'name_en'        => 'Replenishment due',
			'description_et' => 'Käivitub, kui kliendi korduvtoode hakkab ennustuse järgi otsa saama.',
			'description_en' => "Fires when a customer's recurring product is predicted to run out.",
			'recipe_et'      => 'Ehita Smailys "form submitted" trigeriga automatsioon, mille kiri kasutab rec_replenish_sku + soovitusslotte.',
			'recipe_en'      => 'Build a Smaily automation with a "form submitted" trigger; the email uses rec_replenish_sku plus the recommendation slots.',
		),
		array(
			'key'            => 'winback_risk',
			'name_et'        => 'Lahkumisohus klient',
			'name_en'        => 'Win-back risk',
			'description_et' => 'Käivitub, kui klient on ennustuse järgi lahkumas (ostumuster katkenud).',
			'description_en' => 'Fires when a customer is predicted to churn (purchase pattern broken).',
			'recipe_et'      => 'Ehita Smailys "form submitted" trigeriga win-back automatsioon soovitusslottidega.',
			'recipe_en'      => 'Build a Smaily win-back automation with a "form submitted" trigger and recommendation slots.',
		),
		array(
			'key'            => 'life_stage',
			'name_et'        => 'Elufaasi vahetus',
			'name_en'        => 'Life stage change',
			'description_et' => 'Käivitub, kui lemmiklooma elufaas vahetub (nt kutsikas → täiskasvanu).',
			'description_en' => 'Fires when a pet transitions life stage (e.g. puppy to adult).',
			'recipe_et'      => 'Ehita Smailys elufaasi-automatsioon; mootor enrollib kontakti õigel päeval.',
			'recipe_en'      => 'Build a Smaily life-stage automation; the engine enrols the contact on the right day.',
		),
	);
}

/**
 * Bearer-auth check shared by the automations routes (same regex as ping).
 */
function require_bearer_auth(): void {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}
}

// Sub-PR 3.1.2 — engine serves setup under /api/setup/exchange.
// The plugin (Client::PATH_SETUP_EXCHANGE) now sends to the same
// path; mock follows suit so integration tests exercise the live
// route shape, not a mock-only convenience.
if ( $method === 'POST' && $path === '/api/setup/exchange' ) {
	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );
	if ( ! is_array( $body ) || ! isset( $body['setup_token'] ) ) {
		reply(
			400,
			array(
				'error'      => 'invalid_request',
				'message'    => 'setup_token required',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	$token = (string) $body['setup_token'];

	// Honour state file: same token twice = 410.
	if ( isset( $state[ 'used_' . $token ] ) ) {
		reply(
			410,
			array(
				'error'          => 'setup_token_expired_or_used',
				'message'        => 'This setup token has already been used.',
				'regenerate_url' => 'https://mock-engine.test/admin/regenerate/' . $token,
				'request_id'     => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'      => gmdate( 'c' ),
			)
		);
	}

	// Tokens that start with "notfound_" simulate the "not found" case.
	if ( strpos( $token, 'notfound_' ) === 0 ) {
		reply(
			404,
			array(
				'error'      => 'setup_token_not_found',
				'message'    => 'Setup token not found.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Anything else: success. Mark token used.
	$state[ 'used_' . $token ]   = true;
	$state[ 'api_key' ]          = 'sk_mock_' . bin2hex( random_bytes( 16 ) );
	$state[ 'tenant_id' ]        = '00000000-0000-4000-8000-' . bin2hex( random_bytes( 6 ) );
	$state[ 'tenant_name' ]      = 'Mock Tenant';
	save_state( $state_file, $state );

	// Endpoints map MIRRORS the live engine response verified in 3.1.2:
	// keys carry the `ingest_` prefix (NOT bare "catalog") and values are
	// ABSOLUTE URLs (NOT relative paths). The plugin reads
	// endpoints()['ingest_catalog']; a mock serving the old unprefixed/
	// relative shape would pass while production got a null URL — exactly
	// the mock↔engine divergence the path-bug taught us to close. All 14
	// endpoints present (11 from the endpoints-map audit P2 #11, plus the
	// v1.1.0 `automations_*` keys, plus `ingest_catalog_remove` — added to
	// the live map by engine 6b225fb; the Client keeps its absolute-path
	// fallback for pre-6b225fb connections whose stored map lacks the key).
	$engine_base = sprintf( 'http://%s', $_SERVER['HTTP_HOST'] ?? 'localhost:9876' );
	reply(
		200,
		array(
			'tenant_id'       => $state['tenant_id'],
			'tenant_name'     => $state['tenant_name'],
			'api_key'         => $state['api_key'],
			'engine_base_url' => $engine_base,
			'engine_version'  => '1.0.0',
			'endpoints'       => array(
				'ingest_ping'             => $engine_base . '/api/v1/ingest/ping',
				'ingest_catalog'          => $engine_base . '/api/v1/ingest/catalog',
				'ingest_catalog_remove'   => $engine_base . '/api/v1/ingest/catalog/remove',
				'ingest_customers'        => $engine_base . '/api/v1/ingest/customers',
				'ingest_orders'           => $engine_base . '/api/v1/ingest/orders',
				'ingest_browse'           => $engine_base . '/api/v1/ingest/browse',
				'identity_merge'          => $engine_base . '/api/v1/identity/merge',
				// `{email}` placeholder mirrors the LIVE engine endpoints map. A
				// `%s` here would mask a client that fails to substitute `{email}`
				// (the substitution bug a live-walk caught). The customer routes
				// below 404 on a literal-placeholder email to keep this honest.
				'customer_export'         => $engine_base . '/api/v1/customer/{email}/export',
				'customer_delete'         => $engine_base . '/api/v1/customer/{email}',
				'customer_opt_out'        => $engine_base . '/api/v1/customer/{email}/opt-out',
				'recommendations_preview' => $engine_base . '/api/v1/recommendations/preview',
				'recommendations_issue'   => $engine_base . '/api/v1/recommendations/issue',
				'automations_catalog'     => $engine_base . '/api/v1/automations/catalog',
				'automations_config'      => $engine_base . '/api/v1/automations/config',
			),
			'config'          => array(
				'tracking_cookie_name' => 'smaily_rec_uid',
				'session_cookie_name'  => 'smaily_anon_sid',
				'rec_id_cookie_name'   => 'smaily_rec_id',
				'context_cookie_name'  => 'smaily_rec_ctx',
				'cookie_ttl_days'      => 365,
				'session_ttl_days'     => 30,
				'rate_limit_browse'    => 500,
				'rate_limit_other'     => 100,
				'batch_size_max'       => 100,
			),
			'issued_at'       => gmdate( 'c' ),
		)
	);
}

if ( $method === 'GET' && $path === '/api/v1/ingest/ping' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Optional: tenant_id from state lets the test correlate.
	$tenant_id   = isset( $state['tenant_id'] ) ? (string) $state['tenant_id'] : '';
	$tenant_name = isset( $state['tenant_name'] ) ? (string) $state['tenant_name'] : 'Mock Tenant';

	reply(
		200,
		array(
			'ok'                  => true,
			'tenant_id'           => $tenant_id,
			'tenant_name'         => $tenant_name,
			'engine_version'      => '1.0.0',
			'tenant_status'       => 'active',
			'endpoints_available' => array( 'catalog', 'customers', 'orders', 'browse', 'identity_merge' ),
			'server_time'         => gmdate( 'c' ),
		)
	);
}

// Catalog ingest. Mirrors RECENGINE_API_CONTRACT.md §3 + the engine
// team's catalog sanity (6/6): Bearer auth, per-product event_id dedup
// keyed on (tenant, event_id), and a 200 {"deduplicated": true} body when
// the whole batch is a resend. Scenario triggers key on the EVENT_ID prefix
// (auth-401 / retry-429 / retry-500 / d6err), mirroring the other ingest
// endpoints — the `sku` is now the woo-<id> platform key (PRO-1224), never a
// test-controllable string, so triggering on it no longer works.
if ( $method === 'POST' && $path === '/api/v1/ingest/catalog' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	// Wire wrapper key is `products` (§5). W2 (engine `b5b1295`) renamed the
	// catalog wrapper `items` → `products`; the mock must enforce the CURRENT
	// shape, not the pre-W2 one. The mock previously enforced `items` and so
	// hid the plugin's stale `items` send until the N-7.1 catalog live-walk
	// (LESSONS §2.6 — the mock must move to the new shape in the SAME sync,
	// or it masks the drift it exists to catch).
	if ( ! is_array( $body ) || ! isset( $body['products'] ) || ! is_array( $body['products'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'products' => array( 'Required' ) ) ),
			)
		);
	}

	$products = $body['products'];

	$first_event_id = ( isset( $products[0]['event_id'] ) ) ? (string) $products[0]['event_id'] : '';

	// Revoked / invalid key — terminal 4xx, the Client must NOT retry.
	if ( strpos( $first_event_id, 'auth-401' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Transient failure on the FIRST attempt only, then succeed on retry.
	// The per-event_id counter survives across the Client's retry HTTP calls via
	// the state file (the Client resends the same event_id on each retry).
	if ( strpos( $first_event_id, 'retry-429' ) === 0 || strpos( $first_event_id, 'retry-500' ) === 0 ) {
		$counter_key = 'attempts_' . $first_event_id;
		$seen_count  = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );

		if ( $seen_count === 0 ) {
			if ( strpos( $first_event_id, 'retry-429' ) === 0 ) {
				header( 'Retry-After: 1' );
				reply(
					429,
					array(
						'error'      => 'rate_limited',
						'message'    => 'Too many requests — slow down.',
						'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
						'timestamp'  => gmdate( 'c' ),
					)
				);
			}
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
		// seen_count >= 1 → fall through to the success path on retry.
	}

	// Per-product D6 (N-7 retrofit): error (by `d6err` EVENT_ID trigger) /
	// deduplicated (event_id seen) / processed. Natural key is sku; transport
	// dedup is per-item event_id. The per-item error trigger keys on event_id
	// (the sku is now the woo-<id> platform key, PRO-1224 — not test-controllable).
	// (The old all-or-nothing + created/updated/skipped/unmapped_attributes shape
	// is gone — the plugin never consumed it.)
	$seen         = ( isset( $state['catalog_event_ids'] ) && is_array( $state['catalog_event_ids'] ) )
		? $state['catalog_event_ids']
		: array();
	$received     = array();
	$processed    = 0;
	$deduplicated = 0;
	$errors       = array();
	foreach ( $products as $index => $product ) {
		$sku        = isset( $product['sku'] ) ? (string) $product['sku'] : '';
		$event_id   = isset( $product['event_id'] ) ? (string) $product['event_id'] : '';
		$received[] = $event_id;

		if ( strpos( $event_id, 'd6err' ) === 0 ) {
			$errors[] = array(
				'index'   => $index,
				'sku'     => $sku,
				'field'   => 'product_url',
				'message' => 'Invalid input (mock per-item trigger)',
			);
			continue;
		}

		// REQUIRED non-empty (PRO-1491 mock-divergence fix): the mock used to
		// accept an empty/blank category_path, which the real engine rejects
		// with exactly this d6_item_error — the MiuMjau 253-row failure burst
		// this mock gap was hiding. `{lang:value}` counts as blank when every
		// language entry is empty (a live upsert can still 400 on this; a
		// removal is force-filled by CatalogPayloadBuilder::ensure_valid_removal()
		// so it's always sent, never blank).
		$category_path = $product['category_path'] ?? '';
		$category_path_blank = is_array( $category_path )
			? $category_path === array()
			: (string) $category_path === '';
		if ( $category_path_blank ) {
			$errors[] = array(
				'index'   => $index,
				'sku'     => $sku,
				'field'   => 'category_path',
				'message' => 'String must contain at least 1 character(s)',
			);
			continue;
		}

		// REQUIRED non-empty (PRO-1498 mock-divergence fix, folds in PRO-1492):
		// the mock used to accept an empty/blank product_url too, which the real
		// engine rejects with exactly this d6_item_error — mirrors the
		// category_path check above (contract §3, "product_url ... Required,
		// non-empty"). `{lang:value}` counts as blank when every language entry
		// is empty.
		$product_url       = $product['product_url'] ?? '';
		$product_url_blank = is_array( $product_url )
			? $product_url === array()
			: (string) $product_url === '';
		if ( $product_url_blank ) {
			$errors[] = array(
				'index'   => $index,
				'sku'     => $sku,
				'field'   => 'product_url',
				'message' => 'String must contain at least 1 character(s)',
			);
			continue;
		}

		if ( $event_id !== '' && in_array( $event_id, $seen, true ) ) {
			++$deduplicated;
			continue;
		}
		if ( $event_id !== '' ) {
			$seen[] = $event_id;
		}
		++$processed;
	}
	$state['catalog_event_ids']     = $seen;
	$state['last_catalog_received'] = $received;
	$state['last_catalog_skus']     = array_map(
		static function ( $product ) {
			return isset( $product['sku'] ) ? (string) $product['sku'] : '';
		},
		$products
	);
	// CC.3: record name as-sent (string OR {lang:value} object) so a test can
	// assert the multilingual shape reached the wire — the mock accepts both
	// forms but must not silently mask which one was sent.
	$state['last_catalog_names'] = array_map(
		static function ( $product ) {
			return $product['name'] ?? null;
		},
		$products
	);
	// Record in_stock per sku so a test can assert a trashed product was sent as
	// in_stock=false (the catalog.delete → in_stock=false stamp), not dropped.
	$in_stock_by_sku = array();
	foreach ( $products as $product ) {
		$sku = isset( $product['sku'] ) ? (string) $product['sku'] : '';
		if ( $sku !== '' ) {
			$in_stock_by_sku[ $sku ] = ! empty( $product['in_stock'] );
		}
	}
	$state['last_catalog_in_stock'] = $in_stock_by_sku;
	// Record tags per sku so a test can assert tags.product_id (the raw
	// canonical parent id) reached the wire — PRO-1224 grouping / §3b removal
	// key. Optional + backward-compatible, but the mock must not mask whether
	// the plugin actually emits it (CC-8).
	$tags_by_sku = array();
	foreach ( $products as $product ) {
		$sku = isset( $product['sku'] ) ? (string) $product['sku'] : '';
		if ( $sku !== '' ) {
			$tags_by_sku[ $sku ] = ( isset( $product['tags'] ) && is_array( $product['tags'] ) ) ? $product['tags'] : array();
		}
	}
	$state['last_catalog_tags'] = $tags_by_sku;
	// Accumulate sku → tags.product_id across requests so the §3b
	// catalog/remove route can compute removed vs not_found against what was
	// actually ingested (the live engine matches removal on the exact string
	// stored in catalog.tags.product_id).
	$group_by_sku = ( isset( $state['catalog_group_by_sku'] ) && is_array( $state['catalog_group_by_sku'] ) )
		? $state['catalog_group_by_sku']
		: array();
	foreach ( $products as $product ) {
		$sku      = isset( $product['sku'] ) ? (string) $product['sku'] : '';
		$group_id = ( isset( $product['tags']['product_id'] ) ) ? (string) $product['tags']['product_id'] : '';
		if ( $sku !== '' && $group_id !== '' ) {
			$group_by_sku[ $sku ] = $group_id;
		}
	}
	$state['catalog_group_by_sku'] = $group_by_sku;
	save_state( $state_file, $state );

	$response = array(
		'ok'           => true,
		'processed'    => $processed,
		'deduplicated' => $deduplicated,
		'errors'       => $errors,
	);
	if ( $processed === 0 && $deduplicated > 0 && $errors === array() ) {
		$response['deduplicated_all'] = true;
	}
	reply( 200, $response );
}

// Catalog product-level removal — §3b (contract v1.3.0, PRO-1229/PRO-1230).
// Soft tombstone by parent product id: the wrapper carries RAW un-prefixed
// `product_ids` (the exact `tags.product_id` strings the catalog sync
// emitted — NOT the `woo-`-prefixed sku), 1..1000 per request; a malformed
// wrapper is a 400 validation_failed. Idempotent: ids matching no ingested
// row land in `not_found` (a success). NOT D6 — the response is
// {ok, removed_products, rows_tombstoned, not_found}, no per-item errors[].
if ( $method === 'POST' && $path === '/api/v1/ingest/catalog/remove' ) {
	require_bearer_auth();

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	$ids = ( is_array( $body ) && isset( $body['product_ids'] ) && is_array( $body['product_ids'] ) )
		? $body['product_ids']
		: null;
	if ( $ids === null || count( $ids ) === 0 || count( $ids ) > 1000 ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'product_ids' => array( 'Array must contain between 1 and 1000 element(s)' ) ) ),
			)
		);
	}
	$ids = array_map( 'strval', $ids );

	// Match against the accumulated tags.product_id of everything ingested via
	// /ingest/catalog — exact-string match, like the live engine.
	$group_by_sku = ( isset( $state['catalog_group_by_sku'] ) && is_array( $state['catalog_group_by_sku'] ) )
		? $state['catalog_group_by_sku']
		: array();
	$tombstoned   = ( isset( $state['catalog_tombstoned'] ) && is_array( $state['catalog_tombstoned'] ) )
		? $state['catalog_tombstoned']
		: array();

	$removed_products = 0;
	$rows_tombstoned  = 0;
	$not_found        = array();
	foreach ( array_values( array_unique( $ids ) ) as $product_id ) {
		$matched = 0;
		foreach ( $group_by_sku as $sku => $group_id ) {
			if ( (string) $group_id === $product_id ) {
				++$matched;
				$tombstoned[ (string) $sku ] = true;
			}
		}
		if ( $matched > 0 ) {
			++$removed_products;
			$rows_tombstoned += $matched;
		} else {
			$not_found[] = $product_id;
		}
	}

	$state['catalog_tombstoned']   = $tombstoned;
	$state['last_catalog_removed'] = $ids;
	save_state( $state_file, $state );

	reply(
		200,
		array(
			'ok'               => true,
			'removed_products' => $removed_products,
			'rows_tombstoned'  => $rows_tombstoned,
			'not_found'        => array_values( $not_found ),
		)
	);
}

// Customers ingest — W4 email-first + D6 per-item errors[]. Wrapper key is
// `customers` (live-verified in the 3.3.1 probe: {customers:[...]} → 200;
// stable — unlike catalog, which flip-flopped items/products, the customers
// wrapper has not changed). Per-item fate: processed / deduplicated / error.
// Scenario triggers on the FIRST customer's email exercise the flusher's
// terminal-4xx and transient-retry paths; a `d6err-` email prefix on ANY item
// forces that item into errors[] so the partial-success split is testable
// (the plugin can't send a malformed email — WP validates on user create — so
// the mock triggers the error by prefix, not by real validation).
if ( $method === 'POST' && $path === '/api/v1/ingest/customers' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	// Wrapper key is `customers` (W4 §4). Non-array / missing → 400 wrapper-level.
	if ( ! is_array( $body ) || ! isset( $body['customers'] ) || ! is_array( $body['customers'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'customers' => array( 'Required' ) ) ),
			)
		);
	}

	$customers = $body['customers'];

	// Empty / >100 → 400 (the wrapper is all-or-nothing; only per-ITEM
	// failures use errors[]).
	if ( count( $customers ) === 0 || count( $customers ) > 100 ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'customers' => array( 'Array must contain between 1 and 100 element(s)' ) ) ),
			)
		);
	}

	$first_email = isset( $customers[0]['email'] ) ? (string) $customers[0]['email'] : '';

	// Revoked / invalid key — terminal 4xx, the flusher must NOT retry.
	if ( strpos( $first_email, 'auth-401@' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Transient 500 on the FIRST attempt only, then succeed on retry.
	if ( strpos( $first_email, 'retry-500@' ) === 0 ) {
		$counter_key           = 'cust_attempts_' . md5( $first_email );
		$seen_count            = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );
		if ( $seen_count === 0 ) {
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
	}

	// Per-item D6: error (by `d6err-` trigger) / deduplicated (event_id seen) /
	// processed. Natural key is email; transport dedup is per-item event_id.
	$seen         = ( isset( $state['customer_event_ids'] ) && is_array( $state['customer_event_ids'] ) )
		? $state['customer_event_ids']
		: array();
	$processed    = 0;
	$deduplicated = 0;
	$errors       = array();
	foreach ( $customers as $index => $customer ) {
		$email    = isset( $customer['email'] ) ? (string) $customer['email'] : '';
		$event_id = isset( $customer['event_id'] ) ? (string) $customer['event_id'] : '';

		if ( strpos( $email, 'd6err-' ) === 0 ) {
			$errors[] = array(
				'index'   => $index,
				'email'   => $email,
				'field'   => 'email',
				'message' => 'Invalid email (mock per-item trigger)',
			);
			continue;
		}

		if ( $event_id !== '' && in_array( $event_id, $seen, true ) ) {
			++$deduplicated;
			continue;
		}
		if ( $event_id !== '' ) {
			$seen[] = $event_id;
		}
		++$processed;
	}
	$state['customer_event_ids']    = $seen;
	$state['last_customers_received'] = array_map(
		static function ( $customer ) {
			return isset( $customer['event_id'] ) ? (string) $customer['event_id'] : '';
		},
		$customers
	);
	save_state( $state_file, $state );

	$response = array(
		'ok'           => true,
		'processed'    => $processed,
		'deduplicated' => $deduplicated,
		'errors'       => $errors,
	);
	// Pure no-op retry (everything deduplicated, nothing errored).
	if ( $processed === 0 && $deduplicated > 0 && $errors === array() ) {
		$response['deduplicated_all'] = true;
	}
	reply( 200, $response );
}

// Orders ingest — W5 batch + email customer-ref + D6 per-item errors[].
// Wrapper key `orders`, 1..50 per request. Scenario triggers on the FIRST
// order's customer_email (auth-401@ / retry-500@); a `d6err-` customer_email
// on ANY order forces a per-item status error so the partial-success split is
// testable (external_order_id is the WC post id, not controllable in a test;
// the billing email is). Attribution is async — no attribution counts here.
// Orders ingest — §5. Amount semantics (v1.4.0, PRO-1241): all money fields
// are GROSS (tax-inclusive). Deliberately NOT validated here because the LIVE
// engine does not reject on the tax basis either (the §5 sender invariant is
// a monitoring signal, not a 4xx) — a mock-side reject would be stricter than
// live (F3-9 inversion). Gross semantics are pinned by the integration tests
// asserting on `last_orders_payload` below and by the PRO-1241 live-walk.
if ( $method === 'POST' && $path === '/api/v1/ingest/orders' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	if ( ! is_array( $body ) || ! isset( $body['orders'] ) || ! is_array( $body['orders'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'orders' => array( 'Required' ) ) ),
			)
		);
	}

	$orders = $body['orders'];

	// Wrapper is all-or-nothing: empty / >50 → 400 (only per-ITEM failures use errors[]).
	if ( count( $orders ) === 0 || count( $orders ) > 50 ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'orders' => array( 'Array must contain between 1 and 50 element(s)' ) ) ),
			)
		);
	}

	$first_email = isset( $orders[0]['customer_email'] ) ? (string) $orders[0]['customer_email'] : '';

	if ( strpos( $first_email, 'auth-401@' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	if ( strpos( $first_email, 'retry-500@' ) === 0 ) {
		$counter_key           = 'order_attempts_' . md5( $first_email );
		$seen_count            = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );
		if ( $seen_count === 0 ) {
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
	}

	$seen         = ( isset( $state['order_event_ids'] ) && is_array( $state['order_event_ids'] ) )
		? $state['order_event_ids']
		: array();
	$processed    = 0;
	$deduplicated = 0;
	$errors       = array();
	foreach ( $orders as $index => $order ) {
		$external_id = isset( $order['external_order_id'] ) ? (string) $order['external_order_id'] : '';
		$email       = isset( $order['customer_email'] ) ? (string) $order['customer_email'] : '';
		$event_id    = isset( $order['event_id'] ) ? (string) $order['event_id'] : '';

		// Live-engine Zod: items[] is required, min 1. The mock NOT enforcing
		// this hid the pilot's empty-items orders (every line SKU-less or its
		// product deleted) — integration green, live D6-failed (F3-36). Keep
		// this check; the flusher must terminal-skip such orders, never send.
		$order_items = ( isset( $order['items'] ) && is_array( $order['items'] ) ) ? $order['items'] : array();
		if ( count( $order_items ) === 0 ) {
			$errors[] = array(
				'index'             => $index,
				'external_order_id' => $external_id,
				'field'             => 'items',
				'message'           => 'Array must contain at least 1 element(s)',
			);
			continue;
		}

		// Live-engine Zod: `smaily_rec_id: z.string().uuid().optional()`, and
		// orders validate PER ORDER (D6) — a non-uuid rejects THAT order while
		// its batch mates go through. The mock not checking it is what let
		// PRO-1710 hide: a junk `?smaily_rec=` landing value was cookied →
		// stamped on the order → the order permanently rejected live, green
		// here. Regex + message mirror zod verbatim (8-4-4-4-12 hex, no
		// version/variant constraint; "Invalid uuid").
		if ( isset( $order['smaily_rec_id'] )
			&& ( ! is_string( $order['smaily_rec_id'] )
				|| ! preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $order['smaily_rec_id'] ) ) ) {
			$errors[] = array(
				'index'             => $index,
				'external_order_id' => $external_id,
				'field'             => 'smaily_rec_id',
				'message'           => 'Invalid uuid',
			);
			continue;
		}

		// Live-engine Zod: every wire datetime is a strict `.datetime()` — the
		// `Z` form passes, a `+00:00` offset is "Invalid datetime" (the F3-21
		// IsoDate scar, which only ever surfaced live). §5's return signals
		// (v1.8.0, PRO-1633) put a datetime on the LINE for the first time, so
		// the mock validates it there too. The REASON fields are deliberately
		// NOT validated — §5 is normative that an unrecognised reason is stored
		// as `other` and never rejected.
		$bad_returned_at = false;
		foreach ( $order_items as $order_item ) {
			if ( ! is_array( $order_item ) || ! isset( $order_item['returned_at'] ) ) {
				continue;
			}
			if ( ! is_string( $order_item['returned_at'] )
				|| ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $order_item['returned_at'] ) ) {
				$bad_returned_at = true;
				break;
			}
		}
		if ( $bad_returned_at ) {
			$errors[] = array(
				'index'             => $index,
				'external_order_id' => $external_id,
				'field'             => 'items.returned_at',
				'message'           => 'Invalid datetime',
			);
			continue;
		}

		if ( strpos( $email, 'd6err-' ) === 0 ) {
			$errors[] = array(
				'index'             => $index,
				'external_order_id' => $external_id,
				'field'             => 'status',
				'message'           => 'Invalid enum value (mock per-item trigger)',
			);
			continue;
		}

		if ( $event_id !== '' && in_array( $event_id, $seen, true ) ) {
			++$deduplicated;
			continue;
		}
		if ( $event_id !== '' ) {
			$seen[] = $event_id;
		}
		++$processed;
	}
	$state['order_event_ids']     = $seen;
	$state['last_orders_received'] = array_map(
		static function ( $order ) {
			return isset( $order['external_order_id'] ) ? (string) $order['external_order_id'] : '';
		},
		$orders
	);
	// Full wire payloads, so tests can assert items[].sku etc. (PRO-1224 asserts
	// the woo-{id} platform key reached the wire).
	$state['last_orders_payload'] = $orders;
	save_state( $state_file, $state );

	$response = array(
		'ok'           => true,
		'processed'    => $processed,
		'deduplicated' => $deduplicated,
		'errors'       => $errors,
	);
	if ( $processed === 0 && $deduplicated > 0 && $errors === array() ) {
		$response['deduplicated_all'] = true;
	}
	reply( 200, $response );
}

// Browse ingest — §6 batch + D6 per-item errors[]. Wrapper key `events`,
// 1..100 per request (rate limit 500 req/sec, the highest-volume endpoint).
// Browse has NO Layer-1 natural key, so a missing event_id is a per-item
// error (not a silent no-dedup insert), and an invalid event_type is a
// per-item error. Sub-counts: an event with a customer_email / visitor_token
// identity hint is with_customer_match, UNLESS the resolved customer has
// opted out (§10) — then it's forced anonymous (§6/§10 Art 21 engine-side
// gate, PRO-1517): email is checked directly against the opt-out registry,
// smaily_visitor_token via the visitor_token->email registry learned from a
// prior §7 identity/merge (external_id resolution stays unmodeled — no
// registry for it). Scenario triggers ride the FIRST event's event_id prefix
// (auth-401- / retry-500-). The plugin beacon proxy pre-filters junk (id-less
// / bad-type → 400 before it reaches here), so this route is the engine's
// strict D6 validator that a direct Client::ingest_browse call (and the
// live-walk) exercises.
if ( $method === 'POST' && $path === '/api/v1/ingest/browse' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply(
			401,
			array(
				'error'      => 'unauthorized',
				'message'    => 'Authorization header missing or malformed.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	// Wrapper key is `events` (§6). Non-array / missing → 400 wrapper-level.
	if ( ! is_array( $body ) || ! isset( $body['events'] ) || ! is_array( $body['events'] ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'events' => array( 'Required' ) ) ),
			)
		);
	}

	$events = $body['events'];

	// Empty / >100 → 400 (wrapper all-or-nothing; per-EVENT failures use errors[]).
	if ( count( $events ) === 0 || count( $events ) > 100 ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'events' => array( 'Array must contain between 1 and 100 element(s)' ) ) ),
			)
		);
	}

	$first_id = isset( $events[0]['event_id'] ) ? (string) $events[0]['event_id'] : '';

	// Revoked / invalid key — terminal 4xx, no retry.
	if ( strpos( $first_id, 'auth-401-' ) === 0 ) {
		reply(
			401,
			array(
				'error'      => 'api_key_revoked',
				'message'    => 'This api_key has been revoked.',
				'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
				'timestamp'  => gmdate( 'c' ),
			)
		);
	}

	// Transient 500 on the FIRST attempt only, then succeed on retry.
	if ( strpos( $first_id, 'retry-500-' ) === 0 ) {
		$counter_key           = 'browse_attempts_' . md5( $first_id );
		$seen_count            = isset( $state[ $counter_key ] ) ? (int) $state[ $counter_key ] : 0;
		$state[ $counter_key ] = $seen_count + 1;
		save_state( $state_file, $state );
		if ( $seen_count === 0 ) {
			reply(
				500,
				array(
					'error'      => 'internal_error',
					'message'    => 'Transient engine error.',
					'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
					'timestamp'  => gmdate( 'c' ),
				)
			);
		}
	}

	$valid_types = array(
		'product_view',
		'category_view',
		'search',
		'cart_add',
		'cart_remove',
		'wishlist_add',
		'wishlist_remove',
		'checkout_start',
		'checkout_complete',
	);

	// Per-event D6: missing event_id / bad event_type → error; seen event_id →
	// deduplicated; else processed (with_customer_match vs anonymous by hint).
	$seen               = ( isset( $state['browse_event_ids'] ) && is_array( $state['browse_event_ids'] ) )
		? $state['browse_event_ids']
		: array();
	// §6/§10 Art 21 engine-side gate: an opted-out customer is never bound on
	// any resolution path — the event is stored anonymous. Email is checked
	// directly against the opt-out registry; smaily_visitor_token is resolved
	// via `visitor_token_emails` (learned from a prior §7 identity/merge),
	// mirroring the real engine's server-side token->customer resolution
	// (PRO-1517). external_id resolution is still unmodeled (no registry).
	$opted_out_emails    = ( isset( $state['opted_out_emails'] ) && is_array( $state['opted_out_emails'] ) )
		? $state['opted_out_emails']
		: array();
	$visitor_token_emails = ( isset( $state['visitor_token_emails'] ) && is_array( $state['visitor_token_emails'] ) )
		? $state['visitor_token_emails']
		: array();
	$processed          = 0;
	$deduplicated       = 0;
	$with_customer      = 0;
	$anonymous          = 0;
	$errors             = array();
	foreach ( $events as $index => $event ) {
		$event_id   = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
		$event_type = isset( $event['event_type'] ) ? (string) $event['event_type'] : '';

		if ( trim( $event_id ) === '' ) {
			$errors[] = array( 'index' => $index, 'field' => 'event_id', 'message' => 'Required' );
			continue;
		}
		if ( ! in_array( $event_type, $valid_types, true ) ) {
			$errors[] = array( 'index' => $index, 'field' => 'event_type', 'message' => 'Invalid enum value' );
			continue;
		}
		if ( in_array( $event_id, $seen, true ) ) {
			++$deduplicated;
			continue;
		}
		$seen[] = $event_id;
		++$processed;
		$email         = isset( $event['customer_email'] ) ? (string) $event['customer_email'] : '';
		$visitor_token = isset( $event['smaily_visitor_token'] ) ? (string) $event['smaily_visitor_token'] : '';
		$token_email   = ( $visitor_token !== '' && isset( $visitor_token_emails[ $visitor_token ] ) )
			? $visitor_token_emails[ $visitor_token ]
			: '';
		$is_opted_out = ( $email !== '' && isset( $opted_out_emails[ $email ] ) )
			|| ( $token_email !== '' && isset( $opted_out_emails[ $token_email ] ) );
		$has_identity = ( $email !== '' || $visitor_token !== '' );
		if ( $has_identity && ! $is_opted_out ) {
			++$with_customer;
		} else {
			++$anonymous;
		}
	}
	$state['browse_event_ids']     = $seen;
	$state['last_browse_received'] = array_map(
		static function ( $event ) {
			return isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
		},
		$events
	);
	// Full projection so a test can assert an identity field survived the proxy
	// whitelist and reached the engine (F3-49 browse visitor_token pass-through;
	// PRO-1389 server-side customer_email injection for a logged-in session).
	// customer_email is omitted from the row entirely (not even as '') when
	// absent, so a test can assert on absence with assertArrayNotHasKey.
	$state['last_browse_events'] = array_map(
		static function ( $event ) {
			$row = array(
				'event_id'             => isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
				'smaily_visitor_token' => isset( $event['smaily_visitor_token'] ) ? (string) $event['smaily_visitor_token'] : '',
				'sku'                  => isset( $event['sku'] ) ? (string) $event['sku'] : null,
			);
			if ( isset( $event['customer_email'] ) && (string) $event['customer_email'] !== '' ) {
				$row['customer_email'] = (string) $event['customer_email'];
			}
			return $row;
		},
		$events
	);
	save_state( $state_file, $state );

	$response = array(
		'ok'                 => true,
		'processed'          => $processed,
		'deduplicated'       => $deduplicated,
		'errors'             => $errors,
		'with_customer_match' => $with_customer,
		'anonymous'          => $anonymous,
		'retroactive_bound'  => 0,
		'duplicates_skipped' => $deduplicated,
	);
	if ( $processed === 0 && $deduplicated > 0 && $errors === array() ) {
		$response['deduplicated_all'] = true;
	}
	reply( 200, $response );
}

// Identity merge — §7 anon-session → known customer. Triggers on customer_email:
// a `notfound@` prefix simulates the 404 (customer not ingested yet); a repeated
// (anon_session_id, email) is the idempotent no-op (already_bound). Records the
// last merge so a test can assert read/write symmetry.
if ( $method === 'POST' && $path === '/api/v1/identity/merge' ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply( 401, array( 'error' => 'unauthorized', 'message' => 'Authorization header missing or malformed.' ) );
	}

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$email = isset( $body['customer_email'] ) ? (string) $body['customer_email'] : '';
	$anon  = isset( $body['anon_session_id'] ) ? (string) $body['anon_session_id'] : '';

	// Required: customer_email + merge_ts; at least one of anon/token.
	if ( $email === '' || empty( $body['merge_ts'] ) || ( $anon === '' && empty( $body['smaily_visitor_token'] ) ) ) {
		reply(
			400,
			array(
				'error'   => 'validation_failed',
				'details' => array( 'fieldErrors' => array( 'customer_email' => array( 'Required' ) ) ),
			)
		);
	}

	// Customer not yet ingested → 404 (the caller logs + skips).
	if ( strpos( $email, 'notfound@' ) === 0 ) {
		reply(
			404,
			array(
				'error'   => 'customer_not_found',
				'message' => 'No customer found with email ' . $email . '. Send via POST /api/v1/ingest/customers first.',
			)
		);
	}

	$merge_key   = 'merge_' . md5( $anon . '|' . $email );
	$already     = isset( $state[ $merge_key ] );
	$state[ $merge_key ]          = true;
	$state['last_merge_received'] = array(
		'anon_session_id'      => $anon,
		'smaily_visitor_token' => isset( $body['smaily_visitor_token'] ) ? (string) $body['smaily_visitor_token'] : '',
		'customer_email'       => $email,
		'merge_reason'         => isset( $body['merge_reason'] ) ? (string) $body['merge_reason'] : '',
	);
	// PRO-1517 — record token->email so a later browse event resolved ONLY via
	// smaily_visitor_token (no email on the event) can be checked against §10
	// opt-out state at ingest, mirroring the real engine's server-side
	// visitor_token->customer resolution (§7).
	$visitor_token = isset( $body['smaily_visitor_token'] ) ? (string) $body['smaily_visitor_token'] : '';
	if ( $visitor_token !== '' ) {
		if ( ! isset( $state['visitor_token_emails'] ) || ! is_array( $state['visitor_token_emails'] ) ) {
			$state['visitor_token_emails'] = array();
		}
		$state['visitor_token_emails'][ $visitor_token ] = $email;
	}
	save_state( $state_file, $state );

	reply(
		200,
		array(
			'ok'          => true,
			'customer_id' => '550e8400-' . md5( $email ),
			'merged'      => array(
				'browse_events_updated' => $already ? 0 : 12,
				'visitor_tokens_bound'  => 1,
				'session_history_days'  => 22,
			),
		)
	);
}

// GDPR export (§8). The email is in the path (rawurlencoded). Returns rec
// activity + a customer record WITH decision-logic fields (so a test can assert
// the plugin strips them) + orders/order_items (so a test can assert the plugin
// does NOT re-export Woo data). A `notfound`-prefixed email → 404.
if ( $method === 'GET' && preg_match( '#^/api/v1/customer/([^/]+)/export$#', $path, $m ) ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply( 401, array( 'error' => 'unauthorized' ) );
	}
	$email = urldecode( $m[1] );
	// A client that fails to substitute the `{email}` placeholder (e.g. sprintf
	// on a `{email}` URL) sends the literal token. The live engine then looks up
	// the literal string; here we make that an explicit 422 so the integration
	// suite fails loudly instead of silently "finding" a placeholder customer.
	if ( $email === '{email}' || strpos( $email, '%s' ) !== false ) {
		reply( 422, array( 'error' => 'unsubstituted_placeholder', 'message' => 'Email path param was not substituted: ' . $email ) );
	}
	if ( strpos( $email, 'notfound' ) === 0 ) {
		reply( 404, array( 'error' => 'not_found', 'message' => 'No customer with email ' . $email ) );
	}
	reply(
		200,
		array(
			'export_metadata' => array( 'customer_email' => $email ),
			'customer'        => array(
				'email'             => $email,
				'first_name'        => 'Mari',
				'country'           => 'EE',
				'segment'           => 'high_value',   // decision logic — plugin must strip
				'rfm_recency'       => 5,              // decision logic
				'engagement_score'  => 0.91,           // decision logic
				'inferred_species'  => 'dog',          // decision logic
			),
			'browse_events'   => array(
				array( 'event_id' => 'be-1', 'event_type' => 'product_view', 'sku' => 'ACA-1' ),
				array( 'event_id' => 'be-2', 'event_type' => 'cart_add', 'sku' => 'ACA-1' ),
			),
			'recommendations' => array( array( 'rec_id' => 'r-1', 'sku' => 'ACA-2' ) ),
			'visitor_tokens'  => array( array( 'token' => 'vt_abc' ) ),
			'email_events'    => array(),
			'orders'          => array( array( 'external_order_id' => 'WC-1', 'total_amount' => '67.50' ) ), // Woo — plugin must NOT export
			'order_items'     => array( array( 'sku' => 'ACA-1', 'line_total' => '67.50' ) ),                // Woo
		)
	);
}

// GDPR delete (§9). Idempotent: a second delete for the same email → 404.
if ( $method === 'DELETE' && preg_match( '#^/api/v1/customer/([^/]+)$#', $path, $m ) ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply( 401, array( 'error' => 'unauthorized' ) );
	}
	$email = urldecode( $m[1] );
	if ( $email === '{email}' || strpos( $email, '%s' ) !== false ) {
		reply( 422, array( 'error' => 'unsubstituted_placeholder', 'message' => 'Email path param was not substituted: ' . $email ) );
	}
	$key   = 'gdpr_deleted_' . md5( $email );
	if ( isset( $state[ $key ] ) ) {
		reply( 404, array( 'error' => 'not_found', 'message' => 'Customer already deleted.' ) );
	}
	$state[ $key ] = true;
	save_state( $state_file, $state );
	reply(
		200,
		array(
			'ok'              => true,
			'deleted'         => true,
			'customer_email'  => $email,
			'records_removed' => array( 'customer' => 1, 'browse_events' => 2, 'rec_attribution' => 3, 'visitor_tokens' => 1 ),
			'audit_log_id'    => 'audit_' . bin2hex( random_bytes( 4 ) ),
			'deleted_at'      => gmdate( 'c' ),
		)
	);
}

// GDPR opt-out (§10). A `notfound`-prefixed email → 404 (contract: "Response
// 404 Not Found if the customer doesn't exist"), same trigger convention as
// export/merge above. The contract gives no example 404 body for §10, so this
// mirrors the exact shape already used by the sibling §8/§9 routes in this
// file (and the Shopify reference mock's PRO-1477 fix — commit `cb262c4`,
// ../shopify-connect) rather than inventing one (PRO-1517).
if ( $method === 'POST' && preg_match( '#^/api/v1/customer/([^/]+)/opt-out$#', $path, $m ) ) {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if ( ! preg_match( '/^Bearer\s+sk_[A-Za-z0-9_]+$/', $auth ) ) {
		reply( 401, array( 'error' => 'unauthorized' ) );
	}
	$email = urldecode( $m[1] );
	if ( $email === '{email}' || strpos( $email, '%s' ) !== false ) {
		reply( 422, array( 'error' => 'unsubstituted_placeholder', 'message' => 'Email path param was not substituted: ' . $email ) );
	}
	if ( strpos( $email, 'notfound' ) === 0 ) {
		reply( 404, array( 'error' => 'not_found', 'message' => 'No customer with email ' . $email ) );
	}
	$raw   = (string) file_get_contents( 'php://input' );
	$body  = json_decode( $raw, true );
	$flag  = is_array( $body ) && ! empty( $body['opt_out'] );
	// PRO-1517 — persist opted-out emails so browse ingest (§6) can apply the
	// §10 Art 21 engine-side binding gate (email-direct and visitor-token-
	// resolved), mirroring the real engine's server-side enforcement.
	if ( ! isset( $state['opted_out_emails'] ) || ! is_array( $state['opted_out_emails'] ) ) {
		$state['opted_out_emails'] = array();
	}
	if ( $flag ) {
		$state['opted_out_emails'][ $email ] = true;
	} else {
		unset( $state['opted_out_emails'][ $email ] );
	}
	save_state( $state_file, $state );
	reply(
		200,
		array(
			'ok'              => true,
			'customer_email'  => $email,
			'opt_out_status'  => $flag,
			'previous_status' => false,
		)
	);
}

// Automations trigger catalog (§11, contract v1.1.0). Read-only; sector-
// filtered live (the mock serves a fixed 3-trigger "pet" catalog). Top-level
// shape is {triggers, language_modes, docs} — `docs` is the stable help URL
// the plugin must link from the RESPONSE, never hardcode.
if ( $method === 'GET' && $path === '/api/v1/automations/catalog' ) {
	require_bearer_auth();
	reply(
		200,
		array(
			'triggers'       => automations_catalog_triggers(),
			'language_modes' => array( 'single', 'per_language' ),
			'docs'           => 'https://mock-engine.test/docs/en/smaily-templates',
		)
	);
}

// Automations config read (§12). Rows exist only for triggers configured at
// least once — a fresh tenant returns {configs: []}. Each row carries the
// eight §13 fields PLUS the read-only configured_via + updated_at (engine-
// written; tolerated-but-overwritten if a client round-trips them).
if ( $method === 'GET' && $path === '/api/v1/automations/config' ) {
	require_bearer_auth();
	$rows = ( isset( $state['automations_configs'] ) && is_array( $state['automations_configs'] ) )
		? array_values( $state['automations_configs'] )
		: array();
	reply( 200, array( 'configs' => $rows ) );
}

// Automations config save (§13). Full-selection UPSERT on trigger_key —
// PUT never deletes (absent trigger keeps its stored row). Validation is
// ALL-OR-NOTHING (unlike ingest D6 partial success): any invalid row → 422
// with the indexed D6-style errors[] and NOTHING saved. Wrapper violations
// (non-array / empty / >50 configs) use the same 422 shape with NO index and
// field="configs". Custom-check messages are Estonian ("tundmatu trigger",
// "automation_map.id on nõutav"), structural ones English ("Required",
// "Invalid email") — mirror of the live Zod schema, don't loosen it.
if ( $method === 'PUT' && $path === '/api/v1/automations/config' ) {
	require_bearer_auth();

	$raw  = (string) file_get_contents( 'php://input' );
	$body = json_decode( $raw, true );

	// §13: a non-JSON body is a 400 {"error":"invalid_json"} — note: no
	// message/details on this one.
	if ( ! is_array( $body ) ) {
		reply( 400, array( 'error' => 'invalid_json' ) );
	}

	$configs = isset( $body['configs'] ) ? $body['configs'] : null;
	if ( ! is_array( $configs ) || count( $configs ) === 0 || count( $configs ) > 50 ) {
		reply(
			422,
			array(
				'error'  => 'validation_failed',
				'errors' => array(
					array(
						'field'   => 'configs',
						'message' => 'Array must contain between 1 and 50 element(s)',
					),
				),
			)
		);
	}

	$catalog_keys = array_map(
		static function ( array $trigger ): string {
			return $trigger['key'];
		},
		automations_catalog_triggers()
	);
	$modes        = array( 'single', 'per_language' );
	$required     = array( 'trigger_key', 'enabled', 'language_mode', 'automation_map', 'cooldown_days', 'daily_cap', 'test_mode', 'test_emails' );

	$errors       = array();
	$rows_to_save = array();
	foreach ( array_values( $configs ) as $index => $row ) {
		if ( ! is_array( $row ) ) {
			$errors[] = array( 'index' => $index, 'field' => 'unknown', 'message' => 'Expected object' );
			continue;
		}

		$trigger_key = ( isset( $row['trigger_key'] ) && is_string( $row['trigger_key'] ) ) ? $row['trigger_key'] : '';
		$row_error   = static function ( string $field, string $message ) use ( &$errors, $index, $trigger_key ): void {
			$entry = array( 'index' => $index );
			if ( $trigger_key !== '' ) {
				// trigger_key is included when readable from the body — it
				// helps the UI map the error to a row.
				$entry['trigger_key'] = $trigger_key;
			}
			$entry['field']   = $field;
			$entry['message'] = $message;
			$errors[]         = $entry;
		};

		// All 8 keys REQUIRED on every row (no server-side defaults);
		// daily_cap is nullable but its KEY must be present.
		$missing = false;
		foreach ( $required as $required_key ) {
			if ( ! array_key_exists( $required_key, $row ) ) {
				$row_error( $required_key, 'Required' );
				$missing = true;
			}
		}
		if ( $missing ) {
			continue;
		}

		$errors_before = count( $errors );

		if ( $trigger_key === '' || ! in_array( $trigger_key, $catalog_keys, true ) ) {
			$row_error( 'trigger_key', 'tundmatu trigger' );
		}
		if ( ! is_bool( $row['enabled'] ) ) {
			$row_error( 'enabled', 'Expected boolean' );
		}
		if ( ! is_string( $row['language_mode'] ) || ! in_array( $row['language_mode'], $modes, true ) ) {
			$row_error( 'language_mode', 'Invalid enum value' );
		}
		if ( ! is_array( $row['automation_map'] ) ) {
			$row_error( 'automation_map', 'Expected object' );
		} else {
			foreach ( $row['automation_map'] as $map_key => $map_value ) {
				if ( ! is_string( $map_value ) || preg_match( '/^\d+$/', $map_value ) !== 1 ) {
					$row_error( 'automation_map.' . $map_key, 'automatsiooni id peab olema number' );
				}
			}
			// enabled=true binding requirement: single needs `id`,
			// per_language needs `fallback` (enabled=false may be {}).
			if ( $row['enabled'] === true && $row['language_mode'] === 'single' && ! isset( $row['automation_map']['id'] ) ) {
				$row_error( 'automation_map', 'automation_map.id on nõutav' );
			}
			if ( $row['enabled'] === true && $row['language_mode'] === 'per_language' && ! isset( $row['automation_map']['fallback'] ) ) {
				$row_error( 'automation_map', 'automation_map.fallback on nõutav' );
			}
		}
		if ( ! is_int( $row['cooldown_days'] ) || $row['cooldown_days'] < 1 || $row['cooldown_days'] > 365 ) {
			$row_error( 'cooldown_days', 'Number must be between 1 and 365' );
		}
		if ( $row['daily_cap'] !== null && ( ! is_int( $row['daily_cap'] ) || $row['daily_cap'] < 1 || $row['daily_cap'] > 100000 ) ) {
			$row_error( 'daily_cap', 'Number must be between 1 and 100000, or null' );
		}
		if ( ! is_bool( $row['test_mode'] ) ) {
			$row_error( 'test_mode', 'Expected boolean' );
		}
		if ( ! is_array( $row['test_emails'] ) ) {
			$row_error( 'test_emails', 'Expected array' );
		} elseif ( count( $row['test_emails'] ) > 50 ) {
			$row_error( 'test_emails', 'Array must contain at most 50 element(s)' );
		} else {
			foreach ( array_values( $row['test_emails'] ) as $email_index => $email ) {
				if ( ! is_string( $email ) || filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
					$row_error( 'test_emails.' . $email_index, 'Invalid email' );
				}
			}
		}

		if ( count( $errors ) === $errors_before ) {
			// Unknown keys are STRIPPED, not rejected (standard Zod object
			// behavior) — incl. round-tripped configured_via/updated_at.
			$clean = array();
			foreach ( $required as $required_key ) {
				$clean[ $required_key ] = $row[ $required_key ];
			}
			$rows_to_save[] = $clean;
		}
	}

	if ( $errors !== array() ) {
		// All-or-nothing: nothing is written on a 422.
		reply( 422, array( 'error' => 'validation_failed', 'errors' => $errors ) );
	}

	$stored = ( isset( $state['automations_configs'] ) && is_array( $state['automations_configs'] ) )
		? $state['automations_configs']
		: array();
	foreach ( $rows_to_save as $clean ) {
		$clean['configured_via']                  = 'plugin';
		$clean['updated_at']                      = gmdate( 'Y-m-d\TH:i:s' ) . '.000Z';
		$stored[ $clean['trigger_key'] ]          = $clean;
	}
	$state['automations_configs'] = $stored;
	save_state( $state_file, $state );

	reply( 200, array( 'ok' => true, 'upserted' => count( $rows_to_save ) ) );
}

// Fallback.
reply(
	404,
	array(
		'error'      => 'not_found',
		'message'    => sprintf( 'Mock engine has no route for %s %s', $method, $path ),
		'request_id' => 'req_' . bin2hex( random_bytes( 4 ) ),
		'timestamp'  => gmdate( 'c' ),
	)
);
