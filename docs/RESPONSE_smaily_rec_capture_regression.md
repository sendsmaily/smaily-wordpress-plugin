# Plugin-team response — WooCommerce `smaily_rec` capture regression

**Date:** 2026-06-30
**From:** WooCommerce plugin team
**Re:** "Plugin brief — WooCommerce `smaily_rec` capture regression" (2026-06-30)

## TL;DR

Root cause **found and fixed** — and it is **not** the leading hypothesis in your
brief. The plugin already reads `smaily_rec` correctly; the failure is that the
attribution cookie was never **stamped onto the order** on **block checkout**.
MiuMjau uses WooCommerce **block checkout**, and the cookie→order-meta stamping
was hooked only on the **classic** checkout action. So the cookie was captured
but the order carried no `_smaily_rec_id` → `smaily_rec_id` absent from every
order payload. Fixed for new orders. Past orders can only be recovered
**engine-side** via the action-log.

## What we verified (not the cause)

1. **The plugin reads `smaily_rec`, not `utm_content`.** `LandingCapture` (shipped
   in v3.1.0 / F3-46) reads URL param **`smaily_rec`** as primary → cookie
   `smaily_rec_id`; `utm_content` is only a fallback guarded by
   `utm_source=smaily`. Chain is wired + tested: `smaily_rec` → cookie
   `smaily_rec_id` → order meta `_smaily_rec_id` → payload `smaily_rec_id`
   (`OrderPayloadBuilder`). So your hypothesis #3 (plugin still keys on
   `utm_content`) is **not** it.
2. **Deployed version = v3.1.0** (confirmed in the admin) — the F3-46 build that
   captures `smaily_rec`.
3. **The engine connection is live.** Admin shows "Connected as MiuMjau · engine
   v1.0.0 · tenant active", and `order.upsert` events flush with HTTP 200 /
   `accepted`. So `is_connected()` is true and order ingest works.
4. **The param survives the redirect** (your point 2). A current MiuMjau rec link
   lands on:
   `…/toode/…/?utm_source=smaily&utm_medium=email&utm_campaign={{campaign_id}}&smaily_rec=34cb9bc3-…&smaily_vt=vt_…`
   — `smaily_rec` (clean UUID) + `smaily_vt` are present and correctly encoded.
   No stripping, no encoding problem.

## Root cause — block-checkout stamping gap

The order-attribution stamping (`save_attribution_cookies_to_order`: read the
`smaily_rec_id` cookie → write order meta `_smaily_rec_id`) was bound **only** to
`woocommerce_checkout_order_processed`, which the **classic** checkout fires.
**Block checkout** (Store API) does **not** fire that action — it fires
`woocommerce_store_api_checkout_order_processed`. So on a block-checkout store the
cookie is captured but **never stamped onto the order**, and the order reaches
the engine with no attribution fields — exactly the `order.upsert` payload you're
seeing (`customer_email`, `items`, totals — no `smaily_rec_id`).

This was a **documented limitation** of F3-46 ("classic checkout only"), now hit
in production because MiuMjau is on block checkout.

**Fix (shipped):** a Store-API twin —
`woocommerce_store_api_checkout_order_processed` →
`save_attribution_cookies_to_order` — so block-checkout orders carry
`_smaily_rec_id` (+ `_smaily_visitor_token`, `_smaily_rec_ctx`,
`_smaily_anon_session_id`) just like classic. **Going forward**, new block-checkout
orders will carry `smaily_rec_id`. **MiuMjau needs the next plugin build deployed**
(the fix is post-v3.1.0).

## Retroactive attribution — engine-side, not plugin

**The plugin cannot backfill `smaily_rec_id` onto past orders.** The rec_id lived
in the customer's ephemeral checkout cookie; for an order that already happened
that cookie is gone, and there is no plugin-side record linking a past order to
the rec the customer clicked. Re-sending past orders (order backfill) would not
add it — the order has no `_smaily_rec_id` meta.

**The engine can, from the action-log (within ~30-day retention):** every rec
click is logged as `action:click` with `email` + `value` = the destination URL
(which carries `smaily_rec=<uuid>`) + `time`. The orders are already in the engine.
So a **click → order match by email + time window** gives retroactive ("assisted")
attribution **without** the order carrying `smaily_rec_id`. That is an engine-side
capability (parse `smaily_rec` out of the click `value`, match to the order),
bounded by the 30-day action-log window.

## Your points, answered

1. **URL capture** — confirmed: reads `smaily_rec` → cookie `smaily_rec_id`. ✅
2. **Redirect survival** — confirmed: `smaily_rec` reaches the landing intact. ✅
3. **Order write** — the gap was here, **block checkout only**. Now fixed for both
   classic + block. ✅
4. **Plugin version** — v3.1.0 (captures `smaily_rec`; the block-stamping fix is
   the next build).

## Acceptance test

After deploying the next build to MiuMjau: click a current rec link → buy through
**block** checkout → within ~15 min `orders.smaily_rec_id` is populated and the
order attributes as `direct`/`assisted` (not `control_purchase/no_match`).

## Side note (campaign config, not blocking)

The landing link's `utm_campaign` arrives as the literal `{{campaign_id}}`
(unsubstituted Smaily template placeholder). Doesn't affect attribution (the
plugin doesn't read `utm_campaign`), but worth fixing campaign-side so analytics
isn't polluted with a literal placeholder.
