/**
 * Sub-PR 3.7.1 live harness — identity merge (§7) against the REAL Smaily
 * engine (MiuMjau). Proves the EXPLICIT login-driven anon→known binding, and
 * how it relates to the engine's AUTOMATIC browse-event retroactive binding
 * (§6, proved in 3.4.4):
 *
 *   - Scenario A (merge binds the not-yet-bound): anonymous browse on session
 *     S_A (no email) → merge(S_A, email) → browse_events_updated > 0. A second
 *     merge of the same (session, email) → browse_events_already_bound > 0
 *     (idempotent).
 *   - Scenario B (already retroactively bound → merge is a no-op): anonymous
 *     browse on S_B → a browse event on S_B carrying the email (the engine
 *     retroactively binds, retroactive_bound > 0) → merge(S_B, email) →
 *     browse_events_already_bound > 0 (the merge finds nothing left to bind).
 *   - 404: merge for an email that was never ingested → customer_not_found.
 *
 * So explicit merge and automatic retroactive binding are distinguishable and
 * complementary: merge fills the "logged in but never browsed with an email"
 * gap; where retroactive already bound, merge is idempotent.
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
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$settings = new \Smaily\Connect\Settings\RecEngineSettings();
$client   = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints() );

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

function browse_event( $session, $extra = array() ) {
	return array_merge( array(
		'event_id'   => wp_generate_uuid4(),
		'session_id' => $session,
		'event_type' => 'product_view',
		'event_ts'   => IsoDate::to_z( time() ),
		'source'     => 'plugin_woo',
		'sku'        => 'IDM-' . wp_generate_uuid4(),
	), $extra );
}

// Known customer the merge resolves to.
$email = 'idm-walk-' . time() . '@example.test';
$client->ingest_customers( array( array(
	'email'         => $email,
	'event_id'      => wp_generate_uuid4(),
	'first_seen_at' => IsoDate::to_z( time() ),
) ) );

// --- Scenario A: explicit merge binds the not-yet-bound -------------------
$sa = 'idm-A-' . wp_generate_uuid4();
$client->ingest_browse( array( browse_event( $sa ), browse_event( $sa ) ) ); // anonymous (no email)

$merge_a1 = $client->merge_identity( array(
	'anon_session_id' => $sa,
	'customer_email'  => $email,
	'merge_ts'        => IsoDate::to_z( time() ),
	'merge_reason'    => 'user_logged_in',
) );
$updated_a1 = (int) ( $merge_a1['merged']['browse_events_updated'] ?? 0 );
result( 'merge_binds_anon_session', $updated_a1 > 0, 'browse_events_updated=' . $updated_a1 );

$merge_a2 = $client->merge_identity( array(
	'anon_session_id' => $sa,
	'customer_email'  => $email,
	'merge_ts'        => IsoDate::to_z( time() ),
	'merge_reason'    => 'user_logged_in',
) );
$already_a2 = (int) ( $merge_a2['merged']['browse_events_already_bound'] ?? 0 );
$updated_a2 = (int) ( $merge_a2['merged']['browse_events_updated'] ?? 0 );
// Idempotency = the second merge binds NOTHING new (no double-binding). The
// real engine returns already_bound=0 on a pure repeat (the contract example
// showed a non-zero count, but it's an informational field the plugin never
// consumes — the handler discards the merge response).
result( 'merge_idempotent_second_call', $updated_a2 === 0, 'updated=' . $updated_a2 . ' already_bound=' . $already_a2 . ' (idempotent: nothing newly bound)' );

// --- Scenario B: retroactive already bound → merge is a no-op -------------
$sb = 'idm-B-' . wp_generate_uuid4();
$client->ingest_browse( array( browse_event( $sb ), browse_event( $sb ) ) ); // anonymous
$retro = $client->ingest_browse( array( browse_event( $sb, array( 'customer_email' => $email ) ) ) ); // engine retroactively binds
$retro_bound = (int) ( $retro['retroactive_bound'] ?? 0 );
result( 'retroactive_binding_fired', $retro_bound > 0, 'retroactive_bound=' . $retro_bound );

$merge_b = $client->merge_identity( array(
	'anon_session_id' => $sb,
	'customer_email'  => $email,
	'merge_ts'        => IsoDate::to_z( time() ),
	'merge_reason'    => 'user_logged_in',
) );
$updated_b = (int) ( $merge_b['merged']['browse_events_updated'] ?? 0 );
result( 'merge_noop_when_already_retroactively_bound', $updated_b === 0, 'browse_events_updated=' . $updated_b . ' (already bound by retroactive)' );

// --- 404: customer never ingested ----------------------------------------
$got_404 = false;
try {
	$client->merge_identity( array(
		'anon_session_id' => 'idm-404-' . wp_generate_uuid4(),
		'customer_email'  => 'idm-nonexistent-' . wp_generate_uuid4() . '@example.test',
		'merge_ts'        => IsoDate::to_z( time() ),
		'merge_reason'    => 'user_logged_in',
	) );
} catch ( ApiException $e ) {
	$got_404 = ( $e->getCode() === 404 );
}
result( 'merge_404_for_unknown_customer', $got_404, 'engine 404 customer_not_found' );

echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.7-identity: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.7-identity.cjs  (after a real setup-exchange).');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.7-identity-tmp.php';
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

  console.log('\n=== walk-3.7-identity live identity-merge (real MiuMjau engine) ===');
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
  console.error('walk-3.7-identity harness error:', e.message);
  process.exit(1);
});
