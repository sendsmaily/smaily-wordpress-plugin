# Engine-team question — non-product exclusion: filter vs. signal (2026-06-14)

**Purpose:** close the last open item of the catalog-correctness work (your
brief `PLUGIN_BRIEF_catalog_correctness.md` §P3 / `PROMPT_woo_plugin_team.md`
item 6 — "filter non-product post types out of catalog sync"). Paste into the
engine conversation and decide together.

## Where we are

- **Catalog-correctness CC.1–CC.3 DONE + live-walked** (sandbox, 7/7): canonical
  collapse of WPML/WCML translations to one `wc-{canonical_id}` across catalog +
  orders + browse; `{lang:value}` content (model B), per-language 500-char clamp.
  The engine accepted the `{lang:value}` object form (strict Zod, `processed=1
  errors=[]`).
- The **non-product filter (§P3) is the only piece left**, and we want to push
  back on *where* it should live before building anything.

## Our position: the plugin should NOT hard-filter non-products

We think a plugin-side hard exclusion is the **wrong layer**, for three reasons:

1. **It's a per-client rule.** MiuMjau's donation is categorised `kassitoit`
   (same as real food); its gift cards are a specific gift-card plugin's product
   type. The next store's "non-products" look nothing like these. There is no
   generic rule the plugin can encode.
2. **Heuristics backfire.** A `is_virtual()` / `is_downloadable()` /
   category-name filter would exclude the **real products of a legitimate
   virtual- or downloadable-goods store** (a merchant who sells only digital
   goods). "Is this recommendable?" is a **business-model decision**, not a
   structural one the connector can make safely.
3. **The right layer is the engine.** Your `recommendable` flag (migration 0039)
   is centralized, recomputed on every upsert, self-healing on re-sync, and
   tunable engine-side without redeploying every connector. Erkki confirms it
   already works — the MiuMjau gift cards/donation no longer appear in results.

So our proposed division: **the plugin sends signal; the engine owns the
exclusion decision.** Not the reverse.

## What we think you asked for (please confirm)

Your brief's bonus-ask was to send richer `raw_attributes` (incl. product type)
so the engine can derive `recommendable` robustly instead of name-matching. That
matches the "plugin sends signal" model. If so, we'll add a generic signal — no
exclusion logic in the plugin.

## Questions

1. **Confirm the division:** do you agree the plugin should NOT hard-filter, and
   instead send signal for the engine to decide? (If there's a case where you
   genuinely want the connector to drop a row, give us the **robust per-type
   rule** — product type / plugin meta — we will not ship name/category
   heuristics that harm a virtual-goods store.)

2. **Exactly which signal fields do you want?** Our proposal:
   - `product_type` — the WC type string (`simple` / `variable` / `grouped` /
     `external`, **plus gift-card plugins' custom types** like `pw-gift-card`,
     `gift-card`, `gift_card` — these ARE the reliable gift-card signal).
   - `is_virtual` / `is_downloadable` — WC booleans (so you can distinguish a
     digital-goods store from config artifacts).
   - Placement: a **top-level catalog field** (e.g. `product_type`), or inside
     the existing `raw_attributes` object? (`raw_attributes` currently carries
     custom *attribute* labels only — product type is metadata, not an
     attribute, so a top-level field reads cleaner. Your call — it's your
     storage.) Whatever we pick, we update the contract + mock + a live-walk
     (CC-8) in the same pass.

3. **Urgency:** is the current name-match `recommendable` sufficient for the
   MiuMjau go-live (non-products already excluded), or do you want the signal
   shipped before the SKU-graph wipe + full re-backfill? We'd prefer to ship it
   as a small follow-up (not block go-live), unless you see name-match failing
   for the pilot.

## Resolution (2026-06-14)

Engine team confirmed all three (commit 37a8f66): **plugin sends signal, engine
decides**. Contract §3 already carries `product_type` / `is_virtual` /
`is_downloadable`; migration 0040 + `classifyRecommendable` consume them
(gift-card types → `recommendable=false`; virtual/downloadable never
auto-exclude). Q3: follow-up, not a go-live blocker.

**Plugin side — SHIPPED (CC.4):** `CatalogPayloadBuilder` now always emits the
three top-level fields (`product_type` = `WC_Product::get_type()`, incl.
gift-card plugins' custom types; `is_virtual` / `is_downloadable`). Unit-tested
+ **live-walked 9/9** against the sandbox (`bin/walk-cc3-multilingual.cjs`): the
engine accepts the fields, incl. a `product_type: pw-gift-card` send
(`processed=1 errors=[]`). The signal ships with the canonical re-backfill, so
the post-reload catalog is classified on the first pass.

**Two return-questions answered:**

1. **Language-switcher `wc-49143`** — does CC.1–3 remove it? **No.** CC.2 only
   collapses translation *duplicates* to a canonical row; it never drops a row by
   nature. `wc-49143` carries our synthetic `wc-{id}` key, which means it IS a
   `post_type=product` on the store (that's the only thing our enumeration
   ingests). So:
   - The `product_type` signal will now reveal its actual type. **Please
     classify from that.** If it's a distinguishable artifact type, add it to the
     exclusion set (same as gift-card types).
   - If it's a plain `simple` product (a fake "product" the merchant uses as a
     language link), there is **no structural signal** separating it from a real
     product — we will NOT add a name/category heuristic (it would harm a real
     store). That case stays your name-match + the merchant cleaning up the fake
     product. **To decide:** someone inspect post 49143 in MiuMjau
     (`post_type` + WC product type). If it's a real Polylang/menu post type
     (not `product`), it never reached you via us and is a different path.

2. **MiuMjau's gift-card plugin `product_type` string** — we don't have MiuMjau
   store access from here. Two ways to 100%: (a) Erkki checks MiuMjau (open a
   gift-card product → its type), or (b) the canonical re-backfill ships
   `product_type` and you SEE the actual string in the data, then add it to the
   set (one-pass self-heal). Your current set
   (`gift-card`/`gift_card`/`pw-gift-card`/`wc_gc`/`gift_certificate`) likely
   already covers it; (a) just confirms before the reload.

## Reference

- Plugin position + decision log: `docs/DECISIONS.md` (F3-38), `STATUS.md`
  (catalog-correctness section).
- Contract: `RECENGINE_API_CONTRACT.md` §3 (catalog), engine-internal
  `recommendable`.
- Live-walk: `bin/walk-cc3-multilingual.cjs` (CC.3 + CC.4, 9/9 sandbox).
