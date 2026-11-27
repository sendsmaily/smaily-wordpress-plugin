# Security delta audit — before 3.27.0 (3.26.0..54e057f)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-26_3.26.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-26_3.26.0.md)
and the 3.26.0 release-gate row in [`INDEX.md`](INDEX.md). Both stay valid for
what they covered. This one covers everything that landed on `main` after the
3.26.0 tag, ahead of the 3.27.0 release. The code-quality half of the same
pass is [`CODE_QUALITY_DELTA_AUDIT_2026-11-27_3.27.0.md`](CODE_QUALITY_DELTA_AUDIT_2026-11-27_3.27.0.md).

- **Date:** 2026-11-27
- **Baseline:** tag `3.26.0` (`4595667`). Delta `3.26.0..54e057f` on `main`:
  11 commits, 79 files, +6 821 / −380. The shipped code (PHP under
  `includes/`, `admin/wizard.php`, the admin bundle source without its tests)
  is **40 files, +2 232 / −264** (PHP 20 files +1 222 / −188, TypeScript 20
  files +1 010 / −76), plus the two redrawn icons; the rest is tests, the
  catalogs, STATUS / DECISIONS / CLAUDE.md and the docs site.
  - **#395 (CON-442):** a ticked checkout is sent as subscribed
    (`is_unsubscribed = 0`) in every mode; under legitimate interest a
    registered buyer's tick gets its own `order:<id>` contact-sync row.
  - **#399 (CON-457):** an order's language is read from WPML's
    `wpml_language`, else TranslatePress's `trp_language`, else Polylang for
    WooCommerce's `PLLWC_Data_Store` API (guarded, `Throwable` caught).
  - **#400 (CON-446):** in the account-per-language mode (Mode A) each
    contact goes to its language's Smaily account
    (`Smaily\ContactAccount`); a `contact.sync` row records `account_key`;
    the contact import routes per contact; the boot payload carries
    `perLanguageAccounts` (no password); an empty main-account password in
    Mode A is kept from the named language account.
  - **#401 (CON-471 / CON-472):** the daily sync-back reads every distinct
    account with a cursor per account; `ProfilingConsent` reads (and writes)
    the contact's own account, caches keyed per account.
  - **#402 (CON-464):** a Dashboard page and a Log page, a new admin route
    `GET /dashboard` (`REST\DashboardEndpoint`), the menu Dashboard / Initial
    setup / Settings / Log with a finished-setup redirect on each page's
    `load-` hook; `NotificationManager::failed_last_24h()` becomes the one
    count.
  - **#403 (CON-467):** on multisite a network index
    (`smly_plus_network_accounts`, `Smaily\NetworkAccounts`) of each site's
    Smaily subdomains and contact sync mode; the boot payload carries
    `networkSites` (name, address, mode).
  - **#404 (CON-475):** `ProfilingConsent` answers its fallback when the
    Smaily client cannot be built.
  - **#396, #397, #398 (CON-463 / CON-418 / CON-456):** a User Guide link,
    Estonian wording, icons. **#394:** docs only.
  - Open, not in this delta: #405 (regenerated catalogs) and #406 (the
    `build-i18n.sh` purge guard).
- **Auditor:** Claude (Opus 5.5).
- **Method:** static read of the shipped diff and the code it reaches
  (`AdminSession::admin_check()`, the REST route list, `Bootstrap::
  smaily_client()` and every factory that wraps it, the Flusher / CartFlusher /
  TransactionalFlusher / AutomationRouter dispatch paths, `ContactAudience`,
  `ContactSyncMode`, `ContactReconciler`, `BackfillJob`'s refresh and
  freshness rules, `ProfilingConsent`'s caches and durable registry,
  `Credentials` option resolution, `uninstall.php`, `Deactivation`,
  `QueueJanitor` retention, the boot payload in `admin/wizard.php` and
  `EnvDetector`), against DECISIONS CON-442 / CON-446 / CON-457 / CON-464 /
  CON-467 / CON-471 / CON-472 and CLAUDE.md's rules (CON-301 error texts,
  PRO-1742 sync switch, CON-395 stale rule). PHPCS / PHPStan / ESLint / TS
  suppressions added in the delta were listed and checked. A release ZIP was
  built from `54e057f` and verified (see "Release gate").

## Verdict

**0 Critical, 0 High, 1 Medium, 0 Low, 6 Info.** One consent defect in the
account-per-language mode: the delta routes a contact by its current language
only, while the contacts synced before it (and any contact whose language
changes) stay in another Smaily account — so a store-side opt-out does not
reach the account that holds the contact, and under legitimate interest the
weekly refresh creates a subscribed copy of a contact who unsubscribed. No
other security defect. **RESULT: 3.27.0 may proceed once M1 is fixed or
Erkki accepts it in DECISIONS ("CON-446"), and its release gate — Plugin
Check on the built ZIP — is green.**

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| M1 | Medium | `includes/Integrations/WooCommerce/HookHandler.php:767`, `:911`; `includes/Smaily/Flusher.php:284`; `includes/Smaily/BackfillJob.php:502`; `includes/Privacy/ProfilingConsent.php:169` | Mode A routes a contact by its current language only; a contact kept in another account misses the store's opt-out there, gets a subscribed copy in the new account under legitimate interest, and its profiling objection in the old account is not read | Fix, or accept in DECISIONS |
| I1 | Info | `includes/REST/DashboardEndpoint.php:183` | The Dashboard counts scan 30 days of rows with a `LIKE` on `last_response`; no row cap, bounded by the janitor's retention | No — accepted |
| I2 | Info | `includes/Smaily/NetworkAccounts.php:99`; `includes/REST/SettingsEndpoint.php` | A site admin can save an untested subdomain and read which other network sites (name, address, mode) use it | No — accepted |
| I3 | Info | `includes/Integrations/WooCommerce/HookHandler.php:670` | A ticked checkout resubscribes any address it names, before payment; CON-442 extends this to legitimate interest | No — product decision |
| I4 | Info | `includes/Privacy/ProfilingConsent.php:129`; `includes/Smaily/ContactAccount.php:82` | In Mode A each profiling decision runs local reads (user, meta, latest order, option scan, decrypts) before the cache, on storefront requests | No — accepted in DECISIONS CON-472 |
| I5 | Info | `includes/REST/SettingsEndpoint.php:376`, `:797` | An empty password keeps a stored password while the subdomain or user changes; since CON-446 also across options | No — admin only |
| I6 | Info | `includes/Bootstrap.php:663`; `includes/Smaily/ContactReconciler.php` | An opt-in in any account's action log sets the WP opt-in flag, which then syncs the contact to its language account | No — accepted in DECISIONS CON-471 |

### M1 — the account-per-language mode routes a contact by its current language only

`ContactAccount::for_language()` picks the account of the contact's language
*now*. DECISIONS CON-446 accepts that "contacts that already reached the
`default` account stay there; a contact whose language changes later goes to
the new language's account and the old account keeps its copy." Before #400
every Mode A contact went to `default` (since CON-441 the fallback language's
account). So after the update every contact of a non-fallback language has its
copy in `default` and is routed to its own language's account from now on —
and since CON-457 an order in another language can move the routing too. The
decision does not state what that does to consent, and the code carries no
status between the accounts:

1. **An opt-out made in the store does not reach the account that holds the
   contact** (consent mode). Unticking the newsletter box in My Account sends
   `is_unsubscribed = 1` through `enqueue_consent_change()` (`:911`) →
   `maybe_enqueue()` → `with_account()` (`:767`) → the current language's
   account only. The copy in `default` stays subscribed, so the merchant's
   campaigns from that account keep reaching a contact who withdrew consent
   in the store.
2. **Under legitimate interest an unsubscribed contact gets a subscribed copy
   in the new account.** The live sync and the contact import send a contact
   without a status (Smaily keeps an existing contact's own); Smaily creates an
   unknown address as subscribed (the comment at `Flusher.php:274` says so).
   The Flusher (`:284`) and the import (`BackfillJob.php:502`) send to the
   routed account without asking whether the contact exists there. The sync-
   back that would correct the WP side runs in consent mode only, and the
   import's daily refresh re-sends the whole legitimate-interest audience at
   least once a week (`DEFAULT_FRESHNESS_SECONDS`). So within a week of the
   update every registered customer of a non-fallback language — those who
   unsubscribed from `default` included — is a subscribed contact of their
   language's account.
3. **A profiling objection held only in the old account is not read.**
   `ProfilingConsent::refresh_in()` (`:169`) reads only the current account,
   with a new per-account cache key. A contact whose objection (or general
   unsubscribe) sits in `default` and was never read before the update reads
   as "not found" in the language account and is profiled. A contact read
   before the update stays opted out through the per-address durable registry,
   so this part is narrower than 1 and 2.

**Failure scenario:** a Mode A store (Estonian fallback, English account) on
"All customers (legitimate interest)". An English-speaking customer
unsubscribed from the Estonian account's newsletter last year. After 3.27.0
the weekly refresh sends them to the English account without a status; Smaily
creates them there as subscribed, and the next English campaign reaches them.
In consent mode, a customer who unticks the box in My Account stays subscribed
in the Estonian account that holds them.

**Recommended fix (one of, for the orchestrator to choose):**

- (a) Send a row that carries `is_unsubscribed = 1` to every account of
  `ContactAccount::distinct_accounts()`, not only the routed one — an
  unsubscribe in an account that lacks the contact creates an unsubscribed
  record, which is harmless. This closes 1.
- (b) For a status-less row that goes to a language account in Mode A, ask
  that account first (`has_contact()`, as the purchase marker does at
  `Flusher.php:278`); when the contact is unknown there, read it in the other
  distinct accounts and send `is_unsubscribed = 1` when any of them has it
  unsubscribed (the import the same). This closes 2 at one extra GET per new
  contact.
- (c) In Mode A, read the profiling state in the other distinct accounts when
  the routed account does not find the contact. This closes 3.
- Or Erkki accepts the gap in DECISIONS "CON-446" with its consequence stated,
  and the merchant docs say how to move unsubscribes between accounts.

Pin whichever is chosen with an integration case that seeds an unsubscribed
contact in `default` and a Mode A language account (the mock already serves
per-account reads, `AccountPerLanguageConsentReadTest`).

### Info notes

- **I1:** `delivered()` counts `status = 'sent'` rows created in the last 30
  days per queue, filtering `NOT LIKE` on the `last_response` LONGTEXT; the
  Smaily queue uses `idx_status_created`, the Campaign Intelligence queue
  `idx_created_at`. Every value is `prepare()`d, the route is admin-only, and
  the janitor prunes sent rows after 30 days (an import's after 7), so the
  scan is the sent table at most. A large store pays one scan per Dashboard
  open. See the code-quality report, I4.
- **I2:** the network index (`smly_plus_network_accounts`) holds each site's
  normalized subdomains and mode, readable by any code on the network. A
  Connection save stores a complete credential set without a connection test,
  so a site admin can save another account's subdomain with a made-up user and
  see in the contacts step which sites of the same network use it, with their
  address and mode. Sites of one network, no credentials, no contacts —
  accepted as the warning's design (CON-467, "warn, never block").
- **I3:** `sync_order_contact()` sends `is_unsubscribed = 0` for any ticked
  checkout from `woocommerce_checkout_order_processed` /
  `woocommerce_store_api_checkout_order_processed`, i.e. on order creation,
  before payment, for the billing address (guest) or the account address
  (registered). Anyone who submits a checkout with an address and the tick
  can resubscribe that address. True in consent and checkout-only mode since
  CON-439; CON-442 adds legitimate interest. A product decision (DECISIONS
  CON-442: "a tick means subscribed"); a paid-status gate or double opt-in
  would narrow it.
- **I4:** `ProfilingConsent::may_profile()` resolves the account
  (`ContactAccount::for_email()`: `get_user_by`, the resolver's meta and
  latest-order read, `Credentials::option_keys()`'s `LIKE` scan and one
  decrypt per language account) before the transient check, on the `/relay`
  (logged-in identity), the recommendations route and login. Local reads only
  and Mode A only; DECISIONS CON-472 accepts the local reads before the cache.
- **I5:** `persist_credentials()` keeps the stored encrypted password when
  the typed one is empty, even when the subdomain or user changes;
  `main_password_option()` (CON-446) keeps it from a language account's
  option when the main account names that account. The password then goes to
  the new `https://<subdomain>.sendsmaily.net`. Admin-entered, Smaily's own
  domain, and the subdomain is one DNS label (`Settings\Subdomain`).
- **I6:** DECISIONS CON-471: every account's events apply to the WP user of
  that address. An opt-in in any account sets `user_newsletter = 1` (the
  reconciler's write is suppressed, so it sends nothing itself); the next
  status-less consent-mode sync then reaches the contact's language account.
  That is the person's own opt-in, so it is accepted.

## Confirmed clean

- **`GET /dashboard` (CON-464):** registered with `permission_callback =>
  admin_check` (signed in, `wp_rest` nonce, `manage_options`), listed in the
  route list. It calls neither Smaily nor the engine; every query is
  `$wpdb->prepare()`d — the `IN ( … )` and `hook LIKE` lists build their own
  placeholder strings, the `LIKE` patterns go through `esc_like()`, table names
  come from `$wpdb->prefix` and class constants. The answer carries counts,
  booleans, a stored error-code class and a UTC time — no address, credential
  or remote text. `SHOW TABLES LIKE` guards a missing Action Scheduler table.
- **Admin pages:** each render callback checks `manage_options`; the
  finished-setup redirect runs on `load-<hook>` (after WordPress's own page
  access check) with `wp_safe_redirect( admin_url( … ) )`; `$_GET['page']`
  now goes through `sanitize_key( wp_unslash() )` and only selects a view
  from a fixed map.
- **Boot payload:** `perLanguageAccounts` carries each language account's
  subdomain and user with `password: ''` and `connected` from
  `is_complete()` — the same exposure as the main account, admin pages only;
  `networkSites` carries name, home URL and mode only, and the React screen
  prints them as text (no `href`, no `dangerouslySetInnerHTML`).
- **Multisite (CON-467):** nothing is hooked, read or written off multisite;
  each site writes only its own entry (`get_current_blog_id()`), no
  credentials enter the index, deleted / archived / spam sites are skipped,
  at most 10 sites are looked up; `forget()` on deactivation.
- **Per-account routing (CON-446):** the Smaily request never carries
  `account_key` (`dispatch_contact_sync()` builds the row from the address,
  fields, language and status only); a row whose language account was
  removed fails terminally as `account_not_configured: <key>` and is never
  sent to another account; `for_language()` returns a language account only
  with a complete credential set, so the import's per-contact factory call
  cannot meet an incomplete account outside a race; `Credentials::
  resolve_option_key()` applies `sanitize_key()`, so `account_en_GB` and the
  stored `account_en_gb` read the same option.
- **Consent reads follow the routed account (CON-446 / CON-471 / CON-472):**
  the first-order and abandoned-cart `AutomationConsent` reads and the
  profiling read use the account the row would go to; a failed read still
  throws / falls back as before, so nothing is sent blind. The sync-back runs
  each account in its own `try` / `catch ( \Throwable )`, logs the account key
  and `DebugLog::where_with_code()` only, and keeps a failing account's cursor.
  The profiling caches keep finite TTLs (daily, a year) and the `default`
  keys of before, so no cached answer is lost.
- **CON-442:** the tick comes from the order (`user_newsletter` in the
  classic POST, `_smaily_newsletter_optin` order meta from the Store API
  extension), never from a later request; an unticked checkout sends no
  status; the LI row is a "subscribing" row for CON-395's stale rule, so a
  retry cannot undo a newer delivered unsubscribe.
- **CON-457:** `trp_language` is written by TranslatePress from its server
  language; every order language is normalized and clamped to the active
  languages; the Polylang for WooCommerce call runs only when the class and
  `load` exist, inside `catch ( \Throwable )` that logs `DebugLog::where()`.
- **CON-475:** `ProfilingConsent::client()` catches the factory's throw, so
  an incomplete account answers as "no client" on storefront requests.
- **Error texts (CON-301):** every new log line and stored reason is
  plugin-built (account key, error class, code, `file:line`); no remote
  message reaches a log, a row or a REST answer.
- **Uninstall / deactivation:** the per-account cursor option is swept by the
  `smly_plus_%` `LIKE` delete; no new Action Scheduler group. (The network
  option is left behind — see the code-quality report, L2.)
- **Suppressions:** one `phpcs:ignore NonceVerification.Recommended` on the
  sanitized `page` read (a view selector, no state change); one file-level
  `phpcs:disable` of `DirectQuery` / `NoCaching` / `UnescapedDBParameter` in
  `DashboardEndpoint.php` and four scoped `InterpolatedNotPrepared` /
  placeholder-count pairs around queries whose only interpolation is a table
  name or a placeholder list. No `@phpstan-ignore`, `@ts-ignore`,
  `@ts-expect-error` or `eslint-disable` was added.

## Release gate — ZIP built, Plugin Check not run

- **Local ZIP (not a release artifact):** built from `54e057f` per CLAUDE.md
  steps 2–6 in this worktree (`npm ci`, `npm run build:admin`, `composer run
  install-block-modules && composer run build`, `WP_CLI_BIN=vendor/bin/wp bash
  bin/build-i18n.sh` on the host — the regenerated `.pot` / `-et.po` held 861
  messages against the committed 863 (the two the source no longer uses, see
  the code-quality report I6), every one translated, and were reverted; only
  the `.mo` / `.json` build artifacts kept — `composer install --no-dev
  --optimize-autoloader`, `composer run package`, dev vendor restored).
  **ZIP 1 120 150 B, SHA256
  `80d025252a98d940cdf39441ef4b3f364c2c41013f2e19e78fcfc0e8d4aabe9b`,
  build-hash `54e057f`, 441 entries, `bash bin/verify-release-zip.sh
  smaily-connect.zip` (from the worktree root) VERIFY OK, 42 `ok` lines**
  (version 3.26.0: no bump yet). `dist/admin/admin.js` in the ZIP 231 888 B
  (gzip −9: 61 665 B, under the 75 KB budget; +17 685 B raw over 3.26.0, the
  Dashboard and Log pages). The ZIP was deleted afterwards.
- **Plugin Check 2.2.0: NOT RUN** — it needs the wp-env container; the 3.27.0
  release gate runs it on the bump branch. **Expectation from the code:**
  0 ERRORS / 17 WARNINGS, the accepted set with three lines moved by the
  delta — `DirectQuery` + `NoCaching` at `BackfillJob.php:778` (was 755),
  `IngestQueue.php:523` and `:552`, `QueueJanitor.php:298`,
  `EventsEndpoint.php:848` (was 866); `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:165`, `:236`, `HookHandler.php:314` (was
  312), `MarketingConsent.php:42`; `UnescapedDBParameter` at
  `CartSessionStore.php:310`, `:334`, `GdprHandler.php:732`. The new
  queries sit under `DashboardEndpoint.php`'s file-level disable of those
  codes or an existing `phpcs:ignore` (`Credentials::option_keys()`); the
  delta adds no `do_action` / `apply_filters` with a dynamic name (the new
  `add_action( 'load-' . $hook_suffix … )` is not one). Only the run settles
  it.

## Conclusion

The new admin route, the menu redirects, the boot payload additions, the
multisite index, the per-account consent reads and the order-language
sources are sound as merged: no route widens access, no credential or other
site's secret reaches the browser, every new query is prepared, and no remote
text reaches a log. M1 is a consent gap of the account-per-language mode that
the routing decision left unstated: an opt-out does not follow a contact
into the account that holds it, and legitimate interest creates subscribed
copies of unsubscribed contacts. 3.27.0 may proceed once M1 is fixed or
accepted in DECISIONS, and its release gate — Plugin Check on the built ZIP
included — is green.

## Disposition (to be filled)

| # | Disposition |
|---|---|
| M1 (Medium) | _open_ |
| I1–I6 (Info) | _open_ |
