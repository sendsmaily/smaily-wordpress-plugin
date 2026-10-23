# Security + code-quality delta audit — 3.19.0 pre-release gate (3.18.0..a165e70)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-22_3.18.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-22_3.18.0.md),
which covered everything up to the 3.18.0 tag (its addendum included). This
report covers what landed after the tag. The earlier reports stay valid for
what they covered.

- **Date:** 2026-10-23
- **Baseline:** delta `3.18.0..a165e70` (tag `3.18.0` = `d3209da` → `origin/main`
  `a165e70`): **13 commits, 90 files, +5 396 / −1 594**; outside `docs/` and
  `tests/` **47 files, +2 172 / −1 543** (of it the root lockfile and the
  `.pot` / `-et.po` catalogues +462 / −837). The shipped PHP is **28 files,
  +967 / −650** (`includes/`, `integrations/`, `migrations/`, `uninstall.php`;
  the deletions are mostly the legacy `Cart` class, the legacy `Cron`
  abandoned-cart passes and migration 005); the shipped TypeScript sources are
  **8 files, +272 / −13** (tests excluded). The work in it:
  - **#251 CON-180** — a cleared workflow goes to the engine as
    `automation_map: {}` (`AutomationsEndpoint::put_config()`).
  - **#252 CON-170** — the "Last sent" line reads one `LIMIT 1` row per event
    type (`TransactionalStatus::last_sent_at()`).
  - **#253 CON-171** — `composer run package` strips source-map trailers with
    `perl`, so it runs on macOS too.
  - **#254 + #255 CON-173** — "Edit design" on every email card: new admin
    REST routes `GET /rec-engine/emails/designs` and
    `POST /rec-engine/emails/design-link` (`REST\EmailDesignsEndpoint`, the
    shared `RecEngineProxy` trait), `Client::email_designs()` /
    `email_design_link()` / `templated_url()`, the stored workflow ids
    (`Settings\EmailDesignWorkflows`, option `smly_rec_email_workflows`) and
    their use in `Multilingual\Router::resolve_workflow()`.
  - **#257 CON-2** — a withdrawn cart reminder is left out of the Event Log's
    Sent filter (`EventsEndpoint::sent_nothing()`, `branch()`).
  - **#259 CON-57** — Vite 6 / Vitest 4 (dev tools only).
  - **#260 CON-196** — the abandoned-cart consent gate
    (`CartFlusher::consent_refusal()`), the classic-checkout newsletter tick in
    the cart tracker's `cart_content` JSON (`CartSessionStore::
    set_newsletter_optin()` / `encode_content()` / `decode_content()`,
    `CartHookHandler`, `CartPayloadBuilder`).
  - **#261 CON-202** — the checkout newsletter field carries WooCommerce's
    `update_totals_on_change` class.
  - **#258 CON-182** — schema migration 012 (`migrations/012-drop-retired-tables.php`
    → `Migration\RetiredTables`): drains the legacy carts, then drops
    `{prefix}smaily_connect_abandoned_carts` and `{prefix}smly_rec_visitor`;
    `Migrator` runs `.php` migrations; the legacy cart code, migration 005 and
    `Lifecycle::create_woocommerce_tables()` are removed; `uninstall.php` lists
    the legacy table.
  - **#250, #256** — docs only.
- **Auditor:** Claude (Opus 5.5), read-only on the code.
- **Trigger (re-audit policy):** point 1 (the 3.19.0 release boundary) and
  point 2 (**consent**: the abandoned-cart gate and the checkout tick;
  **new REST routes**: the two email-design routes, the changed automations
  proxy; **SQL against custom tables**: the Sent filter, the "Last sent"
  queries, the tracker's new reads and writes, the `DROP TABLE` migration;
  **what gets stored**: the workflow-id option, the tick in `cart_content`, the
  dropped personal-data table). Point 3 too: the engine now names the Smaily
  workflow that the plugin's own emails start (a new trust input on the send
  path).
- **Scope:** file-by-file read of the shipped PHP and TypeScript delta plus
  the call chains it reaches: `AutomationRouter::trigger_automation()` and
  `TransactionalGate::evaluate()` (both call `Router::resolve_workflow()`),
  `Client::get_contact_consent()`, `ContactSyncMode`, `ContactAudience::OPTIN_META`,
  `CartAbandonmentSweeper` (what it queues), `Bootstrap::maybe_run_upgrade()`
  → `UpgradeLock` → `Activation::run()` → `Migrator`, `LegacyCartDrain` in
  full, `Lifecycle` (activation, deactivation, legacy migrations),
  `uninstall.php` (tables and `smly_rec_%` / `smly_plus_%` sweeps),
  `RecEngineSettings::store()` / `disconnect()`, the engine client's logging,
  the admin `EmailDesigns.tsx` / `emailDesigns.ts` / `EventLog.tsx`.
  DECISIONS CON-57, CON-173, CON-182, CON-196 and contract §17, §22, §23 read
  against the code. `readme.txt`, `docs/DATA_MODEL_GDPR.md` and the docs site
  checked for the new behaviour. No real customer data was used.
- **Method:** static read; static tools read-only on the 25 changed PHP files
  that still exist: `vendor/bin/phpcs -n` exit 0; `vendor/bin/phpcs` with
  warnings **0 warnings**; `vendor/bin/phpstan analyse` `[OK] No errors`. CI on
  `main`: "Lint and test the codebase" run 39941604919 on `a165e70` green (both
  integration legs included); `Contract staleness` run 39941604884 green.
  **Release-gate build and Plugin Check** (below): ZIP built locally from
  `a165e70` with `composer run package` unchanged, verified, and checked with
  Plugin Check 2.1.0 in the dev wp-env. Not run: unit / integration suites
  locally (CI ran them), any live walk.

## Verdict

**0 Critical, 0 High, 2 Medium, 6 Low, 8 Info.**

**RESULT: 3.19.0 may proceed once M1 is fixed or explicitly accepted, M2 is
explicitly accepted, and L1 and L6 are handled at the bump.**

The security-sensitive surfaces are correct. Both new routes and the changed
automations proxy check `current_user_can( Constants::CAPABILITY )`
(`manage_options`) through the shared trait, and REST cookie auth needs the
`wp_rest` nonce. The api_key never reaches the browser, and the one-time
design link is neither stored nor logged and is sent with
`Cache-Control: no-store`. Every new statement against a custom table goes
through `prepare()` / `insert()` / `update()`; the only interpolations are
table names from constants. Migration 012 drops nothing it could not drain
first, retries on the next update after any failure, and removes a table of
personal data that had no retention period. The consent gate follows DECISIONS
CON-196 in all three modes (I1), and the queue payload's consent inputs never
reach Smaily.

The two Mediums are about what the new code trusts. M1: the engine's pushed
workflow id replaces the mapped one but keeps the mapped Smaily account and
language; the account question is open in DECISIONS CON-173, and the override
turns on by itself the day the engine starts pushing, with no plugin release
in between. M2: in the two consent modes the gate counts "subscribed in
Smaily" as consent, and before this release every reminder created such
contacts itself.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| M1 | Medium | `includes/Multilingual/Router.php:82`–`:84`; `includes/Settings/EmailDesignWorkflows.php:56`–`:73` | The pushed workflow id replaces the mapped one but keeps the mapped account and language: the follow-up email starts it on the `transactional` account, a Mode A language account starts it on that account, and Mode B's per-language workflows collapse into one. Dormant until the engine pushes (§23 "not live yet"), then live without a release | **Yes** — fix (override only on the `default` account / default language) or accept explicitly |
| M2 | Medium | `includes/Smaily/CartFlusher.php:282`–`:284` | In the consent modes a contact Smaily has as subscribed counts as consent — but each reminder before 3.19.0 created a non-contact as subscribed, so those shoppers keep getting reminders | **Yes** — Erkki accepts (DECISIONS addendum) and the merchant docs say how to find such contacts |
| L1 | Low | `includes/Migration/RetiredTables.php:67` (disable list `:14`) | One new Plugin Check warning, `PluginCheck.Security.DirectDB.UnescapedDBParameter` (false positive) | Yes — at the bump |
| L2 | Low | `includes/REST/EmailDesignsEndpoint.php:158`–`:170` | `allowed_return_url()` uses PHP `parse_url()`, which reads `https://evil.example\@shop.example/` as host `shop.example` (a browser goes to `evil.example`); user info and a port also pass. Not exploitable today | No — backlog |
| L3 | Low | `includes/Settings/EmailDesignWorkflows.php:42`, `:61` | Ids of `kind: transactional` items are kept, though §23 gives a workflow id only to `sent_through: workflow`; a non-null id there would re-route order and shipping confirmations | No — backlog (or with M1) |
| L4 | Low | `includes/Migration/LegacyCartDrain.php:173`; `includes/Smaily/CartSessionStore.php:100` | The drain counts a row as copied without checking that the tracker write succeeded, and migration 012 then drops the source; a store that drained on 3.11.2+, went back to 2.0.0 and returns loses the carts written meanwhile | No — backlog |
| L5 | Low (magnitude unmeasured) | `includes/Smaily/TransactionalStatus.php:124`–`:132` | The per-type `LIMIT 1` query stops early only for a type that sends; for a type with no sent row it walks every sent row, so the worst case is now three walks instead of one | No — backlog (CON-170 follow-up) |
| L6 | Low | `readme.txt` (`:47`, Changelog, Upgrade Notice) | The readme does not say that reminders now need consent in the consent modes, does not name "Edit design", and must not call 3.19.0 a "Safe update" without naming the one-way table drop; Changelog has 255 characters left | Yes — at the bump |
| I1 | Info | `includes/Smaily/CartFlusher.php:265`–`:297`; `includes/Integrations/WooCommerce/CartHookHandler.php:143`–`:148`; `includes/Smaily/CartSessionStore.php:173`–`:251`; `includes/Smaily/CartPayloadBuilder.php:142`–`:150` | Consent gate verified mode by mode against CON-196 |
| I2 | Info | `includes/Migration/RetiredTables.php:51`–`:79`; `includes/DB/Migrator.php:74`, `:131`–`:138`; `includes/Migration/LegacyCartDrain.php:109`–`:127` | Migration 012 failure modes: partial drop, drain failure, re-run, fresh install |
| I3 | Info | `includes/REST/EmailDesignsEndpoint.php`; `includes/REST/RecEngineProxy.php:36`–`:45`; `admin/src/components/steps/EmailDesigns.tsx` | New routes: capability, nonce, input pattern, link handling, no secret or link stored or logged |
| I4 | Info | `includes/REST/EventsEndpoint.php:517`–`:522`, `:681`–`:691`; `includes/Smaily/CartSessionStore.php:83`–`:86`, `:188`, `:203` | SQL: every new statement prepared; the Sent filter and `sent_nothing()` agree |
| I5 | Info | `includes/Settings/EmailDesignWorkflows.php:29`; `includes/Settings/RecEngineSettings.php:198`, `:226`; `uninstall.php:84`, `:157`; `docs/DATA_MODEL_GDPR.md:120` | Stored data: the workflow-id option, the tick in `cart_content`, the dropped tables; one GDPR-doc line is stale |
| I6 | Info | `includes/REST/AutomationsEndpoint.php:176`–`:187` | `automation_map: {}` only changes an empty array to an empty object |
| I7 | Info | `includes/Migration/LegacyCartDrain.php:141` | Pre-existing: the drain `maybe_unserialize()`s the legacy cart (WooCommerce objects inside); now runs once more on stores updating from 1.x / 2.0.0 |
| I8 | Info | `composer.json:61`; `package.json`; `languages/*`; CI | Tooling, static gates, i18n, CI |

---

## M1 — MEDIUM — the pushed workflow keeps the mapped account and language

**Where:** `Router::resolve_workflow()` (`includes/Multilingual/Router.php:73`–`:85`)
resolves the mapped `WorkflowMatch`, then, when `EmailDesignWorkflows::workflow_id()`
returns an id, returns `new WorkflowMatch( $pushed, $match->account_key,
$match->matched_language )`. The id comes from the last §23 read
(`EmailDesignWorkflows::remember()`, `:56`–`:73`), one id per email key — not
per account, not per language.

Three store set-ups get an id from one Smaily account started on another, or
one design for several languages:

1. **Follow-up email.** `follow_up` is in `TransactionalGate::TRIGGERS`
   (`includes/Smaily/TransactionalGate.php:104`), so its match is on the
   `transactional` account — a separate Smaily account by design. Its §23 key
   `plugin.follow_up` has `sent_through: workflow`, so the engine will return a
   workflow id for it. Unless the engine pushes that workflow into the
   transactional account, the plugin triggers the id on the wrong account.
2. **Mode A** (one Smaily account per language): welcome, first order and
   abandoned cart start the pushed id on each language's account.
3. **Mode B** (per-language workflows): every language starts the same pushed
   workflow; the per-language mapping no longer decides the design.

Smaily workflow ids are numbered per account (an assumption — not checked
against the Smaily API): on the wrong account the id either does not exist
(the email fails; a failed follow-up has no WooCommerce fallback) or names a
different workflow of that account, which then starts with the shopper's
data. DECISIONS CON-173 records the account question as **Open** ("If the
engine pushes to one account only, the override must name that account
before the push goes live").

**Why it matters now:** contract §23 says the push "is not live yet" — until
then every id is `null` and the override does nothing. When Smaily's
automations API extension ships, the engine starts returning ids and every
3.19.0 store switches on the next email-settings read. No plugin release sits
between those two moments, so the Open item has no gate on the plugin side.

**Recommendation (before the cut, small):** apply the override only where the
answer is known — `$match->account_key === 'default'` and a single-language
mapping (`matched_language === 'default'`) — and keep the mapped match
elsewhere, until the engine names the account (and language) of a pushed
workflow. Pin it in `RouterTest` (a `transactional` follow-up and a Mode A
row keep their mapped id). Or accept it explicitly, with the engine team's
commitment in DECISIONS CON-173 not to push before the account question is
closed.

## M2 — MEDIUM — "subscribed in Smaily" includes contacts the old reminders created

**Where:** `CartFlusher::consent_refusal()` (`includes/Smaily/CartFlusher.php:265`).
In the consent and checkout-only modes, a found contact with
`is_unsubscribed === '0'` lets the reminder out (`:282`–`:284`), before the
store record and the tick are read. DECISIONS CON-196 chose this ("a
Smaily-side subscription is consent too").

The same entry states the problem the gate fixes: before 3.19.0 "a reminder to
a shopper who never agreed to marketing emails also subscribed them" — Smaily
creates an address an automation is triggered for as a subscribed contact. So
every shopper who got a reminder on a consent-mode store before this release,
and was not a contact before, is now a subscribed Smaily contact, and the gate
reads that as consent: they keep getting reminders. A store that ran
"All customers" and moved to a consent mode has the same residue from the
contact sync. The plugin cannot tell these contacts apart from real
subscribers.

**Cost.** The cohort is bounded (shoppers reminded before 3.19.0), and Smaily
honours an unsubscribe. But it is the consent surface: the reminder goes out
on a basis the plugin itself created. The larger effect is outside the gate —
these contacts also get the merchant's newsletters — and no code change here
removes it.

**Recommendation (before release, a decision):** Erkki accepts the residue in
a DECISIONS CON-196 addendum, and the merchant docs (consent section) tell a
consent-mode merchant that earlier reminders may have subscribed shoppers, and
how to find them in Smaily (contacts created by the abandoned-cart workflow).
A code-side narrowing — trust "subscribed in Smaily" only when the plugin's
own contact sync sent it — needs a Smaily-side marker the plugin does not have
today; backlog if wanted.

## L1 — LOW — one new Plugin Check warning

Plugin Check on the built ZIP reports
`PluginCheck.Security.DirectDB.UnescapedDBParameter` ("Unescaped parameter
`$suffix` used in `$wpdb->query()`") at `includes/Migration/RetiredTables.php:67`.
False positive: `$suffix` iterates over the two class constants
`LEGACY_CART_TABLE` and `VISITOR_TABLE`; `DROP TABLE` takes no placeholder.
The file-level `phpcs:disable` at `:14` lists the WPCS sniffs but not this
Plugin Check one.

**Recommendation (at the bump):** add
`PluginCheck.Security.DirectDB.UnescapedDBParameter` to the disable list at
`:14`, or accept it as warning 18.

## L2 — LOW — `return_url` host check disagrees with browsers on a backslash

`allowed_return_url()` (`:158`) passes a URL when `wp_parse_url()` gives
scheme `https` and the host of `get_site_url()`. PHP's parser reads
`https://evil.example\@shop.example/x` as user `evil.example\`, host
`shop.example` (checked: `parse_url()` returns host `shop.example`); a WHATWG
parser (browsers, Node) treats `\` as `/` and goes to `evil.example`. User info
(`https://u:p@shop.example/`) and a port also pass.

**Not exploitable today:** the value is `window.location.href` of the admin
page, sent by an admin with `manage_options` and the `wp_rest` nonce, so a
third party cannot choose it; and the engine re-checks the host under §17
(422 `validation_failed`). The plugin check is a pre-filter, but it should not
be weaker than the rule it mirrors.

**Recommendation (backlog):** refuse a URL that contains `\`, has `user` /
`pass`, or has a `port`.

## L3 — LOW — workflow ids of transactional items are kept

`EmailDesignWorkflows::KINDS` (`:42`) accepts `plugin` and `transactional`
items, and `remember()` keeps any numeric `smaily_workflow_id`. §23 says
`smaily_workflow_id` is "`sent_through: "workflow"` only … `null` for a
transactional email", and a transactional email is sent "with that
template". Today the engine returns `null`, so nothing happens; but an engine
bug or a later contract change that puts a workflow id on
`transactional.order_confirmation` would re-route order confirmations through
the router's override without any plugin change.

**Recommendation (backlog, or with M1):** keep an id only when
`sent_through === 'workflow'`.

## L4 — LOW — the drain trusts its writes before the table is dropped

`LegacyCartDrain::drain()` (`:173`) calls `CartSessionStore::upsert()` and
counts the row as drained. `upsert()` uses `$wpdb->insert()` /
`$wpdb->update()` (`CartSessionStore.php:100`, `:138`) and does not report a
failed write, so a row that did not reach the tracker (for example a
`smly_plus_cart_session` table that migration 009's `dbDelta()` never created)
is counted as copied, and `RetiredTables::run()` then drops the source.

A second, narrower path: a store that drained on 3.11.2+ keeps the stamp. If
it went back to 1.x / 2.0.0 (which write the legacy table again) and then
updates to 3.19.0, the stamp skips the drain and the new legacy rows are
dropped uncopied.

**Cost:** in-flight cart reminders only (no order or contact data); both paths
are rare.

**Recommendation (backlog):** after each `upsert()` in the drain, throw when
`$wpdb->last_error` is set (the drain then fails, nothing is dropped), and
have `RetiredTables::run()` check that the tracker table exists before it
drops.

## L5 — LOW — "Last sent" is cheaper only for types that send

CON-170 replaced the grouped `MAX( created_at )` with one
`… ORDER BY created_at DESC LIMIT 1` query per event type
(`TransactionalStatus.php:124`–`:132`). With `idx_status_created
(status, created_at)` the database walks the `sent` rows newest first and
reads `event_type` and `sent_payload` from each row until one matches. For a
type that sent recently this stops at once. For a type with no sent row — a
store with the follow-up or shipping confirmation off, which is common — the
walk reads every `sent` row of the queue. With three types the worst case is
three full walks per Settings load and save, where the old query made one.
Admin-only; the size is unmeasured.

**Recommendation (backlog):** add `KEY idx_type_status_created (event_type,
status, created_at)` in a migration (each query then reads one index entry),
or skip the query for a trigger that is switched off.

## L6 — LOW — readme.txt at the bump

- `:47` "Remind a shopper about an abandoned cart after your chosen delay" —
  in the consent and checkout-only modes a reminder now goes out only with
  consent (CON-196). The FAQ or description should say so; merchants will see
  fewer reminders and new `skipped` rows.
- "Edit design" (CON-173) is not named. It talks to the existing engine
  service, so the external-services list needs no new destination; the
  description of what is sent to the engine could name the design link
  request.
- The 3.18.0 Upgrade Notice says "Safe update". 3.19.0 drops two tables
  (CON-182); after it, a downgrade cannot resume the legacy carts. The Upgrade
  Notice should say that the update removes the legacy abandoned-cart table
  after moving its carts.
- The Changelog section is **4 745 characters** (intro 134, `= 3.18.0 =`
  1 903, `= 3.17.1 =` 400, `= 3.17.0 =` 1 613, `= 3.16.1 =` 695): 255
  characters of room against the 5 000 used since the 3.16.0 gate; dropping
  `= 3.16.1 =` gives about 950.

**Recommendation (at the bump):** the three text changes, drop `= 3.16.1 =`
from Changelog and Upgrade Notice, the version bump in the nine places
CLAUDE.md lists.

## I1 — INFO — consent gate verified mode by mode

Read against DECISIONS CON-196 and `ContactSyncMode`:

- **Every mode:** a found Smaily contact with `is_unsubscribed === '1'` →
  `unsubscribed` skip (`:274`). The read is on the `default` account, where
  contact sync writes. A Mode A unsubscribe made in a language account is not
  read; Smaily still keeps it there (`force_opt_in` stays `false`).
- **All customers:** everything else goes out (`requires_optin()` false).
- **Consent:** a subscribed Smaily contact, the tick (`newsletter_optin ===
  true` in the payload), or the cart's WP user with `user_newsletter = 1`
  (read at send time) → send; else `no_marketing_consent`.
- **Checkout-only:** the same without the WP user record (the mode keeps
  none for accounts).
- **Skip:** `EventQueue::mark_skipped()` — `sent`, out of the Sent filter, no
  exchange stored over it, not resendable. The reason words exist in
  `admin/src/lib/transactional-reasons.ts`.
- **Read failure:** `get_contact_consent()` throws → the row takes the retry
  path, nothing is sent blind; the read's exchange is kept for the Event Log
  (no Authorization header — `Client::request()` captures method, endpoint,
  body and reply).
- **Fail-closed edges:** a Smaily `{code, …}` body reads as "not found"; in a
  consent mode that becomes a terminal `no_marketing_consent` skip when the
  store has no record, never a send. A reminder queued before the update has
  no `user_id` / `newsletter_optin`; in a consent mode it goes out only with a
  subscribed Smaily contact.
- **The tick:** written only from `woocommerce_checkout_update_order_review`
  for an existing tracker row (`set_newsletter_optin()`, last report wins,
  unticking clears it), kept across the following cart save by `upsert()`, and
  read at sweep time into the payload's top level. `CartFlusher::dispatch()`
  builds the Smaily call from `email`, `language` and `fields` only, so
  `user_id` and `newsletter_optin` never reach Smaily. An unticked cart keeps
  the plain list every earlier version reads; a ticked one is
  `{"items": […], "newsletter_optin": true}`, which a downgraded 3.18.0
  would read as a two-element item list (its shape guards skip non-item
  entries, so at worst one reminder with no products).
- **Self-asserted tick:** a guest who types another person's address and
  ticks the box makes the store remind that address. Before CON-196 every
  cart was reminded without a tick, so this is narrower than 3.18.0; same
  class as the accepted 3.18.0 I1 welcome note.
- **CON-202:** `update_totals_on_change` only makes WooCommerce post the order
  review when the box changes; the review already carried the box and the
  billing address.

## I2 — INFO — migration 012 failure modes

- **Drain failure:** `LegacyCartDrain::drain()` now throws when `SHOW TABLES`
  or the `SELECT` fails (`$wpdb->last_error` set, `:111`, `:123`); the stamp
  is written only after a full drain (`maybe_run()`, `:81`).
  `RetiredTables::run()` catches it, logs the exception class only, drops
  nothing and returns `false`.
- **Migrator:** `apply()` returns `false` only for a `.php` migration whose
  callable returns `false` (`:137`); the loop then `break`s without recording
  version 12 (`:74`), so nothing after it runs. `Activation::run()` still
  stamps the plugin version, so the retry happens on the **next plugin
  update**, not on the next page load — as DECISIONS CON-182 says.
- **Partial drop:** the legacy table is dropped first; if the visitor-table
  `DROP` fails, `run()` returns `false` with the stamp kept; the re-run skips
  the drain (stamp) and `DROP TABLE IF EXISTS` is a no-op for the dropped
  table. The stamp is deleted only after both drops.
- **Re-run and fresh install:** idempotent (`IF EXISTS`, the stamp). On a fresh
  install no legacy table exists; `Lifecycle::activate()` no longer creates
  it, and migration 005 is gone (a store past 005 never re-runs it).
- **Concurrency:** the step runs inside `UpgradeLock`; a fatal error during
  the drain (memory on a very large legacy table) skips the lock's `finally`
  and is retried after the 15-minute stale takeover — the same as before,
  when the drain ran in `Activation::run()`.
- **Liveness note:** a 012 that fails on every update (for example a database
  user without `DROP`) blocks every later migration, with a debug-log line as
  the only signal. Worth a Settings notice before migration 013 depends on it.
- **Code removed with the table:** nothing in `includes/`, `integrations/` or
  `uninstall.php` reads either table any more (grep); the legacy `Cron` hooks
  stay unregistered; `uninstall.php` and `Lifecycle::uninstall()` still drop
  both tables for a store deleted before 012 ran.
- `.php` migrations: `glob( '/*' )` plus `^(\d+)-.+\.(sql|php)$`, so
  `upgrade-1-3-0.php` and `index.php` are ignored; each migration file has the
  `ABSPATH` guard.

## I3 — INFO — the email-design routes

- `register_rest_route()` with `permission_callback` →
  `RecEngineProxy::permission_check()` → `current_user_can( 'manage_options' )`
  for both routes (and, through the trait, the automations routes). Cookie
  auth needs the `wp_rest` nonce, so the `GET` that writes
  `smly_rec_email_workflows` cannot be forged cross-site.
- `email_key` is required and matches `^[a-z][a-z0-9_.]{0,100}$` — no `/`, no
  leading `.`; `templated_url()` `rawurlencode()`s it into the path.
- Both routes return 503 `not_available` before any engine call unless
  `sending_allowed()` and a key and base URL exist; the client has one attempt
  (`EndpointRegistry`), so an engine `Retry-After` does not hold the admin.
- The design link: returned with `Cache-Control: no-store`; not written to an
  option, a queue row or `DebugLog` (the engine client logs only the version
  warning); `is_engine_link()` lets through only an `https://` URL or the
  engine's own origin, so `tab.location.href = url` cannot run a
  `javascript:` URL. The admin opens a blank tab, sets `opener = null`, and
  closes the tab on failure.
- Errors: 401 → 502 `api_key_rejected`; 422 returns the engine's `errors`;
  other failures return the engine's error code and message — no
  Authorization header, no key.
- `return_url` is the whole admin page URL with its query string; today no
  admin page of the plugin carries a secret in its URL.
- The engine body of `GET …/designs` goes to the admin as-is; React renders
  `subject` and the labels as text (no `dangerouslySetInnerHTML` in the
  delta).

## I4 — INFO — SQL

- `EventsEndpoint::branch()` adds one `COALESCE( last_response, '' ) NOT LIKE
  %s` per outcome in `NOTHING_SENT_OUTCOMES` (`skipped`, `cancelled`), each
  marker through `esc_like()` and `prepare()`. `sent_nothing()` decodes
  `last_response` and compares the top-level `outcome`. The writer of
  `cancelled` (`EventQueue.php:509`) encodes with `wp_json_encode()` at the
  top level, so the two agree as for `skipped` (3.18.0 I2).
- `TransactionalStatus::last_sent_at()`: `prepare()` with `%s` for status and
  type; table from the class constant.
- `CartSessionStore`: the new `SELECT id, cart_content … WHERE cart_token = %s`
  and the `set_newsletter_optin()` `SELECT` / `update()` are prepared; the
  `cart_content` value goes through `update()` formats.
- `RetiredTables`: `DROP TABLE IF EXISTS` with `$wpdb->prefix` plus a class
  constant (L1).

## I5 — INFO — stored data

- **`smly_rec_email_workflows`** (autoload `false`): email key → positive
  integer, only for the six plugin keys, kinds `plugin` / `transactional`,
  states `pending` / `pushed` / `failed`. No personal data. Deleted on a new
  connection (`RecEngineSettings::store()`) and on Disconnect; `uninstall.php`
  sweeps `smly_rec_%`. A failed §23 read keeps the last ids.
- **The tick** in `cart_content`: a boolean in a row that already holds the
  address; the exporter and eraser handle the row as before.
  `docs/DATA_MODEL_GDPR.md:120` still describes `cart_content` as only the
  item list; one clause there makes it current.
- **Dropped:** the legacy table (customer ids with serialized carts, no
  retention, never read by the exporter or eraser) and the never-written
  visitor table. A privacy improvement.
- **Logs:** no new line carries an address, a link or a credential
  (`RetiredTables` logs the exception class; the drain logs a customer id, as
  before).

## I6 — INFO — `automation_map: {}`

`put_config()` replaces `automation_map` with `new \stdClass()` only when it is
exactly `array()`, so a cleared map is sent as `{}` instead of `[]`. Every
other value is passed through for the engine's schema to judge (F3-51); the
proxy still sends nothing the browser did not ask for.

## I7 — INFO — `maybe_unserialize()` in the drain (pre-existing)

The drain reads legacy `cart_content` with `maybe_unserialize()` (`:141`).
Those rows hold `serialize( WC()->cart->get_cart() )`, including WooCommerce
product objects, so unserializing them instantiates classes. The data was
written by the plugin and is reachable only with database write access, so
there is no new trust boundary; it was the same in 3.18.0. With 012 the drain
runs once more on stores that update from 1.x / 2.0.0. Backlog idea:
`unserialize( $value, array( 'allowed_classes' => false ) )` — the drain reads
only `product_id`, `variation_id` and `quantity`.

## I8 — INFO — tooling, static gates, i18n, CI

- **CON-171:** `composer run package` ran unchanged on macOS for this gate
  (`perl -ni`); the 3.18.0 L4 is closed.
- **CON-57:** Vite 6 / Vitest 4 are dev dependencies; the shipped bundles pass
  `bin/check-bundle-scope.sh` (IIFE, no global leak) inside the verify script.
- **PHPCS / PHPStan:** `phpcs -n` exit 0 and **0 warnings** on the 25 changed
  PHP files; PHPStan `[OK] No errors`.
- **i18n:** `WP_CLI_BIN=vendor/bin/wp bash bin/build-i18n.sh` on the host
  succeeded (the committed `.pot` / `-et.po` restored afterwards);
  `msgfmt --statistics`: 691 translated; `msgcmp` reports the catalogues
  consistent.
- **Docs site:** the consent rule for reminders and "Edit design" are
  described in EN and ET.
- **CI:** "Lint and test the codebase" run 39941604919 on `a165e70` green;
  `Contract staleness` run 39941604884 green.

---

## Release gate — built ZIP, verify, Plugin Check

- **Build** (worktree on `a165e70`, macOS): `npm ci`, `composer install`,
  `npm run build:admin` (bundle-scope check OK),
  `composer run install-block-modules && composer run build`,
  `WP_CLI_BIN=vendor/bin/wp bash bin/build-i18n.sh` (committed `.pot` / `.po`
  restored afterwards), `composer install --no-dev --optimize-autoloader`,
  `composer run package` (no manual step), `composer install`.
- **ZIP:** `smaily-connect.zip`, **980 047 B**, SHA256
  `8cf587f5a5c73303359df9bdc1d8fb9123f84ad8b4a526a500aa83baf682d05f`,
  build-hash `a165e70` (clean), 406 entries. Version still 3.18.0 (the bump
  comes later); a local reference build, not the release asset.
- **`bash bin/verify-release-zip.sh smaily-connect.zip`** (repo root):
  **VERIFY OK** — every required file present (incl. all four block builds and
  the admin catalogue JSON), no tests / docs / sources / maps / bin / CI / dev
  vendor, no `sourceMappingURL` trailer, bundles IIFE with no global leak (12
  checks), version 3.18.0 consistent.
- **Plugin Check 2.1.0** against the built ZIP in the dev wp-env (unzipped to
  `wp-content/plugins/smaily-connect-pkg`, `wp plugin check smaily-connect-pkg
  --slug=smaily-connect --format=csv --allow-root --exclude-directories=vendor`;
  the copy removed afterwards): **0 ERRORS / 18 WARNINGS.** The 17 accepted at
  the 3.18.0 gate, three at new lines: `DirectQuery` + `NoCaching` at
  `BackfillJob.php:677`, `IngestQueue.php:398`, `:427`, `QueueJanitor.php:225`,
  `EventsEndpoint.php:804` (was `:784`); `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at `GdprHandler.php:654`,
  `CartSessionStore.php:282`, `:306` (were `:193`, `:217`). **NEW:
  `PluginCheck.Security.DirectDB.UnescapedDBParameter` at
  `RetiredTables.php:67`** (L1). No error.

## Release punch-list — 3.19.0

1. **M1** — limit the pushed-workflow override to the `default` account and a
   single-language mapping, or Erkki accepts it with the engine's commitment
   in DECISIONS CON-173.
2. **M2** — Erkki accepts the "subscribed in Smaily" residue in a DECISIONS
   CON-196 addendum; one paragraph in the docs site's consent note (EN + ET,
   Estonian proofread before publish).
3. **L1** — add the Plugin Check sniff to `RetiredTables.php`'s disable list,
   or accept warning 18.
4. **L6** — readme: reminder consent rule, "Edit design", the table drop in
   the Upgrade Notice; drop `= 3.16.1 =`; version bump in the nine places.
5. CON-182 is a one-way door ("ships only after Erkki approves the
   migration"): confirm the approval is on record before the tag.
6. **PCP against the CI-built ZIP** after the bump — expect the 17 accepted
   warnings at the lines above, plus L1's unless suppressed.
7. Record this audit in STATUS.md (the release-bump PR).

Backlog: L2, L3, L4, L5; the I2 liveness notice; the I5 GDPR-doc clause; the
I7 `allowed_classes` change.

## Conclusion

The delta adds two admin routes, a consent gate and a table-dropping
migration without widening access: the routes are capability- and
nonce-gated and keep the key and the one-time link out of storage and logs,
the new SQL is prepared, and migration 012 refuses to drop what it could not
copy. The consent gate does what CON-196 decided. What needs a decision before
the cut is trust in two outside signals: the engine's workflow id, which
arrives without the account it belongs to (M1), and Smaily's "subscribed",
which the plugin's own earlier reminders set (M2). Static gates are clean, CI
is green, the built ZIP verifies, and Plugin Check adds one false positive.
**3.19.0 may proceed once M1 is fixed or accepted, M2 is accepted, and L1 and
L6 are handled at the bump.**

---

## Disposition (2026-10-23)

| # | Disposition |
|---|---|
| M1 (Medium) | **Fixed** in #265 (Refs CON-173): `Router::resolve_workflow()` uses the pushed workflow only when `Router::takes_pushed_workflow()` holds — the mapped row is on the `default` account and in the store's default language (the `language = 'default'` row, or the row whose language equals the active detector's `get_default_language()`; a Mode B fallback to that row counts). The `transactional` account (follow-up email, order and shipping confirmations), every Mode A language account and a Mode B row of another language keep the mapped workflow. Erkki's interim rule in DECISIONS "CON-173" replaces the Open item; revisit when the engine names a push's account and language (INT-146). Pinned in `RouterTest` and `EmailDesignsTest`. |
| M2 (Medium) | **Accepted** by Erkki (2026-10-23): no behaviour change; DECISIONS "CON-196" addendum. The docs site ("Who gets an abandoned-cart reminder", EN + ET, #265) tells the merchant that earlier reminders added shoppers as subscribed contacts and names the fields that mark them (`is_abandoned_cart`, `abandoned_cart_automation_at`). |
| L1 (Low) | **Fixed** in the 3.19.0 bump: `PluginCheck.Security.DirectDB.UnescapedDBParameter` is added to the file-level `phpcs:disable` list of `RetiredTables.php` (`:14`). The false positive is confirmed: `$suffix` iterates over the class constants `LEGACY_CART_TABLE` and `VISITOR_TABLE`, joined to `$wpdb->prefix`; `DROP TABLE` takes no placeholder. |
| L2 (Low) | **Backlog** — `EmailDesignsEndpoint::allowed_return_url()`: refuse a URL with `\`, user info or a port. |
| L3 (Low) | **Fixed** in #265: `EmailDesignWorkflows::remember()` keeps an id only for an item with `sent_through: "workflow"`. Pinned in `EmailDesignWorkflowsTest`. |
| L4 (Low) | **Backlog** — `LegacyCartDrain::drain()`: fail on a tracker write error before migration 012 drops the source, and check that the tracker table exists before the drop. |
| L5 (Low) | **Backlog** — "Last sent" (CON-170 follow-up): an `(event_type, status, created_at)` index, or no query for a trigger that is switched off. |
| L6 (Low) | **Fixed** in the 3.19.0 bump: `readme.txt` states the reminder's consent rule in the description and the FAQ, lists the subscription-status read before a reminder under the Smaily Public API, names Edit design in the description and the Campaign Intelligence data list, and adds a `= 3.19.0 =` changelog entry and an upgrade notice that names the table removal and that it cannot be undone (no "Safe update"). `= 3.16.1 =` and `= 3.17.0 =` are dropped from Changelog and Upgrade Notice; the Changelog section is 3 798 characters including its heading. |
| I1–I8 (Info) | Accepted as recorded above. The I2 liveness notice, the I5 `DATA_MODEL_GDPR.md` clause and the I7 `allowed_classes` change stay backlog candidates. |

## Addendum: post-audit delta (`a165e70..01138dc`)

After the audited state (`a165e70`), four PRs landed on `main` before the
3.19.0 bump: **4 commits, 13 files, +947 / −81**; outside `docs/` and
`tests/` 5 files, +69 / −33 (`STATUS.md`, the `blocks/` manifest and
lockfile, two PHP files); shipped PHP 2 files, +41 / −7; no shipped
TypeScript.

- **#262** — `STATUS.md` only.
- **#263 CON-56** — `blocks/package.json` override `markdown-it ^14.3.3`;
  the `blocks/` lockfile moves `markdown-it` 14.3.2 → 14.3.3 and
  `launch-editor` 2.14.1 → 2.14.2 (with `shell-quote ^1.10.0`). Build tools
  only; nothing in the ZIP comes from them.
- **#264** — this report and its `INDEX.md` row.
- **#265 (Refs CON-173, CON-196)** — the M1 and L3 fixes and the M2 docs
  (see Disposition), plus DECISIONS entries.

**Security look at #265** (`Multilingual\Router`, `Settings\EmailDesignWorkflows`):

- **Trust input narrowed, not widened.** The pushed id is used on fewer
  paths than in the audited state, never on more: every new branch in
  `takes_pushed_workflow()` returns the mapped match. The id is still the
  positive integer `remember()` normalised, and an email with no mapped
  workflow is still not sent (the check runs after the mapped match is
  resolved).
- **Fail-closed on the language check.** A mapped row with a `null`
  language, an empty detector default or a default that does not equal the
  row's language keeps the mapped workflow. The detector default is read
  from the multilingual plugin's settings (WPML filter, Polylang,
  TranslatePress option, site locale) — not from the request, so a shopper
  cannot steer it, and it gives the same answer in a cron run as in a web
  request.
- **Transactional account.** `resolve_transactional()` can return the
  `language = 'default'` row; the account check runs first, so the
  `transactional` account never takes a pushed id.
- **Stored data.** `smly_rec_email_workflows` now holds fewer entries (only
  `sent_through: "workflow"`); same option, same autoload, same clearing on
  Disconnect and a new connection. No new SQL: the router's two
  `prepare()`d mapping reads are unchanged. No REST route, capability or
  nonce changed.
- **Docs site.** The new EN + ET paragraphs name contact field names and
  describe behaviour; no secret, URL or personal data.
- **#263.** Version-range moves of two dev-only packages inside `blocks/`;
  no shipped file changes; the 3.19.0 release gate (`INDEX.md`) verifies the
  four block builds in the ZIP.

**Findings: none.** The addendum adds no Medium or Low finding. With M1
fixed, M2 accepted and L1, L3 and L6 fixed, **3.19.0 may proceed.**
