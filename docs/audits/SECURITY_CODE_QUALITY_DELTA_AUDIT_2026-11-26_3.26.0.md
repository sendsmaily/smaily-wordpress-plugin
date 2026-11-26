# Security + code-quality delta audit — before 3.26.0 (3.25.0..54599ea)

Follows [`SECURITY_DELTA_AUDIT_2026-11-20_3.25.0.md`](SECURITY_DELTA_AUDIT_2026-11-20_3.25.0.md)
and the 3.25.0 release-gate row in [`INDEX.md`](INDEX.md). Both stay valid for
what they covered. This one covers everything that landed on `main` after the
3.25.0 tag, ahead of the 3.26.0 release.

- **Date:** 2026-11-26
- **Baseline:** tag `3.25.0`. Delta `3.25.0..54599ea` on `main`: 14 commits,
  110 files, +9 106 / −1 271. The shipped code (PHP, the admin bundle source
  without its tests, the new stylesheet, `admin/wizard.php`) is **49 files,
  +2 723 / −509**; the rest is tests, the contract copy, the catalogs,
  STATUS / DECISIONS / CLAUDE.md and the docs site.
  - **#385 (CON-455):** two admin REST routes,
    `POST /rec-engine/automations/test-send` and `POST /rec-engine/automations/go-live`
    (`REST\AutomationSendsEndpoint`), which call contract §19 / §20 with one
    attempt; `RecEngine\Client` gains `automation_test_send()` and
    `automation_go_live()` (`{key}` substituted, never sprintf'd); the boot
    payload gains `adminEmail`.
  - **#387 (CON-427):** the PUBLIC `/relay` route reads the shopper's
    WooCommerce session row (`WC_Session_Handler::get_session_cookie()` +
    `get_session()`) to key a variable product's cart event on the variation
    the cart took; the product page view is keyed on the default (else first
    published) variation.
  - **#388 (CON-431 part A):** the §9 deletion and the §10 opt-out / opt-in are
    rows in `smly_rec_event_queue` (`privacy.delete` / `privacy.opt_out`),
    sent by `RecEngine\PrivacyRequests` on its own Action Scheduler action
    within 10 requests a minute; the address sits in `payload` until the
    request ends, `entity_id` is a keyed hash; a 401 / 403 holds every row
    until a new key.
  - **#389 (CON-431 settings):** a Connection save that replaces a Smaily
    account removes that account's workflow mappings (and the legacy
    abandoned-cart autoresponder id); `Settings\Subdomain` reduces a pasted
    account address to its label and refuses anything that is not one DNS
    label (save and test route); the contact import writes an Event Log
    `contact.import` skipped row per refused contact.
  - **#382 (CON-449):** storefront recommendation cards get an add-to-cart
    link to the cart page carrying `smaily_rec` + `smaily_ctx`;
    `LandingCapture::capture()` also runs on `woocommerce_add_to_cart`; a new
    public stylesheet.
  - **#379 (CON-439), #386 (CON-386), #381 (CON-395):** a guest's checkout
    tick reaches Smaily in every mode; the first-order email asks the shared
    `Smaily\AutomationConsent` rule (extracted from the cart flusher); a
    contact sync that would put a stale subscription into Smaily closes as
    skipped (`Smaily\StaleSubscription`), and its single Retry is refused.
  - **#384 (CON-354):** the §4 customer row's `language` comes from
    `ContactLanguageResolver::for_user()`, not `get_user_locale()`.
  - **#378 / #383:** contract syncs (v1.37.1, v1.37.2); the personal-data
    export lists `stock_alert_requests` and `identifiers`.
  - **#380 (CON-441):** the setup's Connect step counts the fallback account
    in the account-per-language mode (admin bundle only).
  - **#390:** the test-send form says the test shows the design the Smaily
    workflow holds (contract §19's required notice). Merged while this audit
    ran; included.
- **Auditor:** Claude (Opus 5.5).
- **Method:** static read of the shipped diff and the code it reaches (REST
  registration and permission callbacks, `AdminSession`, `RecEngine\Client::
  templated_url()` / `request_url()`, `ApiException::summary()`, the relay's
  handler order and rate limit, WooCommerce's session handler, `IngestQueue`
  pending / delete / prune paths, `QueueJanitor` retention, `uninstall.php`,
  the Event Log list and detail projections, the settings save and the
  connection test), against contract §9 / §10 / §19 / §20 and DECISIONS
  CON-427 / CON-431 (both entries) / CON-439 / CON-386 / CON-395 / CON-449 /
  CON-455. PHPCS / PHPStan suppressions added in the delta were listed and
  checked. A release ZIP was built from `54599ea` and verified; Plugin Check
  could not run (see "Release gate").

## Verdict

**0 Critical, 0 High, 0 Medium, 2 Low, 8 Info.** No security defect that
needs a fix before the release. **RESULT: 3.26.0 may proceed once its release
gate is green** — Plugin Check against the built ZIP did not run in this
audit and must run on the bump branch. L1 and L2 are cheap fixes; whether they
go in before the cut is the orchestrator's call.

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| L1 | Low | `includes/REST/BeaconEndpoint.php:609`, `:649` | The public `/relay` reads and unserializes the shopper's WooCommerce session row once per cart event, not once per request | Recommended (small) |
| L2 | Low | `includes/REST/TestConnectionEndpoint.php:103`, `:133`; `includes/Settings/CredentialSet.php:48` | The connection test compares the normalized subdomain with the stored one as written, so a stored subdomain with a capital no longer reuses the stored password | Recommended (small) |
| I1 | Info | `includes/REST/AutomationSendsEndpoint.php:66` | `KEY_PATTERN` ends in `$`, which in WordPress's pattern check also accepts a trailing newline | No — accepted |
| I2 | Info | `includes/Smaily/RecEngine/PrivacyRequests.php:131`, `:167` | A waiting deletion keeps the erased address in plain text for as long as sending is not allowed; a store that never reconnects keeps it until uninstall | No — accepted in DECISIONS |
| I3 | Info | `includes/Smaily/RecEngine/PrivacyRequests.php:149` | A row that cannot be read still takes a slot of the 10-a-minute window | No — accepted |
| I4 | Info | `includes/REST/AutomationSendsEndpoint.php:127` | The test send has no plugin-side limit; the address the admin types joins the store's Smaily list | No — by design |
| I5 | Info | `includes/Smaily/Client.php:338` | The new subdomain rule guards saves and tests only; a value stored before it is used as stored | No — backlog candidate |
| I6 | Info | `includes/REST/EventsEndpoint.php:682` | The Event Log list runs one indexed query per failed contact-sync row on the page | No — accepted |
| I7 | Info | `includes/Integrations/WooCommerce/StorefrontRecommendations.php:306` | The card button is a GET that adds to the cart | No — accepted |
| I8 | Info | `includes/REST/BeaconEndpoint.php:654` | `get_session_cookie()` checks the cookie's hash but not its expiry | No — accepted |

### L1 — the relay reads the session row once per cart event

`resolve_cart_product_skus()` calls `cart_variation_id()` for every
`cart_add` / `cart_remove` event that names a variable product. Each call
builds a `WC_Session_Handler`, runs one `SELECT session_value` against
`woocommerce_sessions` (the object cache is skipped: the handler's expiry is
unset, so `get_session()` caches nothing) and `maybe_unserialize()`s the
session and the whole `cart` / `removed_cart_contents` value. A batch may hold
`MAX_EVENTS` (100) events and the route takes 120 requests a minute per
address (`RL_MAX_PER_IP`), counted per request, not per event.

**Failure scenario:** a client opens one guest session, fills its cart with
many lines, and posts batches of 100 `cart_add` events naming a variable
product. Each request then runs 100 session reads and 100 unserializations of
the same large cart, so one address can drive about 12 000 of them a minute
on a public, unauthenticated route. Before this delta each such event cost one
`wc_get_product()`, which the object cache absorbs. Availability only — the
reads return the client's own session, and nothing read reaches the response.

**Recommended fix:** read the session once per request — resolve the two
line lists on the first variable-product event and reuse them for the rest of
the batch (a local memo in `resolve_cart_product_skus()`). Optionally skip the
read when the batch has no variable product.

### L2 — the connection test no longer reuses a stored password for a stored subdomain with a capital

`TestConnectionEndpoint::handle()` now normalizes the typed subdomain
(`Subdomain::normalize()`: trimmed, reduced, lower case) and then reuses the
stored password only when `CredentialSet::matches()` finds the stored
subdomain identical. The stored value is compared as it was saved. Saves
before this delta stored `sanitize_text_field()` output, which keeps
capitals.

**Failure scenario:** a store that saved `Demo` presses "Test connection"
(Settings or the setup's Connect step, which does not put the password in the
browser — PRO-2286). The typed value becomes `demo`, `matches()` fails, and
the test answers "Subdomain, username, and password are required." The
merchant must retype a password they may no longer have. The settings save
itself is not affected: `replaces_account()` normalizes both sides, and a save
then stores `demo`.

**Recommended fix:** compare normalized values in the reuse check —
`Subdomain::normalize( $stored->subdomain ) === $subdomain` (or normalize
inside `CredentialSet::matches()`), with a unit case for a stored capital.

### Info notes

- **I1:** WordPress checks a schema `pattern` as `preg_match( '#…#u' )`, and
  `$` also matches before a final newline, so `trigger_key` `abc\n` passes.
  `templated_url()` encodes it (`abc%0A`) and the engine answers `404
  unknown_automation`, which the route maps to the same code. No effect; `\z`
  would make the pattern exact.
- **I2:** DECISIONS "CON-431 (privacy requests)" accepts it: rows wait while
  `sending_allowed()` is false or the key is the refused one; the janitor never
  prunes `pending` rows; uninstall drops the table. The address is the minimum
  needed to carry the deletion to the engine, and it never reaches the Event
  Log, a debug line or `sent_payload`.
- **I3:** `run()` counts a request before `send()`; a `not_built` row (no
  address in its payload) is closed without a call but keeps its slot.
  Reaching it needs a corrupt row; at worst one minute's window is one short.
- **I4:** `admin_check()` (session, `wp_rest` nonce, `manage_options`) gates
  the route; `is_email()` and a 254-character cap check the address; the
  engine limits §19 to 10 an hour per store and keeps no copy. The address
  becoming a contact of the store's Smaily list is the contract's behaviour,
  and both the form (`EngineAutomationsSection.tsx`) and `readme.txt` say so.
- **I5:** `Smaily\Client` builds `https://%s.sendsmaily.net/api/…` from the
  stored subdomain. A value saved before `Settings\Subdomain` (by an admin) is
  not re-checked. The rule could also run where a `Client` is built, so a
  stored value that is not one label sends nothing. Admin-entered and older
  than this delta; defence in depth only.
- **I6:** `shape_row()` asks `StaleSubscription::is_stale()` for each failed
  Smaily-queue row that subscribes; each ask is one `SELECT` on the
  `(event_type, contact_key, status)` index, bounded by the page size. The
  list query now also selects the payload of failed `contact.sync` rows as
  `retry_payload`; the projection does not return it to the browser.
- **I7:** `?add-to-cart=<id>` on the cart page is WooCommerce's own GET
  add; the link carries `rel="nofollow"`, which WordPress's speculative
  loading skips, so a prefetch does not add the product. It is shown only for
  products WooCommerce itself adds from a list.
- **I8:** an expired cookie whose session row the WooCommerce cleanup has
  not yet deleted is still read. The row is the cookie holder's own; nothing
  read is returned.

## Confirmed clean

- **Test send and test mode (CON-455):** both routes use `AdminSession::
  admin_check()`; `trigger_key` has a schema pattern, `live` / `confirmed` are
  booleans and going live needs `confirmed === true`; `switched_by` is the
  current user id only. The URL comes from the endpoints map or the fallback
  template with `{key}` rawurlencoded; the base URL is the stored connection,
  which the setup exchange restricts to `intelligence.smaily.com` (PRO-3623).
  One attempt (`EndpointRegistry` builds the client with `1`), so a §19 502 is
  never resent. Answers carry engine codes only, each filtered through
  `CODE_PATTERN`; never the engine's message, `details.from_address` or the
  typed address. The address is not stored, logged or put in an exchange;
  `request_url()` logs nothing with the URL. `adminEmail` reaches only the
  plugin's admin screens through `wp_json_encode()`.
- **Relay session read (CON-427):** the handler order is unchanged — the
  connection gate, the rate limit and the size guard run before
  `resolve_cart_product_skus()`; only `cart_add` / `cart_remove` events reach
  it. `get_session_cookie()` checks WooCommerce's HMAC of the customer id and
  expiry (keyed with the site's salt), so a forged cookie reads nothing and a
  valid one reads only its holder's session. No session is started, saved or
  destroyed, no cookie is set. The variation id found only selects which key
  `SkuResolver` builds; the response does not change. `maybe_unserialize()`
  runs on data WooCommerce serialized and itself unserializes on every page
  load — no new trust. A store with another session handler gets the old key.
- **Privacy queue (CON-431 A):** `entity_id` is `wp_hash()` of the
  lowercased address, cut to 16 hex characters; debug lines carry the event
  type, that id and `ApiException::summary()` (`http=… error=…`) or
  `DebugLog::where_with_code()` — never an address or a remote message. The
  Event Log detail blanks `payload` for both event types; the list query
  selects no rec-queue payload; `sent_payload` is the wire body (`{confirm,
  reason}` or `{opt_out, reason, opted_out_at}`), the address being in the URL
  path. `finish()` and the `not_built` path replace the payload with `{}`.
  Every other flusher's `pending()` is scoped to its own event types, so no
  ingest flusher picks up a privacy row. `delete_unsent()` replaces waiting
  rows by the hashed id only. A 401 / 403 stores a keyed hash of the key
  (`key_mark()`), never the key. Deactivation cancels `smaily-rec-privacy`;
  uninstall drops the table and sweeps `smly_rec_privacy_pace` with the
  `smly_rec_*` options.
- **Settings save (CON-431 settings):** every subdomain (default,
  per-language, transactional) is normalized and checked before anything is
  written; a refused value writes nothing. `Subdomain::is_plain()` allows one
  DNS label only, so no dot, slash, `@`, port or scheme can reach the
  `https://{subdomain}.sendsmaily.net` URL. The workflow deletes are
  `$wpdb->prepare()`d with the account key from `sanitize_key()`; leaving the
  account-per-language mode keeps the `default` and `transactional` rows. The
  route's auth is unchanged.
- **Refused contacts in the Event Log:** each row carries the WordPress user
  id and the refusal the plugin builds (`TerminalDispatchException`, the
  envelope code; addresses masked), never Smaily's message text (CON-301).
- **Consent (CON-439 / CON-386 / CON-395):** a guest is synced in consent or
  checkout-only mode only with the tick of that checkout; `include_guests()`
  now answers true only under legitimate interest, and its one caller
  (`ContactAudience`) reads it as "guests who did not tick". The first-order
  email asks `AutomationConsent::refusal()` before the workflow and the
  discount code: unsubscribed in Smaily → never; a consent mode → only with a
  subscribed contact, the order's own tick or the stored opt-in (consent mode,
  registered buyer); a failed Smaily read throws and the row retries, so
  nothing is sent blind. `newsletter_optin` and `user_id` come from the order,
  not from the request. The stale-subscription rule only withholds a
  subscribe; an unsubscribe is never withheld. Skip notes are fixed plugin
  texts.
- **Add-to-cart capture (CON-449):** the capture on `woocommerce_add_to_cart`
  is the same `capture()` (format checks in `resolve()`, connection gate,
  `headers_already_sent()`), run once per request (`$captured`). The
  stylesheet is a static file with a version; the card markup escapes every
  value (`esc_url`, `esc_attr`, `esc_html`, `wp_kses_post`).
- **Language (CON-354):** the customer row's `language` comes from the same
  resolver as the Smaily contact (clamped to the active languages, omitted
  when empty); no request-context read.
- **Export arrays (#378):** two more engine arrays are listed under fixed
  labels; WordPress's export renderer filters each value with `wp_kses()`.
- **Suppressions:** the delta adds two `phpcs:disable/enable` pairs
  (`WordPress.DB.PreparedSQL.InterpolatedNotPrepared` / `NotPrepared`) around
  queries whose only interpolation is the table name, with every value
  `prepare()`d. No `@phpstan-ignore`, `@ts-ignore`, `@ts-expect-error` or
  `eslint-disable` was added. No dead code found; `CartFlusher::REASON_*` stay
  as aliases of the shared constants.

## Release gate — ZIP built, Plugin Check not run

- **Local ZIP (not a release artifact):** built from `54599ea` per CLAUDE.md
  steps 2–6 in a worktree (`npm ci`, `build:admin`, block build,
  `WP_CLI_BIN=vendor/bin/wp bash bin/build-i18n.sh` on the host — the dev
  container was down; the regenerated `.pot` / `-et.po` held the same 798
  entries, all translated, and were reverted, only the `.mo` / `.json` build
  artifacts were kept — `--no-dev` vendor, `composer run package`, dev
  vendor restored). **ZIP 1 098 813 B, SHA256
  `d6a3e9e88085d991fff139a7be8c9c3f0a1bc3d50b94ced8f907ba634c3544d9`,
  build-hash `54599ea`, 438 entries, `bin/verify-release-zip.sh … 3.25.0`
  (from the repo root) VERIFY OK, 43 checks** (still version 3.25.0: no bump
  yet). `dist/admin/admin.js` in the ZIP 214 203 B (gzip −9: 57 320 B, under
  the 75 KB budget). The new `public/css/smaily-connect-recommendations.css`
  ships. The ZIP was deleted afterwards.
- **Plugin Check 2.2.0: NOT RUN.** The dev wp-env stack
  (`wp-env-connect-woo-5fca6546-*`) was stopped (exited two days earlier),
  and this audit does not start or stop wp-env stacks. The release gate on
  the bump branch must run it. **Expectation from the code:** 0 ERRORS /
  17 WARNINGS, the accepted set, with these lines moved by the delta —
  `DirectQuery` + `NoCaching` at `BackfillJob.php:755` (was 720),
  `IngestQueue.php:523` and `:552` (were 507 / 536), `QueueJanitor.php:298`,
  `EventsEndpoint.php:866` (was 806); `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:165`, `:236`, `HookHandler.php:312`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at
  `CartSessionStore.php:310`, `:334`, `GdprHandler.php:732` (was 719). The
  new queries (`EventQueue::later_delivered_payloads()`,
  `IngestQueue::forget_payload()`, `SettingsEndpoint::remove_workflows()`)
  sit under their file's `phpcs:disable` of `DirectQuery` / `NoCaching` with
  no earlier re-enable of those codes in the file, and the delta adds no
  dynamic hook name — so no new warning is expected. Only the run settles it.

## Conclusion

The new admin routes, the relay's session read, the privacy queue, the
settings save and the consent changes are sound as merged: no route widens
access, no address or remote message reaches a log, the Event Log or a
response, and no consent path sends without its basis. L1 (one session read
per request on the public relay) and L2 (the connection test's password reuse
after normalization) are small fixes. 3.26.0 may proceed once its release
gate — Plugin Check on the built ZIP included — is green.
