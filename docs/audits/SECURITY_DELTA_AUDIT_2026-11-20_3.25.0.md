# Security delta audit — before 3.25.0 (3.24.1..e8c6464)

Follows [`SECURITY_DELTA_AUDIT_2026-11-19_3.24.1.md`](SECURITY_DELTA_AUDIT_2026-11-19_3.24.1.md)
and the 3.24.1 release-gate row in [`INDEX.md`](INDEX.md). Both stay valid for
what they covered. This one covers everything that landed on `main` after the
3.24.1 tag, ahead of the 3.25.0 release.

- **Date:** 2026-11-20
- **Baseline:** tag `3.24.1`. Delta `3.24.1..e8c6464` on `main`: 15 commits,
  77 files, +6 946 / −1 601. The shipped code (PHP, the admin bundle source
  without its tests, CSS, the feed template) is **33 files, +2 271 / −186**;
  the rest is tests, tooling, the contract copy, STATUS / DECISIONS /
  LESSONS / CLAUDE.md and the docs site.
  - **#373 (CON-175) — "Try Intelligence free":** two admin REST routes,
    `GET /rec-engine/onboarding/status` and `POST /rec-engine/onboarding/start`
    (`REST\OnboardingEndpoint`), which send the main Smaily account's API
    login to the engine's §21 start from the server; the admin opens the
    one-time link in a frame or a new window and posts the setup token it
    receives to the existing setup-exchange route. `RecEngine\Client` gains
    two key-less calls.
  - **#374 — the review fixes for #370 / #373:** the shared `AdminSession`
    trait (session, `wp_rest` nonce in `X-WP-Nonce`, `manage_options`), no
    redirect for the onboarding calls, the start's `display`/`console_url`
    checks, the throttle.
  - **#370 / #371 (CON-350 / CON-351) — banners:** the Campaign Intelligence
    banner on Settings (closed through `POST /notices/dismiss`,
    `REST\NoticesEndpoint`) and the Smaily invitation banner on the Dashboard
    (`NotificationManager::render_invite_banner()`, own stylesheet); the boot
    payload gains `banners`.
  - **#375 (CON-353) — product RSS feed:** the description is sent as the
    store's HTML (`wp_kses_post()`) and the title as plain text, both inside
    CDATA through `Rss::cdata()`; dates in RFC 2822 (`DATE_RSS`); links with
    `esc_url_raw()`.
  - **#366 (CON-68):** the My Account registration form's profile fields are
    saved (`Profile_Settings::smaily_save_registration_fields()`).
  - **#367 (CON-348):** `Helper::get_current_url()` reads the request target
    with `esc_url_raw()`; the RSS Limit field is capped at 100 in the admin.
  - **#362 (CON-337) / #365 (CON-349):** two log lines and one stored state
    no longer carry the database's error text.
  - **#368 / #369 (CON-314):** the admin bundle reads WordPress core's React
    (`react`, `react-dom`, `react-jsx-runtime` handles); the `type="module"`
    filter is gone. #363: a recommendations hint in the admin.
  - **#364 — tooling only:** `bin/lib-smly-snapshot.sh` and
    `bin/restore-smly-rec-options.php` also snapshot and restore the dev
    site's Smaily settings around an integration run. Not shipped.
  - **#361, #372** — STATUS and a byte-identical contract sync (v1.34.0).
- **Auditor:** Claude (Opus 5.5).
- **Method:** static read of the shipped diff and the code it reaches (the
  REST registration and permission callbacks, `RecEngine\Client::request_url()`,
  `ApiException`, the admin bundle's start window and message handling, the
  feed's query and template, the registration save path, the tooling
  scripts' file handling), against contract §21 and DECISIONS CON-175 /
  CON-350 / CON-351 / CON-353. Two earlier partial reviews exist: the banners
  + self-onboarding review, whose fixes are #374, and none of #375. The whole
  delta was read again as merged.

## Verdict

**0 Critical, 0 High, 0 Medium, 0 Low, 6 Info.** No defect found. **RESULT:
3.25.0 may proceed** once the release gate (local ZIP, VERIFY, Plugin Check)
is green.

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| I1 | Info | `integrations/woocommerce/rss.class.php:219` | `plain_text()` strips tags and then decodes entities, so a title stored as `&lt;b&gt;` reaches the feed as the characters `<b>`. The feed calls the title plain text and Smaily shows it as text; the value is the store staff's own title, the same trust as before | No — accepted |
| I2 | Info | `integrations/woocommerce/rss.class.php:182`; `public/template/smaily-rss-feed.php:37` | The public feed now carries the description HTML (kses-filtered) instead of escaped text. Reach is unchanged: published, non-password products only (CON-345); a product hidden from the catalog is still listed, as before this delta, and its description is public on its own page. The page is `application/xml`, so a browser renders the CDATA as text, never as HTML | No — accepted |
| I3 | Info | `public/template/smaily-rss-feed.php:33`, `:37` | A control character (U+0000–U+0008 etc.) in a title or description makes the XML ill-formed and Smaily cannot read the feed. Not new (`esc_html()` kept them too); availability only | No — backlog candidate |
| I4 | Info | `admin/src/components/banners/IntelligenceStart.tsx:76` | The start window is opened without `noopener` so it can post the setup token back; the page in it (the engine's start, on `intelligence.smaily.com` only, checked server-side) can therefore reach `window.opener`. The frame has no `sandbox` (§21 asks for none). Both trust the one engine host the plugin already trusts with the connection | No — accepted |
| I5 | Info | `includes/Smaily/RecEngine/Client.php:892` | A `403 tenant_inactive` answer to a key-less §21 call would set `smly_rec_refused_at` on a store that has no connection. The engine does not answer §21 that way; a later setup exchange clears the flag | No — accepted |
| I6 | Info | `integrations/woocommerce/profile-settings.class.php:228` | The registration save writes user meta under the keys `smaily_connect_account_fields` returns (a developer filter), after the `woocommerce-register` nonce and `sanitize_text_field()`. A filter that adds an unsafe meta key is the developer's code, as for the existing account-details save | No — accepted |

## Confirmed clean

- **Onboarding routes, auth:** both routes use `AdminSession::admin_check()` —
  logged in, a `wp_rest` nonce in `X-WP-Nonce` verified in the callback
  itself, `manage_options`. `display` is an enum. No anonymous path reaches
  them; `EndpointRegistry` lists them with the other admin routes.
- **Onboarding, the Smaily password:** it goes only into the server-to-server
  JSON body to `https://intelligence.smaily.com/api/v1/onboarding/start`
  (`OnboardingEndpoint::ENGINE_BASE_URL`, a constant). The client carries no
  key (no `Authorization` header), sets `redirection => 0` for both §21
  calls (a redirect cannot resend the body elsewhere), and fires no
  `RESULT_HOOK`. `request_url()` writes no log line; the `ApiException`
  message is never returned — `refusal()` maps the status and code to fixed
  answers. Nothing is stored except the `'1'`/`'0'` status transient and the
  site-wide throttle counter.
- **Onboarding, what reaches the browser:** `start_url` only when
  `SetupExchange::is_allowed_engine_url()` passes (https, the engine host, no
  port, no user info); `console_url` under the same check; `engine_origin`
  from that URL. Answers carry `Cache-Control: no-store, private`.
- **Start window messages:** both listeners compare `event.origin` with the
  engine origin from the server; the setup link is accepted only as
  `<origin>/setup/…` and goes to the existing setup-exchange route, which
  re-checks the host and the reply (PRO-3623, CON-289). One exchange at a
  time (`exchanging`). The frame has `referrerPolicy="no-referrer"`.
- **Banners:** `POST /notices/dismiss` takes one allowed key
  (`ci_invitation`) behind `admin_check()`; anything else is `400
  unknown_key` and nothing is written. The Dashboard banner prints fixed
  strings through `esc_html__()`, URLs through `esc_url()`, static SVG
  through `wp_kses()` with a fixed allow-list; it shows only where the
  invitation already showed (capability-gated `render()`). `banner_boot()`
  builds `smailyLoginUrl` from a subdomain `main_credentials()` has matched
  against ReportBuilder's pattern; the boot payload reaches only the
  plugin's own admin screens. External links have `rel="noopener noreferrer"`.
- **Feed CDATA:** `Rss::cdata()` splits every `]]>`, so neither the title nor
  the description can end the section. `esc_url_raw()` cannot emit `>`, so a
  link cannot either. `wp_kses_post()` drops `<script>`, event attributes and
  `javascript:` URLs before Smaily's editor receives the HTML. The shortcode
  run (`do_shortcode`) is unchanged and runs as the anonymous visitor.
- **Registration fields (CON-68):** runs on `woocommerce_created_customer`
  only with a valid `woocommerce-register` nonce, which the checkout's
  account creation does not carry; the newsletter box keeps its own consent
  path; hidden-at-registration fields are skipped.
- **Return address (CON-348):** scheme, host and port still come from
  `home_url()`; only the path and query of the `esc_url_raw()`-cleaned target
  are kept, so `//other.example/x` still yields `/x`. This closes the 3.24.1
  audit's I1.
- **Log text (CON-337, CON-349):** the contact import's start line logs
  `database_error=yes|no`; the order-date index stores and logs an
  `ERROR_*` code; no database text remains in either.
- **Core React (CON-314):** the bundle declares the three core handles as
  dependencies; WordPress 6.6 (the plugin's floor) registers all three.
  `bin/check-bundle-scope.sh` fails the build if React is inlined again.
- **Tooling (#364, not shipped):** the settings snapshot is written under
  `umask 077`, `chmod 600`, in `~/.local/state/smaily-connect/`; it is piped
  over STDIN into the container; the scripts print counts and the
  GOOD/FIXTURE/EMPTY state only, never a credential. The classifier reads
  the subdomain only and unserializes with `allowed_classes => false`. The
  restore writes only names inside its set. `bin/` is excluded from the ZIP.

## Release gate — still to run on the bump branch

Local ZIP build → `bin/verify-release-zip.sh … 3.25.0` → Plugin Check 2.2.0 on
the built ZIP (expected since CON-314: 0 ERRORS and the accepted WARNINGS).
Recorded as the 3.25.0 release-gate row in `INDEX.md`.

## Conclusion

The new admin routes, the onboarding call that carries the Smaily password,
the banners and the feed's HTML description are sound as merged. The six
Info notes need no change before the release; I3 is a backlog candidate.
