/**
 * F3-42 / F3-43 live harness — order-sync data-loss fixes against the REAL
 * Smaily rec-engine (the "Smaily Connect test" SANDBOX tenant).
 *
 * Proves the two fixes from the engine-team 2026-06-19 brief (order #58922),
 * end-to-end through the REAL plugin code, against the live engine:
 *
 *   - F3-42 (status mapping): an order in a CUSTOM WC status (`label-printed`)
 *     maps to a sale (`processing`) and the engine ACCEPTS it — where the old
 *     5-key allowlist silently dropped it.
 *   - F3-43 (deleted product): an order whose product is permanently DELETED
 *     (this wp-env runs WC 10.7, which ZEROES the line's stored ids — the exact
 *     #58922 condition) still serialises a NON-EMPTY items[] (the line keys on
 *     the order-item id, `woo-oi-{id}`), and the engine ACCEPTS the order — where
 *     the order was previously dropped (empty items → terminal skip → marked
 *     "sent" with no POST → silently lost).
 *
 * Why live: the mock validates loosely; only the engine's strict Zod proves it
 * accepts `status:"processing"` for a custom-status order and a `woo-oi-…` sku on
 * a deleted line (CLAUDE.md — catalog/order wire changes are live-walked).
 *
 * Gated on RECENGINE_LIVE=1. Hard-aborts if the connected tenant is production
 * "MiuMjau". Run BEFORE any integration-suite run — EnvScrub wipes the connection.
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

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$builder   = $bootstrap->order_payload_builder();
$flusher   = $bootstrap->order_flusher();
$handler   = new OrderHookHandler( $queue, $builder, $settings );
$tenant    = $settings->tenant_name();

// SAFETY GATE: never send test data to the production tenant.
result( 'sandbox_tenant_not_production', $settings->is_connected() && $tenant !== 'MiuMjau', 'tenant=' . $tenant );
if ( ! $settings->is_connected() || $tenant === 'MiuMjau' ) {
	echo "ABORT_NOT_SANDBOX\n";
	return;
}

// Determinism: drive the handler explicitly + don't let product deletion enqueue
// catalog removals.
global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
remove_all_actions( 'woocommerce_order_status_changed' );
remove_all_actions( 'woocommerce_new_order' );
remove_all_actions( 'before_delete_post' );

// A shipping plugin registers its custom order status; mirror that so
// set_status('label-printed') actually persists. WC validates set_status()
// against the registered statuses and silently falls back to 'pending'
// otherwise — which is exactly the "custom status" the engine never saw.
register_post_status( 'wc-label-printed', array( 'label' => 'Label printed', 'public' => true ) );
add_filter( 'wc_order_statuses', function ( $statuses ) {
	$statuses['wc-label-printed'] = 'Label printed';
	return $statuses;
} );

$created_orders   = array();
$created_products = array();

$make = function ( $email, $status, $sku ) use ( &$created_orders, &$created_products ) {
	$p = new WC_Product_Simple();
	$p->set_sku( $sku );
	$p->set_name( 'F343 ' . $sku );
	$p->set_regular_price( '10.00' );
	$p->set_price( '10.00' );
	$p->set_stock_status( 'instock' );
	$pid                = (int) $p->save();
	$created_products[] = $pid;

	$order = wc_create_order();
	$order->set_billing_email( $email );
	$order->add_product( wc_get_product( $pid ), 2 );
	$order->calculate_totals();
	$order->set_status( $status );
	$oid              = (int) $order->save();
	$created_orders[] = $oid;
	return array( $oid, $pid );
};

// === F3-42: a CUSTOM status maps to a sale and the engine accepts it =========
OrderHookHandler::reset_seen();
list( $oid_a, $pid_a ) = $make( 'f343-custom@example.test', 'label-printed', 'LIVE-F343-CUSTOM-' . wp_generate_uuid4() );

$order_a = wc_get_order( $oid_a );
$obj_a   = $builder->build( $order_a, wp_generate_uuid4() );
result( 'custom_status_maps_to_processing', ( $obj_a['status'] ?? '' ) === 'processing', 'wc_status=' . $order_a->get_status() . ' engine_status=' . ( $obj_a['status'] ?? '(none)' ) );

$handler->on_order_status_changed( $oid_a, 'pending', 'label-printed' );
$enq_a = false;
foreach ( $queue->pending( 200, array( OrderFlusher::EVENT_ORDER_UPSERT ) ) as $r ) {
	if ( (string) $r['entity_id'] === (string) $oid_a ) {
		$enq_a = true;
	}
}
result( 'custom_status_enqueued', $enq_a, 'a custom-status transition is a sale → enqueued' );
$stats_a = $flusher->flush();
result( 'custom_status_order_accepted_by_engine', $stats_a['sent'] >= 1 && $stats_a['failed'] === 0, json_encode( $stats_a ) );

// === F3-43: a DELETED-product order keeps a non-empty items[] + is accepted ===
OrderHookHandler::reset_seen();
list( $oid_b, $pid_b ) = $make( 'f343-deleted@example.test', 'completed', 'LIVE-F343-DEL-' . wp_generate_uuid4() );

// Enqueue while the product still exists (mirrors a real order placed normally).
$handler->on_order_status_changed( $oid_b, 'pending', 'completed' );

// Permanently delete the product — WC 10.7 zeroes the line's stored ids (#58922).
wp_delete_post( $pid_b, true );

// Build fresh AFTER deletion (what the flusher does at send time).
$order_b   = wc_get_order( $oid_b );
$obj_b     = $builder->build( $order_b, wp_generate_uuid4() );
$items_b   = isset( $obj_b['items'] ) && is_array( $obj_b['items'] ) ? $obj_b['items'] : array();
$first_sku = isset( $items_b[0]['sku'] ) ? (string) $items_b[0]['sku'] : '';
result( 'deleted_product_items_not_empty', count( $items_b ) >= 1 && $first_sku !== '', 'items=' . wp_json_encode( $items_b ) );
result( 'deleted_product_line_keyed_synthetically', strpos( $first_sku, 'woo-' ) === 0, 'sku=' . $first_sku );

$stats_b = $flusher->flush();
result( 'deleted_product_order_accepted_by_engine', $stats_b['sent'] >= 1 && $stats_b['failed'] === 0, json_encode( $stats_b ) );

// === cleanup =================================================================
// Orders via the WC CRUD delete — wp_delete_post() is a silent NO-OP for HPOS
// orders (wc_orders table), and the residue poisons the integration suite's
// order-count tests (the 2026-06-19 run's `wc-label-printed` order did exactly
// that: invisible to registered-status sweeps, counted by the backfill SQL).
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
    console.log('walk-f3-43-orders: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-f3-43-orders.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-f3-43-orders-tmp.php';
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

  console.log('\n=== walk-f3-43-orders: custom-status + deleted-product orders against the real engine (sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  if (out.includes('ABORT_NOT_SANDBOX')) {
    console.log('  ✗ ABORTED — not connected to a sandbox tenant (refusing to send to production).');
    failures += 1;
  }
  if (!out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE marker). Raw tail:');
    console.log(out.split('\n').slice(-12).join('\n'));
    failures += 1;
  }
  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-f3-43-orders harness error:', e.message);
  process.exit(1);
});
