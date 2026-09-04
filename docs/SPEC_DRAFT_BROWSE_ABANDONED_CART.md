# SPEC DRAFT — Browse-based abandoned cart for guest stores

**Status:** DRAFT for engine-team discussion. Post-pilot (🟡 BACKLOG). Not
scheduled. **Origin:** Erkki, 2026-06-12 (pilot day 1) — the pilot store is
guest-only, so the account-based abandoned-cart path covers ~nobody.

## Problem

The classic abandoned-cart flow keys on a REGISTERED customer (WP user / cart
table row → email). On a guest-only store that's ~zero coverage. But the
plugin + engine already identify many guest visitors anyway: a click from any
Smaily email carries `smaily_vt` (→ 365-day visitor cookie), and an earlier
checkout merges the email. Use that identity to recover carts for guests.

## What already exists (all live-proven on 2026-06-12 walks)

| Signal | Where | Proof |
|---|---|---|
| `cart_add` / `cart_remove` / `checkout_start` / `checkout_complete` browse events | Contract §6, beacon → proxy → engine | browse walk 13/13 |
| Identity resolution: `smaily_visitor_token` → customer; email merge at checkout | Contract §6 identity flow, §7 identity/merge | walk `anon_vs_with_customer_match`, `engine_retroactive_binding` (bound=2) |
| Retroactive session binding (anon history → customer once identified) | Engine-side, same-session UPDATE | live-proven |
| Orders ingest with `customer_email` (auto-create) | Contract §5 | orders walk 12/12 |
| Catalog with stable product keys incl. synthetic `wc-{id}` (F3-36) | Contract §3 | catalog walk 15/15 |

## Proposed mechanism (engine-side)

A scheduled engine job per tenant:

1. Find customers (identity resolved, NOT anonymous) whose latest cart
   activity (`cart_add` minus `cart_remove`) within window W1 (e.g. 1–24h ago)
   has **no subsequent purchase signal**.
2. **Purchase suppression uses ORDERS ingest, not browse:** a `checkout_complete`
   browse event is deliberately NOT linked to an order (existing design
   decision) and the event can simply be missed (beacon blocked, tab closed).
   Suppress when an order with the same `customer_email` exists with
   `ordered_at` ≥ first cart activity. The engine is the ONLY place that has
   both streams — this is the core reason the feature is engine-side.
3. Reconstruct the cart approximately: the set of SKUs from `cart_add` events
   minus `cart_remove`, joined with the catalog for name / price /
   `product_url`. (Browse events carry `sku` only — no qty/price. If qty is
   wanted, an OPTIONAL `qty` field on `cart_add` is a small additive contract
   change; plugin-side cost is trivial.)
4. Trigger the tenant's Smaily automation with the reconstructed cart.

### Hard requirements (non-negotiable, learned the hard way)

- **Consent gate:** trigger ONLY for contacts with active Smaily marketing
  consent. Browse-tracking cookie consent is NOT email-marketing consent; the
  legal basis (existing-subscriber relationship) must be explicitly agreed
  with the Smaily team and documented before this ships. Opt-outs respected.
- **Age window from day one (F3-37 lesson):** never trigger for cart activity
  older than the window — a re-armed/recovered scheduler must not mass-mail
  history. Plus a per-customer rate cap (e.g. max 1 reminder per N days).
- **Second platform for free:** the Shopify app uses the same beacon client
  and contract — design tenant-agnostic, nothing WP-specific in the engine job.

## Honest limitations

- Coverage is PARTIAL: only identified visitors (Smaily-click within cookie
  TTL or prior same-browser checkout). On a guest store, partial ≫ the
  current zero.
- Cart contents are approximate (SKU set, no qty unless added). Acceptable
  for a reminder email.
- Multi-device: the cart lives on one browser; the reminder may reach a
  customer who continued on another device. The order-suppression check (2)
  handles the bought-elsewhere case.

## Open questions — ANSWERED by the engine team (2026-06-12, see ENGINE_TEAM_PILOT_SYNC_RESULTS.md)

The engine team confirmed good fit (existing `cart_abandonment` trigger seam +
`browse_abandonment` playbook seam in the Pet pack). Their answers:

1. **Job shape:** cron sweep (nightly + optional intraday), not event-driven —
   matches the engine's enrichment paradigm and makes the F3-37 age window
   trivially enforceable in one query.
2. **Defaults:** W1 = 2h–24h (under 2h feels like surveillance; over 24h the
   cart is stale); rate cap max 1 reminder / 7 days per customer, engine-side.
3. **Trigger path:** reuse the existing Smaily custom-field trigger-block
   mechanism — engine writes `rec_cart_abandoned=yes` + the reconstructed
   cart into contact fields; the tenant's own Smaily automation sends. No new
   engine-side template engine.
4. **`qty` on `cart_add`:** not needed for v1 — SKU set + catalog join
   suffices for a reminder. So NO plugin/contract change for v1 at all.
5. **Consent:** Smaily contact state at trigger time via the existing
   contact-sync path — Smaily's own automation refuses unsubscribed contacts
   (authoritative gate); the engine additionally never syncs opted-out
   customers. No cached flag.

**Status:** 🟡 post-pilot on BOTH backlogs; nothing blocks; v1 needs zero
plugin-side changes.
