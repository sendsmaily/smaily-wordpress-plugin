# Security + code-quality delta audit — 3.13.0 pre-release gate (3.12.0..HEAD)

- **Date:** 2026-09-10
- **Baseline:** `a348b10` (the 3.12.0 release cut — the repo state of the last
  substantive audit row in `docs/audits/INDEX.md`; the 3.12.1 hotfix row that
  follows it recorded a deliberate *no re-audit* judgement for a
  build-configuration-only delta, so its commits are folded into this scope
  rather than treated as already audited).
- **HEAD:** `f5f865c` (pre-bump main tip; the version strings still say
  3.12.1).
- **Delta:** `a348b10..f5f865c` — **24 commits, 64 files, +4 006 / −243**;
  shipped plugin code alone (`includes/`, `blocks/`, `integrations/`,
  `admin/src/`, `smaily-connect.php`) is **16 files, +1 119 / −56**. The work
  in it:
  - **PRO-2438** — the daily janitor also prunes the plugin's OWN finished
    Action Scheduler rows (`QueueJanitor::prune_scheduler_history()`), with
    their `actionscheduler_logs` rows.
  - **PRO-2433** — deactivation cancels the plugin's Action Scheduler groups.
  - **PRO-2434** — `Support\UpgradeLock` around the inline upgrade
    (`Bootstrap::maybe_run_upgrade`) + a 300 s time limit for the run.
  - **PRO-2435** — the `ProfilingConsent` stale cache gets a finite TTL, and
    `Activation::purge_autoloaded_profiling_cache()` sweeps the autoloaded
    no-expiry rows an earlier release left behind.
  - **PRO-2436** — the checkout opt-in block's editor script moves to the
    footer group.
  - **PRO-2437** — the recurring-jobs existence check runs at most hourly
    behind the `smly_plus_as_jobs_verified` marker.
  - **PRO-2440** — the landing page's two page-builder surfaces: the
    `[smaily_landing_page]` shortcode and an Elementor widget, both rendering
    through the block's own renderer behind a new server-side URL parser
    (`Integration::landing_page_key()`), simplified in `79b656c`.
  - **PRO-1725 / PRO-2298** — the Campaign Intelligence introduction shown on
    the Settings tab until the engine is connected (copy only).
  - **PRO-2383** — the Smaily event queue joins the WP privacy exporter and
    eraser (`EventQueue::rows_for_privacy_request()` /
    `erase_for_privacy_request()` / `redact_json()`), landed inside this
    window (`188422d`, `e536124`) and **not covered by any earlier audit row**.
  - **PRO-2391** (3.12.1 hotfix) — `vite.config.ts` one single-entry IIFE pass
    per bundle + `bin/check-bundle-scope.sh`; build configuration only.
  - Non-code: `readme.txt`, `docs/**` (incl. the merchant docs site, both
    languages), `CLAUDE.md`, `languages/**`, two replacement listing banners,
    tests.
- **Auditor:** Claude (Opus 5)
- **Trigger (re-audit policy):** policy point 1 (release boundary — 3.13.0) and
  policy point 2 on several named high-risk surfaces regardless of size: new
  raw `$wpdb` DELETEs against **another component's tables** (Action
  Scheduler), a new **public-content input parser** (the shortcode's URL
  argument), an **option-table LIKE sweep**, and a change to **what a GDPR
  erasure removes** (PRO-2383).
- **Scope:** file-by-file read of the production delta (`git diff
  a348b10..HEAD -- includes/ blocks/ integrations/ admin/src/
  smaily-connect.php`) plus the call chains it reaches (`Activation`,
  `Deactivation`, `Bootstrap`, `GdprHandler`, `EventQueue`, `ProfilingConsent`,
  Action Scheduler's `as_unschedule_all_actions()`). Tests and docs were
  skimmed for accidental secrets, not audited as behaviour. PCP against the
  built ZIP and `bin/verify-release-zip.sh` are reported in §Gates.

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium. 2 Low, 7 Info. RESULT: 3.13.0 may
proceed.**

Nothing in the delta widens who can reach a surface, what is stored in the
clear, or what leaves the site. The new SQL is prepared and scoped to rows the
plugin owns (its own hook prefixes, its own transient prefix, its own queue
table); the new merchant-facing input parser cannot produce an embed of
anything but the store's own Smaily account; the GDPR change removes more
personal data than before and retains none of it in readable form.

---

## 1. LOW — two concurrent requests can both take over an *abandoned*
upgrade lock

`includes/Support/UpgradeLock.php:40-54`

`acquire()` is correctly atomic in the normal case: `add_option()` compiles to
`INSERT … ON DUPLICATE KEY UPDATE option_name = VALUES(option_name)`, which
reports 0 affected rows when the row already exists, so the second caller gets
`false`. The **stale-takeover** branch is not atomic:

```php
delete_option( self::OPTION );
return (bool) add_option( self::OPTION, (string) time(), '', false );
```

Two requests that both find a lock older than `TTL` interleave as
A-delete → A-add(true) → B-delete (removes *A's* fresh lock) → B-add(true),
and both then run `Activation::run()` concurrently — precisely the
concurrent-migration case the lock exists to prevent.

Reachable only after a holder died mid-run and 15 minutes have passed, and the
outcome is no worse than the pre-PRO-2434 behaviour (N concurrent runners), so
this is a robustness gap, not a regression. A takeover that is itself atomic —
e.g. an `UPDATE … WHERE option_value = <the stale value>` and checking the
affected-row count, or `wp_cache_add()`-style compare-and-set — would close it.
**No code changed by this audit.**

## 2. LOW — the privacy erasure's payload fallback can miss a non-ASCII
address

`includes/Smaily/EventQueue.php:453-470` (`privacy_request_where()`)

Rows without a `contact_key` (anything enqueued before migration 011, and every
transactional row, whose recipient lives under `to`) are matched with a literal
LIKE on the stored JSON:

```php
'%' . $wpdb->esc_like( '"email":"' . $email . '"' ) . '%',
'%' . $wpdb->esc_like( '"to":"' . $email . '"' ) . '%',
```

`wp_json_encode()` escapes non-ASCII to `\uXXXX`, so a payload holding
`jõe@näide.ee` is stored as `"to":"jõe@näide.ee"` and the raw-address
LIKE does not match it — the row is neither deleted nor redacted, and an
already-`sent` row keeps the address inside `sent_payload`. ASCII addresses
(the overwhelming majority, and every address the checkout normalises) match
correctly, and the case-insensitivity is handled by the column collation.

Impact is an Art 17 completeness edge on rare inputs, not a disclosure: nothing
new is exposed, and the row is already lawfully held. A second LIKE built from
`trim( wp_json_encode( $email ), '"' )` would cover it. **No code changed by
this audit.**

## 3. INFO — the Action Scheduler prune is correctly scoped and prepared

`includes/DB/QueueJanitor.php:135-193`

Read adversarially, because it is the first place this plugin deletes from a
table it does not own:

- **Table names** come from `$wpdb->prefix` + class constants
  (`scheduler_table()`, l. 198-201); no request value reaches them.
- **Hook scoping** is `hook LIKE %s` per prefix, each built with
  `$wpdb->esc_like( 'smly_plus_' )` / `esc_like( 'smly_rec_' )` — the escape
  matters, because an unescaped `_` is a LIKE wildcard. Every Action Scheduler
  hook the plugin schedules is under one of those two prefixes (verified
  against `Activation`, `Bootstrap` and the five flusher/queue classes); no
  other plugin's hook can match.
- **Status predicate** is a literal `IN ( 'complete', 'failed', 'canceled' )`
  — `pending` and `in-progress` are never selected, so nothing scheduled is
  destroyed, and a recurring series is unaffected (its next occurrence is a
  separate pending row).
- **Age predicate** is `scheduled_date_gmt < %s` against a GMT cutoff seven
  days back — the same column Action Scheduler's own cleaner and its
  `hook_status_scheduled_date_gmt` index use.
- **Batching** is `LIMIT %d` (1 000) × at most 20 statements per run, with a
  short-batch break; the remainder waits for the next daily tick. The id list
  is `intval`-mapped and re-bound as `%d` placeholders.
- **Missing tables** are a silent no-op (`table_exists()`, l. 219-224). The
  `SHOW TABLES LIKE %s` argument is not `esc_like`d, but the result is compared
  with `===` to the exact name, so a `_` wildcard cannot widen it.

The only ordering nit: logs are deleted before their actions, so a failed
action DELETE would leave the actions behind without their logs. Cosmetic —
the next tick re-selects them.

## 4. INFO — deactivation cancels by group, and the groups are all ours

`includes/Deactivation.php:47-70`

`as_unschedule_all_actions( '', array(), $group )` with an empty hook and a
non-empty group resolves inside Action Scheduler to
`cancel_actions_by_group( $group )` (`vendor/woocommerce/action-scheduler/
functions.php:316-324`) — it never falls through to the hook-less
`as_unschedule_action()` loop that could touch a foreign action. All five
constants are non-empty, plugin-specific strings (`smaily-connect`,
`smaily-rec-ingest`, `smaily-rec-customers`, `smaily-rec-orders`,
`smaily-rec-catalog-remove`), and `smaily-connect` is shared only by this
plugin's own EventQueue / QueueJanitor / NotificationManager. Cancelling is
lossless in the sense that matters: the pending *events* are rows in the
plugin's own tables and a re-activation re-arms the recurring actions.

## 5. INFO — the profiling-cache purge is scoped to the plugin's own transients

`includes/Activation.php:195-206`

The one-time sweep is a prepared `DELETE … WHERE option_name LIKE %s OR
option_name LIKE %s` with both patterns `esc_like`d from
`_transient_smly_profiling_stale_` and `_transient_timeout_smly_profiling_
stale_`. It cannot reach another plugin's rows or a WordPress core option, and
the durable opt-out registry (`smly_profiling_optouts`) is untouched. The
follow-up `wp_cache_delete( 'alloptions', 'options' )` is the right companion
for a raw options DELETE. With a persistent object cache the individual
`_transient_…` keys may survive the row deletion and keep serving a stale
value until they expire; harmless — the value is only a fallback for a failed
consent read, and the TTL change (`STALE_CACHE_TTL = YEAR_IN_SECONDS`) is what
actually stops the unbounded `alloptions` growth.

## 6. INFO — the shortcode/widget cannot embed anything but the store's own
account

`blocks/landingpage/smaily-integration.class.php:112-176`

The new server-side parser is a strict allowlist, and it is the only path from
merchant input to the rendered `<iframe>`:

- scheme must be `https` (case-folded), else refuse;
- host must equal `strtolower( $subdomain ) . '.sendsmaily.net'` **exactly** —
  so `evil.com`, `sub.sendsmaily.net.evil.com` and a userinfo trick
  (`https://sub.sendsmaily.net@evil.com/`, whose `host` is `evil.com`) are all
  refused;
- the subdomain itself must match `SUBDOMAIN_PATTERN` (a DNS label) before it
  is used, and it comes from the stored credentials, not from the page;
- the key must be a UUID read out of a `/landing-pages/<uuid>` path segment.

The final URL is rebuilt from those two validated parts and passed through
`esc_url()`; the title through `esc_attr__()`; the wrapper through
`get_block_wrapper_attributes()`. `size_css()` (l. 181-192) admits only
`absint( … ) . 'px'` or `^[0-9]+%$`, and otherwise falls back to an integer
constant — no merchant string reaches the `style` attribute. The Elementor
widget's `echo $embed` (`integrations/elementor/landingpage-widget.class.php:
169`) is therefore escaped by construction; its editor-only notice is
`esc_html__()`.

Consequence worth stating plainly: a Contributor-level author who can write a
shortcode gains no new capability — the worst they can do is embed one of the
store's own landing pages.

## 7. INFO — the Elementor widget only loads when Elementor does

`integrations/elementor/admin.class.php:40-51` keeps the `class_exists(
'Elementor\Widget_Base' )` guard and both `require_once`s inside the
`elementor/widgets/register` callback, so the new file is never parsed on a
store without Elementor. `get_style_depends()` names the block's already
registered handle rather than enqueuing anything globally.

## 8. INFO — the erasure's delete/redact split retains no readable personal data

`includes/Smaily/EventQueue.php:299-470`, `includes/Privacy/GdprHandler.php:
162-182, 301-320, 381-415`

The SQL is prepared throughout (table name from `table_name()`, statuses and
match values bound). `redact_json()` keeps structure and keys and replaces
**every** scalar with `[erased]` except a six-key allowlist (`http`,
`outcome`, `note`, `workflow_id`, `account_key`, `to_status`) — an allowlist,
so a payload field added later cannot leak by not being on a denylist; an
undecodable blob is replaced wholesale. `contact_key` is set to `null` (WP's
`$wpdb->update()` emits a real `NULL` for a null value), which is also what
makes a second run a no-op. `items_retained => false` is accurate: what stays
is `event_type`, status and timestamps.

Two non-defects worth recording: the export returns every matching row in one
page (`done: true`), so a contact with an unusually long queue history
produces a large single export — the same shape the other exporters in this
class already have; and the exporter deliberately projects only
`event_type` + `created_at`, never the payload.

## 9. INFO — the inline upgrade's new time limit runs on an
unauthenticated-reachable hook

`includes/Bootstrap.php:607-632`

`maybe_run_upgrade()` is on `admin_init`, which `wp-admin/admin-ajax.php`
fires for unauthenticated `nopriv` requests too — so an anonymous request can
be the one that starts the migration (pre-existing, and it only ever runs the
plugin's own idempotent routine while the stored version trails the code).
PRO-2434 makes that strictly better, not worse: previously every such request
started its own runner, now at most one does. The added
`set_time_limit( 300 )` is the same allowance core's `WP_Upgrader` takes, is
guarded by `function_exists()`, and applies only inside the single locked run.

---

## Confirmed clean (checked, nothing to report)

- **No auth surface moved.** The delta contains no `register_rest_route`, no
  `permission_callback`, no `current_user_can`, no nonce check, no capability
  constant — verified by grep over the whole production diff.
- **No crypto, no external HTTP.** No `openssl_*`, no `wp_remote_*` added.
- **No new stored secret or PII class.** The only new option is a timestamp
  (`smly_plus_as_jobs_verified`) and a lock value (`smly_plus_upgrade_lock`);
  the profiling stale cache still stores a sha256 of the address as the key
  and `'1'`/`'0'` as the value.
- **`update_option( …, false )` trap** (CLAUDE.md): neither new option is a
  default-ON boolean — one is an int timestamp, one is a string time — so the
  "writes nothing" trap does not apply. The matching `EnvScrub` cache-flush
  entry for `OPTION_AS_JOBS_VERIFIED` **is** present
  (`tests/Integration/Support/EnvScrub.php:129-133`); `smly_plus_upgrade_lock`
  is not listed, which is currently harmless (only unit tests touch it) but is
  the same class of trap if an integration test ever asserts on it.
- **Settings-tab copy is i18n only.** `Step4Recommendations.tsx` extracts the
  existing two paragraphs into an `IntroCopy()` component reused on the
  Settings tab while `!isConnected`; both strings are unchanged React text
  nodes, and the msgid set of `languages/smaily-connect.pot` is **identical**
  before and after a clean `bin/build-i18n.sh` run (see §Gates), so no string
  in the delta is missing from the catalogs.
- **Tests and docs carry no secrets** (skimmed); the two replacement listing
  banners are marketing artwork.

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/DB/QueueJanitor.php` | **in** | new raw DELETEs on another component's tables (§3) |
| `includes/Deactivation.php` | **in** | cancel scope (§4) |
| `includes/Activation.php` | **in** | options-table LIKE sweep + upgrade sequencing (§5) |
| `includes/Support/UpgradeLock.php` | **in** | lock atomicity (§1) |
| `includes/Bootstrap.php` | **in** | lock wiring, time limit, AS marker (§1, §9) |
| `blocks/landingpage/smaily-integration.class.php` | **in** | new public-content input parser + output (§6) |
| `integrations/elementor/landingpage-widget.class.php`, `admin.class.php` | **in** | guarded loading, settings → output (§6, §7) |
| `includes/Smaily/EventQueue.php` | **in** | privacy SQL + redaction (§2, §8) |
| `includes/Privacy/GdprHandler.php` | **in** | what an erasure removes / reports (§8) |
| `includes/Privacy/ProfilingConsent.php` | **in** | cache TTL (§5) |
| `includes/smaily-blocks.class.php` | **in** | shortcode registration + script group (§6) |
| `admin/src/components/steps/Step4Recommendations.tsx` | **in** (checked) | copy only, escaped React text |
| `smaily-connect.php`, `readme.txt`, `package*.json`, `tests/bootstrap.php`, `tests/phpstan-bootstrap.php` | out | 3.12.1 version strings |
| `vite.config.ts`, `bin/check-bundle-scope.sh`, `bin/verify-release-zip.sh` | out | build configuration, not shipped (`/bin*` is `.zipignore`d) |
| `docs/**`, `CLAUDE.md`, `languages/**`, `assets/banner-*` | out | no executable content |
| `tests/**` | out | not shipped; skimmed for secrets, clean |

## Gates run for this pass

- **`npm run ci:strict` exit=0** — PHPCS 0 errors, PHPStan `[OK] No errors`,
  PHPUnit unit **809 tests / 2 287 assertions**, vitest **316 across 42 files**,
  tsc/eslint clean.
- **Release build reproduced locally** — `npm run build:admin` (three
  single-entry IIFE passes + `check:bundle-scope`: 9/9 ok),
  `composer run install-block-modules && composer run build`,
  `bash bin/build-i18n.sh`, `composer install --no-dev --optimize-autoloader`,
  `rmdir vendor/bin`, `composer run package`, `composer install`.
- **`bash bin/verify-release-zip.sh smaily-connect.zip 3.12.1` exit=0** —
  383 entries; `dist/admin/admin.js` + `admin.css`, both storefront bundles,
  all three `blocks/*/build/*`, `vendor/autoload.php`,
  `languages/smaily-connect-et.mo` and the admin-bundle ET JSON present;
  tests/docs/`admin/src`/node_modules/TypeScript/source maps/`bin`/CI
  config/dev vendor absent; no `sourceMappingURL` trailer; bundles are IIFEs
  and leak no globals. 3.12.1 is the expected version — this is a **pre-bump**
  build.
- **PCP against the BUILT ZIP** — unzipped to `smaily-connect-pkg` in the
  wp-env CLI container (never over the bind-mounted `smaily-connect`), `wp
  plugin check smaily-connect-pkg --slug=smaily-connect --format=csv
  --allow-root --exclude-directories=vendor`: **0 ERRORS, 9 WARNINGS**
  (3.12.0 baseline: 0 ERRORS, 5 WARNINGS). The container copy was removed
  afterwards. See §PCP below.
- **i18n** — `bin/build-i18n.sh` re-run: the regenerated `.pot`/`-et.po` differ
  from the committed ones in **line references only** (msgid set identical;
  the same 5 pre-existing untranslated Estonian strings as 3.12.0, no new
  ones). The working tree was restored to HEAD afterwards.
- Integration suite **not** re-run (audit pass, no product code changed; it was
  green at `77ac67e`: 300 tests / 1 781 assertions, 1 pre-existing skip).

## PCP result vs. the 3.12.0 baseline

The five baseline warnings are unchanged in class (`BackfillJob.php:513`
`DirectQuery` + `NoCaching`; `EventsEndpoint.php:728` the same pair;
`HookHandler.php:258` `DynamicHooknameFound`). Four warnings are **new**:

| New warning | Class | Assessment |
|---|---|---|
| `includes/DB/QueueJanitor.php:223` `DirectDatabaseQuery.DirectQuery` + `.NoCaching` (×2) | already-accepted class | The line **carries** a `phpcs:ignore` for exactly those two codes (l. 222) and the repo's own PHPCS honours it (0 findings for the file). PCP's run does not, for this construct only — verified empirically: adding a `--` reason changes nothing, deleting the identical `phpcs:ignore` in `Activation.php` **does** make that warning appear (so PCP honours annotations in general), and wrapping l. 223 in `phpcs:disable`/`phpcs:enable` silences it. Cosmetic; a bump-time suppression fix, like the 3.12.0 gate's `fetch_row()` restore. |
| `includes/Smaily/EventQueue.php:361` `PreparedSQLPlaceholders.UnfinishedPrepare` | already-accepted class, real inconsistency | The erasure DELETE's `phpcs:disable` list (l. 358) omits `UnfinishedPrepare`, which the sibling `rows_for_privacy_request()` block (l. 300) does list. The sniff is a false positive — the placeholders arrive via the interpolated `{$sendable}` / `{$where[0]}` fragments, and every value is bound. The repo's own PHPCS reports the same warning. |
| `includes/Bootstrap.php:627` `Squiz.PHP.DiscouragedFunctions.Discouraged` (`set_time_limit`) | **new class** | Real, and deliberate (§9) — the same allowance core's `WP_Upgrader` takes, guarded by `function_exists()`. Either annotate it with the reason or accept it; it should be settled before the wordpress.org submission, where a discouraged-function warning is read by a human reviewer. |

**No new ERROR, and no new finding of substance** — every new warning is
either the known accepted `DirectDatabaseQuery`/`PreparedSQLPlaceholders`
class or an intentional, documented `set_time_limit()`.

## Follow-ups this audit leaves open

1. **Low (§1)** — make `UpgradeLock`'s stale takeover atomic.
2. **Low (§2)** — add a JSON-escaped variant of the address to the privacy
   fallback LIKE so a non-ASCII recipient is erased too.
3. **Info / release hygiene** — restore the three PCP suppressions
   (`QueueJanitor.php:222` → `phpcs:disable`/`enable`; add `UnfinishedPrepare`
   to `EventQueue.php:358`; annotate `Bootstrap.php:627`) so the accepted-warning
   set returns to the 3.12.0 baseline of five.
4. **Info** — add `UpgradeLock::OPTION` to `EnvScrub`'s `$keys_to_flush` before
   any integration test asserts on it.
5. **Packaging note for the bump (not a finding)** — `bin/build-i18n.sh`
   rewrites the committed `.pot`/`-et.po` line references, which makes the
   working tree dirty and stamps `build-hash.txt` as `<sha>-dirty`. The
   verifier does not check for that, so at the bump the i18n regeneration must
   be **committed before** `composer run package` (this pass's ZIP carries
   `f5f865c-dirty` and is a gate artifact, not a release candidate).
