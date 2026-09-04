# Edge-case sweep — rec attribution + contact sync

**Date:** 2026-06-30
**Trigger:** the MiuMjau `smaily_rec_id`-empty regression (block-checkout stamping gap) prompted a "what else have we missed?" sweep across every order-creation path and every capture/consent gate. This is the register of what was checked, what's covered, what's a residual risk, and what needs a live check.

---

## 1. Order-creation paths — does rec-attribution stamp onto the order?

The stamping (`HookHandler::save_attribution_cookies_to_order`: cookie → order meta `_smaily_rec_id` etc.) must fire on every path that a *rec-clicking customer* can use to buy.

| Path | Hook | Stamps? | Correct? |
|---|---|---|---|
| **Classic checkout** | `woocommerce_checkout_order_processed` | yes | ✅ |
| **Block checkout** | `woocommerce_store_api_checkout_order_processed` | yes (**fixed `e55514d`**) | ✅ |
| **Express (Apple/Google Pay in blocks)** | same Store-API hook | yes (covered by the block fix) | ✅ |
| **Pay-for-order page** (pay a pending order) | `woocommerce_checkout_order_processed` (classic flow) | yes | ✅ (verify on a block store) |
| **Subscription renewals** (WC Subscriptions) | none (auto-generated, no checkout) | no | ✅ correct — a renewal is not a rec click |
| **Admin-created orders** | none | no | ✅ correct — no rec click |
| **REST / programmatic orders** | none | no | ✅ correct — no checkout cookie |

→ With the block fix, the *checkout* paths a customer can click-then-buy through are covered. The "no stamp" paths are all paths with no rec click, so absence is correct.

## 2. Capture / cookie residual risks (silent-bail class)

These do NOT fail loudly; the new `LandingCapture` WP_DEBUG logging (added `e55514d`+) now surfaces the bail reason.

| # | Risk | Effect | Mitigation |
|---|---|---|---|
| C1 | **`is_connected()` = false** | capture (and order ingest) off | logged ("not connected"); check Settings → Campaign Intelligence |
| C2 | **`headers_already_sent()`** — a theme/plugin prints before `template_redirect` | capture bails | logged ("headers already sent") |
| C3 | **Cookie domain/path mismatch** — WPML **domain-per-language** (e.g. `.ee` vs `.lv`): rec lands on one domain, checkout on another | cookie not sent at checkout → no stamp | **MiuMjau = single-domain → not affected (Erkki, 2026-06-30).** Re-check with the next **multi-domain** client; a domain-per-language store needs a cross-domain cookie strategy |
| C4 | **Consent / cookie-manager plugin wipes first-party cookies** before checkout | no stamp | first-party functional cookie; document; merchant config |
| C5 | **30-day rec_id cookie TTL** — click now, buy >30 days later | expired → no attribution | contract-defined (30d); acceptable |
| C6 | **Smaily redirect strips the query** | no param on landing | **verified NOT happening** (param survives the `trck.smai.ly` redirect); re-check if Smaily changes link wrapping |

## 3. Contact-sync mode (F3-48) edge cases

| # | Case | Status |
|---|---|---|
| M1 | **Guest / checkout-opt-in sync** | **fixed** (F1) — order-path `should_sync_order_email` + classic + block opt-in wiring |
| M2 | **Guest contact was email-only** | **fixed** — enriched with the order's billing first/last name |
| M3 | **Reconcile→meta-hook echo** (re-creates a Smaily-deleted contact) | **fixed** (reentrancy guard, `f8961c7`) |
| M4 | **Classic + block both fire** for one order → dedupe (`order:<id>`) keeps one row | minor; harmless for legit (no is_unsubscribed); the right handler sets consent for the common single-fire case |
| M5 | **Bulk `user_newsletter` change** (CSV import / wp-cli / another plugin) → a consent-sync per row | by-design (any opt-state change propagates); a large import is a burst — note if it bites |
| M6 | **`checkout_optin` register-only user** (registers, never orders) | not synced — correct by design (checkbox is the only source) |
| M7 | **`ContactReconciler::rebaseline()` `list=1` semantics** | unwired + untested; `re/CONTACT_RECONCILIATION_DESIGN` §6.1 says `list=1`=All subscribers — **verify before wiring rebaseline** |

## 4. Action items

- **Done:** block-checkout stamping (`e55514d`), `LandingCapture` bail logging, guest-payload name enrichment, reconcile reentrancy guard, F1 guest/checkout wiring.
- **Manual / pilot checks (not code):**
  - **Live block-checkout acceptance test** — a real rec-link → block-checkout purchase → confirm `orders.smaily_rec_id` populates (the unit test proves the method; only a live block checkout proves the *hook fires*).
  - **C3** — MiuMjau confirmed single-domain (Erkki, 2026-06-30) → fine. Re-check at the next multi-domain client onboarding (domain-per-language needs a cross-domain cookie strategy).
- **Engine-side (cross-team):** retroactive attribution for past orders via the action-log (click `value` carries `smaily_rec`; match to order by email + time, ~30-day window) — the plugin cannot recover a vanished checkout cookie. See `docs/RESPONSE_smaily_rec_capture_regression.md`.
- **Verify-before-wiring:** M7 (`list=1` semantics) before `rebaseline()` is hooked anywhere.

Lesson recorded: LESSONS.md §2.13 (two-code-path WC events; verify pilot config; silent-bail visibility).
