# Security + code-quality delta audit — 3.18.0 pre-release gate (3.17.1..82db055)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-09_3.17.1.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-09_3.17.1.md),
which covered everything up to the 3.17.1 tag. This report covers what landed
after the tag. The earlier reports stay valid for what they covered.

- **Date:** 2026-10-22
- **Baseline:** delta `3.17.1..82db055` (tag `3.17.1` = `fa8674b` → `origin/main`
  `82db055`): **19 commits, 86 files, +6 627 / −1 566**; outside `docs/` and
  `tests/` **55 files, +3 622 / −1 305** (about half of it the `.pot` / `-et.po`
  catalogues). The shipped PHP is **22 files, +1 262 / −254** (`includes/`,
  `integrations/`, `admin/wizard.php`, the checkout opt-in block's PHP); the
  shipped TypeScript / JS sources are **20 files, +503 / −98** (tests excluded).
  The work in it:
  - **#227 PRO-4092** — a saved inactive workflow shows as `#<id> — inactive` in
    the automation menus (admin UI only).
  - **#228 PRO-4049** — both attribution writers take a `smaily_ctx` only from a
    landing that writes the rec id (`LandingCapture::resolve()`,
    `attribution.ts`).
  - **#229 + #231 PRO-4093** — the welcome starts on the shopper's own marketing
    opt-in in the store (`HookHandler::run_as_storefront_optin()`, the four
    storefront writers, guest welcome, `woocommerce_created_customer` moved to
    priority 20).
  - **#230 + #232 PRO-4091** — the confirmation cards' status line
    (`TransactionalStatus`, `TransactionalGate::evaluate()` / `closed_reason()`,
    `Credentials::password_unreadable()`, boot payload and Settings-save answer
    `transactionalStatus`) and the Event Log skipped row
    (`EventQueue::record_skipped()`).
  - **#233 PRO-4099 + PRO-4068** — portable `bin/build-i18n.sh`, complete
    Estonian catalogue.
  - **#234 + #236 PRO-4095** — the follow-up email: a third transactional
    trigger, `EventQueue::enqueue_scheduled()` / `mark_skipped()`, the delay
    setting (`followUpDelayDays`, 1–90), the Event Log `scheduled_for`.
  - **#238 PRO-4098** — every skipped row reads skipped; the Sent filter's SQL
    leaves it out (`EventsEndpoint::is_skipped()`, `branch()`).
  - **#239 PRO-4097** — the block checkout hides the opt-in box from a subscribed
    customer (`customerSubscribed` in the block's script data).
  - **#240 PRO-4101** — `OrderBackfillJob::uses_hpos()` / `bare_status()`
    replace four copies each; stale comments.
  - **#226, #241** — contract syncs (v1.12.1, v1.13.0; no code). **#223–#225,
    #235, #237** — docs only.
- **Auditor:** Claude (Opus 5.5), read-only on the code.
- **Trigger (re-audit policy):** point 1 (the 3.18.0 release boundary — the next
  wordpress.org version) and point 2 (**consent**: the welcome trigger;
  **changed REST payloads**: `transactionalStatus`, `followUpDelayDays`, the
  Event Log `skipped` / `skip_reason` / `scheduled_for`; **SQL against custom
  tables**: the skipped and scheduled `EventQueue` rows, the grouped last-send
  query, the Event Log's Sent filter; **what gets stored**: skipped rows,
  follow-up rows).
- **Scope:** file-by-file read of the shipped PHP delta plus the call chains it
  reaches: `ContactAudience::should_sync_user()` / `should_sync_order_email()`,
  `HookHandler::on_user_newsletter_meta_update()` / `_add()`,
  `Profile_Settings` (all five save hooks and their nonce checks),
  `EventQueue::pending()` / `restart_age()` / `privacy_request_where()`,
  `QueueJanitor::prune()`, `TransactionalFlusher::process()` /
  `record_exchange()`, `EventsEndpoint::retry()` / `resend()` / `shape_row()`,
  the skipped-outcome writers of both queues (`Flusher`, `CartFlusher`,
  `TransactionalFlusher`, `AbstractD6Flusher`, `CatalogManifest`), migrations
  001–011 (queue indexes), `uninstall.php` (option sweep). The admin
  TypeScript delta read for XSS sinks (none: no `dangerouslySetInnerHTML`; the
  one `innerHTML` in the shipped JS is the pre-existing `recs-core.ts`), the
  reason wording (`transactional-reasons.ts` covers every new code), and the
  delay field. DECISIONS PRO-4049, PRO-4091, PRO-4093, PRO-4095 read against
  the code. `readme.txt`, `docs/DATA_MODEL_GDPR.md` and the docs site checked
  for the new behaviour. No real customer data was used.
- **Method:** static read; static tools read-only on the 22 changed PHP files:
  `vendor/bin/phpcs -n` exit 0; `vendor/bin/phpcs` with warnings 6 warnings, 1
  new (I8); `vendor/bin/phpstan analyse` `[OK] No errors`. CI on `main`:
  "Lint and test the codebase" run 38989373552 on `82db055` green (both
  integration legs included); the `Contract staleness` check has been red since
  run 39375130172 (a contract sync is in a separate PR). **Release-gate build
  and Plugin Check** (below): ZIP built locally from `82db055`, verified, and
  checked with Plugin Check 2.1.0 in the dev wp-env. Not run: unit /
  integration suites locally (CI ran them), any live walk.

## Verdict

**0 Critical, 0 High, 1 Medium, 4 Low, 9 Info.**

**RESULT: 3.18.0 may proceed once M1 is fixed or explicitly accepted, and L1
and L3 are handled at the bump.**

The security-sensitive surfaces are correct. Every touched REST route keeps
its `permission_callback` (`current_user_can( Constants::CAPABILITY )`), and
the new payload fields are read-only projections or strictly validated input
(the delay is a whole number 1–90 or the save is refused with nothing
written). Every new statement against a custom table goes through
`$wpdb->insert()` / `update()` / `prepare()`; the only interpolations are
table names, column names from `OrderBackfillJob::table_spec()` and
placeholder lists. The skipped rows store the order id and a reason code
only, and the follow-up row stores the recipient but no merge tags, so the
privacy eraser still finds and deletes it. The welcome follows the decision
PRO-4093 in every mode (I1). No secret or personal data reaches a log.

The one Medium is in the new follow-up email: its queue row keeps the time it
was scheduled, not the time it was sent, and three readers take that time as
the send time.

---

## Findings

| # | Severity | Where | Summary |
|---|---|---|---|
| M1 | Medium | `includes/Smaily/EventQueue.php:167`; `includes/Smaily/TransactionalStatus.php:122`; `includes/DB/QueueJanitor.php:251`; `includes/REST/EventsEndpoint.php:171` | A follow-up row's `created_at` is the scheduling time (up to 90 days before the send): "Last sent" is off by the delay, the Event Log lists the send at the wrong time, and with a delay over 30 days the janitor deletes every sent or skipped follow-up row at its next daily run |
| L1 | Low | `includes/Smaily/TransactionalStatus.php:154` (disable list `:16`) | One new Plugin Check warning, `PluginCheck.Security.DirectDB.UnescapedDBParameter` (false positive) |
| L2 | Low (magnitude unmeasured) | `includes/Smaily/TransactionalStatus.php:113`–`:134`; callers `includes/Wizard/EnvDetector.php` `transactional_status()`, `includes/REST/SettingsEndpoint.php:599` | The "Last sent" query reads every `sent` row of the Smaily queue, on every Settings page load and every Settings save of any tab |
| L3 | Low | `readme.txt:61`, `:95`, `:155`, `:234`; Changelog | The wordpress.org readme does not name the follow-up email and states a WooCommerce fallback the follow-up does not have; the Changelog has 28 characters left |
| L4 | Low (pre-existing, tooling) | `composer.json:58` (`package:strip-map-refs`) | `composer run package` fails on macOS: GNU-only `sed -i` |
| I1 | Info | `includes/Integrations/WooCommerce/HookHandler.php:294`–`:375`, `:480`–`:515`, `:816`–`:858`; `integrations/woocommerce/profile-settings.class.php:151`–`:206` | Welcome consent verified mode by mode against PRO-4093 |
| I2 | Info | `includes/REST/EventsEndpoint.php:65`, `:512`, `:663` | The Sent filter's SQL and `is_skipped()` agree for every writer in the code |
| I3 | Info | `includes/Smaily/EventQueue.php:536`–`:603`; `includes/Smaily/TransactionalFlusher.php:290`–`:307` | Stored data: skipped rows hold no personal data; the follow-up row holds the recipient up to 90 days, erasable |
| I4 | Info | `includes/REST/SettingsEndpoint.php:338`–`:360`, `:480`–`:490`; `includes/Smaily/TransactionalGate.php` `delay_days_or_null()` | Delay validation and status sanitising are correct; capability and nonce handling unchanged |
| I5 | Info | `includes/Smaily/TransactionalFlusher.php:440`–`:448`, `:493`–`:521`; `includes/Smaily/TransactionalRetryGuard.php:80`–`:85`, `:139` | Follow-up at due time: re-check, retry, Send again and fail-open paths verified; two edge behaviours noted |
| I6 | Info | `includes/Integrations/WooCommerce/LandingCapture.php:231`; `public/js/lib/attribution.ts:137`–`:139` | The two context-cookie writers agree with DECISIONS PRO-4049 |
| I7 | Info | `blocks/checkout-optin/smaily-integration.class.php:64`; `integrations/woocommerce/profile-settings.class.php:402` | `customerSubscribed` is per-user script data on the checkout page; load order safe |
| I8 | Info | `includes/Smaily/TransactionalFlusher.php:290` | One new PHPCS warning (`$match` parameter name), same class as three existing ones |
| I9 | Info | `languages/*`; `docs/site/index.html`; `docs/DATA_MODEL_GDPR.md`; CI | i18n complete; docs site covers the new behaviour; CI green; contract staleness red (separate PR) |

---

## M1 — MEDIUM — a follow-up row keeps its scheduling time as its send time

**Where:** `EventQueue::insert_pending()` (`includes/Smaily/EventQueue.php:167`)
writes `created_at = now` for the follow-up row that
`TransactionalFlusher::schedule_follow_up()` (`:290`) queues with
`next_retry_at = now + delay` (default 7 days, allowed 1–90). When the row
falls due, `process()` marks it `sent` (`mark_sent()`, `EventQueue.php:607`
writes `status` only), `failed`, or skipped (`mark_skipped()`, `:564`).
Nothing moves `created_at`. Three readers treat `created_at` as the time of
the send:

1. **The status line.** `TransactionalStatus::last_sent_at()`
   (`TransactionalStatus.php:122`) takes `MAX( created_at )` of the sent rows.
   For the follow-up card, "Last sent" shows when the newest sent follow-up
   was *scheduled* — the delay earlier than the send.
2. **The Event Log.** The list sorts and shows rows by `created_at`
   (`EventsEndpoint.php:171`). A follow-up sent today appears at its
   scheduling date, a week (or up to 90 days) down the list.
3. **The janitor.** `QueueJanitor` deletes `sent` rows with
   `created_at` older than 30 days (`QueueJanitor.php:251`; `failed` after
   90). With a delay over 30 days, every follow-up row that is sent or
   skipped is already older than 30 days, so the next daily run deletes it.
   The merchant then sees no row in the Event Log and "nothing in the last 30
   days" on the card, while the emails went out. A failed row is kept for 90
   days from scheduling, so with a 90-day delay a failure is deleted within a
   day too.

**Cost.** The status line exists to answer "did it send?" (PRO-4091: a working
email looked broken because nothing showed). For the follow-up it gives a
wrong date at every delay and a false "nothing sent" from a delay of 31 days,
which the Settings save accepts. No email is lost or sent twice: the retry
ceiling already counts from `due_at` (`enforce_retry_ceiling()`, `:551`), and
a revived failed row gets a new `created_at` from `restart_age()`.

**Recommendation (small, before the cut):** when `process()` finishes a
follow-up row (sent, skipped or failed), stamp `created_at` with the current
time in the same update — for example an optional "finished at" argument on
`mark_sent()` / `mark_skipped()` / `mark_failed()`, used only for
`EVENT_TYPE_FOLLOW_UP`, or one `restart_age( array( $id ) )` call before the
mark. A waiting row keeps its scheduling time, so the Event Log still shows
when it was queued, and `scheduled_for` shows when it will go out. Pin it with
a `TransactionalStatusTest` / `TransactionalFlusherTest` case: a row scheduled
40 days ago and sent now reads "Last sent: now" and survives one janitor run.

## L1 — LOW — one new Plugin Check warning

Plugin Check on the built ZIP reports
`PluginCheck.Security.DirectDB.UnescapedDBParameter` ("Unescaped parameter
`$spec['status_col']` used in `$wpdb->get_results()`") at
`includes/Smaily/TransactionalStatus.php:154` (`recent_order_statuses()`).
False positive: the table and column names come from
`OrderBackfillJob::table_spec()` (code constants and `$wpdb->prefix`), the
values go through `prepare()`. It is the same kind as the accepted
`GdprHandler.php` and `CartSessionStore.php` warnings. The file-level
`phpcs:disable` at `:16` lists the WPCS sniffs but not this Plugin Check one;
`EventsEndpoint.php` and `EnvDetector.php` list it.

**Recommendation (before the PCP run on the CI-built ZIP):** add
`PluginCheck.Security.DirectDB.UnescapedDBParameter` to the disable list at
`:16`, or accept it as warning 18.

## L2 — LOW — the "Last sent" query reads every sent row of the Smaily queue

`last_sent_at()` filters `status = 'sent' AND event_type IN ( … ) AND
sent_payload IS NOT NULL AND sent_payload <> ''`. The Smaily queue has
`idx_status_created (status, created_at)` and `idx_created_at` only
(migrations 001, 007, 010, 011), so the database walks the whole `sent` range —
every contact sync, automation and transactional row of the last 30 days —
and reads `event_type` and the `sent_payload` text of each row from the table.
It runs on every Settings page load (`saved_settings( true )`) and in the
answer to every Settings save of any tab (`success_response()`), together with
the bounded recent-orders read (200 rows). Admin-only, not the storefront; the
size is unmeasured. On a store whose contact sync writes many rows a day it
adds a scan to each Settings load and save.

**Recommendation (backlog):** read one row per event type with `ORDER BY
created_at DESC LIMIT 1` (the index then stops at the first match when sends
are frequent), or add `KEY idx_type_status_created (event_type, status,
created_at)` in a migration; and compute the status only for the WooCommerce
tab's save.

## L3 — LOW — readme.txt does not describe the follow-up email

`readme.txt` describes the transactional emails as "order and shipping
confirmations" (`:61`, `:95`, `:153`–`:155`, `:234`) and says "If a Smaily send
fails, the native WooCommerce email is used as a fallback" (`:61`, `:155`). The
follow-up email is not named, and it has no fallback (PRO-4095; `fail_open()`
returns early). The welcome line (`:45`) is already updated (#229). The
Changelog section is **4 972 characters / 810 words** (intro 133,
`= 3.17.1 =` 400, `= 3.17.0 =` 1 613, `= 3.16.1 =` 695, `= 3.16.0 =` 2 131):
against the 5 000-character limit used since the 3.16.0 gate, a 3.18.0 entry
fits only when `= 3.16.0 =` is dropped (about 2 159 characters of room then).
Upgrade Notice entries are 207–285 bytes against Plugin Check's 300.

**Recommendation (at the bump):** name the follow-up email in the description
and FAQ, and say that its failure has no WooCommerce fallback; drop
`= 3.16.0 =` from Changelog and Upgrade Notice.

## L4 — LOW — `composer run package` fails on macOS

`package:strip-map-refs` (`composer.json:58`) runs `sed -i '\|…|d'`. BSD sed
(macOS) reads the script as the `-i` backup suffix and the first file name as
the script: `sed: 1: "staging/smaily-connect/ ...": bad flag in substitute
command`, and `composer run package` exits 1 before it zips. CLAUDE.md
documents this command as the way to reproduce or compare a release ZIP
locally, and the Mac is a supported workstation. CI (Linux) is not affected.
Pre-existing, outside the delta; found while building this gate's ZIP (the
remaining steps were run by hand with `sed -i ''`).

**Recommendation (backlog):** use `perl -ni -e 'print unless m{^//# sourceMappingURL=}'`,
as PRO-4099 did for `bin/build-i18n.sh`.

## I1 — INFO — welcome consent verified mode by mode

Read against DECISIONS PRO-4093, with `ContactAudience` as the audience rule:

- **Consent mode, account.** The welcome starts only in
  `handle_newsletter_change()` (`:854`–`:857`) for a `user_newsletter` change
  0 → 1 made while `$storefront_optin` is set. The flag is set only by
  `run_as_storefront_optin()` (`:506`, restored in `finally`, nesting-safe),
  called from `record_newsletter_optin()` (classic checkout, block checkout,
  registration form) and `Profile_Settings::smaily_save_wc_account_fields()`
  (`woocommerce_save_account_details`, behind the `save_account_details`
  nonce). The wp-admin profile save (`smaily_save_account_fields()`), the
  reconciler (`ContactReconciler::is_applying()` returns first), imports and
  any other meta write run without the flag: consent reaches Smaily, no
  welcome. An unchanged value fires no meta hook (WordPress returns before
  `update_user_meta` when the value is equal), and `$old_value === $new_value`
  returns too, so a subscribed customer's save starts nothing. The checkout
  order path cannot welcome an account here: `should_sync_order_email()`
  returns false for `$customer_id > 0` outside checkout-only mode.
- **Legitimate interest.** An account's tick is noted in
  `$optins_without_record` and welcomed in `welcome_optins_without_record()`
  through `should_sync_user()` (true: the mode syncs every account); a My
  Account 0 → 1 change welcomes through the non-consent branch (`:841`–`:848`).
  A guest is welcomed only with a ticked box and `include_guests`.
- **Checkout-only.** `should_sync_order_email()` returns the tick for guest and
  account; `welcome_optins_without_record()` welcomes nobody (the mode syncs no
  accounts), so each ticked checkout welcomes once.
- **Gates in every path:** setup completed (`gate_closed()`), the Welcome
  toggle, the contact sync switch and audience, then the filter
  (`welcome_allowed()`, `:305`). One welcome per address per request
  (`enqueue_welcome()`, `:320`). `force_opt_in` unchanged (`false`).
- **Not new, recorded:** the checkout tick is self-asserted, so a guest who
  types another person's address and ticks the box makes the store welcome
  that address. The contact sync already subscribed such an address before
  this change; the welcome adds one email. DECISIONS PRO-4093 accepts repeats.
- **Registration order:** `on_woocommerce_created_customer` runs at priority 20
  (`Hooks.php:50`), after both `Profile_Settings` handlers at 10. The
  account-details handler also runs on `woocommerce_created_customer`, but its
  nonce check fails there, so it writes nothing.

## I2 — INFO — the Sent filter's SQL and `is_skipped()` agree

`branch()` adds `COALESCE( last_response, '' ) NOT LIKE '%"outcome":"skipped"%'`
for the Sent filter (`:512`, the marker through `esc_like()` and `prepare()`).
`is_skipped()` decodes `last_response` and compares the top-level `outcome`.
They can differ only for a row whose `last_response` holds an unescaped nested
`"outcome":"skipped"`. Every writer encodes with `wp_json_encode()` and puts
`outcome` at the top level (`Flusher`, `CartFlusher`, `TransactionalFlusher`,
`AbstractD6Flusher`, `EventQueue::skipped_response()`); a Smaily or engine
reply is stored as a JSON value, where a quote inside a string is escaped, so
the marker cannot match inside it. `CatalogManifest`'s skipped outcomes are on
`failed` rows, which both checks leave alone. A `NULL` `last_response` stays in
Sent. Side effect: the list's UNION now carries `last_response` for the
engine queue's sent rows too (it was `''`); those values are small
(`{http, outcome}`), and the raw column is not in the list answer.

## I3 — INFO — what the new rows store

- **Skipped row** (`record_skipped()`, `:536`): `payload = '[]'`,
  `contact_key = NULL`, `sent_payload = NULL`, `last_response =
  {"outcome":"skipped","reason":<code>}`, `entity_id` = order id. No address,
  name or order content. It is not matched by the privacy eraser, and it needs
  not be. Deduplicated per order + trigger per request.
- **Follow-up row** (`schedule_follow_up()`, `:290`): `to`, `workflow_id`,
  `account_key`, `due_at`, no merge tags; `pending` until due (up to 90 days;
  the janitor never prunes `pending`). `contact_key` is NULL (the payload has
  `to`, not `email`), so the eraser matches it through the `"to":` text match
  and deletes it (a `pending` row is deletable); the order's once-per-order
  guard stays `queued`, so no new follow-up is scheduled for it.
  `docs/DATA_MODEL_GDPR.md` states that `pending` rows are never pruned but
  does not say that a follow-up waits up to 90 days; one sentence there would
  make the retention explicit.
- `mark_skipped()` writes `sent_payload` NULL and keeps the `payload` (with
  `to`); the sent-row redaction on erasure covers it.
- No new log line carries an address or a credential.
  `Credentials::password_unreadable()` only reports whether a decryption
  gives `''`.

## I4 — INFO — the Settings save: validation and access

- `followUpDelayDays` is checked before anything on the tab is written
  (`:338`–`:360`): an int, or a string of digits, within 1–90; a float, `true`,
  an empty string or an out-of-range value returns 400. A stored bad value
  reads as the default 7 (`follow_up_delay_days()`), so it can never send at
  once.
- `followUpOrderStatuses` goes through `sanitize_key()` like the shipped set;
  it is not checked against the registered statuses (same as the shipped set).
  A merchant can tick `cancelled`, `refunded` or `failed`; such an order is
  then always skipped at due (`order_closed`) with one Event Log row each.
  Consider leaving those statuses out of the follow-up multi-select.
- `update_option( …follow_up_enabled, false )` on a never-saved option writes
  nothing (CLAUDE.md trap); harmless, the flag defaults to off.
- Every route touched keeps `permission_check()` →
  `current_user_can( Constants::CAPABILITY )`; REST cookie auth needs the
  `wp_rest` nonce. `admin/wizard.php` reads `$_GET['page']` only for a strict
  compare (`$view`). New options are covered by `uninstall.php`'s `smly_plus_%`
  sweep.

## I5 — INFO — the follow-up at due time

- `process()` re-reads the order and re-runs the gate and
  `follow_up_due_reason()` before it sends; the payload is rebuilt from the
  order as it is then, and `due_at` and the resend flag are carried over. A
  skip writes `mark_skipped()` with the reason and stores no exchange over it.
- The main `Flusher` excludes `TransactionalFlusher::EVENT_TYPES` (now three),
  and `pending()` honours `next_retry_at`, so only the transactional flusher
  takes the row, and only when due.
- **Retry** applies to `failed` rows only; a revived follow-up is re-checked at
  due. **Send again** (`enqueue_resend()`) queues an immediate row, which the
  same due-time check can skip with `status_not_counting` when the order has
  moved on — a deliberate merchant action can end as a skipped row. The row
  says why; acceptable.
- `TransactionalRetryGuard::resendable()` excludes rows with a skip reason.
  A `sent` transactional row with a reason-less skipped outcome does not occur:
  `TransactionalFlusher::record_exchange()` writes that outcome only after a
  terminal failure, on a `failed` row.
- No WooCommerce fallback: `fail_open()` returns early for the follow-up
  (`:630`), and the refusal reason makes a failed follow-up retryable.

## I6 — INFO — context cookie writers agree

`LandingCapture::resolve()` takes `smaily_ctx` only when `rec_id_from()`
resolved a rec id — `smaily_rec` or the guarded `utm_content` fallback — and
clears the context for a valid `smaily_rec` without a valid `smaily_ctx`.
`attribution.ts` checks `written.has( recId )` before the context (the context
comes after the rec id in the mapping) and keeps the clear. The browser writer
has no `utm_content` fallback, as before. Matches DECISIONS PRO-4049 and the
CLAUDE.md note.

## I7 — INFO — `customerSubscribed` in the block's script data

`Integration::get_script_data()` adds `Profile_Settings::current_user_subscribed()`
(a `get_user_meta()` of the current user; false for a guest). The value is per
user and is printed on the checkout page, which full-page caches do not serve
to logged-in users. The class is loaded: the block integration registers only
when WooCommerce Blocks loads, and `Profile_Settings` is required whenever
WooCommerce is active. Hiding the box is display only; the server still
accepts an opt-in from the Store API request (the shopper's own).

## I8 — INFO — PHPCS

`phpcs -n` exit 0 on all 22 files. With warnings on: 6 warnings —
`Universal.NamingConventions.NoReservedKeywordParameterNames` for `$match` at
`TransactionalFlusher.php:218`, `:290`, `:328`, `:694` and `$default` at
`LandingCapture.php:370`, `:378`. Only `:290` (`schedule_follow_up()`) is new;
the others predate the delta. Plugin Check does not report this sniff.

## I9 — INFO — i18n, docs, CI

- Re-running `bin/build-i18n.sh` on `82db055` (host wp-cli) changes only
  reference lines and the creation date in the `.pot` / `-et.po` — no new or
  removed `msgid`. `msgfmt --statistics`: 681 translated, no fuzzy, no
  untranslated.
- The docs site describes the follow-up email, the status line, the skipped
  rows and the welcome rule (EN + ET, approved by Erkki 2026-10-16); it is
  published after the release.
- CI "Lint and test the codebase" is green on `82db055` (run 38989373552). The
  `Contract staleness` workflow is red since run 39375130172; a contract sync
  is in a separate PR and does not touch shipped code.

---

## Release gate — built ZIP, verify, Plugin Check

- **Build** (worktree on `82db055`, macOS): `npm run build:admin` (bundle-scope
  check OK), `composer run install-block-modules && composer run build`,
  `WP_CLI_BIN=vendor/bin/wp bash bin/build-i18n.sh` (committed `.pot` / `.po`
  restored afterwards), `composer install --no-dev --optimize-autoloader`,
  `composer run package` — which failed at `package:strip-map-refs` on BSD sed
  (L4); the staged tree was stripped with `sed -i ''` and zipped by hand, the
  same steps — then `composer install`.
- **ZIP:** `smaily-connect.zip`, 971 122 B, SHA256
  `ecd0bbb0d8da6aa58658aaaef11816d4b8174d68893a25dd4c1c5d1870d1d840`,
  build-hash `82db055` (clean), 403 entries. Version still 3.17.1 (the bump
  comes later); this is a local reference build, not the release asset.
- **`bash bin/verify-release-zip.sh smaily-connect.zip`** (repo root):
  **VERIFY OK** — every required file present, no tests / docs / sources /
  maps / dev vendor, no `sourceMappingURL` trailer, bundles IIFE with no global
  leak (12 checks), version 3.17.1 consistent.
- **Plugin Check 2.1.0** against the built ZIP in the dev wp-env (unzipped to
  `wp-content/plugins/smaily-connect-pkg`, `wp plugin check smaily-connect-pkg
  --slug=smaily-connect --format=csv --allow-root --exclude-directories=vendor`;
  the copy removed afterwards): **0 ERRORS / 18 WARNINGS.** The 17 accepted at
  the 3.17.1 gate, three at new lines: `DirectQuery` + `NoCaching` at
  `BackfillJob.php:677`, `IngestQueue.php:398`, `:427`, `QueueJanitor.php:225`,
  `EventsEndpoint.php:784` (was `:728`); `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311` (was
  `:298`), `MarketingConsent.php:42`; `UnescapedDBParameter` at
  `GdprHandler.php:654` (was `:655`), `CartSessionStore.php:193`, `:217`.
  **NEW: `PluginCheck.Security.DirectDB.UnescapedDBParameter` at
  `TransactionalStatus.php:154`** (L1). No error.

## Release punch-list — 3.18.0

1. **M1** — stamp a follow-up row's `created_at` when it is finished (or Erkki
   accepts it with a DECISIONS note).
2. **L1** — add the Plugin Check sniff to `TransactionalStatus.php`'s disable
   list, or accept warning 18.
3. **L3** — readme: name the follow-up email, say it has no fallback; drop
   `= 3.16.0 =`; version bump in the nine places CLAUDE.md lists.
4. **PCP against the CI-built ZIP** after the bump — expect the 17 accepted
   warnings at the lines above, plus L1's unless suppressed.
5. Record this audit in STATUS.md (the release-bump PR, batch rule).

Backlog: L2, L4; the I3 DATA_MODEL_GDPR sentence; the I4 multi-select note.

## Conclusion

The delta moves the welcome onto the shopper's own opt-in without widening who
gets it: every storefront writer is scoped, staff edits, imports and the
reconciler stay silent, and the contact sync's audience is the one rule. The
new admin fields are read-only or strictly validated, the new SQL is prepared,
and the new rows hold no more personal data than they need. Static gates are
clean, CI is green, the built ZIP verifies, and Plugin Check adds one false
positive. **3.18.0 may proceed once M1 is fixed or accepted, and L1 and L3 are
handled at the bump.**

---

## Disposition (2026-10-22)

| # | Disposition |
|---|---|
| M1 (Medium) | **Fixed** in CON-169 (#244): `TransactionalFlusher::process()` re-dates a follow-up row to the current time (`EventQueue::restart_age()`) when it finishes the row — sent, skipped or failed. A waiting row, or a row retried after a transient failure, keeps its queue time; `scheduled_for` still says when it goes out. Pinned in `TransactionalFlusherTest` (unit) and `TransactionalEmailsPipelineTest` (integration: a 60-day follow-up shows its send time in the row, the Event Log and "Last sent", and survives `QueueJanitor::run()`). |
| L1 (Low) | **Fixed** in the 3.18.0 bump (CON-183): `PluginCheck.Security.DirectDB.UnescapedDBParameter` is added to the file-level `phpcs:disable` list of `TransactionalStatus.php` (`:16`), as in `EventsEndpoint.php` and `EnvDetector.php`. The false positive is confirmed: `recent_order_statuses()` interpolates only the table and column names from `OrderBackfillJob::table_spec()` (code constants and `$wpdb->prefix`); the two values go through `prepare()`. |
| L2 (Low) | Backlog **CON-170** (read one row per event type, or an `(event_type, status, created_at)` index; compute the status only for the WooCommerce tab's save). Not a 3.18.0 blocker. |
| L3 (Low) | **Fixed** in the 3.18.0 bump (CON-183): `readme.txt` names the follow-up email in the description, the external-services list, the FAQ and screenshot 4, and says that the follow-up has no WooCommerce fallback (a failed follow-up is retried from the Event Log). `= 3.16.0 =` is dropped from Changelog and Upgrade Notice; the Changelog section with the new `= 3.18.0 =` entry is 4 760 characters including its heading. |
| L4 (Low) | Backlog **CON-171** (`composer run package` on macOS: replace the GNU-only `sed -i`). CI is not affected. |
| I1–I9 (Info) | Accepted as recorded above. The I3 `DATA_MODEL_GDPR.md` sentence and the I4 multi-select note stay backlog candidates. |

## Addendum: post-audit delta (`b9ee014..ef1fe3d`)

After this audit was recorded (#243, `b9ee014`), five PRs landed on `main`
before the 3.18.0 bump: **5 commits, 34 files, +1 047 / −139**; outside
`docs/` and `tests/` 22 files, +422 / −76; shipped PHP 7 files, +55 / −15
(`includes/`, `uninstall.php`); shipped TypeScript sources 8 files,
+126 / −17 (tests excluded).

- **#244 CON-169** — the M1 fix (see Disposition).
- **#245 CON-172** — a save of the Campaign Intelligence automations sends
  only the fields the merchant changed (`toPutRow()`, `changedFields`), and
  the REST proxy `AutomationsEndpoint::put_config()` drops `test_mode: false`
  from every row.
- **#247** — refactor of #245 after the simplification review (admin
  TypeScript only, no behaviour change).
- **#246 CON-51 / CON-52** — comments in `uninstall.php` and
  `RecEngineSettings::disconnect()`, a new unit test
  (`NoStoreDeletionRequestTest`) and docs-site retention text. No code path
  changed.
- **#248 CON-178** — the engine `vt_` token is `vt_` + 1–61 characters
  (64 in all) in `AttributionShape`, `attribution.ts` and the order-meta cap.

**Security look at the touched surfaces:**

- **REST route.** `PUT /automations/config` keeps
  `permission_callback` → `current_user_can( Constants::CAPABILITY )` and the
  `wp_rest` nonce of REST cookie auth. The new step only removes a key: a row
  whose `test_mode` is exactly `false` loses that key, every other value goes
  to the engine unchanged, and the engine's schema stays the validator
  (F3-51). The proxy can therefore send less than the browser asked for,
  never more. The api_key stays server-side. A non-boolean `test_mode` is
  passed through and the engine refuses it with its indexed 422, as before.
- **SQL.** The only new statement path is `EventQueue::restart_age()` from
  the transactional flusher: an `UPDATE … SET created_at = %s WHERE id IN
  (%d, …)` through `prepare()`, with the table name from the class and ids
  from `clean_ids()`. It runs for one row id the flusher itself holds, only
  for `transactional.follow_up`. No new query reads user input.
- **Stored data.** No new column, option or row type. CON-169 changes only a
  finished follow-up row's `created_at`, so the janitor now keeps it 30
  (sent) or 90 (failed) days from the send instead of from the queueing —
  the retention `docs/DATA_MODEL_GDPR.md` states. CON-172 sends the test
  addresses (`test_emails`) to the engine less often (only when the merchant
  changed them); nothing new is stored. CON-178 narrows the accepted token
  shape and the stored order-meta length (67 → 64), so it can only refuse
  more input.
- **Engine data deletion.** Uninstall and Disconnect make no engine request
  (confirmed by reading both paths and by the new unit test).
- **Translations.** One `msgid` changed by hand in `.pot` and `-et.po`
  (the go-live confirm text); `msgcmp` reports the catalogues consistent,
  `msgfmt --check` 681 translated.

**Findings: none.** The addendum adds no Medium or Low finding, and the
verdict stands with M1 fixed: **3.18.0 may proceed.**
