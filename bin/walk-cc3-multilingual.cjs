/**
 * CC.3 + CC.4 live harness — multilingual `{lang:value}` catalog payload AND the
 * structural signal fields (product_type / is_virtual / is_downloadable) against
 * the REAL Smaily rec-engine (the "Smaily Connect test" SANDBOX tenant).
 *
 * What this proves that the integration tests (mock engine) can't: the engine's
 * strict Zod ACCEPTS the model-B object form of name / description / product_url,
 * plus the CC.4 signal fields (incl. a gift-card `product_type` the engine uses
 * to derive `recommendable`)
 * — the exact mock-vs-live divergence CC-8 exists to catch (the mock validates
 * field types loosely; only the live engine enforces them). A stub detector
 * supplies the per-language translations, so the REAL CatalogPayloadBuilder
 * emits the same `{lang:value}` wire bytes the WPML/Polylang path produces — the
 * engine can't tell the source, so this validates the wire shape regardless of
 * which i18n plugin is installed. It also re-confirms the single-language STRING
 * form (model A) is still accepted (graceful degradation).
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected SANDBOX tenant — the script
 * hard-aborts if the connected tenant is the production "MiuMjau" (never send
 * test data to production; the 2026-06-12 incident). Test SKUs are `LIVE-CC3-*`
 * so the engine's `recommendable` flag excludes them from recommendations.
 *
 * Connect first (one-time, secret-safe): pipe a fresh sandbox setup URL into a
 * SetupExchange+store eval-file via STDIN so the token never lands on a command
 * line. Then run this with RECENGINE_LIVE=1.
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
use Smaily\Connect\Smaily\RecEngine\CatalogPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Multilingual\DetectorInterface;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$tenant    = $settings->tenant_name();

// SAFETY GATE: never send test data to the production tenant.
result( 'sandbox_tenant_not_production', $settings->is_connected() && $tenant !== 'MiuMjau', 'tenant=' . $tenant );
if ( ! $settings->is_connected() || $tenant === 'MiuMjau' ) {
	echo "ABORT_NOT_SANDBOX\n";
	return;
}

$client = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );

// Stub detector → the SAME {lang:value} wire bytes the real WPML/Polylang path
// produces (the engine can't tell the source; this validates the wire shape).
$detector = new class implements DetectorInterface {
	public function get_detected_languages(): array { return array( 'et', 'en' ); }
	public function get_current_language(): string { return 'et'; }
	public function get_translated_post_id( int $post_id, string $language ): ?int { return $post_id; }
	public function get_translated_permalink( int $post_id, string $language ): ?string { return null; }
	public function get_default_language(): string { return 'et'; }
	public function get_canonical_post_id( int $post_id ): int { return $post_id; }
	public function get_translations( int $post_id ): array {
		return array(
			'name'        => array( 'et' => 'CC3 Test Toode', 'en' => 'CC3 Test Product' ),
			'description' => array( 'et' => 'Eestikeelne kirjeldus', 'en' => 'English description' ),
			'product_url' => array( 'et' => 'https://example.test/et/cc3', 'en' => 'https://example.test/en/cc3' ),
		);
	}
};

// A real product with a real category (category_path is required, non-empty).
$cat = term_exists( 'cc3-cat', 'product_cat' );
if ( ! $cat ) {
	$cat = wp_insert_term( 'CC3 Cat', 'product_cat', array( 'slug' => 'cc3-cat' ) );
}
$cat_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );

$p = new WC_Product_Simple();
$p->set_sku( 'LIVE-CC3-' . wp_generate_uuid4() );
$p->set_name( 'CC3 Test Product' );
$p->set_regular_price( '12.34' );
$p->set_price( '12.34' );
$p->set_stock_status( 'instock' );
$pid = (int) $p->save();
wp_set_object_terms( $pid, array( $cat_id ), 'product_cat' );

$product = wc_get_product( $pid );
$object  = ( new CatalogPayloadBuilder( $detector ) )->build( $product, wp_generate_uuid4() );

result( 'builder_name_is_lang_object', is_array( $object['name'] ) && isset( $object['name']['et'], $object['name']['en'] ), 'name=' . wp_json_encode( $object['name'] ) );
result( 'builder_description_is_lang_object', is_array( $object['description'] ?? null ), 'description=' . wp_json_encode( $object['description'] ?? null ) );
result( 'builder_product_url_is_lang_object', is_array( $object['product_url'] ), 'product_url=' . wp_json_encode( $object['product_url'] ) );
result( 'builder_category_path_present', isset( $object['category_path'] ) && $object['category_path'] !== '', 'category_path=' . ( $object['category_path'] ?? '' ) );

// CC.4 structural signal — the builder always emits these (engine derives
// recommendable from product_type; the plugin never excludes — F3-38).
result( 'builder_emits_structural_signal', isset( $object['product_type'] ) && array_key_exists( 'is_virtual', $object ) && array_key_exists( 'is_downloadable', $object ), 'type=' . ( $object['product_type'] ?? '?' ) . ' virtual=' . wp_json_encode( $object['is_virtual'] ?? null ) . ' downloadable=' . wp_json_encode( $object['is_downloadable'] ?? null ) );

// Send the {lang:value} object to the REAL engine.
try {
	$resp      = $client->ingest_catalog( array( $object ) );
	$processed = isset( $resp['processed'] ) ? (int) $resp['processed'] : -1;
	$errors    = ( isset( $resp['errors'] ) && is_array( $resp['errors'] ) ) ? $resp['errors'] : array( '<missing>' );
	result( 'engine_accepts_lang_value_object', $processed === 1 && count( $errors ) === 0, 'processed=' . $processed . ' errors=' . wp_json_encode( $errors ) );
} catch ( \Throwable $e ) {
	result( 'engine_accepts_lang_value_object', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// Single-language STRING form must still be accepted (model A graceful path).
$str = array(
	'event_id'      => wp_generate_uuid4(),
	'sku'           => 'LIVE-CC3-STR-' . wp_generate_uuid4(),
	'name'          => 'CC3 Single Lang',
	'category_path' => (string) $object['category_path'],
	'price'         => 9.99,
	'in_stock'      => true,
	'product_url'   => 'https://example.test/cc3-single',
	'description'   => 'Single language description',
	'external_id'   => (string) $pid,
);
try {
	$resp2      = $client->ingest_catalog( array( $str ) );
	$processed2 = isset( $resp2['processed'] ) ? (int) $resp2['processed'] : -1;
	$errors2    = ( isset( $resp2['errors'] ) && is_array( $resp2['errors'] ) ) ? $resp2['errors'] : array( '<missing>' );
	result( 'engine_accepts_string_form_too', $processed2 === 1 && count( $errors2 ) === 0, 'processed=' . $processed2 . ' errors=' . wp_json_encode( $errors2 ) );
} catch ( \Throwable $e ) {
	result( 'engine_accepts_string_form_too', false, 'EXCEPTION ' . $e->getMessage() );
}

// CC.4: a gift-card product_type signal must be ACCEPTED on the wire (the
// engine sets recommendable=false internally — not observable in the ingest
// response, but a Zod reject of the new fields would be). wp-env has no
// gift-card plugin, so the type string is supplied directly.
$gc = array(
	'event_id'        => wp_generate_uuid4(),
	'sku'             => 'LIVE-CC3-GC-' . wp_generate_uuid4(),
	'name'            => 'CC4 Gift Card',
	'category_path'   => (string) $object['category_path'],
	'price'           => 25.0,
	'in_stock'        => true,
	'product_url'     => 'https://example.test/cc4-gc',
	'external_id'     => (string) $pid,
	'product_type'    => 'pw-gift-card',
	'is_virtual'      => true,
	'is_downloadable' => false,
);
try {
	$resp3      = $client->ingest_catalog( array( $gc ) );
	$processed3 = isset( $resp3['processed'] ) ? (int) $resp3['processed'] : -1;
	$errors3    = ( isset( $resp3['errors'] ) && is_array( $resp3['errors'] ) ) ? $resp3['errors'] : array( '<missing>' );
	result( 'engine_accepts_product_type_signal', $processed3 === 1 && count( $errors3 ) === 0, 'processed=' . $processed3 . ' errors=' . wp_json_encode( $errors3 ) );
} catch ( \Throwable $e ) {
	result( 'engine_accepts_product_type_signal', false, 'EXCEPTION ' . $e->getMessage() );
}

wp_delete_post( $pid, true );
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-cc3-multilingual: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-cc3-multilingual.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-cc3-multilingual-tmp.php';
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

  console.log('\n=== walk-cc3-multilingual: {lang:value} catalog against the real engine (sandbox) ===');
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
  console.error('walk-cc3-multilingual harness error:', e.message);
  process.exit(1);
});
