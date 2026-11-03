# Security + code-quality delta audit — pre-3.21.0 (0613c64..c6b8df7)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-02_POST_3.20.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-02_POST_3.20.0.md),
which covered `3.20.0..cf48442`. That report stays valid for what it covered.
This one covers the two code PRs that landed after it, plus Plugin Check
against the CI-built ZIP.

- **Date:** 2026-11-03
- **Baseline:** the 2026-11-02 report (its PR #296 = `0613c64`). Delta
  `0613c64..c6b8df7` on `main`: #297 and #300 (contract copy synced to
  v1.23.0 and v1.24.0, docs only) and the two code PRs below. The shipped PHP
  in the delta is **6 files, +452 / −112**.
  - **#298 (CON-267)** — `includes/DB/LegacyOrderDateIndex.php`: the
    orders-import index is added only with `ALGORITHM=INPLACE, LOCK=NONE`; a
    refused clause is a permanent failure (the fix for the 2026-11-02 L1).
  - **#299 (CON-268)** — new `includes/Smaily/RecEngine/LoginMerge.php`;
    `IdentityHookHandler::on_login()` queues one Action Scheduler action
    instead of calling the engine; `ProfilingConsent::known_opt_out()`;
    `Bootstrap` wiring; `Deactivation::AS_GROUPS` (the fix for the
    2026-11-02 M1).
- **Auditor:** Claude (Opus 5.5).
- **Trigger (re-audit policy):** point 1 (the 3.21.0 release boundary) and
  point 3: a new background job that sends customer data to the engine
  (consent, profiling opt-out, what is stored in the job), and a schema change
  on a core table.
- **Scope:** read of both diffs and the code they reach:
  `LoginMerge` (`enqueue()`, `args()`, `run()`, `send_customer()`, `again()`),
  `IdentityHookHandler::on_login()`, `ProfilingConsent::known_opt_out()` /
  `may_profile()` / `stored_preference()` / `engine_opt_out()`,
  `Bootstrap::login_merge()` / `on_login_merge()` / `profiling_consent()` /
  `rec_client()`, `Client` (throw sites, Retry-After handling),
  `ApiException::retry_after()`, `Deactivation::AS_GROUPS`, `uninstall.php`
  (Action Scheduler rows), `QueueJanitor` (hook prefixes),
  `GdprHandler::erase()`; `LegacyOrderDateIndex` (`run()`, `due_at()`,
  `is_clause_refused()`, `add()`, `with_lock_wait()`, `write_state()`).
  Contract v1.24.0 §4 ("Which customers to send", group c) and §7 ("Retry
  after 404", "Send the customer first", "When to call") read for the facts
  the code relies on. No real customer data was used.
- **Method:** static read. PHPCS and PHPStan were not re-run locally; CI on
  #299's head ran them green (PHP 8.0–8.3), with both integration legs, the
  admin bundle and the blocks jobs, and the contract staleness check.
  **Release gate:** the ZIP from a `release.yml` `workflow_dispatch` dry run,
  `bin/verify-release-zip.sh`, Plugin Check 2.1.0 in the running dev wp-env.
  Not run: the integration suite locally, any live walk, any engine call.

## Verdict

**0 Critical, 0 High, 0 Medium, 1 Low, 5 Info.** No code is changed in
this PR.

**RESULT: 3.21.0 may proceed.** L1 is narrow and can be fixed after the cut
or before it, as Erkki decides.

Both fixes close what the 2026-11-02 audit asked for. The login makes no
engine call: it queues one action, and the action's client makes one attempt
of at most 10 s per request, with a bounded number of runs. The index is
built only online. What the action stores is the WordPress user id, the
visitor token, the anon session id, the consent answer and the run number,
never the email; the debug-log lines carry only the HTTP status and the error
code. The action checks `sending_allowed()`, the user, the token shapes (a
store `vs_` token only with the login's consent) and the full profiling gate
again when it runs, so a contact who opts out of profiling between the login
and the run is neither sent nor merged. The 404 retry follows §7: once, and
only after §4 answered without a per-item error for that customer in the
same run.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| L1 | Low | `includes/Privacy/GdprHandler.php:247`–`:276`, `:520`–`:533`; `includes/Smaily/RecEngine/LoginMerge.php:144`–`:242` | The personal-data eraser does not cancel a waiting `smly_rec_login_merge` action for the user. A run after the eraser's §9 delete sends the customer again (§4) and binds the token (§7) | No — Erkki decides; a small fix fits before or after the cut |
| I1 | Info | `includes/Smaily/RecEngine/LoginMerge.php:125`–`:133` | The action's args hold the user id, the visitor token and the anon session id (pseudonymous, no email); shop managers see them under Scheduled Actions; the janitor prunes finished rows after 7 days, uninstall deletes them | No — accepted |
| I2 | Info | `includes/Smaily/RecEngine/LoginMerge.php:264`–`:273` | A retry run reuses the login's marketing-consent answer; the wait is `max( back-off, Retry-After )` with no upper bound on the engine's value | No — backlog: cap the wait (for example 1 h) |
| I3 | Info | `includes/Smaily/RecEngine/LoginMerge.php:45`–`:48`; `includes/Bootstrap.php:1270`–`:1285` | The docblock says a run never sleeps a Retry-After; the profiling gate inside the run uses `rec_client()` (2 attempts) for the §10 opt-out, which can | No — docblock wording |
| I4 | Info | `includes/Privacy/ProfilingConsent.php:307`–`:308` (unchanged code) | `engine_opt_out()` writes the engine's exception message to the debug log; an engine message can name the customer. Pre-existing, now also reached from the background action | No — backlog: log status and code only, as `LoginMerge::describe()` does |
| I5 | Info | `includes/DB/LegacyOrderDateIndex.php:173`–`:191`, `:260`–`:262` | A refused online clause, or any SQL syntax error, is permanent: the store never gets the index, also after a database upgrade, and only the debug log and the option say so | No — accepted as documented (DECISIONS CON-267) |

---

## L1 — LOW — the eraser leaves a waiting login action

`GdprHandler::erase()` deletes the customer's waiting customer and order
updates from the ingest queue **before** the §9 delete (PRO-3906), so that
nothing sends the customer to the engine again after it. The login action is
a new path that sends the customer (§4) outside that queue, and the eraser
does not look at Action Scheduler. When a waiting or back-off-scheduled
`smly_rec_login_merge` action for the user runs after the eraser:

- the WordPress user still exists (WordPress's erasers do not delete the
  account), so `get_userdata()` finds it;
- `may_profile()` passes for a contact with no recorded opt-out;
- the action sends §4, which creates the customer in the engine again, and
  §7, which binds the token to it.

The window is short in the normal case (an async action runs within a minute
of the login). It is longer when the action is waiting on its back-off (60 s,
then 300 s) or on an engine `Retry-After` (I2). Before CON-268 the merge ran
inside the login request, so this window did not exist.

**Proposal:** in `erase()`, before the §9 call, unschedule the user's
pending `LoginMerge::HOOK` actions in `LoginMerge::AS_GROUP` (the args hold
the user id, so a lookup by group and a filter on `user_id` finds them), and
pin it with an integration test next to the PRO-3906 one. The alternative,
a marker the action reads, keeps state for an erased contact and is not
recommended.

## I1 — what the action stores

`LoginMerge::args()` builds `{user_id, token, anon_session_id, consent,
attempt}`. No email, name or other direct identifier goes into the
Action Scheduler tables; the action reads the email from the user id when it
runs. The visitor token and the anon session id are pseudonymous online
identifiers (personal data under GDPR, not direct identifiers). They sit in
`actionscheduler_actions.args`, which WooCommerce's Scheduled Actions screen
shows to shop managers, who already see the customers' emails.
`QueueJanitor::prune_scheduler_history()` covers the `smly_rec_` prefix, so
finished, failed and cancelled rows are deleted after 7 days, and
`uninstall.php` deletes every `smly_rec_%` action row. Action Scheduler's own
log lines do not copy the args. The new `LoginMerge` debug lines log only
`http=<status> error=<code>`. Accepted; L1's fix also removes a waiting row
for an erased contact.

## I2 — consent answer and the retry wait

The marketing-consent answer is the browser's WP Consent API answer, read in
the login request; the background action has no browser to ask, so it cannot
re-check it, and it says so. The profiling opt-out and the Smaily unsubscribe
(`may_profile()`) are re-checked in every run. A retry run therefore reuses
the login's consent answer for as long as the retries last: 60 s, then
300 s — or longer when the engine's `Retry-After` is longer, because
`again()` takes `max( back-off, retry_after )` and does not cap the engine's
value. The runs stay bounded (`MAX_ATTEMPTS` = 3) and the engine is the
trusted party, so this is not a finding; capping the wait (for example at
one hour) keeps the reused answer recent. Backlog.

## I3 — docblock: "a run never sleeps a Retry-After"

The action's own client is `rec_client( 1, 10 )`: one attempt, no sleep.
But `may_profile()` can resolve a contact to "do not profile" and then call
`engine_opt_out()`, whose client is the default `rec_client()` (2 attempts,
a Retry-After sleep of up to 60 s). The run happens in the background, so
nothing user-facing waits; only the sentence is too strong. Suggest
"the action's own requests never sleep a Retry-After".

## I4 — an engine message in the debug log (pre-existing)

`ProfilingConsent::engine_opt_out()` logs `$e->getMessage()`. An engine
error message can name the customer (a customer 404 echoes the address it
was asked about).
The code is unchanged in this delta, but the login action now reaches it in
the background. `LoginMerge::describe()` already shows the safe form
(status and error code only). Backlog.

## I5 — the permanent index failure

`is_clause_refused()` treats error 1845 / 1846 and any "SQL syntax" answer as
permanent. The statement is a constant, so a syntax error means the server
does not know the clause, and none of these changes from one day to the next.
The cost is that a store whose host later upgrades the database server never
gets the index, and the only signals are the debug-log line and the
`smly_plus_order_date_index` option. The orders import works without it, at
its current speed. Accepted as documented in DECISIONS CON-267.

## Confirmed clean

- **Login request makes no engine call.** `on_login()` reads cookies and
  consent, sets or renews the cookie, reads `known_opt_out()` (transient and
  options only — never Smaily or the engine) and calls `enqueue()`.
- **Gating.** `on_login()` and `run()` both start with `sending_allowed()`;
  a store refused or disconnected after the login sends nothing.
  `Deactivation::AS_GROUPS` lists `LoginMerge::AS_GROUP`, so deactivation
  cancels the waiting actions.
- **Profiling.** The login skips a contact the store already knows has opted
  out (and creates no token for them); the action runs the full
  `may_profile()` before §4 and §7.
- **Token rules.** The action re-checks the token's shape, drops a store
  `vs_` token without the login's consent and drops an anon session id of the
  wrong shape or one already merged for the user.
- **§4 / §7.** §4 only with the login's consent (contract §4 group c); the
  merge after it; a `404 customer_not_found` retried once, only when §4
  answered without a per-item error in the same run; any other 4xx ends the
  action with a log line that carries no message text.
- **Bounded work.** One attempt of at most 10 s per request; at most three
  runs per login; `enqueue()` adds nothing when an action with the same
  values is already pending or running (any attempt number). A login with new
  cookie values queues one more action — bounded by the rate of successful
  logins, which before CON-268 each made a synchronous call.
- **Exceptions.** `Client` throws only `ApiException`; both send blocks
  catch it, so no engine message reaches Action Scheduler's failure log.
- **Index SQL.** The `ALTER TABLE` has no input (table name from `$wpdb`,
  index name and columns are constants); `lock_wait_timeout` is restored in
  `finally` when the previous value was read; the stored and logged error is
  redacted (quoted parts removed, 200 characters); the plain `ADD INDEX`
  fallback is gone.
- **Code quality.** Both files follow the existing patterns (one `args()`
  builder for queued and looked-up args, a test seam for consent, the
  constant `MERGED_META_KEY` spelled once). The new `phpcs:ignore` in
  `LegacyOrderDateIndex::add()` is on the line before the statement it
  covers.

## Release gate — CI-built ZIP, verify, Plugin Check

- **ZIP:** `release.yml` `workflow_dispatch` on `main`, **run 41623168783**
  (head `c6b8df7`, green, 2m10s), downloaded as the workflow artifact
  `smaily-connect-zip` (not a release asset). `smaily-connect.zip`,
  **1 046 166 B**, SHA256
  `67aad9a0da9a11b0084142774a706f0e4f5009be69092ab919ba73df02e44628`,
  build-hash `c6b8df7`, 420 entries. Version 3.20.0 (the bump comes in its
  own PR).
- **`bash bin/verify-release-zip.sh smaily-connect.zip 3.20.0`** (repo
  root): **VERIFY OK** (42 checks).
- **Plugin Check 2.1.0** in the running dev wp-env cli container (unzipped to
  `wp-content/plugins/smaily-connect-pkg`, never the mounted plugin path;
  `wp plugin check smaily-connect-pkg --slug=smaily-connect --format=csv
  --allow-root --exclude-directories=vendor`; the `-pkg` copy and the temp
  files removed afterwards, the mounted plugin checked intact):
  **0 ERRORS / 17 WARNINGS — the same 17 accepted warnings at the same lines
  as the 2026-11-02 report**: `DirectQuery` + `NoCaching` at
  `BackfillJob.php:677`, `IngestQueue.php:504` and `:533`,
  `QueueJanitor.php:229`, `EventsEndpoint.php:804`; `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at `GdprHandler.php:696`,
  `CartSessionStore.php:287`, `:311`. Nothing from `LoginMerge.php` or
  `LegacyOrderDateIndex.php`. No version note was reported.

## Release punch-list

1. **L1** — Erkki: fix before the cut (eraser unschedules the user's waiting
   login actions + an integration test), or after it.
2. **PCP against the CI-built ZIP of the bump** — expect the same 17
   warnings.
3. Backlog: I2 (cap the retry wait), I3 (docblock), I4 (log status and code
   only in `engine_opt_out()`).

## Disposition (2026-11-03)

| # | Disposition |
|---|---|
| L1 (Low) | **Fixed in #303 (CON-275):** the eraser cancels every waiting `smly_rec_login_merge` action of the user (any token, any run number) before the §9 delete, beside the PRO-3906 queue drop; a job for a deleted user still sends nothing. |

## Conclusion

The two PRs fix the 2026-11-02 M1 and L1 without widening access: the login
no longer waits on the engine, the job stores no email and logs no message
text, every run re-checks the connection, the token rules and the profiling
opt-out, and the work per login is bounded. The index is built only online.
One gap is new: the eraser does not cancel a login action still waiting to
run (L1). The CI-built ZIP verifies, and Plugin Check shows only the 17
accepted warnings. **3.21.0 may proceed.**
