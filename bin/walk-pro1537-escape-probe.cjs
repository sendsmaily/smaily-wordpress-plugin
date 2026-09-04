/**
 * PRO-1537 live escape-probe — determines empirically whether Smaily's own
 * `message/send.php` merge-tag substitution HTML-escapes `context` values on
 * its own, or passes them through raw. This is the "unverified external
 * behavior" the v3.9.0 security audit flagged (docs/audits/
 * SECURITY_DELTA_2026-07-23.md) and PRO-1537 then mitigated CLIENT-side by
 * escaping `TransactionalPayloadBuilder`'s merge-tag fields
 * (htmlspecialchars, ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML401) — but never
 * confirmed what Smaily itself does. If Smaily ALSO escapes, our new
 * client-side escape() double-encodes and customer-visible transactional
 * emails would show literal `&lt;...&gt;` text instead of the merchant's
 * real name — a display regression worth knowing about.
 *
 * Deliberately bypasses the plugin entirely — this is not a
 * `Smaily\Connect\Smaily\Client::send_message()` call, it is a raw
 * `POST /api/message/send.php` built by hand, sending RAW (unescaped, and in
 * one case pre-escaped) probe values on the wire. The point is to observe
 * SMAILY's behavior, not ours; going through the plugin's now-escaping
 * TransactionalPayloadBuilder would hide exactly the thing being measured.
 *
 * Wire shape confirmed against both the plugin's own Client::send_message()
 * (includes/Smaily/Client.php) and the unofficial docs
 * (reference/messages.md in erkkimarkus/smaily-api-docs-unofficial):
 * POST JSON `{autoresponder_id, to:[email], context:{...}}`, HTTP Basic
 * auth, response `{code, message, message_ids}` (101 = OK). `autoresponder_id`
 * MUST reference an existing, ACTIVE, SINGLE-SECTION workflow in the
 * smailydemo account — message/send.php renders that workflow's stored
 * template, it does not carry inline subject/body content itself, and a
 * `101` response is NOT proof of delivery (see the docs' gotchas) — the
 * point of this script is only to fire the probe; a human inspects the
 * received raw HTML afterwards.
 *
 * SAFETY: refuses to run unless the credentials file's subdomain is EXACTLY
 * "smailydemo" (this probe must never reach MiuMjau or any production
 * account). Recipient is hard-coded to erkki@smaily.com — one message, not a
 * batch. Credentials are read from a JSON file and NEVER printed/logged; the
 * file path is the only thing that touches the command line.
 *
 * Does NOT touch smly_rec_* options (that's the rec-engine connection, a
 * wholly separate account/table from this Smaily-marketing-API send) — no
 * snapshot guard needed (CLAUDE.md's PRO-1256 guard note).
 *
 * Run:
 *   node bin/walk-pro1537-escape-probe.cjs <autoresponder_id>
 *
 * Credentials file (create first, JSON, mode 600 recommended):
 *   /tmp/smaily_demo_api_creds.json
 *   { "subdomain": "smailydemo", "username": "...", "password": "..." }
 *
 * <autoresponder_id> must be an ACTIVE, SINGLE-SECTION workflow in the
 * smailydemo account whose template body contains the merge tags
 * {{first_name}}, {{last_name}}, {{shipping_method}} somewhere visible
 * (e.g. a line like "Hi {{first_name}} {{last_name}}, your order ships via
 * {{shipping_method}}."). Create it once in the smailydemo Smaily UI
 * (Automation → new single-section workflow → note its numeric id from the
 * URL or the workflow list) and pass that id as the CLI argument.
 */
'use strict';

const fs = require('fs');
const https = require('https');

const CRED_FILE = process.env.CRED_FILE || '/tmp/smaily_demo_api_creds.json';
const RECIPIENT = 'erkki@smaily.com';

// Probe values — deliberately RAW on the wire (no client-side escaping):
//   1. first_name  — an unescaped HTML tag (does Smaily strip/escape it?)
//   2. last_name    — apostrophe + ampersand (the two characters
//      htmlspecialchars() targets besides <>; also a JSON-encoding stress
//      case)
//   3. shipping_method — the ALREADY-escaped literal our own
//      TransactionalPayloadBuilder::escape() would now produce for raw
//      "<i>pre-escaped</i>" input. If Smaily's OWN substitution also
//      escapes, this value re-escapes AGAIN and the customer sees literal
//      "&lt;i&gt;..." text — the double-escape regression this probe exists
//      to catch.
const CONTEXT = {
  first_name: '<b>probe-bold</b>',
  last_name: "O'Brien & Sons",
  shipping_method: '&lt;i&gt;pre-escaped&lt;/i&gt;',
  // 4. subject — the workflow's SUBJECT field is set to the bare merge tag
  //    {{subject}}; if the received email's subject shows this value, the
  //    subject line substitutes context merge tags like the body does (a
  //    sender can take over the whole subject per-send).
  subject: 'Probe-subject <b>subj</b> & Co',
};

function fail(msg) {
  console.error(`walk-pro1537-escape-probe: ${msg}`);
  process.exit(1);
}

function loadCreds() {
  if (!fs.existsSync(CRED_FILE)) {
    fail(
      `credentials file not found at ${CRED_FILE}.\n` +
        '  Create it (JSON, keep it out of git) with:\n' +
        '    {"subdomain":"smailydemo","username":"<smailydemo username>","password":"<smailydemo password>"}\n' +
        '  Then re-run: node bin/walk-pro1537-escape-probe.cjs <autoresponder_id>'
    );
  }
  let parsed;
  try {
    parsed = JSON.parse(fs.readFileSync(CRED_FILE, 'utf8'));
  } catch (e) {
    fail(`credentials file at ${CRED_FILE} is not valid JSON (${e.message}).`);
  }
  const { subdomain, username, password } = parsed;
  if (!subdomain || !username || !password) {
    fail(`credentials file at ${CRED_FILE} is missing subdomain/username/password.`);
  }
  return { subdomain, username, password };
}

function postMessageSend(subdomain, username, password, autoresponderId) {
  const body = JSON.stringify({
    autoresponder_id: autoresponderId,
    to: [RECIPIENT],
    context: CONTEXT,
  });

  const options = {
    hostname: `${subdomain}.sendsmaily.net`,
    path: '/api/message/send.php',
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64'),
    },
  };

  return new Promise((resolve, reject) => {
    const req = https.request(options, (res) => {
      let raw = '';
      res.on('data', (chunk) => (raw += chunk));
      res.on('end', () => resolve({ status: res.statusCode, raw }));
    });
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

(async () => {
  const autoresponderId = process.argv[2];
  if (!autoresponderId || !/^\d+$/.test(autoresponderId)) {
    fail(
      'missing/invalid <autoresponder_id> argument.\n' +
        '  Usage: node bin/walk-pro1537-escape-probe.cjs <autoresponder_id>\n' +
        '  <autoresponder_id> must be an ACTIVE, SINGLE-SECTION workflow already\n' +
        '  created in the smailydemo account whose template body contains the\n' +
        '  merge tags {{first_name}}, {{last_name}}, {{shipping_method}}\n' +
        '  (e.g. "Hi {{first_name}} {{last_name}}, ships via {{shipping_method}}.").\n' +
        '  Create it once in the smailydemo Smaily UI, then pass its numeric id.'
    );
  }

  const { subdomain, username, password } = loadCreds();

  // SAFETY GATE — sandbox-only, mirrors every other walk's
  // sandbox_tenant_not_production gate.
  if (subdomain !== 'smailydemo') {
    fail(
      `credentials subdomain is NOT "smailydemo" (refusing to run) — this probe\n` +
        '  must only ever target the smailydemo sandbox account, never a production one.'
    );
  }

  console.log('=== walk-pro1537-escape-probe — live Smaily message/send.php (smailydemo sandbox) ===');
  console.log(`  sandbox gate: OK (subdomain=smailydemo)`);
  console.log(`  recipient: ${RECIPIENT}`);
  console.log(`  autoresponder_id: ${autoresponderId}`);
  console.log('  context (raw, unescaped by us):');
  for (const [k, v] of Object.entries(CONTEXT)) {
    console.log(`    ${k} = ${JSON.stringify(v)}`);
  }

  let result;
  try {
    result = await postMessageSend(subdomain, username, password, Number(autoresponderId));
  } catch (e) {
    fail(`request failed: ${e.message}`);
  }

  let parsedBody = null;
  try {
    parsedBody = JSON.parse(result.raw);
  } catch {
    // leave null — printed raw below
  }

  console.log(`\n  HTTP status: ${result.status}`);
  if (parsedBody) {
    console.log(`  Smaily code: ${parsedBody.code ?? '(none)'}  message: ${parsedBody.message ?? '(none)'}`);
    if (parsedBody.message_ids) {
      console.log(`  message_ids: ${JSON.stringify(parsedBody.message_ids)}`);
    }
  } else {
    console.log(`  raw response body: ${result.raw}`);
  }

  const sentOk = result.status === 200 && parsedBody && Number(parsedBody.code) === 101;
  console.log(`\n${sentOk ? 'SEND ACCEPTED (code 101)' : 'SEND NOT CONFIRMED — see status/code above'}`);
  console.log(
    '  Reminder (per the unofficial docs): code 101 + a message_id is NOT proof of\n' +
      '  delivery — a malformed merge tag can still return 101 with an empty send\n' +
      '  log. Check the message action log or just the inbox.'
  );

  console.log('\n=== What to look for in the RECEIVED email\'s raw HTML source ===');
  console.log(
    '  1. first_name probe ("<b>probe-bold</b>"):\n' +
      '     - Rendered as an actual BOLD "probe-bold" in the email  => Smaily does NOT\n' +
      '       escape merge-tag values (our own escaping is necessary; no double-escape risk).\n' +
      '     - Shown as literal plain text "<b>probe-bold</b>" (tag visible, not bold)\n' +
      '       => Smaily itself HTML-escapes merge-tag values.\n'
  );
  console.log(
    "  2. last_name probe (\"O'Brien & Sons\"):\n" +
      '     - Displays correctly as O\'Brien & Sons => apostrophe/ampersand pass through\n' +
      '       cleanly either raw or via a normal single-escape.\n' +
      '     - Displays as O&#039;Brien &amp; Sons (entities visible as text, not decoded)\n' +
      '       => same double-escape signal as probe 3 below.\n'
  );
  console.log(
    '  3. shipping_method probe ("&lt;i&gt;pre-escaped&lt;/i&gt;" — this is what our\n' +
      "     plugin's OWN escape() now produces for raw \"<i>pre-escaped</i>\" input):\n" +
      '     - Received email shows italic "pre-escaped" text (i.e. Smaily decoded the\n' +
      '       entities and rendered the tag) => unlikely, but would mean Smaily HTML-\n' +
      '       decodes context values before substitution.\n' +
      '     - Received email shows the literal text "<i>pre-escaped</i>" (single-decoded\n' +
      '       back to the tag text, not bold/italic, not further escaped)\n' +
      '       => Smaily passes context through RAW (matches probe 1\'s "not escaped" case)\n' +
      '       — our own PRO-1537 escaping is the ONLY layer and is correct as shipped.\n' +
      '     - Received email shows the literal text "&lt;i&gt;pre-escaped&lt;/i&gt;" AGAIN\n' +
      '       (i.e. the entities themselves visible as text, double-encoded)\n' +
      '       => Smaily ALSO escapes on its side — our PRO-1537 fix double-escapes,\n' +
      '       and any HTML-bearing merchant input now displays as visible entity\n' +
      '       gibberish instead of plain text. This is the regression to flag.\n'
  );
  console.log(
    '  Probes 1 and 3 should agree (both "escaped" or both "not escaped") — if they\n' +
      "  disagree, note it exactly; that's a more interesting finding than either answer alone."
  );

  process.exit(sentOk ? 0 : 1);
})();
