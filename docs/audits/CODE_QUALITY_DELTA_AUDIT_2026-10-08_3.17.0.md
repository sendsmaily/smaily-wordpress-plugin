# Code-quality + wordpress.org-readiness delta audit — 3.17.0 pre-release gate (3.16.0..HEAD)

- **Date:** 2026-10-08
- **Baseline:** `e0e5f71` (tag `3.16.0`, the version live on wordpress.org).
  The 3.16.1 GitHub-only release (`1f30b45`) has no audit row of its own in
  `docs/audits/INDEX.md`, so its commits (#176–#182) are folded into this
  scope.
- **HEAD:** `91d1c9b` (main; the version strings still say 3.16.1).
- **Delta:** `e0e5f71..91d1c9b` — 31 commits, 88 files, +5 931 / −398. Shipped
  plugin code (`includes/`, `integrations/`, `admin/wizard.php`, `admin/src/`
  non-test, `smaily-connect.php`, `uninstall.php`) is about 30 files,
  +1 400 / −240. The work in it:
  - **3.16.1 content:** PRO-3821 (#177, a cancel holds when a batch already
    started), PRO-3862 (#178, every refusing Smaily body code fails or retries),
    PRO-3872 (#180, Elementor repeater defaults), PRO-3873 (#181, admin menu
    opens Settings after setup).
  - **PRO-3868** (#184) failed contact import stays failed and stops at the
    failing page; **PRO-3817** (#185) Retry-After capped at 60 s;
    **PRO-3859** (#186, #189) + **PRO-3899** (#198) nightly §3c catalog
    manifest and its memory release; **PRO-3824** (#187) CF7
    `force_opt_in=true` explicit; **PRO-3884** (#188) draft/private/pending
    products sent as removals; **PRO-3890/3886** (#190) CI import failure and
    stall detection; **PRO-3889** (#191) legacy client `force_opt_in` default
    `false`; **PRO-3888** (#192, `bin/` only); **PRO-3897** (#193) same-second
    queue order; **PRO-3881/3882** (#194) failure reason on screen;
    **PRO-2384/2448/3906** (#195, #196, #199) GDPR eraser over both queues;
    **PRO-3904** (#197) contact import reads Smaily body codes; **PRO-3902**
    (#200) contact import failure/stall; **PRO-3907** (#203) refusal prefix
    stripped on screen; **PRO-3908** (#204) eraser finds orders in every status
    and letter case; **PRO-3981** (#206) daily contact refresh restarts a
    stalled import; **PRO-3982** (#205, CI only).
- **Auditor:** Claude (Opus 5.5), read-only.
- **Trigger (re-audit policy):** point 1 (release boundary — 3.17.0 is the next
  wordpress.org version), point 2 (GDPR/consent: the eraser; SQL against
  custom tables: the address match, the queue ordering, `delete_unsent`), and
  point 3 (a new outbound call: the nightly manifest).

## Scope and method

- File-by-file read of `git diff 3.16.0..main` for the shipped code, plus the
  call chains it reaches: `CustomerHookHandler`, `AutomationRouter`,
  `Bootstrap::on_backfill_tick` / `make_backfill_job` / the daily contact
  refresh, `BackfillEndpoint::start/cancel`, `IngestFlusher::event_types()`,
  the queue migrations (indexes), `OrderBackfillJob::table_spec()`.
- Each change read against its DECISIONS.md / STATUS.md intent (PRO-3859,
  PRO-3884, PRO-3886/3890/3902/3981, PRO-3904, PRO-2384/3906/3908, PRO-3817,
  PRO-3824/3889).
- WooCommerce's own eraser and customer data store read from the local wp-env
  copy of WooCommerce (`includes/class-wc-privacy-erasers.php`,
  `includes/data-stores/class-wc-customer-data-store.php`,
  `includes/abstracts/abstract-wc-privacy.php`) to check eraser interplay.
- Repo-rule checklist from CLAUDE.md (IsoDate, SkuResolver, `sending_allowed()`
  vs `is_connected()`, new AS group in `Deactivation::AS_GROUPS`, uninstall
  sweep, `update_option` default-ON trap, EnvScrub keys, SetupState /
  ContactSyncMode accessors, raw `get_sku()`, gross money).
- i18n: every new `__()` in the delta checked in `languages/smaily-connect.pot`
  and `languages/smaily-connect-et.po`.
- readme.txt changelog sized with a script; docs site diff read for EN/ET
  pairs.
- Static tools run read-only: `vendor/bin/phpcs -n` (the project gate),
  `vendor/bin/phpcs` with warnings, `vendor/bin/phpstan analyse`.
- Not run: unit/integration suites, PCP (needs the CI-built ZIP and wp-env),
  any live walk. Nothing in the repo was changed.

## Verdict

**0 High, 3 Medium, 5 Low, 9 Info.** No repo-rule violation in the delta.
`phpcs -n` exit 0, PHPStan level-config "No errors". The three Mediums are
correctness gaps against the intent their own decisions record; none is a
regression a 3.16.0 store would notice on update, but M1 defeats the stated
goal of PRO-3906 on the most common erasure, and M2 can make the new nightly
manifest fail silently on large catalogs. Each needs a fix or an explicit
acceptance by Erkki before the 3.17.0 cut.

## Findings

| # | Sev | Where | Finding |
|---|---|---|---|
| M1 | Medium | `includes/Privacy/GdprHandler.php:128,181-196`; `includes/Integrations/WooCommerce/CustomerHookHandler.php:69` | WooCommerce's own customer eraser runs after ours and re-enqueues the erased customer |
| M2 | Medium (magnitude unmeasured) | `includes/Smaily/RecEngine/CatalogManifest.php:100-115`; `includes/Smaily/RecEngine/Backfill/CatalogBackfillJob.php:144` | The whole-catalog walk runs in one Action Scheduler action with no time-limit raise, and the Event Log row is created only after it |
| M3 | Medium (Smaily per-contact codes not verified live) | `includes/Smaily/BackfillJob.php:417,433`; `includes/REST/BackfillEndpoint.php:280-296` | One contact Smaily refuses for good blocks every later contact in every run, the daily refresh included, and the merchant cannot see which contact |
| L1 | Low | `includes/Smaily/RecEngine/IngestQueue.php:404-405` | New `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` warning — a likely new PCP warning |
| L2 | Low | `includes/Integrations/WooCommerce/CatalogHookHandler.php:146,157-165,187-190,262-265` | Draft default-language product with a published translation: live sync sends removals that the import and manifest do not |
| L3 | Low | `docs/site/index.html` (Step 4 / Products); `readme.txt` | PRO-3884's user-visible change (unpublishing removes a product from recommendations) is not documented outside the manifest paragraph |
| L4 | Low | `includes/Privacy/GdprHandler.php:507-531` | The eraser loads every order of the address in one request, now including trash and custom statuses |
| L5 | Low | `includes/Smaily/RecEngine/CatalogManifest.php:145-152` | Only `ApiException` is caught around the send; any other Throwable leaves the row `pending` |
| I1–I9 | Info | see below | |

---

### M1 — WooCommerce's customer eraser re-enqueues the erased customer after our eraser dropped the waiting updates

**Intent (DECISIONS PRO-3906):** "the drop runs before the engine call, so no
waiting row is sent after the engine has forgotten the customer". A later
profile save is accepted as "a new action".

**What happens:** `Bootstrap::boot()` runs at plugin-file load and registers
`GdprHandler` on `wp_privacy_personal_data_erasers` at priority 10.
WooCommerce registers its erasers on the same filter at priority 10
(`WC_Abstract_Privacy::init()`, `$erase_priority = 10`) when `woocommerce.php`
loads. `active_plugins` is sorted alphabetically, so `smaily-connect/…` loads
before `woocommerce/…`, and our eraser runs **before** "WooCommerce Customer
Data". That eraser (`WC_Privacy_Erasers::customer_data_eraser()`) blanks the
billing/shipping fields and calls `$customer->save()` unconditionally;
`WC_Customer_Data_Store::update()` calls `wp_update_user()`, which always fires
`profile_update`. `CustomerHookHandler::on_profile_update()` (gated only on
`is_connected()`) enqueues a fresh `customer.upsert`. The WP user keeps its
`user_email`, so `CustomerFlusher` sends the customer to the engine within a
minute — after our §9 DELETE. The engine creates the customer again.

**Scenario:** any "Erase Personal Data" request for a registered customer on
a store connected to Campaign Intelligence. This is the most common erasure,
and it is not an action by the customer. The gap predates PRO-3906, but
PRO-3906 is the fix for exactly this class and does not cover it; its "Not
covered" list does not mention it.

**Evidence level:** code reading against WooCommerce 10.x source and WP's
plugin load order; not executed. `RecEngineGdprTest` calls
`GdprHandler::erase()` directly and never runs WooCommerce's eraser.

**Recommendation:** run the drop once more after every eraser has finished.
Core fires `wp_privacy_personal_data_erased` (with the request id) after the
last eraser page. Hooking it to call `delete_unsent( customer.upsert, [user] )`
(and the order variant) catches rows that WooCommerce's eraser, or any later
eraser, enqueued. Moving our eraser to a later priority is the other option,
but then WooCommerce's opt-in order eraser can anonymise `billing_email` before
`orders_for()` looks it up. Add an integration test that runs the full eraser
list (or at least `customer_data_eraser()` after ours) and asserts no
`customer.upsert` row is left. The same `profile_update` also re-enqueues a
Smaily `contact.sync` through `HookHandler`. That is the merchant's own ESP and
outside PRO-3906, but worth a sentence in the decision.

### M2 — The nightly manifest walk has no time budget and leaves no trace when it dies

**What happens:** `CatalogManifest::run()` calls
`CatalogBackfillJob::manifest_items()`, which loads every published and
trashed product with `wc_get_product()`, expands variations and resolves keys,
all inside one Action Scheduler action. It only creates the `catalog.manifest`
Event Log row (`row_id()`, line 115) after the walk returns. Nothing raises the
PHP time limit. Action Scheduler's queue runner only raises it to its own 30 s
runner limit. `Bootstrap::maybe_run_upgrade()` is the one place in the plugin
that calls `set_time_limit( 300 )` for a long job.

**Scenario:** a store with 10 000+ products (variable products multiply the
work) runs Action Scheduler through WP-Cron or the async loopback request with
PHP-FPM's usual `max_execution_time = 30`. On Linux that limit counts script
CPU time. A whole-catalog build of `WC_Product` objects can exceed it. PHP
kills the request with a fatal "Maximum execution time exceeded". AS marks the
action failed. No Event Log row exists, so the merchant sees nothing. The same
thing happens every night, and the engine never gets a manifest. PRO-3899
measured memory on 600 products; walk time was not measured.

**Evidence level:** inferred from the code path and PHP/AS behaviour; the
per-product CPU cost is unmeasured — treat the threshold as uncertain.

**Recommendation:** (1) raise the limit at the top of `run()` the same way the
upgrade does (`function_exists( 'set_time_limit' )`, a few minutes). (2) Create
the row before the walk and mark it failed with a plain reason when the build
throws, or leave it `pending` with a "building" marker, so a death is visible.
(3) Time `manifest_items()` on a 10k-product fixture once and record the number
in the PRO-3859 decision.

### M3 — One contact Smaily refuses for good blocks every later contact, in every run

**What happens:** since PRO-3868 and PRO-3904, a refusing body code on the
single-contact upsert (`BackfillJob.php:417`) takes the failure path at line
433. The import goes `failed`, the cursor stays before the page, and no
further batch runs. Since PRO-3981 the daily contact refresh restarts a
`failed` import with `start( false )`. It skips the fresh contacts, reaches the
same refused contact, and fails again. Contacts with a higher user id are never
reached by the import or the refresh while that contact exists. The screen
reason (`failure_reason()`) is Smaily's message with any address masked. The
stored `error_message` carries no user id. Only the debug log, which is off by
default, names `user_id`. The merchant therefore cannot find the contact to
fix.

**Scenario:** one WP user whose address WordPress accepted but Smaily refuses
(code 204 "invalid email", or 203 for a field value Smaily rejects). Every
manual Start import and every daily refresh stops at that user. Live sync on
profile changes still works, so the damage is limited to the import and the
refresh. Whether Smaily answers a single bad contact with a per-request 2xx
refusal code is not verified live here.

**Intent check:** DECISIONS PRO-3904 accepts "Start import is the retry". It
does not consider a contact that fails on every retry, and the PRO-3868 change
has no DECISIONS entry of its own (see I8).

**Recommendation:** either (a) keep the stop for transport, 5xx and 225
answers but, for a permanent per-contact refusal (`TerminalDispatchException`),
record it, skip that contact and continue the walk; or (b) keep the stop and put
the WP user id (not the address) into the stored reason, so the panel can say
which user to fix. Record whichever Erkki picks as a DECISIONS entry.

### L1 — New UnfinishedPrepare warning on the eraser's DELETE

`IngestQueue::delete_for_privacy_request()` passes an interpolated `$where`
into `$wpdb->prepare()`. `phpcs` (warnings on) reports
`WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` at line 405. The
`phpcs:disable` on line 404 names only `InterpolatedNotPrepared` and
`NotPrepared`. `EventQueue.php:310,359` disable `UnfinishedPrepare` for the
same pattern. The project gate (`phpcs -n`) hides warnings, but Plugin Check
reports them, so this is likely a new PCP warning beyond the 10 accepted at
3.16.0. **Recommendation:** add `UnfinishedPrepare` to that disable, as
`EventQueue` does. No behaviour change.

### L2 — Draft default-language product with a published translation flaps

The import and the manifest let a published translation stand in for a draft
canonical (`units_to_send()`: the canonical is not enumerated, so the
translation is). The live hook agrees only when the saved post is the published
translation (`on_save_product`, line 146). Three other paths decide on the
canonical's status alone and send the `in_stock=false` removal:
`on_stock_change()` (157–165: every sale of the product), the canonical's own
save, and the P4 translation-removal paths (187–190, 262–265). The nightly
manifest then sends `in_stock=true` for the same sku and the engine reports it
under `stock_fixed`. The product drops out of recommendations after each sale
and comes back at 03:00. This is rare (multilingual store, default language
left as draft), and there is no test for it. **Recommendation:** have
`is_published()` also accept a published translation, the same rule
`canonical_is_enumerated()` / the import applies, or record the gap as
accepted in PRO-3884.

### L3 — PRO-3884 is user-visible but undocumented

Saving a product as draft, private or pending now removes it from
recommendations, and publishing brings it back. The docs site states it only
inside the manifest paragraph ("Drafts, private and pending products are not
in the list", EN line 548 / ET 569). Nothing in the Products/catalog section
says a non-published product stops being recommended, and trashing is not
documented either. CLAUDE.md's docs rule asks for the site to change in the
same commit as a user-visible behaviour change. **Recommendation:** one
sentence pair (EN+ET) in the catalog/Products section, and a changelog line
(see the punch-list).

### L4 — The eraser loads all of an address's orders at once

`orders_for()` now returns every order id for the address in any status,
trash included, and `erase()` hydrates every `WC_Order` in one request,
`done: true`. WooCommerce's own order eraser pages in blocks of 10 to avoid
timeouts. An address with thousands of orders, such as the shop's own address
used for phone orders or a B2B buyer, can time out the erasure AJAX request.
The pre-PRO-3908 `wc_get_orders( limit -1 )` had the same shape, so this is
not a regression, only wider. `LOWER()` on both sides also defeats the
`billing_email` index (HPOS) and the postmeta value match, which is acceptable
for an admin one-off. **Recommendation:** page by `$page` (ids in chunks) and
return `done` accordingly, or accept and record.

### L5 — A non-API Throwable during the manifest send leaves a pending row

`run()` catches `ApiException` only around `catalog_manifest()`. Any other
Throwable escapes to Action Scheduler, and the row created by `row_id()` stays
`pending` with no exchange. The next night reuses it, so nothing is lost, but
the Event Log shows a pending manifest for a day with no reason. **Recommendation:**
add a `\Throwable` branch that marks the row failed with the class name, never
the message. This mirrors the PRO-3890 rule.

---

### Info

- **I1** `includes/Smaily/RecEngine/Backfill/CatalogImportOnConnect.php:47` —
  `$this->job === null` is dead: `Bootstrap::catalog_backfill_job()` always
  returns an object. The nullable type can go.
- **I2** The failure reason on the import panel is English whatever the admin
  locale (Smaily's message, or `Class at file:line`). It is wrapped in the
  translated "Backfill failed: %s", which is acceptable.
- **I3** `readme.txt` "External services" covers the manifest under "product
  catalog data … stock status". A clause such as "a nightly list of product
  codes and stock status" would make it exact. The storefront recommendations
  fetch (3.16.0, outside this delta) is also not named there.
- **I4** The Art 15 exporter does not export the Campaign Intelligence queue's
  `sent_payload` copies, which Art 17 now deletes. The asymmetry predates the
  delta, but the copies hold the same personal data the exporter lists
  elsewhere.
- **I5** `EventQueue::privacy_request_where()` (line 459): `LOWER( payload )`
  cannot fold a JSON escape, so a stored uppercase non-ASCII letter
  (`Ä`) does not match the lowercased pattern (`ä`). This only
  affects key-less rows (pre-migration-011 and transactional rows) with
  uppercase accented addresses.
- **I6** PRO-3862 treats every Smaily body code except 101 as a refusal. This
  is correct by Smaily's published table. If any endpoint the flushers call
  ever answers success with another code, rows now fail instead of being
  marked sent (uncertain, no evidence it happens).
- **I7** `GdprHandler.php:514` — the new raw query's `phpcs:ignore` does not
  list `PluginCheck.Security.DirectDB.UnescapedDBParameter` (the SQL comes
  from `order_ids_sql()`). PCP may warn; uncertain until PCP runs on the ZIP.
- **I8** PRO-3868 (#184) has no DECISIONS entry of its own. Four later entries
  reference it, and STATUS/LESSONS §2.28 describe it.
- **I9** The legacy `order_ids_sql()` branch has no `post_type` filter, so a
  `shop_subscription` post with `_billing_email` is treated as an order.
  `wc_get_order()` returns a `WC_Subscription` (a `WC_Order`), and its meta is
  erased too. This is probably desirable; noted for the record.

### Repo-rule checklist (CLAUDE.md) — all pass

- New AS group `smaily-rec-catalog-manifest` is in `Deactivation::AS_GROUPS`;
  `uninstall.php`'s `smly_rec_%` sweep covers the hook; the recurring
  registration sits behind the hourly verified marker as documented.
- Sends gate on `sending_allowed()` (manifest `skip_reason()`); enqueue paths
  stay on `is_connected()`.
- Keys: `manifest_item()` / `manifest_item_unresolvable()` go through
  `SkuResolver::resolve()` / `resolve_id()` with the builder's detector, the
  same calls `build()` / `build_unresolvable()` make. No raw `get_sku()`.
- `in_stock` in the manifest uses `is_in_stock()`, the same as `build()`;
  trashed means false, which matches the flusher's stamp.
- No new datetime field on the wire (IsoDate rule n/a); `started_at` parsing in
  `is_stalled()` appends `UTC` to a `current_time( 'mysql', true )` value, which
  is correct.
- No new option, no `update_option( …, false )`, and no EnvScrub key needed
  (EnvScrub's change keeps `smly_plus_schema_version`, PRO-3899).
- `SetupState::completed()` is used for the menu switch, not the raw key.
- No order-money change.
- The manifest event type is not drained by any flusher:
  `IngestFlusher::event_types()` names only `catalog.upsert` / `catalog.delete`,
  and no caller uses an unscoped `pending()`.
- The admin bundle loads on both menu layouts: `smaily_connect_enqueue_admin_bundle()`
  matches `strpos( $hook_suffix, 'smaily-connect' )`, and the wizard's
  `view` switch keys on the `page` slug, which both layouts keep.
- `bin/` scripts (PRO-3888, the PRO-3859 walk) are excluded from the ZIP by
  `.zipignore` (`/bin*`) and start with `defined( 'ABSPATH' ) || exit`.

### Performance

- `IngestQueue::has_pending()` uses `LIMIT 1` on `idx_status_retry`, so it is
  cheap.
- `delete_unsent()` scans the pending and failed range of the same index per
  500-id chunk, which is fine for a one-off.
- `delete_for_privacy_request()` and the EventQueue fallback are full scans
  with `LIKE`, admin-triggered and accepted in PRO-2384/2383.
- `EventQueue::pending()` ordering `created_at, id` is served by
  `idx_status_created`, because InnoDB secondary keys carry the PK, so it adds
  no filesort.
- `IngestQueue::pending()` had no `(status, created_at)` index before and still
  has none; adding `id` to the ORDER BY changes nothing material.
- `BackfillEndpoint::status` now makes one `as_has_scheduled_action()` query
  per poll, and only for a `running` row older than 10 minutes.
- The manifest walk: memory is handled by PRO-3899; time is not (M2).

### Test coverage

Covered: manifest (unit 10 + integration 6 cases incl. over-limit, retried
row, gates, 03:00 schedule, deactivation), drafts (unit + integration incl.
variations and translations), stall/failure for all imports, failure reason
masking and prefix strip, eraser over both queues (incl. non-ASCII, accent
neighbours, waiting rows, custom-status and case-differing orders — HPOS
unit-only), Retry-After cap, CF7/legacy opt-in defaults, admin menu both
states, Elementor defaults.

Gaps: the eraser together with WooCommerce's own erasers (M1); manifest walk
time (M2); a contact refused on every retry (M3); stock change on a
draft-canonical/published-translation product (L2); a non-API Throwable during
the manifest send (L5); the HPOS order lookup is unit-only on the dev wp-env
(legacy storage), as STATUS notes.

### i18n

Every new user-facing string is wrapped with `smaily-connect` and present in
the `.pot` and translated in `-et.po`: "Run setup again" ("Seadista uuesti"),
"The import stopped running in the background." ("Import lakkas taustal
töötamast."), "Not sent: the store has more than %s products, …" (with a
translators comment), and the rewritten Step 4 "Import existing data" copy.
The panel prefix "Backfill failed: %s" was already translated. **No missing
strings.** The admin-bundle JSON is a build artefact, regenerated by
`bin/build-i18n.sh` in CI.

### Docs consistency

The docs-site diff edits EN/ET as pairs: the menu section (#181), Step 1/4
connection placement, the manifest paragraph, the CF7 "Important" note, the
import progress table, stalled-import text, and the eraser paragraph. The
`data-lang="en"` / `"et"` counts are 133/131 at both 3.16.0 and HEAD, so the
delta adds no lone-language block. The open items are L3 and the pending
Estonian proofread STATUS already lists.

### Static tools

- `vendor/bin/phpcs -n -q .` (the project gate): **exit 0**.
- `vendor/bin/phpcs` with warnings: 0 errors, 25 warnings in 10 files. Most
  predate the delta (reserved-word parameter names `$object`/`$match`,
  `CatalogPayloadBuilder` style). The one new warning in delta code is L1
  (`IngestQueue.php:405`).
- `vendor/bin/phpstan analyse --memory-limit=2G`: **[OK] No errors** (exit 0).

## Release punch-list — 3.17.0

1. **Erkki's disposition of M1, M2 and M3** — fix, or accept with a DECISIONS
   note. M1 and M2 have small, local fixes: a `wp_privacy_personal_data_erased`
   re-drop, and a `set_time_limit` plus an early Event Log row.
2. **L1** — add `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` to the
   `phpcs:disable` at `IngestQueue.php:404`, before the PCP run.
3. **readme.txt** — add `= 3.17.0 =` changelog and Upgrade Notice entries and
   keep `= 3.16.1 =`. The changelog section is now **3 730 characters**
   (3.16.1 = 829, 3.16.0 = 2 901), so with 3.16.0 kept the 3.17.0 entry must
   stay under about **1 270 characters** to remain under the 5 000 limit;
   otherwise drop the 3.16.0 block (the GitHub releases link already covers
   older versions). The entry must mention:
   - New: the nightly product list to Campaign Intelligence. It runs at 03:00,
     makes one `catalog.manifest` Event Log row, and removes products the
     store no longer has.
   - Changed: draft, private and pending products are no longer recommended;
     publishing brings them back.
   - Fixed: an import that hits an error or stops running now shows as failed,
     with the reason. A contact import fails when Smaily refuses a contact
     instead of counting it as synced, and the daily refresh restarts a stalled
     contact import.
   - Fixed: queued changes to one product made in the same second are sent in
     order.
   - Security & privacy: the personal-data eraser also removes Campaign
     Intelligence queue copies and waiting updates, finds orders in every
     status and letter case, and matches non-ASCII addresses.
   - Optional: background engine calls wait at most 60 s on Retry-After.

   Keep "Updated to … contract v1.12.0" off unless the contract version moved.
4. **Version bump** in the nine places CLAUDE.md lists, including the `.pot`
   `Project-Id-Version`. Bump `Stable tag` to 3.17.0.
5. **L3** — one EN+ET sentence on non-published products in the docs-site
   catalog section. Erkki's Estonian proofread of every changed ET sentence
   before the FTPS publish (STATUS item 5).
6. **PCP against the CI-built ZIP** (release.yml dry run, then
   `wp plugin check … --slug=smaily-connect`). Expect the 10 accepted
   warnings. Watch for new ones at `IngestQueue.php:405` (L1) and
   `GdprHandler.php:514` (I7).
7. **Human acceptance already listed in STATUS:** the PRO-3859 manifest live
   walk on a fresh SANDBOX token (then `bash bin/lib-smly-snapshot.sh
   snapshot`), and the PRO-3872 customer check.
8. Record this audit (and the parallel security delta audit) as rows in
   `docs/audits/INDEX.md` and in STATUS.md.

## Conclusion

The delta is careful work. The compare-and-set rule (a cancel stays a cancel)
is applied consistently across both import families, and the stall check is
one shared definition. The manifest reuses the import's walk and key resolver,
so its keys cannot drift from the catalog sync. The eraser's binary,
quote-bounded address match closes the accent-neighbour and `\uXXXX` traps.
Static gates are clean and every new string is translated. **3.17.0 may
proceed once M1–M3 are fixed or explicitly accepted, L1 is applied, and the
readme changelog is written within the 5 000-character budget.**

---

## Disposition (2026-10-08)

| # | Disposition |
|---|---|
| M1 | **Fixed** in PRO-3986 (#207): `GdprHandler::after_erasure()` runs the PRO-3906 drop again on `wp_privacy_personal_data_erased`, after the last eraser (WooCommerce's included); an integration test runs WordPress's real erasure sequence. The related Smaily contact re-send after an erasure (the `contact.sync` the same profile save queues, found in the PRO-3994 spike) is PRO-3995 — in progress at the time of writing, to land before 3.17.0. |
| M2 + L5 | **Fixed** in PRO-3987 (#208): the `catalog.manifest` Event Log row is written before the walk, the PHP time limit is raised to 600 s where the host allows it, and a build failure or any unexpected error during the send marks the row failed with the error class and file:line. Remaining, in the backlog as PRO-3989: a run the host kills still leaves the row `pending` until the next night, and the walk time on a large catalog is not measured. |
| M3 | **Fixed** in PRO-3988 (#209): a contact Smaily refuses for good is skipped and listed on the import panel, and the import goes on. |
| L1 | **Fixed** in the PR that records this audit: `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` is added to the `phpcs:disable` / `phpcs:enable` pair around the DELETE in `IngestQueue::delete_for_privacy_request()`, as `EventQueue` does. The SQL is unchanged. |
| L2 | Existing backlog item PRO-3898. |
| L3 | **Fixed** in the PR that records this audit: one EN+ET sentence in the docs site's Step 4 catalog-import paragraph says that only published products are recommended, that a draft, private, pending or trashed product is removed from recommendations, and that publishing it again brings it back. The Estonian sentence awaits Erkki's proofread; the changelog line belongs to the 3.17.0 bump. |
| L4 | Backlog, PRO-3997. |
| I1–I9 | Accepted as recorded above. |

The security delta audit of the same date
([`SECURITY_DELTA_AUDIT_2026-10-08_3.17.0.md`](SECURITY_DELTA_AUDIT_2026-10-08_3.17.0.md))
records its own dispositions; its Info items on `release.yml` (no
`permissions:` block, tag-pinned actions — already noted in the 3.15.0 audit)
and on Contact Form 7's `force_opt_in = true` (Erkki's decision, PRO-3824) are
accepted.
