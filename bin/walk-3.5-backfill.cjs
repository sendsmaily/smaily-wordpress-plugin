/**
 * Sub-PR 3.5.3b live harness — rec-engine backfill against the REAL Smaily
 * engine (MiuMjau). Drives all three backfill jobs (products / customers /
 * orders) through the same Client::ingest_* paths the live hooks use.
 *
 * What this proves that the integration tests (mock engine) can't:
 *   - the backfill reaches the REAL engine and progress reaches 100%
 *     (processed_count == total_count) for every domain;
 *   - the ORDER status filter on real HPOS data (wp-env runs WC 10.7 + HPOS):
 *     an unmapped (pending) order is excluded — total_count counts only the
 *     mapped cohort, not all orders;
 *   - resumability + bounded queue at multi-batch: the order job is driven with
 *     a batch size of 2 so several batches run against the real engine — the
 *     cursor advances monotonically (never restarts) and the queue is drained
 *     (empty) after each batch's inline flush.
 *
 * The order job runs the HPOS path (wp-env is HPOS). The LEGACY order path
 * (the pilot's WC 6.9.4 mode) is unit-tested only (table_spec) and must be
 * verified against a legacy WC env before pilot go-live (STATUS / CLAUDE).
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected tenant. Creates a small
 * controlled dataset (clears existing products/orders/non-admin users first) so
 * the counts are deterministic. MUST run before any integration-suite run.
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
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

// Determinism: clear existing products / orders / non-admin users so totals are
// known, then create a small controlled dataset.
global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}smly_plus_backfill_job WHERE target = 'rec_engine'" );

foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $pid ) {
	wp_delete_post( (int) $pid, true );
}
$order_ids = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array( 'completed', 'processing', 'on-hold', 'cancelled', 'refunded', 'pending', 'failed', 'checkout-draft' ) ) );
foreach ( $order_ids as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o instanceof \WC_Order ) {
		$o->delete( true );
	}
}
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
foreach ( get_users( array( 'fields' => 'ID', 'exclude' => array( 1 ) ) ) as $uid ) {
	wp_delete_user( (int) $uid );
}

// 5 products.
$products = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_sku( 'BFWALK-P-' . $i . '-' . wp_generate_uuid4() );
	$p->set_name( 'Backfill Walk Product ' . $i );
	$p->set_regular_price( '9.99' );
	$p->set_price( '9.99' );
	$p->set_stock_status( 'instock' );
	$products[] = (int) $p->save();
}

// 2 users (+ admin = 3).
for ( $i = 1; $i <= 2; $i++ ) {
	wp_insert_user( array(
		'user_login' => 'bfwalk_' . $i . '_' . wp_generate_uuid4(),
		'user_pass'  => 'x' . wp_generate_password( 12, false ),
		'user_email' => 'bfwalk-' . $i . '-' . wp_generate_uuid4() . '@example.test',
		'role'       => $i === 1 ? 'customer' : 'subscriber',
	) );
}

// 5 orders: 4 mapped (completed/completed/processing/refunded) + 1 unmapped (pending).
function bfwalk_make_order( $status, $product_id ) {
	$order = wc_create_order();
	$order->set_billing_email( 'bfwalk-order-' . wp_generate_uuid4() . '@example.test' );
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->calculate_totals();
	$order->set_status( $status );
	return (int) $order->save();
}
bfwalk_make_order( 'completed', $products[0] );
bfwalk_make_order( 'completed', $products[1] );
bfwalk_make_order( 'processing', $products[2] );
bfwalk_make_order( 'refunded', $products[3] );
bfwalk_make_order( 'pending', $products[4] ); // unmapped — must be excluded.

function bfwalk_read_row( $job_type ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}smly_plus_backfill_job WHERE job_type = %s AND target = 'rec_engine'",
		$job_type
	), ARRAY_A );
}

function bfwalk_run_to_completion( $job, $max = 50 ) {
	$passes = 0;
	do {
		$r = $job->process_batch();
		++$passes;
	} while ( empty( $r['completed'] ) && $passes < $max );
	return $passes;
}

// --- products (real batch) -----------------------------------------------
$pjob = $bootstrap->make_backfill_job( 'products' );
$pjob->start();
bfwalk_run_to_completion( $pjob );
$prow = bfwalk_read_row( 'products' );
result( 'products_backfill_100pct', $prow['status'] === 'completed' && (int) $prow['processed_count'] === (int) $prow['total_count'] && (int) $prow['total_count'] === 5, 'processed=' . $prow['processed_count'] . '/' . $prow['total_count'] );

// --- customers (real batch) ----------------------------------------------
$cjob = $bootstrap->make_backfill_job( 'customers' );
$cjob->start();
bfwalk_run_to_completion( $cjob );
$crow = bfwalk_read_row( 'customers' );
result( 'customers_backfill_100pct', $crow['status'] === 'completed' && (int) $crow['processed_count'] === (int) $crow['total_count'] && (int) $crow['total_count'] === 3, 'processed=' . $crow['processed_count'] . '/' . $crow['total_count'] . ' (admin + 2)' );

// --- orders (batch_size 2 → multi-batch against the real engine) ----------
$oqueue   = $bootstrap->ingest_queue();
$oflusher = new OrderFlusher( $oqueue, $bootstrap->order_payload_builder(), $settings, function () use ( $settings ) {
	return new \Smaily\Connect\Smaily\RecEngine\Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
} );
$ojob = new class( $oqueue, $oflusher ) extends OrderBackfillJob {
	protected function batch_size(): int {
		return 2;
	}
};
$ojob->start();
$orow_start = bfwalk_read_row( 'orders' );

// Drive batches, tracking cursor monotonicity + bounded queue after each.
$cursors      = array();
$bounded_all  = true;
$prev_cursor  = -1;
$monotonic    = true;
$batches      = 0;
do {
	$r = $ojob->process_batch();
	++$batches;
	$row     = bfwalk_read_row( 'orders' );
	$cursor  = (int) $row['cursor_value'];
	if ( $cursor < $prev_cursor ) {
		$monotonic = false;
	}
	$prev_cursor = $cursor;
	$pending = $oqueue->pending( 1000, array( OrderFlusher::EVENT_ORDER_UPSERT ) );
	if ( $pending !== array() ) {
		$bounded_all = false;
	}
} while ( empty( $r['completed'] ) && $batches < 50 );

$orow = bfwalk_read_row( 'orders' );
result( 'orders_status_filter_excludes_unmapped', (int) $orow['total_count'] === 4, 'total=' . $orow['total_count'] . ' (4 mapped of 5 orders; pending excluded)' );
result( 'orders_backfill_100pct', $orow['status'] === 'completed' && (int) $orow['processed_count'] === (int) $orow['total_count'], 'processed=' . $orow['processed_count'] . '/' . $orow['total_count'] );
result( 'orders_multi_batch_resumable', $batches >= 2 && $monotonic, 'batches=' . $batches . ' cursor_monotonic=' . ( $monotonic ? 'yes' : 'no' ) );
result( 'orders_queue_bounded_each_batch', $bounded_all, 'pending empty after every inline flush' );

echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.5-backfill: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.5-backfill.cjs  (after a real setup-exchange).');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.5-backfill-tmp.php';
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

  console.log('\n=== walk-3.5-backfill live rec-engine backfill (real MiuMjau engine) ===');
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
  console.error('walk-3.5-backfill harness error:', e.message);
  process.exit(1);
});
