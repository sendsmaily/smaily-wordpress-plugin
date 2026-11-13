# Security + code-quality delta audit — pre-3.22.0 (3.21.1..afbac7f)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-04_PRE_3.21.1.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-04_PRE_3.21.1.md),
which covered `3.21.0..79d3802`. That report stays valid for what it covered.
This one covers everything that landed on `main` after the 3.21.1 tag.

- **Date:** 2026-11-13
- **Baseline:** tag `3.21.1` (`65fc009`). Delta `3.21.1..afbac7f` on `main`:
  16 commits, 123 files, +7 414 / −494. The shipped PHP in the delta is
  **48 files, +1 874 / −288**, plus the admin bundle (`EmailDesigns.tsx`,
  the API clients, Settings), `admin/wizard.php`, `.po` / `.pot` strings,
  `readme.txt` and the docs site.
  - **#321 / #323 (CON-290) — installation report.** New
    `Report\InstallationReport`, `Report\ReportBuilder`,
    `Report\ConnectionHealth`: a new outbound HTTPS POST to
    `https://subscription.smaily.com/v1/reports`, authenticated with the main
    Smaily account's API user and password in Basic auth; a `setup_saved`
    snapshot 5 minutes after a settings save or a Campaign Intelligence
    connect, a daily snapshot, and inline `disconnected` / `deactivated`
    events. Two new action hooks fired by the two HTTP chokepoints
    (`smly_plus_smaily_api_result`, `smly_plus_engine_api_result`) feed a new
    option `smly_plus_connection_health`. New AS group
    `smaily-connect-installation-report`. A must-use guard plugin
    (`tests/Integration/Fixtures/mu-plugins/`) mounted by `.wp-env.json` /
    `.wp-env.min.json` and loaded by the integration bootstrap. New
    `Router::mapped_triggers_by_account()` query, `Credentials::option_keys()`
    / `subdomains()`, `TransactionalGate::any_enabled()`.
  - **#308 (CON-289) — setup-exchange account check** (contract v1.25.0 §1
    `smaily_subdomain`): `SetupExchange::is_store_account()`, kind
    `account_mismatch`, REST error `smaily_account_mismatch`.
  - **#309 (CON-288) — design window in the admin:** `EditDesign` opens the
    engine's §22 design page in an iframe over the admin where
    `EmailDesignsEndpoint::frameable()` allows it; `engine_origin` on the
    design-link answer; `Support\Url::origin()`.
  - **#310 / #312 (CON-277 / CON-301) — codes, never message text,** in logs,
    stored errors, the Event Log exchange and REST answers (both HTTP clients,
    `RetryPolicy`, `AbstractD6Flusher`, `IngestQueue`, `GdprHandler`,
    `ProfilingConsent`, `LoginMerge`, `CatalogManifest`, the `/relay`
    answer, the admin proxies); `DebugLog::where()` / `where_with_code()`,
    `ApiException::summary()`.
  - **#313 / #315 / #319 (CON-285 / CON-284 / CON-292) — history import
    chain:** one tick chain and one pending check per import
    (`AbstractBackfillJob::action_pending()`, `/backfill/start`), one final
    §25 report with `stop_reason` for a stopped import, the re-run marker read
    inside the lock, a catalog part that could not start on connect is
    started by the next check (`smly_rec_import_sequence_start_due`).
  - **#311 (CON-302) — Magento-parity gaps:** a third CI integration leg on
    the oldest supported stack; the installed version at the foot of
    Settings; the personal-data exporter / eraser also find the WP user's
    orders by customer id; a queue row whose payload throws fails alone
    (`not_built`); a contact import skips a contact whose payload throws.
  - **#320 (PRO-4253) — cart storefront language:** `CartHookHandler`
    records the multilingual plugin's current language in the cart
    tracker's `cart_content` blob; `ContactLanguageResolver::for_user()` /
    `for_guest()` take it first for the abandoned-cart reminder.
  - **#318 (PRO-4252) — storefront form fields:** Phone, Gender and Birthday
    no longer printed on checkout / My Account / registration
    (`hide_in_*` true), wp-admin profile only.
  - **#322 (CON-309) — gender mapping:** `SubscriberPayloadBuilder::gender_label()`
    (`1` Male, `2` Female, else omitted), used by both contact syncs.
  - **#316 / #323** — refactors after simplification reviews; #314 / #317
    STATUS only.
- **Auditor:** Claude (Opus 5.5).
- **Trigger (re-audit policy):** point 1 (the 3.22.0 release boundary),
  point 2 (> 2 000 changed lines) and point 3: new external HTTP that carries
  credentials (subscription service), a new option holding remote error
  codes, public REST answers changed (`/relay`), a new admin REST error kind,
  custom-table SQL (the Router query, the GDPR order-id query), GDPR export /
  erasure scope, an untrusted stored blob (`cart_content`), a cross-origin
  iframe + `postMessage` listener in wp-admin.
- **Scope:** read of the whole shipped PHP diff and the code it reaches:
  `InstallationReport` (send, schedule, retry, connect timeout, events),
  `ReportBuilder` (what goes in the body, which credentials authenticate),
  `ConnectionHealth` (what the hooks carry, what is stored, write rate),
  `Smaily\Client::request()` and `RecEngine\Client::request_url()` (where the
  result hooks fire, what they carry), `RefusalReason::classify()`,
  `RetryPolicy` (envelope refusals), `SettingsEndpoint::save()` /
  `save_connection()` / `report_cleared_accounts()` /
  `save_transactional_account()` / `persist_credentials()`,
  `RecEngineEndpoint` (exchange, ping, disconnect), `SetupExchange`,
  `ExchangeResult`, `Credentials`, `Deactivation::run()`, `Bootstrap`
  (wiring, `register_action_scheduler_jobs`), `uninstall.php` (option and AS
  sweeps), `Router::mapped_triggers_by_account()`, `GdprHandler::order_ids_for()`
  / `order_ids_sql()`, `CartHookHandler::storefront_language()`,
  `CartSessionStore::upsert()` / `encode_content()` / `decode_content()`,
  `CartPayloadBuilder`, `ContactLanguageResolver`, `Profile_Settings` (the
  save handlers' visibility filter), `Data_Handler`, `BeaconEndpoint`,
  `EmailDesignsEndpoint`, `admin/src/components/steps/EmailDesigns.tsx`
  (link check, frame, message listener), `HistoryProgress`,
  `ImportsOnConnect`, `AbstractBackfillJob`, `BackfillEndpoint::start()`,
  `AbstractD6Flusher`, `BackfillJob`. A sweep of every remaining
  `getMessage()` / `get_error_message()` in `includes/`. Read against
  `docs/RECENGINE_API_CONTRACT.md` (v1.29.0) §1 and §25,
  `subscription` `docs/contracts/installation-report-v1.md` (Authentication,
  Connections, Error codes, Addresses), DECISIONS "CON-290" and
  "PRO-4252". No real customer data was used.
- **Method:** static read. CI on `afbac7f`: "Lint and test the codebase"
  (run 43427120864 — PHP 8.0–8.3, admin bundle, blocks, and the three
  integration legs incl. the oldest stack) and "Contract staleness" (run
  43427123089) green. `release.yml` `workflow_dispatch` dry-run on `main`
  (run 43427330476) built the ZIP; VERIFY and Plugin Check below. Not run:
  the integration suite locally, any live walk, any call to the engine or to
  the subscription service.

## Verdict

**0 Critical, 0 High, 0 Medium, 3 Low, 8 Info.** No code is changed in this
PR.

**RESULT: 3.22.0 may proceed.** L1 is two Plugin Check warnings with the
established fix (a justified `phpcs:ignore`, or accept them as warnings 18
and 19). L2 is a reporting-accuracy gap in the new health record. L3 is a
product decision on an off switch for the installation report.

The installation report sends what the readme says and nothing more: site
address, versions, Smaily subdomains, the CI store id, feature ids and error
codes — no customer, order or admin data. The credentials go only into the
Authorization header, over verified TLS, with redirects off, a 5 s total and
3 s connect timeout, never into a log line, and only while a Smaily
marketing account is connected. The result hooks carry no customer
identifier: the Smaily side passes a fixed endpoint name, the engine side
passes a path only when it contains `/ingest/` (never the §8–§10 customer
URLs, which hold the address) and never a query string. The CON-301 sweep
removed every engine or Smaily message text from logs, stored errors, the
Event Log exchange and REST answers, the public `/relay` answer included.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| L1 | Low | `includes/Smaily/Client.php:424`, `includes/Smaily/RecEngine/Client.php:855` | Plugin Check reports two new `WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound` warnings on the new `do_action( self::RESULT_HOOK, … )` calls: 19 warnings, the 17 accepted + these 2 | At the bump: add the justified `phpcs:ignore` the other constant-named hooks carry, or accept them as warnings 18–19 |
| L2 | Low | `includes/Smaily/Client.php:412`; `includes/Report/ConnectionHealth.php:66`–`:72` | An HTTP 2xx reply is announced as a success before `RetryPolicy::throw_if_refused_envelope()` reads Smaily's body code, so a POST that Smaily refuses in its `{code, message}` body (203, 221, 225, …) records `ok` and a fresh `last_sync_at`. A store whose every contact write is refused that way reports a healthy, syncing connection | No — backlog: announce from the envelope check, or have the flushers record the refusal |
| L3 | Low | `includes/Report/InstallationReport.php`; `readme.txt:101`–`:103` | The report has no off switch short of disconnecting the Smaily account: no setting, no filter. Connecting the account is the opt-in under wordpress.org guideline 7 and the readme discloses it, but a reviewer or a merchant may ask for one | No — Erkki decides: a `smaily_connect_installation_report` filter (default on) is a one-line guard in `main_credentials()` |
| I1 | Info | `includes/Report/InstallationReport.php:203`–`:216` | The store's Smaily API user and password now also go to a second Smaily host, daily and on each event. Contract-mandated (the service checks them with one Smaily call, never stores or logs them); TLS verified, no redirect, header only, never logged here | No — accepted (DECISIONS CON-290) |
| I2 | Info | `includes/Report/ConnectionHealth.php:114`–`:143`; `includes/Smaily/RecEngine/Client.php:840` | Health noise and races: an expected engine 4xx (`customer_not_found` on a GDPR export or a login merge) marks Campaign Intelligence `failing` until the next success (contract-consistent: "the most recent request failed"); the whole option is read-modify-written by AS runners and shoppers' `/relay` requests, so one group's change can be lost; a flapping engine writes the option on shopper requests at each status change | No — accepted |
| I3 | Info | `includes/Report/ConnectionHealth.php:66`–`:72`; `includes/REST/TestConnectionEndpoint.php:181` | Every subdomain that ever answered gets a health entry and keeps it — the wizard's "Test connection" with a mistyped subdomain included. Admin-only input, a few bytes each, removed at uninstall; never reported (the report reads only connected accounts) | No — backlog (prune entries of accounts no longer stored) |
| I4 | Info | `includes/Smaily/CartAbandonmentSweeper.php:145`; `includes/Migration/LegacyCartDrain.php:203`; `includes/Smaily/TransactionalPayloadBuilder.php:291`; `includes/Smaily/RecEngine/SetupExchange.php:250` | Four places still log or show a non-remote message text: three debug-log lines with a local Throwable's `getMessage()` (CLAUDE.md: "Any other Throwable: its type and file:line — never getMessage()"), and the setup exchange shows the transport's `WP_Error` message to the admin (the clients now keep the code only). None is an engine or Smaily message | No — backlog: `DebugLog::where()` / the error code |
| I5 | Info | `includes/Settings/Credentials.php:127` | `option_keys()`'s docblock says it runs "on a setup-link connect and on (de)activation only"; `ReportBuilder` now calls it on every build and every `main_credentials()` in Mode A (settings saves, the hourly arm, each report) | No — docblock fix with the next touch |
| I6 | Info | `includes/Smaily/Client.php:49`; `includes/Smaily/RecEngine/Client.php:79` | The two new action hooks are public once 3.22.0 ships (any plugin can listen), but are named in the internal `smly_plus_` prefix while documented extension points use `smaily_connect_`. Renaming after release breaks listeners | No — decide the name before the cut, or record them as internal |
| I7 | Info | `includes/Report/ReportBuilder.php:108`, `:126`, `:323`–`:325` | Report shape edges against installation-report-v1: `installation_url` / `store_url` are not capped at the contract's 255 characters (the service would refuse; logged once, not retried); in Mode A only the first connection carries `intelligence`; leaving Mode A drops the language connections from the snapshot without a `disconnected` event (the credentials stay stored, so the contract's "deletes" rule is not triggered) | No — accepted; first item backlog |
| I8 | Info | `includes/Smaily/RecEngine/SetupExchange.php:270` | An `account_mismatch` consumes the one-time token and drops the per-connection key the engine issued, without a §16 revoke (the plugin never stores it). Contract §1 asks for exactly this; whether the engine expires such an orphan key is the engine team's | No — accepted; question to the engine team |
| I9 | Info | `admin/src/components/steps/EmailDesigns.tsx:94`–`:99`, `:298`–`:318`, `:407` | The design iframe has no `sandbox` attribute. The link must start with `engine_origin + '/'` (the stored, host-pinned engine origin), and the message listener takes only that exact origin and only `closed` / `saved`; first-party page | No — accepted |

---

## L1 — LOW — two new Plugin Check warnings

Plugin Check on the CI-built ZIP reports `DynamicHooknameFound` at
`Smaily/Client.php:424` and `RecEngine/Client.php:855`: the new
`do_action( self::RESULT_HOOK, … )` calls. PCP cannot resolve a class
constant; the values (`smly_plus_smaily_api_result`,
`smly_plus_engine_api_result`) carry the plugin's prefix. `phpcs` stays
silent because the local ruleset does not run that sniff on constants. The
same class of warning is suppressed at `CartAbandonmentSweeper.php:79` and
`ContactLanguageResolver.php:228` / `:311` with a one-line justification, and
accepted (not suppressed) at four other places.

**Recommendation (at the bump):** the same `phpcs:ignore` with its
justification on the two lines (taking I6's naming decision into account),
or accept them as warnings 18 and 19.

## L2 — LOW — an envelope refusal is recorded as a sync

`Smaily\Client::request()` fires the result hook with `''` for every 2xx
reply (`:412`) and returns. Smaily answers many refusals with HTTP 200 and a
body code other than 101; the flushers and the contact import find them only
afterwards, in `RetryPolicy::throw_if_refused_envelope()` (`Flusher.php:149`,
`CartFlusher.php:160`, `TransactionalFlusher.php:626`, `BackfillJob.php:462`).
`ConnectionHealth::record_smaily()` therefore stores status `ok` and, for a
POST, a new `last_sync_at`. The contract defines `last_sync_at` as the last
write that **succeeded** and `failing` as "the most recent request failed".
A store whose contact writes all come back with, say, 203 reports a healthy
account that synced minutes ago — the case the report exists to show.
Nothing is sent wrongly and no data is exposed; the report is optimistic.

**Proposal:** fire the hook from the envelope check (or let `request()`
read the body code itself: 101 = success, 227 = `plan_blocked`, other =
`credentials_rejected` or a new code the contract's "any other code" rule
accepts). Backlog.

## L3 — LOW — no off switch for the installation report

The report starts once a Smaily marketing account is connected and stops
only when none is. There is no setting and no filter. Guideline 7 counts
"registration with a service" as consent, and the readme's External services
section describes the report with the Smaily privacy and terms links, so the
plugin meets the guideline as written. Still, it is usage telemetry
(versions, feature ids, connection health), a reviewer may ask about it, and
a merchant who wants Smaily without it has no way out. A filter costs one
line in `ReportBuilder::main_credentials()` (null = nothing scheduled,
nothing sent) and keeps the default.

**Decision for Erkki** before the cut or after it.

## Info notes

- **I1** — Contract installation-report-v1 §Authentication: the service
  makes one Smaily read with the credentials, caches an HMAC of them, never
  stores or logs them. Plugin side confirmed: header only, `sslverify` true,
  `redirection` 0, body ≤ 64 KB, the log line holds the event, trigger, HTTP
  status and a pattern-checked error code.
- **I2** — `ConnectionHealth` writes a success at most every 5 minutes and a
  change at once. On a storefront the engine chokepoint is reached by
  `/relay` and the recommendations route; a flapping engine therefore costs
  one option write per shopper request at each flip. Concurrent writers can
  lose an update of the other group; the next answer of that group restores
  it.
- **I4** — The text in those three log lines is local (a merchant's filter, a
  cart row's shape), not remote, so CON-301 proper is met; the CLAUDE.md
  wording is broader. The setup-exchange `WP_Error` message is a cURL text
  naming the engine host.
- **I7** — `home_url()` has no query or fragment; only length is unchecked.
- **I9** — The admin's frame decision is server-side
  (`EmailDesignsEndpoint::frameable()`: https and the site URL's origin =
  the admin origin); elsewhere the page opens in a new tab with
  `opener = null`.

## Confirmed clean

- **What the report carries.** `ReportBuilder` reads versions, `home_url()`,
  subdomains (pattern-checked), feature flags, the CI tenant id,
  `sending_allowed()` and the stored health codes — no customer, order,
  admin or free-text field; never the engine API key or tenant name. It runs
  only from its AS action (never a page request) and from the two inline
  events (an admin REST request and the deactivation hook), each bounded by
  the 5 s timeout and never thrown.
- **Credentials.** The main account authenticates (default, else the first
  language account); a Transactional account alone sends nothing. The
  password is decrypted only into the header.
- **The result hooks.** The Smaily hook carries the subdomain, a
  RefusalReason code, the method and a fixed endpoint name; the engine hook
  carries the engine's `error` code (stored only when it matches
  `^[a-z0-9_]{1,64}$`, else `unknown_error`), the method and an `/ingest/`
  path only. No email, no query string, no message text (verified for the
  §8–§10 customer URLs, whose path holds the address).
- **Scheduling and cleanup.** The AS group is in `Deactivation::AS_GROUPS`;
  `uninstall.php`'s `smly_plus_%` option and AS sweeps cover the new option
  and hook; the option is `autoload=false`. A settings save schedules at most
  one pending `setup_saved` (`action_pending()`); retries are capped at
  three, a Retry-After over an hour ends the try.
- **Dev and test guard.** The must-use guard answers a
  `subscription.smaily.com` request with a `WP_Error` at `PHP_INT_MAX`
  priority; it lives under `tests/`, which `.zipignore` excludes — the ZIP
  has no `mu-plugins` or `tests` entry. The running dev wp-env on this Mac
  mounts an older 3.21.1 checkout without the report code, so it sends
  nothing.
- **REST.** No new route. `/settings`, `/rec-engine/setup-exchange`,
  `/rec-engine/disconnect` keep their permission checks; the new 400
  `smaily_account_mismatch` carries a translated sentence with the engine's
  lower-cased subdomain, rendered by React as text. The public `/relay`
  answer now carries only the engine's code and `{index, field}` per
  refusal. The admin proxies answer the code; the ping words its own
  sentence.
- **CON-301 sweep.** Both clients store the `WP_Error` code, not its text;
  the Smaily exchange drops the body's top-level `message`; the engine
  `ApiException` message (still the remote text) is no longer logged,
  stored or returned anywhere — every remaining `getMessage()` in
  `includes/` is a Smaily `ApiException` (plugin-built) or I4's local cases.
- **SQL.** `Router::mapped_triggers_by_account()` and
  `Credentials::option_keys()` are `prepare()`d with a constant table name /
  `esc_like()`d prefix. The GDPR order-id SQL adds `customer_id = %d`
  (HPOS) / `_customer_user = %s` (legacy) as a second placeholder, the
  address condition parenthesised, `DISTINCT` against double matches;
  `$args` match the placeholders in both shapes.
- **GDPR.** The exporter and eraser now also cover the WP user's orders
  placed under another billing address (WooCommerce's own eraser does the
  same); the eraser still drops waiting updates and login jobs before the §9
  call.
- **Cart blob.** `decode_content()` keeps `language` only when it is a
  string; the resolver normalises it and clamps it to the site's active
  languages at send time, and a store without a per-request language
  (`SiteLocaleAdapter`, TranslatePress) records nothing (F3-47 holds).
- **Storefront forms.** The save handlers write only fields visible on that
  form (`smaily_is_field_visible()`), so hiding Phone / Gender / Birthday also
  stops the storefront from writing them, and leaves stored values as they
  are.
- **Gender.** One mapping for both syncs; an unknown value is omitted, so
  Smaily keeps what it holds.
- **Setup-exchange account check.** Runs after the host checks and before
  the key is stored or an import starts; fails open only when the engine
  names no account or the store holds none (contract §1).
- **History import chain.** One tick chain per import (the pending tick is
  replaced; a running one is not doubled); one pending check; the final
  `stop_reason` report waits for the stopped part's queued rows (UTC on both
  sides of the `created_at` comparison) and ends the import once sent.
- **Code quality.** The new classes document their contract, their triggers
  and what they never carry; DECISIONS CON-290 / PRO-4252, CLAUDE.md and the
  readme / docs site were updated in the same PRs. Unit tests pin the report
  body, the health record, the clients' hooks and the settings events;
  `tests/Integration/InstallationReportTest.php` runs against the guard.

## Release gate — CI-built ZIP, verify, Plugin Check

- **ZIP:** `release.yml` `workflow_dispatch` on `main`, **run 43427330476**
  (head `afbac7f`, green), downloaded as the workflow artifact
  `smaily-connect-zip` (not a release asset). `smaily-connect.zip`,
  **1 072 164 B**, SHA256
  `4c92d8ce2a63262458c396f27ac89f66c09412eb64d85306ce4b147c0a65622a`,
  build-hash `afbac7f`, 425 entries. Version 3.21.1 (the bump comes in its
  own PR).
- **`bash bin/verify-release-zip.sh smaily-connect.zip 3.21.1`** (repo
  root): **VERIFY OK** (42 checks).
- **Plugin Check 2.1.0** in the running dev wp-env cli container (unzipped to
  `wp-content/plugins/smaily-connect-pkg`, never the mounted plugin path, not
  activated; `wp plugin check smaily-connect-pkg --slug=smaily-connect
  --format=csv --exclude-directories=vendor --allow-root`; the `-pkg` copy
  and the copied ZIP removed afterwards, the mounted plugin checked intact):
  **0 ERRORS / 19 WARNINGS — the 17 accepted plus L1's two.** The 17, some
  at moved lines: `DirectQuery` + `NoCaching` at `BackfillJob.php:700` (was
  `:677`), `IngestQueue.php:507` and `:536` (were `:504`, `:533`),
  `QueueJanitor.php:229`, `EventsEndpoint.php:804`; `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at `GdprHandler.php:708`
  (was `:696`, now with the account flag), `CartSessionStore.php:310`, `:334`
  (were `:287`, `:311`). New: `DynamicHooknameFound` at
  `Smaily/Client.php:424` and `RecEngine/Client.php:855` (L1). Nothing from
  `includes/Report/`.

## Release punch-list

1. **L1** — the two `phpcs:ignore` lines at the bump (with I6's naming
   decision), or accept 19 warnings.
2. **L3** — Erkki: an off-switch filter for the installation report, or
   accept as is.
3. The 3.22.0 bump (nine places), readme changelog and upgrade notice (the
   installation report is the headline: it is a new outbound connection),
   the Estonian proofread of the new `.po` strings and docs-site pairs.
4. **PCP against the CI-built ZIP of the bump** — expect 17, or 19 without
   L1's fix.
5. Backlog: L2, I3, I4, I5, I7 (URL length).

## Conclusion

The delta's one new external connection, the installation report, sends
what the readme and the contract name, authenticates the way the contract
asks, and stays off shoppers' pages; its health record carries codes, never
a customer identifier or a remote text. The CON-301 sweep closed the last
paths where an engine or Smaily message reached a log, a stored row, the
Event Log or a browser. The setup-exchange account check, the design window,
the history-import chain fixes, the GDPR scope widening and the cart
language are sound. Three Low items remain: two Plugin Check warnings with a
known fix, an optimistic health record, and an off switch to decide.
**3.22.0 may proceed.**
