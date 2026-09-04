/**
 * PRO-1224 + PRO-1230 live harness — canonical `woo-<id>` product keys and the
 * §3b product-level `catalog/remove` against the REAL Smaily rec-engine (the
 * "Smaily Connect test" SANDBOX tenant).
 *
 * Proves, end-to-end through the REAL plugin code, what the mock cannot
 * (strict Zod + the engine's fail-loud `woo-` namespace validation, PRO-1223):
 *
 *   PRO-1224 (catalog):
 *   - a simple product WITH a merchant WC SKU set still keys `sku=woo-<id>`;
 *     the merchant SKU appears NOWHERE in the wire payload (dropped entirely,
 *     engine answer PRO-1225);
 *   - a variable product's rows key `woo-<variation_id>` and every row carries
 *     `tags.product_id` = the RAW un-prefixed canonical PARENT id;
 *   - the live engine ACCEPTS the batch (processed, no errors[]).
 *
 *   PRO-1224 (orders):
 *   - an order's items[] key the SAME `woo-<id>` strings (catalog↔order join)
 *     and the live engine ACCEPTS the order.
 *
 *   PRO-1230 (§3b):
 *   - TRASH does NOT fire §3b — the wp_trash_post callback enqueues only the
 *     F3-40 in_stock=false soft rows (catalog.delete), zero catalog.remove;
 *   - a HARD-deleted PARENT product enqueues exactly ONE catalog.remove row
 *     whose payload product_id is the RAW parent id (= tags.product_id), and
 *     the live `POST /api/v1/ingest/catalog/remove` tombstones the product's
 *     rows (outcome=removed, rows_tombstoned >= the variation count) — a REAL
 *     removal, not a not_found, because this walk synced those rows minutes
 *     earlier with the very tags.product_id §3b matches on.
 *
 * Gated on RECENGINE_LIVE=1. Hard-aborts if the connected tenant is the
 * production "MiuMjau" (2026-06-12 incident). Run BEFORE any integration-suite
 * run — EnvScrub wipes the connection (snapshot/restore per LESSONS §2.17).
 *
 * Hygiene: every WC order is deleted via wc_get_order()->delete(true) (HPOS —
 * wp_delete_post is a silent no-op, LESSONS §2.16); every product is
 * hard-deleted (which IS the §3b test for the parents). Engine-side residue
 * (named in the output): the walk's tombstoned catalog rows + one order —
 * the engine has no order removal.
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
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;
use Smaily\Connect\Integrations\WooCommerce\OrderHookHandler;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$detector  = $bootstrap->multilingual_detector();
$builder   = $bootstrap->catalog_payload_builder();
$tenant    = $settings->tenant_name();

// SAFETY GATE: never send test data to the production tenant.
result( 'sandbox_tenant_not_production', $settings->is_connected() && $tenant !== 'MiuMjau', 'tenant=' . $tenant );
if ( ! $settings->is_connected() || $tenant === 'MiuMjau' ) {
	echo "ABORT_NOT_SANDBOX\n";
	return;
}

// Determinism: drive the handlers explicitly; the real bindings would
// double-enqueue every CRUD save/delete below.
global $wpdb;
$queue_table = $wpdb->prefix . 'smly_rec_event_queue';
$wpdb->query( "TRUNCATE TABLE {$queue_table}" ); // phpcs:ignore
foreach ( array(
	'save_post_product',
	'woocommerce_product_set_stock_status',
	'woocommerce_variation_set_stock_status',
	'before_delete_post',
	'wp_trash_post',
	'untrashed_post',
	'woocommerce_order_status_changed',
	'woocommerce_new_order',
) as $hook ) {
	remove_all_actions( $hook );
}

$catalog_handler = new CatalogHookHandler( $queue, $builder, $settings, $detector );
$order_handler   = new OrderHookHandler( $queue, $bootstrap->order_payload_builder(), $settings );

$MERCH_SKU_SIMPLE = 'ZZMERCHSKU-SIMPLE-1224';
$MERCH_SKU_VAR    = 'ZZMERCHSKU-VAR-1224';

// Category (category_path is required non-empty on the wire).
$cat = term_exists( 'p1224-cat', 'product_cat' );
if ( ! $cat ) {
	$cat = wp_insert_term( 'P1224 Cat', 'product_cat', array( 'slug' => 'p1224-cat' ) );
}
$cat_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );

// --- Simple product WITH a merchant WC SKU set (the PRO-1224 crux) ---------
$p = new WC_Product_Simple();
$p->set_sku( $MERCH_SKU_SIMPLE );
$p->set_name( 'LIVE P1224 Simple (walk)' );
$p->set_regular_price( '12.34' );
$p->set_price( '12.34' );
$p->set_stock_status( 'instock' );
$pid = (int) $p->save();
wp_set_object_terms( $pid, array( $cat_id ), 'product_cat' );

// --- Variable product, 2 variations (one with a merchant SKU too) ----------
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$vp = new WC_Product_Variable();
$vp->set_name( 'LIVE P1224 Variable (walk)' );
$vp->set_attributes( array( $attr ) );
$vpid = (int) $vp->save();
wp_set_object_terms( $vpid, array( $cat_id ), 'product_cat' );

$vids = array();
foreach ( array( 'S', 'M' ) as $i => $size ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $vpid );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( '20.00' );
	$v->set_price( '20.00' );
	$v->set_status( 'publish' );
	$v->set_stock_status( 'instock' );
	if ( 0 === $i ) {
		$v->set_sku( $MERCH_SKU_VAR );
	}
	$vids[] = (int) $v->save();
}

// === 1. PRO-1224 builder wire shape =========================================
$obj = $builder->build( wc_get_product( $pid ), wp_generate_uuid4() );
result( 'simple_sku_is_woo_id', ( $obj['sku'] ?? '' ) === 'woo-' . $pid, 'sku=' . ( $obj['sku'] ?? '(none)' ) );
result( 'simple_tags_product_id_raw', ( $obj['tags']['product_id'] ?? '' ) === (string) $pid, 'tags.product_id=' . ( $obj['tags']['product_id'] ?? '(none)' ) );
result( 'simple_external_id_platform_id', ( $obj['external_id'] ?? '' ) === (string) $pid, 'external_id=' . ( $obj['external_id'] ?? '(none)' ) );
result( 'simple_merchant_sku_nowhere', strpos( (string) wp_json_encode( $obj ), $MERCH_SKU_SIMPLE ) === false, 'payload=' . wp_json_encode( $obj ) );

$units = $builder->expand( wc_get_product( $vpid ) );
result( 'variable_expands_to_variations', count( $units ) === 2, 'units=' . count( $units ) );
$var_ok = true;
$var_detail = array();
foreach ( $units as $unit ) {
	$vo = $builder->build( $unit, wp_generate_uuid4() );
	$var_detail[] = ( $vo['sku'] ?? '?' ) . '/pgid=' . ( $vo['tags']['product_id'] ?? '?' );
	if ( ( $vo['sku'] ?? '' ) !== 'woo-' . $unit->get_id()
		|| ( $vo['tags']['product_id'] ?? '' ) !== (string) $vpid
		|| strpos( (string) wp_json_encode( $vo ), $MERCH_SKU_VAR ) !== false ) {
		$var_ok = false;
	}
}
result( 'variation_rows_key_variation_id_group_parent', $var_ok, implode( ' ', $var_detail ) );

// === 2. PRO-1224 catalog: engine accepts the woo-<id> batch ================
CatalogHookHandler::reset_seen();
$catalog_handler->on_save_product( $pid );
$catalog_handler->on_save_product( $vpid );
$pending_upserts = count( $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_UPSERT ) ) );
result( 'catalog_enqueued_three_units', $pending_upserts === 3, 'pending=' . $pending_upserts );

$stats = $bootstrap->ingest_flusher()->flush();
result( 'engine_accepts_woo_id_catalog', ( $stats['sent'] ?? 0 ) === 3 && ( $stats['failed'] ?? 1 ) === 0 && ( $stats['skipped'] ?? 1 ) === 0, wp_json_encode( $stats ) );

// F3-44 evidence: the exact JSON POSTed + the engine's reply, per row.
$rows = $wpdb->get_results( "SELECT event_type, entity_id, status, sent_payload, last_response FROM {$queue_table} ORDER BY id", ARRAY_A ); // phpcs:ignore
$merch_leak = false;
foreach ( $rows as $r ) {
	if ( strpos( (string) $r['sent_payload'], 'ZZMERCHSKU' ) !== false ) {
		$merch_leak = true;
	}
	echo 'EVIDENCE catalog ' . $r['event_type'] . ' entity=' . $r['entity_id'] . ' status=' . $r['status']
		. ' sent_sku=' . ( preg_match( '/"sku":"([^"]+)"/', (string) $r['sent_payload'], $m ) ? $m[1] : '?' )
		. ' tags=' . ( preg_match( '/"tags":({[^}]*})/', (string) $r['sent_payload'], $m2 ) ? $m2[1] : '?' )
		. ' response=' . $r['last_response'] . "\n";
}
result( 'no_merchant_sku_on_wire', ! $merch_leak, 'sent_payload scan for ZZMERCHSKU' );

// === 3. PRO-1224 orders: items[] join on the same woo-<id> =================
OrderHookHandler::reset_seen();
$order = wc_create_order();
$order->set_billing_email( 'pro1224-walk@example.test' );
$order->add_product( wc_get_product( $pid ), 1 );
$order->add_product( wc_get_product( $vids[0] ), 2 );
$order->calculate_totals();
$order->set_status( 'completed' );
$oid = (int) $order->save();

$oobj  = $bootstrap->order_payload_builder()->build( wc_get_order( $oid ), wp_generate_uuid4() );
$oskus = array_map( static fn( $i ) => (string) ( $i['sku'] ?? '' ), $oobj['items'] ?? array() );
sort( $oskus );
$expected = array( 'woo-' . $pid, 'woo-' . $vids[0] );
sort( $expected );
result( 'order_items_key_woo_id', $oskus === $expected, 'items skus=' . wp_json_encode( $oskus ) );
result( 'order_merchant_sku_nowhere', strpos( (string) wp_json_encode( $oobj ), 'ZZMERCHSKU' ) === false, 'order payload scan' );

$order_handler->on_order_status_changed( $oid, 'pending', 'completed' );
$ostats = $bootstrap->order_flusher()->flush();
result( 'engine_accepts_woo_id_order', ( $ostats['sent'] ?? 0 ) >= 1 && ( $ostats['failed'] ?? 1 ) === 0, wp_json_encode( $ostats ) );
$orow = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_response FROM {$queue_table} WHERE event_type = %s AND entity_id = %s ORDER BY id DESC", OrderFlusher::EVENT_ORDER_UPSERT, (string) $oid ), ARRAY_A ); // phpcs:ignore
echo 'EVIDENCE order entity=' . $oid . ' status=' . ( $orow['status'] ?? '?' ) . ' response=' . ( $orow['last_response'] ?? '?' ) . "\n";

// === 4. PRO-1230: TRASH does NOT fire §3b (soft in_stock=false path) =======
CatalogHookHandler::reset_seen();
$catalog_handler->on_delete_product( $pid ); // the wp_trash_post binding
$soft_rows   = count( $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_DELETE ) ) );
$remove_rows = count( $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) ) );
result( 'trash_enqueues_soft_delete_not_remove', $soft_rows === 1 && $remove_rows === 0, 'catalog.delete=' . $soft_rows . ' catalog.remove=' . $remove_rows );
$tstats = $bootstrap->ingest_flusher()->flush();
result( 'engine_accepts_soft_removal_upsert', ( $tstats['sent'] ?? 0 ) === 1 && ( $tstats['failed'] ?? 1 ) === 0, wp_json_encode( $tstats ) );

// === 5. PRO-1230 §3b: hard-delete the VARIABLE parent → catalog/remove =====
CatalogHookHandler::reset_seen();
$catalog_handler->on_hard_delete_product( $vpid ); // the before_delete_post binding, product still loadable
$remove_pending = $queue->pending( 200, array( CatalogHookHandler::EVENT_CATALOG_REMOVE ) );
$rp             = ( count( $remove_pending ) === 1 ) ? json_decode( (string) $remove_pending[0]['payload'], true ) : array();
result( 'hard_delete_enqueues_one_remove_row', count( $remove_pending ) === 1 && ( $rp['product_id'] ?? '' ) === (string) $vpid, 'rows=' . count( $remove_pending ) . ' product_id=' . ( $rp['product_id'] ?? '(none)' ) );

// Actually delete (real WC teardown; hooks already removed → no double-enqueue).
// WC CRUD delete, not wp_delete_post: the CRUD path clears WC's in-request
// caches/lookup rows, so the leak check below sees DB truth.
foreach ( $vids as $vid ) {
	$vobj = wc_get_product( $vid );
	if ( $vobj ) {
		$vobj->delete( true );
	}
}
$vpobj = wc_get_product( $vpid );
if ( $vpobj ) {
	$vpobj->delete( true );
}

$rstats = $bootstrap->catalog_remove_flusher()->flush();
$rrow   = $wpdb->get_row( $wpdb->prepare( "SELECT status, sent_payload, last_response FROM {$queue_table} WHERE event_type = %s AND entity_id = %s", CatalogHookHandler::EVENT_CATALOG_REMOVE, (string) $vpid ), ARRAY_A ); // phpcs:ignore
$rresp  = json_decode( (string) ( $rrow['last_response'] ?? '' ), true ) ?: array();
echo 'EVIDENCE catalog.remove entity=' . $vpid . ' status=' . ( $rrow['status'] ?? '?' ) . ' sent=' . ( $rrow['sent_payload'] ?? '?' ) . ' response=' . ( $rrow['last_response'] ?? '?' ) . "\n";
result( 'engine_removes_variable_product', ( $rstats['sent'] ?? 0 ) === 1 && ( $rstats['failed'] ?? 1 ) === 0 && ( $rresp['outcome'] ?? '' ) === 'removed', wp_json_encode( $rstats ) . ' outcome=' . ( $rresp['outcome'] ?? '?' ) );
result( 'remove_tombstones_both_variation_rows', ( $rresp['rows_tombstoned'] ?? 0 ) >= 2, 'rows_tombstoned=' . ( $rresp['rows_tombstoned'] ?? '?' ) );

// === 6. PRO-1230 §3b: hard-delete the SIMPLE product (also cleanup) ========
CatalogHookHandler::reset_seen();
$catalog_handler->on_hard_delete_product( $pid );
$pobj = wc_get_product( $pid );
if ( $pobj ) {
	$pobj->delete( true );
}
$sstats = $bootstrap->catalog_remove_flusher()->flush();
$srow   = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_response FROM {$queue_table} WHERE event_type = %s AND entity_id = %s", CatalogHookHandler::EVENT_CATALOG_REMOVE, (string) $pid ), ARRAY_A ); // phpcs:ignore
$sresp  = json_decode( (string) ( $srow['last_response'] ?? '' ), true ) ?: array();
echo 'EVIDENCE catalog.remove entity=' . $pid . ' status=' . ( $srow['status'] ?? '?' ) . ' response=' . ( $srow['last_response'] ?? '?' ) . "\n";
result( 'engine_removes_simple_product', ( $sstats['sent'] ?? 0 ) === 1 && ( $sstats['failed'] ?? 1 ) === 0 && ( $sresp['outcome'] ?? '' ) === 'removed', wp_json_encode( $sstats ) . ' outcome=' . ( $sresp['outcome'] ?? '?' ) );

// === cleanup =================================================================
// Order via WC CRUD delete — wp_delete_post is a silent no-op under HPOS
// (LESSONS §2.16) and residue poisons the integration suite's count asserts.
$o = wc_get_order( $oid );
if ( $o instanceof \WC_Order ) {
	$o->delete( true );
}
wp_delete_term( $cat_id, 'product_cat' );

// Leak check on DB truth (get_post) — wc_get_product can answer from the
// in-request cache after a hard delete and report a phantom leak.
$leak = array();
foreach ( array( $pid, $vpid, $vids[0], $vids[1] ) as $id ) {
	clean_post_cache( $id );
	if ( get_post( $id ) ) {
		$leak[] = 'product:' . $id;
	}
}
if ( wc_get_order( $oid ) ) {
	$leak[] = 'order:' . $oid;
}
result( 'wc_rows_cleaned_up', $leak === array(), $leak === array() ? 'no WC residue' : implode( ',', $leak ) );

echo 'RESIDUE engine-side (sandbox tenant "' . $tenant . '"): tombstoned catalog rows woo-' . $pid . ', woo-' . $vids[0] . ', woo-' . $vids[1] . ' (in_stock=false via §3b); one order entity ' . $oid . ' (email pro1224-walk@example.test) — the engine has no order removal.' . "\n";
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-pro1224-1230: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-pro1224-1230.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-pro1224-1230-tmp.php';
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

  console.log('\n=== walk-pro1224-1230: woo-<id> keys + §3b catalog/remove against the real engine (sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  for (const l of out.split('\n')) {
    if (l.startsWith('EVIDENCE ') || l.startsWith('RESIDUE ')) console.log(`  ${l}`);
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
  console.error('walk-pro1224-1230 harness error:', e.message);
  process.exit(1);
});
