/**
 * Sub-PR 3.8.1 live harness — GDPR rights (§8/§9/§10) against the REAL Smaily
 * engine (MiuMjau), plus the plugin's HPOS-safe order-meta handling.
 *
 * Proves end-to-end:
 *   - EXPORT scope on real data: the GdprHandler export surfaces engine
 *     rec-activity (browse_events) + the plugin's _smaily_* order meta, but NOT
 *     WooCommerce purchase data (total_amount) and NOT the engine's decision
 *     fields (segment / rfm_*). The engine §8 omits rec_attribution server-side.
 *   - HPOS order-meta on real wc_orders_meta: wp-env runs WC 10.7 + HPOS, so the
 *     order rec-meta is stored/read/erased via $order->get_meta /
 *     delete_meta_data — the storage-agnostic path (get_post_meta would miss it).
 *   - OPT-OUT toggle (§10): opt_out=true excludes, opt_out=false re-includes.
 *   - ERASE idempotency (§9): a second erase hits the engine 404 (already gone)
 *     and is still a success — no throw.
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected tenant. MUST run before any
 * integration-suite run.
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
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

function field_names( $export ) {
	$names = array();
	foreach ( $export['data'] as $item ) {
		foreach ( $item['data'] as $pair ) {
			$names[] = (string) $pair['name'];
		}
	}
	return $names;
}

function group_labels( $export ) {
	$labels = array();
	foreach ( $export['data'] as $item ) {
		$labels[] = (string) $item['group_label'];
	}
	return $labels;
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$client    = $bootstrap->rec_client();

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

$email = 'gdpr-walk-' . time() . '@example.test';

// Seed: a customer + browse events linked to that customer (so the export has
// rec-activity to return).
$client->ingest_customers( array( array( 'email' => $email, 'event_id' => wp_generate_uuid4(), 'first_seen_at' => IsoDate::to_z( time() ) ) ) );
$client->ingest_browse( array(
	array( 'event_id' => wp_generate_uuid4(), 'session_id' => 'gdpr-sess', 'event_type' => 'product_view', 'sku' => 'GDPR-A', 'event_ts' => IsoDate::to_z( time() ), 'source' => 'plugin_woo', 'customer_email' => $email ),
	array( 'event_id' => wp_generate_uuid4(), 'session_id' => 'gdpr-sess', 'event_type' => 'cart_add', 'sku' => 'GDPR-A', 'event_ts' => IsoDate::to_z( time() ), 'source' => 'plugin_woo', 'customer_email' => $email ),
) );

// A real HPOS order with rec-meta (wc_orders_meta under HPOS).
$p = new WC_Product_Simple();
$p->set_sku( 'GDPR-WALK-' . wp_generate_uuid4() );
$p->set_regular_price( '42.00' );
$p->set_price( '42.00' );
$p->save();
$order = wc_create_order();
$order->set_billing_email( $email );
$order->add_product( wc_get_product( (int) $p->get_id() ), 1 );
$order->calculate_totals();
$order->update_meta_data( '_smaily_rec_id', 'rec-walk-123' );
$order->update_meta_data( '_smaily_visitor_token', 'vt-walk' );
$order_id = (int) $order->save();

$gdpr = new GdprHandler( $settings, function () use ( $bootstrap ) {
	return $bootstrap->rec_client();
} );

// --- EXPORT (Art 15) scope on real data ----------------------------------
$export = $gdpr->export( $email );
$names  = field_names( $export );
$labels = group_labels( $export );
// Assert on the surfaced group (robust to inner-key naming), not a bet on an
// engine §8 row field name we haven't probed.
result( 'export_has_rec_activity', in_array( 'Browse events (recommendation engine)', $labels, true ), 'browse_events group surfaced; labels=' . json_encode( array_values( array_unique( $labels ) ) ) );
result( 'export_has_plugin_hpos_meta', in_array( '_smaily_rec_id', $names, true ), 'order _smaily_rec_id read from wc_orders_meta (HPOS)' );
result( 'export_excludes_woo_purchase', ! in_array( 'total_amount', $names, true ) && ! in_array( 'line_total', $names, true ), 'no order total / line items (Woo owns those)' );
result( 'export_strips_decision_fields', ! in_array( 'segment', $names, true ) && ! in_array( 'rfm_recency', $names, true ), 'no segment / rfm (decision logic)' );
result( 'export_omits_rec_attribution', ! in_array( 'rec_attribution', $names, true ), 'engine omits attribution from §8' );

// --- OPT-OUT (Art 21 / §10) toggle ---------------------------------------
// §10 status fields are informational: assert the toggle round-trips (true then
// false), proving the email path-param substituted (a literal placeholder 404s).
try {
	$opt_on  = $client->customer_opt_out( $email, array( 'opt_out' => true, 'reason' => 'user_preference', 'opted_out_at' => IsoDate::to_z( time() ) ) );
	$opt_off = $client->customer_opt_out( $email, array( 'opt_out' => false, 'reason' => 'user_preference' ) );
	result( 'opt_out_toggle', ( ( $opt_on['opt_out_status'] ?? null ) === true ) && ( ( $opt_off['opt_out_status'] ?? null ) === false ), 'on=' . json_encode( $opt_on['opt_out_status'] ?? null ) . ' off=' . json_encode( $opt_off['opt_out_status'] ?? null ) );
} catch ( \Throwable $t ) {
	result( 'opt_out_toggle', false, 'threw: ' . $t->getMessage() );
}

// --- ERASE (Art 17 / §9) — engine + HPOS plugin-meta ---------------------
$erase = $gdpr->erase( $email );
result( 'erase_items_removed', ! empty( $erase['items_removed'] ), json_encode( $erase ) );

$reloaded = wc_get_order( $order_id );
$meta_after = ( $reloaded instanceof \WC_Order ) ? (string) $reloaded->get_meta( '_smaily_rec_id' ) : 'ORDER_GONE';
result( 'erase_removed_hpos_plugin_meta', $meta_after === '', 'order _smaily_rec_id after erase = [' . $meta_after . ']' );

// --- ERASE idempotency: second erase → engine 404 → still a success ------
$erase2 = $gdpr->erase( $email );
result( 'erase_idempotent_second_call', ! empty( $erase2['done'] ), 'second erase done (engine 404 treated as success)' );

if ( $reloaded instanceof \WC_Order ) {
	$reloaded->delete( true );
}

echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.8-gdpr: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.8-gdpr.cjs  (after a real setup-exchange).');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.8-gdpr-tmp.php';
  const hostPath = path.join(__dirname, '..', tmpName);
  fs.writeFileSync(hostPath, LIVE_PHP);

  let out = '';
  try {
    out = runDocker(['exec', cli, 'wp', 'eval-file', `${PLUGIN_PATH_IN_CONTAINER}/${tmpName}`, '--allow-root']);
  } catch (e) {
    // A non-zero exit (e.g. an uncaught PHP fatal) still carries the RESULT
    // lines printed before it died — surface them rather than swallowing.
    out = `${e.stdout ? e.stdout.toString() : ''}\n${e.stderr ? e.stderr.toString() : ''}`;
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

  console.log('\n=== walk-3.8-gdpr live GDPR rights (real MiuMjau engine + HPOS) ===');
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
  console.error('walk-3.8-gdpr harness error:', e.message);
  process.exit(1);
});
