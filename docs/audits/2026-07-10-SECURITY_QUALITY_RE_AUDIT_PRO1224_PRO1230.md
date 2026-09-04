# Security + code-quality re-audit — PRO-1224 / PRO-1230 / docs-links delta

- **Date:** 2026-07-10
- **Baseline:** delta `ea5bce0..fc7b577` (v3.5.0 → pre-3.6.0 main; commits `b12863e`…`fc7b577`)
- **Auditor:** Claude (Fable 5, clean-context agent — no involvement in writing the delta)
- **Trigger (re-audit policy):** the delta changes a REST route's behavior (`EventsEndpoint` retry-kick hook list), adds a new outbound HTTP call (`Client::catalog_remove()`), adds a new WP-hook-driven enqueue surface (`before_delete_post`), and totals ~3,400 changed lines — both the "security-sensitive surface" and size triggers fire.
- **Scope:** full diff of the delta + current versions of every new/heavily-changed file, checked against contract v1.3.0 §3/§3b and DECISIONS PRO-1224/PRO-1230; both security and code-quality dimensions. Read-only pass.

## Verdict

**0 Critical / 0 High / 0 Medium / 1 Low / 4 Info.** No blocker for the 3.6.0 release.

## Security — all surfaces clean

1. **EventsEndpoint retry-kick** (`includes/REST/EventsEndpoint.php`): the `/events/retry` route keeps `permission_check` (`current_user_can( manage_options )`) + REST cookie-nonce. `rec_flush_hooks()` is a fixed whitelist of class constants; the delta only adds the `CatalogRemoveFlusher::FLUSH_HOOK`/`AS_GROUP` pair. `as_enqueue_async_action` is called with an empty fixed args array — no request parameter can reach a hook name. No path for a lower-privileged or unauthenticated caller to schedule arbitrary hooks.
2. **`Client::catalog_remove()`** (`includes/Smaily/RecEngine/Client.php`): URL via the standard `resolve_url( 'ingest_catalog_remove', PATH_INGEST_CATALOG_REMOVE )` endpoints-map-else-constant mechanism; map + base_url come from the admin-gated setup exchange — SSRF surface unchanged. Authorization header exists only transiently in `request_url()` args; the RecEngine namespace has no `last_exchange` capture, and rec-flusher stored exchanges are built from the wire object + selected response fields — the header can never reach `sent_payload`/`last_response`/logs (F3-44 rule holds). Body is `{ product_ids: [...] }` — numeric-string platform ids, no PII/secrets.
3. **`CatalogRemoveFlusher`**: zero SQL of its own — all DB access via `IngestQueue` (`%s`-placeholder IN-lists, format-array `$wpdb->update`, `prepare()`d attempts). Event-type scoping drains only `catalog.remove` (unit-pinned); `IngestFlusher` still drains only `catalog.upsert`/`catalog.delete` — no cross-consumption/starvation. Stored exchanges go through the inherited 10 KB `trim_json`/`trim_text` (visibility widened private→protected for exactly this reuse); content is id + outcome fields, secret-free.
4. **`CatalogHookHandler::on_hard_delete_product`** (`before_delete_post` fires for all post types): post-type routed — `product_variation` → soft path, non-`product` → return (unit-tested); `auto-draft` skipped; `gate_open()` (`is_connected()`) first. The only payload value is `SkuResolver::product_group_id()` — an integer post id cast to string — enqueued via the fully-prepared `IngestQueue::enqueue()`. Deletion authority was already checked by WP before the hook fires.
5. **SkuResolver / payload builders (PRO-1224)** — net data-minimization, verified: `grep -rn "get_sku"` across `includes/`, `public/`, `admin/` returns **zero hits**; the merchant SKU appears on no wire surface. `catalog.sku` = `woo-<canonical_id>`; `external_id` = raw platform id; `tags.product_id` = raw integer-string; order items `woo-<id>`/`woo-oi-<item_id>`; beacon sku via the same resolver. Every emitted key is an int cast to string with a constant prefix — nothing injectable, no new PII.
6. **Docs links**: all three echo sites (`admin/wizard.php`, `smaily.class.php` action-links + row-meta) use `esc_url( Constants::docs_url() )` + `esc_html__`; `target="_blank"` carries `rel="noopener noreferrer"`; a filter-supplied `javascript:` URL is neutralized by `esc_url` at output.
7. **`docs/site/index.html`** (static, not shipped in the ZIP): no `innerHTML`/`document.write`/`eval`/URL-param reads; localStorage values feed only `setAttribute('data-lang'|'lang'|'data-theme', …)` — not an injection sink.

## Code quality — contract + DECISIONS conformance clean

8. **Contract §3b**: wrapper `{ product_ids: [...] }` of RAW un-prefixed parent ids; batch ≤100 (cap 1000); in-wrapper dedupe; empty batch impossible (keyless rows terminal-skip before `send()`). Response handling is §3b-native, not D6: 2xx → all rows SENT; `not_found` = per-row success, never retried (unit-pinned); terminal-4xx/transient-5xx inherited and tested.
9. **DECISIONS PRO-1230 routing** — all five rules implemented and tested: single-variation delete keeps the per-SKU soft path; trash keeps F3-40 `in_stock=false` (`wp_trash_post → on_delete_product` unchanged); auto-draft GC skipped; per-variation soft rows pre-claimed into the one remove; translation-of-surviving-canonical re-syncs the canonical (P4). Integration E2E covers trash-vs-purge, variation delete, variable-parent family remove.
10. **`AbstractD6Flusher::apply_response()` seam**: pure extract-method — the base implementation delegates unchanged to the private D6 path; catalog/customer/order flushers don't override it, so their D6 split/invariant/exchange behavior is byte-identical. No regression risk.
11. **Test coverage**: `CatalogRemoveFlusherTest` (7 tests), `CatalogHookHandlerTest` (+9 hard-delete routing tests), `RecEngineCatalogTest` (+4 E2E vs the mock's exact-string §3b route, moved in the same sync per CC-8), plus the live-walk `bin/walk-pro1224-1230.cjs` (LIVE OK, 20 checks, 2026-07-10) — the mock-vs-live rule was followed.

## Findings

| # | Severity | Finding | Disposition |
|---|---|---|---|
| 1 | **Low** | Cross-flusher tombstone/soft-delete ordering race: the dedupe pre-claim in `CatalogHookHandler::enqueue_remove()` is per-request static state. If a variation's `catalog.delete` row is enqueued in a *different* request than the parent's remove (e.g. WP-CLI deleting variations then the parent), the `in_stock=false` upsert can drain via `IngestFlusher` on an independent tick and land AFTER the §3b tombstone. Row stays `in_stock=false`, so practical exposure is low; periodic full re-sync reconciles. | **Open (accepted for 3.6.0).** Follow-up: confirm with the engine team that a plain upsert does not clear a tombstone's `recommendable=false`; only if it does, cancel pending matching `catalog.delete` rows at remove-enqueue time. |
| 2 | Info | Older-engine 404 on `/ingest/catalog/remove` is a terminal 4xx → rows FAILED, though the contract says "treat absence as not yet available". Moot against the current deployed engine (route live-walked OK). | Accepted; special-case only if plugin/engine version skew becomes real. |
| 3 | Info | Dead guard in `enqueue_remove()` (`$group_id === ''` can never fire — `product_group_id()` falls back to the input id). Harmless defensive noise; the meaningful guard lives in `row_to_object()`. | Accepted. |
| 4 | Info | A hard-deleted never-published `draft`/`pending` product produces a `not_found` round-trip. Explicitly accepted in DECISIONS PRO-1230 (idempotent; `not_found` is contract-success). | Accepted. |
| 5 | Info | A remove row's `sent_payload` stores the per-row `{product_id}` object, not the exact POSTed `{product_ids:[…]}` wrapper — same per-row convention as the D6 flushers; the outcome JSON disambiguates. | Accepted (consistent with existing behavior). |

## Release-gate companion (3.6.0)

PCP against the BUILT ZIP (build-hash `f8903ce`, v3.6.0, run in wp-env cli container with `--slug=smaily-connect --exclude-directories=vendor`): **clean except the single intentional `plugin_updater_detected`** (`Update URI` clobber-guard, F3-35 — removed at the upstream merge). One PCP finding surfaced and fixed during the gate: the 3.6.0 upgrade notice exceeded PCP's 300-char limit → shortened to 292 chars (`readme.txt`). ZIP verified: required contents present (dist/admin, sc-runtime.js, 3 block builds, vendor/autoload.php, .mo + admin-bundle i18n JSON, composer.json), dev artifacts absent (tests/docs/node_modules/admin-src/dist-client/dev-vendor), ~1.07 MB. `ci:strict` exit=0 (PHPUnit unit 500, vitest 236, PHPCS/PHPStan/tsc/eslint clean).
