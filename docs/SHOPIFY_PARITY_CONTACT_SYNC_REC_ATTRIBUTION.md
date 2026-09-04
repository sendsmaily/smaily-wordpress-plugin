# Shopify Connect parity — contact sync + rec attribution (platform-agnostic)

**Date:** 2026-06-30
**Audience:** the Shopify Connect agent (greenfield TS). `connect/` is read-only reference.
**Purpose:** the F3-47 / F3-48 / rec-attribution work shipped in the WooCommerce plugin v3.2.0 is **mostly platform-agnostic** — the *concepts* and the *Smaily-side contract* are identical across Woo and Shopify; only the **store-side glue** (hooks, customer storage, checkout) differs. This doc separates the two so Shopify can build parity without re-deriving the design.

Authoritative companions (read these too): `docs/CONTACT_SYNC_MODES.md`, `docs/CONSENT_STRATEGY_COMPARISON.md`, `docs/DATA_MODEL_GDPR.md`, `docs/EDGE_CASES_REC_ATTRIBUTION_CONTACT_SYNC.md`, `re/docs/smaily-api/`.

---

## 1. Contact-sync modes (the consent model) — PLATFORM-AGNOSTIC

A small set of **named presets keyed to lawful basis**, NOT a toggle matrix. Default is the consent-safe one. The store picks one in setup/settings.

| Preset | Audience | Send `is_unsubscribed`? | Smaily→store reconcile | Automation `force_opt_in` |
|---|---|---|---|---|
| **All customers (legitimate interest)** | every customer (+ optional guests) | never (Smaily owns suppression) | no | `false` |
| **Subscribers only (consent)** — DEFAULT | only opted-in | opt-in → `0`, opt-out → `1` | yes, both directions | `false` |
| **Checkout opt-in only** | only the checkout opt-in checkbox (guests + accounts) | `0` on opt-in | no | `false` |

**Rules that are platform-independent:**
- **Default = consent** — lawful-safe; never silently broaden audience on upgrade.
- **`is_unsubscribed` only on an explicit opt-state transition**, never on a routine data sync — so a profile/data refresh can't resurrect a Smaily unsubscribe between reconciles.
- **`include_guests`** — a checkbox, default off (guests synced only when on; intrinsic to checkout-opt-in).
- **Automation `force_opt_in` is always `false`** — an automation trigger enrols a contact Smaily has never seen, but must never re-subscribe one who opted out (GDPR Art. 21: honour unsubscribes). The Woo plugin briefly offered a per-store override toggle under legitimate interest; it was retired in PRO-1716 — don't build one.
- **One audience source of truth** (`ContactAudience` in Woo) consumed by *both* live-sync and the bulk backfill, so they never disagree.

**Woo glue → Shopify equivalent:**
| Concern | WooCommerce | Shopify |
|---|---|---|
| Opt-in record | `user_newsletter` user-meta (checkbox writes it) | customer `emailMarketingConsent` / a metafield |
| Live sync trigger | `user_register` / `profile_update` / `woocommerce_save_account_details` | `customers/create` + `customers/update` webhooks |
| Opt-in/out transition | `update_user_meta`/`add_user_meta` on `user_newsletter` (read OLD value before write) | diff `emailMarketingConsent` on the customer-update webhook |
| Checkout opt-in (guest + account) | classic `woocommerce_checkout_order_processed` ($posted_data) + block Store-API | checkout UI extension / `orders/create` webhook + the marketing-consent field |
| Bulk backfill | `BackfillJob` walking the user table, audience-filtered, freshness-marked | a paginated customer query + the same audience filter |

## 2. Bidirectional reconcile (Smaily ↔ store) — PLATFORM-AGNOSTIC, delta-first

Consent mode keeps the store's opt-in flag a faithful mirror of Smaily. **Smaily has no webhooks → poll.** Two mechanisms (same as `ContactReconciler`):

- **Standing reconcile = action-log deltas.** `GET /api/history.php?since_seq_id=<cursor>&actions[]=optin&actions[]=optout&actions[]=delete&actions[]=complaint`. Track the max `seq_id` as a durable cursor. O(changes), light. `optin`→subscribed, everything else→unsubscribed. Map by email to the store customer; write only on change.
- **Occasional re-baseline = full list.** `GET /api/contact.php?list=1&fields=email,is_unsubscribed` (paged, `offset`/`limit`). Onboarding / manual / a poller dormant past the ~30-day action-log retention. **Verify `list=1` semantics first** (it = "All subscribers" per `re/CONTACT_RECONCILIATION_DESIGN` §6.1; the API reference also describes `list` as a segment id — confirm before relying on it).
- **Loopback suppression (critical):** the reconcile's own store-side write (Smaily→store) must NOT echo back to Smaily (a mirrored `delete` would re-create the deleted contact — a GDPR-erasure violation). Woo uses a reentrancy guard (`ContactReconciler::is_applying()`); Shopify needs the equivalent flag/skip around the write.
- **Marketing only** — never touch *profiling* consent here (a marketing unsubscribe ≠ an Art-21 profiling objection; see §5).

## 3. Contact language — PLATFORM-AGNOSTIC, context-independent

Resolve the Smaily `language` from a **context-independent** source (works in cron/jobs, not just a page request — the Woo bug that motivated this used the page locale and clobbered everyone to the site default in cron). Priority:
1. the customer's stored preferred language,
2. the most-recent order's language,
3. the store's configured default language (the multilingual plugin's default, NOT the platform admin locale),
4. the platform locale short code.
Normalize to the short code (`en_US`→`en`). **Omit when unresolved** (Smaily: absent = keep existing, empty = wipe). Make it filter/override-able. Clamp to the store's *active* languages so dirty history can't emit a language the store doesn't have.

## 4. Rec-attribution capture — PLATFORM-AGNOSTIC flow, platform-specific carriers

The click→purchase signal: a recommendation email link lands on the store carrying **`smaily_rec=<uuid>`** (+ `smaily_vt` visitor token, `smaily_ctx`). Capture → carry to the order → send to the engine.

- **URL param → first-party cookie** on landing: `smaily_rec` → cookie (e.g. `smaily_rec_id`, 30-day), server-side, **ungated by browse/marketing consent** (first-party functional signal: a rec uuid + opaque token). `utm_content` is only a fallback guarded by `utm_source=smaily`.
- **Cookie → order**: at checkout, copy the cookies into order attributes/metafields; the engine reads them from the order payload (`smaily_rec_id`, `smaily_visitor_token`, `smaily_rec_ctx`).
- **THE LESSON (cost a production regression):** **cover EVERY checkout path.** Woo's classic vs **block (Store API)** checkout fire different hooks; the stamping was classic-only, so a block-checkout store captured the cookie but never stamped the order → empty attribution. Shopify's analogue: the standard checkout vs checkout extensions vs draft/express — make sure the order-attribution step fires on **all** order-creation paths the customer can click-then-buy through. **Verify the pilot store's actual checkout type before scoping.**
- **Retroactive:** past orders can't be store-side backfilled (the cookie is gone). Retroactive attribution is **engine-side** via the action log (the `click` action's `value` carries `smaily_rec`; match to an order by email + time, ~30-day window).
- **Observability:** log the capture bail reason (not-connected / headers-already-sent / no-valid-param / captured). A silently-bailing capture is a monitoring gap, not just a code gap.

## 5. Consent layers — keep marketing and profiling separate (BOTH platforms)

Smaily owns consent, granularly: **marketing consent** (governs *sending*; the contact-sync layer) is a SEPARATE parameter from **profiling consent** (`smaily_rec_profiling`; governs the rec engine). A marketing unsubscribe is **not** a profiling objection and vice-versa. Neither layer's sync may leak across. (Full model: `docs/DATA_MODEL_GDPR.md`, `docs/CONSENT_STRATEGY_COMPARISON.md`.)

## 6. Smaily API wire shapes — IDENTICAL across platforms (validate against live Smaily)

These are the Smaily endpoints the above uses — same for Woo and Shopify. **Don't trust a mock for format; validate each against the real Smaily API** (the recurring mock-vs-live scar):
- **Contact upsert** `POST /api/contact.php` — flat addresses: `email`, `language` (short code), `is_unsubscribed` (0/1), custom fields. Absent field = keep; empty = wipe.
- **Action log** `GET /api/history.php` — `since_seq_id` cursor (gap-free, resumable), `limit`≤10000, `actions[]` filter; rows `{seq_id, email, action, time, value}`; `click.value` = the destination URL (carries `smaily_rec`). Pull-only (no webhooks).
- **Contact list** `GET /api/contact.php?list=1&fields=email,is_unsubscribed` — offset/limit pages (≤25000).
- **Automation trigger** `POST /api/autoresponder.php` — `force_opt_in` (bool; `true` = re-subscribe on trigger, `false` = honour consent — always send `false`). *Undocumented param — now in `re/docs/smaily-api/reference/automations.md`.*

## 7. Lessons worth carrying to Shopify
- Named presets > toggle matrix for consent (maps to lawful basis; no incoherent combos).
- Default to the consent-safe behaviour; broad audience is a deliberate, warned opt-in.
- Two code paths for one outcome (classic/block checkout, etc.) → cover both unless you've VERIFIED only one is in use; verify the pilot's config.
- A silently-bailing capture/sync needs bail-reason logging — silent drops are invisible to every dashboard.
- Live-walk every formatted Smaily field; the mock validates loosely, the live Zod is strict.
