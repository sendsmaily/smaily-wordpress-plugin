# Security + code-quality delta audit — pre-release gate after 3.20.0 (3.20.0..cf48442)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-23_3.20.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-23_3.20.0.md),
which covered everything up to the 3.20.0 bump (its addendum included). This
report covers what landed on `main` after the `3.20.0` tag. The earlier
reports stay valid for what they covered.

- **Date:** 2026-11-02
- **Baseline:** delta `3.20.0..cf48442` (tag `3.20.0` = `c82e53c` → `origin/main`
  `cf48442`): **19 commits, 140 files, +14 663 / −966**; outside `docs/`,
  `tests/`, `STATUS.md` and `CLAUDE.md` **75 files, +5 382 / −630**. The
  shipped PHP (`includes/`, `integrations/`, `uninstall.php`, `migrations/`)
  is **42 files, +3 763 / −466**; the shipped TypeScript sources are
  **18 files, +464 / −125** (tests excluded). The work in it:
  - **#276 (CON-182)** — refactor: seven legacy `Cron` abandoned-cart helpers
    removed (dead code).
  - **#277 CON-207** — schema migration 012: the legacy cart drain checks
    each write, and the migration keeps the legacy table while the cart
    tracker table is missing.
  - **#279 CON-206** — the §17 design-link return address leaves out a URL
    that a browser reads differently from PHP.
  - **#280** — docs only (the 3.20.0 release record).
  - **#281, #291, #293** — engine contract synced to v1.20.0, v1.21.0, v1.22.0.
  - **#282 + #285 CON-250** — new public REST route `POST /land`: the
    store's server sets the email-click cookies.
  - **#283 CON-245** — the orders import walks newest first inside the
    engine's history window; its cursor is JSON in `cursor_value`.
  - **#284 CON-218** — a pushed workflow is used only on the Smaily account
    the engine names for it.
  - **#286 CON-228** — new admin REST route `POST /rec-engine/console-entry`
    and the §24 engine call.
  - **#287 CON-246** — only customers in the §4 scope reach the engine
    (`RecEngine\CustomerScope`, new SQL); a registered buyer's order queues
    the buyer.
  - **#288 CON-247** — connecting starts the catalog, customers and orders
    imports one after another; a store connected before the release starts
    them once after the update.
  - **#289 CON-255** — an automation card that is off shows only its switch;
    the discount code is gated server-side on the automation's switch.
  - **#290 CON-248** — schema migration 013 (`smly_plus_backfill_job.sent_count`)
    and the §25 history progress report.
  - **#292 CON-236** — an import run sends at 5 requests a second for up to
    40 s and stops on a 429.
  - **#294 CON-259** — the index `smaily_connect_order_date` on the core
    `wp_posts` table for a connected legacy-storage store.
  - **#295 CON-251** — a store `vs_` visitor token at login for a customer
    with marketing consent, and its renewal.
- **Auditor:** Claude (Opus 5.5).
- **Trigger (re-audit policy):** point 1 (the next release boundary), point
  2 (a large delta, ~4 200 shipped lines) and point 3 on every named
  surface: two new REST routes (a public one and an admin one), two schema
  migrations and a schema change on a core WordPress table, SQL against
  custom and core tables, new external HTTP calls (§24, §25), a new cookie
  written at login (consent, a stored identifier), and what is sent to the
  engine (customer scope).
- **Scope:** file-by-file read of the shipped PHP and TypeScript delta plus
  the call chains it reaches: `LandEndpoint` → `LandingCapture::store()` /
  `resolve()` / `send_cookie()`, `RequestThrottle`; `ConsoleEntryEndpoint`
  → `RecEngineProxy::permission_check()` → `Client::console_entry()`;
  `IdentityHookHandler::on_login()` → `LandingCapture::issue_visitor_token()` /
  `renew_visitor_token()`, `ProfilingConsent::may_profile()`,
  `Client::merge_identity()` / `request_url()`; `LegacyOrderDateIndex`,
  `uninstall.php`, `Deactivation::AS_GROUPS`; migrations 012 / 013,
  `LegacyCartDrain`, `RetiredTables`; `CustomerScope`,
  `ContactAudience::user_id_sql()`, `CustomerBackfillJob`, `OrderBackfillJob`
  (`next_page()`, `page()`, `decode_window()`, `count_orders()`,
  `count_buyers()`), `AbstractBackfillJob::process_run()`, `ImportPace`,
  `ImportRows`, `AbstractD6Flusher` (429 parking, `skipped_orders[]`),
  `IngestQueue` (new counts), `HistoryProgress`, `HistoryConfig`,
  `ImportsOnConnect`, `Bootstrap` wiring, `EnvDetector`, `AutomationDiscount::
  automation_enabled()`, `EmailDesignWorkflows`, `EmailDesignsEndpoint::
  allowed_return_url()`, `public/js/lib/attribution.ts`,
  `OpenConsoleButton.tsx`. DECISIONS CON-206, CON-207, CON-218, CON-228,
  CON-236, CON-245–CON-248, CON-250, CON-251, CON-255, CON-259 read against
  the code; contract §5, §7, §15, §24, §25 read for the facts the code relies
  on; `readme.txt`, `docs/DATA_MODEL_GDPR.md` and the docs site checked for
  the new behaviour. No real customer data was used.
- **Method:** static read; `vendor/bin/phpcs` (with warnings) on the 40
  changed PHP files; PHPStan via `ci:strict`. **Release-gate build and
  Plugin Check** (below): ZIP built locally, verified, and checked with
  Plugin Check 2.1.0 in the running dev wp-env (not restarted). CI on `main`
  `cf48442`: "Lint and test the codebase" run 41459264001 green (both
  integration legs); `Contract staleness` run 41459264254 **red** (I10). Not
  run: the integration suite locally (the running wp-env mounts the older
  `connect` checkout; CI runs both legs), any live walk.

## Verdict

**0 Critical, 0 High, 1 Medium, 6 Low, 12 Info.** Three Low findings were
fixed in this PR (L5, L6, L7); M1 and the other Lows are proposals.

**RESULT: the next release may proceed once M1 is accepted or narrowed,
L4 is handled at the bump, and the contract copy is synced (I10).**

The security-sensitive surfaces are correct. Both new routes are gated as
their kind requires: `/land` is public but refuses a disconnected store, a
request from another site and an over-limit address, takes only the three
fields it knows through LandingCapture's own shape rules, and answers
`no-store`; `/rec-engine/console-entry` needs a signed-in user, the
`wp_rest` nonce and `manage_options`, sends only the signed-in admin's own
email, makes one 3-second attempt and never stores or logs the link. The
schema steps are idempotent and fail closed; the core-table index is added
only in a background action, only on a connected legacy-storage store, never
in a request, at most three times, and uninstall drops only the plugin's own
index. All new SQL is prepared, with identifiers from code. The login token
is 22 characters from a CSPRNG (about 131 bits), written only by the server
with the existing attributes, never put in a URL, a log or a stored row, and
created only with an explicit marketing yes, a passed profiling gate and an
allowed connection. The new engine calls are gated on `sending_allowed()`,
log only status / error codes, and send counts and dates (§25) or the
admin's own email (§24).

The Medium is about availability, not access: CON-251 makes the
synchronous identity merge run at more logins, and that merge uses the
retrying client (2 attempts, 15 s each, a Retry-After wait of up to 60 s).

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| M1 | Medium | `includes/Integrations/WooCommerce/IdentityHookHandler.php:100`–`:180`; `includes/Bootstrap.php:750`–`:754`; `includes/Smaily/RecEngine/Client.php:753`–`:830` | A customer's login waits on the identity merge, which uses `rec_client()` (2 attempts × 15 s, Retry-After up to 60 s). CON-251 makes every consenting login call it (the token it creates makes every later login call it too) | **Yes** — accept, or move the merge to one short attempt (3 s, as §16 / §24) or to a background action |
| L1 | Low | `includes/DB/LegacyOrderDateIndex.php:318`–`:342`, `:243`–`:245` | When the server refuses `ALGORITHM=INPLACE, LOCK=NONE`, the fallback runs a plain `ADD INDEX`, which the server may build with a lock that blocks writes to `wp_posts` — where a legacy-storage store writes its orders — for the whole build | Erkki decides — accept as documented, or record `failed` instead of falling back |
| L2 | Low | `includes/REST/ConsoleEntryEndpoint.php:157`–`:161` | `is_engine_link()` accepts any `https://` URL from the engine answer; the admin's browser is sent there | No — backlog: require the engine's host |
| L3 | Low | `includes/Wizard/EnvDetector.php:241`–`:262` | The scope count (two sub-queries, a `postmeta` join on legacy storage) runs on every wizard / Settings load, connected or not | No — backlog: only when connected, or cache it briefly |
| L4 | Low | `readme.txt:85`–`:125`, `:185`–`:205` | The readme does not yet describe the delta: §24 sends the signed-in admin's email, the login merge and the token it sets, the customer scope, the §25 counts, the imports a connected store starts after the update (CON-247); the 3.19.0 changelog line on "main Smaily account" is superseded by CON-218 | **Yes** — at the bump |
| L5 | Low — **fixed here** | Plugin Check on the built ZIP | Five new `PluginCheck.Security.DirectDB.UnescapedDBParameter` warnings (`CustomerBackfillJob.php:63`, `:75`; `LegacyOrderDateIndex.php:325`, `:333`, `:361`), all false positives | Fixed in this PR |
| L6 | Low — **fixed here** | `docs/DATA_MODEL_GDPR.md` | The privacy-policy template (EN + ET) and the register table did not name the login token (CON-251); the docs site did | Fixed in this PR |
| L7 | Low — **fixed here** | `includes/Smaily/RecEngine/Backfill/OrderBackfillJob.php:99`; `includes/DB/LegacyOrderDateIndex.php:109`; `includes/Integrations/WooCommerce/LandingCapture.php:379`–`:405` | One `UnfinishedPrepare` false positive and one alignment warning in new code; a docblock above the wrong method that gave a stale reason for `HttpOnly=false` | Fixed in this PR |
| I1 | Info | `includes/REST/LandEndpoint.php:89`–`:115` | `/land` verified | — |
| I2 | Info | `includes/REST/ConsoleEntryEndpoint.php:88`–`:133`; `admin/src/components/steps/OpenConsoleButton.tsx` | `/rec-engine/console-entry` verified | — |
| I3 | Info | `migrations/013-add-backfill-sent-count.sql`; `includes/Migration/RetiredTables.php:55`–`:108`; `includes/Migration/LegacyCartDrain.php` | Migrations 012 / 013 verified | — |
| I4 | Info | `includes/DB/LegacyOrderDateIndex.php`; `uninstall.php:186`–`:195`; `includes/Deactivation.php:59`–`:60` | The core-table index verified (beyond L1) | — |
| I5 | Info | `includes/Integrations/WooCommerce/LandingCapture.php:192`–`:239`, `:398`–`:410`; `IdentityHookHandler.php:100`–`:180` | The login token verified; the shared-browser trade-off is as DECISIONS CON-251 records | — |
| I6 | Info | `includes/Smaily/RecEngine/Client.php:652`–`:675`; `HistoryProgress.php`; `HistoryConfig.php:56`–`:90` | New engine calls (§24, §25, the history-window ping) verified | — |
| I7 | Info | `ImportPace.php`; `AbstractBackfillJob.php:213`–`:249`; `AbstractD6Flusher.php:168`–`:178`, `:339`–`:340`; `ApiException.php` | Import pacing and 429 handling verified | — |
| I8 | Info | `CustomerScope.php`; `ContactAudience.php`; `CustomerBackfillJob.php:57`–`:82`; `OrderBackfillJob.php` | SQL of CON-246 / CON-245 verified; data minimisation | — |
| I9 | Info | `AutomationDiscount.php`; `HookHandler.php:965`–`:972`; admin cards | CON-255: no server authorization change; the discount's new server gate | — |
| I10 | Info | `docs/RECENGINE_API_CONTRACT.md` | `Contract staleness` is red on `main`: the engine moved to `a35a94d`. This PR's check will be red for the same reason | Sync before the release |
| I11 | Info | `ImportsOnConnect::start_after_update()` (`:154`) | The release starts the customers and orders imports once on every connected store after the update | Name it in the upgrade notice |
| I12 | Info | tooling, CI, local build | Static gates, catalogues, build | — |

---

## M1 — MEDIUM — a customer's login waits on the identity merge

**Where:** `IdentityHookHandler::on_login()` runs on `wp_login`, inside the
shopper's login request. Its client is `Bootstrap::rec_client()` with the
defaults (`Bootstrap.php:750`–`:754`): `max_attempts` 2,
`DEFAULT_TIMEOUT_SECONDS` 15. `Client::request_url()` retries a network
error after 1 s, and a 429 / 5xx after the answer's Retry-After (capped at
`MAX_RETRY_AFTER_SECONDS` = 60) or the back-off. Before the merge, the
profiling gate (`ProfilingConsent::may_profile()`, `:131`) reads Smaily on a
cache miss.

**What CON-251 changed:** before, a login that carried a token but whose
anon session was already merged made no call. Now every login with a token
calls the merge, a consenting login without a token creates one (so every
later login of that customer carries one, for 365 days and renewed at each
login), and the engine answers `404 customer_not_found` for a customer
outside the §4 scope, so that customer's every login makes a call that
binds nothing.

**Effect:** while the engine is slow or unreachable, each such login waits
up to about 31 s (15 s, 1 s, 15 s); after a 429 with a Retry-After, up to
about 90 s. The login itself succeeds afterwards (the exception is caught),
so this is not a lock-out, but the shopper sees a hung login, and a
WooCommerce checkout that logs the shopper in waits too. The storefront's
other engine calls were made single-attempt for this reason (`/relay`,
PRO-3620; §15, PRO-3835), as were §16 and §24 in the admin.

**Recommendation (before release, one of):**
- Give the merge its own client: one attempt, `BROWSE_TIMEOUT_SECONDS`
  (3 s), no redirect, no Retry-After wait — as `store_uninstall()` and
  `console_entry()` do. A failed merge already waits for the next login.
- Or set the cookie in the request (it must be) and send the merge from a
  single Action Scheduler action with the body as its argument (the body
  holds an email: keep it out of the AS args or accept that AS stores it —
  the first option avoids that question).
- Or Erkki accepts the current behaviour (a DECISIONS CON-251 addendum).

Optional with either fix: create no token for a customer outside
`CustomerScope::includes_user()`, so an out-of-scope login makes no call
until the customer's first order binds a token (the order path would then
need its own token source — today the guest token only).

## L1 — LOW — the index fallback may block writes to `wp_posts`

**Where:** `LegacyOrderDateIndex::add()` (`:318`–`:342`) first runs
`ALTER TABLE … ADD INDEX …, ALGORITHM=INPLACE, LOCK=NONE`. When the error
matches `is_clause_refused()` (`:243`–`:245`: the `ALGORITHM` / `LOCK`
clause refused, or a syntax error from a server that does not know it), it
runs the same `ADD INDEX` without the clause, and the server picks the
algorithm and lock.

**Effect:** the cases that reach the fallback are the ones where the server
cannot build the index without blocking writes: a server that refuses
`LOCK=NONE` for this table, or a server old enough not to know the clause
(it copies the table). `lock_wait_timeout` (5 s) bounds the wait for the
metadata lock, not the build. On legacy storage `wp_posts` holds the orders,
so for the length of the build a new order can wait. The run is in a
background action and happens at most three times, and DECISIONS CON-259
records the fallback; the docblock's "reads and writes go on during the
build" holds only for the first statement.

**Recommendation (Erkki decides):** accept as documented (and say in the
docblock and DECISIONS that the fallback may block writes), or drop the
fallback and record `failed` with the server's refusal — the import works
without the index, and a store on such a server keeps today's speed.

## L2 — LOW — the console link is accepted from any https host

`ConsoleEntryEndpoint::is_engine_link()` (`:157`–`:161`) returns true for
any URL starting with `https://`, or one on the stored engine origin. The
link comes from the authenticated engine answer over TLS to the host the
setup exchange allowed (PRO-3623), so only a compromised engine — or a
stored connection on another host — could send the admin elsewhere. The
admin's tab is opened with `opener = null` and React sets `href` only to
this URL, and `javascript:` cannot pass the `https://` check. Defence in
depth only.

**Recommendation (backlog):** require the URL's host to equal the host of
`RecEngineSettings::base_url()` (contract §24's example is on the engine
host), else 502 `invalid_engine_response`.

## L3 — LOW — the scope count runs on every Settings load

`EnvDetector::store_totals()` (`:241`–`:262`) adds `engineCustomers`:
`CustomerBackfillJob::count_in_scope()`, a count over `wp_users` with a
`usermeta` sub-query and an order sub-query (on legacy storage a
`postmeta` ⋈ `posts` join across the history window). It runs on each
wizard and Settings load, including stores that never connect Campaign
Intelligence. On a large store that is seconds of database time per admin
page view.

**Recommendation (backlog):** compute it only when `is_connected()`, or
cache it in a short transient (the import's own `count_total()` stays
exact).

## L4 — LOW — readme.txt does not yet describe the delta

The "External services" list (`readme.txt:85`–`:125`) has no line for:
- §24 — when the site administrator presses "Open Campaign Intelligence",
  the plugin sends **the signed-in administrator's email** to Campaign
  Intelligence for a one-time console link;
- the identity merge at login (pre-existing gap, now wider): the customer's
  email and store user ID, the store's visitor cookie value, and — new —
  the visitor cookie the store sets at login for a customer with marketing
  consent (CON-251);
- §25 — counts and dates of the history import (no personal data);
- "customer records" now means the customers in the §4 scope (CON-246).

The Changelog and Upgrade Notice need the delta's entry, and the upgrade
notice should say that a connected store starts its customers and orders
imports once after the update (CON-247, I11). The `= 3.19.0 =` line "used
only for emails mapped on your main Smaily account and in your store's
default language" describes 3.19.0 correctly but is superseded by CON-218
(the account the engine names, the transactional emails included); keep it
as the record of 3.19.0 and add a "Changed:" line in the new entry, or
reword it if Erkki prefers. The Changelog is 4 586 bytes now (5 000 budget),
so the new entry needs the oldest one dropped.

**Recommendation (at the bump):** the four External-services lines, the
changelog entry, the upgrade notice; Estonian proofread of the docs-site
texts of the delta (STATUS lists them as pending).

## L5 — LOW — fixed here — five Plugin Check false positives

Plugin Check on the first ZIP of this audit reported 22 warnings: the 17
accepted at 3.20.0 plus five new `PluginCheck.Security.DirectDB.
UnescapedDBParameter`:
- `CustomerBackfillJob.php:63` (`$sql`) and `:75` (`$where`): the
  interpolated part is `CustomerScope::where_sql()`, which carries only
  column expressions from code, the `$wpdb` table names and `%s` / `%d`
  placeholders; every value goes through `prepare()`.
- `LegacyOrderDateIndex.php:325`, `:333` (`$add`): `$wpdb->posts`, the
  `INDEX` constant and a fixed column list — no input.
- `LegacyOrderDateIndex.php:361` (`$previous`): cast with `(int)` in the
  same expression.

**Fixed in this PR:** the sniff code joins the existing `phpcs:disable` /
`phpcs:ignore` comments at those lines (comment-only). The re-built ZIP's
Plugin Check is back to the 17 accepted warnings.

## L6 — LOW — fixed here — the GDPR doc missed the login token

`docs/DATA_MODEL_GDPR.md`'s privacy-policy template (EN, and the ET copy)
named the guest checkout token but not the token set at login, that the
cookie's period starts again at each consenting login, or that it stays
after logout; the docs site (`docs/site/index.html`, the policy template)
already said all three. The register table had no row for it.

**Fixed in this PR:** the docs site's EN and ET sentences copied into the
template word for word, and a register row naming `IdentityHookHandler::
on_login()` and its gates. The ET text is the docs site's, so it carries
that text's proofread state.

## L7 — LOW — fixed here — static-tool hygiene in new code

- `OrderBackfillJob.php:99`: `WordPress.DB.PreparedSQLPlaceholders.
  UnfinishedPrepare` on `count_orders()` — the placeholders are in `$where`
  (`sale_orders_where()`). Added to the `phpcs:disable` with a comment
  (same pattern as `CustomerScope`).
- `LegacyOrderDateIndex.php:109`: assignment alignment.
- `LandingCapture.php`: the `send_cookie()` docblock sat above
  `headers_already_sent()` (two docblocks in a row, since be00bb6f), and its
  reason for `HttpOnly=false` ("the beacon proxy reads these cookies
  client-side") was stale: no storefront script reads these cookies since
  the visitor token is read on the server (PRO-3860). Moved to its method;
  the reason now says the scripts write them with `document.cookie` when
  `/land` fails (CON-250), and a script cannot replace an HttpOnly cookie.

## I1 — INFO — `POST /land`

- **Gates, in order:** 404 when not `is_connected()`; `RequestThrottle` 30
  per 60 s keyed on `REMOTE_ADDR` only (never a forwarding header); 403
  unless `Sec-Fetch-Site` is exactly `same-origin` (a missing header too);
  400 when nothing resolves; 204 on success. Every answer carries
  `Cache-Control: no-store, private`.
- **Input:** only `smaily_rec`, `smaily_vt`, `smaily_ctx` are read from the
  form body; `LandingCapture::clean()` refuses non-scalars and
  `sanitize_text_field()`s; `resolve()` keeps a rec id only as a UUID, a
  visitor token only in the engine's `vt_` shape (a link never plants a
  `vs_`), and a context only with a valid rec id. No value is echoed.
- **Cross-site planting:** a page elsewhere cannot set a cookie in a
  shopper's browser (the browser sends `cross-site`); a non-browser client
  can forge the header but only sets cookies in its own jar.
- **Consent:** ungated, like the landing capture (F3-46); behind the
  `smaily_connect_capture_attribution` switch.
- **Fallbacks:** a browser without `Sec-Fetch-Site`, or a store behind a
  proxy that shares one `REMOTE_ADDR` bucket, gets a 403 / 429 and the
  script writes the cookies itself (the pre-CON-250 behaviour). Logged lines
  name slots, never values.

## I2 — INFO — `POST /rec-engine/console-entry`

`admin_check()` requires `is_user_logged_in()` and a valid `wp_rest` nonce
in `X-WP-Nonce` (checked in the route, not only by REST cookie auth), then
`current_user_can( manage_options )`. `open()` answers 403 on a refused
account and 503 unless `sending_allowed()` with a key and base URL. The
email is the signed-in user's own (`is_email()`, ≤ 254 characters) — never
a customer's. One attempt, 3 s, `redirection` 0, no retry (`console_entry()`
single-attempt). The link is returned to the admin's browser only — not
stored, not logged, not in the Event Log; the answer is `no-store`. Errors
map to codes (`api_key_rejected`, `tenant_inactive`, `rate_limited` with
the wait, `engine_unavailable`); no engine message or key reaches the
answer. React opens `about:blank` first with `opener = null` and sets the
URL only after the answer; the "not available" link is
`rel="noopener noreferrer"`.

## I3 — INFO — migrations 012 and 013

- **012 (CON-207):** `RetiredTables::run()` returns `false` (the Migrator
  does not record the step, so it runs again on the next update) when the
  tracker table is missing (`SHOW TABLES LIKE` prepared with `esc_like()`,
  a failed check counts as missing), when the drain cannot read the legacy
  table, or when `CartSessionStore::upsert()` reports a refused write. A
  retried drain writes the same rows again by token, and `reminder_enqueued_at`
  survives, so no second reminder.
- **013 (CON-248):** a dbDelta restatement adding `sent_count INT UNSIGNED
  DEFAULT NULL` — additive, idempotent, same on HPOS and legacy. Counts
  only. Uninstall already drops the table. `ImportRows::record_accepted()`
  updates it with a prepared `sent_count = sent_count + %d … AND sent_count
  IS NOT NULL`; the run key is the job's `started_at`, so a row of an
  earlier run never counts on a later one.

## I4 — INFO — the `wp_posts` index (beyond L1)

Scheduled only (never run in a request) from the hourly recurring-jobs
check and once after a successful setup exchange; run only while
`is_connected()` and on legacy storage with WooCommerce active; `SHOW INDEX`
first, and any index starting `(post_type, post_date_gmt)` counts as present;
an attempt is counted before the `ALTER`, at most 3, a day apart; a
`running` state older than an hour counts as failed. The statements hold no
input. `$wpdb` errors are suppressed for the attempt and restored in
`finally`, as is the session's `lock_wait_timeout`. The stored error has
its quoted parts removed (a denied `ALTER` names the database user and
host) and is cut to 200 characters. The AS group is in
`Deactivation::AS_GROUPS`; the state option is swept by uninstall's
`smly_plus_%` delete; `uninstall.php` loads only this class file and drops
`smaily_connect_order_date` when `SHOW INDEX` finds it, inside a
`try`/`catch` so the cleanup goes on. Noted: a drop that times out on the
5 s lock wait leaves the index after uninstall, silently (harmless; no
retry exists after uninstall).

## I5 — INFO — the visitor token at login

- **Format and entropy:** `vs_` + 22 characters from `[0-9A-Za-z]` by
  `random_int()` (about 131 bits), the contract's store token.
- **Cookie:** set by the server with `setcookie()`: the engine config's
  `tracking_cookie_name` and `cookie_ttl_days` (365 by default),
  `SameSite=Lax`, `Secure` when `is_ssl()`, `HttpOnly` false (L7: the
  scripts' fallback writes it), `COOKIEPATH` / `COOKIE_DOMAIN`.
- **Never in a URL, a log or a store row:** the token goes only in the §7
  merge body (POST, JSON) and the cookie; log lines name slots or "not
  issued"; the store keeps no copy (only the merged anon session id in
  user meta, as before).
- **Gates:** created only when the browser has no valid token, not in cron
  or WP-CLI, with `MarketingConsent::given()` (fail-closed),
  `sending_allowed()`, and a passed profiling gate; a renewal under the same
  gates writes the same value. Without consent no `vs_` token is created or
  sent (PRO-3860); a `vt_` token is sent ungated (F3-46) but not renewed.
- **Shared browser:** as DECISIONS CON-251 records — the next person on the
  browser sees the previous customer's product cards (no personal data in
  §15's answer), a second customer's login keeps the first customer's token
  and the engine does not move it. Also true: that second customer's merge
  tells the engine the first customer's token was seen on their login, and
  a consenting second login renews the first customer's cookie for another
  year. Within the accepted trade-off; noted for the record.
- **GDPR:** the cookie is in the browser; the engine erases its
  `visitor_tokens` with the customer; the policy template now names it (L6).

## I6 — INFO — new engine calls

- **§24 console entry:** see I2.
- **§25 history progress:** its own single AS action (group in
  `AS_GROUPS`), checked every 60 s, a report at most every 5 minutes or on a
  phase change; `sending_allowed()` checked first; one attempt, 3 s; the body
  holds an import id (`woo-<UTC time>-<6 hex>`), a phase, a date and counts
  — the buyers count is `COUNT( DISTINCT billing email )`, a number, never
  the addresses. A failure logs `http=%d error=%s` or the exception class.
- **History-window ping (`HistoryConfig::for_new_import()`):** only with
  `sending_allowed()`, one attempt, in the admin's Import / connect request;
  a value outside `1 ≤ start ≤ horizon` falls back to the stored block or
  18 / 60. No upper bound on `horizon_months` (engine-trusted; noted).
- **Secrets:** no new path logs the key, a URL or a body; the Event Log
  stores exchanges only for queue rows (unchanged).

## I7 — INFO — import pacing and 429 handling

An import run (`process_run()`) raises PHP's limit to 90 s where lower and
allowed, sends at most 5 requests a second for at most 40 s, each request
one attempt of at most 10 s (`import_rec_client()`), and stops at the first
request that fails as a whole. A 429 sets the next run after the engine's
wait (`retry_after_seconds` in the body, else `Retry-After`), clamped to
1–3600 s; a run also stops reading pages while 2 000 rows of its kind wait.
The rows of a failed request are parked for at least the engine's wait
(`AbstractD6Flusher`, uncapped — engine-trusted). The live sync keeps its
own retrying client. A run is wall-clock bounded by its budget; the time
limit only matters on hosts that count wall time.

## I8 — INFO — SQL of CON-246 / CON-245; data minimisation

All new queries go through `$wpdb->prepare()`: table and column names come
from `$wpdb` properties and `OrderBackfillJob::table_spec()`; status lists
are expanded into `%s` placeholders; the `IN ( SELECT … )` fragments carry
only placeholders and literals from code (`'_customer_user'`,
`'_billing_email'`, `meta_value = '1'`). The JSON cursor is written by the
plugin, read back through `decode_window()` (type-checked), and its parts
are bound as `%s` / `%d`; at most about 200 characters, inside
`cursor_value VARCHAR(255)`. `has_pending_before()` with an empty type list
would be invalid SQL, but every caller passes a fixed non-empty list.
**Minimisation:** the engine now gets fewer customers (the contact-sync
audience plus buyers inside the horizon), and the import no longer reads
out-of-scope accounts. Customers already sent before the update and now
out of scope stay in the engine (DECISIONS CON-246, out of scope).

## I9 — INFO — CON-255

The admin cards hide an off automation's values; nothing in the REST
routes or their capability checks changed (`SettingsEndpoint`,
`AutomationsEndpoint` are not in the delta). New server gate:
`AutomationDiscount::issue()` makes no code unless the automation's own
switch is on (`automation_enabled()` reads the same options and defaults as
`HookHandler`'s enqueue gates, and the abandoned-cart status), so a hidden
discount setting cannot issue codes.

## I10 — INFO — the contract copy is stale on `main`

`Contract staleness` run 41459264254 on `cf48442`: "CONTRACT COPY STALE —
sync from engine@a35a94d". The engine's contract moved after the v1.22.0
sync (#293). This audit did not read the new version. A CC-8 sync (byte for
byte, mock and code follow-through) is due before the release, and this
PR's own staleness check will be red for that reason only.

## I11 — INFO — imports start on connected stores after the update

`ImportsOnConnect::start_after_update()` (one time, `smly_rec_import_
sequence_update_checked`) starts the customers and orders imports on every
store that is connected and `sending_allowed()`, unless they completed or
were cancelled. This is DECISIONS CON-247's decision; it means the release
itself sends customer and order history from every connected store. The
upgrade notice should say so (L4).

## I12 — INFO — static gates, catalogues, build

- **PHPCS** on the 40 changed PHP files: 0 errors; warnings before the
  fixes 9 (L7's two plus seven pre-existing / cosmetic — reserved-word
  parameter names, one alignment in `BackfillEndpoint.php:502`). PHPStan
  `[OK] No errors`.
- **Catalogues:** a fresh `bin/build-i18n.sh` gives the same msgid set as
  the committed `.pot` (0 added, 0 gone); the ET `.po` has no empty or
  fuzzy entry. The committed files were restored after the build.
- **`npm run ci:strict` exit 0** on the PR head: PHPUnit unit **1 587 tests
  / 5 504 assertions**, vitest **483 tests in 52 files**, ESLint, tsc.
- **Dependencies:** five open Dependabot alerts on `main` (2026-11-02), all
  in development dependencies, none shipped in the ZIP: `undici` (high) in
  the root lockfile, `fast-uri` (two moderate, two low) in the `blocks/`
  lockfile. Fix them in range per DECISIONS PRO-3858 (backlog).
- Dead code: #276 removed about 230 unreachable legacy lines; no new dead
  code found. No duplicate logic found beyond the deliberate twins
  (`ContactAudience::should_sync_user()` / `user_id_sql()`, the two cookie
  writers), each documented.

---

## Release gate — built ZIP, verify, Plugin Check

- **Build** (worktree on the PR branch, macOS): `npm ci`, `composer install`,
  `npm run build:admin` (bundle-scope check OK),
  `composer run install-block-modules && composer run build`,
  `WP_CLI_BIN=vendor/bin/wp bash bin/build-i18n.sh` (committed `.pot` / `.po`
  restored afterwards), `composer install --no-dev --optimize-autoloader`
  (no `vendor/bin/` this time), `composer run package`, `composer install`.
- **ZIP:** `smaily-connect.zip`, **1 041 543 B**, SHA256
  `f7e44c0081ec6db532c0698b275e3d1dbb62f03dc347a3a46d8207df8479f5c2`,
  build-hash `941287f` (this PR's code commit: `cf48442` plus L5 / L7),
  419 entries. Version still 3.20.0 (the bump comes later); a local
  reference build, not the release asset.
- **`bash bin/verify-release-zip.sh smaily-connect.zip`** (repo root):
  **VERIFY OK** (41 checks).
- **Plugin Check 2.1.0** against the built ZIP in the running dev wp-env
  (unzipped to `wp-content/plugins/smaily-connect-pkg`, never the mounted
  plugin path; `wp plugin check smaily-connect-pkg --slug=smaily-connect
  --format=csv --allow-root --exclude-directories=vendor`; the copy removed
  afterwards): **0 ERRORS / 17 WARNINGS — the 17 accepted at 3.20.0**, two
  files at new lines: `DirectQuery` + `NoCaching` at `BackfillJob.php:677`,
  `IngestQueue.php:504` and `:533` (were `:398`, `:427`), `QueueJanitor.php:229`,
  `EventsEndpoint.php:804`; `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at `GdprHandler.php:696`,
  `CartSessionStore.php:287`, `:311` (were `:282`, `:306`). The first ZIP
  (build-hash `ce23879`, before L5) gave 22: these 17 plus L5's five.

## Release punch-list

1. **M1** — accept (DECISIONS CON-251 addendum), or give the login merge one
   3 s attempt (or a background action).
2. **L1** — Erkki: accept the index fallback as documented (docblock +
   DECISIONS say it may block writes), or record `failed` instead.
3. **I10** — CC-8 contract sync to the engine's current contract.
4. **L4** — readme: External services (§24 admin email, login merge and
   token, customer scope, §25), changelog entry, upgrade notice naming
   I11; the version bump in the nine places; Estonian proofread of the
   delta's docs-site texts before the FTPS publish.
5. **PCP against the CI-built ZIP** after the bump — expect the 17 accepted
   warnings at the lines above.
6. Record this audit's disposition in STATUS.md (the release-bump PR).

Backlog: L2, L3.

## Disposition (Erkki, 2026-11-03)

| # | Disposition |
|---|---|
| M1 (Medium) | **Fixed in #299 (CON-268):** the login merge moves to a background job, so the login no longer waits on the engine. |
| L1 (Low) | **Fixed in #298 (CON-267):** the index is added only by an online build (`ALGORITHM=INPLACE, LOCK=NONE`); a refusal is recorded and never retried with a plain `ADD INDEX`. |
| L2, L3 (Low) | Backlog, as recommended. |
| L4 (Low) | **Fixed in #BUMP (3.21.0 bump):** `readme.txt` External services names the §24 admin email, the login (from a background job: email, store user ID, the visitor cookie and session; the `vs_` cookie set at a consenting login, kept after logout, renewed at each consenting login), the §4 customer scope and the §25 progress counts; the `= 3.21.0 =` changelog adds a "Changed:" line that supersedes the 3.19.0 "main Smaily account" rule (CON-218); the upgrade notice names the imports a connected store starts after the update (CON-247, I11) and the posts-table index (CON-259 / CON-267). |
| L5, L6, L7 (Low) | Fixed in this PR (#296). |
| I10 (Info, the contract-sync blocker) | **Resolved by #297:** the contract copy is synced byte for byte to v1.23.0 (engine `a35a94d`); no wire-shape change, so no code or mock change. |
| Other Info | Accepted as recorded above. |

## Conclusion

The delta adds two REST routes, two schema steps, an index on a core
table, a cookie written at login and three engine calls without widening
access: the routes carry the gates their kind needs, the schema steps are
idempotent and fail closed, the index is added off-request and only where
it is used, the SQL is prepared, the token is random, consent-gated and
kept out of URLs and logs, and the engine gets fewer customers than before.
What needs a decision before the cut is how long a shopper's login may wait
on the engine (M1) and whether the index's fallback may block writes (L1);
the readme and the contract copy need their pass at the bump. Static gates
are clean, `ci:strict` passes, CI on `main` is green apart from the
contract staleness check, the built ZIP verifies, and Plugin Check is back
to the 17 accepted warnings after this PR's comment-only fixes.
**The next release may proceed once M1 is accepted or narrowed, L4 is
handled at the bump, and the contract copy is synced.**
