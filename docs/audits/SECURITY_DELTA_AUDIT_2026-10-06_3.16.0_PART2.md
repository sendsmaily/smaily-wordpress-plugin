# Security delta audit — 3.16.0 pre-release gate, part 2 (066724d..1fcae5d)

> **Disposition:** M1, L2, L3 fixed before the cut in #172 (PRO-3857), Erkki 2026-10-06; follow-ups PRO-3860 (identity merge / relay token consent, fixing before 3.16.0) and PRO-3859 (nightly manifest, after 3.16.0).

Addendum to [`SECURITY_DELTA_AUDIT_2026-10-06_3.16.0.md`](SECURITY_DELTA_AUDIT_2026-10-06_3.16.0.md)
(part 1, `3afff33..066724d`). Part 1 stays valid for what it covered; this
part covers only what landed after it.

- **Date:** 2026-10-06
- **Baseline:** delta `066724d..1fcae5d` (the part-1 tip to the current main
  tip; **6 commits, 58 files, +3 865 / −294**; the shipped plugin code alone —
  `includes/`, `integrations/`, `public/js/` without tests, and
  `blocks/recommendations/` — is **22 files, +926 / −88**). The work in it:
  - **#165** — the part-1 report (docs only).
  - **#166 (PRO-3831 / PRO-3832)** — the two part-1 Lows: the Elementor
    action keeps `elementor_form_url` only for a page on the store's own host
    (`FormSubscription::is_store_page()`); a page with recommendation cards
    set `DONOTCACHEPAGE` (removed again by #167).
  - **#167 (PRO-3835)** — **new public REST route**
    `GET /wp-json/smaily-connect/v1/recommendations`
    (`RecommendationsEndpoint`): the block and the shortcode now print one
    empty container for every visitor, and a new storefront bundle
    `sc-recs.js` fetches the cards after page load, with marketing consent
    only. The route names a logged-in shopper from the `logged_in` cookie and
    a guest from the visitor-token cookie (§15 v1.10.0), answers
    `{html}` with `Cache-Control: no-store, private`, refuses
    `Sec-Fetch-Site: cross-site` / `same-site`, and shares a new
    `RequestThrottle` with `/relay`. The engine call is one attempt with a
    10 s timeout; a failure is cached empty per shopper for 10 minutes.
  - **#168** — merchant docs (Elementor guide), docs only.
  - **#169 (PRO-3845)** — **new cookie**: `GuestVisitorToken` gives a guest
    buyer with marketing consent a store-created visitor token at checkout
    (`LandingCapture::issue_visitor_token()`), written to the visitor-token
    cookie and to the `_smaily_visitor_token` order meta, so the order sends
    it as `smaily_visitor_token` (§5 v1.11.0). `AttributionShape::is_visitor_token()`
    now also accepts the store format `vs_` + 22 alphanumerics.
  - **#170 (PRO-3849)** — **consent**: `Support\MarketingConsent` (PHP) and
    `public/js/lib/marketing-consent.ts` count marketing consent only when the
    WP Consent API consent cookie for the category is `allow` AND
    `wp_has_consent()` is true; the browse runtime, `sc-recs.js` and the
    checkout token all use it.
  - **Contract** syncs to v1.10.0 and v1.11.0 (`docs/` only).
- **Auditor:** Claude (Opus 5.5)
- **Trigger (re-audit policy):** point 1 (the 3.16.0 release boundary),
  point 2 (a new public REST route, a new cookie, **GDPR/consent** —
  the consent rule itself — and **external HTTP** from a public route).
- **Scope:** file-by-file read of the production delta (`git diff
  066724d..1fcae5d -- . ':!tests' ':!docs'`), plus the call chains it reaches:
  `StorefrontRecommendations` in full, `RecommendationsEndpoint`,
  `RequestThrottle`, `BeaconEndpoint::rate_limited()` / `EVENT_FIELDS`,
  `Bootstrap::storefront_recommendations()` / `rec_client()`,
  `Client::visitor_recommendations()` / `request_url()`,
  `LandingCapture::resolve()` / `capture()` / `current_visitor_token()` /
  `issue_visitor_token()` / `set_cookie()`, `GuestVisitorToken` in full,
  `HookHandler::save_attribution_cookies_to_order()`,
  `OrderPayloadBuilder` (visitor-token forward), `AttributionShape`,
  `MarketingConsent` + `marketing-consent.ts`, `recs-core.ts` / `recs.ts`,
  `beacon-core.ts` `detectConsent()`, `FormSubscription::is_store_page()`,
  `GdprHandler::ORDER_META_KEYS`, the contract §5 / §15 deltas, and
  `docs/DATA_MODEL_GDPR.md`. One throwaway PHP probe (outside the repo)
  confirmed Finding 1's entry step: `LandingCapture::resolve( array(
  'smaily_vt' => 'vs_' . <22 alphanumerics> ) )` returns the value for the
  visitor cookie. Tests were read only to see what is pinned
  (`RecommendationsEndpointTest`, `GuestVisitorTokenTest`,
  `MarketingConsentTest`, `LandingCaptureTest`). No real customer data was
  used; examples are placeholders.

## Verdict

**0 Blocking, 0 Critical, 0 High, 1 Medium, 2 Low, 8 Info.**

**RESULT: 3.16.0 may proceed once Finding 1 (Medium) is fixed, or Erkki
accepts it in writing.** The fix is small (one shape check on the URL capture
path), and the code is unreleased, so no store carries a planted token yet.
The two Lows need Erkki's disposition (fix before the cut, or accept); neither
blocks.

The new route is built with care: the shopper is resolved from server state
only, the engine key stays server-side, the answer is `no-store, private`, a
cross-site read is refused, every engine-supplied value is validated or
escaped, and nothing identifying is logged. The consent rule is fail-closed in
both languages. Part 1's two Lows are closed: the Elementor host check holds,
and the page no longer carries per-shopper data, so the `DONOTCACHEPAGE`
marking was correctly removed with nothing left behind.

The Medium is a seam between two pieces of new work: the store-created token
format (#169) widened the shared shape check, and the URL capture path (which
exists for engine email links) inherited it. That lets a link plant a token
the attacker knows, and the engine binds it to the victim at the next guest
order.

**PCP was not run in this pass** — the release gate runs it on the CI-built
ZIP. No new `$wpdb` use, so no new PCP database warning is expected.

---

## Findings

| # | Severity | Where | Summary |
|---|---|---|---|
| 1 | **Medium** | `LandingCapture.php:222`–`:225`, `AttributionShape.php:44`–`:51`, `HookHandler.php:729`–`:771` | A `?smaily_vt=vs_…` link plants an attacker-known token; the victim's next guest order binds it; the attacker then reads the victim's recommendations and can steer their profile |
| 2 | Low | `HookHandler.php:729`–`:771`, `RecommendationsEndpoint.php:86`–`:113` | A `vs_` cookie is sent on every later order, and the route answers, without checking marketing consent on the server |
| 3 | Low | `StorefrontRecommendations.php:80`, `:90`, `:256`–`:271`; `RecommendationsEndpoint.php:54`, `:96` | A guest names its own cache key, so the per-shopper caches bound neither engine calls nor PHP workers per client; a slow engine plus one address can hold ~20 workers |
| 4 | Info | `RecommendationsEndpoint.php:96`–`:109` | Cross-site read guard holds in current browsers; residual in browsers without `Sec-Fetch-Site`; a cross-site page can burn the shopper's address bucket |
| 5 | Info | `recs-core.ts:53`–`:74`, `StorefrontRecommendations.php:182`–`:236` | `innerHTML` takes server-escaped HTML only; no XSS path found |
| 6 | Info | `LandingCapture.php:177`–`:196`, `GuestVisitorToken.php:67`–`:91` | Token randomness, cookie attributes and the issue gates hold |
| 7 | Info | — | A guest's token names them in that browser for 365 days (shared browsers) |
| 8 | Info | `MarketingConsent.php`, `marketing-consent.ts` | The explicit-yes rule is fail-closed and the same in PHP and TS |
| 9 | Info | `FormSubscription.php:214`, `:258`–`:280` | #166 confirmed; nothing dangling from the removed `DONOTCACHEPAGE` |
| 10 | Info | `GdprHandler.php:61`–`:66`, contract §5 / §15 | New data on the wire is a pseudonymous token; export/erase cover it; two doc lines were stale |
| 11 | Info | whole delta | No new SQL, capability, nonce or secret; logs carry no identifier |

---

## 1. MEDIUM — a link can plant a known visitor token, and the engine binds it to the victim at their next guest order

**Where:** `includes/Integrations/WooCommerce/LandingCapture.php:222`–`:225`
(`resolve()` takes `smaily_vt` from the URL through
`AttributionShape::is_visitor_token()`), `includes/Smaily/RecEngine/Support/AttributionShape.php:44`–`:51`
(widened by #169 to accept `vs_` + 22 alphanumerics),
`includes/Integrations/WooCommerce/HookHandler.php:729`–`:771` (stamps any
visitor-token cookie onto the order), `GuestVisitorToken.php:80` (keeps an
existing token), contract §5 "Store-created visitor token" (an unknown `vs_`
token on an order is created and bound to the order's customer).

The URL capture path exists for engine email links, which carry only `vt_`
tokens; a store-created `vs_` token never travels in a URL (the JS writer
`attribution.ts` still accepts `vt_` only). Because the shared shape check now
accepts `vs_`, the PHP path does:

1. The attacker picks a well-formed token `vs_<22 chars>` and sends the
   victim a link to the real store: `https://store.example/?smaily_vt=vs_<22 chars>`.
   `LandingCapture` runs on `template_redirect`, connection-gated and
   consent-ungated (F3-46), and writes the token into the visitor-token
   cookie for 365 days. Probe-confirmed.
2. The victim later checks out as a guest. `GuestVisitorToken` sees a valid
   token and keeps it; `HookHandler` stamps it onto the order; the order
   sends it as `smaily_visitor_token`.
3. The engine does not know the token, so it creates it and binds it to the
   victim's customer for 365 days (§5).
4. The attacker sets the same cookie in their own browser and calls
   `GET /wp-json/smaily-connect/v1/recommendations` (the route reads only the
   cookie; consent is checked only in `sc-recs.js`). The answer is the
   victim's recommendation cards — products the engine picked from the
   victim's purchase and browse history — with the victim's `rec_id`s.
5. The attacker can also send browse events to `/relay` with that token
   (`smaily_visitor_token` is a whitelisted client field); the engine binds
   them to the victim's profile, so the attacker can steer the products that
   appear in the merchant's emails to the victim.

**Preconditions:** the store has the engine connected; the victim opens the
link (an ordinary store link, no phishing domain), later buys as a guest from
the same browser, has not objected to profiling, and is not unsubscribed in
Smaily (§15 answers empty otherwise). Step 4 does not need the block or the
shortcode on any page — the route answers whenever the engine may be called.

**Why Medium:** a targeted person's purchase-derived recommendations (which
can reveal a sensitive purchase on some stores) can be read, and the content
of the merchant's emails to that person can be manipulated. It needs the
victim's cooperation twice (click, then guest purchase), and the data shown
is products, not contact details — so not High.

**Proposed fix (before the 3.16.0 cut):** the URL capture path accepts only
the engine format. Add `AttributionShape::is_engine_visitor_token()` (the
`vt_` pattern) and use it in `LandingCapture::resolve()`; keep the widened
`is_visitor_token()` for the cookie, order-meta and send paths. Pin it in
`LandingCaptureTest` (`smaily_vt=vs_…` resolves no visitor slot). Together
with Finding 2's server-side consent check, a planted `vs_` value then never
reaches an order. **Open (engine team):** confirm that an unknown `vt_` value
on an order creates no binding — §5 says so for `vs_` only — otherwise the
same plant works with an invented `vt_` value.

## 2. LOW — a `vs_` token is sent on later orders, and the route answers, without a server-side consent check

**Where:** `HookHandler.php:729`–`:771` (stamps the visitor-token cookie onto
every order, ungated — correct for an engine `vt_` attribution token, which
F3-46 keeps consent-ungated); `RecommendationsEndpoint.php:86`–`:113`
(no consent check; consent lives only in `recs-core.ts:47`–`:49`).

Contract §5 says: "Create and send a `vs_` token only for a shopper who gave
marketing consent … Without consent, create no token and send none."
`GuestVisitorToken` honours the "create" half. The "send" half is not
enforced: once a browser carries a `vs_` cookie, every later order from it
sends the token, and the engine renews the binding for 365 days, also after
the shopper withdrew marketing consent in the banner, and also for a planted
token (Finding 1). The merchant privacy template (docs site, "Visitor-token
cookie for guest buyers") describes the token as consent-based. Likewise the
route sends a guest's token to the engine for any request; only the bundle
checks consent.

**Why Low:** after a withdrawal, the browse runtime and `sc-recs.js` stop, so
the renewed binding has no effect until consent is given again; the order
itself reaches the engine by email either way. It is a gap between the stated
consent rule and the code, not a data exposure on its own.

**Proposed fix:** in `save_attribution_cookies_to_order()`, stamp a `vs_`
value only when `MarketingConsent::given()` (a `vt_` value stays ungated, per
F3-46); in the route, ask about a guest token only when
`MarketingConsent::given()` (the consent cookie is on the same-origin request,
so the server can apply the same rule). Pin both with a unit test.

## 3. LOW — a guest names its own cache key, so the caches bound neither engine calls nor PHP workers per client

**Where:** `StorefrontRecommendations.php:256`–`:271` (cache key = tenant +
md5 of the identifier the request carries; failure cached per key),
`:80` (`TIMEOUT_SECONDS = 10`); `RecommendationsEndpoint.php:54`, `:96`
(120 requests per address per minute).

A guest's identifier is the cookie value, and any well-formed value is asked
about. A client that sends a fresh `vs_<22 chars>` on each request misses
every cache, so the only bound is the per-address limit:

- **Workers under a slow engine.** Each miss holds a PHP worker for up to
  10 s when the engine hangs (the engine's own limit is 10 s; a refused
  connection fails fast). 120 misses per minute is 2 per second, so one
  address can keep about 20 workers busy — the whole PHP-FPM pool of a typical
  WooCommerce host — and the store's own pages, checkout included, queue
  behind them. Without an attacker, the same outage costs one 10 s request
  per shopper per 10 minutes. A logged-in shopper's cold profiling read
  (part 1 Info 5, up to 30 s on Smaily) now also runs in this background
  request.
- **Rows.** Each miss writes one transient (1 h, or 10 min after a failure).
  Without a persistent object cache that is two `wp_options` rows per request:
  up to ~14 400 rows per address per hour, removed only by WordPress's daily
  expired-transient sweep. The `/relay` session counter has the same shape
  already (a client-chosen key), so this doubles an existing class rather
  than opening a new one.
- IPv6 rotation (part 1 Info 4) multiplies both.

**Why Low:** the worker exhaustion needs an engine slowdown the attacker does
not control, and the row growth is bounded per address and self-cleaning. The
engine side is protected by its own 100 req/s per tenant limit.

**Proposed fix (any one helps; the first is the important one):** a
store-wide breaker — after an engine timeout or 5xx, set one short transient
(for example 60 s) that makes every cache miss answer empty without a call;
a lower per-address ceiling for cache misses than for hits; a client timeout
below the engine's 10 s (the cards are optional — 3 to 5 s loses little).

## 4. INFO — the cross-site read guard holds in current browsers

**Where:** `RecommendationsEndpoint.php:96`–`:109`.

WordPress sends `Access-Control-Allow-Origin: <origin>` with
`Access-Control-Allow-Credentials: true` for any origin, and the route reads
the login cookie directly instead of WordPress's REST nonce (deliberately — a
page-embedded nonce breaks under full-page caching). The `Sec-Fetch-Site`
check is therefore the guard against a foreign page reading a shopper's cards.
Checked:

- Current Chrome, Edge, Firefox (90+) and Safari (16.4+) send
  `Sec-Fetch-Site`, so a credentialed `fetch()` and a JSONP `<script
  src="…?_jsonp=cb">` (WordPress REST supports `_jsonp` by default) both get
  the empty answer. Independently, the login cookie has no `SameSite`
  attribute (Chrome/Edge treat it as `Lax`, Firefox partitions third-party
  cookies, Safari blocks them) and the visitor cookie is `SameSite=Lax`, so a
  cross-site subresource request carries neither in those browsers.
- **Residual:** an old browser that sends neither `Sec-Fetch-Site` nor applies
  a `Lax` default or partitioning would leak. The share is negligible.
  **Optional hardening:** when `Sec-Fetch-Site` is absent, answer empty unless
  the `Origin` (or `Referer`) host is the store's host.
- The rate limit runs before the cross-site check, so a foreign page can spend
  a shopper's 120-per-minute address bucket and hide their cards for a minute.
  A nuisance only.
- `Cache-Control: no-store, private` overrides WordPress's own headers (the
  response headers are sent last). A CDN rule that caches `/wp-json/` and
  ignores the origin's `Cache-Control` would still store one shopper's answer
  — the same misconfiguration class as part 1 Low 2. **Optional:** add
  `Vary: Cookie`.

## 5. INFO — `sc-recs.js` injects only server-escaped HTML

**Where:** `public/js/recs-core.ts:53`–`:74`,
`StorefrontRecommendations.php:182`–`:236`.

The bundle fetches `boot.url` (from `rest_url()`, printed with
`wp_json_encode()`), with `credentials: 'same-origin'`, and puts the `html`
string into each container with `innerHTML`. The HTML is the same markup part
1 Info 9 checked: the engine contributes only a `RecId::is_valid()` rec id and
a digits-only product id; link `esc_url()`, name `esc_html()`, price
`wp_kses_post()`, image from WooCommerce. A non-OK status, a non-string
`html`, a throw or an empty string leaves the containers empty. No path for
visitor- or engine-controlled markup was found. The bundle is an IIFE in its
own Vite pass and is in `check:bundle-scope` and `verify-release-zip.sh`.
Functional note only: unlike the browse runtime, `sc-recs.js` does not honour
`smailyConnectBeacon.consentOverride` (fail-closed direction).

## 6. INFO — the checkout token: randomness, cookie and gates hold

**Where:** `LandingCapture.php:177`–`:196`, `:315`–`:360`;
`GuestVisitorToken.php:67`–`:91`.

- **Randomness:** 22 draws of `random_int( 0, 61 )` over a 62-character
  alphabet — uniform, from PHP's CSPRNG, about 131 bits. Not guessable.
- **Cookie:** the same writer as a landing capture: `Secure` on https,
  `SameSite=Lax`, the engine-config name and TTL (365 days by default).
  `HttpOnly` is off on purpose: the browse runtime reads the token for
  `enrich()`. Consequence: any script on the store's pages (third-party tags
  included) can read it and, from a page on the store, fetch that guest's
  cards. Accepted with the design.
- **Gates:** connected; not cron or WP-CLI; no customer id on the order and
  nobody logged in; `MarketingConsent::given()`; no valid token in the
  browser (a malformed one is replaced). The two hooks fire only for a
  shopper checkout, not for admin-created or WC-REST-created orders. The
  token goes into order meta only when the cookie could be written. Nothing
  logs the token.

## 7. INFO — a guest's token names them in that browser for 365 days

The token is a bearer identifier for the browser profile that holds it. On a
shared device, a later user who consents sees the first buyer's
recommendations until the cookie expires or is cleared. This is inherent to
naming a guest by a cookie, and §15 already chooses it (for a logged-in
shopper the customer id wins over the token). **Optional:** mention shared
devices in the privacy template.

## 8. INFO — the explicit-yes rule is fail-closed and identical in PHP and TS

**Where:** `includes/Support/MarketingConsent.php`,
`public/js/lib/marketing-consent.ts`.

Both require `wp_has_consent( category ) === true` AND the API's consent
cookie for the category `=== 'allow'`. No API, no cookie, `deny`, a
non-string value or a throw is no consent. The cookie name comes from code
only — the WP Consent API's `wp_consent_cookie_prefix` filter (PHP) or the
`consent_api.cookie_prefix` the API prints (TS) plus the
`smaily_connect_beacon_consent_category` filter — never from visitor input,
and it is used only as a lookup key, so a strange prefix cannot inject
anything; the TS lookup matches `name=` from the start of each cookie, so a
cookie whose name merely ends with it does not match. A visitor can of course
set their own consent cookie; that is their own assertion, as with any
consent banner. `consentOverride` keeps its meaning (a site-supplied
function, `=== true` only). Both are pinned (`MarketingConsentTest`,
`marketing-consent.test.ts`).

## 9. INFO — #166 confirmed; nothing dangling from the removed `DONOTCACHEPAGE`

- `FormSubscription::is_store_page()` refuses whitespace, control characters
  and backslashes, any user info, a non-http(s) scheme and a missing host, and
  compares the host case-insensitively with `home_url()` / `site_url()`. A
  different port on the same host is kept, which is harmless. Part 1 Low 1 is
  closed.
- `DONOTCACHEPAGE` / `nocache_headers()` have no reference left in shipped
  code; DECISIONS PRO-3832 is marked superseded by PRO-3835; the page HTML
  carries no per-shopper data. Part 1 Low 2 is closed by design.

## 10. INFO — new data on the wire, and GDPR coverage

- **Orders (§5):** the new value is a `vs_` token on the existing
  `smaily_visitor_token` field — a pseudonymous identifier, personal data once
  the engine binds it. **§15:** a guest request carries the token and `limit`
  only; no email or name (contract rule kept).
- **Export / erase:** `_smaily_visitor_token` is in
  `GdprHandler::ORDER_META_KEYS`, so the WordPress privacy tools export and
  remove it; the engine erase deletes `visitor_tokens` (DATA_MODEL_GDPR). The
  browser cookie cannot be erased from the server, which is normal; after an
  engine erase the token names nobody.
- **Stale docs (fixed in this PR / follow-up):** `docs/DATA_MODEL_GDPR.md`'s
  `GuestVisitorToken` row still described the superseded PRO-3845 consent-type
  rule; this PR corrects it. The merchant docs site's Recommendations block
  section (EN+ET) still says a returning guest is "someone who came … from a
  Smaily email before" and omits guest buyers with a checkout token — a
  follow-up, because the docs site needs the EN+ET pair and the Estonian
  proofread.

## 11. INFO — no new SQL, capability, nonce or secret

- No `$wpdb` use, migration, table or autoloaded option was added. New data
  at rest: the per-shopper storefront transients (finite TTL), the
  per-address rate-limit transients, and one order meta value.
- The one new `register_rest_route` is the public GET route above
  (`permission_callback => '__return_true'`, gated in the handler). No new
  `current_user_can` or nonce.
- `RequestThrottle` is a move of `/relay`'s counter and `client_ip()` with the
  same behaviour; `/relay`'s keys and ceilings are unchanged.
- No secret reaches the browser: the boot blob holds the route URL and the
  consent category only. New log lines carry an exception class name or a
  fixed string, never a token, email or user id.

---

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/REST/RecommendationsEndpoint.php`, `includes/REST/RequestThrottle.php`, `includes/REST/EndpointRegistry.php`, `includes/REST/BeaconEndpoint.php` | **in** | new public route, throttle (§3, §4, §11) |
| `includes/Integrations/WooCommerce/StorefrontRecommendations.php`, `blocks/recommendations/**`, `includes/Bootstrap.php`, `includes/Smaily/RecEngine/Client.php` | **in** | engine call, cache, escaping, timeout (§3, §5) |
| `public/js/recs.ts`, `public/js/recs-core.ts`, `vite.config.ts`, `package.json`, `bin/verify-release-zip.sh` | **in** | new bundle, DOM injection, build checks (§5) |
| `includes/Integrations/WooCommerce/GuestVisitorToken.php`, `includes/Integrations/WooCommerce/LandingCapture.php`, `includes/Smaily/RecEngine/Support/AttributionShape.php`, `includes/Integrations/WooCommerce/HookHandler.php` | **in** | new cookie, token format, order forward (§1, §2, §6) |
| `includes/Support/MarketingConsent.php`, `public/js/lib/marketing-consent.ts`, `public/js/beacon-core.ts`, `includes/Notifications/NotificationManager.php` | **in** | consent rule (§8) |
| `includes/Integrations/Elementor/FormSubscription.php` | **in** | host check (§9) |
| `admin/src/components/steps/Step4Recommendations.tsx` | **in** (skimmed) | copy only |
| `STATUS.md`, `CLAUDE.md`, `docs/**`, `languages/**`, `.github/**` | out | no executable plugin content |
| `tests/**`, `*.test.ts`, `vitest.config.ts` | out | not shipped; read for what is pinned |

## Gates run for this pass

Documentation-only pass. No plugin code was changed, so `ci:strict`, the
integration suite and PCP were not run here; the release gate covers them. One
throwaway PHP probe (outside the repo) confirmed Finding 1's entry step.

## Follow-ups this audit leaves open

1. **Finding 1 (Medium): fix before the 3.16.0 cut, or Erkki accepts it.**
   URL capture accepts only `vt_`; pin `smaily_vt=vs_…` → no visitor slot.
   Ask the engine team whether an unknown `vt_` on an order creates a binding.
2. **Finding 2 (Low): Erkki to decide.** Stamp a `vs_` value on an order only
   with `MarketingConsent::given()`; the route asks about a guest token only
   with it.
3. **Finding 3 (Low): Erkki to decide.** Store-wide breaker after an engine
   timeout/5xx; optionally a lower miss ceiling and a shorter client timeout.
4. **Finding 4 (Info, optional):** `Origin`/`Referer` host check when
   `Sec-Fetch-Site` is absent; `Vary: Cookie`.
5. **Finding 7 (Info, optional):** mention shared devices in the privacy
   template.
6. **Finding 10 (Info, docs):** the docs site's Recommendations section
   (EN+ET) should name guest buyers with a checkout token, then go through the
   Estonian proofread.
