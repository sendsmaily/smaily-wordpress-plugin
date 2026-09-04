# CLAUDE.md — Agent Working Guide (Smaily Connect plugin)

If you are a fresh agent picking up this repo: read this first, then `STATUS.md`
(where we are now), then `docs/RECENGINE_API_CONTRACT.md` (the contract you
build against), `docs/DECISIONS.md` (why things are the way they are), and
`docs/LESSONS.md` (mistakes already made — don't repeat them). README gives the
30-second project orientation.

This file is the operational knowledge that otherwise lives only in agent memory
and commit messages. It exists so you don't re-discover it the painful way.

---

## Keeping the docs current — part of every change

The handoff docs are only worth reading if they're true. Keeping them current is
not a separate chore; it's part of the work that changed them.

- **STATUS.md** — update in the SAME commit that finishes a sub-PR, syncs the
  contract, or changes a lock condition / roadmap. (Its own header states the
  rule.) Stale status = a defect, treat it like a failing test.
- **CLAUDE.md** (this file) — when you learn a new operational fact the hard way
  (a new `sg`-style gotcha, a build-command change, a new scar), add it here in
  the same commit. The whole point is that the next agent doesn't re-discover it.
- **DECISIONS.md** — when a decision is made, changed, or superseded,
  record it (with why, not just what). A reversed decision gets updated, not
  silently dropped.
- **LESSONS.md** — when a class of mistake is caught (especially mock-vs-live,
  context-audit, or seam bugs), add the lesson so it generalizes.
- **README roadmap / INDEX.md** — if you change what's done or which files
  exist, refresh these too. They went stale once (README said Customers/Orders
  were pending after they shipped); don't let it recur.
- **Merchant docs site (`docs/site/index.html`)** — the bilingual (EN/ET)
  user-facing documentation that mirrors `connect.smaily.com/docs`. When a change
  alters **user-visible behavior** — a wizard step, a Settings control, an error
  or notice string, a consent/companion-plugin requirement, an import's behavior,
  a requirement (WP/WC/PHP floor) — update the site **in the same commit, in BOTH
  languages** (the two `data-lang="en"`/`data-lang="et"` blocks are siblings; edit
  the pair together or they drift). It's a single self-contained page — no build
  step; open it in a browser to verify. See the note below.

**The rule across all of them:** if your change makes a doc wrong, your change
isn't finished until the doc is fixed in the same commit. If you notice a doc is
already stale, fixing it is in-scope now, not a future task.

---

## Operational knowledge (the painful-to-rediscover bits)

### Integration tests need `sg docker`
The agent sandbox strips the `docker` supplementary group from the running
process, so a bare `docker info` looks like Docker is unavailable — it isn't.
Docker is installed and the daemon runs; you (the user `erkki`) are in the
`docker` group. Restore it per-command with `sg`:

```
sg docker -c "composer run test:integration"
```

Integration tests run real WP + WooCommerce + MariaDB via wp-env. Do NOT
conclude "Docker unavailable" from a bare `docker info` failure — use `sg`.

**Filtered/single-test integration runs:** prefer the wrapper — it passes
extra args through to phpunit AND keeps the PRO-1240 smly_rec_* snapshot/
restore guard (the suite boots the DEV site's WordPress in the `…-cli-1`
container, so even a filtered run can clobber the dev site's engine
connection):

```
sg docker -c "bash bin/run-integration-tests.sh --filter <TestName>"
```

If you must hand-roll a `docker exec`, use `phpunit.integration.xml.dist` —
the default `phpunit.xml.dist` loads the UNIT bootstrap (no wp-load, no
WooCommerce) and the test then fails with `undefined function
update_option()` / "WooCommerce missing" even though the real suite is green.
A hand-rolled run also bypasses the snapshot guard — wrap it in the shared
guard yourself (`bash bin/lib-smly-snapshot.sh snapshot` before, `… restore`
after — PRO-1256), or recover afterwards with
`bash bin/run-integration-tests.sh --restore-only` (restores the dev
connection from the durable snapshot without running the suite).

### Live-walk needs a fresh setup-token — from the SANDBOX tenant, never MiuMjau
**MiuMjau IS the pilot's PRODUCTION tenant** (engine-side correction,
2026-06-12 sync: the engine has exactly two tenants — MiuMjau and the
"Smaily Connect test" sandbox; there is no separate dev tenant). The
2026-06-12 walks ran against production and the engine team had to purge the
residue. **All future walks use the "Smaily Connect test" sandbox tenant** —
ask Erkki for a setup token from THAT tenant's Integrations page.

Also learned the hard way: under the engine's pre-0036 single-key model, a
token exchange ROTATED the tenant's only API key — the 2026-06-12 wp-env
exchange silently revoked the live pilot store's key mid-day. The engine now
issues per-connection keys (migration 0036), so this can't recur — but it's
the template for why dev work never touches a production tenant.

Mechanics (unchanged): the setup-token is **one-time** (consumed on
exchange) and connections get scrubbed by integration test runs. Since
PRO-1240 the `test:integration` wrapper snapshots/restores the dev site's
`smly_rec_*` options AUTOMATICALLY around a suite run, and since PRO-1256
the same guard is shared with walk scripts (see the wp-env snapshot note
below) — only a hand-rolled `docker exec` phpunit run bypasses it, and
`bash bin/run-integration-tests.sh --restore-only` recovers from the
durable snapshot even then. When a live-walk reports `is_connected = 0`:
- Ask the user to mint a fresh SANDBOX token (or a full setup URL
  `https://<engine>/setup/<token>`) into a `/tmp/smaily_re_setup_*` file
  (plain token/URL, secret-safe file method).
- Exchange it via the plugin's real SetupExchange + store() path (F3-12).
- Never echo the token; delete temp files after.

**Secret-safe exchange mechanic (used CC.3, 2026-06-14).** The exchange runs
inside the wp-env cli container (`wp eval-file`), which can't read the host
`/tmp`. Bridge the token WITHOUT putting it on any command line: write a
container-visible PHP that reads the URL from STDIN (`trim(fgets(STDIN))`),
calls `SetupExchange::parse_setup_url()` → `exchange()` → `$settings->store()`,
and prints only NON-secret fields (kind, tenant_name, connected). Pipe the file
in: `cat /tmp/smaily_re_setup_url | sg docker -c "docker exec -i <cli> wp
eval-file <script> --allow-root"`. `docker exec -i` forwards stdin; the token
never appears in args/output. **Always print the resulting `tenant_name` and
abort any send if it's `MiuMjau`** — the dev wp-env can be left pointing at
production. `bin/walk-cc3-multilingual.cjs` bakes this safety gate in
(`sandbox_tenant_not_production`); after CC.3 the dev env is connected to the
"Smaily Connect test" SANDBOX — keep it there.

**Don't hand-roll that script any more — it's committed as
`bin/exchange-setup-token.php`** (2026-08-05). It reads the token or full setup
URL from STDIN, runs the real `parse_setup_url()` → `exchange()` → `store()`
path, prints only `kind`/`tenant_name`/`engine_host`/`engine_version`/
`connected`, and exits 2 with `PRODUCTION_TENANT_ABORT` if the tenant comes back
`MiuMjau`. A bare token (no host) reuses the stored engine base URL. Usage:
`cat /tmp/smaily_re_setup_token | sg docker -c "docker exec -i <cli> wp eval-file
wp-content/plugins/smaily-connect/bin/exchange-setup-token.php --allow-root"`,
then `bash bin/lib-smly-snapshot.sh snapshot` so the new connection becomes the
durable one a suite run restores, then delete the temp token file (it is
one-time and now consumed).

### woocommerce-stubs are PHPStan-only
`woocommerce-stubs` is in the PHPStan config, NOT the runtime autoload. In unit
tests, WC objects are built with PHPUnit `createMock` + shared shims (e.g. the
`WC_Order` shim in HookHandlerTest, `WC_Order_Item_Product`). Reuse this pattern
for any new WC-dependent unit test.

### Use SkuResolver for the engine product key — ALWAYS `woo-<id>`, NEVER the merchant SKU (PRO-1224)
The engine keys catalog, order items, AND browse events on `sku`, but the
engine's `sku` is a **join/identity key, not a human SKU** (contract §3, identity
rule sharpened 2026-07-09). Every place that puts a product key on the wire goes
through `Support\SkuResolver`, which ALWAYS emits `woo-<canonical_id>` — the
platform id (variation id for a variable product, product id for a simple one),
namespaced `woo-`, **never the merchant WC SKU field, not even as a fallback**.
Deleted-product order lines key from the ids stored on the line item, else the
order-item id (`woo-oi-<item_id>`); a line is never dropped (F3-43).
- **Why the reversal (PRO-1224 supersedes F3-36's "real SKU else `wc-{id}`"):**
  the merchant SKU is optional, blank, reused, or garbage on real stores (a price
  `"63.00"`, a seq `"12"` shared by dozens of products, an EAN) — keying on it
  collapses distinct products onto one `(tenant, sku)` row and silently destroys
  history (Urban Green 605→330, PRO-1223). The engine is adding fail-loud
  namespace validation that rejects off-scheme keys. Prefix is `woo-`, NOT `wc-`.
- **`tags.product_id` (added PRO-1224):** every catalog row carries the RAW
  (un-prefixed) canonical PARENT product id via `SkuResolver::product_group_id()`
  — the grouping key (cross-variant cadence, PRO-1227) and the product-level
  removal key (`catalog/remove` §3b, PRO-1230). RAW, not `woo-`-prefixed —
  deliberate Shopify parity (`tags.product_id = product.id`) + the §3b example
  (`product_ids: ["7620134"]`). Do not namespace it.
- **Merchant SKU is dropped entirely** (engine answer PRO-1225, 2026-07-10): the
  engine consumes it nowhere (ranking/serving key on `sku`; no console/report/
  export shows it). NEVER put it in `catalog.external_id` (that field is the raw
  platform variant id + drives collision detection). If a future operator-debug
  need appears, it goes in `tags.merchant_sku`, never `external_id`/`sku`.
- **Migration:** a store already synced under the old scheme keeps its stale rows
  (UPSERT-only, no delete-by-absence) — the `woo-<id>` keys make fresh rows.
  Orphan removal is a one-time manual purge coordinated per store with the engine.
- A raw `get_sku()` + empty-check reintroduces the pilot's day-1 breakage (silently
  empty catalog, D6-failed orders, rejected browse events) AND the collision bug.
  If you add a new SKU surface, use the resolver; if a record still can't be keyed,
  make it observable (terminal skip), never a silent pre-enqueue drop (LESSONS §2.11).

### Order status: custom statuses go THROUGH; deleted-product lines are NEVER dropped (F3-42/F3-43)
Two pilot data-loss fixes (engine brief 2026-06-19, order #58922) that flip
earlier decisions — don't re-revert them:
- **Status mapping is a DENYLIST, not an allowlist.** `OrderPayloadBuilder::
  map_status()` sends `''` (skip) ONLY for `pending`/`on-hold`/`failed`/
  `checkout-draft`/`draft`/`auto-draft`/`trash`; **every other status — incl.
  any custom shipping status (`label-printed`, `shipped`, …) — defaults to a
  sale (`processing`)**. The old 5-key allowlist silently dropped custom
  statuses (the orders never reached the engine). `on-hold` is now NON-sale
  (reverses F3-22, per the engine team — payment not captured). The order
  backfill mirrors this: `status NOT IN (non_sale_wc_statuses())` (CC-9 single
  source). Engine `status` is a strict enum (`completed|processing|cancelled|
  refunded`) — a custom status must MAP to one, never pass through raw.
- **A deleted-product order line is never dropped.** `SkuResolver::
  resolve_order_item()` never returns `''` — when current WC has zeroed the
  stored ids it keys on the order-item id (`wc-oi-{item_id}`), so the line (and
  the whole order) is never silently lost (#58922 was marked "sent" with no
  POST because an empty `items[]` terminal-skipped + `mark_sent`). The empty-
  items terminal skip now only fires for a genuinely product-less order
  (shipping/fee only). Reverses F3-36's drop-the-line for the deleted case.

### Order money is GROSS (tax-inclusive) — never a bare `get_total()` on a line (PRO-1241)
Contract v1.4.0 §5: every money field on the orders wire is **gross — what the
customer paid**. In WooCommerce, `WC_Order_Item::get_total()` is **NET (ex-tax)**
— serializing it raw under the gross `total_amount` understated per-SKU revenue
~24% on the pilot (median `unit_price/catalog.price` ≈ 1/1.24, Estonian VAT).
`OrderPayloadBuilder` is the single chokepoint (live hook, flusher retries and
the order backfill all build through it at send time): `line_total =
get_total() + get_total_tax()`, `unit_price = gross line ÷ qty` (post-discount,
NOT `subtotal/qty`), line discount = the gross subtotal-vs-total delta, order
`discount_amount = get_total_discount( false )` (the parameterless default is
ex-tax — an easy re-regression). Sender invariant: `Σ items[].line_total +
shipping ≈ total_amount` (engine monitors, doesn't reject — so the mock doesn't
reject either; the pinning lives in unit + integration payload asserts). If you
add a new order money field, put it on the gross basis and extend the invariant
tests.

### Event Log "Details" shows the real request + engine response (F3-44)
Order/catalog/Smaily queue rows enqueue an EMPTY `payload` (the flusher builds the
wire object fresh at send), so Details used to show `Payload: []`. The flushers now
store the send-time exchange per row via `IngestQueue` / `EventQueue::store_exchange`:
`sent_payload` (the exact JSON POSTed; NULL on a terminal skip) + `last_response`
(`{http, outcome, error?}`). Migration 007 added both columns to BOTH queues. Rules
that must hold: **never store the Authorization header** — the Smaily `Client` captures
the exchange in `request()` from method/endpoint/body + reply, NOT the auth `$args`;
the Smaily `Flusher` reads `Client::last_exchange()` in a `try/finally` so a throwing
call is still captured; fields trim to ~10 KB and are janitor-pruned. A terminal-skip
stores `last_response={outcome:"skipped"}` — that's how you tell a row marked "sent"
that never actually POSTed (the #58922 confusion). Stored for ALL rows, success too.

### Trashing a product fires NO catalog hook — it's kept as `in_stock=false`
`before_delete_post` is **permanent-delete-only**; trashing routes through
`wp_update_post`, so a trashed product fires neither the delete nor (usefully)
the save hook. Left alone, a trashed-but-once-bought product silently keeps a
stale engine catalog row or has none — its order lines orphan the
`order_items.sku ↔ catalog.sku` join (the 2026-06-17 pilot ~4% miss, F3-40).
The fix keeps it in the graph as `in_stock=false` (engine has no delete-by-key;
a `catalog.delete` row IS an `in_stock=false` upsert): `Bootstrap` binds
`wp_trash_post → on_delete_product` and `untrashed_post → on_save_product`, and
`CatalogBackfillJob` enumerates `publish` **and** `trash`. **Trap (cost a green→
red integration cycle):** `wp_trash_post()` then calls `wp_update_post(trash)`,
which fires `save_post_product` → `on_save_product` AFTER the removal — re-upserting
`in_stock=true` and undoing it. `on_save_product` early-returns when the saved
post's status is `trash`; don't remove that guard. A *permanently* deleted PARENT
product (incl. a purge-from-trash) now fires the §3b product-level tombstone
(PRO-1230): `before_delete_post → on_hard_delete_product` enqueues ONE
`catalog.remove` row whose payload is the RAW un-prefixed canonical parent id
(`SkuResolver::product_group_id()` = `tags.product_id` — §3b matches that exact
string, never the `woo-` sku), drained by `CatalogRemoveFlusher` on its own AS
hook (NOT D6: response has no per-item errors[]; `not_found` is a success). A
single VARIATION's hard-delete keeps the per-SKU soft path — §3b would tombstone
its surviving siblings — and trash still never fires §3b. After any change here
the pilot needs a catalog re-backfill.

### A `catalog.delete` tombstone is ALWAYS force-filled and sent — never silently skipped (PRO-1498)
F3-39/F3-40 originally SKIPPED a captured removal object whose `category_path`
or `product_url` came back blank (the auto-draft-GC burst, 2026-06-14) — correct
for a never-published artifact (nothing to remove), but the same skip also fired
for a genuinely *synced* product whose removal object happened to come back
blank (MiuMjau: 51 live rows failing engine validation with empty `product_url`,
+1 with empty `category_path`), which left it stuck `in_stock=true` in the engine
forever (the engine has no delete-by-key, so a skipped/rejected removal can't
self-heal). **CatalogPayloadBuilder::ensure_valid_removal()** now force-fills a
still-blank `category_path`/`product_url` with a generic placeholder
(`'uncategorized'` / `home_url('/?smaily_connect_removed_product={id}')`) instead
of leaving it blank, and **CatalogPayloadBuilder::build_unresolvable()** builds a
whole minimal tombstone from the bare id when `wc_get_product()` fails
completely (e.g. a since-deactivated gift-card plugin's `product_type`) —
`CatalogHookHandler::enqueue_delete_unresolvable()` /
`CatalogBackfillJob::enqueue_unavailable_unresolvable()` call it when the
soft-delete path's `get_product()` returns null but the post IS (or was) a
product/variation. **Delete-only, deliberately**: the live `catalog.upsert` path
(`CatalogPayloadBuilder::primary_category_path()`) keeps failing loud on a
genuinely broken store — an empty required field there is still a real
merchant-data-gap signal worth surfacing (F3-39's original intent, unchanged).
`CatalogHookHandler::is_removable()` is retired (superseded by always-send). Out
of scope: §3b `catalog.remove` (PRO-1230) is untouched — a different mechanism
for a hard-deleted PARENT, not conflated with this per-SKU soft tombstone fix.
The mock (`tests/Integration/Fixtures/mock-rec-engine/router.php`) now rejects
an empty `product_url` the same way it already rejected `category_path`
(mirrors the live engine — PRO-1492 folded in here). MiuMjau's existing stuck
rows need a post-release re-drive/re-sync once this ships; the code fix alone
doesn't retroactively repair rows already captured with the old blank shape.

**PRO-1506 — the force-fill above ran ENQUEUE-time only; it now ALSO runs at
FLUSH time, so a pre-3.8.1 stuck row heals on Retry.** Confirmed live on
MiuMjau (2026-07-21): re-driving the 52 stuck rows via Event Log Retry failed
AGAIN with the identical errors — `IngestFlusher::row_to_object()` sends a
`catalog.delete` row's STORED captured object verbatim (only stamps
`event_id`/`in_stock=false`), so a row captured BEFORE the PRO-1498 fix just
resent the same stored blank forever; the enqueue-time fix cannot retroactively
heal a row already in the queue. The flusher's `catalog.delete` branch now ALSO
calls `ensure_valid_removal()` on the stored object before send (idempotent —
a no-op on an already-valid post-3.8.1 capture), and falls back to
`build_unresolvable( entity_id, event_uuid )` when the row has no captured
object at all (corrupt/missing payload) instead of a terminal skip with
nothing sent. This is why `RecEngineCatalogTest::
test_mock_rejects_empty_product_url_on_a_delete_row_like_the_live_engine` now
posts a raw blank payload directly through `Client::ingest_catalog()` instead
of enqueueing-and-flushing — that path can no longer reach the mock with a
blank value once the flusher repairs it first. MiuMjau's 52 rows still need
one more live Retry after this ships; the fix makes the NEXT retry succeed, it
doesn't resend anything by itself.

### A substituted `category_path` is flagged with `tags.category_defaulted` — never on an empty/unresolved one (PRO-1499)
Contract v1.6.0 (engine commit `06266a8`) adds optional catalog tag
`tags.category_defaulted` (`"true"`, omit-on-false): tells the engine a row's
`category_path` is a **placeholder the sender substituted**, not real
taxonomy, so it skips category-**slug**-keyed derivation for that row
(species-from-category, `category_canonical`, replenishable-from-category —
**name**-keyed derivations and any explicit tenant tag still win regardless).
Three call sites in `CatalogPayloadBuilder`, one rule: flag it ONLY when a
real (non-empty) placeholder value actually reached the wire.
- `build()` — `primary_category_path()` now takes an optional by-ref
  `$defaulted` out-param, `true` only on the PRO-1491 empty-terms →
  store-default-fallback branch; `tags()` stamps the flag only when
  `$defaulted` AND the resulting `category_path` is non-empty. An
  unresolvable store default (`category_path` stays `""`) stays UNflagged —
  that row fails the engine's REQUIRED-field check regardless, so there's no
  placeholder value to mark.
- `ensure_valid_removal()` (PRO-1498) — stamps the flag exactly when it
  force-fills a still-blank `category_path` with `PLACEHOLDER_CATEGORY`; a
  `category_path` `build()` already resolved is left untouched (and already
  carries the flag from `build()` if that was itself a store-default
  substitution).
- `build_unresolvable()` (PRO-1498) — ALWAYS carries the flag: there is no
  real product behind it, so `category_path` is definitionally a placeholder.
No mock change was needed — `router.php` already captures the whole `tags`
object per-SKU with no keys allowlist (the PRO-1224 `tags.product_id`
precedent). Live-walked against the sandbox engine
(`bin/walk-pro1499-category-defaulted.cjs`): `{"http":200,"outcome":
"accepted"}` with the flag on the sent payload.

### Use the IsoDate helper for datetimes — never raw format
The engine's strict Zod `.datetime()` requires Z-suffix (`Y-m-d\TH:i:s\Z`), NOT
`+00:00`. Raw `gmdate('c')` / `$date->format('c')` produces `+00:00` and the
engine rejects it. This bug shipped twice (customer `first_seen_at`, catalog
`on_sale_until`) before being caught by a live-walk. The fix is the shared
`IsoDate` helper (F3-21) — every builder uses it so the bug can't recur. Any new
datetime field goes through IsoDate.

### The "Sync contacts to Smaily" switch is `ContactSyncMode::sync_enabled()` — one accessor, one key (PRO-1742)
The master contact-sync switch lives in `smaily_connect_subscriber_sync_enabled`
(the legacy name — the wizard's save route writes it, and the pre-wizard
settings page wrote it before that) and is read ONLY through
`ContactSyncMode::sync_enabled()` (default ON): the four live `HookHandler`
gates, `ContactAudience` (which is how the backfill and the "about N will be
synced" estimate honour it), and `EnvDetector`'s hydration.
`SettingsEndpoint::LEGACY_OPTION_SYNC_ENABLED` is defined AS
`ContactSyncMode::OPTION_SYNC_ENABLED`, so the key has one spelling in the
plugin. The scar: the sync used to gate on `smly_plus_subscriber_sync_enabled`,
which nothing has ever written — both default ON, so it only bit the merchant
who switched contact sync OFF and kept syncing anyway. Automations (welcome /
first-order / abandoned cart) are deliberately NOT gated by it — they have
their own toggles and consent basis, and the merchant docs promise exactly that.
**Related WP trap, same fix:** `update_option( $key, false )` on an option that
was never saved writes NOTHING (WP compares against `get_option()`'s `false` and
concludes nothing changed) — harmless for a flag that defaults to off, silent
data loss for one that defaults to ON. This switch is therefore stored as
`'1'`/`''`. Check this before adding any new default-ON boolean setting.
**Its twin (PRO-2292):** the setup-completed flag `smly_plus_setup_completed` is
defined once as `Settings\SetupState::OPTION_SETUP_COMPLETED` and read ONLY
through `SetupState::completed()` — the live-sync gate, the cart tracker, the
abandoned-cart sweeper, the Settings redirect, `EnvDetector` and the daily
contact-sync tick all call it, and the wizard's Finish route writes through the
same constant. Check this before adding any new gate on either flag: read the
accessor, never the raw key.

### Contact-sync language goes through ContactLanguageResolver — never get_user_locale / get_current_language_code (F3-47)
The Smaily contact `language` code is resolved ONLY by `Support\
ContactLanguageResolver` (`for_user` / `for_order`). It is context-independent
(no `ICL_LANGUAGE_CODE`/`pll_current_language`/`get_user_locale` reads), so it
returns the same answer in a cron tick as an HTTP request. Sources mirror the
merchant's working Make automations: `_user_preferred_language` user meta →
most-recent order's `wpml_language` → the multilingual default via
`DetectorFactory` (WPML `wpml_default_language`, e.g. `et`) → site-locale short
code; normalised to the short form (`en_US`→`en`). The scar it routes around
(Prike, F3-47): the legacy cron's `Helper::get_current_language_code()` falls
back to `get_locale()` in cron = the WP **site** locale (`en`), which on an
`et`-content store with an `en` WP locale clobbered ~1000 contacts to `en`
daily. **Two rules:** (1) a NEW datetime-style sin — never reintroduce a raw
`get_user_locale()`/`get_locale()` language source on the contact path; route it
through the resolver. (2) **Omit `language` when the resolver returns `''`** —
Smaily treats absent as "leave existing intact", empty as "wipe"; the
HookHandler payload builders add the key only when non-empty. (3) The resolved
code is **clamped to the site's active languages** (`DetectorFactory::
get_detected_languages()`) — a code outside that set (dirty history, e.g. a
stray `ru` on an `et`/`en`-only store) falls to the default, so the sync can't
spawn a list that shouldn't exist; the resolver never invents a language, the
clamp just locks it. Contact sync is gated by `setup_completed` (email wizard),
independent of the rec-engine — so this can ship to a non-engine store. The corrective mass re-sync of an already-
drifted store is the backfill running the SAME resolver (SP-B), not a one-off.

### Build / test / walk commands
- `npm run ci:strict` — PHPCS + PHPStan + PHPUnit unit + JS (eslint/tsc/vitest).
  Must be `exit=0`.
  **vitest-green ≠ typecheck-green.** vitest runs through esbuild, which STRIPS
  TS types without checking them — a wrongly-typed test (e.g. a mock object with
  the wrong field shape) RUNS fine under `npm run test` but fails `npm run
  typecheck` (tsc). So `npm run test` passing tells you nothing about types.
  Always run the full `ci:strict` chain (it runs tsc after vitest), never just
  `npm run test`. (Scar 3.5.3a: a `getBackfillStatus` mock used camelCase
  `etaSeconds` instead of the API's snake_case `eta_seconds`; vitest green, tsc
  red, ci:strict exit=2.)
- `sg docker -c "composer run test:integration"` — real-environment integration.
  The build stamp lives at the plugin ROOT (`build-hash.txt`, PRO-1781), outside
  the out-dir a `vite build` empties — no post-build `package:hash` ritual is
  needed before an integration run any more.
- Live-walk scripts live in `bin/` (e.g. `bin/walk-3.3.cjs`). Run against the
  connected engine; needs a setup-token (above).
- `composer run package` — produces the distributable ZIP.

(Verify exact paths/scripts against the repo — this list is the working set as
of orders ingest; update if the build evolves.)

### Merchant docs site lives in `docs/site/index.html` — one bilingual HTML file
The user-facing documentation (install, wizard, settings, imports, errors, FAQ,
privacy) is a **single self-contained HTML page** at `docs/site/index.html`,
built to look and work like the Shopify docs at `connect.smaily.com/docs`. It is
**hosted separately** — live at `https://smaily.com/connect-woo/` — NOT shipped in
the plugin ZIP (`docs/` is excluded by `.zipignore`), so it never bloats the build.
The plugin links to it from the wizard/Settings screens + the Plugins page; every
UI link resolves through `Constants::docs_url()` (const `DOCS_URL`, filter
`smaily_connect_docs_url`) — **one line to change** when Smaily plugin docs move to
`connect.smaily.com` (the long-term plan: each plugin its own home there).

Facts that must stay true when you touch it:
- **Bilingual, one file.** EN and ET are parallel sibling blocks toggled by a
  `data-lang` attribute on `<html>` (JS in the page, choice saved to
  `localStorage`). Every translatable unit exists twice — `data-lang="en"` and
  `data-lang="et"`. Edit the pair together; a lone-language edit is a drift bug.
- **No build step, no external deps.** Inline CSS+JS, system/Inter font stack, no
  CDN/webfont/script src — so it renders identically whether opened as a local
  file, hosted anywhere, or previewed as a Claude Artifact. Keep it dependency-free.
- **Brand:** Smaily pink `#e91e63` (light) / `#ff5c9d` (dark accent for legibility);
  light+dark via `prefers-color-scheme` + a `data-theme` override.
- **Content source of truth is the real plugin**, not the Shopify copy — don't
  paste Shopify-isms that don't hold here (no Shopify OAuth "permission prompts";
  no 60-day order window; consent is the **WP Consent API** companion plugin;
  background work is **Action Scheduler**; GDPR is the **WP Privacy tools**). The
  keep-current rule is in "Keeping the docs current" above.

**Publishing it live (FTPS).** The live copy at `https://smaily.com/connect-woo/`
is published over FTPS — Erkki places a 3-line credentials file (host /
username / password) at `/tmp/smaily-connect-woo-ftp` on request (secret-safe
convention: never committed, never echoed). The account is chrooted directly
into the connect-woo web root (contains `index.html` only). Working upload
recipe: curl with explicit TLS, cert check relaxed (the FTP service cert
doesn't match the hostname), passive-mode workaround, and credentials via a
runtime-built `-K` config file (never on the command line):
```
curl --ssl-reqd -k --disable-epsv -K <cfg> -T docs/site/index.html ftp://smaily.com/index.html
```
**Publish only after the Estonian proofread of changed content** — the
PRO-1520-established human gate; don't push a language pair live unfiltered.

### Audits live in `docs/audits/` — re-run after bigger changes
All audit reports + the register table live in `docs/audits/` (start at
`docs/audits/INDEX.md`): the Fable codebase audit, the Security audit, the
Code-quality + wordpress.org-readiness audit (carries the GA/upstream punch-list),
the upstream audit + comparison, and the mock↔engine divergence register.

An audit is a snapshot of one repo state — it goes stale as code moves (the
2026-06-25 audits exist because ~10k lines landed after the 2026-06-11 one). So
**re-run the security + code-quality audits after a bigger change** — concretely:
before any GA/non-beta tag or wordpress.org submission; after a large delta
(rule of thumb > ~2,000 changed plugin lines); or after any change to a
security-sensitive surface (a REST route, the public `/relay` beacon,
auth/capability/nonce, crypto, custom-table SQL, what gets stored/logged
(secrets/PII), GDPR/consent, external HTTP/file I/O). Scope = the delta since the
last audit's baseline + a security pass on any high-risk surface it touched + PCP
**against the built ZIP** for a release gate. Record the run as a row in
`docs/audits/INDEX.md` + a dated report, and note it in STATUS.md. Skipping the
re-audit on a bigger change is a defect, like a skipped gate. The full policy is
in `docs/audits/INDEX.md` § Re-audit policy.

**Running PCP (WordPress Plugin Check):** in wp-env (PCP plugin installed via
`wp plugin install plugin-check --activate`). **Run it against the BUILT ZIP, not
the dev tree** — `composer run package` → `docker cp` the zip into the `…-cli-1`
container → unzip into `wp-content/plugins/<dir>` → `wp plugin check <dir>
--slug=smaily-connect --format=csv --allow-root --exclude-directories=vendor`.
Two gotchas that cost real time (2026-06-25, the pre-3.0 PCP-clean pass):
- **The dev-tree run UNDER-reports.** `wp plugin check smaily-connect` (the mounted
  working tree) hides `dist/` duplicate templates, the `blocks/` tree, and which files
  actually ship; it reads clean while the ZIP is not. The packaged ZIP is the only
  honest gate. (Conversely it also trips on dev-only `*.zip`/`.github`/`*.md`/configs
  that `.zipignore` excludes — noise, not findings.)
- **Always pass `--slug=smaily-connect`.** PCP infers the expected text domain from
  the plugin DIRECTORY name; unzip to any other dir (`smaily-connect-pkg`, …) and every
  `__( …, 'smaily-connect' )` call becomes a false `TextDomainMismatch` (hundreds of
  them). `--slug` pins the expected domain.
- For the **real release ZIP**, `composer install --no-dev --optimize-autoloader`
  before `composer run package` (else the ZIP ships phpunit et al.); ship `composer.json`
  (PCP flags `missing_composer_json_file` when `vendor/` ships without it).
- **NEVER `rm -rf` (or `mv` over) the container's
  `/var/www/html/wp-content/plugins/smaily-connect` — it is the BIND MOUNT of this
  host repo.** A container-side `rm -rf` on it deletes the entire host working tree
  INCLUDING `.git` (the mount point itself survives with "Resource busy" — that error
  is the tell that you just emptied the real repo). This happened for real at the
  3.6.1 gate (2026-07-11): full re-clone from GitHub required; only unpushed local
  commits + gitignored build artifacts were at stake (all recoverable that day —
  push discipline is what made it survivable). Always unzip the ZIP to a DIFFERENT
  dir (`smaily-connect-pkg`) + pin `--slug=smaily-connect`; never target the mounted
  plugin path with destructive container commands.
The `plugin_updater_detected` finding (the `Update URI` clobber-guard, F3-35) is GONE —
the header was removed 2026-07-23 ahead of the sendsmaily upstream merge (ships in
v3.9.0; see DECISIONS F3-35). The `mismatched_plugin_name` note is history only: it was
the `(BETA)` Name suffix, dropped at the 3.0 GA bump.

### React admin i18n — rebuild with `bin/build-i18n.sh`, never plain `compile-translations`
The React admin UI strings are wrapped with a thin `wp.i18n` shim
(`admin/src/lib/i18n.ts`, called as `__( 'text', 'smaily-connect' )`); the bundle
reads `window.wp.i18n` at runtime (it does NOT bundle `@wordpress/i18n`), and
`admin/wizard.php` enqueues the bundle with a `wp-i18n` dependency +
`wp_set_script_translations`. Two gotchas make the standard `compile-translations`
WRONG for this, so use **`bin/build-i18n.sh`** (it needs the wp-env container):
- **`wp i18n make-pot` cannot parse `.tsx`** (the bundled WP-CLI uses a PHP ES parser
  that chokes on TypeScript → it silently extracts ZERO admin strings). The script
  first **esbuild-transpiles `admin/src/` → a throwaway `_i18n-src/` of plain JS** so
  make-pot can see the `__()` calls.
- **`make-json` hashes its output to its own scheme**, but WordPress loads the
  script-translation JSON by `md5()` of the script path **relative to the plugin dir**
  — `dist/admin/admin.js` → `smaily-connect-et-464ceaab21588225a35cae9f83dfa47d.json`.
  The script builds the combined catalog (via a `--use-map`) and **renames it** to that
  fixed name. (The hash is stable; the path never changes.)
- The **committed** i18n source is `languages/smaily-connect.pot` + `…-et.po`. The
  `*.mo`/`*.json` are gitignored build artifacts (shipped in the ZIP via rsync, NOT
  git) — `bin/build-i18n.sh` regenerates them from the `.po`. Run it before packaging
  whenever admin strings or translations changed; the `.po` translations survive
  (`update-po` preserves `msgstr`). Verify a real render with the Playwright check
  (set the dev site to a locale, confirm `wp.i18n.__()` returns the translation).

### Cutting a release ZIP + GH release (official repo; local build = the reference)
**A release is cut in the official repository `sendsmaily/smaily-wordpress-plugin`
and the release ZIP is built by CI there — not on your machine** (PRO-2281,
2026-09-03: after the PR #135 merge the official `main` IS the working
repository; the fork `erkkimarkus/smaily-wordpress-plugin` is archived
read-only history). Your working checkout's `origin` should therefore be
`sendsmaily/smaily-wordpress-plugin` — check with `git remote -v` before
pushing a bump commit; if `origin` is still the fork, fix the remote (that is a
one-time human action, not something a task changes silently).

`composer run package` ALONE is not a release — it rsync+zips the working tree
but does NOT build the JS/blocks/translations, and `dist/`, `vendor/`,
`blocks/*/build/` are gitignored. **Since PRO-2277 `release.yml` runs this whole
sequence in CI**, and that CI ZIP is the artifact merchants get. Steps 1–6 below
stay documented because they are how you REPRODUCE or COMPARE a ZIP locally (a
packaging bug, a PCP run against the built ZIP, a pre-flight before tagging) —
they are no longer how the released asset is produced. Full sequence (verified
2026-06-14, v2.1.0-beta.3-rc.1):
1. Bump version in FOUR places: `smaily-connect.php` (Version header +
   `SMAILY_CONNECT_VERSION` + `SMAILY_CONNECT_PLUGIN_VERSION`), `package.json`,
   `readme.txt` (Stable tag + Changelog + Upgrade Notice). Also the test pins:
   `tests/Unit/ConstantsTest.php`, `tests/bootstrap.php`,
   `tests/phpstan-bootstrap.php` (else ConstantsTest fails). Commit FIRST so
   `package:hash` stamps a clean (non-`-dirty`) build-hash.
2. `npm run build:admin` → `dist/admin/*`,
   `dist/public/js/sc-runtime.js` + `dist/public/js/sc-landing.js` (the second
   storefront bundle is built by a chained `build:landing` pass — see the beacon
   note below; if it's missing from `dist/`, the landing pass didn't run).
   ONE command builds all three: the old `build:client` was a duplicate of
   `build:admin` (there is no `client` vite mode) and was removed in PRO-1949.
3. `composer run install-block-modules && composer run build` → `blocks/*/build/*`
   (the first installs `blocks/node_modules`; without it `wp-scripts` is missing).
4. Translations: run **`bash bin/build-i18n.sh`** (uses the wp-env container by
   default; set `WP_CLI_BIN=vendor/bin/wp` to run the wp-cli steps on the host
   instead, which is what CI does) to
   rebuild `languages/*.mo` + `*.json` — including the admin-bundle catalog
   `…-et-464ceaab….json` — from the committed `.po`. The plain `compile-translations`
   composer script does NOT produce the correct admin-bundle JSON (see the i18n note
   above). Skip only if no admin strings or `.po` translations changed AND the
   `*.mo`/`*.json` already on disk are current (they are gitignored, shipped from disk).
5. `composer install --no-dev --optimize-autoloader` (prod vendor) →
   `composer run package` → `composer install` (restore dev so tests work again).
6. VERIFY the ZIP before releasing — **`bash bin/verify-release-zip.sh
   smaily-connect.zip [expected-version]`** does every check in this step and
   exits non-zero on any failure (CI runs the same script): version string;
   required present
   (`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`,
   `dist/public/js/sc-landing.js`, `blocks/*/build/*`,
   `vendor/autoload.php`, `languages/*.mo`, `build-hash.txt` at the root);
   NOT present (`tests`, `docs`, any `*.ts` source, any `*.map`,
   `node_modules`, `admin/src`, dev vendor pkgs). `.zipignore`
   excludes `blocks/node_modules` (583M) — a bloated ZIP means it leaked.
   **Source maps are stripped from the ZIP (PRO-1949)** — `.zipignore` drops
   `*.map` and `composer run package` sed-strips the then-dangling
   `//# sourceMappingURL=` trailer out of the staged bundles, so the check is
   two-part: no `*.map` entries AND no trailer left in the shipped JS
   (`unzip -p … dist/admin/admin.js | grep -c sourceMappingURL` ⇒ 0). Local
   builds still emit maps — nothing about debugging changes, only the ZIP.
7. **Release it — push, tag, let CI build, then publish to wordpress.org.**
   a. Push the bump commit to `main` on `sendsmaily/smaily-wordpress-plugin`.
   b. `gh release create 3.11.2 --repo sendsmaily/smaily-wordpress-plugin
      --target main --title "…" --notes-file …` — **NO local ZIP argument**:
      the workflow attaches the asset. **Tag convention: the PLAIN version, no
      `v` prefix** (`3.11.2` — that is what `release.sh` builds its download URL
      from and what the repo's history uses; the workflow tolerates a leading
      `v`, don't rely on it). A NORMAL release, no `--prerelease`.
   c. `release.yml` builds admin + both storefront bundles + blocks +
      translations + a `--no-dev` vendor tree, runs `bin/verify-release-zip.sh`
      and attaches `smaily-connect.zip`. A tag that doesn't match the plugin
      header version fails the run **before** anything is uploaded. Wait for the
      run (`gh run watch`); optionally download the asset and re-check it with
      `bash bin/verify-release-zip.sh smaily-connect.zip <version>`.
   d. `./release.sh -u sendsmaily` — pushes that asset to the wordpress.org SVN.
      **One-way door:** it reaches every install; it is Erkki's to run.
   To dry-run the builder without touching a release:
   `gh workflow run release.yml --repo sendsmaily/smaily-wordpress-plugin --ref
   main` — on `workflow_dispatch` the verified ZIP is uploaded as a workflow
   ARTIFACT (`gh run download <id>`), never as a release asset.

**HISTORY — the fork flow (for reading old releases, not for cutting new ones).**
Releases up to and including v3.11.1 were cut on
`erkkimarkus/smaily-wordpress-plugin` with a **`v`-prefixed** tag
(`gh release create v3.3.2 smaily-connect.zip --repo erkkimarkus/… --target main`),
a locally built ZIP uploaded by hand, and a mandatory `--repo` because `gh`
defaulted to the sendsmaily remote we had no write access to. Older beta tags
add an `-rc.<N>` suffix + `--prerelease`. Do NOT copy any of this for a new
release; the fork's pre-PRO-2277 release runs are all red (the old broken
workflow, not a signal about those releases).

### CI "Lint and test the codebase" is PRE-EXISTING red on main — not authoritative
The GH workflow runs `composer run test:php` (= bare `phpunit`, includes the
Integration suite) in a runner WITHOUT WooCommerce → ~76 "WooCommerce not active"
errors. It has been red since before the catalog-correctness work (e.g. e22a26b,
2026-06-12). Do NOT read a red "Lint and test" as "I broke something." The
authoritative gates are LOCAL: `npm run ci:strict` (unit + static + JS) and
`sg docker -c "composer run test:integration"` (real WP+WC via wp-env). If you
touch CI, the fix is to run only `phpunit --testsuite unit` there (or give the
integration job a wp-env), not to chase the integration errors.

### Browse beacon ships as `sc-runtime.js` + `/relay` — NOT "beacon" (ad-block lists)
The storefront beacon's two browser-visible names are deliberately neutral: the
script is `dist/public/js/sc-runtime.js` (vite entry key `public/js/sc-runtime`,
source file still `public/js/beacon.ts`) and the proxy route is
`/wp-json/smaily-connect/v1/relay` (`BeaconEndpoint::ROUTE`). The word **"beacon"**
is on EasyPrivacy ad-block filter lists and was blocked for real pilot users (the
POST 404'd until the ad-blocker was disabled — F3-41). Do NOT rename these back to
"beacon", and don't introduce new browser-facing tracker-keyword names (track,
collect, analytics, pixel, telemetry…). Internal names (the `StorefrontBeacon` /
`BeaconEndpoint` classes, `beacon.ts`/`beacon-core.ts`, `window.smailyConnectBeacon`,
the `beaconUrl` config key) keep "beacon" on purpose — they're not browser-visible,
so renaming them is churn for no benefit. Whether a blocker still catches `/relay` is
a **manual browser check** (200 with the blocker on); the integration test only proves
the server dispatches `/relay`.

**There are TWO storefront bundles, and never both on one page (PRO-1767).**
`sc-runtime.js` is the full browse runtime (loaded on the `is_enabled()` gate:
connected + browse toggle + WC). `dist/public/js/sc-landing.js` (vite entry key
`public/js/sc-landing`, source `public/js/landing.ts`) is the attribution-ONLY
writer, loaded when the store is connected but the runtime is NOT
(`StorefrontBeacon::is_attribution_only_enabled()` — LandingCapture's gate, incl.
the `smaily_connect_capture_attribution` master switch, not the beacon's). It
exists because a browse-off store behind a full-page cache had NO attribution
writer at all: the cached response never runs PHP, so `LandingCapture` is blind.
Two facts that must stay true when you touch it:
- **Both bundles import `public/js/lib/attribution.ts`** (the one capture
  implementation, incl. the PRO-1710 UUID check) — so they are built in
  **SEPARATE vite passes** (`vite build --mode landing`, chained from every
  `build*` npm script). In ONE pass Rollup hoists the shared module into
  `dist/shared/attribution-<hash>.js` and BOTH bundles get a top-level `import`
  — neither then loads as the classic `<script>` `StorefrontBeacon` enqueues
  (verified 2026-08-05; that would silently break browse tracking too). If you
  add a third storefront entry, give it its own pass.
- **The tiny bundle stays tiny** (~1.2 kB): URL params → cookies → strip. No
  transport, no consent surface, no session cookie, no `/relay` URL in its boot
  blob (`window.smailyConnectLanding` carries cookie names / param names / TTLs
  and nothing else) — that minimalism is why it can load consent-independently.

### Browse consent is fail-closed on the WP Consent API — needs the `wp-consent-api` plugin, NOT vendor code (F3-50)
The beacon sends browse events ONLY when `window.wp_has_consent(category) === true`
(`beacon-core.ts` `detectConsent`, category `marketing` via the
`smaily_connect_beacon_consent_category` PHP filter) — else the JS `consentOverride` hatch,
else fail-closed. No signal ⇒ **0 events, no error** — indistinguishable from "feature off".
`window.wp_has_consent` is defined by the free **"WP Consent API" plugin** (`wp-consent-api`),
which CMPs register consent INTO — **CookieYes, Complianz, Real Cookie Banner all support it**
(CookieYes maps its `Advertisement` category → WP Consent API `marketing`). **The MiuMjau
0-events bug (2026-07-03) was the companion plugin simply not being installed** (`typeof
window.wp_has_consent === 'undefined'` live) — NOT a CookieYes incompatibility. **Do NOT write
per-vendor consent code** (3.3.1 shipped a CookieYes cookie-parser on the wrong assumption
"CookieYes can't do the API"; reverted in 3.3.2). The standard covers every compliant CMP; a
bespoke adapter is justified ONLY for a dominant CMP that won't adopt the API — not the long
tail. The fix is a config install (the merchant adds `wp-consent-api`) surfaced by
`NotificationManager::needs_consent_api_notice` (browse on + connected + no `wp_has_consent`
→ a dismissible admin notice pointing to the plugin). When a pilot reports "browse = 0
events", first live-probe `typeof window.wp_has_consent` in the real storefront console
(the server-side live-walk can't see this — browser consent resolution is the documented
uncovered gap); if undefined, the fix is installing `wp-consent-api`, not plugin code. NB:
there is **no** `smaily_connect_beacon_consent` PHP filter (only the JS `consentOverride` +
`smaily_connect_beacon_consent_category`).

### Browse browser-timing is NOT live-walk-covered (manual pilot check)
Browse (3.4) is client-originated telemetry, so unlike catalog/customers/orders
the live-walk (`bin/walk-3.4-browse.cjs`) proves only the server side:
proxy→engine §6 + the abuse filter + the engine accepting all 9 event types.
The **browser MOMENT** a page-view fires — `checkout_start` on the checkout
page, `checkout_complete` on order-received, `product_view` on a product page —
is NOT observable from a server-side proxy walk. Coverage is split:
- engine accepts the types → live-walk (9-types check);
- JS maps `pageType` → event → vitest (`beacon-core.test.ts`);
- PHP picks the `pageType` from WC conditional tags (`StorefrontBeacon::
  page_context`) → only the `other` default is integration-tested. The harness
  is plain `TestCase` (no `WP_UnitTestCase`/`go_to()`), so `is_checkout()` /
  `is_product()` can't be driven to exercise the branches — writing a test that
  faked them would prove nothing. The conditional-tag branching is trivial; the
  real "does it fire on the right page" check is **manual pilot verification**
  (or a future Chromium E2E — not built, YAGNI, low risk). What IS unit-tested
  (PRO-1445), split out on purpose: once on the product page, the product→`sku`
  RESOLUTION doesn't touch any conditional tag — `page_context()` delegates it
  to `StorefrontBeacon::product_context( \WC_Product $product )`, which
  `tests/Unit/Integrations/WooCommerce/StorefrontBeaconTest.php` calls directly
  with a fake `WC_Product` carrying a merchant SKU that looks nothing like the
  canonical key, asserting the result is always `woo-{id}` (SkuResolver,
  PRO-1224) — the exact class of bug PRO-1390 shipped (a browse surface reading
  the merchant SKU instead of the resolver). The untested part stays only "am I
  on a product page", not "what key does that page report".

Do NOT claim the live-walk validates checkout/page-view timing — it validates
the engine contract, not the browser render moment.

### Rec attribution capture is SERVER-SIDE (LandingCapture) — separate from the browse beacon
`Integrations\WooCommerce\LandingCapture` (F3-46) captures the recommendation
attribution params an email rec link carries (`smaily_rec`/`smaily_vt`/`smaily_ctx`,
or `utm_content` guarded by `utm_source=smaily`) into the first-party cookies the
checkout already stamps onto the order — on `template_redirect`, **ungated by the
browse beacon's toggle/consent/ad-block path**. This is the missing piece behind the
pilot's "374 orders / 0 `smaily_rec_id`": the cookie producer used to be JS-only
(`StorefrontBeacon`/`captureUrlParams`), which never ran with browse-tracking off.
Two things that must stay true:
- **It writes the SAME cookies `HookHandler::save_attribution_cookies_to_order()`
  reads** (`smaily_rec_id`/`smaily_rec_uid`/`smaily_rec_ctx`, names+TTLs from the
  engine config — the contract §"Cookie names", NOT the brief's `smre_*`/90d). Do not
  rename to a parallel cookie set; the whole point is zero downstream change.
- **Attribution capture is consent-UNgated (Erkki, F3-46)** — first-party functional
  signal, gated only on `is_connected()` + the `smaily_connect_capture_attribution`
  filter. Browse telemetry (Layer 2) stays consent-gated (StorefrontBeacon). Don't
  fold attribution back behind the browse consent gate.
- **`headers_already_sent()` is a test seam** — PHPUnit's own progress output makes the
  bare `headers_sent()` true mid-suite, so both the unit and integration tests override
  it; never inline `headers_sent()` back or the write-path tests can't run.

The click→land→buy→attribute round-trip (does the cookie set on a real landing, does a
test purchase carry `smaily_rec_id`, does the engine credit it via path-1) is a **manual
pilot check** — like browse timing, the server path is unit+integration-proven but the
browser moment isn't live-walk-coverable.

### Browse events carry `smaily_visitor_token` for cold-start — NOT rec_id/email, NOT attribution (F3-49)
Browse attribution rides ORDER signals, not browse (engine-confirmed 2026-07-03): the
order's `smaily_rec_id` + email-click drive the `direct`/`exact_later`/`indirect_*`
classes; browse would at best give the soft `assisted_view`, which the engine
deprioritized. So `enrich()` (`rec-engine-client.ts`) puts the opaque
`smaily_visitor_token` on each browse event **when the cookie is present** (omit-on-empty,
mirrors `session_id`) — its value is future **cold-start personalization** (the engine
binds the browse row to the customer via the token), NOT attribution. The CLIENT still
NEVER adds `smaily_rec_id` / `smaily_ctx` / `customer_email` to browse events — deliberate
data-minimization enforced CLIENT-side (the omission is `enrich()`'s job) — that discipline
is unchanged. **Since PRO-1486 `customer_email` is ALSO enforced SERVER-side**: it is no
longer in `BeaconEndpoint::EVENT_FIELDS` at all, so a client-supplied value (spoofed
attribution, or probing another contact's opt-out state by guessing emails) is stripped
before forwarding, regardless of whether the JS ever tries to send it — see the PRO-1486
addendum below. `smaily_rec_id`/`smaily_ctx` are still in the whitelist (client-side-only
discipline, same spoofing class, unresolved — flagged as a PRO-1486 follow-up, not yet
fixed). Profiling
opt-out on the token path is **engine-side** (server-enforced): an opted-out contact's
browse event is never bound to a customer; the plugin's email-based `ProfilingConsent` gate
stays the first filter but can't map `visitor_token`→email (engine-issued token). Guest-browse
binds only via `identity/merge` on login (F3-27) — browse-session-only is an accepted v1
limitation. (DECISIONS F3-49.)

**PRO-1389 addendum — the one sanctioned SERVER-side exception.** `BeaconEndpoint::
attach_logged_in_identity()` (called from `handle()`, after the abuse/rate-limit filtering,
before the D6 send) resolves the current visitor server-side via
`resolve_logged_in_email()` — `wp_validate_auth_cookie( '', 'logged_in' )` against the real
`logged_in` auth cookie, **not** a page-embedded REST nonce (WP's cookie-auth REST
middleware only populates the current user with a valid `X-WP-Nonce`, which the beacon
never sends, and a page-embedded nonce breaks under full-page caching — the MiuMjau
reality, PRO-1388) — and attaches `customer_email` (contract §6) to every event in the
batch. This closes the gap `StorefrontBeacon`'s docblock used to call "a later
enhancement": `IdentityHookHandler` only merges identity on `wp_login`, so a customer who
stays logged in browsing forever previously got no identity attached at all. Consent does
NOT weaken: event existence is still gated solely by the JS marketing-consent gate;
injection additionally checks the (a).1 `ProfilingConsent` gate for the resolved email
BEFORE attaching it — an opted-out contact's event is forwarded **unchanged, still
anonymous**, never dropped (contrast with the pre-existing `filter_by_profiling()` gate,
which DROPS an event that already carries an opted-out email — that gate is never
triggered by this feature, since injection never attaches an email for an opted-out
contact in the first place). The email never reaches the JS blob or the `/relay` response —
injection is purely on the outbound engine request. (DECISIONS PRO-1389, addendum to F3-49.)

**PRO-1486 addendum — `customer_email` is now ALSO stripped server-side.** Erkki flagged
(2026-07-21, engine-confirmed via PRO-1490) that a client-supplied `customer_email` on the
`/relay` POST was previously passed straight through by `EVENT_FIELDS` with no check —
spoofable (attach an arbitrary email to anonymous browsing; probe another contact's
profiling opt-out state by guessing emails). `customer_email` is now REMOVED from
`EVENT_FIELDS`; the only surviving source is `attach_logged_in_identity()`, which runs
AFTER `validate_batch()`'s whitelist and assigns the field directly (bypassing the
whitelist). The strip is scoped to the browse-event POST handler only — see the
`EVENT_FIELDS` docblock and the DECISIONS.md PRO-1486 entry for the caveat a future
storefront-recommendations GET proxy (which would legitimately take a `customer_email`
query param) must not inherit blindly. `filter_by_profiling()`'s per-event branch for a
client-supplied email DIFFERING from the server-resolved one is gone (unreachable once the
client-supplied value is stripped) — the method keeps its loop/counter/logging shape as
defense-in-depth against a future customer_email producer that skips its own consent
check, but in the current single-producer graph it can no longer actually drop anything.

### OrderBackfill — which storage path the tests actually cover (HPOS vs legacy)
OrderBackfillJob (3.5.2) reads orders with a direct `WHERE id > cursor` query
against whichever table is active — `wc_orders` (HPOS) or `wp_posts` (legacy) —
detected via `OrderUtil::custom_orders_table_usage_is_enabled()`. The table +
column mapping is a pure method (`OrderBackfillJob::table_spec`).

**The wp-env test env runs WC 10.7 with HPOS ENABLED** (orders in `wc_orders`,
zero in `wp_posts`). So:
- the **HPOS path is INTEGRATION-tested** (RecEngineOrderBackfillTest runs
  against real `wc_orders`);
- the **legacy path is UNIT-tested only** (`OrderBackfillJobTest::table_spec`) —
  it is structurally identical (same WHERE shape, different table/columns) but
  is NOT exercised against real `wp_posts` orders in this env.

The PILOT is WC 6.9.4 → **legacy storage** (HPOS only defaults at WC 8.2+). So
the pilot's actual path is the unit-tested-only one. Low risk (the SQL is the
same shape, table_spec-verified), but if a legacy-storage order-backfill issue
surfaces, reproduce it against a LEGACY WC env — the HPOS-mode wp-env won't show
it. Do NOT assume "integration green" covers the legacy order path.

### Delete orders via wc_get_order()->delete(true) — wp_delete_post is an HPOS no-op (2026-07-07 flake)
Any test/walk/script that creates a WC order MUST clean it up with
`wc_get_order( $id )->delete( true )`. `wp_delete_post( $order_id, true )` does
NOTHING under HPOS (orders live in `wc_orders`, not `wp_posts`) and fails
silently. The scar: the 2026-06-19 F3-43 live-walk leaked ONE `wc-label-printed`
custom-status order this way; a registered-status sweep (`wc_get_orders` +
`wc_get_order_statuses()`) can't see an unregistered custom status, while the
backfill's F3-42 denylist SQL counts it as a sale — so 18 days later every
`RecEngineOrderBackfillTest` count assert came back +1 and looked like a
cross-test flake. Second rule from the same scar: a test asserting exact counts
over the whole orders table sweeps it STATUS-BLIND off the active table
(`OrderBackfillJob::table_spec()` — see `delete_all_orders()` in that test),
never through a registered-status filter. Live-walks share the dev wp-env DB
with the integration suite — a leaked walk order is a delayed test failure.
(LESSONS §2.16.)

### A test firing a real WC hook must pass its full arg tuple — a short do_action() trips OTHER real listeners
PHP 8 raises `ArgumentCountError` when a registered callback declares a
parameter with no default and the hook was fired with too few args — this
is invisible when you only think about YOUR OWN callback (which usually has
defaults), but `do_action()` calls EVERY listener on that hook with the same
args, including unrelated real code still registered (the legacy
`Subscriber_Synchronization::smaily_checkout_subscribe_customer( $order_id,
$posted_data, $order )` on `woocommerce_checkout_order_processed`, WooCommerce
core's own `PointOfSaleEmailHandler::maybe_suppress_email( $enabled, $order )`
on `woocommerce_email_enabled_*`, and 4.0's own `$enabled` filter passing
`( bool, $order, $email_instance )`). Found while integration-testing PRO-1504
Stage 2: `do_action( 'woocommerce_checkout_order_processed', $order_id )`
(1 arg) and `apply_filters( 'woocommerce_email_enabled_customer_processing_
order', true )` (1 arg) both fatal with `ArgumentCountError`, NOT from any
code this session wrote. Fix: fire the hook with the SAME arg count/shape
real WC would use (`do_action( 'woocommerce_checkout_order_processed',
$order_id, array(), $order )`; `apply_filters( 'woocommerce_email_enabled_…',
$enabled, $order, null )`) — check the real firing call site (`grep -n
"do_action( '<hook>'"` in the WC/plugin source) before hand-rolling a
shortened `do_action()`/`apply_filters()` in a test.

### Engine-side automations config is TENANT-scoped — walk residue is visible to real stores
`/api/v1/automations/config` rows live per TENANT, not per store/connection —
every store connected to the same tenant (the sandbox is shared by the dev
wp-env AND real test stores) sees and overwrites the same rows, and PUT never
deletes. The T2.3 walk's leftover `replenish_due` row (`language_mode='single'`,
enabled=false) rendered as a one-dropdown oddity in Erkki's real test store
hours later (→ T2.4 made the display mode store-global). Rules: a walk that
writes engine state must end fail-closed (`enabled=false`, `test_mode=true`)
AND its report must name the residue it leaves; when a store shows a weird
per-trigger inconsistency, first ask what else wrote to that tenant. Related:
the dev wp-env's sandbox connection lives on the DEV site (port 8888 /
`…-cli-1`), the tests site (`…-tests-cli-1`) has its own options — but do NOT
conclude the dev connection is safe from a suite run: the 2026-07-08 (F3-53)
full-suite run overwrote the DEV site's `smly_rec_*` options with fixture
values (`re-fixture.test` base URL, a fixture tenant named "MiuMjau"), and the
2026-07-10 PRO-1224 walk found the connection fixture-dead with no snapshot to
restore from. **Since PRO-1240 this is mechanically guarded:**
`bin/run-integration-tests.sh` (= `composer run test:integration`)
automatically snapshots the dev site's `smly_rec_*` options to
`~/.local/state/smaily-connect/smly_rec_snapshot.json` (mode 600, outside the
repo; a fixture/empty state never overwrites a good snapshot) before the
suite, and after it — even on failure — restores them secret-safely (JSON
piped over STDIN into `docker exec -i … wp eval-file
bin/restore-smly-rec-options.php`, never on a command line) and prints the
restored `tenant_name`, warning loudly on `MiuMjau`/fixture. An intentionally
DISCONNECTED dev site is left alone (no auto-reconnect). **Since PRO-1256 the
guard is a shared library, not wrapper-internal:** `bin/lib-smly-snapshot.sh`
(sourced by the wrapper; also executable — `snapshot`/`restore` subcommands,
always exit 0 so a guard problem never fails the guarded run). Walk scripts
opt in via `require('./lib-smly-snapshot.cjs').guardSmlyRec()` at the top —
snapshot now + restore on process exit, crash included. **The wired example
is `bin/walk-3.1.cjs` — the only existing walk that writes/deletes the
`smly_rec_*` connection options** (it seeds a mock connection and scrubs the
options); the other walks only READ the connection or TRUNCATE the
`smly_rec_event_queue` table (queue rows, not the connection) and need no
guard. **Any future walk that writes `smly_rec_*` options must call
`guardSmlyRec()` before touching them.** After any run that still scrubbed
the connection (hand-rolled phpunit, an unguarded walk):
`bash bin/run-integration-tests.sh --restore-only` restores from the durable
snapshot without running the suite (non-secret output only; exit 3 when no
usable snapshot exists — then a fresh SANDBOX setup token is the fix).
(LESSONS §2.17.)

### Integration baseline is WP 7.0; the pilot stack needs an override to reproduce
Since 2026-06-11 `.wp-env.json` pins `core: WordPress/WordPress#7.0` (Erkki's
call: new work targets 7.0; the earlier WP 6.9.4 baseline was an interim step).
The PILOT still runs the OLD stack — WC 6.9.4, legacy order storage, older WP —
so a pilot bug may NOT reproduce on the default env. To stand up a
pilot-faithful env, drop in a `.wp-env.override.json` (gitignored-by-use,
delete after) like the legacy-WC verification used:

```
{ "core": "WordPress/WordPress#6.9.4", "phpVersion": "8.1",
  "plugins": ["https://downloads.wordpress.org/plugin/woocommerce.6.9.4.zip",
               "https://downloads.wordpress.org/plugin/polylang.latest-stable.zip"] }
```

then `npx @wordpress/env start --update` (NOT `npx wp-env` — that alias only
prints a deprecation notice and exits 0 WITHOUT starting, which silently looks
like success), reset the carried-over HPOS options so `is_hpos()=false`, run
the suite, and restore the default env afterwards (delete the override +
`start --update` again). See the go-live checklist entry in STATUS.md for the
original WC 6.9.4 walk-through.

### Endpoints-map URL placeholder is `{email}`, not `%s` — substitute, don't sprintf
The engine's endpoints-map advertises the GDPR customer URLs (§8/§9/§10) with a
literal `{email}` token: `…/customer/{email}/export`. The email goes in the URL
**path** (rawurlencoded) and the substitution convention is `{email}`. 3.8.0
shipped `sprintf(resolve_url(…), rawurlencode($email))` — a silent no-op on a
`{email}` URL, so the literal `{email}` was sent and the engine 404'd (`No
customer with email '{email}'`). The unit + mock endpoints maps had used `%s`,
mirroring the bug → all gates green; only the LIVE engine used `{email}`, so only
the 3.8.1 live-walk caught it. Use `Client::customer_url()` (`str_replace`), keep
fallback `PATH_CUSTOMER_*_TMPL` constants on `{email}`, and seed mock/unit maps
with `{email}` (the mock 422s on a literal-placeholder email). General rule: a
URL from the endpoints-map carries the engine's placeholder syntax — confirm it
(`{name}` vs `%s`) before picking a substitution function; a 404 echoing an
un-interpolated token is YOUR request, not an engine bug. (LESSONS §2.9.)

### Merging `upstream/main` (sendsmaily) — MERGE, ours wins, and audit the CLEAN hunks
**HISTORY once PR #135 has landed** (PRO-2281): after the merge there is ONE
working repository and no upstream/fork split, so nothing routine merges
`upstream/main` any more — keep this section for reading the merge commits it
produced, and for the CLEAN-hunk hazards below, which generalize to any large
merge.

The upstream PR (#135) folds this rewrite into `sendsmaily/smaily-wordpress-plugin`.
When upstream lands maintenance on the legacy plugin, our PR goes un-mergeable and the
fix is a **merge of `upstream/main` into our `main` — never a rebase, never a
force-push** (Erkki, 2026-08-04). Conflict rule: **our side wins** — the v3 rewrite
replaces the legacy plugin, so files we deleted (the legacy admin UI, `phpcs.xml`)
stay deleted, and the plugin header, `readme.txt` header, `composer.json`/`.lock`,
`.wp-env.json`, `.zipignore` and `languages/*` keep our values.
**The hazard is not the conflicts — it's what merges CLEANLY**, because git never
shows it to you. Two real bites in the 2026-08-04 merge:
- **A moved `namespace`.** Upstream moved `includes/smaily-helper.class.php`'s
  `namespace` to the top of the file; taking "ours" at the ABSPATH-guard conflict
  marker left the file with **two** `namespace` declarations — a PHP fatal no marker
  flagged. After any upstream merge, `php -l` **every** changed PHP file before the
  gates (`for f in $(git diff HEAD --name-only …); do php -l "$f"; done`).
- **An upstream-only config that shadows one of ours.** Upstream's `phpstan.neon` has
  no counterpart here, so it lands clean — and PHPStan prefers `phpstan.neon` over our
  `phpstan.neon.dist`, silently swapping the whole analysis config (their level-5
  legacy scan + stubs we don't install) and breaking `composer run analyze`. Dropped
  deliberately. Check every upstream-only file for the same shadowing before keeping it.
Do keep upstream substance that doesn't collide (their legacy bug/quality fixes,
`release.sh`, the wp-env dev env + `contributing.md`, their changelog entries) — and
if a kept doc references something our tree doesn't have, fix the reference in the same
commit rather than importing a false doc.

---

## How we work (the rhythm)

### Sub-PR rhythm with human checkpoints
Work proceeds in small sub-PRs (e.g. 3.3.0, 3.3.1...). Each one:
1. Plan first — state scope, files, surface edge cases, **report the plan and
   wait for go-ahead before coding.**
2. Code + tests.
3. Gates green: `ci:strict` + integration (`sg docker`) + relevant regression
   (e.g. catalog/customers regression must stay green when shared code changes).
4. Report at the end (results, gate output, anything surfaced) **before** the
   next sub-PR. Push directly to main per project rhythm.

Do NOT batch multiple sub-PRs without a checkpoint. The human participates at
edge cases and strategic decisions, not every line — but the checkpoint between
steps is the safety rail.

### Context audit before building (LESSONS §2.5)
Before starting real code on a new area, do a context audit: `git log`, read the
relevant DECISIONS entries + the fresh contract section + the template code you
are mirroring. This is how the queue silent-loss (3.3.0), the topple-enqueue
(3.3.3), and the guest-flusher question were caught **before** coding rather
than in a live-walk.

### Spec sync is byte-identical (CC-8)
When the engine team changes the contract, sync `docs/RECENGINE_API_CONTRACT.md`
byte-for-byte with the engine repo: replace the file, `git diff` + md5 confirm
identical, commit with a message naming the engine commit + what changed, push.
The embedded header note records the sync. This discipline has caught real
bugs (products->items drift, datetime Z-form, the seam bugs).

**A sync is NOT code-complete.** Syncing the doc byte-for-byte does NOT mean the
plugin code follows. The W2 catalog wrapper rename (`items`->`products`) synced
into the doc but the code kept sending `items`; the mock (still on `items`) hid
it for five syncs until the first catalog live-walk after W2 (N-7.1) caught the
`400`. So after every sync that changes a **wire shape** (wrapper key, a required
field, an enum, a removed field): (1) check the real plugin code follows in the
same pass; (2) move the **mock to the new shape in the same sync** (else it masks
the drift); (3) run that endpoint's live-walk (or one curl) before calling it
done. Don't let a breaking change wait for an unrelated sub-PR to surface it.
(LESSONS §2.7.)

**Staleness is now CI-guarded (PRO-1250, Decision A on PRO-1247).** The
`Contract staleness` workflow (`.github/workflows/contract-staleness.yml` —
deliberately its OWN workflow, since "Lint and test" is pre-existing red) runs
`bin/check-contract-staleness.sh` on push/PR/daily-schedule/dispatch and fails
when `docs/RECENGINE_API_CONTRACT.md` is no longer byte-identical with the
engine repo's (`erkkimarkus/smaily-recommendations`, PRIVATE) main branch.
The daily schedule is the real guard — drift usually arrives with no push on
our side. It needs the repo secret `ENGINE_CONTRACT_READ_TOKEN` (fine-grained
PAT, contents:read on the engine repo); a missing/expired secret fails with a
distinct "CANNOT CHECK" message (exit 2), never masquerading as "CONTRACT COPY
STALE" (exit 1). The script also runs locally: no args falls back to the local
engine checkout (`ENGINE_CHECKOUT`, default `…/smaily.app/re`), or pass a
path/URL. This automates DETECTION only — the sync discipline above
(byte-identical + mock + code follow-through in the same pass, live-walk a
wire-shape change) is unchanged. Per-bump sync issues are RETIRED for this
repo: a red staleness run is the signal to sync.

---

## Things NOT to do (each is a scar)

- **Don't assume a wire shape — live-probe it.** Catalog was coded to `products`;
  the live engine wanted `items`; the mock (built to the same wrong assumption)
  hid it until the 3.2.4 live-walk. For customers we probed the wrapper key
  before locking it (3.3.1) and avoided the repeat. If a contract detail could
  diverge, send one live request before committing to it.
- **Don't repeat the datetime bug** — use IsoDate (above).
- **Don't invent real-world facts** — URLs, prices, versions, support links.
  A fabricated `smaily.com/support` URL shipped once (correct is
  `https://smaily.com/help/`). If you don't know, check or ask; don't construct
  a plausible-looking value.
- **Don't trust mocks for format validation.** Mocks validate loosely; the
  engine's Zod is strict. Every formatted field (wrapper key, datetime, enum) is
  a mock-vs-live divergence risk. Live-walk must cover each formatted field, not
  just happy-path structure. (LESSONS §2.3, §2.4.)
- **Catalog-flusher D6 consolidation (lock RESOLVED in N-7.1).** This was a hard
  lock condition: while the catalog flusher was all-or-nothing it would mark
  engine-rejected products SENT (silent loss). N-7.1 moved it onto the shared
  `AbstractD6Flusher`; the catalog live-walk proves the split against the real
  engine (`flusher_d6_split_lock_proof`: a no-SKU product comes back as a D6
  per-item error and is marked FAILED, the valid one SENT). Keep it on the D6
  base — do not reintroduce an all-or-nothing 2xx success path.
- **Don't trust a sync as code-complete** — see the CC-8 note above. A wire-shape
  change in a sync (wrapper key, required field, enum, removed field) must be
  verified against the plugin code AND the mock in the same pass, then live-walked.
  The W2 `items`->`products` drift hid for five syncs because none of these
  happened. (LESSONS §2.7.)
- **Don't re-add legacy WP-Cron scheduling, and don't call a callback "orphaned"
  while anything can still fire it (F3-53, Prike).** Scheduling is owned by the
  AS `smly_plus_*` recurring actions; `Lifecycle::set_scheduled_actions()` was
  removed because its re-arm (activation + any WooCommerce re-activation) ran
  AFTER WPCronAuditor's one-time clear and resurrected the daily legacy
  `smaily_sync_subscribers` mass-send — the F3-47 language clobber — on a real
  client. The legacy abandoned-cart pass is RETIRED (PRO-1195) with its
  callbacks deregistered for the same reason: a stray surviving WP-Cron event
  must find nothing to fire (it would double-remind against the new pipeline).
  The F3-53 guards live on in the new code — a reader of rows another plugin
  version may have written (the legacy-cart drain, tracker `cart_content`,
  queue payloads) treats them as wire input: shape guards + per-row Throwable
  backstop, terminal-mark, never an eternal retry loop.

---

## Coexistence map (legacy vs new)

- Legacy namespace `Smaily_Connect\*` + new `Smaily\Connect\*` coexist.
- Two independent feature flags / gates:
  - `setup_completed` (email wizard) — switches Smaily contact-sync legacy ->
    new. Coordinates the legacy<->new Smaily-sync path.
  - `is_connected()` (rec-engine, Step 4, optional) — gates rec-engine ingest.
    Independent of the email wizard. Rec-engine ingest fires iff the engine is
    connected, regardless of wizard state.
- These target **different destinations** (Smaily contact-API vs rec-engine), so
  there's no double-sync conflict. The CustomerHookHandler (rec-engine) uses
  `is_connected()`; it enqueues all registered users (A-filter, F3-20), matching
  the email-sync handler's breadth (neither filters by role).
- **Abandoned cart is fully on the new path (PRO-1195).** The legacy
  `Cart`/`Cron` abandoned-cart registrations are removed (classes kept for the
  upstream diff; the legacy table kept — it's the one-time drain source and
  the rollback safety net). Pipeline: `CartHookHandler` (guest carts included)
  → `smly_plus_cart_session` tracker (own scalar JSON shape, never
  `serialize(get_cart())`) → `CartAbandonmentSweeper` on the 15-min
  `smly_plus_abandoned_cart` AS tick (cutoff + the F3-37 backlog guard) →
  `automation.abandoned_cart` rows in the Smaily EventQueue → `CartFlusher` on
  `smly_plus_flush_cart_events` (router-first, legacy `autoresponder_id`
  fallback, F3-44 exchanges). Gated by `setup_completed` + the merchant
  toggle; reads the SAME legacy options (status/cutoff/fields — upgrade
  continuity, zero reconfiguration). The Smaily EventQueue is now drained by
  TWO flushers — event-type scoping (`pending()`'s only/exclude args) keeps
  them off each other's rows; a new Smaily-side event type must pick its
  flusher deliberately. The queue payload's field names are LEGACY TEMPLATE
  PARITY (`is_abandoned_cart`, the prefilled `product_<field>_1..10` matrix,
  `over_10_products`) — merchants' Smaily autoresponder templates depend on
  them, don't "clean them up". One-time drain stamp:
  `smly_plus_cart_legacy_drained` (autoload=false — EnvScrub flushes its
  per-key cache explicitly).

---

## Architecture pattern (every ingest domain follows this)

PayloadBuilder (WC object -> wire shape) -> IngestQueue (generic, carries
event_type + entity_id + payload + event_uuid) -> Flusher (batch flush, D6
errors[] parse, Action Scheduler job) -> HookHandler (WC hooks -> enqueue).
Catalog established it (F3-16); customers mirrored it (F3-19); orders mirrors it
again. The queue is shared and event_type-scoped (each flusher drains only its
own event types — this prevents one flusher consuming another's rows).

D6 contract (F3-18): batch ingest returns `200 {ok, processed, deduplicated,
errors:[{index, <natural_key>?, field, message}]}`. Invariant: `processed +
deduplicated + errors.length == total`. The flusher maps `errors[].index ->
batch_rows[index]` (index-aligned parallel arrays), marks errored rows failed,
the rest sent. CustomerFlusher is the reference implementation.


## Linear discipline (Smaily process bridge)

This repository is engineering truth — STATUS/DECISIONS/docs stay canonical here. Linear is the coordination and visibility layer. Full process: Outline → Processes → "Agent-Driven Development"; compact guide: Linear document "Linear workflow guide for AI agents" (attached to MGMT-5). Three rules for every agent working here:

1. **Anchor before work.** A Linear project must exist before substantive work starts — create it or link to it (minutes, not hours). This repo's project: [Smaily Connect for WooCommerce — v3 rewrite](https://linear.app/smaily/project/smaily-connect-for-woocommerce-v3-rewrite-c766b8f9e27b), initiatives *Smaily E-Commerce native integrations* + *Campaign Intelligence*.

2. **One-way doors interrupt.** Before any irreversible or expensive-to-undo commitment — persistent data schema, public/integration API contracts (e.g. `RECENGINE_API_CONTRACT.md`), releases reaching real users or stores, anything touching deliverability/reputation, pricing, or legal/consent — halt, file a Linear issue in the project with the evidence and proposed action, and wait for Erkki's approval. Reversible work proceeds at full speed without asking.

3. **Scribe pass at session end.** Before finishing a working session, distill it into Linear: post an honest project status update (onTrack/atRisk/offTrack), promote new backlog items to Linear issues, update the project's SDD document if architecture moved, close completed issues. Use the `/linear-project` skill if available, otherwise the Linear MCP tools directly.

Never duplicate repo documents into Linear — summarize and link. Linear content is written in English.
