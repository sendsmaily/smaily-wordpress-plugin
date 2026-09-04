# Smaily Connect — Code-Quality & wordpress.org-Readiness Audit

**Date:** 2026-06-25 · **Auditor:** Claude (Opus 4.8), read-only — no code modified.
**Repo state:** `main` @ v2.1.0-beta.10 (commit `2597888`).
**Scope:** code-quality + architecture of the delta since `906cf3d` (2026-06-11),
and a full **WordPress.org Plugin Check (PCP 2.0.0)** + plugin-review-guideline
pass against the whole shipped surface. Gate purpose: **3.0 GA + upstream merge**.
**Companion:** `SECURITY_AUDIT.md` (security findings live there, not duplicated).

> **Headline: GA / upstream-ready from a code-quality standpoint.** No High
> correctness or data-loss defects in the changed code. The work before a clean
> public submission is a tidy punch-list (below), not a rewrite.

---

## RESOLUTION — full PCP-clean punch-list applied (2026-06-25)

The §C punch-list was applied the same day (Erkki's call: full PCP-clean before the
3.0 cut). **`wp plugin check` against the built ZIP is now clean except two
intentional findings:** `plugin_updater_detected` (the `Update URI` header — the
F3-35 fork clobber-guard, removed at the upstream merge) and `mismatched_plugin_name`
(the `(BETA)` suffix in the plugin Name header — dropped at the 3.0 GA bump). Gates:
**ci:strict exit=0 (PHPUnit 374, JS 158)**. What changed:

- **ABSPATH guard** added to the ~29 shipped legacy PHP files (new PSR-4 code already had it).
- **`error_log`** routed through a new `Support\DebugLog` (WP_DEBUG-gated, single phpcs:ignore chokepoint); ~23 call sites converted.
- **DB sniffs** (`DirectQuery`/`NoCaching`/`UnescapedDBParameter`/`UnquotedComplexPlaceholder`/`SchemaChange`) suppressed via file-level `phpcs:disable` on the custom-table data-access classes + targeted line ignores; the existing per-query `WordPress.DB.PreparedSQL.*` disables (needed by local PHPCS) were preserved, and a few bare `phpcs:enable` lines that were re-enabling my file-level disable were trimmed.
- **`ExceptionNotEscaped`** suppressed on the exception-throwing API-client classes (messages go to the Event Log, never echoed).
- **Nonce / hookname / prefix / textdomain** false-positives suppressed with justified ignores (legacy display reads, third-party WPML/WC hooks, uninstall/template globals).
- **readme** Upgrade Notice trimmed to a single ≤300-char entry; **blocks `apiVersion` 2→3** (WP 7.0 iframe-editor requirement — ⚠️ needs a block-editor smoke-test before release); **`.zipignore`** extended to drop stale unused `dist/partials` + `dist/template` (the code only ever loads `public/…`), `BACKLOG.md`, `blocks/.eslintrc.cjs`, and to **ship `composer.json`** (PCP wants it whenever `vendor/` ships).

> **Operational lesson — run PCP against the built ZIP, with `--slug`.** The dev-tree
> run (`wp plugin check smaily-connect`) was *misleading*: it hid `dist/` duplicate
> templates, the `blocks/` tree, and which files actually ship — so it under-reported.
> And unzipping the package to any dir whose name ≠ the text domain makes PCP expect
> that dir name as the text domain → **hundreds of false `TextDomainMismatch` errors**.
> The authoritative gate is: build the ZIP, unzip it, and
> `wp plugin check <dir> --slug=smaily-connect --exclude-directories=vendor`. Recorded
> in CLAUDE.md. **For the actual release ZIP, also `composer install --no-dev` first**
> (this validation ZIP shipped the dev vendor; vendor was excluded from the check, but
> the real release must not ship phpunit et al.).

---

## A. Code-quality & architecture (changed code: 906cf3d..HEAD)

**Strengths (what's done well):**
- **`SkuResolver` single-chokepoint** — catalog/order/browse all key through one
  resolver with multilingual canonicalization; the never-empty `wc-oi-{item_id}`
  deleted-line fallback (F3-43) is correct and tested. The "never silently drop a
  record" rule is genuinely honored: every skip is a terminal `mark_sent` +
  `store_exchange(outcome:skipped)`, visible in the Event Log.
- **Status denylist (F3-42)** — single source (`non_sale_wc_statuses()`) reused by
  the backfill SQL so the two can't drift; full `map_status` dataProvider coverage.
- **Datetime discipline** — every datetime goes through `IsoDate::to_z`; no raw
  `gmdate('c')`/`format('c')` in changed code.
- **Event Log exchange storage (F3-44)** — careful: auth header never captured,
  `try/finally` capture on throw, ~10 KB trim + janitor prune, migration 007
  preserves dbDelta invariants.
- **Cypher GCM** — solid; idempotent upgrade re-encryption, undecryptable blobs
  preserved.
- **No error swallowing** — every `catch` records to Event Log / `last_error` or
  returns an observable terminal state; GDPR 404 = idempotent success.

**Findings:**

| # | Severity | Location | Issue | Recommendation |
|---|---|---|---|---|
| Q-1 | Low | `REST/BeaconEndpoint.php:29,34,41` | Class docblock still says the browser POSTs to `/beacon`; route was renamed to `/relay` (F3-41). Constant + line 68 correct; prose lagged. | Update the three mentions to `/relay` (same-commit doc rule). |
| Q-2 | Low | `Smaily/RecEngine/CatalogPayloadBuilder.php:204-215` | On a single-language site `SiteLocaleAdapter` can return a scalar `product_url=''`; `localized()` forwards the REQUIRED field empty → surfaces as a D6 Event Log error (observable, not silent; removal path separately guarded by `is_removable()`). | No action — intentional ("engine error is the signal"); noted as the one required-field path that can serialize empty. |
| Q-3 | Low | `Integrations/WooCommerce/StorefrontBeacon.php:159` | `page_context()` builds a fresh `CatalogPayloadBuilder` per product-page view for one public method. | Acceptable; if touched, extract a static category-path helper. |
| Q-4 | Low (note) | `Smaily/Flusher.php:254-263` vs `AbstractD6Flusher.php:295-308` | The two intentionally-separate queue stacks each re-implement `EXCHANGE_MAX`/`trim_*`/skip-marker. | Acceptable — decoupling is intentional (different tables/idempotency). Not worth a shared trait unless a third consumer appears. |

**Test-coverage gaps on the changed surface** (all low-risk; the load-bearing
pilot fixes — SkuResolver, payload builders, flushers, hook handlers, backfill,
Cypher, GDPR/consent — are explicitly pinned):

| Severity | Area | Gap |
|---|---|---|
| Medium | `Multilingual/TranslatePressAdapter.php` | The only detector with **zero tests**. Add `TranslatePressAdapterTest` mirroring `PolylangAdapterTest`. (Low pilot risk — single-language.) |
| Medium | `Notifications/NotificationManager` `probe_smaily`/`probe_engine` | Pure `evaluate_signals()` is tested; the HTTP probes feeding it (down-since set/clear, factory-null) are not. |
| Low | `Activation::cleanup_removed_rec_feature_options` | Dead-option sweep untested (idempotent `delete_option`). |
| Low | `Smaily/EventQueue` `store_exchange`/`reset_failed` | No direct unit test (the Flusher fake overrides; `reset_failed` only integration-tested on sibling `IngestQueue`). |
| Low | `Smaily/Client` `get_contact_consent`/`write_profiling_consent` | No direct request-shape test (only via mocked Privacy tests). |
| Low | `AbstractD6Flusher.php:213,295-308` | `>10 KB` trim + `assert_invariant` count-mismatch branches unasserted. |

---

## B. WordPress.org Plugin Check (PCP 2.0.0) + plugin-review readiness

PCP was run in wp-env (`wp plugin check smaily-connect`). **Caveat:** it ran
against the dev working tree, so a large share of the raw output is dev-only noise
that the **built distributable excludes via `.zipignore`** — ~25 stray release
ZIPs in the repo root (`compressed_files`), `.github`, hidden dev files
(`.eslintrc.cjs`, `.env.example`, …), config files (`phpunit*.xml.dist`,
`phpcs.xml.dist`, `phpstan.neon.dist` → `application_detected`), `*.md` docs,
`bin/*.sh`. **The real gate is PCP against the packaged ZIP** — make that a step
of the 3.0 release cut. The table below is filtered to the **shipped** surface.

**Verified clean (PCP + review pass):** output escaping; input
sanitization/unslashing; forbidden functions (no `eval`/`unserialize`/`extract`/
remote `file_get_contents`/raw `curl_*`/`mysql_*`); **prefixing** (all globals,
constants `SMAILY_CONNECT_*`, options `smaily_connect_*`/`smly_plus_*`/`smly_rec_*`,
hooks, meta keys); **external-services disclosure** present and matches what the
code sends (`readme.txt` `== External services ==`); no `var_dump`/`print_r`/
`console.log`/TODO/FIXME in shipped source.

| # | Category | Severity | Location | Issue | Recommendation |
|---|---|---|---|---|---|
| W-1 | ABSPATH guard | **wp.org blocker** (PCP hard-flags 4; project-rule covers ~29) | Legacy layer: `includes/smaily-*.class.php` (9), `integrations/**` (10), `blocks/**` (3), `public/smaily-public.class.php`, `admin/smaily-admin-notices.class.php`, `admin/partials/notices/1_3_0_upgrade_cf7_notice.php`, `migrations/upgrade-1-3-0.php` | No `defined('ABSPATH') \|\| exit;` (declares `namespace` directly). PCP hard-flags the 4 output/side-effect files (`smaily-lifecycle.class.php`, `cf7/service.class.php`, the cf7 notice partial, `migrations/upgrade-1-3-0.php`); the project's own rule mandates it everywhere. | Add `defined( 'ABSPATH' ) \|\| exit;` after the namespace line in every shipped legacy PHP file (~29). One line each. (`uninstall.php` already correct via `WP_UNINSTALL_PLUGIN`.) |
| W-2 | Plugin name | **GA cut** | `smaily-connect.php` plugin header `Name` | Header is **"Smaily Connect (BETA)"**; readme.txt says "Smaily Connect" → PCP `mismatched_plugin_name`. | Drop "(BETA)" from the `Name` header as part of the 3.0 GA bump. |
| W-3 | Update URI | **Upstream-merge blocker** | `smaily-connect.php` `Update URI:` header | PCP `plugin_updater_detected` — *"Use of the Update URI header is not allowed in plugins hosted on WordPress.org."* This is the F3-35 fork-only clobber-guard. | **Keep while forked; REMOVE when merging into the wordpress.org-hosted upstream.** (See `UPSTREAM_COMPARISON.md` / DECISIONS F3-35.) |
| W-4 | Output escaping | Should-fix | `Smaily/RecEngine/Client.php:433,463`, `Smaily/Client.php:256` (~9) | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` — `throw new ApiException(...)` with an interpolated message. Not echoed to a browser (caught + stored in Event Log) → low real risk, but PCP flags it ERROR. | Wrap the dynamic part in `esc_html()` inside the `throw`, or a scoped `phpcs:ignore` with justification. |
| W-5 | Enqueueing | Should-fix | `admin/partials/smaily-admin-notice.php:19-38` | Inline `<script>` (admin-notice AJAX-dismiss jQuery) emitted from PHP — output IS escaped (`esc_js`/`esc_attr`/`wp_create_nonce`), so not XSS. PCP discourages hardcoded inline `<script>`. | Move to an enqueued handle (`wp_enqueue_script` + `wp_localize_script`) or `wp_add_inline_script`. The AJAX handler + nonce already exist. |
| W-6 | Direct DB | Should-fix (noise) | queues / backfill / events (~90: `DirectDatabaseQuery.DirectQuery`/`NoCaching`, `UnescapedDBParameter`) | Custom-table queries flagged for direct-query + no-caching. **All are prepared** (Security audit verified); `UnescapedDBParameter` are PCP false-positives on dynamic-placeholder `IN()` lists. | Add scoped `phpcs:ignore` comments with caching/justification notes so a clean PCP run is achievable. No functional change. |
| W-7 | i18n (JS) | Should-fix (GA polish) | `admin/src/**/*.tsx` (41 components) | The React admin UI is **entirely hardcoded English** — 0/41 TSX use `@wordpress/i18n`; PHP i18n is fully clean. Not a hard wp.org blocker, but expected for a translatable GA. | Wire `@wordpress/i18n` `__()` (text domain `smaily-connect`) + `wp_set_script_translations`, or accept as a documented GA limitation. |
| W-8 | Nonce verification | Should-fix (review) | `public/partials/smaily-public-basic.php:5`, `integrations/woocommerce/profile-settings.class.php:191`, `includes/smaily-helper.class.php:363`, `integrations/cf7/partials/*` (~7) | `WordPress.Security.NonceVerification.Recommended` on legacy form handlers. Several are false-positives (REST nonce upstream); the legacy form paths deserve a look. | Verify each legacy handler nonce-checks; add `phpcs:ignore` where a higher layer already verifies. |
| W-9 | Prefixing (globals) | Nice-to-have | `uninstall.php` (~13), `public/template/smaily-rss-feed.php`, cf7 partials | `NonPrefixedVariableFound` — global-scope locals in uninstall/templates. Cosmetic. | Optional: wrap in a function or prefix. |
| W-10 | readme.txt | Nice-to-have | `readme.txt` | `upgrade_notice_limit` (section too long); "What's new in **2.0**" label while version is 2.1.x; `readme.md` + `readme.txt` → `case_sensitive_files` (the `.md` is dev-only, excluded from dist). | Trim the upgrade-notice; relabel to current version during the GA readme pass. |

---

## C. Punch-list for 3.0 GA + upstream merge (ordered)

**Before 3.0 GA (clean public submission):**
1. **W-1** — add the ABSPATH guard to the ~29 shipped legacy PHP files. *(bulk, trivial)*
2. **W-2** — drop "(BETA)" from the plugin `Name` header during the version bump.
3. **S-4 / `error_log`** — gate the ~21 unconditional `error_log()` diagnostics behind `WP_DEBUG`.
4. **W-4** — `esc_html()`/`phpcs:ignore` the ~9 `ExceptionNotEscaped` throws.
5. **W-6 / W-8** — add scoped `phpcs:ignore` justifications so PCP-against-the-ZIP is clean.
6. **W-10** — readme GA pass (version labels, upgrade-notice length).
7. **Re-run PCP against the packaged ZIP** (not the dev tree) as the release gate.
8. Housekeeping: remove the ~25 stray `smaily-connect-*.zip` from the repo root (gitignored cruft).

**At upstream merge (separate effort):**
- **W-3** — remove the `Update URI` header (not allowed on wordpress.org). Still pending — it is the fork's clobber-guard, removed only at the actual submission.
- ~~**W-5**~~ — **DONE (2026-06-25):** the admin-notice dismiss moved from an inline `<script>` to an enqueued `admin/js/notice-dismiss.js` (E2E-verified).
- ~~**W-7**~~ — **DONE (2026-06-25):** the React admin UI is fully internationalized (~244 strings / 24 files wrapped with a `wp.i18n` shim; `wp_set_script_translations` wired; reproducible `bin/build-i18n.sh`; Playwright-verified Estonian render). Full ET translation of the `.po` is the remaining content task.
- Reconcile with upstream #119 (it already added ABSPATH guards to its copies of
  the shared legacy files) and #120/#128/#132 — see `UPSTREAM_COMPARISON.md`.

**Backlog (post-GA, low-risk):** the test-coverage gaps in §A (TranslatePressAdapter,
probe_smaily/engine, EventQueue exchange/reset, Activation cleanup, Client consent).

---

## Verdict

Code-quality and wp.org readiness are good. **No blockers beyond a one-line-each
ABSPATH sweep and a handful of polish items.** Clear the §C "Before 3.0 GA" list,
re-run PCP against the built ZIP, and the plugin passes the public-submission bar.
Re-run this audit after the next class of bigger changes (`docs/audits/INDEX.md`
§ Re-audit policy).
