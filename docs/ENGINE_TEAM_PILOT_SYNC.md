# Engine-team sync brief — pilot go-live (2026-06-12)

**Purpose:** the plugin side is deployed/deploying on the pilot store and real
data is starting to flow. This is the joint final-sync checklist — paste this
into the engine conversation and walk it together.

## Plugin-side state (what you are receiving from)

- Plugin **2.1.0-beta.2** (release v2.1.0-beta.2-rc.2, build `8d75a8d`).
- Pilot store: WC 6.9.4, **legacy order storage**, **guest-only** (no customer
  accounts), **NO SKUs on any product**, older orders reference deleted
  products. Tenant: the pilot tenant (Erkki has the id; the dev tenant
  MiuMjau is separate — today's 40/40 live-walks ran against MiuMjau).
- Contract copy on plugin side: v1.0.0. **Action: confirm byte-sync** (md5 of
  `docs/RECENGINE_API_CONTRACT.md` in both repos must match — CC-8).

## What changed plugin-side TODAY that you will SEE in the data

1. **Synthetic product keys (F3-36).** The pilot store has no SKUs, so EVERY
   product key arriving from this tenant is the synthetic form `wc-{id}`
   (e.g. `wc-1234` — opaque string, ≤64 chars, stable per product). Catalog,
   `items[].sku` in orders, AND browse `sku` all use the same key. If any
   engine-side analytics/validation assumes human-style SKU formats, flag it
   now. Caveat to know: if the merchant later assigns real SKUs, the key for
   that product CHANGES (fresh catalog row; history splits — accepted
   trade-off, plugin DECISIONS F3-36).
2. **Catalog backfill re-run incoming.** Until today the plugin silently
   skipped SKU-less products, so this tenant's catalog is near-EMPTY on your
   side. After the re-run you should see catalog rows ≈ the store's product
   count. That jump is expected, not a bug.
3. **Orders retry flood incoming.** The store's ~50 D6-failed `order.upsert`s
   (`items: Array must contain at least 1`) will be retried after upgrade.
   Orders whose every product was deleted are now terminal-skipped
   plugin-side and will simply NEVER arrive — do not wait for them.
4. **Browse events now always carry `sku`** on product/cart events (was
   omitted for SKU-less products → your Zod rejected them).
5. **Customer base on this tenant builds from ORDERS** (guest store —
   customer.upserts are only the handful of registered wp users). W4 email
   auto-create is the path; expect customers ≈ distinct billing emails.

## Joint verification checklist (run together, in order)

1. Contract md5 match (both repos).
2. After plugin upgrade + catalog backfill: engine catalog count for the
   tenant == store product count (incl. variations as units). Zero D6 errors
   on the backfill batches.
3. After "Retry all failed": failed order rows on plugin side reach terminal
   states; engine `ingest_event_log` shows the orders processed; spot-check
   one order's `items[].sku` joins to a catalog row.
4. Browse: events arriving with `sku`; `with_customer_match` /
   `retroactive_bound` > 0 once real Smaily-email clicks happen.
5. Engine-side error/log sweep over the deploy window
   (engine repo `PILOT_MONITORING.md` queries).
6. GDPR round-trip once: one export + one anonymize against a test email on
   the pilot tenant (plugin §8/§9 paths are live-walked, but not yet on THIS
   tenant).

## Heads-ups (no engine action needed)

- The day-1 mass-email incident on the pilot was a third-party cart plugin
  (CartBounty Pro) draining its backlog when the plugin swap revived the
  site's dead WP-Cron — nothing engine-related; recorded as plugin F3-37.
- A post-pilot feature idea is coming your way separately:
  `SPEC_DRAFT_BROWSE_ABANDONED_CART.md` (engine-side abandoned-cart from
  browse signals). Read when convenient; nothing blocks on it.
