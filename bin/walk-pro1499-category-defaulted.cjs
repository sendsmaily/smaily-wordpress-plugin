/**
 * PRO-1499 live harness — `tags.category_defaulted` on a catalog.upsert row
 * against the REAL Smaily rec-engine (the "Smaily Connect test" SANDBOX
 * tenant). Contract v1.6.0 (engine commit 06266a8, engine-side skip
 * behavior already deployed per PRO-1500).
 *
 * Proves, end-to-end through the REAL plugin code, what the mock cannot
 * (the mock accepts the tags shape unconditionally — this confirms the LIVE
 * engine's strict Zod also accepts the new optional key and the batch is
 * processed, not rejected):
 *
 *   - a PUBLISHED product with NO product_cat term at all (bulk-import shape,
 *     bypassing WC_Product::save()'s force_default_term self-heal — same
 *     fixture technique as RecEngineCatalogTest::make_uncategorized_product())
 *     builds a category_path from the store's default_product_cat fallback
 *     (PRO-1491) AND carries tags.category_defaulted = "true" on the wire;
 *   - the live engine ACCEPTS the batch (processed, no errors[]).
 *
 * Gated on RECENGINE_LIVE=1. Hard-aborts if the connected tenant is the
 * production "MiuMjau". Run BEFORE any integration-suite run — EnvScrub
 * wipes the connection (snapshot/restore per LESSONS §2.17).
 *
 * Hygiene: the fixture product is hard-deleted (wp_delete_post, force=true)
 * at the end. Engine-side residue (named in the output): one tombstoned-free
 * catalog row (in_stock=true, woo-<id>) — no delete is sent for it (this walk
 * only exercises the upsert path), left for a future catalog re-backfill or
 * manual purge like other walks' residue.
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

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$builder   = $bootstrap->catalog_payload_builder();
$tenant    = $settings->tenant_name();

// SAFETY GATE: never send test data to the production tenant.
result( 'sandbox_tenant_not_production', $settings->is_connected() && $tenant !== 'MiuMjau', 'tenant=' . $tenant );
if ( ! $settings->is_connected() || $tenant === 'MiuMjau' ) {
	echo "ABORT_NOT_SANDBOX\n";
	return;
}

global $wpdb;
$queue_table = $wpdb->prefix . 'smly_rec_event_queue';
$wpdb->query( "TRUNCATE TABLE {$queue_table}" ); // phpcs:ignore

// Determinism: the real save_post_product binding would ALSO enqueue an
// upsert row when wp_insert_post() below creates the product — drive the
// queue explicitly instead (same pattern as walk-pro1224-1230.cjs).
remove_all_actions( 'save_post_product' );

$sku = 'ZZ-PRO1499-NOCAT';
$existing = wc_get_product_id_by_sku( $sku );
if ( $existing ) {
	wp_delete_post( $existing, true );
}

// Bulk-import shape (F3-39 addendum): wp_insert_post + meta directly, NEVER
// through WC_Product::save() — so WC_Post_Data::force_default_term() never
// runs and the product genuinely has zero product_cat terms.
$pid = (int) wp_insert_post(
	array(
		'post_type'   => 'product',
		'post_status' => 'publish',
		'post_title'  => 'PRO-1499 walk (no category)',
	)
);
update_post_meta( $pid, '_sku', $sku );
update_post_meta( $pid, '_regular_price', '9.99' );
update_post_meta( $pid, '_price', '9.99' );
update_post_meta( $pid, '_stock_status', 'instock' );
update_post_meta( $pid, '_manage_stock', 'no' );
update_post_meta( $pid, '_virtual', 'no' );
update_post_meta( $pid, '_downloadable', 'no' );

$product = wc_get_product( $pid );
result( 'fixture_product_loads', $product instanceof WC_Product, 'pid=' . $pid );

$defaulted = false;
$path      = $builder->primary_category_path( $product, $defaulted );
result( 'category_path_defaulted_and_nonempty', $defaulted && $path !== '', 'path=' . $path . ' defaulted=' . ( $defaulted ? '1' : '0' ) );

CatalogHookHandler::reset_seen();
$queue->enqueue( CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $pid, array(), wp_generate_uuid4() );
$stats = $bootstrap->ingest_flusher()->flush();
result( 'engine_accepts_batch', ( $stats['sent'] ?? 0 ) === 1 && ( $stats['failed'] ?? 1 ) === 0, wp_json_encode( $stats ) );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, sent_payload, last_response FROM {$queue_table} WHERE event_type = %s AND entity_id = %s", CatalogHookHandler::EVENT_CATALOG_UPSERT, (string) $pid ), ARRAY_A ); // phpcs:ignore
$sent = json_decode( (string) ( $row['sent_payload'] ?? '' ), true ) ?: array();
result( 'wire_carries_category_defaulted_true', ( $sent['tags']['category_defaulted'] ?? '' ) === 'true', 'tags=' . wp_json_encode( $sent['tags'] ?? null ) );
echo 'EVIDENCE catalog entity=' . $pid . ' status=' . ( $row['status'] ?? '?' ) . ' sent=' . ( $row['sent_payload'] ?? '?' ) . ' response=' . ( $row['last_response'] ?? '?' ) . "\n";

// === cleanup =================================================================
wp_delete_post( $pid, true );
clean_post_cache( $pid );
result( 'fixture_cleaned_up', ! get_post( $pid ), 'pid=' . $pid );

echo 'RESIDUE engine-side (sandbox tenant "' . $tenant . '"): one catalog row woo-' . $pid . ' left in_stock=true (upsert-only walk, no delete sent) — harmless test SKU, safe to ignore or purge later.' . "\n";
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-pro1499-category-defaulted: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-pro1499-category-defaulted.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-pro1499-tmp.php';
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

  console.log('\n=== walk-pro1499-category-defaulted: tags.category_defaulted against the real engine (sandbox) ===');
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
  console.error('walk-pro1499-category-defaulted harness error:', e.message);
  process.exit(1);
});
