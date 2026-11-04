# Security + code-quality delta audit — pre-3.21.1 (3.21.0..79d3802)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-03_PRE_3.21.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-03_PRE_3.21.0.md),
which covered `0613c64..c6b8df7`. That report stays valid for what it covered.
This one covers the one code PR that landed after the 3.21.0 tag.

- **Date:** 2026-11-04
- **Baseline:** tag `3.21.0` (`fa61174`). Delta `3.21.0..79d3802` on `main`:
  #304 (STATUS only) and **#305 (CON-282)**. The shipped PHP in the delta is
  **9 files, +376 / −62** (`Bootstrap`, `DB\Migrator`,
  `Migration\RetiredTables` (docblock), `REST\BackfillEndpoint`,
  `Backfill\AbstractBackfillJob`, `CatalogImportOnConnect`,
  `HistoryProgress`, `ImportsOnConnect`, `migrations/012-drop-retired-tables.php`),
  plus one new `.po` / `.pot` string and one docs-site EN + ET pair.
  - **Loop breaker:** a batch walks only a `running` row; a cursor write that
    matches no running row ends the run and the tick chain.
  - **Checked start and read:** `AbstractBackfillJob::start()` returns 0 when
    its INSERT fails; `read_state()` returns false on a database error;
    `/backfill/start` answers 500 `start_failed`.
  - **Schema gate:** `Migrator::REQUIRED_VERSION` (13) and
    `Migrator::schema_ready()` gate the import tick, the sequence check and
    the history-progress check.
  - **Upgrade from Action Scheduler:** `Bootstrap::maybe_run_upgrade` also
    runs on `action_scheduler_before_process_queue`, behind `UpgradeLock`.
  - **Migrator DEFERRED:** migration 012 answers `Migrator::DEFERRED` instead
    of false; later migrations run; the step is retried first on every later
    `migrate()` (`smly_plus_schema_deferred`).
  - **One-time re-run:** `ImportsOnConnect::rerun_once()`
    (`smly_rec_import_sequence_rerun_checked`).
- **Auditor:** Claude (Opus 5.5).
- **Trigger (re-audit policy):** point 1 (the 3.21.1 release boundary) and
  point 2: SQL against custom tables (the backfill state row), a change to the
  migration runner, and an upgrade path that now runs from Action Scheduler.
- **Scope:** read of the whole PHP diff and the code it reaches:
  `Bootstrap::maybe_run_upgrade()` / `on_backfill_tick()` /
  `make_backfill_job()` / `imports_on_connect()`, `Support\UpgradeLock`,
  `Activation::run()` (what the upgrade does, where it can throw),
  `Migrator` (`migrate()`, `apply()`, `schema_ready()`, the deferred list),
  `RetiredTables::run()`, `AbstractBackfillJob` (`start()`, `batch()`,
  `walk_batch()`, `read_state()`), `OrderBackfillJob::next_page()` /
  `after_page()` (can a running batch write no row), `ImportRows` (the
  `sent_count` update), `ImportsOnConnect` (`start()`, `run_check()`,
  `start_after_update()`, `rerun_once()`, `advance()`, `start_part()`,
  `history_unfinished()`, `acquire_lock()`), `HistoryProgress` (`begin()`,
  `run()`, `check()`), `BackfillEndpoint::start()` and the admin client's error
  handling (`admin/src/api/client.ts`), `EndpointRegistry`'s connect callback,
  `uninstall.php` (the option sweeps). Action Scheduler 3.9.3 (bundled with
  WooCommerce 10.7, read in the dev wp-env container, read only):
  `ActionScheduler_QueueRunner::run()` and
  `ActionScheduler_WPCLI_QueueRunner::run()`, where the hook fires. DECISIONS
  "CON-282" (both entries) and LESSONS 2.31 read for the intended rules. No
  real customer data was used.
- **Method:** static read. PHPCS, PHPStan, the unit suite and both
  integration legs ran green in CI on #305 (run 41866857755); the
  `Contract staleness` check is red on `main` for an unrelated reason (the
  engine moved to `fe1728a`; a separate sync). **Plugin Check was not run:**
  it needs the wp-env containers, which mount a different, older checkout and
  whose dev site is connected to a real engine tenant; this gate was told not
  to use them. The release gate below lists it for the CI-built ZIP of the
  bump. Not run: the integration suite locally, any live walk, any engine
  call.

## Verdict

**0 Critical, 0 High, 0 Medium, 2 Low, 6 Info.** No code is changed in
this PR.

**RESULT: 3.21.1 may proceed.** Both Low findings are robustness gaps on
error paths that a normal store does not reach; they go to the backlog.

The fix closes the loop from both sides: a stray tick on a row that is not
`running` sends nothing and ends, and a batch whose cursor write matches no
row ends instead of reading the same page again. The background import work
waits for the schema instead of deciding on reads that cannot succeed, and
the upgrade now reaches a store minutes after a background auto-update, in a
background request, under the existing lock. The one-time re-run sends only
while `sending_allowed()`, never beside a stored sequence or a running
import, never over a merchant's Hold back or Cancel, and once.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| L1 | Low | `includes/Bootstrap.php:196`, `:805`–`:832` | An exception from `Activation::run()` on the new Action Scheduler path escapes into `ActionScheduler_QueueRunner::run()` and ends that queue run before any action — for every plugin on the site — on every run until the upgrade succeeds. Before, the same exception broke only wp-admin requests | No — backlog: catch `Throwable` on the AS path, log class + file:line, leave the version unstamped |
| L2 | Low | `includes/DB/Migrator.php:130`, `:255` | `schema_ready()` trusts the recorded version. A `.sql` step whose `dbDelta()` fails silently (for example no ALTER privilege) still records 13, so the gate opens on a table without `sent_count`; the checks then wait forever (sequence check every 15 min, progress check every 60 s), the re-run is never decided, nothing is sent | No — backlog: check the column, or cap the wait and surface it |
| I1 | Info | `includes/Support/UpgradeLock.php:40`–`:54`; `includes/Bootstrap.php:811` | Cost and concurrency of the new hook: one `get_option()` per queue run when current; `add_option()` lock; the stale-lock takeover (delete + add) has a narrow two-holder window, now reachable from several runners | No — accepted (pre-existing, PRO-2434) |
| I2 | Info | `includes/DB/Migrator.php:86`–`:100` | A deferred step is retried only when `migrate()` runs, i.e. on a plugin update; a deferred entry whose file a later release removes stays in the list and is skipped; a retried step that answers false does not stop the run | No — accepted (CON-182's "retried on the next update") |
| I3 | Info | `includes/Smaily/RecEngine/Backfill/ImportsOnConnect.php:492`–`:504` | The re-run also re-sends the history of a store whose orders import completed under 3.20 (`sent_count` NULL) and which ran 3.21.0; the docs-site and changelog wording ("never finished") does not name that case | No — accepted (DECISIONS "CON-282 — one-time re-run"); wording note for the product owner |
| I4 | Info | `includes/Smaily/RecEngine/Backfill/ImportsOnConnect.php:256`–`:303` | `rerun_once()` does not re-check its marker after `acquire_lock()`; two overlapping checks can both pass it. The second finds the products import live, starts nothing, and only calls `HistoryProgress::begin()` again (a new import id, no engine call) | No — backlog, with the CON-282 follow-up "double check scheduling" |
| I5 | Info | `includes/Smaily/RecEngine/Backfill/ImportsOnConnect.php:138`–`:150` | On connect, `start()` saves the sequence even when the products start returned 0; `advance()` then reads the products row as ended and starts customers | No — backlog (needs a failed INSERT in the connect request, after the upgrade ran) |
| I6 | Info | `includes/Smaily/RecEngine/Backfill/ImportRows.php:96` | On a store that has not run migration 013 yet, the flushers' `sent_count` update fails silently: the accepted count of a 3.21.0 run's rows is lost; the rows are sent and marked as before | No — accepted |

---

## L1 — LOW — an upgrade exception now stops the site's Action Scheduler queue

`maybe_run_upgrade()` runs `Activation::run()` inside `try { … } finally {
release }` with no `catch`. On `admin_init` an exception from it broke the
admin request. On `action_scheduler_before_process_queue` it escapes into
Action Scheduler: in 3.9.3 the hook fires at the top of
`ActionScheduler_QueueRunner::run()`, before `do_batch()`, so the WP-Cron or
async queue run ends with no action processed — WooCommerce's own actions and
every other plugin's included. The lock is released, the version is not
stamped, and the next queue run (about a minute later) tries again and fails
the same way.

What can throw there is narrow: `$wpdb` does not throw (WordPress turns
`mysqli` exceptions off), `RetiredTables::run()` catches `Throwable`, and
dbDelta does not throw. The plausible source is Action Scheduler's own
`as_schedule_recurring_action()` in `schedule_recurring_action_scheduler_jobs()`
when its store cannot save the action — a database state in which the queue
could not run its actions anyway. So the trigger is unlikely; the blast
radius is what grew.

**Proposal:** on the Action Scheduler path, wrap `Activation::run()` in
`catch ( \Throwable $e )`, write `DebugLog` with the class and file:line (no
message), and return — the version stays unstamped, so `admin_init` and the
next queue run still retry. Backlog.

## L2 — LOW — the schema gate reads the version, not the column

`schema_ready()` is `smly_plus_schema_version >= 13`. `Migrator::apply()`
returns true after any `.sql` step, because `dbDelta()` reports nothing
(the Migrator's own comment names this). A store whose database user cannot
`ALTER` the backfill table therefore records 13 with no `sent_count`. Then:

- `start()` returns 0 — Import now shows the new "could not be started"
  message, and no tick is scheduled;
- `read_state()` returns false — `ImportsOnConnect` waits (one check every
  15 minutes) and `HistoryProgress` waits (one check every 60 s while a
  history import is begun), with no end, and the re-run is never decided;
- the status route shows the read error as idle (a CON-282 follow-up).

Nothing is sent to the engine on this path, and the empty Action Scheduler
actions are pruned after 7 days (about 1 500 a day). It needs a database
that refuses an `ALTER` the plugin has run since migration 002.

**Proposal:** either make `schema_ready()` confirm the column once (a cached
`SHOW COLUMNS`), or bound the waits and surface a notice. Backlog.

## I1 — cost and concurrency of the upgrade on the Action Scheduler hook

The hook fires once per queue run — WP-Cron's `action_scheduler_run_queue`
(a loopback request, not a shopper's page, unless the store sets
`ALTERNATE_WP_CRON`, as DECISIONS says), the async request runner, and
`wp action-scheduler run` (there after the claim). When the stored version
equals the code, the cost is one `get_option()` of an `autoload=false` row
per runner request. When it does not, `UpgradeLock::acquire()` lets one
runner in (`add_option()` is an `INSERT IGNORE` on the unique option name);
the others return at once and run their actions on the new code, which the
schema gate covers for the imports. The hook also fires in a runner that then
stops on Action Scheduler's concurrent-batch limit, so the upgrade can run
there — harmless under the lock.

The takeover of a lock older than 15 minutes is `delete_option()` +
`add_option()`; two runners that both read the stale value can each end up
holding it (one deletes the other's fresh lock). This needs a dead holder and
two runners within milliseconds, and `Activation::run()` is idempotent.
Pre-existing (PRO-2434), now reachable from more processes. Accepted.

## I2 — the deferred migration's retry cadence

`migrate()` runs only from `Activation::run()`, which `maybe_run_upgrade()`
calls only while the version stamp trails the code. So a deferred 012 is
retried on the next plugin update, not on a timer — the same cadence CON-182
promised, and the legacy cart table (customer data) stays until then. The
only signals are the debug-log lines of `RetiredTables` and the option. If a
later release removes `012-drop-retired-tables.php`, its entry stays in
`smly_plus_schema_deferred` and is skipped by `continue` (harmless; the
uninstall sweep of `smly_plus_%` removes it). A retried step that answers
`false` stays deferred instead of stopping the run; the class docblock's
"`false` still stops the run" holds for a first run only. Accepted.

## I3 — the re-run's reach, and its wording

`history_unfinished()` counts a `completed` orders row with `sent_count`
NULL as unfinished. That is every store whose orders import completed under
3.20 or earlier (the column did not exist) and which ran 3.21.0's check: it
sends its whole history once more. DECISIONS "CON-282 — one-time re-run"
records this as intended (such a store never sent a §25 history import), and
the engine upserts, so nothing is duplicated there; the cost is one more full
import's traffic per such store. The docs site says the re-run is for a store
"whose order history never finished sending", and the planned changelog line
says "whose history import never finished"; a merchant whose 3.20 import
completed may not recognise their store in that. Accepted; the product owner
may want "never finished since 3.21.0" or similar in the proofread.

## I4 — two overlapping sequence checks

`rerun_once()` reads its marker, the stored sequence and the three rows, then
takes the lock, and does not read the marker again inside it. If two
`smly_rec_import_sequence_check` actions overlap (the CON-282 follow-up
"double check scheduling" names how two can exist) and the second takes the
lock after the first released it, the second runs `HistoryProgress::begin()`
(overwrites `smly_rec_history_import` with a new import id and schedules a
check — no engine call) and `start_part( 'products' )`, which finds the
products import live and starts nothing. No history is sent twice. Re-reading
the marker after `acquire_lock()` closes it. Backlog, with that follow-up.

## I5 — a failed products start on connect

`ImportsOnConnect::start()` (the connect route) calls
`CatalogImportOnConnect::start()`, which now returns false when `start()`
returned 0, but `ImportsOnConnect::start()` saves `products, customers,
orders` either way. The next check's `advance()` reads the products row:
null or a finished earlier run is not live and not cancelled, so the
sequence moves on to customers and the catalog import is skipped. The connect
request runs in wp-admin after `admin_init`'s upgrade, so the schema is
current; it needs a failed INSERT on a current schema. The shape predates
CON-282. Backlog.

## I6 — the flushers' count on a not-yet-migrated store

`ImportRows` adds accepted rows with `UPDATE … SET sent_count = sent_count +
%d … AND sent_count IS NOT NULL`. Before 013 has run, that statement fails
silently. Rows of a 3.21.0 run still in the queue are sent and marked as
before; only their count is lost, and a re-run starts from 0. The gate keeps
new import rows from being made before the schema is ready. Accepted.

## Confirmed clean

- **The loop is closed.** `batch()` walks only `running`; a `completed`,
  `cancelled`, `failed` or unknown status sends nothing and ends the chain.
  `walk_batch()` ends the run when `$wpdb->update()` matches no running row
  (0) or fails (false). MySQL reports changed rows, so 0 also means "same
  values"; that only happens for a batch with no ids and the same cursor,
  and `OrderBackfillJob::next_page()` marks an empty background page
  `completed`, the int-cursor imports always move the cursor when they have
  ids — no import is stopped early by it.
- **A failure is not "nothing there".** `start()` returns 0 on a failed
  INSERT and none of its three callers schedules a tick then. `read_state()`
  returns false only with `$wpdb->last_error` set (reset by every query), so a
  missing row stays null.
- **The schema gate waits, it never ends.** A not-ready tick reschedules
  itself in 30 s without sending; the sequence check and the progress check
  come back later; neither one-time start is decided before the schema is
  ready. The upgrade normally makes it ready at the start of the same queue
  run.
- **The re-run sends only where it may.** `sending_allowed()` is read before
  anything starts (a disconnected or refused store marks the step done and
  gets nothing), and every batch checks it again. It waits while a sequence
  is stored or any part runs, so no second chain starts beside a merchant's
  Import now or a connection's sequence. A `cancelled` part (Hold back,
  Cancel) means no re-run. A row that does not read decides nothing. The
  marker is written after the sequence is saved, and a store that never ran
  3.21.0 is decided by the after-update start instead — the two one-time
  starts cannot both fire.
- **SQL.** No new SQL text: `start()` and `read_state()` keep their prepared
  statements and only check the result. The new options
  (`smly_plus_schema_deferred`, `smly_rec_import_sequence_rerun_checked`) are
  `autoload=false` and fall under `uninstall.php`'s `smly_plus_%` /
  `smly_rec_%` sweeps.
- **REST.** `/backfill/start`'s permission check is unchanged; the new 500
  carries a fixed, translated message and the code `start_failed`, nothing
  from the database. The admin client shows the route's own `message`.
- **The upgrade stays off shoppers' pages.** Only Action Scheduler runners
  and `admin_init` call it; `ALTERNATE_WP_CRON` is the store's own choice and
  is documented.
- **Code quality.** The new rules are documented where they live (Migrator
  class docblock, `rerun_once()`, `HistoryProgress` class docblock), in
  DECISIONS, LESSONS 2.31 and CLAUDE.md. `MigratorTest` pins
  `REQUIRED_VERSION` to the highest migration file, so a new migration cannot
  forget it. The state reader's type (`array|false|null`) is spelled the same
  in all three classes.

## Release gate

- **CI on #305** (run 41866857755, head of the PR): PHP 8.0–8.3 (PHPCS,
  PHPStan, unit), admin bundle, blocks, integration legacy + HPOS — green.
- **Plugin Check against the built ZIP: not run here** (see Method). The
  release flow (`docs/audits/INDEX.md` re-audit policy) wants it for a
  release boundary; run it on the CI-built ZIP of the 3.21.1 bump in an
  environment that does not mount another checkout or touch a real tenant,
  or accept the 3.21.0 result: this delta adds no new `phpcs:ignore`, no new
  direct query and no new hook name, so no new warning is expected — only
  the 17 accepted ones, some at moved lines.

## Release punch-list

1. The 3.21.1 bump (version, readme changelog and upgrade notice).
2. The Estonian proofread of the new `.po` string and the docs-site pair
   (I3's wording note goes with it).
3. Plugin Check on the CI-built ZIP of the bump, or Erkki accepts it as not
   run (see Release gate).
4. Backlog: L1, L2, I4, I5.

## Conclusion

CON-282 ends the 3.21.0 import loop in two independent places, keeps the
background imports from deciding anything on a schema the update has not
reached, and brings the upgrade to stores that update in the background,
under the existing lock and off shoppers' pages. The one-time re-run is gated
on the connection, respects the merchant's stops and runs once. Two
error-path gaps remain (L1, L2), neither reachable on a normal store.
**3.21.1 may proceed.**
