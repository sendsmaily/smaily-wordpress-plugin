/**
 * Sub-PR 3.4.4 live harness — browse ingest against the REAL Smaily rec engine
 * (the connected SANDBOX tenant "Smaily Connect test" — NEVER MiuMjau, which is
 * the pilot's PRODUCTION tenant). Browse is the first CLIENT-originated domain, so the walk
 * differs from the server-walks:
 *
 *   - PROXY path (in-process REST dispatch to POST /beacon): the full chain
 *     BeaconEndpoint → validate/rate-limit → Client::ingest_browse → live
 *     engine. The /beacon route is public + nonce-less, so an in-process
 *     dispatch is a faithful proof (the HTTP layer adds no auth).
 *   - CLIENT-direct (Client::ingest_browse): the §6 per-item behaviours the
 *     proxy would 400 before they reach the engine (id-less / bad-type events),
 *     plus anonymous vs with_customer_match and retroactive binding.
 *   - ABUSE on the live /beacon: 101-cap / bad-type / id-less → 400, and the
 *     per-session rate limit → 429 (cookie + lowered filter, since CLI has no
 *     REMOTE_ADDR / cookie of its own).
 *
 * NOT covered (honest boundary): the browser MOMENT a page-view fires
 * (checkout_start on the checkout page, checkout_complete on order-received).
 * The server-side proxy can't observe browser page state. The engine ACCEPTS
 * those types (the 9-types check), the PHP page detection is covered by
 * StorefrontBeaconTest, the JS mapping by vitest; the render moment is a manual
 * pilot verification / future E2E (see STATUS + CLAUDE).
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected tenant in the wp-env DB.
 * MUST run before any integration-suite run — EnvScrub wipes the connection.
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PLUGIN_PATH_IN_CONTAINER = '/var/www/html/wp-content/plugins/smaily-connect';

const runDocker = (args) => {
  const joined = args.map((a) => `'${a.replace(/'/g, "'\\''")}'`).join(' ');
  let canDocker = true;
  try { execSync('docker ps', { stdio: 'pipe' }); } catch { canDocker = false; }
  const cmd = canDocker ? `docker ${joined}` : `sg docker -c "docker ${joined}"`;
  return execSync(cmd, { stdio: 'pipe', maxBuffer: 1024 * 1024 * 32 }).toString();
};

const findCliContainer = () => {
  const list = runDocker(['ps', '--filter', 'name=wp-env-', '--filter', 'name=-cli-1', '--format', '{{.Names}}'])
    .split('\n').filter((n) => n && !n.includes('-tests-cli-1'));
  if (list.length === 0) {
    throw new Error('No wp-env cli container found. Start wp-env: npx @wordpress/env start');
  }
  return list[0];
};

const LIVE_PHP = String.raw`<?php
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$settings = new \Smaily\Connect\Settings\RecEngineSettings();

// The beacon proxy gate needs browse-tracking on; register the routes (rest_api_init
// only fires on a real REST request, not inside wp eval-file).
update_option( 'smly_plus_rec_track_browsing', true );
do_action( 'rest_api_init' );

$client = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints() );

// Build a valid browse event with the common required fields + per-type extra.
function browse_event( $type, $extra = array() ) {
	$base = array(
		'event_id'   => wp_generate_uuid4(),
		'session_id' => 'walk-sess-' . wp_generate_uuid4(),
		'event_type' => $type,
		'event_ts'   => IsoDate::to_z( time() ),
		'source'     => 'plugin_woo',
	);
	return array_merge( $base, $extra );
}

// In-process dispatch to the public /beacon proxy → BeaconEndpoint → live engine.
function dispatch_beacon( $events ) {
	$req = new WP_REST_Request( 'POST', '/smaily-connect/v1/relay' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_param( 'events', $events );
	$resp = rest_get_server()->dispatch( $req );
	return array( 'status' => $resp->get_status(), 'data' => $resp->get_data() );
}

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

// --- A. PROXY path against the live engine -------------------------------
$happy = dispatch_beacon( array( browse_event( 'product_view', array( 'sku' => 'WALK-PV-1', 'category_path' => 'food/dry' ) ) ) );
result( 'proxy_happy_200', ( $happy['status'] === 200 ) && ( (int) ( $happy['data']['processed'] ?? 0 ) >= 1 ), json_encode( $happy ) );

// All 9 §6 event types in one batch, each carrying its required field.
$nine = array(
	browse_event( 'product_view', array( 'sku' => 'WALK-9-PV' ) ),
	browse_event( 'category_view', array( 'category_path' => 'food/dry' ) ),
	browse_event( 'search', array( 'search_query' => 'dog food' ) ),
	browse_event( 'cart_add', array( 'sku' => 'WALK-9-CA' ) ),
	browse_event( 'cart_remove', array( 'sku' => 'WALK-9-CR' ) ),
	browse_event( 'wishlist_add', array( 'sku' => 'WALK-9-WA' ) ),
	browse_event( 'wishlist_remove', array( 'sku' => 'WALK-9-WR' ) ),
	browse_event( 'checkout_start' ),
	browse_event( 'checkout_complete' ),
);
$nine_resp = dispatch_beacon( $nine );
result( 'proxy_nine_types_all_processed', ( $nine_resp['status'] === 200 ) && ( (int) ( $nine_resp['data']['processed'] ?? 0 ) === 9 ), 'processed=' . ( $nine_resp['data']['processed'] ?? 'n/a' ) . ' (EventType 8->9 confirmed live)' );

// --- B. CLIENT-direct: engine §6 specifics the proxy filters before engine -
// Anonymous vs with_customer_match needs a resolvable customer; create one.
$cust_email = 'walk-browse-' . time() . '@example.test';
$cust = $client->ingest_customers( array( array( 'email' => $cust_email, 'event_id' => wp_generate_uuid4(), 'first_seen_at' => IsoDate::to_z( time() ) ) ) );
result( 'customer_seeded_for_match', (int) ( $cust['processed'] ?? 0 ) >= 1 || (int) ( $cust['deduplicated'] ?? 0 ) >= 1, json_encode( $cust ) );

$match = $client->ingest_browse( array(
	browse_event( 'product_view', array( 'sku' => 'WALK-M-1', 'customer_email' => $cust_email ) ),
	browse_event( 'product_view', array( 'sku' => 'WALK-M-2' ) ),
) );
result( 'anon_vs_with_customer_match', ( (int) ( $match['with_customer_match'] ?? 0 ) >= 1 ) && ( (int) ( $match['anonymous'] ?? 0 ) >= 1 ), 'match=' . ( $match['with_customer_match'] ?? 'n/a' ) . ' anon=' . ( $match['anonymous'] ?? 'n/a' ) );

// F3-49: the plugin now carries smaily_visitor_token on browse events (identity
// for the engine's cold-start binding, NOT attribution — attribution rides
// order signals). Prove the LIVE engine ACCEPTS the field on a browse event (no
// per-item rejection). Whether an arbitrary token RESOLVES to with_customer_match
// depends on it already existing in the engine's visitor_tokens table (issued on
// an email click), which a walk can't seed — so assert acceptance, not resolution.
$vt_evt = $client->ingest_browse( array(
	browse_event( 'product_view', array( 'sku' => 'WALK-VT-1', 'smaily_visitor_token' => 'vt_walk_' . wp_generate_uuid4() ) ),
) );
result( 'engine_accepts_browse_visitor_token', ( (int) ( $vt_evt['processed'] ?? 0 ) >= 1 ) && ( ( $vt_evt['errors'] ?? array() ) === array() ), json_encode( $vt_evt ) );

// Missing event_id → engine per-item error (no Layer-1 natural key for browse).
$no_id = $client->ingest_browse( array( array( 'session_id' => 'walk-noid', 'event_type' => 'product_view', 'event_ts' => IsoDate::to_z( time() ), 'sku' => 'WALK-NOID' ) ) );
$no_id_err = $no_id['errors'][0]['field'] ?? '';
result( 'engine_missing_event_id_per_item_error', $no_id_err === 'event_id', json_encode( $no_id['errors'] ?? array() ) );

// Invalid event_type → engine per-item error.
$bad_type = $client->ingest_browse( array( browse_event( 'totally_bogus', array( 'sku' => 'WALK-BT' ) ) ) );
$bad_type_err = $bad_type['errors'][0]['field'] ?? '';
result( 'engine_invalid_event_type_per_item_error', $bad_type_err === 'event_type', json_encode( $bad_type['errors'] ?? array() ) );

// Dedup: resend the same event_id.
$dup_evt = browse_event( 'product_view', array( 'sku' => 'WALK-DUP' ) );
$client->ingest_browse( array( $dup_evt ) );
$dup2 = $client->ingest_browse( array( $dup_evt ) );
result( 'engine_event_id_deduplicated', (int) ( $dup2['deduplicated'] ?? 0 ) >= 1, json_encode( $dup2 ) );

// Retroactive binding: anon events on session S, then an S event carrying the
// known email → the engine rebinds the earlier anon events to that customer.
$retro_session = 'walk-retro-' . wp_generate_uuid4();
$client->ingest_browse( array(
	browse_event( 'product_view', array( 'sku' => 'WALK-R-1', 'session_id' => $retro_session ) ),
	browse_event( 'category_view', array( 'category_path' => 'food/dry', 'session_id' => $retro_session ) ),
) );
$retro = $client->ingest_browse( array( browse_event( 'product_view', array( 'sku' => 'WALK-R-3', 'session_id' => $retro_session, 'customer_email' => $cust_email ) ) ) );
result( 'engine_retroactive_binding', (int) ( $retro['retroactive_bound'] ?? 0 ) >= 1, 'retroactive_bound=' . ( $retro['retroactive_bound'] ?? 'n/a' ) );

// --- C. Abuse model on the live /beacon endpoint -------------------------
$too_many = array();
for ( $i = 0; $i < 101; $i++ ) {
	$too_many[] = browse_event( 'product_view', array( 'sku' => 'WALK-CAP-' . $i ) );
}
$cap = dispatch_beacon( $too_many );
result( 'abuse_101_events_400', $cap['status'] === 400, 'status=' . $cap['status'] );

$abuse_type = dispatch_beacon( array( browse_event( 'nope_not_real', array( 'sku' => 'X' ) ) ) );
result( 'abuse_bad_type_400', ( $abuse_type['status'] === 400 ) && ( ( $abuse_type['data']['field'] ?? '' ) === 'event_type' ), json_encode( $abuse_type ) );

$abuse_noid = dispatch_beacon( array( array( 'event_type' => 'product_view', 'session_id' => 'x', 'event_ts' => IsoDate::to_z( time() ) ) ) );
result( 'abuse_missing_id_400', ( $abuse_noid['status'] === 400 ) && ( ( $abuse_noid['data']['field'] ?? '' ) === 'event_id' ), json_encode( $abuse_noid ) );

// Rate limit: CLI has no cookie/REMOTE_ADDR, so simulate a session + squeeze
// the per-session ceiling to 2 → the 3rd request in the window trips 429.
$_COOKIE['smaily_anon_sid'] = 'walk-rl-' . wp_generate_uuid4();
delete_transient( 'smly_beacon_rl_s_' . md5( $_COOKIE['smaily_anon_sid'] ) );
add_filter( 'smaily_connect_beacon_rate_limit_session', function () { return 2; } );
$rl1 = dispatch_beacon( array( browse_event( 'product_view', array( 'sku' => 'WALK-RL-1' ) ) ) );
$rl2 = dispatch_beacon( array( browse_event( 'product_view', array( 'sku' => 'WALK-RL-2' ) ) ) );
$rl3 = dispatch_beacon( array( browse_event( 'product_view', array( 'sku' => 'WALK-RL-3' ) ) ) );
result( 'abuse_rate_limit_429', ( $rl1['status'] === 200 ) && ( $rl2['status'] === 200 ) && ( $rl3['status'] === 429 ), 'statuses=' . $rl1['status'] . ',' . $rl2['status'] . ',' . $rl3['status'] );

echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.4-browse: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.4-browse.cjs  (after a real setup-exchange).');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.4-browse-tmp.php';
  const hostPath = path.join(__dirname, '..', tmpName);
  fs.writeFileSync(hostPath, LIVE_PHP);

  let out = '';
  try {
    out = runDocker(['exec', cli, 'wp', 'eval-file', `${PLUGIN_PATH_IN_CONTAINER}/${tmpName}`, '--allow-root']);
  } finally {
    fs.unlinkSync(hostPath);
  }

  const results = out.split('\n')
    .filter((l) => l.startsWith('RESULT '))
    .map((l) => {
      const m = l.match(/^RESULT (\S+) (PASS|FAIL) ?(.*)$/);
      return m ? { name: m[1], status: m[2], detail: m[3] } : null;
    })
    .filter(Boolean);

  console.log('\n=== walk-3.4-browse live browse-ingest (real connected engine — sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  if (!out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE marker). Raw tail:');
    console.log(out.split('\n').slice(-12).join('\n'));
    failures += 1;
  }
  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-3.4-browse harness error:', e.message);
  process.exit(1);
});
