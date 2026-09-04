# Prompt — Woo plugin team (paste as-is into the plugin conversation)

Context: engine-side reply to your `ENGINE_TEAM_PILOT_SYNC.md`. Full results in
`docs/ENGINE_TEAM_PILOT_SYNC_RESULTS.md` (copied into your repo's `docs/`).
Engine is at commit `7c202e5`, all fixes deployed to production.

## Corrections to your mental model

1. **There is no separate "pilot tenant".** The engine has exactly two tenants:
   **MiuMjau = the pilot/production tenant** (your backfill is flowing into it)
   and the empty "Smaily Connect test" sandbox. Your 40/40 live-walks ran
   AGAINST the production pilot tenant — the engine found and purged the
   residue (6 walk-* customers, 11 orders, 18 browse events). From now on:
   **all endpoint walks/tests go to the "Smaily Connect test" sandbox** — ask
   Erkki for a setup token from that tenant's Integrations page.
2. The store is not uniformly SKU-less: ~580 catalog rows carry real EAN keys
   alongside 5200 `wc-*` synthetic ones. Harmless, but the F3-36 key-change
   caveat applies to fewer products than "all".

## Engine-side changes you benefit from (already live)

- **Per-connection API keys**: `setup/exchange` no longer rotates the tenant
  key — a new exchange can never kill your stored key again. Keys are listed
  and revocable in the engine admin.
- **Catalog ingest fixes**: intra-batch same-SKU collapse + UTF-16/NUL
  sanitization. The 91% error rate your retries were hitting is gone
  (verified zero errors post-deploy). Anything in your queue that failed with
  HTTP 500 before 14:24 UTC 2026-06-12 will succeed on retry.
- **Automatic tag derivation**: the engine now derives `species`,
  `category_canonical` and `replenishable` from category slugs on every
  upsert. No plugin change needed for that.

## Asks (in priority order)

1. **Send Woo attribute term LABELS, not term IDs.** We receive
   `pa_kaubamargid: ["398"]`, `pa_lemmiklooma-vanus: ["441"]`,
   `pa_vali-kaal: ["206"]`. Contract §3's `raw_attributes` examples are labels
   (`pa_species: ["dog"]`). Until labels arrive, the engine cannot derive
   life_stage (age-based rules), brand (loyalty rules) or pack size
   (bulk-size rules) for this store. Likely fix: resolve term IDs via
   `get_terms`/`wc_get_product_terms` before building the payload.
2. **When the retry queue is empty:** report terminal-state counts
   (sent / terminal-failed / terminal-skipped) + a few `errors[]` response
   samples, so we can reconcile your "~7000 sent" against the engine's stored
   ~2900+. Engine-side expectation: most D6 rejections are legacy orders with
   missing/invalid `customer_email`.
3. **Browse beacon**: Erkki enabled it on the store — confirm events are
   flushing. As of this writing the engine has received zero non-walk browse
   events. Once real traffic flows we will verify `with_customer_match` and
   retroactive binding on our side.
4. **Catalog count comparison**: when backfill completes, compare the store's
   product+variation count against the engine count (admin catalog page now
   shows the live total). Confirm your batch logs show `errors: []`.
5. **Ask the merchant**: is category `live-test-cat` (104 products) a real
   store category or a leftover test category?
6. **Filter non-product post types out of catalog sync.** The store's
   multilingual plugin exposes a language-switcher item as a "product"
   (`wc-49143` · "🇱🇻 Latvian (Latviešu)" · €11.50) and it landed in the
   engine catalog. Engine-side deletion is pointless (next sync re-upserts
   it) — the sync should only enumerate real, purchasable products
   (post_type=product, exclude virtual config artifacts where detectable).

## FYI, no action

- GDPR round-trip ran 6/6 against the live API on the pilot tenant (export →
  delete → 404 verified).
- The browse-based abandoned-cart spec got engine-side answers to all 5 open
  questions (see the results doc) — still post-pilot, nothing blocks.
