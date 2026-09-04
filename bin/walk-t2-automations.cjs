/**
 * T2 live harness — engine-run automations config (contract v1.1.0 §11–§13)
 * against the REAL Smaily rec-engine (the "Smaily Connect test" SANDBOX
 * tenant, engine v1.1.0 with the automations_* endpoints-map keys).
 *
 * What this proves that the integration tests (mock engine) can't: the LIVE
 * engine's catalog shape (sector-filtered triggers with all 6 fields +
 * language_modes + docs), the PUT→GET round-trip with the engine-stamped
 * `configured_via='plugin'` (the brief's acceptance criterion), the v1.1.0
 * indexed 422 shape ({index?, trigger_key?, field, message}) for the two
 * canonical invalid bodies (enabled without automation_map.id; per_language
 * without fallback), and the 401 on a bad key — all THROUGH the plugin's
 * Client methods (automations_catalog / automations_config /
 * put_automations_config), not bare curls.
 *
 * Fail-closed cleanup: the walk's final act PUTs the touched trigger back to
 * enabled=false + test_mode=true and verifies via GET — the sandbox must not
 * be left with an enabled=true row carrying a placeholder workflow id.
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected SANDBOX tenant — the script
 * hard-aborts if the connected tenant is the production "MiuMjau" (never send
 * test data to production; the 2026-06-12 incident).
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
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$tenant    = $settings->tenant_name();

// SAFETY GATE: never touch the production tenant's automations config.
result( 'sandbox_tenant_not_production', $settings->is_connected() && $tenant !== 'MiuMjau', 'tenant=' . $tenant );
if ( ! $settings->is_connected() || $tenant === 'MiuMjau' ) {
	echo "ABORT_NOT_SANDBOX\n";
	return;
}

$client = new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 5 );

// ---------------------------------------------------------------
// 1. GET /automations/catalog (§11) — via Client::automations_catalog().
// ---------------------------------------------------------------
$catalog = null;
try {
	$catalog = $client->automations_catalog();
} catch ( \Throwable $e ) {
	result( 'catalog_get_200', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
	echo "DONE\n";
	return;
}

$triggers = ( isset( $catalog['triggers'] ) && is_array( $catalog['triggers'] ) ) ? $catalog['triggers'] : array();
$keys     = array_map( static fn( $t ) => (string) ( $t['key'] ?? '?' ), $triggers );
result( 'catalog_get_200', true, 'triggers=' . count( $triggers ) . ' keys=' . implode( ',', $keys ) );
result( 'catalog_triggers_nonempty', count( $triggers ) > 0, 'count=' . count( $triggers ) );

$field_names   = array( 'key', 'name_et', 'name_en', 'description_et', 'description_en', 'recipe_et' );
$fields_ok     = count( $triggers ) > 0;
$fields_detail = '';
foreach ( $triggers as $i => $t ) {
	foreach ( $field_names as $f ) {
		if ( ! isset( $t[ $f ] ) || ! is_string( $t[ $f ] ) || $t[ $f ] === '' ) {
			$fields_ok      = false;
			$fields_detail .= " trigger[$i].$f missing/empty;";
		}
	}
}
result( 'catalog_trigger_fields_complete', $fields_ok, $fields_ok ? 'all 6 fields non-empty on every trigger' : $fields_detail );

$modes = ( isset( $catalog['language_modes'] ) && is_array( $catalog['language_modes'] ) ) ? $catalog['language_modes'] : array();
result( 'catalog_language_modes', in_array( 'single', $modes, true ) && in_array( 'per_language', $modes, true ), 'language_modes=' . wp_json_encode( $modes ) );

$docs = isset( $catalog['docs'] ) ? (string) $catalog['docs'] : '';
result( 'catalog_docs_url', strpos( $docs, 'https://' ) === 0, 'docs=' . $docs );

if ( count( $triggers ) === 0 ) {
	echo "DONE\n";
	return;
}
$trigger_key = (string) $triggers[0]['key'];

// ---------------------------------------------------------------
// 2. GET /automations/config (§12) — initial state.
// ---------------------------------------------------------------
try {
	$initial = $client->automations_config();
	$rows    = ( isset( $initial['configs'] ) && is_array( $initial['configs'] ) ) ? $initial['configs'] : null;
	$summary = array_map(
		static fn( $r ) => sprintf(
			'%s enabled=%s test_mode=%s via=%s',
			(string) ( $r['trigger_key'] ?? '?' ),
			wp_json_encode( $r['enabled'] ?? null ),
			wp_json_encode( $r['test_mode'] ?? null ),
			(string) ( $r['configured_via'] ?? '?' )
		),
		$rows ?? array()
	);
	result( 'config_get_initial_200', is_array( $rows ), 'rows=' . count( $rows ?? array() ) . ( $summary ? ' [' . implode( ' | ', $summary ) . ']' : '' ) );
} catch ( \Throwable $e ) {
	result( 'config_get_initial_200', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------
// 3. PUT valid (§13) — one catalog trigger, all 8 fields, test-mode ON.
// ---------------------------------------------------------------
$valid_row = array(
	'trigger_key'    => $trigger_key,
	'enabled'        => true,
	'language_mode'  => 'single',
	'automation_map' => array( 'id' => '123' ),
	'cooldown_days'  => 7,
	'daily_cap'      => null,
	'test_mode'      => true,
	'test_emails'    => array( 'erkki@smaily.com' ),
);
try {
	$put = $client->put_automations_config( array( $valid_row ) );
	result( 'put_valid_200_upserted_1', ( $put['ok'] ?? false ) === true && (int) ( $put['upserted'] ?? -1 ) === 1, 'body=' . wp_json_encode( $put ) );
} catch ( \Throwable $e ) {
	result( 'put_valid_200_upserted_1', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------
// 4. GET round-trip — the row comes back with our values AND the
//    engine-stamped configured_via='plugin' (brief acceptance criterion).
// ---------------------------------------------------------------
$row = null;
try {
	$after = $client->automations_config();
	foreach ( ( $after['configs'] ?? array() ) as $r ) {
		if ( ( $r['trigger_key'] ?? '' ) === $trigger_key ) {
			$row = $r;
			break;
		}
	}
	$values_ok = is_array( $row )
		&& ( $row['enabled'] ?? null ) === true
		&& ( $row['language_mode'] ?? null ) === 'single'
		&& ( $row['automation_map']['id'] ?? null ) === '123'
		&& (int) ( $row['cooldown_days'] ?? -1 ) === 7
		&& array_key_exists( 'daily_cap', $row ) && $row['daily_cap'] === null
		&& ( $row['test_mode'] ?? null ) === true
		&& ( $row['test_emails'] ?? null ) === array( 'erkki@smaily.com' );
	result( 'roundtrip_row_values', $values_ok, 'row=' . wp_json_encode( $row ) );
	result( 'roundtrip_configured_via_plugin', is_array( $row ) && ( $row['configured_via'] ?? '' ) === 'plugin', 'configured_via=' . (string) ( $row['configured_via'] ?? '<missing>' ) . ' updated_at=' . (string) ( $row['updated_at'] ?? '<missing>' ) );
} catch ( \Throwable $e ) {
	result( 'roundtrip_row_values', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
	result( 'roundtrip_configured_via_plugin', false, 'see above' );
}

// ---------------------------------------------------------------
// 5. PUT invalid A — enabled=true, single, automation_map WITHOUT id
//    → 422 with the v1.1.0 INDEXED errors[] ({index, field, message}).
// ---------------------------------------------------------------
$invalid_a              = $valid_row;
$invalid_a['automation_map'] = new stdClass(); // {} on the wire — key present, id missing.
try {
	$client->put_automations_config( array( $invalid_a ) );
	result( 'put_invalid_missing_id_422_indexed', false, 'engine accepted an enabled row without automation_map.id' );
} catch ( ApiException $e ) {
	$errors  = $e->errors();
	$indexed = false;
	foreach ( $errors as $err ) {
		if ( is_array( $err ) && array_key_exists( 'index', $err ) && (int) $err['index'] === 0
			&& isset( $err['field'] ) && is_string( $err['field'] ) && $err['field'] !== '' ) {
			$indexed = true;
			break;
		}
	}
	result( 'put_invalid_missing_id_422_indexed', (int) $e->getCode() === 422 && $indexed, 'http=' . (int) $e->getCode() . ' errors=' . wp_json_encode( $errors ) );
} catch ( \Throwable $e ) {
	result( 'put_invalid_missing_id_422_indexed', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------
// 6. PUT invalid B — per_language map without the required fallback → 422.
// ---------------------------------------------------------------
$invalid_b                   = $valid_row;
$invalid_b['language_mode']  = 'per_language';
$invalid_b['automation_map'] = array(
	'et' => '12',
	'en' => '13',
); // no "fallback" — required when enabled (§13).
try {
	$client->put_automations_config( array( $invalid_b ) );
	result( 'put_invalid_no_fallback_422', false, 'engine accepted per_language without fallback' );
} catch ( ApiException $e ) {
	result( 'put_invalid_no_fallback_422', (int) $e->getCode() === 422 && count( $e->errors() ) > 0, 'http=' . (int) $e->getCode() . ' errors=' . wp_json_encode( $e->errors() ) );
} catch ( \Throwable $e ) {
	result( 'put_invalid_no_fallback_422', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------
// 7. Invalid API key → 401 (through the plugin Client, same code path
//    the proxy's api_key_rejected mapping sits on).
// ---------------------------------------------------------------
$bad_client = new Client( 'sk_invalid_walk_t2_automations', $settings->base_url(), $settings->endpoints(), 5 );
try {
	$bad_client->automations_catalog();
	result( 'invalid_key_401', false, 'engine accepted an invalid key' );
} catch ( ApiException $e ) {
	result( 'invalid_key_401', (int) $e->getCode() === 401, 'http=' . (int) $e->getCode() . ' code=' . $e->error_code() );
} catch ( \Throwable $e ) {
	result( 'invalid_key_401', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------
// 8. FAIL-CLOSED CLEANUP — put the touched trigger back to enabled=false
//    (test_mode stays true) and verify: the sandbox must not keep an
//    enabled=true row with the placeholder workflow id 123.
// ---------------------------------------------------------------
$cleanup_row            = $valid_row;
$cleanup_row['enabled'] = false;
try {
	$put2 = $client->put_automations_config( array( $cleanup_row ) );
	result( 'cleanup_put_disabled_200', ( $put2['ok'] ?? false ) === true, 'body=' . wp_json_encode( $put2 ) );
} catch ( \Throwable $e ) {
	result( 'cleanup_put_disabled_200', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}
try {
	$final     = $client->automations_config();
	$final_row = null;
	foreach ( ( $final['configs'] ?? array() ) as $r ) {
		if ( ( $r['trigger_key'] ?? '' ) === $trigger_key ) {
			$final_row = $r;
			break;
		}
	}
	result( 'cleanup_verified_disabled', is_array( $final_row ) && ( $final_row['enabled'] ?? null ) === false && ( $final_row['test_mode'] ?? null ) === true, 'row=' . wp_json_encode( $final_row ) );
} catch ( \Throwable $e ) {
	result( 'cleanup_verified_disabled', false, 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() );
}

echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-t2-automations: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Connect a SANDBOX tenant first, then: RECENGINE_LIVE=1 node bin/walk-t2-automations.cjs');
    return;
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-t2-automations-tmp.php';
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

  console.log('\n=== walk-t2-automations: §11–§13 automations config against the real engine (sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  if (out.includes('ABORT_NOT_SANDBOX')) {
    console.log('  ✗ ABORTED — not connected to a sandbox tenant (refusing to touch production).');
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
  console.error('walk-t2-automations harness error:', e.message);
  process.exit(1);
});
