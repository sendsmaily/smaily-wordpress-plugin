/**
 * PRO-1633 live harness — line-level RETURN SIGNALS (contract v1.8.0 §5) against
 * the REAL Smaily rec-engine (the "Smaily Connect test" SANDBOX tenant).
 *
 * Proves end-to-end through the REAL plugin code (a real WooCommerce refund →
 * the real `woocommerce_order_partially_refunded` binding → OrderPayloadBuilder
 * → OrderFlusher → live engine):
 *
 *   - a PARTIAL WC refund changes NO order status (so nothing else would ever
 *     resync the order) yet still enqueues an order.upsert row;
 *   - the fully-refunded line wires `returned_at` in the IsoDate `Z` form plus
 *     `return_reason_raw` (the merchant's refund reason), the untouched line
 *     wires neither, and `return_reason_standardised` is never guessed;
 *   - the live engine ACCEPTS the payload (processed, zero errors[]), verified
 *     via flusher stats + the F3-44 stored exchange on the row;
 *   - the sender obligation holds: a LATER, unrelated sync of the same order
 *     re-derives the return from the order's refunds and sends it again (items
 *     are fully replaced on re-ingest — an omitting sync would erase it);
 *   - a partly-refunded QUANTITY (1 of 3) is not a return.
 *
 * Why live: `returned_at` is a formatted datetime field, exactly the class the
 * mock can't prove (LESSONS §2.3/§2.4) — the engine's strict Zod `.datetime()`
 * rejects a `+00:00` offset, and this is the first datetime we put on a LINE.
 *
 * Residue left in the SANDBOX tenant: TWO ingested order rows (external_order_ids
 * printed in the output) + their auto-created customers. Store-side there is NO
 * residue: orders (incl. their refunds) and products are deleted (orders via
 * wc_get_order()->delete(true) — HPOS). Writes no smly_rec_* option, so it needs
 * no snapshot guard; it only TRUNCATEs the ingest queue table.
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

// HEALTH GATE: a stored connection can still carry a key the engine has since
// stopped accepting (401). Without this the walk creates orders and every
// engine-facing check fails identically, which reads like a code defect.
try {
	$bootstrap->rec_client()->ping();
	result( 'sandbox_key_still_accepted', true );
} catch ( \Throwable $e ) {
	result( 'sandbox_key_still_accepted', false, $e->getMessage() );
	echo "ABORT_KEY_REJECTED\n";
	return;
}

global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
// Determinism: status changes are driven explicitly. The REFUND binding is
// deliberately LEFT REGISTERED — proving it fires is the point of this walk.
remove_all_actions( 'woocommerce_order_status_changed' );
remove_all_actions( 'woocommerce_new_order' );
remove_all_actions( 'before_delete_post' );
remove_all_actions( 'save_post_product' );

$created_orders   = array();
$created_products = array();

// The wire items of the last row the flusher POSTed for an order, keyed by sku.
$sent_items = function ( $order_id ) use ( $wpdb ) {
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT sent_payload, last_response, status FROM {$wpdb->prefix}smly_rec_event_queue WHERE entity_id = %s AND event_type = %s ORDER BY id DESC LIMIT 1",
			(string) $order_id,
			OrderFlusher::EVENT_ORDER_UPSERT
		),
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		return array( 'row' => null, 'items' => array() );
	}
	$sent  = json_decode( (string) $row['sent_payload'], true );
	$items = array();
	foreach ( ( is_array( $sent ) ? ( $sent['items'] ?? array() ) : array() ) as $item ) {
		$items[ (string) $item['sku'] ] = $item;
	}
	return array( 'row' => $row, 'items' => $items );
};

$refund_line = function ( $order_id, $product_id, $qty, $amount, $reason ) {
	$order      = wc_get_order( $order_id );
	$line_items = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		if ( (int) $item->get_product_id() === (int) $product_id ) {
			$line_items[ $item_id ] = array( 'qty' => $qty, 'refund_total' => $amount );
		}
	}
	return wc_create_refund(
		array(
			'order_id'       => $order_id,
			'amount'         => $amount,
			'reason'         => $reason,
			'line_items'     => $line_items,
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);
};

try {
	$make_product = function ( $sku, $price ) use ( &$created_products ) {
		$p = new WC_Product_Simple();
		$p->set_sku( $sku );
		$p->set_name( 'PRO1633 ' . $sku );
		$p->set_regular_price( $price );
		$p->set_price( $price );
		$p->set_stock_status( 'instock' );
		$pid                = (int) $p->save();
		$created_products[] = $pid;
		return wc_get_product( $pid );
	};

	$make_order = function ( $email, $lines ) use ( &$created_orders ) {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$oid              = (int) $order->save();
		$created_orders[] = $oid;
		return $oid;
	};

	$uniq     = substr( wp_generate_uuid4(), 0, 8 );
	$returned = $make_product( 'LIVE-1633-BACK-' . $uniq, '10.00' );
	$kept     = $make_product( 'LIVE-1633-KEPT-' . $uniq, '5.00' );

	$oid = $make_order( 'pro1633-returns@example.com', array( array( $returned, 2 ), array( $kept, 1 ) ) );
	echo 'ENGINE_RESIDUE external_order_id=' . $oid . "\n";

	// --- baseline send: nothing has come back yet -----------------------------
	OrderHookHandler::reset_seen();
	$handler->on_order_status_changed( $oid, 'pending', 'processing' );
	$stats = $flusher->flush();
	$base  = $sent_items( $oid );
	result(
		'baseline_order_accepted_without_return_fields',
		( $stats['sent'] ?? 0 ) === 1 && ! isset( $base['items'][ 'woo-' . $returned->get_id() ]['returned_at'] ),
		json_encode( $stats )
	);

	// --- the merchant refunds the whole first line, with a reason -------------
	OrderHookHandler::reset_seen();
	$refund = $refund_line( $oid, $returned->get_id(), 2, 20.0, 'Ei sobinud' );
	result( 'wc_created_the_partial_refund', $refund instanceof WC_Order_Refund, is_wp_error( $refund ) ? $refund->get_error_message() : '' );
	result(
		'partial_refund_leaves_the_order_status_untouched',
		wc_get_order( $oid )->get_status() === 'processing',
		'status=' . wc_get_order( $oid )->get_status()
	);
	result(
		'refund_hook_enqueued_a_resync',
		count( $queue->pending( 10, array( OrderFlusher::EVENT_ORDER_UPSERT ) ) ) === 1,
		'the registered woocommerce_order_partially_refunded binding fired'
	);

	$stats = $flusher->flush();
	$sent  = $sent_items( $oid );
	$back  = $sent['items'][ 'woo-' . $returned->get_id() ] ?? array();
	$still = $sent['items'][ 'woo-' . $kept->get_id() ] ?? array();

	result(
		'engine_accepts_the_returned_line',
		( $stats['sent'] ?? 0 ) === 1 && ( $stats['failed'] ?? 1 ) === 0 && ( $stats['skipped'] ?? 1 ) === 0
			&& strpos( (string) $sent['row']['last_response'], '"outcome":"accepted"' ) !== false,
		json_encode( $stats ) . ' response=' . ( $sent['row']['last_response'] ?? '(none)' )
	);
	result(
		'returned_at_is_the_iso_z_form',
		isset( $back['returned_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $back['returned_at'] ) === 1,
		'returned_at=' . ( $back['returned_at'] ?? '(none)' )
	);
	result(
		'return_reason_raw_is_the_merchant_refund_reason',
		( $back['return_reason_raw'] ?? '' ) === 'Ei sobinud' && ! isset( $back['return_reason_standardised'] ),
		'wire=' . wp_json_encode( $back )
	);
	result(
		'the_untouched_line_stays_kept',
		$still !== array() && ! isset( $still['returned_at'] ) && ! isset( $still['return_reason_raw'] ),
		'wire=' . wp_json_encode( $still )
	);

	// --- sender obligation: a LATER, unrelated sync must keep the return ------
	$returned_at = (string) ( $back['returned_at'] ?? '' );
	OrderHookHandler::reset_seen();
	wc_get_order( $oid )->update_status( 'completed' );
	$handler->on_order_status_changed( $oid, 'processing', 'completed' );
	$stats = $flusher->flush();
	$again = $sent_items( $oid );
	$back2 = $again['items'][ 'woo-' . $returned->get_id() ] ?? array();
	result(
		'a_later_unrelated_sync_still_carries_the_return',
		( $stats['sent'] ?? 0 ) === 1 && ( $back2['returned_at'] ?? '' ) === $returned_at && $returned_at !== '',
		'status=completed returned_at=' . ( $back2['returned_at'] ?? '(erased!)' )
	);

	// --- a partly-refunded QUANTITY is not a return ---------------------------
	$partial = $make_product( 'LIVE-1633-PARTQTY-' . $uniq, '10.00' );
	$oid2    = $make_order( 'pro1633-partqty@example.com', array( array( $partial, 3 ) ) );
	echo 'ENGINE_RESIDUE external_order_id=' . $oid2 . "\n";
	OrderHookHandler::reset_seen();
	$refund_line( $oid2, $partial->get_id(), 1, 10.0, 'One of three' );
	$stats = $flusher->flush();
	$item  = $sent_items( $oid2 )['items'][ 'woo-' . $partial->get_id() ] ?? array();
	result(
		'one_of_three_refunded_is_still_kept',
		( $stats['sent'] ?? 0 ) === 1 && $item !== array() && ! isset( $item['returned_at'] ) && ! isset( $item['return_reason_raw'] ),
		'wire=' . wp_json_encode( $item )
	);
} finally {
	// --- cleanup: NO store-side residue --------------------------------------
	foreach ( array_unique( $created_orders ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o instanceof \WC_Order ) {
			$o->delete( true ); // HPOS: wp_delete_post is a silent no-op for orders (refunds go with it).
		}
	}
	foreach ( array_unique( $created_products ) as $id ) {
		wp_delete_post( $id, true );
	}
}
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-pro1633-return-signals: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-pro1633-return-signals.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-pro1633-return-signals-tmp.php';
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

  console.log('\n=== walk-pro1633-return-signals: line-level returns against the real engine (sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  for (const residue of out.split('\n').filter((l) => l.startsWith('ENGINE_RESIDUE '))) {
    console.log(`  i engine-side residue in the sandbox tenant: ${residue.replace('ENGINE_RESIDUE ', '')}`);
  }
  const aborted = out.includes('ABORT_NOT_SANDBOX') || out.includes('ABORT_KEY_REJECTED');
  if (out.includes('ABORT_NOT_SANDBOX')) {
    console.log('  ✗ ABORTED — not connected to a sandbox tenant (refusing to send to production).');
  }
  if (out.includes('ABORT_KEY_REJECTED')) {
    console.log('  ✗ ABORTED — the sandbox engine rejected the stored API key (401). Nothing was created.');
    console.log('    Fix: mint a fresh setup token on the "Smaily Connect test" tenant and exchange it (see CLAUDE.md).');
  }
  if (!aborted && !out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE marker). Raw tail:');
    console.log(out.split('\n').slice(-12).join('\n'));
    failures += 1;
  }
  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-pro1633-return-signals harness error:', e.message);
  process.exit(1);
});
