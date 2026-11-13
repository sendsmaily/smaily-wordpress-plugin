# Security + code-quality delta audit — after 3.22.0 (3.22.0..45b6b49)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-13_PRE_3.22.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-13_PRE_3.22.0.md),
which covered `3.21.1..afbac7f`, and the 3.22.0 release-gate row in
[`INDEX.md`](INDEX.md). Both stay valid for what they covered. This one covers
everything that landed on `main` after the 3.22.0 tag.

- **Date:** 2026-11-13
- **Baseline:** tag `3.22.0`. Delta `3.22.0..45b6b49` on `main`: 11
  commits, 55 files, +3 399 / −433. The shipped PHP in the delta is
  **17 files, +738 / −190**, plus the admin bundle (`EmailDesigns.tsx`,
  `Step2Subscribers.tsx`, the API clients, the progress hook, the types),
  `.po` / `.pot` strings and the docs site.
  - **#329 (CON-304) — catalog import, one product fails alone:**
    `CatalogBackfillJob::enqueue_record()` catches a Throwable from loading or
    building one parent post and queues its row already failed
    (`not_built`); a Throwable after a failed query (`$wpdb->last_error`) is
    thrown on and stops the import.
  - **#331 (CON-291) — background upgrade wrapper + column gate:**
    `Bootstrap::maybe_run_upgrade_in_background()` on
    `action_scheduler_before_process_queue` logs and keeps an upgrade
    Throwable; `Migrator::schema_ready()` now also reads the column with
    `SHOW COLUMNS … LIKE %s`, and `Migrator::schema_failed()` ends the import
    tick, the sequence check and the history-progress check, and makes the
    backfill routes answer `schema_incomplete` / a failed card.
  - **#332 (CON-203) — Router same-language lookup + TranslatePress:**
    `Router::find_same_language_mapping()` (second query, prepared) matches a
    row whose code normalises to the looked-up code;
    `ContactLanguageResolver::normalize()` now public static;
    `TranslatePressAdapter::get_current_language()` returns `$TRP_LANGUAGE`;
    `CartHookHandler` records it.
  - **#334 (CON-286 / CON-305) — unreadable state + unreadable contacts:** a
    state row the database cannot read answers a failed card;
    `BackfillJob::start()` returns 0 when its row write fails; new option
    `smly_plus_contact_import_unreadable` (`BackfillJob::OPTION_UNREADABLE`)
    listed by `/backfill/status` as `unreadable` and erased by the
    personal-data eraser; the orders import fails a run whose cursor is not a
    window instead of asking the engine for a new one.
  - **#336 (CON-311) — design link `display`:** `/rec-engine/emails/design-link`
    takes `display` (`frame` | `tab`, schema `enum`) and passes it on as §22
    `display`; the design window waits for `ready`, keeps `changes.unsaved`,
    asks before closing with unsaved changes; the iframe gains
    `allow="clipboard-read; clipboard-write; fullscreen"` and
    `referrerpolicy="no-referrer"`.
  - **#337 (CON-312) — nightly manifest out-of-stock fallback:** a product
    that cannot be loaded is listed out of stock from its database ids (raw
    `has_term()` + a prepared `wp_posts` variation query); the night is not
    sent when more than 20 % of the listed parent products could not be
    loaded (`CatalogManifest::MAX_UNLOADABLE_PERCENT`); the products-import
    gate reads the state row and skips the night when it cannot.
  - **#338** — refactor after a simplification review (`CatalogManifest::
    fail_row()`, `CatalogBackfillJob::post_units()` /
    `is_collapsed_translation()`, the design window's new-tab button).
  - **#330 / #333** — contract syncs to v1.31.0 / `e91335f` (§5 `channel`,
    §21 frame mode built, §22 `display` / `frame_origin` / `ready` /
    `changes`); **#335** — STATUS only.
- **Auditor:** Claude (Opus 5.5).
- **Trigger (re-audit policy):** point 3 — a REST route's parameters changed
  (design-link `display`), REST answers changed (`/backfill/status`,
  `/backfill/start`), a cross-origin iframe's permissions and its
  `postMessage` handling changed, custom-table and core-table SQL
  (`SHOW COLUMNS`, the Router query, the manifest's variation query), a new
  stored option holding user ids, GDPR erasure scope, Throwable handling in
  background runs.
- **Scope:** read of the whole shipped diff and the code it reaches:
  `CatalogBackfillJob` (enqueue, manifest walk, fallback), `CatalogPayloadBuilder::
  expand()` / `expand_by_id()` / `manifest_item_unresolvable()`,
  `IngestFlusher::row_to_object()`, `AbstractD6Flusher` (the `not_built`
  path), `EventsEndpoint::retry()`, `CatalogHookHandler::enqueue_sync()`,
  `CatalogManifest`, `AbstractBackfillJob::process_batch()` / `read_state()` /
  `is_live()`, `Bootstrap` (`maybe_run_upgrade()`,
  `maybe_run_upgrade_in_background()`, `on_backfill_tick()`, the daily
  contact tick), `Migrator`, `ImportsOnConnect`, `HistoryProgress`,
  `OrderBackfillJob::next_page()`, `BackfillJob` (`start()`, the skip lists),
  `BackfillEndpoint` (`start()`, `status()`, `contact_list()`),
  `GdprHandler::erase_skipped_contact()`, `uninstall.php` (option sweep),
  `EmailDesignsEndpoint`, `admin/src/components/steps/EmailDesigns.tsx`,
  `Step2Subscribers.tsx`, `Router`, `TranslatePressAdapter`,
  `CartHookHandler::storefront_language()`, `ContactLanguageResolver`. Read
  against `docs/RECENGINE_API_CONTRACT.md` (v1.31.0) §3c, §5 and §22,
  DECISIONS CON-203, CON-286, CON-291, CON-304, CON-305, CON-311, CON-312. No
  real customer data was used.
- **Method:** static read. CI on `45b6b49`: "Lint and test the codebase"
  (run 43483892228 — PHP 8.0–8.3, admin bundle, blocks and the three
  integration legs) and "Contract staleness" (run 43483892244) green.
  `release.yml` `workflow_dispatch` dry-run on `main` (run 43484026712) built
  the ZIP; VERIFY and Plugin Check below. Not run: the integration suite
  locally, any live walk, any call to the engine, a TranslatePress store.

## Verdict

**0 Critical, 0 High, 1 Medium, 2 Low, 7 Info.** No code is changed in this
PR.

**RESULT: the next release may proceed once M1 is decided.** M1 is not a
defect of this delta: Plugin Check 2.2.0 (the earlier gates ran 2.1.0) adds a
check that reports three ERRORs on the admin bundle, which inlines React 18.
The 3.22.0 release asset gives the same three under 2.2.0. It needs a recorded
decision — accept with the reason, or move the admin bundle onto WordPress's
React — before the next release is cut. L1 and L2 are backlog.

No new security exposure. The new REST parameter is enum-validated twice, the
design window still takes messages only from the stored engine origin and
opens only a link on it, every new query is prepared or uses constant table
names, the new skip list holds user ids and error locations only and is
erased with the user's other data, and every new catch stores or logs the
error type and place, never a message text.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| M1 | Medium | `dist/admin/admin.js:9` (built from `admin/src/`) | Plugin Check 2.2.0 reports 3 ERRORs: `inlined_react`, `inlined_react_dom`, `inlined_react_jsx_runtime`. Pre-existing: the same three on the 3.22.0 release asset under 2.2.0; the check is new in 2.2.0. First PCP ERROR since the 3.0 gate | Decision before the next cut: accept with a recorded reason, or externalise React to WordPress's copy |
| L1 | Low | `includes/Smaily/RecEngine/Backfill/CatalogBackfillJob.php:170`–`:180`; `includes/Smaily/RecEngine/IngestFlusher.php:99`, `:111`–`:116` | A CON-304 `not_built` row is keyed on the PARENT post id. For a variable product, a Retry (single row or "Retry all failed") of a `catalog.upsert` row that now loads sends the variable parent as one catalog row, `woo-<parent>`, in stock — a sku no other path sends — and none of its variations; a `catalog.delete` row sends a `build_unresolvable(<parent>)` tombstone and no variation tombstone. The live hook queues one row per unit | No — backlog |
| L2 | Low | `includes/Multilingual/TranslatePressAdapter.php:63`; `includes/Integrations/WooCommerce/CartHookHandler.php:308`–`:314`; `includes/Support/ContactLanguageResolver.php:104` | On a TranslatePress store, a cart change made by an AJAX or Store API request whose URL has no language slug records the store's default language (DECISIONS CON-203 names this). The cart language comes FIRST for a logged-in shopper, ahead of `_user_preferred_language` and the order language, so such a shopper can now get the reminder in the default language where 3.22.0 used their stored preference. Not checked on a live TranslatePress store | No — backlog: a manual check of AJAX add-to-cart and the block cart on a TranslatePress store |
| I1 | Info | `includes/Bootstrap.php:877`–`:883` | A persistently failing background upgrade is tried again on every Action Scheduler queue run (each takes the lock and re-runs the failing step), one debug line each, no back-off. Before CON-291 the same run threw on every queue run and ended it | No — accepted |
| I2 | Info | `includes/DB/Migrator.php:153`; `includes/REST/BackfillEndpoint.php` (`schema_incomplete_message()`) | `schema_failed()` is terminal: no later update re-runs migration 013, so the imports stay off until someone adds the column by hand; the card says "Contact Smaily support". Documented (DECISIONS CON-291) | No — backlog: a repair step (re-run 013's ALTER in the background) if this is seen in the field |
| I3 | Info | `includes/DB/Migrator.php:181` | `SHOW COLUMNS … LIKE 'sent_count'`: `_` is a LIKE wildcard, so a column such as `sentXcount` would also match. No such column exists; the value is prepared and the table name is the prefix plus a constant | No — accepted |
| I4 | Info | `includes/Smaily/RecEngine/Backfill/CatalogBackfillJob.php:183`–`:186` | `database_failed()` reads `$wpdb->last_error` of the LAST query, which can be an unrelated query that a third-party hook ran and ignored inside `wc_get_product()`. A broken product then stops the import or fails the night instead of failing alone. Fail-closed | No — accepted |
| I5 | Info | `includes/Smaily/RecEngine/CatalogManifest.php:88`, `:96`–`:98` | The 20 % guard counts parent products, not skus: a few large variable products that cannot be loaded can list well over 20 % of the store's skus out of stock while the parent share stays under 20 %. Documented in the constant's docblock | No — accepted |
| I6 | Info | `admin/src/components/steps/EmailDesigns.tsx:447`–`:448` | The iframe delegates `clipboard-read` (and write, fullscreen) to the engine origin; the browser still asks the user before a read. No `sandbox`, as before (pre-3.22.0 I9). Contract §22 Embedding rule 6 asks for exactly this | No — accepted |
| I7 | Info | `includes/Smaily/RecEngine/Backfill/OrderBackfillJob.php:151` | An orders import run started before 3.21.0 (a bare-id cursor) and still `running` after the update now fails once with `UnexpectedValueException`; Import now starts it again with a new window. Documented (DECISIONS CON-286) | No — accepted |

---

## M1 — MEDIUM — Plugin Check 2.2.0 reports three ERRORs on the admin bundle

`wp plugin check` with Plugin Check **2.2.0** reports, at
`dist/admin/admin.js:9`:

- `inlined_react_jsx_runtime`, `inlined_react`, `inlined_react_dom` (ERROR) —
  "This file inlines the "react" package instead of externalizing it. The
  bundled copy predates React 19 and will likely break when WordPress
  upgrades to React 19."

The admin bundle is a Vite build with its own React 18.3 (`package.json`
`react` / `react-dom` `^18.3.1`). It has done so since the React admin
shipped; the earlier gates ran Plugin Check 2.1.0, which has no such check.
To confirm it is not this delta, the same Plugin Check 2.2.0 run against the
**3.22.0 release asset** (SHA256 `ac6ed3d6…1aea`) reports the same three
ERRORs and the same 17 warnings.

What it means in practice: the bundle uses its own React and reads only
`window.wp.i18n` from WordPress, so a WordPress move to React 19 does not
replace the React it runs on — the breakage the message predicts needs a
component shared with WordPress's React, which this bundle does not use.
The risk is the review signal: wordpress.org reviewers and merchants run
Plugin Check, and an ERROR reads as a defect. It also costs bundle size
(the 250 KB gzip budget carries React).

**Decision for Erkki** before the next release is cut: (a) accept, and record
in DECISIONS why the inlined copy is safe here (self-contained, no shared
components) — the next gates then expect 3 ERRORs / 17 WARNINGS; or (b) build
the admin bundle against WordPress's React (`wp-element`, React 18.3 on the
supported floor WordPress 6.6) as a planned piece of work, with its own
compatibility check on the oldest stack.

## L1 — LOW — retrying a variable product's `not_built` row sends the parent

`enqueue_not_built()` queues the failed row under the enumerated **parent**
post id — the only id known when `wc_get_product()` or `expand_by_id()`
threw. The live hook (`CatalogHookHandler::enqueue_sync()`) and the import's
normal path queue one row per **unit** (each variation of a variable
product). The row is terminal, but the Event Log's Retry (single row, and
"Retry all failed" — `EventsEndpoint::retry()` resets every failed ingest
row) sends it again through `IngestFlusher::row_to_object()`:

- `catalog.upsert`: once the product loads (for example after the broken
  extension is fixed), `build()` builds the **variable parent** as one unit,
  sku `woo-<parent id>`, with the parent's stock. No other path sends that
  sku, so the engine gets an extra, recommendable row; the variations are
  not refreshed. The next nightly manifest lists the variations only, so it
  tombstones the extra row within a day.
- `catalog.delete`: the row has no captured object, so the flusher sends
  `build_unresolvable( <parent id> )` — a tombstone for `woo-<parent id>` —
  and no tombstone for the variations (the nightly manifest lists them out
  of stock, so they are corrected the next night).

Nothing is lost for good and nothing leaks; the engine catalog is wrong
for up to a day after such a retry. **Proposal (backlog):** have the
flusher expand a variable parent on a `catalog.upsert` row into its units
(or queue the retry through `CatalogHookHandler`'s per-unit path), and on a
`catalog.delete` row without an object expand the parent's variation ids
the way `not_loaded_items()` does; pin it with a unit test of a variable
`not_built` row.

## L2 — LOW — a TranslatePress cart may record the default language

CON-203 makes `TranslatePressAdapter::get_current_language()` return
`$TRP_LANGUAGE`, which TranslatePress sets from the request URL's language
slug and to the default language when there is none, and
`CartHookHandler` now records it (the TranslatePress exclusion of PRO-4253
is gone). DECISIONS CON-203 notes that an AJAX or REST cart request whose
URL has no slug records the default language. What it does not note: in
`ContactLanguageResolver::for_user()` the recorded cart language comes
**first**, before the shopper's `_user_preferred_language` and the language
of their last order. So a logged-in shopper on the English storefront of an
Estonian-default store whose cart changes go through a slug-less AJAX URL
(archive AJAX add-to-cart) or the block cart's Store API (if its URL carries
no slug) gets the reminder in Estonian, where 3.22.0 used their stored
preference. Whether TranslatePress puts the slug on those URLs, or sets the
language from the referrer, was not checked — the wp-env has no
TranslatePress.

**Proposal (backlog):** a manual check on a TranslatePress store (classic
AJAX add-to-cart on an archive page and the block cart); if the default
language shows up, record no cart language when the request is AJAX/REST and
`$TRP_LANGUAGE` equals the default.

## Info notes

- **I1** — `maybe_run_upgrade()` releases `UpgradeLock` in `finally` and
  stamps the version last, so each retry is a full, locked attempt. The log
  line is `DebugLog::where( $e )` — type and place only.
- **I2** — the import cards, `/backfill/start` (500 `schema_incomplete`) and
  the docs site (both languages) say so; the history-progress and sequence
  checks stop re-arming and the hourly registration arms them again once
  the column is there.
- **I4** — `wpdb::flush()` resets `last_error` before each query, so the
  read is of the last query only; a stale error from an earlier request
  cannot leak in.
- **I5** — the night's row records `unloadable` and `products` in its
  exchange, and the debug log names each product id listed out of stock.
- **I6** — the frame link is a one-time URL in the iframe's `src`, admin
  only; `referrerpolicy="no-referrer"` keeps it out of the engine page's
  outbound `Referer`.

## Confirmed clean

- **Design-link `display`.** The route's schema has `enum: ['frame','tab']`
  (WordPress refuses any other value with 400) and the handler passes it on
  only after `in_array( …, true )`. `return_url` handling is unchanged. The
  route keeps its permission check; the link is never stored or logged.
- **Design window messages.** The listener still drops any message whose
  `event.origin` is not the stored engine origin, or whose `type` is not
  `smaily_intelligence_design`; `ready` / `changes` only flip local UI state
  (`unsaved` taken only when it is a boolean); nothing from a message is
  rendered or used as an id. The frame `src` is set only when the link starts
  with `engine_origin + '/'`; the tab path still sets `opener = null`.
- **Status routes.** `/backfill/status` and `/backfill/start` keep their
  permission callbacks; the new failed answers carry plugin-worded
  sentences, no database error text. `unreadable` uses the same builder as
  `refused`: user id, the address with only its first character before the
  `@`, `get_edit_user_link()`, and a reason screened by `screen_reason()` —
  for this list the error class and line. React renders all of it as text.
- **The unreadable-contacts option.** `{count, contacts: [{user_id,
  reason}]}`, at most 20 entries, autoload off, cleared by `start()`, swept by
  `uninstall.php`'s `smly_plus_%` delete, in `EnvScrub`'s flush list. The
  personal-data eraser now walks both skip lists and lowers each count; no
  email is stored, so the exporter has nothing to add. A WP user deleted
  before the erasure leaves a bare user id until the next run (as the
  refused list).
- **SQL.** `Migrator::column_present()` — `prepare()` on the column name,
  table name `$wpdb->prefix` + constant, `phpcs:ignore` with reason, result
  cached per request and cleared after a migration run; a database error
  decides nothing (null). `Router::find_same_language_mapping()` —
  `prepare()` on `trigger_type`, constant table, reads three columns, `ORDER
  BY id`, matched in PHP through the one normaliser. `variation_ids()` —
  `prepare()` with four placeholders on `{$wpdb->posts}`, mirroring
  `WC_Product_Variable::get_children()` (publish + private). No new Plugin
  Check SQL warning.
- **No remote text stored or logged.** Every new catch (`enqueue_not_built`,
  `post_manifest_items`, `maybe_run_upgrade_in_background`, the manifest's
  `too_many_unloadable` row) stores or logs `DebugLog::where( $e )` or
  plugin-built counts. The manifest's debug lines carry a product id and the
  error location.
- **Throwable handling in background runs.** The catalog import's per-product
  catch rethrows a database error, which `process_batch()` catches and
  records as the run's failure (PRO-3890); the manifest's fallback rethrows
  a database error or a detector Throwable, which `build_and_send()` catches
  and fails the night with nothing sent; the orders cursor exception lands in
  the same `process_batch()` catch. The manifest skips the night when the
  products import state cannot be read (`import_active()` → null) instead of
  reading it as "not running".
- **Manifest fallback.** Out-of-stock listing (`in_stock: false`) is the safe
  side of §3c (the engine keeps the sku, not recommendable until the next
  upsert) — better than the earlier omission, which tombstoned. The sku comes
  from `SkuResolver::resolve_id()` (`woo-<id>`, PRO-1224). The collapse check
  runs before the fallback, so a translation never adds an item.
- **Router / language.** Normalised on lookup only; the exact-code query runs
  first, so short-code stores (WPML, Polylang) take the same path as before.
  The recorded cart language is normalised and clamped to the active
  languages at send time (F3-47 holds); the resolver itself stays
  context-independent.
- **Contract follow-through.** v1.30.0 §5 `channel` is optional with missing =
  `online`, so the plugin needs no change; v1.31.0 §22 `display` is sent;
  `frame_origin` is not needed (`frameable()` holds only on the site's own
  origin, rule 1 (a)).
- **Code quality.** Each change carries its DECISIONS entry, CLAUDE.md and
  docs-site pairs (EN + ET) were updated in the same PRs, and the docblocks
  state the contract, the failure modes and what is never stored. Tests pin
  the new paths (`CatalogBackfillJobTest`, `CatalogManifestTest`,
  `MigratorTest` with `SchemaColumnWpdb`, `RouterTest`,
  `TranslatePressAdapterTest`, `EmailDesigns.test.tsx`,
  `Step2Subscribers.backfillCopy.test.tsx`, `OrderBackfillJobTest`). #338
  removed duplication (`fail_row()`, one collapse check, one new-tab button)
  without changing behaviour.

## Release gate — CI-built ZIP, verify, Plugin Check

- **ZIP:** `release.yml` `workflow_dispatch` on `main`, **run 43484026712**
  (head `45b6b49`, green), downloaded as the workflow artifact
  `smaily-connect-zip` (not a release asset). `smaily-connect.zip`,
  **1 080 242 B**, SHA256
  `9f10269dc12e5ce57bc125b5cdb9879feb263eac48b91b776f91f9da84884a78`,
  build-hash `45b6b49`, 425 entries. Version 3.22.0 (no bump since the tag).
- **`bash bin/verify-release-zip.sh smaily-connect.zip 3.22.0`** (repo root):
  **VERIFY OK** (42 checks).
- **Plugin Check 2.2.0** — installed into the running dev wp-env cli
  container for this run (the container had none; left installed). The ZIP
  unzipped to `wp-content/plugins/smaily-connect-pkg` (never the mounted
  plugin path, not activated); `wp plugin check smaily-connect-pkg
  --slug=smaily-connect --format=csv --exclude-directories=vendor
  --allow-root`; the `-pkg` copy, a second copy of the 3.22.0 asset used for
  the M1 comparison, and the copied ZIPs removed afterwards, the mounted
  plugin checked intact. **3 ERRORS / 17 WARNINGS.** The 17 are the accepted
  ones, two at moved lines: `DirectQuery` + `NoCaching` at
  `BackfillJob.php:720` (was `:700`), `IngestQueue.php:507` and `:536`,
  `QueueJanitor.php:229`, `EventsEndpoint.php:804`; `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at
  `CartSessionStore.php:310`, `:334`, `GdprHandler.php:711` (was `:708`). The
  3 ERRORs are M1 (new check in Plugin Check 2.2.0, same on the 3.22.0
  asset). Nothing new from the delta: the `SHOW COLUMNS`, Router and
  variation queries raise no warning.

## Punch-list

1. **M1** — Erkki: accept the three inlined-React ERRORs with a recorded
   reason, or plan the move to WordPress's React. Until decided, the next
   gate expects 3 ERRORs / 17 WARNINGS under Plugin Check 2.2.0.
2. Backlog: L1 (expand a variable parent on retry), L2 (TranslatePress
   manual check), I2 (repair step for a missing column).

## Conclusion

The delta hardens failure handling — one broken product, an unreadable state
row, a failed background upgrade or a missing column no longer stops or
misleads the imports and the nightly list — and widens the design window and
the per-language lookup. None of it opens a new path for untrusted input:
the new parameter is an enum, the window's messages stay origin-pinned, the
SQL is prepared, the new stored list holds ids only and is erased with the
user, and no catch stores a message text. Two Low items are correctness
edges (a retried variable product, a TranslatePress cart language). The one
Medium item is Plugin Check 2.2.0's new inlined-React check, which the 3.22.0
release already fails the same way. **The next release may proceed once M1
is decided.**
