/**
 * Sub-PR 3.0 chromium harness — browser-level mirror of the
 * tests/Integration/ suite. Confirms the PHP-side passes line up
 * with what an actual headless Chrome sees on /wp-admin.
 *
 * Reusable template for Faas 3.1+: each new feature should land a
 * sibling `bin/walk-3.<N>.cjs` that exercises the new flow end-to-end
 * before the ZIP ships.
 *
 * Run:
 *   node bin/walk-3.0.cjs
 *
 * Exit code:
 *   0 — every assertion passed
 *   1 — at least one failed
 *
 * Requires wp-env running + the smaily-connect plugin activated. The
 * integration test runner (bin/run-integration-tests.sh) ensures
 * both. Run this script AFTER `composer run test:integration` so any
 * server-side breakage surfaces first.
 */
const { chromium } = require('playwright-core');
const { execSync } = require('child_process');

const ADMIN_URL = 'http://localhost:8888/wp-admin';
const WIZARD_URL = `${ADMIN_URL}/admin.php?page=smaily-connect-wizard`;
const SETTINGS_URL = `${ADMIN_URL}/admin.php?page=smaily-connect-settings`;

const failures = [];
const fail = (msg) => { failures.push(msg); console.log('  ✗', msg); };
const pass = (msg) => console.log('  ✓', msg);

/**
 * Runs a docker command, transparently switching to `sg docker -c`
 * when the current shell lacks docker-group permission (Erkki's
 * local box; CI runners are pre-grouped so the direct path works).
 */
const runDocker = (args) => {
  // args is an array; we join with whitespace and pass as a single
  // string under sg's -c to keep argument boundaries intact.
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
  const list = runDocker([
    'ps',
    '--filter', 'name=wp-env-',
    '--filter', 'name=-cli-1',
    '--format', '{{.Names}}',
  ]).split('\n').filter((n) => n && !n.includes('-tests-cli-1'));
  if (list.length === 0) {
    throw new Error('No wp-env cli container found. Start wp-env first: npx @wordpress/env start');
  }
  return list[0];
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

// Expected REST routes — kept in sync with EndpointRegistry::expected_routes().
// If a new endpoint lands in 3.1+, add the (method, path) pair here AND in
// the PHP registry. The pair-and-check is intentional: this list catches
// the case where a registry entry was added but no actual handler exists
// (the route would 404 at runtime — chromium's fetch surfaces that).
const EXPECTED_ROUTES = [
  { method: 'POST', path: '/test-smaily' },
  { method: 'POST', path: '/backfill/start' },
  { method: 'GET',  path: '/backfill/status' },
  { method: 'POST', path: '/backfill/cancel' },
  { method: 'GET',  path: '/workflows' },
  { method: 'POST', path: '/settings' },
];

(async () => {
  const browser = await chromium.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  // Seed: setupCompleted=true so Settings is reachable without the
  // wizard redirect kicking in. We're testing the route + boot
  // surface, not the wizard-first gate (2.I covered that already).
  console.log('[seed] Marking setup as completed so Settings is reachable.');
  const cli = findCliContainer();
  runDocker(['exec', cli, 'wp', 'option', 'update', 'smly_plus_setup_completed', '1', '--allow-root']);

  const consoleErrors = [];

  try {
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    const page = await ctx.newPage();
    page.on('console', (m) => {
      if (m.type() === 'error') {
        consoleErrors.push(m.text());
      }
    });

    console.log('\n[1] Login + load wizard page...');
    await login(page);
    await page.goto(WIZARD_URL, { waitUntil: 'networkidle' });
    const boot = await page.evaluate(() => window.smailyConnectBoot);

    console.log('   buildHash:', boot && boot.buildHash);

    // Assertion: buildHash matches dist/build-hash.txt.
    const expectedHash = require('fs').readFileSync(
      require('path').join(__dirname, '..', 'dist', 'build-hash.txt'),
      'utf8',
    ).trim();
    if (boot.buildHash === expectedHash) {
      pass(`buildHash matches dist/build-hash.txt (${expectedHash})`);
    } else {
      fail(`buildHash mismatch: boot=${boot.buildHash} vs dist=${expectedHash}`);
    }

    // Assertion: boot payload shape.
    const requiredKeys = ['nonce', 'restUrl', 'view', 'envSnapshot', 'savedSettings'];
    for (const key of requiredKeys) {
      if (key in boot) pass(`boot.${key} present`);
      else fail(`boot.${key} missing`);
    }

    console.log('\n[2] Probe every expected REST route via fetch() with the boot nonce...');
    const routeResults = await page.evaluate(async (routes) => {
      const out = [];
      const boot = window.smailyConnectBoot;
      for (const r of routes) {
        try {
          const init = {
            method: r.method,
            headers: { 'X-WP-Nonce': boot.nonce, 'Content-Type': 'application/json' },
          };
          // POST routes need a body — empty {} is fine, the test
          // doesn't care about success status, only about "no 404".
          if (r.method === 'POST') {
            init.body = JSON.stringify({});
          }
          const res = await fetch(boot.restUrl + r.path.replace(/^\//, ''), init);
          out.push({ method: r.method, path: r.path, status: res.status });
        } catch (e) {
          out.push({ method: r.method, path: r.path, error: e.message });
        }
      }
      return out;
    }, EXPECTED_ROUTES);

    for (const r of routeResults) {
      if (r.error) {
        fail(`${r.method} ${r.path} threw: ${r.error}`);
      } else if (r.status === 404) {
        fail(`${r.method} ${r.path} returned 404 — endpoint not registered`);
      } else if (r.status === 401 || r.status === 403) {
        fail(`${r.method} ${r.path} returned ${r.status} — nonce / permission missing`);
      } else {
        // 200, 400, 500 are all OK — they confirm the route is wired.
        pass(`${r.method} ${r.path} reachable (HTTP ${r.status})`);
      }
    }

    console.log('\n[3] Load Settings page + confirm same buildHash...');
    await page.goto(SETTINGS_URL, { waitUntil: 'networkidle' });
    if (page.url().includes('page=smaily-connect-settings')) {
      pass('Settings page renders (setup_completed gate satisfied)');
    } else {
      fail(`Settings redirected unexpectedly to: ${page.url()}`);
    }
    const settingsBoot = await page.evaluate(() => window.smailyConnectBoot);
    if (settingsBoot && settingsBoot.buildHash === expectedHash) {
      pass('Settings page boot payload reports same buildHash');
    } else {
      fail(`Settings buildHash mismatch: ${settingsBoot && settingsBoot.buildHash}`);
    }

    await ctx.close();

    console.log('\n=== 3.0 chromium harness done ===');
    if (consoleErrors.length > 0) {
      // Console errors are advisory — don't fail unless they reference our plugin.
      const ours = consoleErrors.filter((e) => e.toLowerCase().includes('smaily'));
      if (ours.length > 0) {
        ours.forEach((e) => fail(`Console error from our code: ${e}`));
      } else {
        console.log(`(${consoleErrors.length} third-party console error(s) — ignored)`);
      }
    }

    if (failures.length === 0) {
      console.log('All assertions passed. Faas 3.0 baseline is solid; ready to start 3.1.');
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
