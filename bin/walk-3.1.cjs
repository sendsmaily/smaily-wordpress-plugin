/**
 * Sub-PR 3.1 chromium harness — Step 4 rec-engine connect / ping /
 * disconnect cycle from the browser side.
 *
 * Strategy: instead of standing up a mock engine reachable from
 * both the host and the wp-env container, this harness seeds the
 * connected state directly via wp-cli for the "happy path" branch,
 * then drives the disconnect + reconnect flow against the REST
 * endpoint and confirms the React UI mirrors server state.
 *
 * The integration suite already exercises the engine round-trip
 * against the mock server (RecEngineSetupExchangeTest +
 * RecEnginePingTest); this harness verifies the browser layer
 * (Step 4 UI states, banner text, button enables, boot-payload
 * hydration after reload).
 */
const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const { guardSmlyRec } = require('./lib-smly-snapshot.cjs');

// PRO-1256: this walk seeds/scrubs the dev site's smly_rec_* connection
// options (seedConnectedState / seedDisconnectedState below). Snapshot the
// real connection now and restore it on process exit — even on a crash —
// so the walk can't leave the dev site's engine connection scrubbed
// (CLAUDE.md wp-env snapshot note; shared guard = bin/lib-smly-snapshot.sh).
guardSmlyRec();

const ADMIN_URL = 'http://localhost:8888/wp-admin';
const WIZARD_URL = `${ADMIN_URL}/admin.php?page=smaily-connect-wizard`;

const failures = [];
const fail = (msg) => { failures.push(msg); console.log('  ✗', msg); };
const pass = (msg) => console.log('  ✓', msg);

const runDocker = (args) => {
  const joined = args.map((a) => `'${a.replace(/'/g, "'\\''")}'`).join(' ');
  let canDocker = true;
  try { execSync('docker ps', { stdio: 'pipe' }); }
  catch { canDocker = false; }
  if (canDocker) {
    return execSync(`docker ${joined}`, { stdio: 'pipe' }).toString().trim();
  }
  return execSync(`sg docker -c "docker ${joined}"`, { stdio: 'pipe' }).toString().trim();
};

const findCliContainer = () => {
  const list = runDocker(['ps', '--filter', 'name=wp-env-', '--filter', 'name=-cli-1', '--format', '{{.Names}}'])
    .split('\n').filter((n) => n && !n.includes('-tests-cli-1'));
  if (list.length === 0) {
    throw new Error('No wp-env cli container found. Start wp-env first: npx @wordpress/env start');
  }
  return list[0];
};

const wpCli = (cli, args) => runDocker(['exec', cli, 'wp', ...args, '--allow-root']);

/**
 * Run a one-shot PHP script inside the wordpress container. Avoids
 * the shell-quoting fight when seeding values that contain JSON or
 * special chars — we just write a file, docker cp it, and exec.
 */
const runPhpInContainer = (script) => {
  const tmpFile = `/tmp/walk-3.1-seed-${Date.now()}.php`;
  require('fs').writeFileSync(tmpFile, script);
  // The wordpress container shares the host /tmp via the wp-env
  // volume mount for /home/erkki, so we copy explicitly.
  const wpContainer = runDocker(['ps', '--filter', 'name=wp-env-', '--filter', 'name=-wordpress-1',
    '--format', '{{.Names}}']).split('\n').find((n) => n && !n.includes('-tests-')) || '';
  if (wpContainer === '') {
    throw new Error('No wp-env wordpress container found.');
  }
  runDocker(['cp', tmpFile, `${wpContainer}:${tmpFile}`]);
  const out = runDocker(['exec', wpContainer, 'php', tmpFile]);
  require('fs').unlinkSync(tmpFile);
  return out;
};

const seedConnectedState = () => {
  runPhpInContainer(`<?php
define('REST_REQUEST', true);
require_once '/var/www/html/wp-load.php';
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'smly_%'");
// Direct DELETE bypasses option-cache invalidation — wp_cache_flush
// is the safe cleanup so the update_options below don't short-circuit
// against a stale "already set" cached value.
wp_cache_flush();

// Smaily side — so Wizard Step 1 is satisfied.
update_option('smly_plus_setup_completed', true);
update_option('smly_plus_default_connection_verified', true);
update_option('smaily_connect_api_credentials', [
  'subdomain' => 'wizardtest',
  'username'  => 'alice@example.com',
  'password'  => 'placeholder',
]);
update_option('smly_plus_multilingual_mode', 'single');

// Rec-engine — mirror what setup-exchange would persist. The api_key
// is opaque text; chromium tests never exercise the ping proxy
// against this value (the integration suite handles that path).
update_option('smly_rec_connected', true);
update_option('smly_rec_api_key', 'chromium-test-placeholder');
update_option('smly_rec_engine_base_url', 'http://127.0.0.1:9876');
update_option('smly_rec_engine_version', '1.0.0');
update_option('smly_rec_tenant_id', '00000000-0000-4000-8000-aabbccddeeff');
update_option('smly_rec_tenant_name', 'Chromium Mock Tenant');
update_option('smly_rec_endpoints', json_encode(['ping' => '/api/v1/ingest/ping']));
update_option('smly_rec_config', json_encode([]));
update_option('smly_rec_issued_at', gmdate('c'));
echo "seeded connected state\\n";
`);
};

const seedDisconnectedState = () => {
  runPhpInContainer(`<?php
require_once '/var/www/html/wp-load.php';
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'smly_rec_%'");
wp_cache_flush();
update_option('smly_plus_setup_completed', true);
update_option('smly_plus_default_connection_verified', true);
update_option('smaily_connect_api_credentials', [
  'subdomain' => 'wizardtest',
  'username'  => 'alice@example.com',
  'password'  => 'placeholder',
]);
update_option('smly_plus_multilingual_mode', 'single');
echo "seeded disconnected state\\n";
`);
};

async function login(page) {
  await page.goto('http://localhost:8888/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#user_login', { timeout: 15000 });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('#wp-submit'),
  ]);
}

async function navigateToStep4(page) {
  await page.goto(WIZARD_URL, { waitUntil: 'networkidle' });
  await page.waitForSelector('text=Step 1 of 6', { timeout: 10000 });
  // Step 1 is "already connected" (smailyConnected=true) so Continue is live.
  // Click through to Step 4.
  for (let i = 0; i < 3; i++) {
    await page.getByRole('button', { name: /^continue/i }).click();
    await page.waitForTimeout(300);
  }
  await page.waitForSelector('text=Step 4 of 6', { timeout: 10000 });
}

(async () => {
  const cli = findCliContainer();
  const browser = await chromium.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  try {
    // ===================================================================
    // Scenario A — connected on first paint (boot payload hydrates).
    // ===================================================================
    console.log('\n[A] Step 4 loaded with rec-engine already connected');
    seedConnectedState();

    let ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    let page = await ctx.newPage();
    await login(page);
    await navigateToStep4(page);

    const boot = await page.evaluate(() => window.smailyConnectBoot && window.smailyConnectBoot.savedSettings);
    if (boot.recEngine && boot.recEngine.connected === true) {
      pass('boot.savedSettings.recEngine.connected = true');
    } else {
      fail(`expected boot.recEngine.connected=true, got ${JSON.stringify(boot.recEngine)}`);
    }
    if (boot.recEngine && !('apiKey' in boot.recEngine) && !('api_key' in boot.recEngine)) {
      pass('boot.savedSettings.recEngine does NOT expose api_key');
    } else {
      fail('boot.recEngine leaks api_key into the browser');
    }

    const connectedBanner = await page.locator('text=/✓ Connected/i').first().isVisible().catch(() => false);
    if (connectedBanner) pass('Step 4 renders ✓ Connected banner');
    else fail('Step 4 connected banner missing');

    // Feature toggles should be visible (Step-4-internal progressive disclosure).
    const syncOrdersToggle = await page.locator('input[name="rec-sync-orders"]').isVisible().catch(() => false);
    if (syncOrdersToggle) pass('Feature toggles visible in connected state');
    else fail('Feature toggles missing in connected state');

    // Disconnect button present.
    const disconnectBtn = page.getByRole('button', { name: /disconnect/i });
    if (await disconnectBtn.isVisible().catch(() => false)) pass('Disconnect button rendered');
    else fail('Disconnect button missing');

    // Click Disconnect; auto-accept the window.confirm so the test runs unattended.
    page.on('dialog', (d) => d.accept());
    await disconnectBtn.click();
    await page.waitForTimeout(800);

    // After disconnect: SetupCard should render.
    const setupUrlInput = await page.locator('input#rec-engine-setup-url').isVisible().catch(() => false);
    if (setupUrlInput) pass('After Disconnect, SetupCard renders with the setup URL input');
    else fail('SetupCard did not render after Disconnect');
    await ctx.close();

    // ===================================================================
    // Scenario B — fresh paint, SetupCard error states.
    // ===================================================================
    console.log('\n[B] Step 4 SetupCard error states');
    seedDisconnectedState();

    ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    page = await ctx.newPage();
    await login(page);
    await navigateToStep4(page);

    const inputPresent = await page.locator('input#rec-engine-setup-url').isVisible().catch(() => false);
    if (inputPresent) pass('SetupCard renders with setup URL input on fresh visit');
    else fail('SetupCard missing on fresh visit');

    const connectBtn = page.getByRole('button', { name: /^connect$/i });
    if (await connectBtn.isDisabled().catch(() => false)) pass('Connect button disabled with empty input');
    else fail('Connect button should be disabled with empty input');

    // Paste an invalid URL — server returns invalid_setup_url.
    await page.fill('input#rec-engine-setup-url', 'not a url');
    if (!(await connectBtn.isDisabled())) pass('Connect button enables when input non-empty');
    else fail('Connect button stayed disabled with non-empty input');

    await connectBtn.click();
    // Banner.
    await page.waitForSelector('text=/Setup URL not recognised|Engine unreachable|Setup link/i', { timeout: 5000 }).catch(() => null);
    const errBanner = await page.locator('text=/Setup URL not recognised|setup URL/i').first().isVisible().catch(() => false);
    if (errBanner) pass('Error banner surfaces for invalid setup URL');
    else fail('Expected error banner for invalid URL');

    await ctx.close();

    // ===================================================================
    // Scenario C — reload persistence after re-seed.
    // ===================================================================
    console.log('\n[C] Connected state persists across reload');
    seedConnectedState();
    ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    page = await ctx.newPage();
    await login(page);
    await navigateToStep4(page);
    const stillConnected = await page.locator('text=/✓ Connected/i').first().isVisible().catch(() => false);
    if (stillConnected) pass('Connected state hydrates from boot payload on reload');
    else fail('Connected banner missing after fresh page load');

    await ctx.close();

    console.log('\n=== 3.1 chromium harness done ===');
    if (failures.length === 0) {
      console.log('All assertions passed. Step 4 rec-engine UI is verified.');
    } else {
      console.log(`${failures.length} failure(s):`);
      failures.forEach((f) => console.log('  -', f));
      process.exitCode = 1;
    }
  } catch (err) {
    console.error('Walkthrough crashed:', err.message);
    console.error(err.stack);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
