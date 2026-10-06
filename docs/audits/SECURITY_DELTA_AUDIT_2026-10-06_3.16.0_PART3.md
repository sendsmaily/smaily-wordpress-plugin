# Security delta audit — 3.16.0 pre-release gate, part 3 (1fcae5d..e1dcc18)

Addendum to [`SECURITY_DELTA_AUDIT_2026-10-06_3.16.0.md`](SECURITY_DELTA_AUDIT_2026-10-06_3.16.0.md)
(part 1, `3afff33..066724d`) and
[`SECURITY_DELTA_AUDIT_2026-10-06_3.16.0_PART2.md`](SECURITY_DELTA_AUDIT_2026-10-06_3.16.0_PART2.md)
(part 2, `066724d..1fcae5d`). Parts 1 and 2 stay valid for what they covered;
this part covers only what landed after part 2, which is the fixes for part 2's
findings.

- **Date:** 2026-10-06
- **Baseline:** delta `1fcae5d..e1dcc18` (**3 commits, 31 files, +1 521 /
  −115**; the shipped plugin code alone — `includes/` and `public/js/` without
  tests — is **9 files, +173 / −49**). The work in it:
  - **#171** — the part-2 report (docs only).
  - **#172 (PRO-3857)** — part 2's M1, L2 and L3: a landing link takes only the
    engine `vt_` token (`AttributionShape::is_engine_visitor_token()`); a
    store `vs_` token rides an order, and the recommendations route asks about
    a guest, only with `MarketingConsent::given()`; an engine timeout, network
    failure or 5xx on §15 sets the store-wide pause transient
    `smly_rec_storefront_paused` for 2 minutes. Contract v1.12.0 sync (new
    §3c nightly manifest, not built; no wire shape the plugin sends changed).
  - **#173 (PRO-3860)** — the login identity merge and `/relay` send a `vs_`
    token only with `MarketingConsent::given()`; `/relay` reads the token from
    the visitor-token cookie on the server and strips a token from the request
    body; the JS no longer sends one.
- **Auditor:** Claude (Opus 5.5)
- **Trigger (re-audit policy):** point 1 (the 3.16.0 release boundary) and
  point 2 (the public `/relay` route, the public `/recommendations` route,
  **GDPR/consent**).
- **Scope:** file-by-file read of the production delta (`git diff
  1fcae5d..e1dcc18 -- includes public/js`), plus the call chains it reaches:
  every reader of the visitor-token cookie (`LandingCapture::current_visitor_token()`,
  `RecommendationsEndpoint::visitor_token()`, `HookHandler::save_attribution_cookies_to_order()`,
  `GuestVisitorToken::maybe_issue()`, `OrderPayloadBuilder`), `BeaconEndpoint::validate_batch()`
  (strip vs. reject), `StorefrontRecommendations::slots()` / `identity()`,
  `Client::request_url()` (which failures carry code 0 or 5xx), and contract
  §5, §6, §7 and §15. To answer part 2's open engine question, the engine's
  own source was read in the local engine checkout (`lib/visitor-tokens/manager.ts`,
  `app/api/v1/identity/merge/route.ts`, `app/api/v1/recommendations/customer/route.ts`);
  that checkout can lag the deployed engine. Tests were read only to see what
  is pinned. No real customer data was used.

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium, 1 Low, 5 Info.**

**RESULT: 3.16.0 may proceed.** All three part-2 findings are fixed as
proposed, and PRO-3860 closes the two send paths part 2 did not name. Every
path that sends a visitor token to the engine now reads it on the server from
the shopper's own cookie, shape-checked, and holds a `vs_` token to an
explicit marketing yes; an engine `vt_` token stays consent-ungated, per
F3-46. The fixes add no SQL, capability, nonce, secret or log identifier.

The one Low is older than 3.16.0 and sits in the engine: a link can still set
an invented `vt_` token, and browse retro-binding can then attach an
attacker's anonymous browsing to a victim. It cannot reach the victim's
recommendations (the read path that made part 2's M1 a Medium).

**PCP was not run in this pass** — the release gate runs it on the CI-built
ZIP. No new `$wpdb` use, so no new PCP database warning is expected.

---

## Findings

| # | Severity | Where | Summary |
|---|---|---|---|
| 1 | Low | engine `identity/merge` + §6 retro-binding; `LandingCapture.php:225` | An invented `vt_` link token cannot be read back, but browsing sent under it can still be bound to the victim (pre-existing, engine-side) |
| 2 | Info | `LandingCapture.php:225`, `AttributionShape.php:48`–`:64`, `attribution.ts` | Part 2 M1 fixed: a link takes `vt_` only, in PHP and JS |
| 3 | Info | `HookHandler.php:770`–`:773`, `RecommendationsEndpoint.php:118`, `IdentityHookHandler.php:85`–`:91`, `BeaconEndpoint.php:364`–`:377` | Part 2 L2 fixed on all four send paths; the body token is stripped, not rejected |
| 4 | Info | `StorefrontRecommendations.php:281`, `:293`–`:296` | Part 2 L3 fixed: the store-wide pause bounds a hanging engine to one timeout window per 2 minutes |
| 5 | Info | `RecommendationsEndpoint.php:148`–`:157`, `HookHandler.php:97` | Two cookie readers still skip the shape check; their callers check it |
| 6 | Info | whole delta | No new SQL, capability, nonce, secret or log identifier |

---

## 1. LOW — an invented `vt_` link token is not readable, but browsing under it can still be bound to the victim

**Where:** `LandingCapture.php:225` (a link may carry any `vt_` + 1–64
alphanumerics); engine `identity/merge` (§7) and the §6 cross-session
retro-binding.

Part 2 asked the engine team whether an unknown `vt_` value on an order
creates a binding. The engine source answers it: `bindOrderVisitorTokens()`
inserts a `visitor_tokens` row only for the store `vs_` format; a `vt_` token
only extends a row that already exists for the same customer. The identity
merge only updates an existing `visitor_tokens` row. §15 resolves a guest only
through a `visitor_tokens` row. So an invented `vt_` value never names the
victim to §15, and the attacker cannot read the victim's recommendations with
it. Part 2's M1 has no `vt_` variant.

The steering half remains. The attacker opens `?smaily_vt=vt_<invented>` in
their own browser and sends anonymous browse events under that token, then
gets the victim to open the same link. When the victim logs in (identity
merge) or browses while logged in (the events carry `customer_email`), the
engine retro-binds the earlier anonymous events with that token to the victim
— the attacker's events included. The attacker can therefore add products to
the victim's browse profile, which can change the products in the merchant's
emails to the victim. The engine's PRO-3649 rule (first binding wins) limits
it to a token the victim is the first customer to bind.

**Why Low:** it needs the victim to open a crafted link and later log in on
the same browser, it reads nothing, and it existed before 3.16.0 (the `vt_`
link capture and the token on browse events predate this release). The
plugin cannot tell an engine-issued `vt_` token from an invented one; only the
engine can.

**Proposed fix (engine team, after 3.16.0):** retro-bind browse events by
visitor token only when the engine issued that token (a `visitor_tokens` row
exists), so a token nobody issued never collects history.

## 2. INFO — part 2 M1 is fixed: a link takes only the engine `vt_` token

`AttributionShape` now has `is_engine_visitor_token()` (`vt_` only) and
`is_store_visitor_token()` (`vs_` only); `is_visitor_token()` is their union.
`LandingCapture::resolve()` uses the engine-only check, so `?smaily_vt=vs_…`
writes no cookie. The JS writer (`attribution.ts`) already accepted `vt_`
only; it is now pinned. The cookie read, order meta and send paths keep the
union, so a `vs_` token issued at checkout still works. Pinned:
`test_resolve_ignores_a_store_created_token_in_a_link`,
`test_capture_writes_no_cookie_for_a_store_created_token_in_a_link`, and the
`attribution.test.ts` case.

## 3. INFO — part 2 L2 is fixed on every send path

Every path that sends a visitor token to the engine was checked:

| Path | Before | After |
|---|---|---|
| Order (`HookHandler` stamp → `OrderPayloadBuilder`) | any cookie value stamped | a `vs_` value only with `MarketingConsent::given()`; `vt_` ungated (F3-46); the payload builder still sends only a shape-valid token |
| Order (`GuestVisitorToken`) | issued only with consent | unchanged |
| `/recommendations` | guest token passed on for any request | guest token passed on only with consent (`vt_` as well — stricter than needed, matches `sc-recs.js`); a logged-in shopper is named by the account |
| Login merge (`IdentityHookHandler`) | raw cookie, no shape check | shape-checked cookie; a `vs_` value only with consent |
| `/relay` | token taken from the request body | `smaily_visitor_token` removed from `EVENT_FIELDS`; `attach_visitor_token()` adds the shape-checked cookie token after validation, a `vs_` value only with consent |

`validate_batch()` copies only whitelisted keys, so a body that still carries
`smaily_visitor_token` — a 3.15 `sc-runtime.js` served from a page cache after
the update — is stripped, not rejected, and its events still forward.

A cross-site page cannot make `/relay` attach the victim's token: the
visitor-token cookie is `SameSite=Lax`, so a cross-site POST does not carry
it. Before #173, anyone who knew a token could name it in a request body; now
only the browser that holds the cookie sends it.

## 4. INFO — part 2 L3 is fixed: the store-wide pause

After an `ApiException` with code 0 (network failure or timeout) or 500+, the
pause transient (2 minutes, finite TTL, so autoload is off) makes every cache
miss answer empty without an engine call. A 4xx, 429 included, does not pause
the store; a cached answer is still served during the pause. Pinned in
`StorefrontRecommendationsTest` and the render integration test.

Residual, accepted: misses that started before the first timeout ends still
wait for their own timeout. Under a hanging engine one address can therefore
hold about 20 workers for one 10-second window every 2 minutes, instead of
all the time. A client that can make the engine answer 5xx to a shape-valid
token would hide every shopper's cards for 2 minutes at a time; the cards are
optional content, and no such input is known.

## 5. INFO — two cookie readers still skip the shape check

`RecommendationsEndpoint::visitor_token()` and the `HookHandler` stamp read the
visitor-token cookie without `AttributionShape`. Their callers check it
(`StorefrontRecommendations::identity()`, `OrderPayloadBuilder`), so a
malformed value is never sent. **Optional:** route both through
`LandingCapture::current_visitor_token()`, so one reader holds the rule.

## 6. INFO — nothing else changed in the attack surface

No `$wpdb` use, migration, table, capability, nonce or secret was added. The
one new option is the pause transient (finite TTL). The new log line carries a
fixed string and a number. The `marketing_consent_given()` seams are
`protected` methods that only tests override. Contract v1.12.0 adds the §3c
nightly manifest; the plugin does not send it yet (PRO-3859), so nothing new
leaves the store.

---

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/REST/BeaconEndpoint.php`, `includes/REST/RecommendationsEndpoint.php` | **in** | public routes (§3) |
| `includes/Integrations/WooCommerce/HookHandler.php`, `IdentityHookHandler.php`, `LandingCapture.php` | **in** | token send paths (§1–§3) |
| `includes/Integrations/WooCommerce/StorefrontRecommendations.php` | **in** | pause (§4) |
| `includes/Smaily/RecEngine/Support/AttributionShape.php`, `public/js/lib/attribution.ts`, `public/js/lib/rec-engine-client.ts` | **in** | token shape, JS send (§2, §3) |
| `STATUS.md`, `CLAUDE.md`, `docs/**` | out | no executable plugin content |
| `tests/**`, `*.test.ts` | out | not shipped; read for what is pinned |

## Gates run for this pass

Documentation-only pass. No plugin code was changed, so `ci:strict`, the
integration suite and PCP were not run here; the 3.16.0 release gate runs them
(see the 3.16.0 release-gate row in `INDEX.md`).

## Follow-ups this audit leaves open

1. **Finding 1 (Low, engine team):** retro-bind browse events by visitor token
   only for a token the engine issued.
2. **Finding 5 (Info, optional):** one shape-checked reader for the
   visitor-token cookie.
3. Part 2's open Info items (4, 7, 10) stay open as part 2 lists them.
