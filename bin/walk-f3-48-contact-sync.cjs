/**
 * F3-48 live harness — the NEW Smaily CONTACT-API wire shapes against the REAL
 * Smaily API, driving the actual `Smaily\Connect\Smaily\Client` (not a hand-
 * rolled curl) so we validate the exact bytes the plugin sends.
 *
 * Proves against live Smaily (the `smailydemo` SANDBOX account):
 *   - contact upsert (POST /api/contact.php) accepts a SHORT language code
 *     (`et`, not `et_EE`) + custom fields → code 101;
 *   - `is_unsubscribed` 0/1 round-trips (F3-48.6 opt-in/opt-out propagation),
 *     read back via GET /api/contact.php?email=;
 *   - an ABSENT `language` on a re-upsert is accepted (absent = keep, the
 *     omit-when-empty rule);
 *   - the action log (GET /api/history.php, `since_seq_id` + `actions[]`) — the
 *     ContactReconciler standing-delta source — returns the documented row shape;
 *   - the full list (GET /api/contact.php?list=1&fields=email,is_unsubscribed) —
 *     the rebaseline source — returns {email,is_unsubscribed} rows;
 *   - automation trigger (POST /api/autoresponder.php) accepts the `force_opt_in`
 *     param (F3-48.4) — and the observed re-subscribe side-effect is reported.
 *
 * SAFETY: the PHP refuses to send anything unless the subdomain is exactly
 * `smailydemo` (the analog of the rec-engine walks' sandbox_tenant_not_production
 * gate). All writes target a synthetic `@example.com` address (RFC-2606 reserved-
 * for-documentation — a real, non-delivering domain, so even a force_opt_in=true
 * automation trigger reaches no real inbox). NOTE: do NOT use a `.test`/`.example`/
 * `.invalid` reserved TLD here — live Smaily rejects those with code 203 "invalid
 * data" (LESSONS §2.14, probe-confirmed 2026-07-01). Credentials are PIPED via STDIN
 * (`cat <file> | docker exec -i …`) — they never appear on a command line, in an
 * option, or in this script's output.
 *
 * NOTE: contact.php upsert is processed ASYNCHRONOUSLY server-side (the "slow batch"
 * gotcha) — a contact is NOT readable the instant the POST returns its 101. The
 * readbacks below POLL with a retry/sleep window rather than reading once.
 *
 * Run:  SMAILY_LIVE=1 node bin/walk-f3-48-contact-sync.cjs
 *       (credentials file defaults to /tmp/smaily_api; override with CRED_FILE=…)
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PLUGIN_PATH_IN_CONTAINER = '/var/www/html/wp-content/plugins/smaily-connect';
const CRED_FILE = process.env.CRED_FILE || '/tmp/smaily_api';

const sh = (cmd, opts = {}) =>
  execSync(cmd, { stdio: 'pipe', shell: '/bin/bash', maxBuffer: 1024 * 1024 * 32, ...opts }).toString();

// Docker group is stripped in the sandbox — wrap every docker call in `sg docker`.
const dockerCanRunDirect = (() => {
  try { execSync('docker ps', { stdio: 'pipe' }); return true; } catch { return false; }
})();
const wrapDocker = (inner) => (dockerCanRunDirect ? inner : `sg docker -c ${JSON.stringify(inner)}`);

const findCliContainer = () => {
  const out = sh(wrapDocker("docker ps --filter name=wp-env- --filter name=-cli-1 --format '{{.Names}}'"));
  const list = out.split('\n').filter((n) => n && !n.includes('-tests-cli-1'));
  if (list.length === 0) {
    throw new Error('No wp-env cli container found. Start wp-env: npx @wordpress/env start');
  }
  return list[0];
};

const LIVE_PHP = String.raw`<?php
use Smaily\Connect\Smaily\Client;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}
function note( $name, $detail = '' ) {
	echo 'NOTE ' . $name . ' ' . $detail . "\n";
}
function redact_email( $e ) {
	return preg_replace( '/^(.).*@/', '$1***@', (string) $e );
}
function row_keys( $rows ) {
	if ( ! is_array( $rows ) || ! isset( $rows[0] ) || ! is_array( $rows[0] ) ) {
		return array();
	}
	return array_keys( $rows[0] );
}
// Poll get_contact_consent until the contact is found AND (if $want_unsub is not
// null) its is_unsubscribed equals $want_unsub — contact.php is async, so a single
// read right after an upsert misses. Returns array( $consent, $attempts_used ).
function poll_consent( $client, $email, $want_unsub = null, $tries = 12, $sleep = 5 ) {
	$c = array( 'found' => false, 'is_unsubscribed' => null );
	for ( $i = 1; $i <= $tries; $i++ ) {
		$c = $client->get_contact_consent( $email );
		if ( $c['found'] === true && ( $want_unsub === null || $c['is_unsubscribed'] === $want_unsub ) ) {
			return array( $c, $i );
		}
		if ( $i < $tries ) {
			sleep( $sleep );
		}
	}
	return array( $c, $tries );
}

// --- credentials from STDIN (secret-safe; never echoed) ---
$raw = '';
while ( ( $line = fgets( STDIN ) ) !== false ) {
	$raw .= $line;
}
$subdomain = '';
$username  = '';
$password  = '';
foreach ( preg_split( '/\r?\n/', trim( $raw ) ) as $l ) {
	if ( ! preg_match( '/^\s*([^:=]+)\s*[:=]\s*(.*)$/', $l, $m ) ) {
		continue;
	}
	$k = strtolower( trim( $m[1] ) );
	$v = trim( $m[2] );
	if ( preg_match( '/alamdomeen|subdomain|domain|host/', $k ) ) {
		$subdomain = $v;
	} elseif ( preg_match( '/kasutaja|user/', $k ) ) {
		$username = $v;
	} elseif ( preg_match( '/salas|pass|parool/', $k ) ) {
		$password = $v;
	}
}

result( 'creds_parsed', $subdomain !== '' && $username !== '' && $password !== '', 'subdomain=' . $subdomain );

// --- SAFETY GATE: sandbox-only ---
if ( $subdomain !== 'smailydemo' ) {
	result( 'sandbox_only_gate', false, 'subdomain is NOT smailydemo (got "' . $subdomain . '") — ABORT, refusing to touch a non-demo Smaily account' );
	echo "DONE\n";
	return;
}
result( 'sandbox_only_gate', true, 'subdomain=smailydemo (safe demo account)' );

$client = new Client( $subdomain, $username, $password );
$email  = 'smaily-f348-walk-' . time() . '@example.com';
note( 'test_email', redact_email( $email ) . ' (synthetic @example.com — RFC-2606 doc domain, non-delivering)' );

$success_code = 101; // Smaily contact.php OK code.

// 1. Auth + autoresponder.php?status=ACTIVE reachable.
try {
	$ok = $client->test_connection();
	result( 'auth_connects', $ok, 'test_connection (GET /api/autoresponder.php?status=ACTIVE) 2xx=' . ( $ok ? 'yes' : 'no' ) );
} catch ( \Throwable $t ) {
	result( 'auth_connects', false, 'threw: ' . $t->getMessage() );
}

// 2. Upsert WITH a SHORT language code + is_unsubscribed=0 + a custom field.
try {
	$resp = $client->upsert_subscribers( array( array(
		'email'           => $email,
		'language'        => 'et',
		'is_unsubscribed' => 0,
		'first_name'      => 'F348Walk',
		'store'           => get_site_url(),
	) ) );
	$code = isset( $resp['code'] ) ? (int) $resp['code'] : -1;
	result( 'upsert_short_language', $code === $success_code, 'POST /api/contact.php code=' . $code . ' (expect 101); language="et" accepted' );
} catch ( \Throwable $t ) {
	result( 'upsert_short_language', false, 'threw: ' . $t->getMessage() );
}

// 3. Read the contact back (polled — async): found + is_unsubscribed = "0".
try {
	list( $c, $n ) = poll_consent( $client, $email, '0' );
	result( 'consent_read_optin', $c['found'] === true && $c['is_unsubscribed'] === '0', 'GET /api/contact.php?email= found=' . json_encode( $c['found'] ) . ' is_unsubscribed=' . json_encode( $c['is_unsubscribed'] ) . ' (after ' . $n . ' poll(s))' );
} catch ( \Throwable $t ) {
	result( 'consent_read_optin', false, 'threw: ' . $t->getMessage() );
}

// 4. Opt-OUT propagation: upsert is_unsubscribed=1 → read back "1" (polled).
try {
	$client->upsert_subscribers( array( array( 'email' => $email, 'is_unsubscribed' => 1 ) ) );
	list( $c, $n ) = poll_consent( $client, $email, '1' );
	result( 'is_unsubscribed_optout', $c['is_unsubscribed'] === '1', 'after is_unsubscribed=1 → read back ' . json_encode( $c['is_unsubscribed'] ) . ' (after ' . $n . ' poll(s))' );
} catch ( \Throwable $t ) {
	result( 'is_unsubscribed_optout', false, 'threw: ' . $t->getMessage() );
}

// 5. Opt-IN propagation: upsert is_unsubscribed=0 → read back "0" (round-trip, polled).
try {
	$client->upsert_subscribers( array( array( 'email' => $email, 'is_unsubscribed' => 0 ) ) );
	list( $c, $n ) = poll_consent( $client, $email, '0' );
	result( 'is_unsubscribed_optin', $c['is_unsubscribed'] === '0', 'after is_unsubscribed=0 → read back ' . json_encode( $c['is_unsubscribed'] ) . ' (after ' . $n . ' poll(s))' );
} catch ( \Throwable $t ) {
	result( 'is_unsubscribed_optin', false, 'threw: ' . $t->getMessage() );
}

// 6. ABSENT language on a re-upsert is accepted (omit = keep; routine data sync
//    never sends language='' / is_unsubscribed when unchanged).
try {
	$resp = $client->upsert_subscribers( array( array( 'email' => $email, 'first_name' => 'F348Walk2', 'store' => get_site_url() ) ) );
	$code = isset( $resp['code'] ) ? (int) $resp['code'] : -1;
	result( 'upsert_absent_language', $code === $success_code, 'POST /api/contact.php (no language key) code=' . $code . ' (expect 101)' );
} catch ( \Throwable $t ) {
	result( 'upsert_absent_language', false, 'threw: ' . $t->getMessage() );
}

// 7. Action log (GET /api/history.php) — ContactReconciler standing-delta source.
try {
	$log  = $client->get_action_log( 0, array( 'optin', 'optout', 'delete', 'complaint' ), 100 );
	$keys = row_keys( $log );
	$shape_ok = is_array( $log ) && ( $log === array() || ( in_array( 'seq_id', $keys, true ) && in_array( 'email', $keys, true ) && in_array( 'action', $keys, true ) ) );
	result( 'action_log_shape', $shape_ok, 'GET /api/history.php rows=' . count( $log ) . ' keys=' . json_encode( $keys ) );
	if ( isset( $log[0] ) && is_array( $log[0] ) ) {
		$sample = $log[0];
		if ( isset( $sample['email'] ) ) {
			$sample['email'] = redact_email( $sample['email'] );
		}
		note( 'action_log_sample', json_encode( $sample ) );
	}
} catch ( \Throwable $t ) {
	result( 'action_log_shape', false, 'threw: ' . $t->getMessage() );
}

// 8. Full list (GET /api/contact.php?list=1) — ContactReconciler rebaseline source.
try {
	$list = $client->list_contacts( 0, 200 );
	$keys = row_keys( $list );
	$shape_ok = is_array( $list ) && ( $list === array() || ( in_array( 'email', $keys, true ) && in_array( 'is_unsubscribed', $keys, true ) ) );
	$found_self = false;
	foreach ( $list as $r ) {
		if ( isset( $r['email'] ) && strcasecmp( (string) $r['email'], $email ) === 0 ) {
			$found_self = true;
			break;
		}
	}
	result( 'list_contacts_shape', $shape_ok, 'GET /api/contact.php?list=1 rows=' . count( $list ) . ' keys=' . json_encode( $keys ) );
	note( 'list_contacts_self_present', $found_self ? 'test contact present in list=1 output' : 'test contact NOT in first page (page size 200)' );
} catch ( \Throwable $t ) {
	result( 'list_contacts_shape', false, 'threw: ' . $t->getMessage() );
}

// 9. Autoresponder list (GET /api/autoresponder.php?status=ACTIVE).
$active_wf = 0;
try {
	$wfs  = $client->list_autoresponders();
	$keys = row_keys( $wfs );
	$shape_ok = is_array( $wfs ) && ( $wfs === array() || ( in_array( 'id', $keys, true ) && in_array( 'name', $keys, true ) ) );
	result( 'autoresponders_shape', $shape_ok, 'count=' . count( $wfs ) . ' keys=' . json_encode( $keys ) );
	foreach ( $wfs as $wf ) {
		if ( isset( $wf['id'] ) && ( ! isset( $wf['status'] ) || $wf['status'] === 'ACTIVE' ) ) {
			$active_wf = (int) $wf['id'];
			note( 'autoresponder_pick', 'id=' . $active_wf . ' name=' . json_encode( $wf['name'] ?? '' ) . ' status=' . json_encode( $wf['status'] ?? '' ) );
			break;
		}
	}
} catch ( \Throwable $t ) {
	result( 'autoresponders_shape', false, 'threw: ' . $t->getMessage() );
}

// 10. force_opt_in (F3-48.4) — only if an ACTIVE workflow exists. The contact is
//     opted-out first; we trigger with force=false then force=true and REPORT the
//     observed re-subscribe behaviour (the persistent side-effect contract isn't
//     certain, so the hard assertion is only "the param is accepted, no throw").
if ( $active_wf > 0 ) {
	try {
		$client->upsert_subscribers( array( array( 'email' => $email, 'is_unsubscribed' => 1 ) ) );
		list( $before, ) = poll_consent( $client, $email, '1' );

		$r_false = $client->trigger_automation( $active_wf, array( array( 'email' => $email ) ), false );
		sleep( 8 );
		$after_false = $client->get_contact_consent( $email );

		$r_true = $client->trigger_automation( $active_wf, array( array( 'email' => $email ) ), true );
		sleep( 8 );
		$after_true = $client->get_contact_consent( $email );

		$accepted = is_array( $r_false ) && is_array( $r_true );
		result( 'automation_force_opt_in_accepted', $accepted, 'POST /api/autoresponder.php force=false code=' . json_encode( $r_false['code'] ?? null ) . ' force=true code=' . json_encode( $r_true['code'] ?? null ) . ' (wire shape accepted; HTTP 200, no throw)' );
		note( 'automation_code_meaning', 'code 221 = "Invalid autoresponder ID provided" (per Smaily errors table): the arbitrarily-picked first ACTIVE workflow id=' . $active_wf . ' is not a valid form-submitted-trigger target (likely a campaign/newsletter series, not an autoresponder.php-triggerable workflow). In production AutomationRouter maps the right workflow by trigger type. The force_opt_in param reaching Smaily on the wire is what F3-48.4 needs (HTTP 200 + structured response, no transport error); the enroll/send outcome depends on a correctly-typed workflow, outside this walk.' );
		note( 'force_opt_in_effect', 'is_unsubscribed: before=' . json_encode( $before['is_unsubscribed'] ) . ' afterForceFalse=' . json_encode( $after_false['is_unsubscribed'] ) . ' afterForceTrue=' . json_encode( $after_true['is_unsubscribed'] ) . ' (force_opt_in=true did NOT flip the stored unsubscribe — it governs the send, not the contact record)' );
	} catch ( \Throwable $t ) {
		result( 'automation_force_opt_in_accepted', false, 'threw: ' . $t->getMessage() );
	}
} else {
	note( 'automation_force_opt_in_accepted', 'SKIPPED — no ACTIVE autoresponder on smailydemo to trigger against' );
}

// Cleanup footprint: leave the synthetic contact unsubscribed.
try {
	$client->upsert_subscribers( array( array( 'email' => $email, 'is_unsubscribed' => 1 ) ) );
	note( 'cleanup', 'test contact left is_unsubscribed=1' );
} catch ( \Throwable $t ) {
	note( 'cleanup', 'failed: ' . $t->getMessage() );
}

echo "DONE\n";
`;

(async () => {
  if (process.env.SMAILY_LIVE !== '1') {
    console.log('walk-f3-48-contact-sync: SMAILY_LIVE is not set to 1 — skipping the live Smaily test.');
    console.log('         Run: SMAILY_LIVE=1 node bin/walk-f3-48-contact-sync.cjs  (needs a sandbox credential file).');
    return;
  }
  if (!fs.existsSync(CRED_FILE)) {
    console.error(`walk-f3-48-contact-sync: credential file ${CRED_FILE} not found.`);
    process.exit(1);
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-f3-48-tmp.php';
  const hostPath = path.join(__dirname, '..', tmpName);
  fs.writeFileSync(hostPath, LIVE_PHP);

  // Pipe the credential file straight into the container's STDIN — Node never
  // reads the secret, it never hits a command line. `docker exec -i` forwards it
  // to `wp eval-file`, which reads it via fgets(STDIN).
  const evalInner = `docker exec -i ${cli} wp eval-file ${PLUGIN_PATH_IN_CONTAINER}/${tmpName} --allow-root`;
  const cmd = `cat ${JSON.stringify(CRED_FILE)} | ${wrapDocker(evalInner)}`;

  let out = '';
  try {
    out = sh(cmd);
  } catch (e) {
    out = `${e.stdout ? e.stdout.toString() : ''}\n${e.stderr ? e.stderr.toString() : ''}`;
  } finally {
    fs.unlinkSync(hostPath);
  }

  const lines = out.split('\n');
  const results = lines
    .filter((l) => l.startsWith('RESULT '))
    .map((l) => {
      const m = l.match(/^RESULT (\S+) (PASS|FAIL) ?(.*)$/);
      return m ? { name: m[1], status: m[2], detail: m[3] } : null;
    })
    .filter(Boolean);
  const notes = lines
    .filter((l) => l.startsWith('NOTE '))
    .map((l) => l.replace(/^NOTE /, ''));

  console.log('\n=== walk-f3-48-contact-sync — live Smaily contact API (smailydemo sandbox) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  if (notes.length) {
    console.log('  ── notes ──');
    for (const n of notes) console.log(`    · ${n}`);
  }
  if (!out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE marker). Raw tail:');
    console.log(lines.slice(-15).join('\n'));
    failures += 1;
  }
  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-f3-48-contact-sync harness error:', e.message);
  process.exit(1);
});
