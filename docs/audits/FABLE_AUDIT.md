# Smaily Connect — Codebase Audit

**Date:** 2026-06-11 · **Auditor:** Claude (Fable 5), read-only audit — no code was
modified. · **Repo state:** commit `906cf3d` (main), v2.0.0-beta.1.
**Method:** direct inspection + three parallel read-only exploration passes
(functionality, security, database/quality); key findings spot-verified by hand.

> **Remediation log (post-audit fixes):**
> - **F1** `66f0a37` — §4#1 (CRITICAL, password in debug.log): all four 2.H.16
>   diagnostic logs removed (regression confirmed resolved by Erkki).
> - **F2** `11e03ce` — §4#3 dead `wp_ajax_smaily_admin_save` registration
>   removed; §3/§7 factual correction: the `smly_plus_automation_mapping`
>   writer DOES exist (`SettingsEndpoint::replace_automation_mappings()`,
>   missed by the exploration pass) — recommendation 7 withdrawn.
> - **F3** `fde2ee5` — §4#2 (IMPORTANT, static IV / AUTH_KEY-prefix leak): Cypher v2 —
>   AES-256-GCM (`smy2:` versioned blob, random nonce), legacy-CBC read
>   fallback, `Activation::reencrypt_legacy_secrets()` migrates all stored
>   secrets on upgrade. Closes the BACKLOG GCM item early (DECISIONS F3-32);
>   proven by `CypherGcmTest` (6 integration tests against the real class).
> - **F4** `ff1436f` — §6 readme.txt drift: full 2.0 rewrite — stable tag →
>   2.0.0-beta.1, 2.0 description + changelog + upgrade notice, and the
>   previously missing external-services disclosure for the recommendation
>   engine (catalog/customer/order/browse data flows, consent gating).
> - **F5** `f2b15fe` — §6 INSTALL.md gap: shopper profiling-opt-out section added
>   (Step-4 subsection incl. the privacy-policy-must-mention-profiling
>   warning, plus troubleshooting + quick-reference rows).
> - **F6** `4e99914` — §5/§7#9 (queue growth): `DB\QueueJanitor` daily AS tick prunes
>   terminal rows (sent 30d / failed 90d, filterable; pending never) in
>   LIMIT-batches from both queues; migration 006 adds `idx_created_at` to
>   both tables. Pulled forward pre-pilot (DECISIONS F3-33).
> - Remaining items tracked below and in BACKLOG.md.

> Scope note: this is a WordPress/WooCommerce plugin, so the section template was
> adapted — "Supabase tables / RLS" becomes custom `$wpdb` tables + `wp_options`
> + access-control checks; "cron endpoints" becomes WP-Cron / Action Scheduler.

---

## 1) Overview & stack

**What it is.** Smaily Connect — a WooCommerce/WordPress plugin connecting a store
to Smaily email marketing **and** a multi-tenant recommendation engine
("rec-engine"). Two coexisting codebases: legacy v1.x (`Smaily_Connect\*`) and the
v2.0 rewrite (`Smaily\Connect\*`, PSR-4 in `includes/`).

**Stack (from composer.json / package.json):**

| Layer | Tech | Version |
|---|---|---|
| Runtime | PHP | ≥ 8.0 (platform-pinned 8.0.99) |
| Platform | WordPress / WooCommerce | WP floor 6.6, tested 7.0 · WC floor 6.9, tested 10.7 |
| Background jobs | woocommerce/action-scheduler | ^3.7 |
| Admin UI | React 18.3 + TypeScript 5.6 + Vite 5.4 + Tailwind 3.4 | current |
| JS tests | Vitest 3.2 + Testing Library | current |
| PHP QA | PHPUnit 9.6, PHPStan, PHPCS/WPCS 3.1, brain/monkey | current |

**Directory structure:**

| Dir | Contents |
|---|---|
| `includes/` | v2.0 PSR-4 core: `Smaily/RecEngine/` (ingest, backfill), `REST/`, `Privacy/`, `Notifications/`, `Multilingual/`, `DB/`, `Wizard/`, `Settings/` + 10 legacy `smaily-*.class.php` files |
| `admin/` | React wizard + settings SPA (`admin/src/`) + legacy admin partials |
| `public/` | Storefront beacon (`public/js/beacon*.ts`, `rec-engine-client.ts`) + legacy partials |
| `integrations/` | Legacy CF7, Elementor, WooCommerce (cart, RSS, cron) integrations |
| `blocks/` | Gutenberg blocks: newsletter-signup, checkout-optin, landingpage |
| `tests/` | `Unit/` + `Integration/` (wp-env, real WP+WC+MariaDB) |
| `bin/` | Live-walk scripts (`walk-3.x-*.cjs`) + integration runner |
| `migrations/` | Versioned SQL schema files + 1 PHP upgrade |
| `docs/`, `spec/` | 29 markdown docs (contract, decisions, lessons, status, install) |

**Size (tracked files, code lines):** 410 tracked files total.

| Dir | Files | Lines |
|---|---|---|
| includes/ | 77 | 14 268 |
| admin/ | 95 | 10 969 |
| tests/ | 69 | 12 781 |
| integrations/ | 18 | 3 865 |
| bin/ | 10 | 2 353 |
| public/ | 11 | 1 442 |
| blocks/ | 19 | 1 434 |
| **Total code** | **~300** | **~47 000** |

---

## 2) Functionality map

Status legend: ✅ complete · 🟡 partial/deferred · ❌ broken. "Live-walked" =
proven against the real engine, not just mocks.

| Feature | Status | Key files |
|---|---|---|
| Smaily credential connect + test | ✅ | `includes/Smaily/Client.php`, `REST/TestConnectionEndpoint.php`, legacy `smaily-client.class.php` |
| Setup wizard (6 steps, React) | ✅ | `admin/src/components/steps/Step1–6*.tsx`, `state/wizard-reducer.ts` |
| Settings tabs incl. Event Log | ✅ | `admin/src/components/settings/`, `REST/SettingsEndpoint.php` |
| Contact/email sync (legacy + new, gated by `setup_completed`) | ✅ | `smaily-lifecycle.class.php`, `Smaily/EventQueue` + flusher, `SubscriberPayloadBuilder.php` |
| CF7 integration | ✅ | `integrations/cf7/{public,service,admin}.class.php` |
| Elementor newsletter widget | ✅ | `integrations/elementor/newsletter-widget.class.php` (~1 500 LOC) |
| Gutenberg blocks (newsletter, checkout-optin, landingpage) | ✅ | `blocks/*/` — all three have real implementations (an earlier exploration pass flagged landingpage as empty; verified false — `blocks/landingpage/src/` is populated and tested) |
| WC abandoned cart + RSS feed (legacy) | ✅ | `integrations/woocommerce/{cart,cron,rss}.class.php` |
| Rec-engine connect (setup-token exchange) | ✅ | `Smaily/RecEngine/SetupExchange.php`, `REST/RecEngineEndpoint.php` |
| Catalog ingest | ✅ live-walked 15/15 | `CatalogPayloadBuilder`, `IngestQueue`, `IngestFlusher` (D6), `CatalogHookHandler` |
| Customers ingest | ✅ live-walked 10/10 | `CustomerPayloadBuilder`, `CustomerFlusher`, `CustomerHookHandler` |
| Orders ingest (HPOS + legacy) | ✅ live-walked 12/12 | `OrderPayloadBuilder`, `OrderFlusher`, `OrderHookHandler` |
| Browse beacon — server proxy | ✅ live-walked 13/13 | `REST/BeaconEndpoint.php` (abuse model: 404-gate, rate-limit, §6 validation) |
| Browse beacon — JS client | ✅ (render-moment = manual pilot check) | `public/js/beacon.ts`, `beacon-core.ts`, `lib/rec-engine-client.ts`, `Integrations/WooCommerce/StorefrontBeacon.php` |
| Backfill (products/customers/orders) | ✅ live-walked 7/7 | `Smaily/RecEngine/Backfill/*`, `REST/BackfillEndpoint.php`, `BackfillPanel.tsx` |
| Identity merge (login → anon session bind) | ✅ live-walked 6/6 | `IdentityHookHandler.php`, `Client::merge_identity` |
| GDPR (WP Privacy exporter/eraser + §10 opt-out) | ✅ live-walked 10/10 | `Privacy/GdprHandler.php` |
| Profiling consent (opt-out model, My Account toggle, beacon two-gate) | ✅ live-walked 9/10 (§10 step env-blocked, 3.8-proven) | `Privacy/ProfilingConsent.php`, `ProfilingConsentAccount.php` |
| Event Log + manual retry | ✅ | `REST/EventsEndpoint.php`, `EventLog.tsx` |
| Health notices (failed>50, engine-down, Smaily-down) | ✅ | `Notifications/NotificationManager.php` |
| Email notification channel | 🟡 deferred post-pilot (3.10.3) | — |
| Multilingual (Polylang/WPML/TranslatePress adapters) | ✅ code; 🟡 Mode B/C router untested | `includes/Multilingual/` |
| Migrations / activation | ✅ | `DB/Migrator.php`, `Activation.php`, `migrations/00*.sql` |
| PWA | n/a — not part of this product | — |

Nothing was found that is *broken* (❌). The 🟡 items are all consciously deferred
and tracked in `BACKLOG.md`.

---

## 3) Database

**Custom tables (5, created via `DB/Migrator` from `migrations/*.sql`):**

| Table | Key columns | Indexes | Used by |
|---|---|---|---|
| `smly_plus_event_queue` | event_type, entity_id, payload, status, attempts, last_error | `(status, created_at)` | Smaily contact-sync queue (`EventQueue`, flusher, `EventsEndpoint`) |
| `smly_rec_event_queue` | + event_uuid (UNIQUE), depends_on_event_id, next_retry_at | `(status, next_retry_at)`, uuid, depends_on | Rec-engine ingest (`IngestQueue`, all D6 flushers, `EventsEndpoint`) |
| `smly_plus_backfill_job` | job_type, target, cursor_value, counts, status | UNIQUE `(job_type, target)` | All backfill jobs + `BackfillEndpoint` |
| `smly_plus_automation_mapping` | trigger_type, language, account_key, workflow_id | UNIQUE `(trigger, lang, account)` | `Multilingual/Router` reads; written by `SettingsEndpoint::replace_automation_mappings()` *(audit correction — initially reported as "writer not located")* |
| `smly_rec_visitor` | visitor_id (PK), email, identified_at, first/last_seen | email | **Created but unused** — schema reserved; browse events intentionally bypass the DB (transient buffer) |

**Options:** ~29 keys. Connection secrets (`smly_rec_api_key`, endpoints map,
tenant info) are `autoload=false` and encrypted; feature flags
(`smly_plus_rec_track_browsing`, `smly_plus_setup_completed`); health state
(`*_down_since`); schema version. Dead Step-4 toggle keys
(`smly_plus_rec_sync_*`) are deleted idempotently on upgrade
(`Activation::cleanup_removed_rec_feature_options()`).

**Meta/transients:** user meta `_smaily_synced_at`, `_smaily_rec_merged_anon_sid`;
order meta `_smaily_rec_id` (HPOS-safe access); rate-limit + browse-buffer
transients; profiling-consent daily TTL cache.

**Access control (the WP equivalent of RLS):** there is no row-level security in
WP — protection is capability checks on every REST route (see §4) plus
`$wpdb->prepare()` on every query touching these tables (verified for queue,
events-filter, and all three backfill cursor queries).

**Mismatches:**
- `smly_rec_visitor` — created, never written (intentional forward-schema; documented).
- ~~`smly_plus_automation_mapping` — reads exist, no writer found~~ — **corrected**: the writer is `SettingsEndpoint::replace_automation_mappings()`.
- No tables used-but-missing were found.

---

## 4) Security review

**Route/auth matrix (all via `register_rest_route`, `REST/EndpointRegistry.php`):**

| Route | Auth |
|---|---|
| `/settings`, `/test-smaily`, `/workflows`, `/backfill/*`, `/rec-engine/*` (exchange/ping/disconnect), `/events`, `/events/detail`, `/events/retry` | `current_user_can('manage_options')` + WP REST cookie-nonce |
| `/beacon` (POST) | **Public by design** — abuse model: hard 404 unless connected + browse-toggle on, per-IP + per-session rate limit, §6 event validation + field whitelist, profiling-consent drop gate |

**Findings, by severity:**

| # | Severity | Finding | Evidence |
|---|---|---|---|
| 1 | **CRITICAL — ✅ FIXED (F1 `66f0a37`)** | **Plaintext Smaily password logged to `debug.log`.** `error_log('[…settings.save_connection] data=' . wp_json_encode($data))` logs the full REST payload — including `smailyCredentials.password` — on every connection save. It is a leftover "Sub-PR 2.H.16 diagnostic" whose own comment says *"Remove this block once the regression is pinned."* `wp-content/debug.log` is often web-readable. | `includes/REST/SettingsEndpoint.php:191` (the second log at :203 is the safe pattern — `password_len` only) |
| 2 | **IMPORTANT — ✅ FIXED (F3 `fde2ee5`, AES-256-GCM + upgrade re-encryption)** | **Static IV derived from `AUTH_KEY` — and stored in the DB blob.** `Smaily_Cypher` (AES-256-CBC + HMAC-SHA256, encrypt-then-MAC with `hash_equals` — that part is sound) uses `substr(AUTH_KEY, 0, 16)` as the IV for *every* encryption, and prepends that IV to the base64 value persisted in `wp_options`. Consequences: (a) deterministic encryption — equal plaintexts produce equal ciphertexts; (b) **the first 16 bytes of `AUTH_KEY` are recoverable from any DB dump/backup**. The CBC→GCM upgrade is already in BACKLOG (post-pilot); this finding raises its priority — a random per-message IV is a small change even before GCM. | `includes/smaily-cypher.class.php:21–25,40–47` |
| 3 | MINOR — ✅ FIXED (F2 `11e03ce`) | Dead AJAX registration: `wp_ajax_smaily_admin_save` hooks a method that does not exist anywhere. Harmless (WP ignores it) but confusing. | `admin/smaily-admin.class.php:81` |
| 4 | MINOR | Beacon rate-limiting rides on transients — on a host with an unreliable object cache the limit may not persist. Acceptable for telemetry; worth knowing. | `REST/BeaconEndpoint.php` |
| 5 | MINOR | `SetupExchange` accepts a merchant-pasted engine URL (scheme+host extracted via `wp_parse_url`, path regex-validated) — an admin could point it at an internal host (SSRF-shaped), but the actor is already `manage_options`. Low practical risk. | `Smaily/RecEngine/SetupExchange.php:76–118` |

**Checks that passed:**
- `.gitignore` covers `.env`, `dist/`, `coverage/`, `vendor/`, `node_modules/`, `*.zip`; nothing of those is git-tracked.
- No hardcoded API keys/passwords in source; live-walk scripts read tokens from temp files/env, never inline.
- Credentials encrypted at rest; rec-engine key `autoload=false`; password never sent to the frontend (`EnvDetector::saved_settings()` excludes it).
- SQL: all user-influenced queries use `$wpdb->prepare()` (queue, events filters with token sanitization, backfill cursors as `%d`).
- Output escaping consistent (`esc_attr`/`esc_html`/`wp_kses_post`) in spot-checked templates, notices, the My Account toggle.
- Admin actions check capability **and** nonce server-side (notification dismiss via nonce'd admin-post; profiling toggle via logged-in + nonce; REST saves via capability) — not just hidden UI.
- Cron/Action Scheduler jobs are internal hooks only; no unauthenticated external trigger path (no CRON_SECRET needed — WP model differs from a public cron URL).
- ABSPATH guards: effectively 100 % (entry points use their own standard guards, incl. `WP_UNINSTALL_PLUGIN` in `uninstall.php`).
- CSRF: beacon is intentionally nonce-less (anonymous telemetry, abuse-modelled); identity merge fires server-side on `wp_login` (not forgeable via CSRF in a meaningful way); profiling toggle nonce'd.

---

## 5) Code quality & debt

**Tests — yes, extensive (gate: `npm run ci:strict` + wp-env integration):**

| Suite | Count |
|---|---|
| PHP unit (PHPUnit, `tests/Unit`) | **310 tests** (39 files) |
| PHP integration (real WP+WC+MariaDB via wp-env) | **90 tests** (28 files) — also proven on a WC 6.9.4 legacy env (75/75) |
| JS (Vitest) | **144 tests** (22 files) |
| Live-walks (against the real engine) | 8 scripts in `bin/`, all domains proven |

**Known gaps:** WC hook-handler classes (~1 200 LOC) have no *direct* unit tests
(covered indirectly by integration); Multilingual Router Mode B/C untested;
browse render-moment + consent-plugin gating are manual pilot checks; the flaky
`useBackfillProgress` fake-timer test is tracked.

**Debt / problem areas:**
- **Legacy↔new duplication (the largest single debt):** two API clients, two
  notice systems (`Notice_Registry` vs `NotificationManager`), two
  options/settings layers, two queues. Coexistence is deliberate (BETA bridge,
  two destinations) but consolidation is unscheduled.
- **TODO markers:** essentially none in code (1 `TODO(phase-4-marketplace-prep)`
  in `integrations/woocommerce/cron.class.php:59`). Deferred work lives in
  `BACKLOG.md` instead — unusually disciplined.
- **Commented-out code:** none of significance.
- **Performance:** no N+1 in critical paths. Watch-items: missing standalone
  `created_at` index for future queue pruning (janitor deferred, queues grow
  unbounded during the pilot); `EnvDetector` user/orders counts unbounded but
  wizard-only; beacon proxy does a synchronous engine call (rate-limited,
  loss-tolerant by design); `orders_count` behaviour at large volume un-load-tested.
- **Dependencies:** current across the board; no unused or stale packages found.
- **Stale leftovers:** the 2.H.16 diagnostic block (see §4 finding 1) and the
  dead ajax hook are the only true leftovers found.

---

## 6) Work in progress / unfinished

- ✅ RESOLVED (F1) — **Leftover diagnostic block** — `SettingsEndpoint.php:178–210`, self-labelled
  "remove once the regression is pinned" (and it logs the password — §4#1).
  Needs confirmation whether the 2.H.15/2.H.16 staging regression was resolved.
- ✅ RESOLVED (F4) — **`readme.txt` drift:** `Stable tag: 1.6.1` vs plugin
  `Version: 2.0.0-beta.1`; changelog ends at 1.6.1; description predates the
  whole rec-engine feature set. Tracked in BACKLOG ("doc-drift, upstream-merge").
- ✅ RESOLVED (F5) — **`docs/INSTALL.md`** lacked the profiling-opt-out section ((a).2 shipped a
  My Account toggle after the doc was written). Tracked.
- **`smly_rec_visitor` table** — created, never populated (intentional reserve).
- **JS `mergeIdentity()` stub** in `rec-engine-client.ts` still throws —
  intentional (M2 platform-agnostic path; the live path is server-side PHP).
- **Deferred by design (BACKLOG):** email notification channel (3.10.3), queue
  janitor + `created_at` index, CBC→GCM, auto-retry classification, drop-count
  UI surface, WP 7.0 integration-matrix run, explicit opt-in flip if the
  regulator tightens.
- **Manual pilot verifications outstanding:** browse render-moment per page
  type; CookieYes consent actually suppressing the beacon; optional §10
  live-walk re-run (needs a fresh setup-token).
- **Env vars:** no `.env`-style gaps — runtime config comes from WP options and
  the engine's endpoints-map after token exchange; the only `.env.example` is
  Docker-dev (USER/GROUP ids), standard.
- **No test/debug endpoints left in production routes** — all registered routes
  are intentional product surface.

---

## 7) Prioritized recommendations

1. ✅ DONE (F1 `66f0a37`) — **Remove the password-logging diagnostic at `SettingsEndpoint.php:191` (or the whole 178–210 block if the 2.H.16 regression is pinned)** — it writes the merchant's Smaily password in plaintext to `debug.log` on every save; this is the one finding that should not reach the pilot.
2. ✅ DONE (F3 `fde2ee5`, went straight to GCM) — **Fix the static-IV cipher before more secrets accumulate** — switch `Smaily_Cypher` to a random per-message IV now (tiny change), GCM later as planned; today every DB backup leaks an `AUTH_KEY` prefix and ciphertexts are deterministic.
3. ✅ DONE (F4 `ff1436f`, full rewrite incl. external-services disclosure) — **Sync `readme.txt` with v2.0.0-beta.1** — a stable tag pointing at 1.6.1 will break/confuse any update or marketplace flow and misdescribes the product.
4. ✅ DONE (verified during F1: the remaining ~25 `error_log` calls are operational error messages, no payload dumps) — **Close the leftover-diagnostic loop** — `SettingsEndpoint` has four `error_log` calls; verify each survives the "would I want this in a merchant's debug.log?" test.
5. ✅ DONE (F5 `f2b15fe`) — **Add the profiling-opt-out section to `docs/INSTALL.md`** — merchants need the shopper-facing toggle documented before pilot support requests arrive.
6. ⏳ OPEN (pilot-time by nature) — **Run the planned manual pilot verifications early in week 1** (browse render-moment, CookieYes gating) — they're the only untested behaviour that ships to shoppers.
7. ~~**Clarify the `smly_plus_automation_mapping` writer**~~ — withdrawn: the writer exists (`SettingsEndpoint::replace_automation_mappings()`); the audit's exploration pass missed it.
8. ✅ DONE (F2 `11e03ce`) — **Delete the dead `wp_ajax_smaily_admin_save` registration** — one line, removes a confusing phantom endpoint.
9. ✅ DONE (F6 `4e99914`, pulled forward pre-pilot) — **Schedule the queue janitor + `created_at` index** — both queues currently grow without pruning; cheap now, painful at scale.
10. ⏳ OPEN (BACKLOG, when next touched) — **Add direct unit tests for the WC hook handlers** — ~1 200 LOC of glue covered only indirectly; a regression there fails silently until an integration run.

---

*End of audit. No files were modified other than the creation of this report.*
