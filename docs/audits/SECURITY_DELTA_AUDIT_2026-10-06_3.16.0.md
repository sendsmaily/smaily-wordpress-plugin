# Security delta audit — 3.16.0 pre-release gate (3.15.0..HEAD)

- **Date:** 2026-10-06
- **Baseline:** delta `3afff33..066724d` (the commit that recorded 3.15.0 live
  on wordpress.org — its code is the `3.15.0` tag plus docs only — to the
  current main tip; **13 commits, 110 files, +6 888 / −264**; the shipped
  plugin code alone — `includes/`, `integrations/`, `admin/src/` and
  `public/js/` without their tests, plus the new `blocks/recommendations/` —
  is **46 files, +1 755 / −153**). The work in it:
  - **PRO-3623** — a new engine connection is accepted only on
    `https://intelligence.smaily.com`: `SetupExchange::is_allowed_engine_url()`
    checks the setup link (before any request) and the engine's reply
    (`engine_base_url` and every endpoint-map URL); the setup-exchange REST
    route checks the base first; the integration suite opens one extra host
    through the `SMAILY_CONNECT_TEST_ENGINE_HOST` constant.
  - **PRO-3620** — the public `/relay` route: `external_id` leaves the
    browse-event whitelist; the per-IP rate limit always applies (a request
    with no usable `REMOTE_ADDR` counts against one shared bucket); the engine
    forward is one attempt, 3 s, no redirect, no retry.
  - **PRO-3788** — storefront recommendations: the `smaily/recommendations`
    block and `[smaily_recommendations]` shortcode call the engine (§15) on the
    page render for a logged-in shopper, cache the answer per shopper for 1 h,
    and render product cards from the store's own data; both attribution
    writers (`LandingCapture`, `attribution.ts`) now clear the context cookie
    when a landing carries a valid `smaily_rec` and no valid `smaily_ctx`.
  - **PRO-3806** — **consent / new public input**: an Elementor Pro form
    action ("Smaily") that upserts the visitor as a subscribed contact
    (`is_unsubscribed = 0`), with mapped fields, the source fields
    `elementor_form_name` / `elementor_form_url` /
    `elementor_form_submitted_at`, an optional workflow and a 5-minute
    double-submit transient.
  - **PRO-3627** — **consent**: a profiling choice and the abandoned-cart
    purchase marker are written only to a contact Smaily already has
    (`Client::has_contact()`).
  - **PRO-3750** — a Smaily `{code: 203}` "invalid data" on HTTP 200 fails the
    row at once (`RetryPolicy::throw_if_permanent_envelope()`).
  - **PRO-3796** — `over_10_products` is prefilled `''` on every abandoned-cart
    reminder.
  - **PRO-3743** — a successful setup exchange starts the products import,
    first batch 180 s out (held back by the existing `/backfill/cancel`); the
    route returns `catalogImport` / `catalogImportDelaySeconds`.
  - **PRO-3707 / PRO-3673 / PRO-3609** — admin UI: the engine automations
    screen re-reads §12 after a save and shows the stored state; copy beside
    the browse-tracking toggle (`consentApiPresent` in the boot payload); the
    soft opt-in note in Step 2.
  - **#164** — refactor after the simplification review (state-row read and
    constants to the job layer, `has_contact()` / `CODE_OK`,
    `request_url()` single-attempt option, the Elementor dropdown gate in
    `Form_Action`).
  - **Contract** sync to v1.9.1 (`docs/` only).
  - Non-code: `STATUS.md`, `CLAUDE.md`, `README.md`, `docs/**`,
    `languages/**`, `bin/` (dev tooling, not shipped), tests.
- **Auditor:** Claude (Opus 5.5)
- **Trigger (re-audit policy):** point 1 (the 3.16.0 release boundary),
  point 2 (the public `/relay` route, **GDPR/consent** — the Elementor
  subscribe path and the PRO-3627 write guards — and new **external HTTP**: the
  §15 call on the page render) and point 3 (two new public entry points: an
  Elementor form submission that writes to Smaily, and a block/shortcode that
  calls the engine for any logged-in visitor).
- **Scope:** file-by-file read of the production delta (`git diff
  3afff33..066724d -- . ':!tests' ':!docs'`), plus the call chains it
  reaches: `SetupExchange` in full, `RecEngineEndpoint::setup_exchange()`,
  `RecEngineSettings::store()` / `base_url()`, `Client::request_url()` /
  `resolve_url()`, `BeaconEndpoint::handle()` / `rate_limited()` /
  `client_ip()` / `EVENT_FIELDS`, `StorefrontRecommendations` in full, its
  block and shortcode registration, `ProfilingConsent::may_profile()` /
  `refresh()` / `write()`, `Bootstrap::profiling_consent()` /
  `rec_client()` / `smaily_client()`, `LandingCapture::resolve()` /
  `write_cookie()` / `send_cookie()`, `attribution.ts`
  `captureAttributionParams()` / `writeCookie()`, `FormSubscription` and
  `Form_Action` in full, Smaily `Client::request()` (exception text),
  `Flusher::dispatch_contact_sync()`, `CartFlusher`, `RetryPolicy`,
  `CatalogImportOnConnect`, `AbstractBackfillJob::read_state()`,
  `BackfillEndpoint` (start / cancel), `EventQueue::erase_for_privacy_request()`.
  The new host check was probed with 30 parser-trick inputs in a throwaway
  PHP script (outside the repo; `wp_parse_url` stubbed to `parse_url`, which is
  what WordPress calls). Tests were read only to see what is pinned
  (`SetupExchangeTest`, `FormSubscriptionTest`, `FormActionTest`,
  `ClientCustomerRecommendationsTest`). Elementor Pro is not in this repo or
  in wp-env, so its side of the form action (how it builds `form_settings`,
  `fields` and `page_url`) is described from its public source and is part of
  the human acceptance run that PRO-3806 already names. No real customer data
  was used; examples below are placeholders.

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium. 2 Low, 10 Info. RESULT: 3.16.0
may proceed.** Both Lows need a disposition from Erkki (fix before the cut, or
accept); neither blocks.

No REST route, capability or nonce check was added or widened. The only new
capability check is the Elementor workflow dropdown's `is_admin() &&
current_user_can( 'edit_posts' )`, which matches the PRO-2350 stance for the
workflow list. The host check holds against every parser trick tried. The
public `/relay` route is narrower than in 3.15.0. The two consent changes
(PRO-3627, PRO-3750) move toward fewer unintended subscriptions and fewer
silent drops. The new storefront call is gated on login, `sending_allowed()`
and the profiling gate, escapes everything it prints, and keeps only a
validated rec id and a product id from the engine. The two Lows are a
visitor-controlled value stored under a name that says it is the store's own
page, and a missing do-not-cache signal on a per-shopper block.

**PCP (WordPress Plugin Check) was not run in this pass** — the release gate
runs it on the CI-built ZIP (CLAUDE.md "Running PCP"). One thing to expect
there: `AbstractBackfillJob::read_state()` is the state query that moved out
of `BackfillEndpoint`; it is covered by `AbstractBackfillJob.php`'s file-level
`DirectQuery` / `NoCaching` suppression, so no new warning is expected from it.

---

## 1. LOW — `elementor_form_url` is a visitor-chosen URL stored under a name that says it is the store's page

**Where:** `integrations/elementor/form-action.class.php:63`–`:64` (reads
Elementor's `page_url` form meta) → `includes/Integrations/Elementor/FormSubscription.php:211`–`:214`
(`esc_url_raw()`, then stored on the Smaily contact as `elementor_form_url`).

Elementor Pro takes the page URL of a submission from the form POST (the
`referrer` field the browser sends), not from server state. A visitor can
therefore submit any `http(s)` URL. `esc_url_raw()` refuses `javascript:` and
other unsafe schemes, but keeps any `https://` address on any host.

The merchant docs (`docs/site/index.html:905`, ET `:1008`) and DECISIONS
PRO-3806 describe the field as "the page the form was sent from". A merchant
who believes that may put `{{ elementor_form_url }}` into the welcome workflow
as a "back to where you were" link. Then:

1. An attacker submits the form with a victim's email address and
   `referrer=https://phish.example/login`.
2. The contact is subscribed (`is_unsubscribed = 0`), and the workflow, if
   configured, is triggered.
3. The victim receives the merchant's own branded email, from the merchant's
   own sender, with a link to the attacker's page.

**Why it is Low, not higher:** it needs the merchant to render the field as a
link. Every other field a form sends (name, mapped fields) is visitor text in
the same way, so a careful merchant already treats form fields as untrusted.
The harm is phishing through a trusted sender, not data exposure. The
subscribe itself is the accepted form-signup class (Info 6).

**Proposed fix:** keep the URL only when its host is the store's own host
(`wp_parse_url( home_url(), PHP_URL_HOST )`, compared case-insensitively);
otherwise omit the field. Alternatively store only the path. Pin both cases in
`FormSubscriptionTest` (same-host kept, foreign host dropped). The docs line
can then stay as it is.

## 2. LOW — the recommendations block sends no do-not-cache signal, so a page cache that stores logged-in pages can serve one shopper's recommendations to others

**Where:** `blocks/recommendations/smaily-integration.class.php:24`–`:26`
→ `includes/Integrations/WooCommerce/StorefrontRecommendations.php:87`–`:140`.

The block and the shortcode render per shopper, from `get_current_user_id()`,
into the page HTML. Nothing marks that HTML as private. The design relies on
page caches bypassing logged-in users, which is the default for the common WP
caches (WP Rocket, WP Super Cache, W3TC, LiteSpeed) and for WooCommerce-aware
hosting. A cache that ignores the login cookie (a CDN "cache everything" rule,
a Varnish configuration that strips cookies) stores the first logged-in
shopper's cards and serves them to every later visitor.

What leaks: up to four products the engine picked for that shopper (derived
from their purchase and browse history), and the shopper's `rec_id`s in the
links. A later purchase through such a link is credited to the first
shopper's recommendation.

**Why it is Low, not higher:** it needs a misconfigured cache. The same
misconfiguration also leaks WooCommerce's own logged-in output (the account
menu, the mini-cart) more directly. The cards carry no name, email or order
data. The DECISIONS PRO-3788 rationale already rejects client-side rendering
for exactly this reason, but does not close the server-side variant.

**Proposed fix:** when `render()` returns non-empty HTML, call
`wc_maybe_define_constant( 'DONOTCACHEPAGE', true )` (WooCommerce's own
helper, honoured by WP Super Cache, W3TC, LiteSpeed and WP Rocket) and, when
headers are not yet sent, `nocache_headers()`. A rendered-nothing page stays
cacheable. One unit/integration assert that the constant is defined after a
non-empty render.

## 3. INFO — the host check holds; it pins where a connection may point, not where the engine may redirect

**Where:** `includes/Smaily/RecEngine/SetupExchange.php:64`–`:99`, `:198`–`:242`;
`includes/REST/RecEngineEndpoint.php:176`–`:188`.

Checked: the setup link's base, a pasted `base_url` and the reply's
`engine_base_url` + every endpoint-map URL all go through one rule (https,
exactly `intelligence.smaily.com`, no port, no user info, no whitespace,
control characters or backslashes). The probe refused, among others: user
info before another host (`…@evil.com`, `…:443@evil.com`, `…;@evil.com`,
`…%2f@evil.com`), the host in a fragment or query of another host, a
backslash, a trailing dot, a lookalike suffix or prefix, an explicit `:443`,
plain http, protocol-relative and schemeless forms, `%2e`-encoded dots, a
tab and a non-breaking space. It allowed only the real host, its case
variants, a trailing `/`, an empty port (`:`) and a query or fragment after
the host. Those all reach the same host in WordPress's HTTP transport, whose
authority also ends at the first `/`, `?` or `#`. The REST route checks before
`exchange()`, and `exchange()` checks again, so the token never leaves for
another host. A refused reply is never stored. All of this is pinned in
`SetupExchangeTest` (19 refused shapes, 4 allowed ones, refused-before-request
and refused-reply cases).

Two notes, no change needed for 3.16.0:

- **Redirects.** The exchange POST (`wp_remote_post`, `SetupExchange.php:208`)
  and every engine call except the browse forward (`Client.php:589`–`:605`)
  keep WordPress's default of following up to five redirects. The browse
  forward already sets `redirection => 0`. A 307/308 from the engine host
  would resend the token body, or the Bearer key, to the `Location` host.
  Only Smaily controls that host, so this is defence in depth. **Optional:**
  set `'redirection' => 0` on the exchange and on `request_url()`.
- **Stored connections and the test seam.** A connection saved before 3.16.0
  is not re-checked, by design (a retired preview alias must keep working;
  CLAUDE.md "A new engine connection is accepted only on …"). The
  `SMAILY_CONNECT_TEST_ENGINE_HOST` constant is defined only in
  `tests/Integration/bootstrap.php:73`, which the release ZIP excludes
  (`tests` is in the verify script's not-present list). It lets one host
  through on any port and over http, so a site can only open it by editing PHP
  on purpose.

## 4. INFO — `/relay` rate limit: the per-IP bound is per address, so IPv6 and proxies change what it means

**Where:** `includes/REST/BeaconEndpoint.php:645`–`:662` (`rate_limited()`),
`:682`–`:689` (`client_ip()`).

The PRO-3620 change is correct: the per-IP counter can no longer be skipped,
and the session counter alone (a cookie the client chooses) never decides.
`X-Forwarded-For` and similar headers are still ignored. Three properties of
keying on the full `REMOTE_ADDR` remain:

- **IPv6:** one client usually controls a whole /64, so it can rotate its
  source address and get a fresh 120-per-minute bucket each time. The
  docblock's "the bound a client cannot lift" holds for IPv4 only.
- **Behind a reverse proxy or CDN without `real_ip` / `mod_remoteip`:** every
  shopper shares the proxy's few addresses, so 120 requests per minute across
  the whole store is the cap. Browse telemetry is then dropped on a busy store.
  This was true before the delta, and it is a misconfiguration the docblock
  names.
- **The shared `''` bucket:** on a server where `REMOTE_ADDR` is missing or
  not an IP, one client can use up the bucket for every client.

The impact is limited to anonymous browse telemetry: identity is server-side
only, and `external_id` is now stripped too. Each accepted request costs one
engine call of at most 3 s. **Optional hardening:** key IPv6 on its /64
prefix.

## 5. INFO — the storefront render can still wait on the profiling read; the 1 s bound covers only the §15 call

**Where:** `includes/Integrations/WooCommerce/StorefrontRecommendations.php:159`
→ `ProfilingConsent::may_profile()` → `refresh()` →
`Smaily\Client::get_contact_consent()` (`timeout => 30`, `Client.php:344`) and,
for an opted-out answer, `engine_opt_out()` on `Bootstrap::rec_client()` (2
attempts, 15 s each, with back-off).

The engine call itself is bounded (one attempt, 1 s, no Retry-After wait —
`Bootstrap::storefront_recommendations()`). But on a cold profiling cache the
same render first reads the Smaily contact, and can then call the engine
opt-out. A slow Smaily can hold that one page render for up to 30 s (more if
the opt-out also has to wait). The result is cached for a day, on success and
on error (`refresh()` → `cache()`), so this happens at most once per shopper
per day and cannot be repeated by one visitor. The same pattern already
exists on the My Account page and on `/relay` (PRO-1389). The class docblock's
"built to cost the shopper nothing" is true for the engine call only.

Two smaller points on the same path:

- An engine error is not cached (by design, §15), so while the engine is down
  every logged-in render that shows the block pays up to 1 s. The §15 client
  is not built with `single_attempt`, so it still follows redirects, and each
  hop gets its own 1 s.
- `usable_slots()` (`:190`) does not cap the slot count at `LIMIT`. The engine
  is trusted to answer with at most 4, but an oversized answer would mean one
  `wc_get_product()` per slot.

**Optional:** on a profiling-cache miss, render nothing and warm the cache in
the background; cap `usable_slots()` at `LIMIT`; `redirection => 0` on the
§15 call.

## 6. INFO — the Elementor action subscribes any typed address and resubscribes an earlier unsubscribe (decided; accepted class)

**Where:** `includes/Integrations/Elementor/FormSubscription.php:110`–`:145`,
`:175`–`:179`.

Every newsletter-mode submission, and every ticked contact-mode submission,
upserts the typed address with `is_unsubscribed = 0`. WordPress does not
check that the address belongs to the visitor. A contact who unsubscribed
earlier is subscribed again. This is the deliberate PRO-3806 consent rule
(Erkki, 2026-10-05) and the same class as 3.15.0 Low 1 (accepted, PRO-3433)
and every public signup form. The plugin adds no rate limit of its own and
relies on Elementor's spam protection (honeypot, reCAPTCHA), which the
merchant has to switch on. A bot can therefore subscription-bomb a form that
has none, which costs the merchant list quality and sender reputation.

Checked and correct: in contact mode, nothing is sent unless the configured
consent field is non-empty, and a missing consent-field setting sends
nothing. The email is trimmed, lowercased and must pass `is_email()`. Only
mapped fields are sent. Mapped values go through `sanitize_text_field()`.
Empty values are left out (Smaily reads empty as "wipe"). The form settings
come from the saved page, not from the POST, so a visitor cannot change the
mapping or the workflow.

**Follow-up (docs only):** in the Elementor section of the merchant docs
(EN+ET), recommend switching on Elementor's honeypot or reCAPTCHA, and
mention a double opt-in workflow for merchants who want verified addresses.

## 7. INFO — a field mapping can write the plugin's own consent and marker fields

**Where:** `FormSubscription::RESERVED_FIELDS` (`FormSubscription.php:71`),
which refuses `email`, `is_unsubscribed` and the three `elementor_form_*`
names.

A mapping row may target any other `[a-z0-9_]{1,64}` name, including fields
the plugin itself reads as consent or state: `smaily_rec_profiling` and
`smaily_rec_profiling_ts` (read by `ProfilingConsent`),
`abandoned_cart_purchased_at` and `abandoned_cart_automation_at` (workflow
exits). A form that maps a visitor-editable field (a hidden field included)
to `smaily_rec_profiling` + `smaily_rec_profiling_ts` lets any visitor write
`'1'` with a current Z-form timestamp for any address. That lifts a store-side
profiling opt-out on the next read-back (the PRO-3434 parse accepts a
present-moment timestamp, correctly).

Only a form author can create such a mapping: a user who can edit that page
in Elementor, which is typically an `edit_posts`-level user. Smaily forms that
accept custom fields already have the same reach (the PRO-3434 threat model
lists them). It is a misconfiguration risk, not a visitor-only exploit.
**Optional hardening:** also refuse the `smaily_rec_` and `abandoned_cart_`
prefixes in `contact()`, and pin it in `FormSubscriptionTest`.

## 8. INFO — the Elementor action's editor surface, errors and export hold

- **Workflow list:** fetched only for `is_admin() && current_user_can(
  'edit_posts' )` (`form-action.class.php:181`). A visitor's submission runs
  through `admin-ajax.php`, where `is_admin()` is true, but an anonymous
  visitor fails the capability check, so no Smaily read happens on a
  submission. The list (active workflow ids and names) is cached for 5
  minutes in one shared transient; it is the same data `/smaily/v1/autoresponders`
  already gives `edit_posts` users (PRO-2350).
- **Who can point a form at the store's Smaily account:** any user who can
  edit an Elementor page. The workflow setting is a SELECT, but Elementor does
  not enforce SELECT options on save, so an author can store any workflow id.
  That still reaches only the store's own Smaily account, and with
  `force_opt_in = false`. This matches the PRO-2350 trust level for
  `edit_posts`; noted for completeness.
- **Errors:** the visitor sees two fixed, translated strings. The editor's
  admin-only line is `'Smaily: ' . error()`. `error()` is built from the
  Smaily client's exception text (HTTP status, method, endpoint, Smaily code,
  or the transport error) or the plugin's own "code %d" line. None of these
  carries a credential, the submitted email or any other visitor text. So even
  if Elementor renders admin messages as HTML, nothing visitor-controlled
  reaches them.
- **Logs:** the two `DebugLog` lines carry the workflow id and the exception
  text, not the email (as DECISIONS PRO-3806 says).
- **Export:** `on_export()` drops `smaily_workflow_id`. No control holds a
  credential: the action uses the saved connection.
- **Double-submit transient:** keyed on `md5( form id | form name | email )`,
  finite 5-minute TTL (not autoloaded), written only when a workflow is
  configured. A flood of distinct addresses makes one short-lived row each,
  cleaned up by WordPress's expired-transient sweep. The transient is set
  before the trigger, so a trigger that fails is not retried within 5 minutes.
  That is a functional edge only.

## 9. INFO — the storefront call: gating, escaping and the cache key hold

- **Gates:** logged-in user, `sending_allowed()` (connected and not refused,
  PRO-1893), and `may_profile()` checked **before** the cache read, so an
  opt-out takes effect on the next render even inside the 1 h cache.
- **Identity:** the engine is asked by `customer_external_id` = the WP user
  id, never by email. The contract (§15) answers an id that several customers
  share in the tenant with an empty list, so a user-id collision across stores
  on one tenant cannot show another person's recommendations.
- **What is kept from the engine:** only `rec_id` (must pass
  `RecId::is_valid()`) and a product id (digits only, from `external_id` or a
  `woo-<digits>` sku via `SkuResolver::product_id_from_key()`). Names, prices
  and images come from WooCommerce. A product that `is_visible()` refuses
  (unpublished, hidden, out of stock while hidden) is skipped.
- **Escaping:** the link is `esc_url()` over `add_query_arg()`, the name is
  `esc_html()`, the price is `wp_kses_post()`, and the image is
  `WC_Product::get_image()` (escaped by WooCommerce). The heading is a fixed
  translated string.
- **Cache:** `smly_rec_storefront_` + `md5( tenant_id | user_id )`, 1 h TTL
  (a finite-TTL transient, not autoloaded, PRO-2435). A user cannot read
  another user's entry, and a reconnect to another tenant uses new keys.
- **Editor:** the block's editor view is a static placeholder (no
  `ServerSideRender`), so an author does not trigger the call. The shortcode
  renders the **viewer's** recommendations, never the author's.
- **Logs:** an engine failure logs the exception class name only.

## 10. INFO — the Smaily queue changes are consent-positive and lose nothing silently

- **PRO-3627:** the profiling write and the abandoned-cart purchase marker now
  read the contact first and write only when Smaily has it, which closes the
  "write creates a subscribed contact" path for both. A failed read writes
  nothing: the profiling path logs it and the next read-back reconciles; the
  purchase-marker row takes the normal retry path. A not-found contact closes
  the marker row as a skip with a specific Event Log note
  (`NOTE_NOT_A_CONTACT`), so it stays observable. The window between the read
  and the write is negligible.
- **PRO-3750:** a 203 envelope fails the row (`permanent_envelope_203: …`
  with Smaily's message) instead of marking it sent or retrying it forever.
  Every other non-101 code on HTTP 200 still marks a `Flusher` row sent; that
  is unchanged and documented in the class docblock (pre-existing). A failed
  row is deleted, not redacted, by an erasure request (it is in
  `STATUSES_SENDABLE`), so the stored Smaily message cannot outlive one.
- **PRO-3796:** `over_10_products` prefilled `''` is a field clear on the
  reminder row; it subscribes no one and sends no new data.
- **#164:** `has_contact()` is a thin wrapper over the existing
  `get_contact_consent()` read (same `GET contact`, same exception);
  `CODE_OK` replaced literal `101`s one for one; `request_url()`'s
  `$single_attempt` reproduces exactly the earlier browse behaviour (one
  attempt, 3 s, `redirection => 0`, no Retry-After sleep); `rec_client()` now
  also passes `$settings` explicitly, which is the same instance the default
  built. No security property moved or weakened.

## 11. INFO — catalog import on connect, admin copy and the attribution cookie rule add no new surface

- **PRO-3743:** no new route. The import starts only inside the
  `manage_options`-gated setup-exchange route, after a successful (host-checked)
  exchange; it sends catalog data, which is not personal data, only to the
  allowed host; and `process_batch()` still gates on `sending_allowed()`. The
  hold-back is the existing `/backfill/cancel` (same capability), which
  unschedules the delayed tick. `AbstractBackfillJob::read_state()` is the
  same prepared `SELECT` that moved out of `BackfillEndpoint` (table name from
  `$wpdb->prefix` + a constant, `job_type` / `target` as `%s`).
- **PRO-3707 / PRO-3673 / PRO-3609:** React only, plus one boolean
  (`consentApiPresent` = `function_exists( 'wp_has_consent' )`) in the
  boot payload. The admin delta has no `dangerouslySetInnerHTML`. Its two new
  links are static (`https://smaily.com/help/`,
  `https://wordpress.org/plugins/wp-consent-api/`) with
  `rel="noopener noreferrer"`. All engine-supplied text renders as React text.
  The new `.po` strings carry no markup; the two `%s` placeholders are filled
  in React.
- **Context cookie rule (PRO-3788):** both writers clear `smaily_rec_ctx` only
  when the same landing wrote a valid `smaily_rec`. The guarded
  `utm_content` fallback never clears, and a non-UUID `smaily_rec` touches
  neither cookie. The PHP delete uses the same path and domain as the PHP
  write. A visitor could always set or clear their own attribution cookies;
  nothing new is exposed. Capture stays consent-ungated and connection-gated,
  as decided in F3-46.

## 12. INFO — open Dependabot alerts on `blocks/package-lock.json` (build tooling, not shipped, not new)

GitHub reports 10 open Dependabot alerts on `main` (1 critical, 5 high, 2
moderate, 2 low), all `npm`, all in `blocks/package-lock.json`, all
**development** scope: `form-data` (critical), `tar-fs` ×4 and `ws` (high),
`webpack-dev-server` ×2 (moderate), `on-headers` and `cookie` (low). They come
through the blocks' `@wordpress/scripts` build chain. None of them is in the
release ZIP: `.zipignore` drops `blocks/node_modules`, and only
`blocks/*/build/*` ships. The delta did not add them; its lockfile change is
the `recommendations` workspace link, and the new workspace declares the same
`@wordpress/scripts` `^27` range the other blocks already use. They run on
developer machines and in the CI build jobs (`npm ci --prefix blocks`), where
the dev server and the tar extraction paths these advisories describe are not
used. **Optional, separate from the release:** bump `@wordpress/scripts` (or
add `overrides`) in the blocks workspace and close the alerts.

---

## Confirmed clean (checked, nothing to report)

- **No new or changed REST route, `permission_callback`, nonce model or
  crypto.** Grep over the added production lines: no `register_rest_route`,
  `permission_callback`, `$_GET` / `$_POST` / `$_REQUEST`, `wp_remote_*`
  (besides the existing transport inside `Client::request_url()`), and one
  new `current_user_can` (Info 8). The only new `$wpdb` use is the moved,
  prepared `read_state()`.
- **`/relay` is narrower:** `external_id` is removed from `EVENT_FIELDS`
  (spoofed binding of anonymous browsing to a guessed user id), the per-IP
  limit always applies, and the forward cannot hold a worker longer than 3 s.
  The 502 body still echoes the client's exception message, as before; that
  text is the engine's error code and message or the transport error, never
  the key.
- **No secret reaches the browser or a log:** the engine key stays
  server-side (the §15 call is server-rendered); the Elementor action has no
  credential control; the new `DebugLog` lines carry no email or key.
- **No new outbound destination.** The §15 call and the import go to the
  stored engine host, which a new connection can only set to
  `intelligence.smaily.com`. The Elementor action uses the saved Smaily
  account.
- **No new table, migration or autoloaded option.** New data at rest: three
  finite-TTL transients (storefront slots, Elementor dedupe, Elementor
  workflow list), plus three new fields written to the Smaily contact (not
  stored in WordPress).
- **`docs/site/index.html`** is merchant documentation, excluded from the
  ZIP. Skimmed the new Elementor and recommendations sections: no
  credentials.

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/Smaily/RecEngine/SetupExchange.php`, `ExchangeResult.php`, `includes/REST/RecEngineEndpoint.php`, `includes/REST/EndpointRegistry.php` | **in** | host check, setup route, import on connect (§3, §11) |
| `includes/Smaily/RecEngine/Client.php` | **in** | browse single attempt, §15 call, timeouts, redirects (§3, §5, §10) |
| `includes/REST/BeaconEndpoint.php` | **in** | public `/relay` (§4) |
| `includes/Integrations/WooCommerce/StorefrontRecommendations.php`, `blocks/recommendations/**`, `includes/smaily-blocks.class.php`, `includes/smaily.class.php`, `includes/Bootstrap.php` | **in** | new page-render engine call, cache, escaping (§2, §5, §9) |
| `includes/Integrations/WooCommerce/LandingCapture.php`, `public/js/lib/attribution.ts` | **in** | attribution cookie rule (§11) |
| `includes/Integrations/Elementor/FormSubscription.php`, `integrations/elementor/form-action.class.php`, `integrations/elementor/admin.class.php` | **in** | new public write path, consent, editor gate (§1, §6–§8) |
| `includes/Privacy/ProfilingConsent.php`, `includes/Smaily/Client.php`, `includes/Smaily/Flusher.php`, `includes/Smaily/CartFlusher.php`, `includes/Smaily/RetryPolicy.php`, `includes/Smaily/CartPayloadBuilder.php`, `includes/Smaily/TransactionalFlusher.php`, `includes/Integrations/WooCommerce/HookHandler.php` | **in** | consent writes, terminal failures (§10) |
| `includes/Smaily/RecEngine/Backfill/*`, `includes/Smaily/BackfillJobInterface.php`, `includes/REST/BackfillEndpoint.php` | **in** | SQL move, scheduling (§11) |
| `includes/Smaily/RecEngine/Support/SkuResolver.php`, `includes/REST/WorkflowsEndpoint.php`, `includes/Wizard/EnvDetector.php`, `includes/Notifications/NotificationManager.php`, `includes/Integrations/WooCommerce/StorefrontBeacon.php` | **in** | helpers moved or exposed; boot-payload boolean (§9, §11) |
| `admin/src/**` | **in** | admin render (§11) |
| `bin/*` | out | dev tooling, not shipped (`bin/exchange-setup-token.php` comment only) |
| `STATUS.md`, `CLAUDE.md`, `README.md`, `docs/**`, `languages/**`, `blocks/package*.json` | out | no executable plugin content (`.po` strings skimmed, §11) |
| `tests/**` | out | not shipped; read for what is pinned |

## Gates run for this pass

Documentation-only pass. No code was changed, so `ci:strict`, the integration
suite and PCP were not run here. The release gate covers them (CI-built ZIP,
`bin/verify-release-zip.sh`, PCP).

## Follow-ups this audit leaves open

1. **Finding 1 (Low): Erkki to decide — fix before 3.16.0, or accept.** Keep
   `elementor_form_url` only when its host is the store's own host (or store
   the path); pin both cases.
2. **Finding 2 (Low): Erkki to decide — fix before 3.16.0, or accept.**
   `DONOTCACHEPAGE` (+ `nocache_headers()` when possible) when the
   recommendations block renders cards; one assert.
3. **Finding 3 (Info, optional):** `redirection => 0` on the setup exchange
   and on `request_url()`.
4. **Finding 4 (Info, optional):** key the `/relay` per-IP counter on the /64
   prefix for IPv6.
5. **Finding 5 (Info, optional):** render nothing on a profiling-cache miss
   and warm it in the background; cap `usable_slots()` at `LIMIT`; no
   redirects on the §15 call.
6. **Finding 6 (Info, docs):** recommend Elementor's honeypot / reCAPTCHA and
   a double opt-in workflow in the Elementor docs (EN+ET).
7. **Finding 7 (Info, optional):** refuse the `smaily_rec_` and
   `abandoned_cart_` prefixes as mapping targets.
8. **Finding 12 (Info, optional):** close the 10 dev-scope Dependabot alerts
   in the blocks workspace (bump `@wordpress/scripts` or add `overrides`).
