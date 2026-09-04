/**
 * smly_rec_* snapshot/restore guard for live-walk scripts (PRO-1256).
 *
 * Any walk that WRITES or DELETES the dev site's `smly_rec_*` connection
 * options (seeding a mock connection, scrubbing the options table, …) must
 * call guardSmlyRec() BEFORE touching them. It runs the shared shell guard
 * (bin/lib-smly-snapshot.sh, the same logic bin/run-integration-tests.sh
 * uses — PRO-1240 guarantees: durable mode-600 snapshot outside the repo,
 * fixture/production/empty states never clobber a good snapshot, secret-safe
 * STDIN restore, tenant_name verification with a loud MiuMjau/fixture
 * warning) and registers a process-exit handler that restores the
 * connection — so a crashed or interrupted walk can't leave the dev site's
 * engine connection scrubbed.
 *
 * Walks that only READ the connection, or only touch the
 * `smly_rec_event_queue` TABLE (queue rows, not the connection options),
 * do not need this.
 *
 * Usage (top of the walk, before any seeding):
 *   require('./lib-smly-snapshot.cjs').guardSmlyRec();
 *
 * The guard is failure-tolerant by design: a snapshot/restore problem is
 * warned about but never fails the walk itself. If a walk still ends up
 * with a scrubbed connection, recover with:
 *   bash bin/run-integration-tests.sh --restore-only
 */
'use strict';

const { execFileSync } = require('child_process');
const path = require('path');

const LIB_SH = path.join(__dirname, 'lib-smly-snapshot.sh');

const runGuardStep = (subcommand) => {
  try {
    // stdio inherit: the guard's own output (pre-run state, restored
    // tenant_name, warnings) goes straight to the walk's console. The
    // snapshot JSON itself never crosses this boundary — the shell lib
    // pipes it container-ward over its own STDIN redirect.
    execFileSync('bash', [LIB_SH, subcommand], { stdio: 'inherit' });
  } catch (err) {
    console.error(
      `smly_rec_* guard: '${subcommand}' step failed (${err.message}) — continuing; ` +
        "recover manually with 'bash bin/run-integration-tests.sh --restore-only' if needed."
    );
  }
};

let armed = false;

const guardSmlyRec = () => {
  if (armed) {
    return;
  }
  armed = true;
  runGuardStep('snapshot');
  // 'exit' handlers must be synchronous — execFileSync is, so the restore
  // runs even when the walk crashes (uncaught exception → exit fires).
  process.on('exit', () => runGuardStep('restore'));
  // Signals bypass 'exit' unless converted to a normal exit.
  for (const sig of ['SIGINT', 'SIGTERM']) {
    process.on(sig, () => process.exit(130));
  }
};

module.exports = { guardSmlyRec };
