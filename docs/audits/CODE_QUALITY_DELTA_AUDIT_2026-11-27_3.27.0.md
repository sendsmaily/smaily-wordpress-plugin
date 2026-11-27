# Code-quality delta audit — before 3.27.0 (3.26.0..54e057f)

The code-quality half of the pre-3.27.0 pass. The security half, the delta
description (commits, sizes) and the release-gate record (local ZIP, Plugin
Check expectation) are in
[`SECURITY_DELTA_AUDIT_2026-11-27_3.27.0.md`](SECURITY_DELTA_AUDIT_2026-11-27_3.27.0.md).

- **Date:** 2026-11-27
- **Baseline:** tag `3.26.0` (`4595667`). Delta `3.26.0..54e057f` on `main`
  (#394–#404): 11 commits, 79 files, +6 821 / −380; shipped code 40 files,
  +2 232 / −264. #405 and #406 are open and not in the delta.
- **Auditor:** Claude (Opus 5.5).
- **Method:** read of the shipped diff for correctness, robustness,
  duplication, naming against the codebase's rules (CLAUDE.md "Architecture
  pattern", the one-accessor rules, CON-301), test coverage of each change,
  and the docs the change should have moved. A focused pass on every caller
  of `Bootstrap::smaily_client()` through a factory (the question CON-475
  raised), now that #400 / #401 route contacts and reads per account. The
  local build of the release ZIP (steps 2–6) is recorded in the security
  report.

## Verdict

**0 High, 0 Medium, 2 Low, 8 Info.** The delta is well tested (unit and
integration cases for every PR, including Mode A routing, per-account consent
reads, the network index, the Dashboard route and the menu redirects) and
follows the codebase's patterns. L1 is a pre-existing robustness gap the
CON-475 investigation pointed at; #400 does not widen it (it closes it for
language accounts) but leaves the `default` and automation paths as they
were. L2 is a missing uninstall cleanup of the new network option. Neither
blocks the release on its own; both are small.

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| L1 | Low | `includes/Smaily/Flusher.php:186`–`:190`, `:304`, `:351`; `includes/Smaily/AutomationRouter.php:141` | A `RuntimeException` from the Smaily client factory escapes `Flusher::flush()`: the row stays pending, comes first again, and stops every later run of the whole Smaily queue | Recommended (small) |
| L2 | Low | `uninstall.php:54`; `includes/Smaily/NetworkAccounts.php:190` | Uninstall leaves the network option `smly_plus_network_accounts` (every site's subdomains and mode) in `sitemeta` | Recommended (one line) |
| I1 | Info | `admin/wizard.php:306`; `includes/Smaily/NetworkAccounts.php:99` | The index refresh (a possible network-option write) runs on every plugin admin page, not only setup / Settings as documented | No |
| I2 | Info | `includes/Smaily/NetworkAccounts.php:125` | A site name is passed as stored (HTML-escaped), so `&` shows as `&amp;` in the warning | No — cosmetic |
| I3 | Info | `includes/Integrations/WooCommerce/HookHandler.php:767`; `includes/Smaily/ContactAccount.php:134` | In Mode A every contact-sync enqueue builds a new `ContactAccount`: an option-name scan plus one decrypt per language account each time | No |
| I4 | Info | `includes/REST/DashboardEndpoint.php:183` | `delivered()` scans the last 30 days of each queue with a `NOT LIKE` on a LONGTEXT column | No |
| I5 | Info | `includes/Integrations/WooCommerce/HookHandler.php:653` | CON-442's "registered buyer's tick under legitimate interest" is an audience rule outside `ContactAudience` | No |
| I6 | Info | `languages/smaily-connect.pot`, `languages/smaily-connect-et.po` | The committed catalogs carry two messages no source uses any more ("Run setup again", "Event Log") | No — #405 |
| I7 | Info | `includes/Notifications/NotificationManager.php:332` | The health check calls the Smaily factory outside its `try`; an incomplete `default` account fails the AS run (pre-existing) | No |
| I8 | Info | `STATUS.md` | The CON-475 bullet and the handoff said PR #404 was open; it is merged as `54e057f` | Fixed in this PR |

### L1 — a factory throw stalls the Smaily queue

`Bootstrap::smaily_client( $account_key )` throws `RuntimeException` when
the account's stored credential set is null or incomplete (`Bootstrap.php:1233`)
— on a finished setup that is the case CON-475 met (incomplete stored
credentials, e.g. a password that no longer decrypts). `Flusher::flush()`
catches only `TerminalDispatchException` and `ApiException`
(`Flusher.php:186`–`:190`). Every caller through a factory, after #400 / #401:

| Caller | Account | Catch around the factory call | Effect of a throw |
|---|---|---|---|
| `Flusher::row_client()` (contact sync) `:301` | a language account | `RuntimeException` → `TerminalDispatchException( 'account_not_configured: <key>' )` (new in #400) | row failed, queue goes on |
| `Flusher::row_client()` `:304` | `default` | none | **escapes `flush()`** |
| `Flusher::consent_refusal()` (first-order) `:351` | `for_language()`: a complete language account, else `default` | none | **escapes** when `default` is incomplete (as before #400) |
| `AutomationRouter::trigger_automation()` `:141` (welcome, first-order via the Flusher) | the mapping row's account | none in the router or the Flusher | **escapes `flush()`** |
| `CartFlusher` (consent read `:275`, legacy fallback `:250`, router) | routed / `default` / mapping | `catch ( \Throwable )` → row failed with `DebugLog::where()` | row failed |
| `TransactionalFlusher` `:635` | transactional | `catch ( \Throwable )` (`:488`) | row failed |
| `ContactReconciler` construction (`Bootstrap.php:667`) | each distinct account | per-account `catch ( \Throwable )` (new in #401) | that account skipped, logged |
| `BackfillJob::client_for()` (`BackfillJob.php:502`) | `for_language()` | outside the per-contact `try` | not reachable: `for_language()` returns only complete accounts, `default` is built in `Bootstrap::make_backfill_job()`'s `try` |
| `ProfilingConsent::client()` | `for_email()` | `catch ( \Throwable )` (CON-475) | fallback answer |
| `NotificationManager::probe_smaily()` | `default` | none (I7) | the health-check run fails |

An exception that escapes `flush()` fails the Action Scheduler action; the
row keeps `pending` with its attempts unchanged, and `EventQueue::pending()`
orders by `created_at, id`, so the next run meets the same row first and
stops again. Every contact sync, welcome and first-order email behind it
waits until the credentials are fixed, and the Event Log shows nothing
failed. #400 makes the language-account contact-sync path terminal (good) and
does not widen the others: the consent read targets `default` only where it
did before. So this is pre-existing, not a regression.

**Recommended fix:** do as `CartFlusher` and `TransactionalFlusher` do — a
final `catch ( \Throwable $e )` in `Flusher::flush()` that marks the row
failed with `DebugLog::where( $e )` (F3-53's rule: deterministic errors are
terminal, never an eternal retry). Or narrower: wrap the two remaining
factory calls (`row_client()`'s `default` branch and `consent_refusal()`)
and the router call in the same `RuntimeException` →
`TerminalDispatchException( 'account_not_configured: <key>' )` mapping
`row_client()` already uses. A unit case with a throwing factory and two
rows pins that the second row is still sent.

### L2 — uninstall leaves the network option

`NetworkAccounts::store()` writes `smly_plus_network_accounts` with
`update_site_option()` (`wp_sitemeta` on multisite). `Deactivation::run()`
removes the current site's entry, but `uninstall.php` sweeps only the current
blog's `smly_plus_%` rows in `wp_options`, so after a network uninstall the
option stays with the entries of every other site (normalized subdomains and
modes; no credentials, no contacts). A reinstall later reads those stale
entries until each site refreshes its own. **Recommended fix:**
`if ( is_multisite() ) { delete_site_option( 'smly_plus_network_accounts' ); }`
in `uninstall.php` (the uninstall runs once per network for a network-wide
plugin), plus the line in the file's header list.

### Info notes

- **I1:** `smaily_connect_enqueue_admin_bundle()` puts
  `NetworkAccounts::sites_sharing_account()` into the boot payload of every
  Smaily Connect admin page (Dashboard and Log included), and that call
  refreshes and, when it changed, writes the site's own index entry. The
  class docblock and DECISIONS CON-467 say "when its setup or Settings screen
  opens". Harmless (writes only on change); compute it for the wizard and
  settings views only, or correct the two texts.
- **I2:** WordPress stores `blogname` HTML-escaped (`sanitize_option()`), and
  `get_blog_details()->blogname` returns it as stored. React prints text, so a
  site called "Tom & Jerry" reads "Tom &amp; Jerry" in the warning.
  `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )` (or
  `wp_specialchars_decode()`) before it enters the payload fixes the display;
  React still escapes it.
- **I3:** `HookHandler::with_account()` creates a `ContactAccount` per
  enqueue, and in Mode A `for_language()` runs `Credentials::option_keys()`
  (an indexed `LIKE` on `option_name`) and decrypts every language account's
  password. A bulk user import of N users in Mode A pays N scans and N × M
  decrypts in one request. Off Mode A it reads one option. A per-request memo
  (reset on `updated_option` of a credentials key) would make it one.
- **I4:** see the security report, I1. The Smaily queue's
  `idx_status_created` serves `status = 'sent' AND created_at >= …`; the
  Campaign Intelligence queue has no `(status, created_at)` index and uses
  `idx_created_at`. The janitor's retention bounds the scan. If a large store
  finds the Dashboard slow, the cheaper count is `status` + `created_at`
  without the outcome filter, with the skipped rows counted apart.
- **I5:** CLAUDE.md and `ContactAudience` say the audience lives in one
  class, which "MUST agree" with the counts; `sync_order_contact()` now adds
  `$li_account_tick` beside `should_sync_order_email()`. DECISIONS CON-442
  chose this ("`ContactAudience` is unchanged"), and the count of the import
  is not affected (it counts accounts, not checkouts). Worth a pointer in
  `ContactAudience`'s docblock so the next reader finds the second rule.
- **I6:** a host run of `bin/build-i18n.sh` from `54e057f` extracts 861
  messages; the committed `.pot` / `-et.po` hold 863 — the extra two are
  "Run setup again" (the menu item CON-464 removed) and "Event Log". Both are
  translated, so nothing shows untranslated; #405 (open) regenerates the
  catalogs in the container and should drop them.
- **I7:** `probe_smaily()` calls the factory before its `try`; with the
  setup finished and `default` incomplete the factory throws and the 15-minute
  health check fails as an AS action instead of reporting the account as
  refused. Pre-existing, outside the delta; the same one-line catch as L1.
- **I8:** STATUS.md's CON-475 bullet and "Next session opens with" said
  "PR #404 open, not merged"; main has it as `54e057f`. Corrected in this PR,
  per the keep-current rule.

## Confirmed clean

- **Tests:** each PR brings its cases — `AccountPerLanguageContactSyncTest`
  and `AccountPerLanguageConsentReadTest` (Mode A routing, the terminal
  `account_not_configured`, per-account consent reads),
  `CheckoutTickResubscribeTest` (CON-442, both checkouts, guest and
  registered), `ContactLanguageOrderSourceTest` +
  `ContactLanguageResolverPolylangWcTest` (CON-457, the Polylang for
  WooCommerce double of the vendor's API), `DashboardEndpointTest` (unit +
  integration), `AdminMenuTest` (menu order and redirects),
  `NetworkAccountsTest` (index writes, bounds, skipped sites),
  `ProfilingConsentTest` (per-account cache keys, CON-475's throwing factory),
  `ContactReconcilerTest` (per-account cursor), `FlusherTest` (row account),
  and the React tests for the Dashboard, its verdict order, the Log page, the
  Mode A reload and the network warning. New options are in `EnvScrub`'s
  flush list.
- **One accessor each:** the account rule is `ContactAccount` (live sync,
  import, consent reads, sync-back, network index); the failed count is
  `NotificationManager::failed_last_24h()` (its `EventsEndpoint` copy is
  removed); the main account in the admin is `mainAccount()`
  (`admin/src/state/main-account.ts`, was private to `Wizard.tsx`).
- **Compatibility:** a queue row without `account_key` goes to `default`,
  so rows queued before the update are unchanged; the `default` profiling
  cache keys and the main reconcile cursor keep their names, so the update
  loses no cached answer and re-reads nothing; the old page slugs still
  resolve.
- **Docs moved with the code:** DECISIONS (CON-442, CON-446, CON-457,
  CON-464, CON-467, CON-471, CON-472), CLAUDE.md, ARCHITECTURE,
  CONTACT_SYNC_MODES, the docs site (EN + ET pairs) and STATUS.
- **Build:** `npm ci`, `build:admin` (four IIFE passes, `check:bundle-scope`
  green), the block build and the package ran clean; `verify-release-zip.sh`
  VERIFY OK. `admin.js` grew 17 685 B raw (the Dashboard and Log pages) and
  stays under the gzip budget at 61 665 B.

## Disposition (to be filled)

| # | Disposition |
|---|---|
| L1 (Low) | _open_ |
| L2 (Low) | _open_ |
| I1–I8 (Info) | _open_ (I8 fixed in this PR) |
