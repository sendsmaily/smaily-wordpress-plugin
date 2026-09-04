/**
 * Sub-PR 3.3-orders.4 live harness — orders ingest against the REAL Smaily
 * rec engine (MiuMjau tenant), the contract-validation counterpart to
 * RecEngineOrdersTest.
 *
 * Two format risks this walk exists to catch (the mock validates loosely; the
 * engine's Zod is strict — LESSONS §2.4):
 *   - the WC→enum status mapping (does the engine accept exactly
 *     completed/processing/cancelled/refunded, and reject a raw WC status?), and
 *   - ordered_at datetime (IsoDate Z-form must pass, like first_seen_at did
 *     after the customers 3.3.4 fix).
 *
 * Backend-driven: exercises OrderHookHandler → IngestQueue → OrderFlusher →
 * Client against the live engine, plus direct live_post for the per-item D6
 * and status-enum probes.
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
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

function live_post( $url, $auth, $body ) {
	$r = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => $auth, 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $r ) ) {
		return array( 'err' => $r->get_error_message() );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	return is_array( $decoded ) ? $decoded : array();
}

$created_orders   = array();
$created_products = array();

function live_make_order( $email, $status, $sku, &$created_orders, &$created_products ) {
	$existing = wc_get_product_id_by_sku( $sku );
	if ( $existing ) {
		wp_delete_post( $existing, true );
	}
	$p = new WC_Product_Simple();
	$p->set_sku( $sku );
	$p->set_name( 'Walk Order ' . $sku );
	$p->set_regular_price( '10.00' );
	$p->set_price( '10.00' );
	$p->set_stock_status( 'instock' );
	$pid               = (int) $p->save();
	$created_products[] = $pid;

	$order = wc_create_order();
	$order->set_billing_email( $email );
	$order->add_product( wc_get_product( $pid ), 1 );
	$order->calculate_totals();
	$order->set_status( $status );
	$oid             = (int) $order->save();
	$created_orders[] = $oid;
	return $oid;
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$builder   = $bootstrap->order_payload_builder();
$flusher   = $bootstrap->order_flusher();
$handler   = new OrderHookHandler( $queue, $builder, $settings );

// Determinism: drive the handler explicitly. The Bootstrap order hook is
// already registered in wp eval-file, so set_status would double-enqueue.
global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
remove_all_actions( 'woocommerce_order_status_changed' );
remove_all_actions( 'woocommerce_new_order' );

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

$ingest_url = $settings->endpoints()['ingest_orders'] ?? ( rtrim( $settings->base_url(), '/' ) . '/api/v1/ingest/orders' );
$auth       = array( 'Authorization' => 'Bearer ' . $settings->api_key(), 'Content-Type' => 'application/json' );

// --- 1. upsert end-to-end (hook → queue → flusher → engine) --------------
OrderHookHandler::reset_seen();
$oid1 = live_make_order( 'walk-order-1@example.test', 'completed', 'WALK-ORD-1', $created_orders, $created_products );
$handler->on_order_status_changed( $oid1, 'pending', 'completed' );
$uuid1 = '';
foreach ( $queue->pending( 200, array( OrderFlusher::EVENT_ORDER_UPSERT ) ) as $r ) {
	if ( (string) $r['entity_id'] === (string) $oid1 ) {
		$uuid1 = (string) $r['event_uuid'];
	}
}
result( 'upsert_enqueued', $uuid1 !== '', 'event_uuid=' . $uuid1 );
$stats = $flusher->flush();
result( 'upsert_flushed_to_engine', $stats['sent'] >= 1 && $stats['failed'] === 0, json_encode( $stats ) );

// --- 2. ordered_at datetime + mapped status accepted (format check) ------
// If ordered_at were +00:00 (not Z) or status were a raw WC slug, the engine
// would have errored this order in step 1. A clean processed:1 confirms both.
$client  = $bootstrap->rec_client();
$obj1    = $builder->build( wc_get_order( $oid1 ), wp_generate_uuid4() );
$has_z   = isset( $obj1['ordered_at'] ) && substr( (string) $obj1['ordered_at'], -1 ) === 'Z';
$is_enum = isset( $obj1['status'] ) && in_array( $obj1['status'], array( 'completed', 'processing', 'cancelled', 'refunded' ), true );
result( 'ordered_at_is_z_form', $has_z, 'ordered_at=' . ( $obj1['ordered_at'] ?? '(none)' ) );
result( 'status_is_mapped_enum', $is_enum, 'status=' . ( $obj1['status'] ?? '(none)' ) );

// --- 3. per-item event_id dedup on resend --------------------------------
try {
	$first  = $client->ingest_orders( array( $obj1 ) );
	$second = $client->ingest_orders( array( $obj1 ) );
	result( 'resend_first_processed', (int) ( $first['processed'] ?? 0 ) >= 1, json_encode( $first ) );
	result( 'resend_second_deduplicated', (int) ( $second['deduplicated'] ?? 0 ) >= 1, json_encode( $second ) );
} catch ( \Throwable $e ) {
	result( 'resend_first_processed', false, 'EXC ' . $e->getMessage() );
	result( 'resend_second_deduplicated', false, 'EXC ' . $e->getMessage() );
}

// --- 4. status-mapping live: each engine enum accepted --------------------
$enum_ok = true;
$enum_detail = array();
foreach ( array( 'completed', 'processing', 'cancelled', 'refunded' ) as $i => $st ) {
	$o = $obj1;
	$o['external_order_id'] = 'WALK-ENUM-' . $st;
	$o['status']            = $st;
	$o['event_id']          = wp_generate_uuid4();
	$resp                   = live_post( $ingest_url, $auth, array( 'orders' => array( $o ) ) );
	$ok                     = (int) ( $resp['processed'] ?? 0 ) === 1 && empty( $resp['errors'] );
	$enum_ok                = $enum_ok && $ok;
	$enum_detail[ $st ]     = $ok ? 'ok' : json_encode( $resp );
}
result( 'all_engine_enum_statuses_accepted', $enum_ok, json_encode( $enum_detail ) );

// --- 5. D6 PARTIAL SUCCESS (the core) + raw WC status rejected ------------
// A valid order + one with a raw WC status ('shipped', not in the enum) must
// return 200 processed:1, errors:[{index:1, field:status}]. This proves both
// the D6 split AND that the WC→enum mapping is necessary (a raw status fails).
$ok_order  = $obj1;
$ok_order['external_order_id'] = 'WALK-D6-OK';
$ok_order['event_id']          = wp_generate_uuid4();
$bad_order = $obj1;
$bad_order['external_order_id'] = 'WALK-D6-BAD';
$bad_order['status']            = 'shipped';
$bad_order['event_id']          = wp_generate_uuid4();
$d6 = live_post( $ingest_url, $auth, array( 'orders' => array( $ok_order, $bad_order ) ) );
$d6_processed = (int) ( $d6['processed'] ?? -1 );
$d6_errors    = isset( $d6['errors'] ) && is_array( $d6['errors'] ) ? $d6['errors'] : array();
$d6_index     = ( count( $d6_errors ) === 1 && isset( $d6_errors[0]['index'] ) ) ? (int) $d6_errors[0]['index'] : -1;
$d6_field     = ( count( $d6_errors ) === 1 && isset( $d6_errors[0]['field'] ) ) ? (string) $d6_errors[0]['field'] : '';
result(
	'd6_partial_success_real_engine',
	$d6_processed === 1 && $d6_index === 1 && $d6_field === 'status',
	json_encode( array( 'processed' => $d6['processed'] ?? null, 'errors' => $d6_errors ) )
);
result(
	'd6_invariant_holds',
	$d6_processed + (int) ( $d6['deduplicated'] ?? 0 ) + count( $d6_errors ) === 2,
	sprintf( 'processed=%d deduplicated=%d errors=%d', $d6_processed, (int) ( $d6['deduplicated'] ?? 0 ), count( $d6_errors ) )
);

// --- 6. wrapper-key re-confirm (orders, not items) -----------------------
$w = $obj1;
$w['external_order_id'] = 'WALK-WRAP';
$w['event_id']          = wp_generate_uuid4();
$wrap = live_post( $ingest_url, $auth, array( 'orders' => array( $w ) ) );
result( 'wrapper_orders_accepted', ! empty( $wrap['ok'] ) && (int) ( $wrap['processed'] ?? 0 ) >= 1, json_encode( $wrap ) );

// --- 7. batch of valid orders through the flusher ------------------------
OrderHookHandler::reset_seen();
for ( $i = 1; $i <= 4; $i++ ) {
	$oid = live_make_order( sprintf( 'walk-batch-%d@example.test', $i ), 'completed', sprintf( 'WALK-BATCH-%d', $i ), $created_orders, $created_products );
	$handler->on_order_status_changed( $oid, 'pending', 'completed' );
}
$bstats = $flusher->flush();
result( 'batch_flushed_all_sent', $bstats['sent'] === 4 && $bstats['failed'] === 0, json_encode( $bstats ) );

// --- cleanup -------------------------------------------------------------
// Orders via the WC CRUD delete — wp_delete_post() is a silent NO-OP for HPOS
// orders (wc_orders table); the residue poisons the suite's order-count tests.
foreach ( array_unique( $created_orders ) as $id ) {
	$o = wc_get_order( $id );
	if ( $o instanceof \WC_Order ) {
		$o->delete( true );
	}
}
foreach ( array_unique( $created_products ) as $id ) {
	wp_delete_post( $id, true );
}
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.3-orders: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.3-orders.cjs  (after a real setup-exchange).');
    process.exit(0);
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.3-orders-tmp.php';
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

  console.log('\n=== walk-3.3-orders live orders-ingest (real MiuMjau engine) ===');
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
  console.error('walk-3.3-orders harness error:', e.message);
  process.exit(1);
});
