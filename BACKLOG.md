# BACKLOG.md — consolidated deferred-work index (Smaily Connect)

**Internal doc.** This repo stays private (the pilot ships via a built ZIP; the
public face is an upstream-merge into the main plugin, not this Git history). So
this backlog is internal working knowledge, like STATUS / CLAUDE / DECISIONS.

## What this file is for

One place to see **everything deferred** — so a pilot go/no-go can tell
*conscious deferral* from *forgotten*, and post-pilot priority is a single list
instead of five STATUS sub-sections + scattered DECISIONS notes.

**Division of labour (keep it this way):**
- **STATUS.md** — "where we are now" (current state, what just shipped).
- **BACKLOG.md** (this file) — "what's deferred + why + priority" (the index).
- **DECISIONS.md** — the *why* / rationale. This file **links** there, it
  does not duplicate the reasoning.

Priority legend: 🔴 pilot-need (before go-live) · 🟡 post-pilot · 🟢 nice-to-have
· 🔵 manual pilot-verification (not deferred code — execution tasks).

_Last updated: 2026-06-09 (initial consolidation: gathered STATUS §Post-pilot /
§Known-deferred / §Future-backlog / (a)-TODO + DECISIONS inline defer-notes +
WP7_COMPAT Phase-4 + three audit-only gaps that were tracked nowhere)._

---

## 🔴 Pilot-need — before go-live (mostly NOT plugin feature code)

| What | Why deferred | Doc location |
|---|---|---|
| **Privacy-policy must mention profiling** | Legal — the opt-out/default-on model requires a transparent disclosure. Erkki / docs. | STATUS L407/L422, DECISIONS F3-31 (L1643) |
| **(a) fail-open GDPR-window review** | Conscious consent-risk in the read-error window (fail-open + opt-out model). Erkki investigating separately before any opt-in flip. | STATUS L423, DECISIONS L1637 |

> **Doc-drift fix — RESOLVED 2026-06-11 in full** (upstream-merge prep sub-PR:
> README feature-complete + Event-Log/profiling/janitor rows; readme.txt 2.0
> rewrite (F4); INSTALL.md profiling section (F5); plus CHANGELOG.md created,
> DECISIONS_DRAFT finalized as docs/DECISIONS.md, .pot/et.po regenerated with
> 39 new-string translations, phase-4 cron-interval TODO decided: keep).

> **WP 7.0 env-matrix — RESOLVED 2026-06-11** (baseline moved to WP 7.0 per
> Erkki — the 6.9.4 baseline was interim; suite 99/99 on WP 7.0; runner memory
> 512M; pilot-repro override recipe in CLAUDE.md). No longer a backlog item.

> **Queue janitor + created_at index — RESOLVED 2026-06-11** (pulled forward
> pre-pilot per FABLE_AUDIT rec 9; fix F6, DECISIONS F3-33: daily AS tick prunes
> terminal rows — sent 30d / failed 90d, filterable, pending never — in
> LIMIT-batches; migration 006 adds `idx_created_at` to both queues).

> **GCM encryption — RESOLVED 2026-06-11** (FABLE_AUDIT fix F3, DECISIONS F3-32:
> Cypher v2 `smy2:` AES-256-GCM + legacy-read fallback + Activation upgrade
> re-encryption of all stored secrets). No longer a backlog item.

> **TESTING.md pilot-acceptance plan — RESOLVED** (Erkki supplied the business
> logistics: duration, real-data, go/no-go). No longer a backlog item.

---

## 🟡 Post-pilot — deferred plugin work

| What | Why deferred | Doc location |
|---|---|---|
| **3.10.3 email channel** (`wp_mail`) | Admin-notice base already covers proactive-in-wp-admin; email needs working server SMTP (recommend an SMTP plugin in the doc). | STATUS L362, DECISIONS L1606 |
| ~~**Abandoned-cart rewrite onto the new namespaced pipeline**~~ | **DONE 2026-07-11 (PRO-1195)** — rewritten onto the namespaced pipeline: `smly_plus_cart_session` tracker (scalar JSON, never `serialize(get_cart())`), guest carts via checkout-entered email, Event Log observability (F3-44 exchanges), one-time legacy drain on upgrade, legacy pass retired (table kept). No longer a backlog item. | DECISIONS PRO-1195, STATUS 2026-07-11 entry |
| **(a) explicit opt-in if AKI tightens** | Conditional — `ProfilingConsent::is_allowed()` is invertible; flip only if the regulator requires opt-in. | STATUS L406, DECISIONS L1643 |
| **(a) drop-count UI surface** (`smly_profiling_dropped_24h`) | Counter is wired; surfacing it in the UI is a refinement, not built. | STATUS L386, DECISIONS L1682 |
| **Auto-retry of transient failures** | Conscious choice: Retry is **manual-only** — auto-retrying a deterministic 4xx loops. Revisit only if transient-vs-permanent classification is added. | STATUS L339, DECISIONS L1601 |
| **`orders_count` scale** | Large-order-volume behaviour of the HPOS order-count / pagination path not load-checked. _(Was tracked nowhere before this file — audit-only gap.)_ | Audit-only — needs a home; verify under a high-order-count env post-pilot |
| **Trigger roadmap — Woo→Smaily triggers + engine sweeps** ⭐ | Erkki + agent ideation 2026-06-12: Family A (engine-free Smaily triggers: post-purchase follow-up, delayed review ask, cross/upsell with RSS content, ⭐ contact-field enrichment — last_order_date/orders_count/total_spent/categories → Smaily segmentation does win-back/VIP free, cancellation recovery) + Family B (engine-side sweeps: replenishment ⭐ for the pet vertical, win-back, back-in-stock, price-drop, global frequency cap). Two-tier story: every email works engine-free, engine block upgrades it. Plus 2 concrete plugin items: variation-stock hook gap (do regardless) + P10 no-email order guard (confirm from Event Log first). | `docs/TRIGGER_ROADMAP_DRAFT.md` |
| **Browse-based abandoned cart for guest stores** ⭐ | Erkki's idea (2026-06-12, pilot day 1): the legacy abandoned-cart only covers REGISTERED users — ~zero coverage on a guest-only store like the pilot. The beacon already gives the engine `cart_add`/`cart_remove`/`checkout_start` + identity (Smaily-click `smaily_vt` → 365d cookie, retroactive session binding — live-proven). Engine-side detection ("identified visitor, cart activity, no order in window") → Smaily automation trigger. Engine-side because: order-suppress is only reliable there (browse `checkout_complete` ≠ order by design), and the feature transfers free to the Shopify app. Needs: engine-team spec, Smaily-consent gate, F3-37-style age window from day one; plugin side ≈ optional `qty` on `cart_add`. Coverage is partial (identified visitors only) — but partial ≫ zero. | `docs/SPEC_DRAFT_BROWSE_ABANDONED_CART.md` (hand to the engine conversation); DECISIONS F3-37 (the backlog-guard lesson it must inherit) |

### Phase-4 strategic (preserved plan — do NOT drop)

| What | Status | Doc location |
|---|---|---|
| **WP 7.0 AI-catalog registration** | **Planned, NOT built.** Smaily Connect offers AI-based recommendations, so it should register into WP 7.0 "Armstrong"'s AI infrastructure. Three opportunities, all experimental in WP 7 → wait for stable contracts: **(1)** Smaily + rec-engine as **Connectors** (centralised credential storage); **(2) Abilities API ⭐** — register typed actions AI agents can discover (the marketing win: first Estonian/Nordic email platform with Abilities support); **(3) MCP Adapter** — auto-exposes the Abilities to MCP tools. P5 touched only version-floors, so no AI-feature code exists yet to preserve. | `docs/WP7_COMPAT.md` §Strategic-opportunity (Opp 1/2/3, L67–125, roadmap L147–152) |

---

## 🟢 Nice-to-have / cosmetic

| What | Status | Doc location |
|---|---|---|
| **N-7 `EVENT_*` constant-location asymmetry** | Cosmetic — catalog constants on CatalogHookHandler, customer/order on their Flusher. N-7 chose an abstract base (not a dispatcher), so the "unify" premise no longer applies. Defer or drop. | STATUS L505 |
| **Flaky `useBackfillProgress` test** (fake-timer race) | Fix with deterministic timer mocking. | STATUS L510 |
| **JS `mergeIdentity` stub** | **Intentional, NOT dead code** — kept platform-agnostic for the M2 path. Documented as deliberately retained. | DECISIONS L1439, STATUS L252 |
| **User-level consent-bridge** (Cookiebot / custom) | YAGNI — MiuMjau is CookieYes (WP-Consent-API-native, needs nothing). Build when a non-WP-Consent-API client arrives. | STATUS L535 |

---

## 🔵 Manual pilot-verification (NOT deferred code — execution tasks)

These are things a server-side test can't prove; verify by hand during the pilot.

| What | Why not machine-testable | Doc location |
|---|---|---|
| **Browse render-timing** (page-view fires on the right page) | A server-side walk can't observe the browser moment (`checkout_start` on checkout, `checkout_complete` on order-received, `product_view` on a product page). PHP page-type detection can't be driven in the integration harness (no `go_to()`); JS mapping is vitest-tested. (Future Chromium E2E — not built, YAGNI.) | STATUS L512, CLAUDE.md "Browse browser-timing" |
| **CookieYes live consent-gating** | Confirm a real consent plugin actually suppresses the beacon. | STATUS L428 |

---

## ✅ Resolved / decided — recorded so they aren't re-raised

| Item | Outcome |
|---|---|
| **Route-A N-4 — CSV divergence** | RESOLVED (N-4a): the admin CSV path now also requires `product_url` + `in_stock`, matching `ProductSchema`. Engine commit `852ea04`. (`docs/RECENGINE_API_CONTRACT.md` L1439.) |
| **Route-A D-5 — export attribution** | DECIDED: the engine export deliberately omits the `rec_attribution` array (engine-side Art-15 legal review, not a plugin contract issue). Tracked as known-deferred. (STATUS L521, contract L1175.) |
| **Route-A F-1 — AI field-mapping wizard** | RESOLVED — **engine-side** functionality (the engine maps fields via AI), believed built. Never a plugin-side item, so correctly untracked in this repo. **Confirm with the engine team.** Not a plugin deferral. |
