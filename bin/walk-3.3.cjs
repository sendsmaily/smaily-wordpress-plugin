/**
 * Sub-PR 3.3.4 live harness — customers ingest against the REAL Smaily
 * rec engine (MiuMjau tenant), the contract-validation counterpart to the
 * mock-server integration suite (RecEngineCustomersTest).
 *
 * Customers ingest is the D6 reference: per-item errors[] partial success.
 * The core check here is the FIRST time per-item errors[] is exercised
 * against the real deployed engine (not the mock) — a {valid, invalid-email}
 * batch must return 200 {processed:1, errors:[{index:1, field:email}]}, the
 * exact shape CustomerFlusher splits on.
 *
 * Backend-driven (no new admin UI): exercises the real CustomerHookHandler →
 * IngestQueue → CustomerFlusher → Client path against the live engine.
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected tenant in the wp-env DB
 * (run setup-exchange first). MUST run before any integration-suite run —
 * EnvScrub wipes the smly_rec_* options the connection lives in.
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
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

// Direct POST (bypasses the plugin Client) so a deliberately-invalid customer
// can be sent — the builder never produces a malformed email, so the D6
// per-item error path is only reachable by hand-crafting the body.
function live_post( $url, $auth, $body ) {
	$r = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => $auth, 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $r ) ) {
		return array( 'err' => $r->get_error_message() );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	return is_array( $decoded ) ? $decoded : array();
}

function live_make_user( $email ) {
	$existing = get_user_by( 'email', $email );
	if ( $existing instanceof WP_User ) {
		wp_delete_user( $existing->ID );
	}
	return (int) wp_insert_user( array(
		'user_login' => 'live_' . substr( md5( $email ), 0, 12 ),
		'user_email' => $email,
		'user_pass'  => wp_generate_password( 16 ),
		'first_name' => 'Live',
		'last_name'  => 'Customer',
		'role'       => 'customer',
	) );
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$builder   = $bootstrap->customer_payload_builder();
$flusher   = $bootstrap->customer_flusher();
$handler   = new CustomerHookHandler( $queue, $settings );
$created   = array();

// Determinism: drive the handler explicitly. The Bootstrap user hooks are
// already registered in wp eval-file, so creating users would double-enqueue.
// Truncate the queue and silence the registered hooks first.
global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
remove_all_actions( 'user_register' );
remove_all_actions( 'profile_update' );
remove_all_actions( 'woocommerce_created_customer' );
remove_all_actions( 'woocommerce_save_account_details' );

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

$ingest_url = $settings->endpoints()['ingest_customers'] ?? ( rtrim( $settings->base_url(), '/' ) . '/api/v1/ingest/customers' );
$auth       = array( 'Authorization' => 'Bearer ' . $settings->api_key(), 'Content-Type' => 'application/json' );

// --- 1. upsert end-to-end (hook → queue → flusher → engine) --------------
CustomerHookHandler::reset_seen();
$uid1      = live_make_user( 'live-cust-1@example.test' );
$created[] = $uid1;
$handler->on_user_register( $uid1 );
$uuid1 = '';
foreach ( $queue->pending( 200, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ) as $r ) {
	if ( (string) $r['entity_id'] === (string) $uid1 ) {
		$uuid1 = (string) $r['event_uuid'];
	}
}
result( 'upsert_enqueued', $uuid1 !== '', 'event_uuid=' . $uuid1 );
$stats = $flusher->flush();
result( 'upsert_flushed_to_engine', $stats['sent'] >= 1 && $stats['failed'] === 0, json_encode( $stats ) );

// --- 2. per-item event_id dedup on resend --------------------------------
// W4 customers honours Layer-2 event_id dedup: a resent event_id returns
// deduplicated (not re-processed).
$client = $bootstrap->rec_client();
$object = $builder->build( get_userdata( $uid1 ), wp_generate_uuid4() );
try {
	$first  = $client->ingest_customers( array( $object ) );
	$second = $client->ingest_customers( array( $object ) );
	result( 'resend_first_processed', (int) ( $first['processed'] ?? 0 ) >= 1, json_encode( $first ) );
	result( 'resend_second_deduplicated', (int) ( $second['deduplicated'] ?? 0 ) >= 1, json_encode( $second ) );
} catch ( \Throwable $e ) {
	result( 'resend_first_processed', false, 'EXC ' . $e->getMessage() );
	result( 'resend_second_deduplicated', false, 'EXC ' . $e->getMessage() );
}

// --- 3. D6 PARTIAL SUCCESS (the core) ------------------------------------
// First time per-item errors[] is exercised against the real engine. A batch
// with one valid + one invalid-email customer must return 200 with
// processed:1 and errors:[{index:1, field:email}] — the exact shape the
// CustomerFlusher splits on. Sent by hand (the builder can't make a bad email).
$ok_cust  = array( 'email' => 'live-d6-ok@example.test', 'event_id' => wp_generate_uuid4(), 'external_id' => 'live-d6-ok' );
$bad_cust = array( 'email' => 'not-an-email', 'event_id' => wp_generate_uuid4(), 'external_id' => 'live-d6-bad' );
$d6 = live_post( $ingest_url, $auth, array( 'customers' => array( $ok_cust, $bad_cust ) ) );
$d6_processed = (int) ( $d6['processed'] ?? -1 );
$d6_errors    = isset( $d6['errors'] ) && is_array( $d6['errors'] ) ? $d6['errors'] : array();
$d6_err_index = ( count( $d6_errors ) === 1 && isset( $d6_errors[0]['index'] ) ) ? (int) $d6_errors[0]['index'] : -1;
$d6_err_field = ( count( $d6_errors ) === 1 && isset( $d6_errors[0]['field'] ) ) ? (string) $d6_errors[0]['field'] : '';
result(
	'd6_partial_success_real_engine',
	$d6_processed === 1 && $d6_err_index === 1 && $d6_err_field === 'email',
	json_encode( array( 'processed' => $d6['processed'] ?? null, 'deduplicated' => $d6['deduplicated'] ?? null, 'errors' => $d6_errors ) )
);
// Invariant the flusher relies on: processed + deduplicated + errors == total.
$d6_dedup = (int) ( $d6['deduplicated'] ?? 0 );
result(
	'd6_invariant_holds',
	$d6_processed + $d6_dedup + count( $d6_errors ) === 2,
	sprintf( 'processed=%d deduplicated=%d errors=%d total=2', $d6_processed, $d6_dedup, count( $d6_errors ) )
);

// --- 4. batch of valid customers through the flusher ---------------------
CustomerHookHandler::reset_seen();
$batch_uids = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$uid          = live_make_user( sprintf( 'live-batch-%d@example.test', $i ) );
	$created[]    = $uid;
	$batch_uids[] = $uid;
	$handler->on_user_register( $uid );
}
$bstats = $flusher->flush();
result( 'batch_flushed_all_sent', $bstats['sent'] === 5 && $bstats['failed'] === 0, json_encode( $bstats ) );

// --- 5. wrapper-key re-confirm (customers, not items) --------------------
$wrap = live_post( $ingest_url, $auth, array( 'customers' => array( array( 'email' => 'live-wrap@example.test', 'event_id' => wp_generate_uuid4(), 'external_id' => 'live-wrap' ) ) ) );
result( 'wrapper_customers_accepted', ! empty( $wrap['ok'] ) && (int) ( $wrap['processed'] ?? 0 ) >= 1, json_encode( $wrap ) );

// --- 6. builder omit-vs-null: optional empties are OMITTED, not null ------
// A sparse user (no last name removed, no WC billing) — the wire object must
// drop empty optionals (absent != empty, F2-10), never send explicit null.
$sparse_uid = live_make_user( 'live-sparse@example.test' );
$created[]  = $sparse_uid;
$sparse_u   = get_userdata( $sparse_uid );
$sparse_u->last_name = '';
$sparse_obj = $builder->build( $sparse_u, 'sparse-uuid' );
$has_email   = isset( $sparse_obj['email'] );
$omits_empty = ! array_key_exists( 'last_name', $sparse_obj ) && ! array_key_exists( 'phone', $sparse_obj );
$no_nulls    = true;
foreach ( $sparse_obj as $k => $v ) {
	if ( $v === null ) {
		$no_nulls = false;
	}
}
result(
	'builder_omits_empty_optionals',
	$has_email && $omits_empty && $no_nulls,
	'keys=' . json_encode( array_keys( $sparse_obj ) )
);

// --- cleanup test users --------------------------------------------------
foreach ( array_unique( $created ) as $id ) {
	wp_delete_user( $id );
}
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.3: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.3.cjs  (after a real setup-exchange).');
    process.exit(0);
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.3-tmp.php';
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

  console.log('\n=== walk-3.3 live customers-ingest (real MiuMjau engine) ===');
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
  console.error('walk-3.3 harness error:', e.message);
  process.exit(1);
});
