# Security delta audit — 3.15.0 pre-release gate (3.14.0..HEAD)

- **Date:** 2026-09-29
- **Baseline:** delta `3d5643f..5661b4a` (the `release: 3.14.0` commit to the
  pre-bump main tip; **18 commits, 43 files, +2 899 / −349**; the shipped
  plugin code alone — `includes/`, `integrations/`, `admin/src/` without its
  tests — is **18 files, +544 / −65**). The work in it:
  - **PRO-3190** — the order / shipping confirmation `context`
    (`TransactionalPayloadBuilder`) gains order money/status/method fields,
    and **new personal data on the wire** — delivery name, billing and
    delivery address, phone, the shopper's order note — behind a new
    default-OFF setting (`smly_plus_transactional_personal_data_enabled`,
    saved by the WooCommerce Settings tab, React `Toggle`); two merchant
    filters (`smaily_connect_transactional_email_fields`,
    `smaily_connect_transactional_email_product_fields`) with key/value
    validation, escaping, length/count caps and a `Throwable` guard.
  - **PRO-3406** — **consent**: a newsletter tick at the classic checkout,
    My Account registration and the block checkout is saved as
    `user_newsletter = 1` by one writer, `HookHandler::record_newsletter_optin()`,
    which the existing meta-transition handler turns into `is_unsubscribed = 0`
    at Smaily.
  - **PRO-3192** — **consent**: the durable profiling opt-out registry now
    stores the moment of the opt-out, compared with the Smaily contact's
    `smaily_rec_profiling_ts`; entries recorded before the change are read as
    newest.
  - **PRO-3335** — `product_url_N` (plain product permalink, published
    products only) in order/shipping confirmations and abandoned-cart
    reminders.
  - **CI** — `lint_and_test.yml` runs only the unit suite in the PHP matrix
    and builds the blocks (`npm ci --prefix blocks`) before the integration
    job; `contract-staleness.yml` comment only.
  - Non-code: `STATUS.md`, `CLAUDE.md`, `README.md`, `readme.md` (deleted,
    PRO-3411), `docs/**`, `languages/**`, tests.
- **Auditor:** Claude (Opus 5.5)
- **Trigger (re-audit policy):** point 1 (the 3.15.0 release boundary) and
  point 2 — the delta touches **GDPR/consent** (the newsletter consent record,
  the profiling opt-out comparison) and **what gets stored/sent (PII)** (new
  personal-data fields on the wire, stored in the queue's `payload` /
  `sent_payload`), regardless of its size. Linear PRO-3431, first criterion.
- **Scope:** file-by-file read of the production delta (`git diff
  3d5643f..origin/main -- . ':!tests' ':!docs'`), plus the call chains it
  reaches: `HookHandler::handle_newsletter_change()` /
  `sync_order_contact()`, `ContactAudience::should_sync_order_email()`,
  `ContactSyncMode::include_guests()`, `Profile_Settings` (field gating,
  `add_checkout_subscription_checkbox()`), the block Store-API extension
  registration (`smaily-blocks.class.php`), `ProfilingConsent` in full,
  `Client::get_contact_consent()` / `write_profiling_consent()`,
  `EventQueue::erase_for_privacy_request()` / `redact_json()` /
  `privacy_request_where()`, `TransactionalFlusher::dispatch()` and its retry
  ceiling, `QueueJanitor` retention, `DebugLog`, `SettingsEndpoint`
  (`permission_check`, `save_transactional_triggers`), and
  `ProductMatrixBuilder::fill()`. Tests were read only to see what is pinned
  (`TransactionalPayloadBuilderTest`, `ProfilingConsentTest`,
  `CheckoutNewsletterOptinTest`). No real customer data was used; examples
  below are placeholders.

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium. 2 Low, 10 Info. RESULT: 3.15.0
may proceed — Low 1 accepted, Low 2 fixed in PRO-3434 before release.**
(Dispositions by Erkki, 2026-09-29; see each finding.)

No route, capability or crypto changes. The one new setting goes through the
existing `manage_options`-gated Settings route. The new personal data is off
by default, escaped, erased by the existing allowlist redaction, and kept for
the queue's existing retention. The consent changes only ever write an opt-in
on an explicit tick, and the profiling change moves the registry toward
opt-out. Both Lows are consent-integrity edges that already existed in a
wider form before this delta. Erkki accepted Low 1 and chose to fix Low 2
before the release.

**PCP (WordPress Plugin Check) was not run in this pass (skipped).** The
Colima VM on this Mac is shared (2 CPU / 4 GiB) and was busy with another
project's container (`nocode-db-1`, about 85 % CPU at the time). This worktree
also has no `node_modules`, `vendor` or `dist`, so a PCP run against the built
ZIP would have meant a full build plus a second wp-env stack started from a
worktree path. The release gate runs PCP on the CI-built ZIP (CLAUDE.md
"Running PCP").

---

## 1. LOW — a newsletter tick on a newly created account is a single opt-in for an unverified address, and it overrides a prior Smaily unsubscribe

**Where:** `integrations/woocommerce/profile-settings.class.php:184`
(`smaily_save_registration_newsletter_optin`), `:167` (checkout, incl. an
account created there), `includes/Integrations/WooCommerce/HookHandler.php:358`
(block checkout account creation) → `HookHandler::record_newsletter_optin()`
(`:371`) → `handle_newsletter_change()` (`:667`), which sends
`is_unsubscribed = 0` ("an explicit re-grant overrides a prior Smaily
unsubscribe").

Anyone can register a WooCommerce account for an email address that has no
account yet, without proving they own it. WooCommerce does not verify the
address. If they tick the box, the plugin now records `user_newsletter = 1`
and the Smaily contact for that address is subscribed. If that person had
unsubscribed at Smaily earlier, they are subscribed again. In **consent mode
with guests off (the default)**, no storefront path did this before 3.15.0:
the account path wrote no consent record, and guest orders are not synced.
The delta adds three such entry points. The easiest is My Account
registration, which needs no cart or order.

**Why it is Low, not higher:**

- It works only for an address with no account on the store, because WooCommerce
  refuses to register an address that already has one.
- The effect is marketing email to the victim, not data exposure. Every
  message carries Smaily's unsubscribe link, and an unsubscribe comes back
  through the reconciler.
- The same capability already exists elsewhere: in checkout-only mode, or
  with the guests toggle on, a guest checkout sends `is_unsubscribed = 0` for
  whatever email is typed (`sync_order_contact()`). Any public newsletter
  signup form has the same exposure. The delta widens where this happens, not
  what can happen.
- The design is deliberate (DECISIONS PRO-3406): a tick is an explicit
  opt-in.

**Proposed fix (a product decision, not made here):** for an account created
in the same request, subscribe without overriding a prior Smaily unsubscribe.
Either omit `is_unsubscribed` when the contact is found unsubscribed, or send
fresh-account ticks through a Smaily double opt-in workflow. Keep the current
behaviour for a logged-in, existing account ticking the box, where the email
was already bound to the account before the tick.

**Disposition (Erkki, 2026-09-29): risk ACCEPTED, no code change** (Linear
PRO-3433; DECISIONS PRO-3433). The harm is limited to unwanted marketing
email, it only works for an address with no account on the store yet, and the
same class of exposure already exists through public signup forms and the
checkout-only / guest modes. Both proposed fixes (not overriding a prior
unsubscribe for a same-request account, and double opt-in) are deferred.

## 2. LOW — the contact's profiling timestamp is parsed leniently and has no upper bound

**Where:** `includes/Privacy/ProfilingConsent.php:172`
(`is_newer_opt_in()`: `strtotime( $consent['smaily_rec_profiling_ts'] ?? '' )`).

`strtotime()` also accepts relative strings (`tomorrow`, `+1 year`) and any
far-future date. A `'1'` on the contact with such a "timestamp" counts as
newer than every opt-out moment the store records until that date. Suppose a
shopper opts out in My Account and the Smaily write fails (the PRO-3192 case
this change fixes). The next read then sees that future-dated `'1'` and lifts
the opt-out, so the shopper is profiled again.

**Why it is Low:** the value is written only by something that can write the
Smaily contact's fields: the merchant, another integration on the same Smaily
account, or a Smaily form that accepts custom fields. Before the delta, a bare
`'1'` with no timestamp lifted the opt-out on its own. The delta therefore
narrows this path; it is not a regression. A normal store-side opt-out whose
write succeeds overwrites the forged value.

**Proposed fix:** parse only the form the plugin itself writes (`IsoDate`
Z-form, e.g. `DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', … )` in
UTC). Treat a timestamp more than a few minutes in the future as "no
timestamp", which counts as older. Pin both with a
`missing_timestamps()`-style data provider row (`'tomorrow'`, a date one year
ahead).

**Disposition (Erkki, 2026-09-29): to be FIXED before 3.15.0** (Linear
PRO-3434), in a separate PR that is in progress. The release waits for it.

## 3. INFO — the personal-data switch governs the built-in keys only; a merchant filter can still send personal data

`PERSONAL_DATA_KEYS` are reserved while the switch is off
(`TransactionalPayloadBuilder.php:152`), so an extras filter cannot send
*those names*. Both filters still receive the full `WC_Order`
(`:159`, `:230`), so a snippet can send the phone or address under any other
key (`phone`, `product_ship_to`, …) with the switch off. This is by design:
the filter is the merchant's own in-process PHP, and no allowlist can stop in-process code.
DECISIONS PRO-3190 says "a snippet can't send them past it", which is true
only for those names. **Follow-up (docs only):** say in the merchant docs
(EN+ET) and DECISIONS that the switch covers the built-in fields, and that
what a filter sends is the merchant's responsibility.

## 4. INFO — where the new personal data is stored, who sees it, and what the switch does not retract

With the switch on, the fields go into the queue row's `payload.context`, and
into `sent_payload` as the literal body POSTed to Smaily (F3-44, trimmed to
~10 KB). They are cleartext at rest, which is the class of the 2026-06-25 PII-at-rest
Low, and readable in the Event Log Details. The Event Log is gated on
`Constants::CAPABILITY` (`EventsEndpoint::permission_check`). Retention is the
janitor's existing 30 days for sent rows and 90 days for failed rows.

Turning the switch **off** does not change rows already queued. Retries
resend the stored `context` (`TransactionalFlusher.php:420`), but only within
`RETRY_CEILING_SECONDS` (1 h). After that the row is terminal, so the window
is bounded. "Send again" rebuilds the context and follows the current switch.
No action needed. Worth one line in the merchant docs if a merchant asks why
an old row still shows an address.

## 5. INFO — GDPR erasure covers the new keys by construction; the delivery recipient is not a lookup key

`EventQueue::redact_json()` keeps only `REDACTION_KEEP_KEYS`
(`EventQueue.php:98`: `http`, `outcome`, `note`, `workflow_id`,
`account_key`, `to_status`) and replaces every other value, recursively, with
`[erased]`. The new personal-data keys and every merchant extra are therefore
blanked in `payload`, `sent_payload` and `last_response` of a `sent` row. A
still-sendable row is deleted. This is pinned through the real builder
(`test_an_erasure_request_blanks_the_new_personal_data_in_the_stored_send_history`).
Keys survive, but they are schema names restricted to `[a-z0-9_]`.

Erasure finds a transactional row by its recipient (`"to":"<email>"` or
`contact_key`), which is the billing email. A **different delivery recipient**
(a gift address) now appears as `shipping_first_name` / `shipping_address_*`
in the row, but an erasure request under that person's own email does not
find it. WooCommerce's own order eraser behaves the same way. The rows expire
with retention. Noted, not actionable in the plugin.

## 6. INFO — the merchant-extras guard holds

Checked in `clean_extras()` (`TransactionalPayloadBuilder.php:287`):

- A throwing filter (`Throwable`, including PHP `Error`) and a non-array
  return both add nothing, so a broken snippet cannot break checkout or the
  send.
- Keys must match `^[a-z][a-z0-9_]{0,63}$` and carry the prefix (`product_`
  per line).
- Reserved keys are refused:
  - order level: all built-ins, including the product matrix already in
    `$context`, the personal-data keys, and `over_10_products`;
  - per line: the base `PRODUCT_KEYS`.
- Built-ins also win through `+`, so a key collision can never overwrite a
  built-in.
- Only scalar values are kept. They are cut to 1 000 characters, then
  `htmlspecialchars`-escaped like every other text field.
- There are at most 20 keys per filter. The per-line count is shared across
  lines (`$accepted` by reference).
- Per-line extras stay aligned with their slot, because `ProductMatrixBuilder::fill()`
  walks the same `array_slice( …, 0, 10 )` list in order.
- Log lines are `DebugLog` (only with `WP_DEBUG` on). The dropped key is
  reduced to `[A-Za-z0-9_-]`, 64 characters. A thrown filter's own message is
  logged as-is, so a merchant exception that embeds order data would reach the
  debug log. That is the merchant's message and WP_DEBUG-only, so it is acceptable.

## 7. INFO — `customer_note` and address fields are escaped but not length-capped

`customer_note` is free text a shopper types (`:191`). It is
`htmlspecialchars`-escaped, like `first_name` (PRO-1537), so no HTML reaches
the template. Two edges:

- **No length cap.** Extras are cut at 1 000 characters, built-ins are not. A
  very long note makes a large queue row. If Smaily rejects the send, the row
  goes terminal and the flusher falls back to WooCommerce's own email, which
  is fail-safe.
- **Template syntax.** `htmlspecialchars` does not neutralise `{{ … }}` or
  `{% … %}`. This is not new: names have carried shopper text since before
  this delta. Smaily is expected to substitute merge values without rendering
  them again.

**Optional follow-up:** cap `customer_note` (for example at 1 000 characters,
like the extras). Also make one sandbox send with a note containing
`{{ first_name }}` to confirm Smaily does not render it.

## 8. INFO — the newsletter-tick writers: nonce, field and mode gates hold; an unticked box never subscribes

- **Classic checkout** (`woocommerce_checkout_update_user_meta`,
  `profile-settings.class.php:167`): WooCommerce has already verified the
  `woocommerce-process_checkout` nonce. `$data` is WooCommerce's
  `get_posted_data()`, which holds only fields in `get_checkout_fields()`. The
  checkbox is added there only when the merchant enables checkout
  subscription, and not for a buyer already opted in. A forged
  `user_newsletter` POST with the box off therefore never reaches `$data`.
  WooCommerce normalises a checkbox to `1`/`''`, and the writer requires `1`.
- **My Account registration** (`:184`): the writer requires the
  `woocommerce-register` nonce, the field being offered (`get_fields()`) and
  `user_newsletter === 1`. A checkout-created account never carries that
  nonce: the classic checkout nonce differs, and the block checkout is JSON
  through the Store API with an empty `$_POST`. So it cannot double-write
  (`test_classic_checkout_account_creation_tick_subscribes` asserts the
  registration callback stays silent).
- **Block checkout** (`HookHandler.php:316`, `:353`): only a strict
  `true === …user_newsletter` sets the order meta. An unticked resubmission
  deletes it. The record goes to the order's own customer id: the logged-in
  user, or the account created in that request. One gap: the server does not
  check that the merchant placed or enabled the opt-in block, because the
  extension schema registers whenever WooCommerce is active. A shopper who
  forges a Store API request can therefore opt in **their own** account only.
  That is harmless.
- **Common writer** (`:371`): it only ever writes `'1'`, and only when
  `SetupState::completed()` holds and the mode is consent. The
  transition handler also requires `sync_enabled()`. An unticked box writes
  nothing, never a `0`, so it cannot unsubscribe
  (`test_an_unticked_box_records_nothing`, `test_other_modes_write_no_consent_record`).
- **Evidence meta** `_smaily_newsletter_optin`: the value is `'1'`, the key is
  underscore-hidden and it holds no personal data.

## 9. INFO — `product_url` is safe by construction; one dead-link edge for variations

`ProductMatrixBuilder::product_url()` (`:111`) returns the plain permalink
with no tracking parameters, or `''` unless the product is `publish`. For a
variation, WooCommerce core's `WC_Product_Variation::get_permalink( $item )`
`urlencode`s every attribute key and value before `add_query_arg()`, and the
base permalink comes from a sanitized slug. No quote or `<` can reach the
template, so leaving it without `htmlspecialchars`, like `product_image_url`,
is fine.

**Edge (functional, low value):** the publish check looks at the
*variation's* own status. A published variation whose **parent** is draft,
private or trashed would link to an unpublished parent page, which is a dead link in that buyer's own
email. It exposes nothing beyond a product the buyer bought. In the cart
builder, a disabled (`private`) variation yields `''` rather than falling back
to the parent link. The transactional side has no unit test for an
unpublished product (the cart side has
`test_an_unpublished_product_has_an_empty_link`). **Optional follow-up:** for
a variation, also require the parent to be `publish`, and pin both builders.

## 10. INFO — the profiling registry now holds a moment; old entries fail closed; the option is still read-modify-write

- **What is stored:** the value under `smly_profiling_optouts[md5(email)]` is now
  a Unix time: the moment of a store-side opt-out, or `0` for a mirrored one.
  This adds when-it-happened to data at rest. The key is the same hashed
  email, and the option is `autoload=false`. It is a suppression record and
  correctly survives an erasure.
- **Pre-PRO-3192 entries:** `true` entries are read as newest. A contact's
  `'1'`, even a genuinely newer one set elsewhere, is overwritten once with
  `'0'` (`ProfilingConsent.php:126` → `write( …, false )`). That is fail-closed
  (no profiling without consent) and deliberate (DECISIONS PRO-3192). The
  shopper can opt back in from My Account.
- **Carry-back:** the carry-back write sends no `is_unsubscribed`, so it
  cannot change a newsletter subscription. It never fires for a not-found
  contact, so it cannot create one.
- **Pre-existing race:** concurrent refreshes of different emails can lose
  one entry, because `remember_optout()` does get → modify →
  `update_option()` on one option (`:320`–`:333`). The delta writes a little
  more often (a carry now re-stamps the moment). The mirrored caches still
  hold `false`, so a lost entry shows up as a lost durable opt-out only after
  the stale cache is evicted. **Optional hardening**, not new: per-email
  options or an atomic write.
- **Contact read:** `Client::get_contact_consent()` reads one more field with
  a `(string)` cast and returns `null` when it is absent.

## 11. INFO — the new setting and admin switch add no new surface

`SettingsEndpoint::save_transactional_triggers()` (`:405`) stores
`! empty( $data['transactionalPersonalData'] )`, a boolean, through the
existing route. That route is gated by `permission_check()`
(`current_user_can( Constants::CAPABILITY )`) and WordPress REST cookie auth
(`X-WP-Nonce`). `EnvDetector` exposes only the boolean to the boot payload. The
CLAUDE.md `update_option( …, false )` trap does not apply, because the flag
defaults off: a never-saved "off" writing nothing is harmless. The React
`Toggle` and `Card` render static translated strings, with no
`dangerouslySetInnerHTML` and no `href` from data.

## 12. INFO — CI change (supply-chain angle)

The integration job's new "Build blocks" step (`lint_and_test.yml:151`) runs
`composer run install-block-modules`, which is `npm ci --prefix blocks`,
against the committed `blocks/package-lock.json`: lockfileVersion 3 with
integrity hashes on 1 635 entries. `release.yml` and this workflow's
`blocks` job (`:204`) already ran the same command, so it adds no new
dependency set. The workflow triggers on `push` / `pull_request`, not
`pull_request_target`, and references no secrets, so a fork PR's install
scripts run with a read-only token and nothing to steal. The unit-only PHPUnit
change reduces what CI runs; it adds nothing. **Pre-existing hardening, optional:**
there is no top-level `permissions: contents: read`, and actions are pinned by
tag, not SHA.

---

## Confirmed clean (checked, nothing to report)

- **No route, capability, nonce-model or crypto change.** Grep over the added
  production lines: no `register_rest_route`, `permission_callback`,
  `current_user_can`, `wp_remote_*` or `$wpdb`. The only new superglobal
  reads are `$_POST['woocommerce-register-nonce']` (verified) and
  `$_POST['user_newsletter']` (compared to `1`, value never stored).
- **No new outbound destination.** Transactional sends use the same
  `Client::send_message()` to the transactional account. The profiling
  timestamp is a read of an existing Smaily call.
- **No new table, migration or autoloaded option.** One new option (the
  switch) and one new order meta key.
- **Escaping of every new text field:** order status name, method ids and
  titles, all personal-data fields and extras go through `escape()`. Money
  fields are `wc_price` stripped to text. `_raw` fields are
  `wc_format_decimal`.
- **Public `/relay`, `LandingCapture` and both storefront bundles:** untouched.
- **`docs/site/index.html`** is merchant documentation, excluded from the ZIP.
  Skimmed: no credentials.

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/Smaily/TransactionalPayloadBuilder.php` | **in** | new personal data, switch, merchant filters (§3–§7) |
| `includes/Smaily/ProductMatrixBuilder.php`, `includes/Smaily/CartPayloadBuilder.php` | **in** | `product_url` (§9) |
| `includes/Smaily/TransactionalResend.php`, `includes/Integrations/WooCommerce/TransactionalEmailHookHandler.php` | **in** | pass the trigger to the builder (read; no gate touched) |
| `includes/Integrations/WooCommerce/HookHandler.php` | **in** | consent writer + block-checkout meta (§1, §8) |
| `integrations/woocommerce/profile-settings.class.php` | **in** | checkout / registration consent writers, nonce (§1, §8) |
| `includes/Privacy/ProfilingConsent.php`, `includes/Smaily/Client.php` | **in** | opt-out moment, timestamp read (§2, §10) |
| `includes/REST/SettingsEndpoint.php`, `includes/Wizard/EnvDetector.php`, `admin/src/**` | **in** | the switch (§11) |
| `.github/workflows/*.yml` | **in** (supply chain) | §12 |
| `STATUS.md`, `CLAUDE.md`, `README.md`, `readme.md`, `docs/**`, `languages/**` | out | no executable content |
| `tests/**` | out | not shipped; read for what is pinned |

## Gates run for this pass

Documentation-only pass. No code was changed, so `ci:strict`, the integration
suite and PCP were not run here. **PCP skipped**, for the reason in the
Verdict. The release gate (CI-built ZIP, `bin/verify-release-zip.sh`, PCP)
covers them.

## Follow-ups this audit leaves open

1. **Finding 1 (Low): closed — risk accepted by Erkki (2026-09-29, PRO-3433).**
   A tick on an account created in the same request may keep overriding a
   prior Smaily unsubscribe. Omitting `is_unsubscribed` for a
   found-unsubscribed contact, and double opt-in, are both deferred.
2. **Finding 2 (Low): fix before 3.15.0 (Erkki, 2026-09-29, PRO-3434, PR in
   progress).** Parse `smaily_rec_profiling_ts` strictly (IsoDate Z-form) and
   treat a future timestamp as absent. Add data-provider rows.
3. **Finding 3 (Info, docs):** state that the personal-data switch covers the
   built-in fields only, and that filter output is the merchant's
   responsibility (EN+ET + DECISIONS PRO-3190).
4. **Finding 7 (Info, optional):** cap `customer_note`. Run one sandbox probe
   that a `{{ … }}` note is not rendered by Smaily.
5. **Finding 9 (Info, optional):** require the parent to be published for a
   variation's `product_url`. Add a transactional unpublished-product test.
6. **Finding 12 (Info, optional):** add `permissions: contents: read` to
   `lint_and_test.yml`.
