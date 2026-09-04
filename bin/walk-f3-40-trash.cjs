/**
 * F3-40 live harness — a TRASHED product is kept in the rec-engine catalog as
 * `in_stock=false` (never dropped), against the REAL Smaily rec-engine (the
 * "Smaily Connect test" SANDBOX tenant).
 *
 * Why this exists: F3-40 shipped on mock-only validation (ci:strict + the
 * mock-engine integration suite). The mock validates loosely; the engine's
 * strict Zod is the only thing that proves a trashed product's `catalog.delete`
 * (→ in_stock=false UPSERT) is ACCEPTED on the wire. Every catalog wire-shape
 * change in this project must be live-walked (CLAUDE.md) — this closes that gap
 * and becomes the permanent regression cover the trash path lacked.
 *
 * What it proves end-to-end against the live engine, through the REAL code:
 *   - LIVE HOOK: wp_trash_post → on_delete_product enqueues exactly one
 *     catalog.delete and NO catalog.upsert (the clobber guard: wp_trash_post
 *     also fires save_post_product → on_save_product, which must early-return on
 *     a trash-status save). The flusher stamps in_stock=false and the engine
 *     accepts it (processed=1, errors=[]).
 *   - UNTRASH: untrashed_post → on_save_product re-syncs the product as a
 *     catalog.upsert; the engine accepts the in_stock=true state.
 *   - BACKFILL: CatalogBackfillJob::enqueue_record on a trashed product routes
 *     it through enqueue_unavailable → a catalog.delete the engine accepts (the
 *     same wire object the live hook produces, so both paths are covered).
 *
 * NOT covered here (by design, matching the integration suite): the is_removable
 * skip of a category-LESS trashed product — WooCommerce auto-assigns
 * "Uncategorized", so a genuinely category-less fixture is fragile live; that
 * guard is unit-tested (CatalogBackfillJobTest::test_trashed_product_with_blank_
 * category_path_is_skipped). The engine does not echo in_stock in the ingest
 * response, so "in_stock=false on the wire" is proven by the mock integration
 * test (last_catalog_in_stock); here we prove the engine ACCEPTS the row.
 *
 * Gated on RECENGINE_LIVE=1. Hard-aborts if the connected tenant is the
 * production "MiuMjau" (never send test data to production — the 2026-06-12
 * incident). Test SKUs are `LIVE-F340-*` so the engine's `recommendable` flag
 * excludes them. Run BEFORE any integration-suite run — EnvScrub wipes the
 * smly_rec_* options the connection lives in.
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
use Smaily\Connect\Smaily\RecEngine\Backfill\CatalogBackfillJob;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$flusher   = $bootstrap->ingest_flusher();
$tenant    = $settings->tenant_name();

// SAFETY GATE: never send test data to the production tenant.
result( 'sandbox_tenant_not_production', $settings->is_connected() && $tenant !== 'MiuMjau', 'tenant=' . $tenant );
if ( ! $settings->is_connected() || $tenant === 'MiuMjau' ) {
	echo "ABORT_NOT_SANDBOX\n";
	return;
}

global $wpdb;
$truncate = function () use ( $wpdb ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
};
// Count this request's queue rows for one entity by event type.
$count_rows = function ( $event_type, $entity_id ) use ( $queue ) {
	$n = 0;
	foreach ( $queue->pending( 200 ) as $r ) {
		if ( $r['event_type'] === $event_type && (string) $r['entity_id'] === (string) $entity_id ) {
			$n++;
		}
	}
	return $n;
};

// A product with a real product_cat term so category_path is non-empty (the
// engine requires it; is_removable keys on it). The Bootstrap WC hooks are LIVE
// in wp eval-file, so creating/saving fires on_save_product — intentional here:
// we want the real wp_trash_post path to fire both hooks (clobber-guard proof).
$make_categorized = function ( $sku ) {
	$cat = term_exists( 'f340-cat', 'product_cat' );
	if ( ! $cat ) {
		$cat = wp_insert_term( 'F340 Cat', 'product_cat', array( 'slug' => 'f340-cat' ) );
	}
	$cat_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );
	$p = new WC_Product_Simple();
	$p->set_sku( $sku );
	$p->set_name( 'F340 ' . $sku );
	$p->set_regular_price( '9.99' );
	$p->set_price( '9.99' );
	$p->set_stock_status( 'instock' );
	$p->set_category_ids( array( $cat_id ) );
	return (int) $p->save();
};

$created = array();

// =====================================================================
// Scenario A — LIVE HOOK: trash → in_stock=false, untrash → in_stock=true
// =====================================================================
CatalogHookHandler::reset_seen();
$sku_a     = 'LIVE-F340-A-' . wp_generate_uuid4();
$pid_a     = $make_categorized( $sku_a );
$created[] = $pid_a;

// Clear the create-time upsert + reset the in-request dedup so the clobber guard
// is what suppresses an upsert during trashing, not already_seen masking it.
$truncate();
CatalogHookHandler::reset_seen();

// wp_trash_post fires wp_trash_post → on_delete_product (enqueue catalog.delete)
// AND save_post_product → on_save_product (must early-return on trash status).
wp_trash_post( $pid_a );
$is_trash   = get_post_status( $pid_a ) === 'trash';
$delete_n   = $count_rows( CatalogHookHandler::EVENT_CATALOG_DELETE, $pid_a );
$upsert_n   = $count_rows( CatalogHookHandler::EVENT_CATALOG_UPSERT, $pid_a );
result( 'live_trash_enqueues_delete_not_upsert', $is_trash && $delete_n === 1 && $upsert_n === 0, 'status_trash=' . ( $is_trash ? 1 : 0 ) . ' delete=' . $delete_n . ' upsert=' . $upsert_n );

$stats_t = $flusher->flush();
result( 'live_trash_in_stock_false_accepted_by_engine', $stats_t['sent'] >= 1 && $stats_t['failed'] === 0, json_encode( $stats_t ) );

// Untrash → on_save_product re-syncs the product as a catalog.upsert.
$truncate();
CatalogHookHandler::reset_seen();
wp_untrash_post( $pid_a );
// Some WP versions restore an untrashed post to 'draft'; force publish so the
// product is a live, sellable upsert again.
wp_update_post( array( 'ID' => $pid_a, 'post_status' => 'publish' ) );
$untrash_upsert = $count_rows( CatalogHookHandler::EVENT_CATALOG_UPSERT, $pid_a ) >= 1;
result( 'live_untrash_enqueues_upsert', $untrash_upsert, 'upsert_rows=' . $count_rows( CatalogHookHandler::EVENT_CATALOG_UPSERT, $pid_a ) );

$stats_u = $flusher->flush();
result( 'live_untrash_in_stock_true_accepted_by_engine', $stats_u['sent'] >= 1 && $stats_u['failed'] === 0, json_encode( $stats_u ) );

// =====================================================================
// Scenario B — BACKFILL: a trashed product routes through enqueue_unavailable
// =====================================================================
$truncate();
CatalogHookHandler::reset_seen();
$sku_b     = 'LIVE-F340-B-' . wp_generate_uuid4();
$pid_b     = $make_categorized( $sku_b );
$created[] = $pid_b;
wp_trash_post( $pid_b );
$truncate(); // drop the live-hook delete; assert only what the BACKFILL enqueues.

// Drive the real backfill's per-record branch directly (not the whole-store
// process_batch, which would enumerate every product). Same deps Bootstrap
// wires for the AS job; subclass only to reach the protected enqueue_record.
$backfill = new class(
	$bootstrap->ingest_queue(),
	$bootstrap->ingest_flusher(),
	$bootstrap->catalog_payload_builder(),
	$bootstrap->multilingual_detector()
) extends CatalogBackfillJob {
	public function run_one( int $id ): void {
		$this->enqueue_record( $id );
	}
};
$backfill->run_one( $pid_b );
$bf_delete = $count_rows( CatalogHookHandler::EVENT_CATALOG_DELETE, $pid_b );
result( 'backfill_trashed_enqueues_delete', $bf_delete === 1, 'delete_rows=' . $bf_delete );

$stats_b = $flusher->flush();
result( 'backfill_trashed_accepted_by_engine', $stats_b['sent'] >= 1 && $stats_b['failed'] === 0, json_encode( $stats_b ) );

// Cleanup: permanently remove the test products + leave the queue empty.
remove_all_actions( 'before_delete_post' );
foreach ( $created as $id ) {
	wp_delete_post( $id, true );
}
$truncate();
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-f3-40-trash: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-f3-40-trash.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-f3-40-trash-tmp.php';
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

  console.log('\n=== walk-f3-40-trash: trashed product kept in_stock=false against the real engine (sandbox) ===');
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
  console.error('walk-f3-40-trash harness error:', e.message);
  process.exit(1);
});
