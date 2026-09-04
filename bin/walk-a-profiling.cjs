/**
 * Sub-PR (a).2 live harness — profiling-consent wiring end-to-end against the
 * REAL Smaily API (contact write/read-back) + the REAL rec-engine (§10 opt-out +
 * the beacon proxy), exercised through the plugin's WIRED code (not the probe
 * script): Client::write_profiling_consent / get_contact_consent, ProfilingConsent
 * (opt_out/opt_in/may_profile), the §10 customer_opt_out, and the BeaconEndpoint
 * profiling gate via rest_do_request.
 *
 * Proves:
 *   - write → read-back round-trip (opt-in state stored + read back);
 *   - the opt-out enforcement rule (is_allowed) on the real read-back;
 *   - opt-out → §10 engine opt-out accepted;
 *   - beacon-stop: an opted-out contact's browse event is dropped, never forwarded;
 *   - opt back in restores the state.
 *
 * Needs: Smaily creds at /tmp/smaily_credentials (Alamdomeen/Kasutaja/Salasõna) +
 * a connected rec-engine tenant. Gated on RECENGINE_LIVE=1. Test emails use
 * @example.com (Smaily rejects .test on write — probe finding).
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
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$lines = @file( '/tmp/smaily_credentials', FILE_IGNORE_NEW_LINES );
$val = static function ( $line ) {
	$p = strpos( (string) $line, ':' );
	return $p === false ? trim( (string) $line ) : trim( substr( (string) $line, $p + 1 ) );
};
$sub  = is_array( $lines ) && isset( $lines[0] ) ? $val( $lines[0] ) : '';
$user = is_array( $lines ) && isset( $lines[1] ) ? $val( $lines[1] ) : '';
$pass = is_array( $lines ) && isset( $lines[2] ) ? $val( $lines[2] ) : '';

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
result( 'connected', $settings->is_connected() && $sub !== '', 'tenant=' . $settings->tenant_name() );

$smaily    = new SmailyClient( $sub, $user, $pass );
$rec_bs    = $bootstrap;
$profiling = new ProfilingConsent(
	$settings,
	static function () use ( $smaily ) { return $smaily; },
	static function () use ( $rec_bs ) { return $rec_bs->rec_client(); }
);

$email = 'consent-walk-' . time() . '@example.com';
$key   = 'smly_profiling_' . md5( strtolower( $email ) );
delete_transient( $key );

// --- 1. write opt-in, read it back (wired Client methods, real Smaily) ----
$w = $smaily->write_profiling_consent( $email, true, IsoDate::to_z( time() ) );
result( 'write_opt_in', isset( $w['code'] ) && (int) $w['code'] === 101, 'code=' . json_encode( $w['code'] ?? null ) );

sleep( 2 );
$c = $smaily->get_contact_consent( $email );
result( 'readback_round_trip', $c['found'] && $c['smaily_rec_profiling'] === '1' && $c['is_unsubscribed'] === '0',
	'found=' . json_encode( $c['found'] ) . ' profiling=' . json_encode( $c['smaily_rec_profiling'] ) . ' unsub=' . json_encode( $c['is_unsubscribed'] ) );

// --- 2. enforcement rule + resolver on the real read-back -----------------
result( 'rule_opt_in_profiles', ProfilingConsent::is_allowed( $c['is_unsubscribed'], $c['smaily_rec_profiling'] ) === true, 'is_allowed(0,1)=true' );
delete_transient( $key );
result( 'may_profile_true_when_in', $profiling->may_profile( $email ) === true, 'fresh read-back resolves profile' );

// --- 3. opt out → Smaily=0 + engine §10 -----------------------------------
$profiling->opt_out( $email );
sleep( 2 );
$c2 = $smaily->get_contact_consent( $email );
result( 'opt_out_written_to_smaily', $c2['smaily_rec_profiling'] === '0', 'profiling=' . json_encode( $c2['smaily_rec_profiling'] ) );

// Observe the engine accepting the §10 opt-out (the same call opt_out makes).
try {
	$o = $rec_bs->rec_client()->customer_opt_out( $email, array( 'opt_out' => true, 'reason' => 'profiling_consent', 'opted_out_at' => IsoDate::to_z( time() ) ) );
	result( 'engine_opt_out_accepted', ! empty( $o['ok'] ) || array_key_exists( 'opt_out_status', $o ), 'status=' . json_encode( $o['opt_out_status'] ?? null ) );
} catch ( \Throwable $e ) {
	result( 'engine_opt_out_accepted', false, 'threw: ' . $e->getMessage() );
}

result( 'may_profile_false_when_out', $profiling->may_profile( $email ) === false, 'cache reflects opt-out' );

// --- 4. beacon-stop: opted-out browse event is dropped, not forwarded -----
update_option( 'smly_plus_rec_track_browsing', true );
$req = new WP_REST_Request( 'POST', '/smaily-connect/v1/relay' );
$req->set_body_params( array( 'events' => array( array(
	'event_id' => 'walk-' . time(), 'event_type' => 'product_view', 'session_id' => 'walk-s', 'event_ts' => IsoDate::to_z( time() ), 'customer_email' => $email,
) ) ) );
$resp = rest_do_request( $req );
$bd   = $resp->get_data();
result( 'beacon_stop_drops_opted_out', (int) $resp->get_status() === 200 && (int) ( $bd['processed'] ?? -1 ) === 0,
	'http=' . $resp->get_status() . ' processed=' . json_encode( $bd['processed'] ?? null ) );

// --- 5. opt back in restores the state ------------------------------------
$profiling->opt_in( $email );
sleep( 2 );
$c3 = $smaily->get_contact_consent( $email );
result( 'opt_in_restores', $c3['smaily_rec_profiling'] === '1', 'profiling=' . json_encode( $c3['smaily_rec_profiling'] ) );

delete_transient( $key );
echo "DONE walk_email=$email\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-a-profiling: RECENGINE_LIVE not set to 1 — skipping the live test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-a-profiling.cjs  (needs /tmp/smaily_credentials + a connected tenant).');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-a-profiling-tmp.php';
  const hostPath = path.join(__dirname, '..', tmpName);
  fs.writeFileSync(hostPath, LIVE_PHP);

  let out = '';
  try {
    runDocker(['cp', '/tmp/smaily_credentials', `${cli}:/tmp/smaily_credentials`]);
    out = runDocker(['exec', cli, 'wp', 'eval-file', `${PLUGIN_PATH_IN_CONTAINER}/${tmpName}`, '--allow-root']);
  } catch (e) {
    out = `${e.stdout ? e.stdout.toString() : ''}\n${e.stderr ? e.stderr.toString() : ''}`;
  } finally {
    try { runDocker(['exec', cli, 'rm', '-f', '/tmp/smaily_credentials']); } catch { /* best-effort */ }
    fs.unlinkSync(hostPath);
  }

  const results = out.split('\n')
    .filter((l) => l.startsWith('RESULT '))
    .map((l) => {
      const m = l.match(/^RESULT (\S+) (PASS|FAIL) ?(.*)$/);
      return m ? { name: m[1], status: m[2], detail: m[3] } : null;
    })
    .filter(Boolean);

  console.log('\n=== walk-a-profiling: profiling consent end-to-end (real Smaily + MiuMjau) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  if (!out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE). Raw tail:');
    console.log(out.split('\n').slice(-12).join('\n'));
    failures += 1;
  }
  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-a-profiling harness error:', e.message);
  process.exit(1);
});
