# Security pass — the two block-editor REST routes widened to `edit_posts` (PRO-2350)

- **Date:** 2026-09-07
- **Baseline:** `eac100b` (main tip at audit time). The change under review is
  `0057ed6` (PRO-2346, `/smaily/v1/configuration`) and `78ba231` (PRO-2347,
  `/smaily/v1/autoresponders`), both landed the same day.
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** point 2 — "a new/changed REST route … or
  auth/capability/nonce logic". The delta is ~37 lines, far under the
  ~2,000-line rule of thumb, but it changes a `permission_callback` on two
  routes, which is a named high-risk surface regardless of size.
- **Scope — deliberately narrow:** exactly these two routes. Everything else in
  the day's work (the landing-page block's move to server rendering, the Event
  Log retry fix, the docs-site pass) is out of scope; nothing else in the
  `smaily/v1` namespace exists, and no route in the separate
  `smaily-connect/v1` namespace was touched.

## Verdict

**PASS. 0 Critical / High / Medium / Low. 1 Info (accepted, no action).**

Both routes are read-only, both return only what the storefront already
publishes or what an automation is named, and both correctly refuse anyone
without `edit_posts`. The widening is honest: it hands a content editor
strictly less than the settings screen an administrator already sees.

## What the change is

`includes/smaily-api.class.php` registers exactly two routes under
`smaily/v1`. Its private `register_endpoint()` helper still defaults every
route to `manage_options`; both routes now pass an explicit
`permission_callback` of `current_user_can( 'edit_posts' )` in the per-route
`$args`, overriding that default for these two only. No other route in the
plugin changed, and the default for any future route stays `manage_options`.

The reason in both cases is the same defect: the block editor's sidebar fetches
these routes on every mount, and an Editor — the usual role for a marketing
user — got a refusal, so the landing-page block showed "Please configure the
plugin first" and the sign-up block never left its loading spinner, on a fully
connected store.

## 1. Response content — every field, enumerated

Read `API::get_configuration()`, `API::list_autoresponders()`,
`Options::get_api_credentials()`, `Helper::get_autoresponders_list()`,
`Helper::filter_enabled_autoresponders()` and `Smaily_Client::request()`, then
observed the real responses (§3).

### `GET /smaily/v1/configuration`

| Field | Value | Sensitivity |
|---|---|---|
| `subdomain` | The account's Smaily subdomain, e.g. `auditstore` | **Public already** — it is printed into the storefront's own HTML as the sign-up form's `action="https://{subdomain}.sendsmaily.net/api/opt-in/"` (`public/partials/smaily-public-basic.php`, `blocks/newsletter-signup/smaily-integration.class.php`, `integrations/elementor/newsletter-widget.class.php`) and into the landing-page block's `<iframe src>` (`blocks/landingpage/smaily-integration.class.php`). Any anonymous visitor of a page carrying a Smaily form reads it from view-source. |
| `settings_url` | `admin_url( 'admin.php?page=smaily-connect' )` | None — a predictable wp-admin URL derived from `home_url`, not a credential and not a capability. Following it still requires the settings page's own capability. |

**Nothing else is returned.** This is structural, not incidental: the handler
builds a literal two-key array and `array_merge`s a literal two-key array over
it, so extra keys stored in the credentials option cannot leak through. The
username is never read at all, and although `Options::get_api_credentials()`
does decrypt `password` into the array it hands back, `get_configuration()`
never reads that key. Confirmed on the wire — the observed body has exactly two
keys (§3).

### `GET /smaily/v1/autoresponders`

| Field | Value | Sensitivity |
|---|---|---|
| `value` | The automation's Smaily id, as a string (`"4321"`) | Low. Where a sign-up block already uses an automation, its id is published in the storefront markup as `<input type="hidden" name="autoresponder" value="…">`. |
| `label` | The automation's title in Smaily (`"Welcome series"`) | Low, and the one genuinely new thing an Editor learns — see §4. Free text authored by the merchant's own marketing team, rendered by the block into a `SelectControl` (React-escaped). |

**Nothing else is returned.** `list_autoresponders()` builds each row from
exactly two keys and never spreads the upstream row, so any other field Smaily
returns on `workflows.php` (`is_enabled`, and anything the API adds later) is
dropped rather than forwarded. Confirmed on the wire: the disabled automation
in the fixture is absent, and the enabled one comes back as `{"value","label"}`
only.

No credential, token, engine API key, rec-engine connection state, order, cart,
contact or any other store internal appears in either response.

## 2. Side effects and error paths

- **Both are GET-only.** `register_rest_route` receives `'methods' => 'GET'`
  for both; the live route table confirms `methods=["GET"]` and no second
  handler (§3). Neither handler writes an option, meta, transient, custom-table
  row or queue row: `get_configuration()` is two reads (`get_option` via
  `Options`, `admin_url`); `list_autoresponders()` is one credential read plus
  one outbound HTTP GET.
- **The upstream call is server-side with stored credentials.**
  `Helper::get_autoresponders_list()` returns `array()` immediately unless
  `Options::has_credentials()` is true, then constructs `Smaily_Client` from
  the stored option. The Basic `Authorization` header is built inside
  `Smaily_Client::request()` and never leaves it. Nothing in the path logs:
  there is no `error_log`, no `DebugLog`, and no exchange capture (the F3-44
  exchange store belongs to the namespaced `Smaily\Connect\Smaily\Client`, a
  different class this legacy route does not use).
- **Error paths carry nothing.** `Smaily_Client::request()` does put an
  `error` key (the `WP_Error` message) and a `code` key on its return value,
  but `get_autoresponders_list()` reads **only** `$result['body']` and returns
  `array()` when it is missing or empty. So a transport failure and an upstream
  4xx both surface to the caller as an empty list — no `WP_Error` message, no
  upstream status, no upstream body text, no exception message. Verified by
  stubbing both shapes (§3): a `WP_Error` carrying a hostname-bearing cURL
  message and a 401 body naming the API username both produced `[]`.
  (The flip side — a broken account and an account with no automations look
  alike to the merchant — is a UX observation, not a security one, and predates
  this change.)
- **No new outbound destination and no new data at rest.** The upstream call
  existed before, from the same handler, with the same credentials; only who
  may trigger it changed.

## 3. Access — exercised against the running wp-env

Driven through the real REST server on the wp-env **tests** site (so the dev
site's sandbox connection was never in play), with placeholder credentials
written and restored inside the run, the Smaily call answered by a
`pre_http_request` stub, and the probe users deleted afterwards. Anonymous
access was additionally confirmed over real HTTP with `curl` against the dev
site (8888) and the tests site (8889). No probe file, user or option value was
left behind.

| Actor | `/smaily/v1/configuration` | `/smaily/v1/autoresponders` |
|---|---|---|
| Anonymous (dispatch) | **401** `rest_forbidden` | **401** `rest_forbidden` |
| Anonymous (real HTTP, curl) | **401** `rest_forbidden` | **401** `rest_forbidden` |
| Subscriber (no `edit_posts`) | **403** `rest_forbidden` | **403** `rest_forbidden` |
| Editor | **200**, body `{"subdomain":"auditstore","settings_url":"…admin.php?page=smaily-connect"}` | **200**, body `[{"value":"4321","label":"Welcome series"}]` |
| Administrator | **200** | **200** |

The 401/403 split is WordPress core's own (`rest_authorization_required_code()`
returns 401 for a logged-out caller, 403 for a logged-in one), which is what
the acceptance criterion "the request is refused" asks for.

**Cookie without a nonce is treated as anonymous.** Curling both routes with a
valid `wordpress_logged_in_*` cookie for an Editor but **no** `X-WP-Nonce`
returned **401** on both — WP core's `rest_cookie_check_errors()` calls
`wp_set_current_user( 0 )` when no nonce is present at all. So a third-party
page cannot ride a logged-in editor's cookie to read the subdomain or the
automation names; the block's own `apiFetch` sends the nonce and is unaffected.

**REST index.** Both routes carry `show_in_index` at its default (`true`) — no
assumption is being hidden there. The anonymous `/wp-json/smaily/v1` and
`/wp-json/` listings show the two paths, their single `GET` method and an empty
`args` map, and nothing else: no subdomain, no automation names, no
description, no schema. That is ordinary WordPress route discovery, identical
to what the routes exposed when they required `manage_options`, and it leaks
nothing — the permission callback still runs on any actual call. Setting
`show_in_index => false` would hide the paths from discovery without changing
what anyone can read, so it is not recommended (security by obscurity, and it
would break `wp-json` tooling).

## 4. Residual exposure — accepted, and stated plainly

Anyone who may edit content on the store can now learn two things they could
not before:

1. **The store's Smaily subdomain.** Not a new exposure in any real sense — the
   storefront publishes it to anonymous visitors in the sign-up form's `action`
   URL and the landing-page block's `<iframe src>`. An Editor could already
   read it by viewing any page carrying a Smaily block, or by looking at the
   block's own attributes in a saved post.
2. **The names of the account's enabled `form_submitted` automations.** This is
   the one genuinely new item. The *ids* are already public wherever a sign-up
   block uses one (a hidden `autoresponder` input in the storefront markup); the
   *titles* were previously visible only to an administrator. They are marketing
   labels authored by the merchant's own team ("Welcome series"), not customer
   data and not a secret, and knowing one grants nothing: enrolling an address
   in an automation goes through Smaily's own opt-in endpoint with the
   subdomain and id that are already public.

The exposure is proportionate to the capability: `edit_posts` is the capability
to publish content on the store, and both facts are inputs to publishing a
Smaily block. Neither route hands over anything that would let an Editor act
beyond their role — no credentials to authenticate as the store, no way to send
mail, no contact data, no settings write.

## 5. Info (accepted, no action)

**I-1 — an Editor can trigger one upstream Smaily GET per `/autoresponders`
call, with no cache and no rate limit.** The route calls
`workflows.php?trigger_type=form_submitted` on every request, and the block
fetches it on every mount. Widening from `manage_options` to `edit_posts`
enlarges the set of authenticated users who can drive that loop. Accepted: the
caller must still be an authenticated content editor of this store, the request
is a single upstream GET with no body, and the same amplification already
existed for administrators. If the automation list ever needs it, a short
transient in `Helper::get_autoresponders_list()` is the fix — worth doing for
editor responsiveness rather than for security.

## Gates

Docs-only pass; no product code changed, so no `ci:strict` or integration run
is required by this audit. The two routes' behaviour is pinned by
`tests/Integration/LandingPageBlockConfigRouteTest.php` and
`tests/Integration/NewsletterBlockAutorespondersRouteTest.php`, both green in
the full integration run STATUS.md records for the day's last code commit
(270 tests / 1574 assertions, 1 pre-existing skip).
