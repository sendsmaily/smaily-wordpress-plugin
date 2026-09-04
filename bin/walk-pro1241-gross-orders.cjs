/**
 * PRO-1241 live harness — GROSS (tax-inclusive) order amounts against the REAL
 * Smaily rec-engine (the "Smaily Connect test" SANDBOX tenant).
 *
 * Proves contract v1.4.0 §5 "Amount semantics" end-to-end through the REAL
 * plugin code (OrderPayloadBuilder → OrderFlusher → live engine):
 *
 *   - a REAL-WC-taxed (24% VAT), coupon-discounted, shipped multi-line order
 *     wires items[].line_total = get_total() + get_total_tax() (GROSS, not the
 *     pre-1.4.0 net get_total() that understated per-SKU revenue ~24%),
 *     unit_price = gross line ÷ qty, and gross discount_amount (line + order);
 *   - the §5 sender invariant holds on the wire:
 *     Σ items[].line_total + shipping ≈ total_amount (± rounding);
 *   - the live engine ACCEPTS the gross payload (processed, zero errors[]),
 *     verified via flusher stats + the F3-44 stored exchange on the row.
 *
 * Why live: amounts are exactly the formatted-field class the mock can't prove
 * (LESSONS §2.3) — only the engine's strict Zod + real ingest accept the values.
 *
 * Residue left in the SANDBOX tenant: ONE ingested order row (external_order_id
 * printed in the output) + its auto-created customer (pro1241-gross@example.com).
 * Store-side there is NO residue: order/products/coupon/tax rate are deleted and
 * the tax options restored (orders via wc_get_order()->delete(true) — HPOS).
 *
 * Gated on RECENGINE_LIVE=1. Hard-aborts if the connected tenant is production
 * "MiuMjau".
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

// Determinism: drive the handler explicitly; keep product/catalog hooks out.
global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
remove_all_actions( 'woocommerce_order_status_changed' );
remove_all_actions( 'woocommerce_new_order' );
remove_all_actions( 'before_delete_post' );
remove_all_actions( 'save_post_product' );

// --- REAL WC tax engine: 24% VAT (pilot market), prices entered ex-tax -------
$prev_calc_taxes  = get_option( 'woocommerce_calc_taxes' );
$prev_prices_incl = get_option( 'woocommerce_prices_include_tax' );
$prev_tax_based   = get_option( 'woocommerce_tax_based_on' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
$tax_rate_id = (int) WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '24.0000',
		'tax_rate_name'     => 'VAT',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	)
);

$created_orders   = array();
$created_products = array();
$coupon_id        = 0;

try {
	$make_product = function ( $sku, $price ) use ( &$created_products ) {
		$p = new WC_Product_Simple();
		$p->set_sku( $sku );
		$p->set_name( 'PRO1241 ' . $sku );
		$p->set_regular_price( $price );
		$p->set_price( $price );
		$p->set_stock_status( 'instock' );
		$pid                = (int) $p->save();
		$created_products[] = $pid;
		return wc_get_product( $pid );
	};

	$uniq      = substr( wp_generate_uuid4(), 0, 8 );
	$product_a = $make_product( 'LIVE-1241-A-' . $uniq, '10.00' );
	$product_b = $make_product( 'LIVE-1241-B-' . $uniq, '20.00' );

	$coupon = new WC_Coupon();
	$coupon->set_code( 'pro1241-' . $uniq );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( '5.00' );
	$coupon_id = (int) $coupon->save();

	$order = wc_create_order();
	$order->set_billing_email( 'pro1241-gross@example.com' );
	$order->add_product( $product_a, 1 );
	$order->add_product( $product_b, 2 );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_total( '5.00' );
	$order->add_item( $shipping );
	$order->apply_coupon( $coupon->get_code() );
	$order->calculate_totals( true );
	$order->set_status( 'completed' );
	$oid              = (int) $order->save();
	$created_orders[] = $oid;
	echo 'ENGINE_RESIDUE external_order_id=' . $oid . "\n";

	$order = wc_get_order( $oid );
	result( 'wc_really_taxed_the_order', (float) $order->get_total_tax() > 0.0, 'order_tax=' . $order->get_total_tax() . ' total=' . $order->get_total() );

	// --- wire object: gross basis (built exactly as the flusher does) --------
	$obj   = $builder->build( $order, wp_generate_uuid4() );
	$items = $obj['items'];

	$all_gross    = true;
	$all_unit_ok  = true;
	$all_disc_ok  = true;
	$line_sum     = 0.0;
	$wc_items     = array_values( $order->get_items() );
	foreach ( $wc_items as $i => $wc_item ) {
		$net      = (float) $wc_item->get_total();
		$gross    = $net + (float) $wc_item->get_total_tax();
		$wired    = (float) $items[ $i ]['line_total'];
		$qty      = (int) $wc_item->get_quantity();
		$line_sum += $wired;
		if ( abs( $wired - $gross ) > 0.0001 || $wired <= $net ) {
			$all_gross = false;
		}
		if ( abs( (float) $items[ $i ]['unit_price'] - $gross / $qty ) > 0.0001 ) {
			$all_unit_ok = false;
		}
		if ( ! isset( $items[ $i ]['discount_amount'] ) || (float) $items[ $i ]['discount_amount'] <= 0.0 ) {
			$all_disc_ok = false;
		}
	}
	result( 'line_total_is_gross_not_net', $all_gross, 'items=' . wp_json_encode( $items ) );
	result( 'unit_price_is_gross_over_qty', $all_unit_ok );
	result( 'line_discounts_present_gross', $all_disc_ok );

	$gross_shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
	$total_amount   = (float) $obj['total_amount'];
	result(
		'sender_invariant_lines_plus_shipping_is_total',
		abs( $total_amount - ( $line_sum + $gross_shipping ) ) <= 0.01,
		'sum(line_total)=' . $line_sum . ' + shipping=' . $gross_shipping . ' vs total_amount=' . $total_amount
	);

	$gross_discount = (float) $order->get_total_discount( false );
	$net_discount   = (float) $order->get_total_discount( true );
	result(
		'order_discount_is_gross',
		isset( $obj['discount_amount'] ) && abs( (float) $obj['discount_amount'] - $gross_discount ) <= 0.0001 && (float) $obj['discount_amount'] > $net_discount,
		'wired=' . ( $obj['discount_amount'] ?? '(none)' ) . ' gross=' . $gross_discount . ' net=' . $net_discount
	);

	// --- enqueue via the real handler, flush via the real flusher → live engine
	OrderHookHandler::reset_seen();
	$handler->on_order_status_changed( $oid, 'pending', 'completed' );
	$stats = $flusher->flush();
	result( 'engine_accepts_gross_order', ( $stats['sent'] ?? 0 ) >= 1 && ( $stats['failed'] ?? 1 ) === 0 && ( $stats['skipped'] ?? 1 ) === 0, json_encode( $stats ) );

	// --- F3-44 stored exchange: the exact POSTed JSON is gross + accepted ----
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT sent_payload, last_response, status FROM {$wpdb->prefix}smly_rec_event_queue WHERE entity_id = %s AND event_type = %s",
			(string) $oid,
			OrderFlusher::EVENT_ORDER_UPSERT
		),
		ARRAY_A
	);
	$sent_ok = is_array( $row ) && $row['status'] === 'sent'
		&& strpos( (string) $row['sent_payload'], '"line_total":' . wp_json_encode( (float) $items[0]['line_total'] ) ) !== false
		&& strpos( (string) $row['last_response'], '"outcome":"accepted"' ) !== false;
	result( 'stored_exchange_shows_gross_wire_and_accept', $sent_ok, is_array( $row ) ? ( 'status=' . $row['status'] . ' response=' . $row['last_response'] ) : 'row missing' );
} finally {
	// --- cleanup: NO store-side residue ---------------------------------------
	foreach ( array_unique( $created_orders ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o instanceof \WC_Order ) {
			$o->delete( true ); // HPOS: wp_delete_post is a silent no-op for orders.
		}
	}
	foreach ( array_unique( $created_products ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $coupon_id > 0 ) {
		wp_delete_post( $coupon_id, true );
	}
	if ( $tax_rate_id > 0 ) {
		WC_Tax::_delete_tax_rate( $tax_rate_id );
	}
	update_option( 'woocommerce_calc_taxes', $prev_calc_taxes );
	update_option( 'woocommerce_prices_include_tax', $prev_prices_incl );
	update_option( 'woocommerce_tax_based_on', $prev_tax_based );
}
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-pro1241-gross-orders: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-pro1241-gross-orders.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-pro1241-gross-orders-tmp.php';
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

  console.log('\n=== walk-pro1241-gross-orders: gross (tax-inclusive) order amounts against the real engine (sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  const residue = out.split('\n').find((l) => l.startsWith('ENGINE_RESIDUE '));
  if (residue) {
    console.log(`  i engine-side residue in the sandbox tenant: ${residue.replace('ENGINE_RESIDUE ', '')} (+ customer pro1241-gross@example.com)`);
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
  console.error('walk-pro1241-gross-orders harness error:', e.message);
  process.exit(1);
});
