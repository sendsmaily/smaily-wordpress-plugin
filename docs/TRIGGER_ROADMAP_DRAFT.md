# Trigger roadmap DRAFT — Woo→Smaily triggers + engine-side sweeps

**Status:** idea register + Erkki's scope decisions (2026-06-12 evening).
BACKLOG links here.

**Decisions (Erkki, 2026-06-12):**
- **A2 Review request: GREENLIT** — separate automation selection, product
  data passed exactly like abandoned cart does (trigger_automation +
  product_N_* fields). Sub-PR planned.
- **A3 Cross/upsell: REVISED after RSS reality-check** — RSS gives every
  recipient the SAME content; per-customer content must ride the trigger
  payload. Woo CAN supply it: `get_cross_sell_ids()` / `get_upsell_ids()`
  (merchant-curated) with `wc_get_related_products()` (category/tag
  heuristic) as fallback, filtered (in stock, not already purchased, cap 4),
  passed as the same `product_N_*` payload fields as abandoned cart.
  **Erkki's field observation (2026-06-12): MANY merchants never configure
  cross-sell/upsell ids** — so the curated path will often be empty and the
  related-products fallback (weaker, category overlap) carries the email.
  Plan accordingly: the v1 email must look good on fallback-only data, and
  the settings UI should show which source the store actually has (e.g.
  "X% of your products have curated cross-sells"). This quality gap is the
  engine block's exact upsell story later.
- **T-series plan agreed for NEXT session (Erkki, 2026-06-12 late):**
  T1 trigger engine + Review instance (~1d; defaults pending: 7d delay /
  completed-only / per-order flag ± per-email cooldown), T3 contact-field
  enrichment (~1d; powers winback shape 2 + VIP), T2 cross/upsell instance
  (+0.5–1d, with the fallback-quality caveat above). Winback shape decision
  (re-trigger-resets-delay vs date-field-offset automation) = Erkki checks
  Smaily capabilities.
- **A4 fields → winback/VIP: YES, logic must be CONFIGURABLE — and it is,
  in Smaily**: plugin ships fields; thresholds/segments/templates live in
  Smaily (ready-made templates being built there). Nothing hardcoded
  plugin-side.
- **A1 confirmation + A5 cancel/refund: OUT OF SCOPE for now** —
  transactional emails belong to Smaily's separate transactional channel,
  which needs its own setup; deliberately not now.
- **A6 payment-failed: parked** (was already).
- **Wishlist / back-in-stock: deferred** — without the engine both need a
  third-party plugin (WC core has no wishlist nor notify-me interest
  capture); with the engine back-in-stock rides browse interest with no
  extra plugin (Family B). Revisit post-pilot via the engine route.
- Family B stays engine-team territory (their calls, their logic).

**Today's baseline (Woo + Smaily, no engine):** welcome series, first-order
trigger, abandoned cart (now F3-37-guarded), subscriber sync + checkout
opt-in + CF7/Elementor forms, birthday field sync, product RSS feed for
templates.

**The strategic frame — two tiers, one email slot:** every trigger below
works WITHOUT the rec-engine (Smaily-only value), and each email has a slot
where the engine's recommendation block upgrades it (static/RSS content →
personalized). That keeps the free tier genuinely useful AND gives every
email a natural engine-upsell story.

---

## Family A — Smaily-side triggers from Woo hooks (NO engine required)

Mechanics already in the plugin: `trigger_automation()` (used by abandoned
cart) + contact-field sync. Action Scheduler gives delayed one-shot actions.

### A1. Post-purchase thank-you / order-confirmation follow-up (Erkki's idea)

Hook: `woocommerce_order_status_processing|completed` → trigger automation
with order payload (items, totals). **Design caution:** WC already sends the
transactional confirmation — do NOT duplicate it. Two honest shapes:
- **Recommended:** a marketing-flavored "thank you" follow-up sent shortly
  AFTER WC's transactional email — cross-sell slot inside (RSS/category
  block without engine; engine block with). Marketing consent applies
  (Smaily refuses unsubscribed — fine).
- Replacing WC's transactional email with a Smaily-sent one is a separate,
  riskier discussion (transactional vs marketing stream separation,
  deliverability) — not v1.

### A2. Review / feedback request (Erkki's idea)

Hook: order completed → AS single action with delay (e.g. +7d) → automation
with purchased items list. Engine-free fully. Refinement: the pilot's
shipping plugin exposes a "Label printed" custom status — delivery-based
timing beats completion-based; pilot-specific, learn then generalize.
Engine upgrade: recommendations appended → review email becomes a seller.

### A3. Cross-/upsell follow-up (Erkki's idea)

Same delayed-trigger mechanics, +N days after purchase. Engine-free content:
the EXISTING RSS product feed scoped by category (buyer of category X gets
feed of X/complementary) — the P6 RSS URL builder already makes these URLs.
Engine version: personalized block. This is the clearest two-tier email.

### A4. ⭐ Contact-field enrichment — the engine-free goldmine

Not a new email — new FIELDS. On order events, recompute and sync per
contact: `last_order_date`, `orders_count`, `total_spent`, `avg_order_value`,
`first_order_date`, `last_purchase_categories`, `used_coupon_codes`.
Smaily's own segmentation then powers: win-back ("last_order_date > 90d"),
VIP segments (total_spent), category campaigns, coupon-responder targeting —
**merchant builds these in Smaily with zero further plugin work.** Highest
value-per-code-line for non-engine customers; also showcases Smaily's
segmentation. Cost: one hook handler + field mapping + a docs page.
(F3-36-style care: define the field set once, document it, version it.)

### A5. Cancellation / refund service-recovery

Hook: status → cancelled/refunded → automation trigger ("sorry" + voucher).
Cheap, engine-free, good retention story.

### A6. Payment-failed recovery (LOW priority, flagged)

`woocommerce_order_status_failed` → "complete your payment". Consent-
sensitive, overlaps abandoned-cart/CartBounty territory; failed orders are
deliberately outside rec-ingest (F3-22) — this would be a separate Smaily
trigger only. Park it.

---

## Family B — engine-side sweeps (recorded for the engine backlog; the engine
team makes its own calls per its own logic — these are OUR notes, not asks)

All need ZERO new plugin hooks (orders/browse/catalog already flow); all use
the custom-field trigger path confirmed in SPEC_DRAFT_BROWSE_ABANDONED_CART;
all inherit the F3-37 rules (age window + rate cap from day one).

| Idea | Signal | Note |
|---|---|---|
| Browse-based abandoned cart | cart_add/remove + identity + orders-suppress | Full spec draft exists; engine answered the open questions; v1 = zero plugin changes |
| Review request (engine flavor) | orders + ordered_at | Family-A2 with recommendations; either side can own timing |
| Replenishment / repeat purchase ⭐ | order intervals + Pet pack | Best vertical fit for the pet pilot (consumables) |
| Win-back | RFM (engine has rfm_recency) | Engine flavor of A4's win-back; engine picks WHAT to offer |
| Back-in-stock | browse interest × catalog in_stock transition | Needs the variation-stock hook fix below |
| Price-drop alert | browse interest × price transition | Needs engine-side price history (their schema call) |
| Global frequency cap | all of the above | One person must not get 3 automations in a week; the cap belongs engine-side (it sees everything) — and Smaily-side for Family A |

---

## Plugin-side fixes/hooks this surfaced (small, concrete)

1. **`woocommerce_variation_set_stock_status` not registered** — found
   2026-06-12: only the parent-product stock hook is wired, so a variation
   selling out does NOT refresh its catalog row's `in_stock` → the engine
   can recommend sold-out variations. ~One add_action + test. Do this
   regardless of any idea above (catalog correctness, and back-in-stock
   depends on it).
2. **P10 candidate — no-email order guard:** orders with empty
   `billing_email` can never pass the engine (`customer_email` required) —
   terminal-skip them instead of send-and-fail (same F3-36 pattern as empty
   items). Confirm first from the pilot Event Log that `field=customer_email`
   dominates the failed rows.

## Cross-cutting rules (apply to EVERY trigger above)

- Consent: Smaily automation refusing unsubscribed contacts is the
  authoritative gate; transactional-stream replacement is out of scope.
- F3-37 from day one: age window + per-customer rate cap on every
  time-based trigger — a re-armed scheduler must never mass-mail history.
- Frequency capping across triggers before the family grows past ~2 emails.
