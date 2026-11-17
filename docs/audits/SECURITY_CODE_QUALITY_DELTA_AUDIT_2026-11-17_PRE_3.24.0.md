# Security + code-quality delta audit — before 3.24.0 (3.23.0..3d0497a)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-13_AFTER_3.22.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-13_AFTER_3.22.0.md),
which covered `3.22.0..45b6b49`, and the 3.23.0 release-gate row in
[`INDEX.md`](INDEX.md). Both stay valid for what they covered. This one covers
everything that landed on `main` after the 3.23.0 tag, ahead of the 3.24.0
cut.

- **Date:** 2026-11-17
- **Baseline:** tag `3.23.0`. Delta `3.23.0..3d0497a` on `main`: 11
  commits, 98 files, +4 918 / −355. The shipped PHP in the delta is
  **37 files, +980 / −169** (`includes/` plus the checkout-optin block's
  Store API class), plus the admin bundle and the checkout-optin block's
  script (15 files, +271 / −20), `.po` / `.pot` strings and the docs site.
  - **#345 (CON-323) — connection prefix, contract v1.33.0:** the setup
    exchange declares `capabilities: ["connection_prefix"]` and reports the
    last connection's prefix as `connection.previous_prefix`; the answer's
    `config.connection.prefix` is validated (`''` or `c<N>-`) and stored in
    `smly_rec_connection_prefix` (autoload, kept on disconnect, swept on
    uninstall); the §2 ping updates it; every key on the wire gets it
    (`SkuResolver`, `OrderPayloadBuilder::external_order_id`); stored
    `catalog.delete` objects, `catalog.remove` ids and `/relay` browse skus
    are re-keyed under the current prefix at send time
    (`SkuResolver::reprefix()`); §3b `errors[]` (`connection_prefix_mismatch`)
    fails that row; storefront recommendations render by `external_id` only
    and drop a slot whose sku is not under the connection's prefix.
  - **#344 (CON-321) — invitation notice:** an `admin_notices` notice on
    every admin screen while no Smaily marketing account is connected
    (`ReportBuilder::main_credentials()`), with a Settings link, an external
    sign-up link (`Constants::SIGNUP_URL`), a docs link and a 90-day dismissal.
  - **#347 (CON-326 / CON-327):** the Settings save refuses an abandoned-cart
    cutoff above `CartAbandonmentSweeper::max_cutoff_minutes()` (1425 by
    default); the sweep's per-row backstop logs `DebugLog::where()` instead of
    the exception message.
  - **#348 (PRO-4263 / CON-325) — checkout:** the block checkout's newsletter
    box calls `extensionCartUpdate()`; the plugin registers a Store API update
    callback (`woocommerce_store_api_register_update_callback`, namespace
    `smaily-checkout-optin`) that records the tick on the cart tracker; the
    classic billing email gets `update_totals_on_change` and an inline
    `wc-checkout` script that triggers `update_checkout` on its change.
  - **#350 (CON-328) — payment reminder:** a fourth transactional email; one
    Action Scheduler single action per checkout order (hook
    `smly_plus_payment_reminder_check`, group
    `smaily-connect-payment-reminder`, args the order id only); a
    `transactional.payment_reminder` row only for an order still `pending` /
    `failed` with `needs_payment()`; merge field `payment_url`; delay setting
    5–1440 minutes.
  - **#351 (CON-330):** an order with no billing email is skipped by
    `OrderFlusher` with reason `no_billing_email` (`AbstractD6Flusher::
    $skip_reason`).
  - **#346 / #349** — refactors after simplification reviews (no behaviour
    change); **#341 / #343** — contract syncs to v1.32.0 / v1.33.0 (#341 also
    adds the §8 `outcome_events` section to the personal-data export);
    **#342** — STATUS only.
  - **Not in this audit:** PR #352 (`refactor/payment-reminder-tighten`, no
    behaviour change by its description) was open, not merged, when this
    report was written. Its diff was read: it moves the payment reminder's
    candidate test into `PaymentReminderCheck::is_candidate()` and shares two
    UI components; nothing in it changes a finding below.
- **Auditor:** Claude (Opus 5.5).
- **Trigger (re-audit policy):** point 1 (the 3.24.0 release boundary),
  point 2 (> 2 000 changed lines; security-sensitive surfaces: the public
  `/relay` route's payload, a new Store API write entry point, a new
  outbound field (`payment_url`), new stored options, a new background job
  that carries an order id, GDPR export scope, logging), point 3 (a new
  outbound link destination in wp-admin).
- **Scope:** read of the whole shipped diff and the code it reaches:
  `RecEngineSettings` (prefix accessors, `note_ping()`, `store()`,
  `disconnect()`), `SetupExchange::exchange()`, `ExchangeResult`,
  `RecEngineEndpoint::setup_exchange()` answers, `Client::ping()`,
  `SkuResolver`, `CatalogPayloadBuilder::with_current_prefix()`,
  `IngestFlusher::row_to_object()`, `CatalogRemoveFlusher`,
  `OrderPayloadBuilder::build()`, `OrderFlusher`, `AbstractD6Flusher`,
  `BeaconEndpoint::handle()` / `current_prefix_skus()` /
  `resolve_cart_product_skus()`, `StorefrontRecommendations::usable_slots()` /
  `product_id()` / `cards()`, `NotificationManager` (render, dismissal,
  links), `ReportBuilder::main_credentials()`, `Credentials::get()` /
  `option_keys()`, `CartHookHandler` (the three new callbacks,
  `session_token()`, `tracking_enabled()`),
  `CartSessionStore::set_newsletter_optin()`, the checkout-optin block,
  `HookHandler::on_checkout_block_optin()`, `PaymentReminderCheck`,
  `TransactionalEmailHookHandler`, `TransactionalGate`,
  `TransactionalFlusher` (`schedule()`, `scheduled_at_due()`),
  `TransactionalRetryGuard`, `TransactionalPayloadBuilder`, `EventsEndpoint`,
  `SettingsEndpoint::save_woocommerce()`, `CartAbandonmentSweeper`,
  `EnvDetector`, `Deactivation`, `Bootstrap`, `GdprHandler` (the eraser's
  queue and Action Scheduler cleanup, the export sections),
  `EventQueue::erase_for_privacy_request()`, `uninstall.php`, the admin
  components and reducers in the delta. Read against
  `docs/RECENGINE_API_CONTRACT.md` v1.33.0 §1, §2, §3, §3b, §3c, §5, §6, §15,
  and DECISIONS CON-321, CON-323, PRO-4263 + CON-325, CON-328, CON-330. No real
  customer data was used.
- **Method:** static read. `npm run ci:strict` on `3d0497a` in a fresh
  worktree: exit 0 (PHPCS, PHPStan, PHPUnit unit 1927 tests, ESLint, tsc,
  vitest 515). CI on `3d0497a`: "Lint and test the codebase" (run
  44178179813 — PHP 8.0–8.3, admin bundle, blocks and the three integration
  legs) and "Contract staleness" (run 44178180110) green. **Not run:** the
  integration suite locally, wp-env, a CI-built ZIP, `verify-release-zip.sh`
  and Plugin Check — those belong to the 3.24.0 release gate on the bump
  branch, as for 3.23.0. No live walk, no engine call, no Store API request
  against a real store.

## Verdict

**0 Critical, 0 High, 0 Medium, 4 Low, 9 Info.** No code is changed in this
PR.

**RESULT: 3.24.0 may proceed** once the release gate (CI-built ZIP, VERIFY,
Plugin Check 2.2.0 — expected 3 ERRORS / 17 WARNINGS, the accepted set) is
green. No finding blocks it. L1 is the one worth a small fix before or soon
after the cut, because 3.24.0 is the first release that can hit it on an
upgrading store; L2–L4 are backlog.

No new security exposure. The prefix is validated before it is stored and
before the exchange is accepted, never logged by value, and every key the
plugin sends is built or re-keyed from it at send time; a browser-supplied
sku is forced under the connection's own prefix. The invitation notice is
capability-gated, fully escaped and its dismissal is nonce- and
capability-checked. The Store API callback writes one boolean to the
caller's own cart row, behind WooCommerce's Store API nonce, and calls
nothing else. The payment reminder's Action Scheduler action carries an
order id only, and every new log line or stored skip carries a code or an
error location, never a message text.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| L1 | Low | `includes/REST/SettingsEndpoint.php:474`–`:494`; `admin/src/components/steps/AutomationSection.tsx:143` (extras shown only on an enabled card); `includes/Wizard/EnvDetector.php:538` | The new cutoff ceiling is checked on every WooCommerce-tab save, also when the abandoned-cart card is switched off — and a switched-off card hides the cutoff field. A store upgrading with a stored cutoff of 1426 minutes or more (allowed until now) and the reminder off cannot save the tab — including switching on the new payment reminder — until it switches the cart card on to see and lower the field. A filtered cart lifetime under 25 minutes makes the ceiling lower than the 10-minute floor, so every save of the tab is refused | Recommended before or soon after the cut: check the ceiling only when `abandonedCartEnabled` is true (or clamp the stored value on load) |
| L2 | Low | `includes/Smaily/PaymentReminderCheck.php:73`–`:110`; `includes/Privacy/GdprHandler.php:531`–`:550` | A personal-data erasure while a payment reminder waits (5 min – 24 h) does not stop it on a store that keeps order data on erasure (WooCommerce's default: "Remove personal data from orders" off). The waiting action holds no address, the eraser cancels nothing for it, and `run()` stops only on `_anonymized` — so it queues and sends a reminder to the erased address. A follow-up email waiting in the queue is deleted by the same erasure | No — backlog: cancel the order's pending `smly_plus_payment_reminder_check` actions in `drop_waiting_updates()` for the request's order ids |
| L3 | Low | `includes/Integrations/WooCommerce/TransactionalEmailHookHandler.php:117`–`:141`, `:153`–`:175` | A status change into `failed` schedules a reminder for any checkout order that has no reminder marker — every order placed before 3.24.0, of any age. A merchant who bulk-marks old unpaid checkout orders `failed` in wp-admin sends each shopper a reminder with a working pay link (if `needs_payment()` holds) | No — backlog: skip an order older than a bound (for example the delay maximum) or a change made in wp-admin |
| L4 | Low | `includes/Smaily/RecEngine/Support/SkuResolver.php:184`–`:187`; `includes/Integrations/WooCommerce/StorefrontRecommendations.php:383`–`:391` | On a connection WITHOUT a prefix, `is_own_key()` accepts every sku, so a §15 slot naming another connection's product (sku `c4-…`) is rendered as THIS store's product with the same numeric `external_id` — a wrong product with that slot's `rec_id`. Contract §15 v1.33.0 says a slot can name another shop's product. Not reachable today: the engine assigns no prefixes yet (§1 Status), and it needs a pre-1.33 connection sharing an engine store with a prefixed shop | No — before the engine switches prefix assignment on: on a `''` connection, accept only a `woo-` sku (or refuse the prefix shape) |
| I1 | Info | `includes/Settings/RecEngineSettings.php:232`–`:258` | A ping whose `config.connection` is `null` stores the prefix `''` (`config_prefix()` reads an unset block as "no prefix"), re-keying every later send unprefixed; an object without `prefix` or a malformed value is ignored and logged by name. Matches the exchange's reading; the contract never sends `null` | No — accepted |
| I2 | Info | `includes/Notifications/NotificationManager.php:397`–`:418`; `includes/Settings/Credentials.php:135` | The invitation's check runs on every admin screen for an admin who has not dismissed it: one credential read + decrypt; on a Mode A store whose default account is empty, also the uncached `option_name LIKE` scan and a decrypt per language account. Cheap, but the scan's `phpcs:ignore` reason ("setup-link connect or (de)activation only") is no longer true | No — accepted; fix the comment when the file is next touched |
| I3 | Info | `includes/Notifications/NotificationManager.php:588`–`:596` | The 90-day dismissal is one site option (`smly_notice_dismissed`), as for every other notice: one admin's Dismiss hides it for all admins | No — accepted (existing pattern) |
| I4 | Info | `includes/Smaily/TransactionalPayloadBuilder.php:144`–`:149` | `payment_url` is `get_checkout_payment_url()` — the order-pay link with the order key. It reaches the store's Smaily account (and its email archive) and the Event Log's stored request (admins only). The same link WooCommerce's own customer-invoice email carries | No — accepted |
| I5 | Info | `includes/Integrations/WooCommerce/CartHookHandler.php:236`–`:247`; `:142`–`:176` | The Store API callback (like the classic order review since CON-196) records a tick whether or not the merchant shows the newsletter box; a crafted request can tick a shopper's own cart, so in consent mode a visitor who types someone else's address and ticks can cause one reminder to it. Same exposure as the classic checkout before this delta; the subscription itself is still made only with the order | No — accepted |
| I6 | Info | `includes/Integrations/WooCommerce/CartHookHandler.php:213`–`:222`, `:189`–`:200` | The classic checkout now captures the billing email as it is typed (CON-325, approved), so a guest who typed only the email is tracked for the reminder; the inline `wc-checkout` script is a static string with no interpolation (a strict-CSP store blocks it, as it blocks WooCommerce's own inline scripts) | No — accepted |
| I7 | Info | `includes/Smaily/RecEngine/ExchangeResult.php:143`–`:147` | The `prefix_invalid` reason is a plain English string, not wrapped in `__()`, and is shown in the admin as the exchange error — an Estonian admin sees English | No — backlog |
| I8 | Info | `includes/Smaily/RecEngine/Support/SkuResolver.php:196`–`:199`; Event Log details | Contract §1 calls the prefix opaque ("never parse it, never show it"); `reprefix()` strips the documented shape from keys the plugin itself built (cached pages, stored rows), and the Event Log shows prefixed keys in the stored request. Both deliberate (DECISIONS CON-323) | No — accepted |
| I9 | Info | `STATUS.md:29`, `:170`–`:181`; `docs/DECISIONS.md` | STATUS still calls #344, #346–#351 "PR open, not merged" although all are on `main` (the batch STATUS PR is pending), and CON-326 / CON-327 have no DECISIONS entry although CON-326 adds a new save refusal (STATUS line 179 holds the only record) | No — the batch STATUS PR fixes the first; add a CON-326 entry with it |

---

## L1 — LOW — a hidden cutoff can block every WooCommerce-tab save

`SettingsEndpoint::save_woocommerce()` reads `abandonedCartCutoffMinutes`,
raises it to 10 and refuses the whole tab with 400 when it is above
`CartAbandonmentSweeper::max_cutoff_minutes()` — before anything is written,
and whatever `abandonedCartEnabled` says. The admin always posts the
hydrated value (`buildTabPayload.ts:41`), hydrated from the stored option
(`EnvDetector.php:538`) without the reducer's clamp (the clamp runs only on a
field change). A switched-off automation card shows none of its extras
(CON-255, `AutomationSection.tsx:143`), so the cutoff field is not on the
screen.

So on a store that saved a cutoff of 1426 minutes or more before 3.24.0
(STATUS's CON-326 bullet names this case) and keeps the cart reminder off,
every save of the WooCommerce tab — the transactional triggers, the new
payment reminder, the follow-up, the workflow mappings — answers 400 with
"The abandoned-cart cutoff time must be from 10 to 1425 minutes…". The
merchant has to switch the cart card on to reach the field. The message names
the field, so the way out exists, but it reads as unrelated to what they were
saving. Separately, a merchant filter on
`smaily_connect_abandoned_cart_max_age_seconds` under 1 500 s makes the
ceiling smaller than the 10-minute floor, and every save of the tab is
refused.

**Recommendation:** check the ceiling only when the reminder is switched on
(a switched-off card's cutoff cannot fail to send), or clamp the value at
hydration as the reducer does on change; and floor the ceiling at 10. One
unit test for "card off, stored 1440, other field saved".

## L2 — LOW — an erasure does not stop a waiting payment reminder

DECISIONS CON-328 notes that the action carries no user or address, so the
eraser has nothing to cancel, and that an order WooCommerce's eraser
anonymized is never reminded. That holds only when WooCommerce removes order
data on erasure, which is off by default (`woocommerce_erasure_request_
removes_order_data`). With the default, an erasure processed while the
action waits leaves the order's billing email in place; `run()` then queues
a `transactional.payment_reminder` row and the flusher sends it. The plugin
treats the same moment differently for the follow-up email: its row carries
the recipient and the eraser deletes it (`EventQueue::
erase_for_privacy_request()`). The window is the merchant's delay (default
20 minutes, at most 24 hours) and the email is a service email about an
order the merchant chose to keep, so the impact is small.

**Recommendation (backlog):** in `GdprHandler::drop_waiting_updates()`,
cancel `PaymentReminderCheck::HOOK` actions whose args are one of the
request's order ids (`as_unschedule_action( HOOK, array( $id ), AS_GROUP )`
per order), as CON-275 does for login jobs; pin it in the eraser's
integration test.

## L3 — LOW — a status change into `failed` can remind old orders

`on_order_status_changed()` calls `maybe_payment_reminder()` on any change
into `failed`. The once-per-order marker is set only when an action is
scheduled, so every checkout order placed before 3.24.0 has none. A
merchant who tidies up old unpaid checkout orders by bulk-marking them
`failed` schedules one reminder per order; each one whose order still
`needs_payment()` sends the shopper an email with a working pay link,
possibly weeks after they left. Orders marked `cancelled` are not affected.

**Recommendation (backlog):** schedule on `failed` only for an order created
within a bound (for example `PAYMENT_REMINDER_DELAY_MAX` or the store's hold-
stock time), or only for a change not made in wp-admin.

## L4 — LOW — an unprefixed connection accepts another shop's slot

`StorefrontRecommendations::product_id()` returns 0 for a sku outside the
connection's prefix, but `SkuResolver::is_own_key()` is true for every key
when the prefix is `''`. Contract §15 (v1.33.0) states that a slot can name
another shop's product of the same engine store, and that a connection made
before 1.33.0 keeps `''`. So a store whose plugin connected before 1.33.0 and
shares its engine store with a later, prefixed shop can receive a slot like
`{sku: "c4-woo-17", external_id: "17"}` and render its own product 17 —
unrelated — with that slot's `rec_id`, which the attribution path later
credits. Not reachable while the engine assigns no prefixes (§1 Status).

**Recommendation:** before the engine switches assignment on, make
`is_own_key()` on a `''` connection accept only keys that start with
`woo-` (the plugin's own namespace), with a unit test for a prefixed slot on
an unprefixed connection.

## Info notes

- **I1** — the exchange refuses a wrong-shape prefix before anything is
  stored (`prefix_invalid`, after the host and account checks); the ping
  keeps the stored one and logs a fixed sentence.
- **I2** — the default-account fast path (#349) covers single-account
  stores; the dismissal is read before the credentials, so a dismissed
  notice costs nothing.
- **I4** — `payment_url` is not HTML-escaped, like `product_url`; it is
  built by WooCommerce from the site URL and the generated order key, with no
  shopper input.

## Confirmed clean

- **Prefix validation and storage (CON-323).** One shape,
  `c[1-9][0-9]{0,3}-`, anchored for the whole-value check
  (`CONNECTION_PREFIX_PATTERN`) and reused unanchored in `reprefix()`'s
  regex; `is_valid_prefix()` requires a string. The exchange accepts `''`
  (no `connection` block, an engine before 1.33.0) or the shape, refuses
  anything else without storing (`prefix_invalid`, 502 with a plugin-worded
  sentence). `previous_prefix` is sent only to an exchange URL that passed
  the `intelligence.smaily.com` rule (PRO-3623). The option is a non-secret
  string, autoloaded for storefront reads, swept by `uninstall.php`'s
  `smly_rec_%` delete. `connection_prefix()` is `''` while disconnected.
- **Re-keying at send time.** Catalog upserts and orders are built at flush
  from the current prefix; a stored `catalog.delete` object passes
  `ensure_valid_removal()` then `with_current_prefix()`; `catalog.remove`
  ids pass `reprefix()`; the manifest walk builds through `SkuResolver`.
  `reprefix()` drops only a leading prefix followed by `woo-` or a bare id,
  so it is idempotent and never prefixes twice. §3b `errors[]` is read for
  `product_id` only; the row stores `{field: product_ids}`, never the
  engine's message (CON-301).
- **`/relay` (§6).** `current_prefix_skus()` runs after `validate_batch()`
  and the cart-sku resolution, before identity and the profiling gate; a
  browser-supplied sku is re-keyed under the connection's own prefix, so a
  page cannot write into another connection's namespace (on a `''`
  connection a foreign `c<N>-` prefix is stripped). No new field reaches the
  engine; the rate limits, origin rules and consent gates are unchanged.
- **Storefront recommendations.** The sku is no longer taken apart; the
  product id comes from `external_id` only (`ctype_digit`), and `cards()`
  still loads it through `wc_get_product()` and drops a product that is not
  `is_visible()`. Output escaping unchanged.
- **Invitation notice (CON-321).** `render()` returns unless
  `current_user_can( 'manage_options' )`; the sentence is `esc_html__()`,
  each link `esc_url()` + `esc_html()` inside `wp_kses_post()`; the external
  links carry `target="_blank" rel="noopener noreferrer"`; the sign-up URL
  and the docs URL are constants. The Dismiss link is the existing
  `admin-post.php` route: nonce per key (`wp_verify_nonce`), capability
  check, `sanitize_key()`, `wp_safe_redirect()` to the referer. No new REST
  route.
- **Store API callback (PRO-4263).** Registered only when WooCommerce
  provides `woocommerce_store_api_register_update_callback`; the
  `cart/extensions` route is a cart route, so WooCommerce checks its Store
  API nonce. The callback returns unless `tracking_enabled()` (setup finished
  + the reminder switched on) and a WooCommerce session exists, reads only
  `user_newsletter === true` (a strict boolean), and writes one boolean into
  the caller's own tracker row through a prepared `SELECT` and
  `$wpdb->update()`. No Smaily call, no user meta, no welcome: the
  subscription still happens only with the order
  (`HookHandler::on_checkout_block_optin()`, unchanged apart from the shared
  namespace constant).
- **Payment reminder (CON-328).** The action's only argument is the order id
  (cast to int on run); it sits in its own group, listed in
  `Deactivation::AS_GROUPS`, and its finished rows fall under the janitor's
  `smly_plus_%` prune. Eligibility is re-read when it runs and again when the
  row is sent (`created_via` checkout / store-api, not anonymized,
  `pending`/`failed` with `needs_payment()`), and the gate is evaluated each
  time. Offline methods (`bacs`, `cheque`, `cod`) and admin/REST orders are
  never scheduled. The row carries the recipient, so a queued reminder is
  removed by the existing queue erasure. The delay is validated by one rule
  for the save route and the reader (`NumberInput::whole_number_in_range`,
  5–1440, 400 before any write). Debug lines carry the order id and a reason
  code only. A failed reminder does not fail open (no WooCommerce email to
  fall back to), matching the follow-up.
- **CON-330 / CON-327 logging rule.** The order skip stores
  `{outcome: skipped, reason: no_billing_email}` and a fixed note; the
  sweep's backstop logs `DebugLog::where( $e )` — type and place, never the
  message. `$skip_reason` is reset before every row.
- **GDPR export (#341).** The new `outcome_events` section goes through the
  same loop and labels as the other engine sections.
- **Code quality.** Each feature carries its DECISIONS entry (except I9),
  CLAUDE.md and the docs site (EN + ET) were updated in the same PRs, and
  tests pin the new paths (unit: `SkuResolverTest`, `SetupExchangeTest`,
  `RecEngineConnectionPrefixTest`, `CatalogRemoveFlusherTest`,
  `IngestFlusherTest`, `StorefrontRecommendationsTest`,
  `NotificationManagerTest`, `CartHookHandlerTest`,
  `PaymentReminderCheckTest`, `TransactionalEmailHookHandlerTest`,
  `SettingsEndpointTest`, `OrderFlusherTest`; integration:
  `RecEngineConnectionPrefixTest`, `SmailyInviteNoticeTest`,
  `AbandonedCartConsentTest`, `TransactionalEmailsPipelineTest`,
  `RecEngineOrdersTest`, `RecEngineGdprTest`; the block.s `block.test.js`
  and the reducer and card vitest files). #346 and #349
  removed duplication (one prefix shape, one namespace constant, one
  Settings-link builder) without changing behaviour. `npm run ci:strict` exit
  0 on `3d0497a`.

## Release gate — still to run on the bump branch

Not run here (no wp-env in this audit, by instruction): the `release.yml`
dry-run on the 3.24.0 bump branch, `bin/verify-release-zip.sh … 3.24.0` on its
artifact from the repo root, and Plugin Check 2.2.0 on that ZIP
(`smaily-connect-pkg`, `--slug=smaily-connect
--exclude-directories=vendor`). Expected: VERIFY OK and **3 ERRORS / 17
WARNINGS** — the accepted inlined-React three and the accepted seventeen,
some at moved lines (`SettingsEndpoint.php`, `CartSessionStore.php` and
`TransactionalPayloadBuilder.php` changed in this delta). The new SQL in the
delta (`CartSessionStore::set_newsletter_optin()`) existed before; no new
direct query was added. Estonian strings of #344, #347, #348, #350 and #351
await Erkki's proofread before the docs site is published.

## Punch-list

1. **L1** — small fix recommended before or soon after the cut: check the
   cutoff ceiling only for a switched-on reminder (or clamp on load), floor
   it at 10.
2. Backlog: L2 (cancel a waiting reminder on erasure), L3 (bound the `failed`
   trigger), L4 (unprefixed connection accepts only `woo-` keys — before the
   engine switches prefixes on), I7 (translate the `prefix_invalid` reason),
   I2's stale `phpcs:ignore` reason, I9 (CON-326 DECISIONS entry with the
   batch STATUS PR).
3. Release gate as above.

## Conclusion

The delta adds the connection prefix to every key on the wire, a fourth
transactional email, an admin invitation and two checkout capture points.
None of it opens a new path for untrusted input: the prefix is validated
before storage and re-applied at send time, a browser cannot choose another
connection's namespace, the Store API callback writes one boolean to the
caller's own cart, the notice is capability-gated and escaped, and the
reminder's background action carries only an order id. The four Low items
are correctness and edge-case consent gaps — a save blocked by a hidden
field, an erasure that does not stop a waiting reminder, reminders on a
bulk status change, and a future cross-shop slot. **3.24.0 may proceed once
its release gate is green.**
