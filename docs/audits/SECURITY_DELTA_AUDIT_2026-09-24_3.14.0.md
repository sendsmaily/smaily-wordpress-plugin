# Security delta audit — 3.14.0 pre-release gate (3.13.0..HEAD)

- **Date:** 2026-09-24
- **Baseline:** delta `3.13.0..8c26eed` (the v3.13.0 tag to the pre-bump main
  tip; **4 commits, 23 files, +1 076 / −87**; the shipped plugin code alone —
  `includes/`, `admin/src/`, `smaily-connect.php` — is **11 files, +206 / −26**).
  The work in it:
  - **PRO-2449** — the plugin header `Description` drops the stale
    "(BETA: …)" note. Text only.
  - **PRO-3187** — transactional emails pick their Smaily workflow by the
    order's language on a store with more than one language:
    `TransactionalGate::resolve_if_open()` takes the order and resolves its
    language through `ContactLanguageResolver::for_order()`; `Router` gains a
    transactional branch (`resolve_transactional()`: exact language →
    default-fallback row → the `default` row, independent of the multilingual
    mode); the two native-WC-email suppression filters now take the email's
    order as their 2nd argument; `TransactionalEmailHookHandler` and
    `TransactionalResend` pass the order; the Settings section renders one
    row per detected language on the transactional account.
  - **PRO-2513** — the My Account "Smaily Campaign Intelligence" section is
    shown only where Campaign Intelligence is live
    (`ProfilingConsentAccount::is_shown()` = `SetupState::completed() &&
    RecEngineSettings::sending_allowed()`, read at call time by both
    `render()` and `handle_post()`), and the checkbox reflects the new
    display accessor `ProfilingConsent::known_preference()` (?bool); on
    `null` a static notice replaces the form. **A consent surface.**
  - Non-code: `STATUS.md`, `docs/DECISIONS.md`, `docs/site/index.html`
    (merchant docs, not shipped), `languages/`, tests.
- **Auditor:** Claude (Opus 5.5)
- **Trigger (re-audit policy):** policy point 1 (the 3.14.0 release boundary)
  and point 2 — the delta touches **GDPR/consent** (the shopper's profiling
  opt-out control, PRO-2513) and **SQL against a custom table** (a new
  Router branch over `smly_plus_automation_mapping`), regardless of its size.
- **Scope:** file-by-file read of the production delta (`git diff
  3.13.0..HEAD -- includes/ admin/src/ smaily-connect.php`), plus the call
  chains it reaches (`ProfilingConsent` in full, `ContactLanguageResolver::
  for_order()`, `Router::find_mapping()` / `find_fallback_mapping()`,
  `SettingsEndpoint::replace_automation_mappings()`, WooCommerce's
  `WC_Email::is_enabled()` filter contract). Test-only and docs-only files
  were skimmed for accidental secrets, not audited as behaviour. PCP against
  the built ZIP is reported in the register row for this gate, not here.

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium. 2 Low, 6 Info. RESULT: 3.14.0 may
proceed.**

Nothing in the delta adds a route, a capability or nonce change, a new
outbound destination, a new stored data class or a new log line. The consent
change narrows where the section appears and what it claims; both Lows are
about the shopper's opt-out control being unavailable in two narrow states —
a trade-off recorded in DECISIONS PRO-2513, reported here so it is a
conscious one, not a defect in what was built.

---

## 1. LOW — mid-onboarding, the engine can be receiving data while the
shopper's opt-out section is hidden

**Where:** `ProfilingConsentAccount::is_shown()` (PRO-2513).

The gate requires **both** `SetupState::completed()` and `sending_allowed()`.
Rec-engine ingest (catalog, customers, orders) and the logged-in browse
identity attach are gated on the engine connection alone (CLAUDE.md
"Coexistence map" — "fires iff the engine is connected, regardless of wizard
state"), and the setup-token exchange route is not gated on the wizard's
Finish. So a merchant who connects the engine in the wizard and leaves before
Finish runs a store where the engine receives shopper data and the My Account
opt-out is hidden. Before the delta the section rendered in that state, and an
opt-out there still took effect locally (durable opt-out registry) and on the
engine (`engine_opt_out()`), even though the Smaily write had no client.

**Why it is Low, not higher:**

- The state is an unfinished onboarding — Settings redirects to the wizard
  until Finish, so a live store sits in it only if the merchant abandons the
  wizard after step 4.
- The pre-delta section in that state was itself inaccurate: with no Smaily
  client `may_profile()` failed open, so the box showed "ticked" whatever the
  truth — exactly the misrepresentation PRO-2513 fixes.
- Other erasure / objection channels are unchanged (WP Privacy tools, the
  engine-side GDPR endpoints the merchant can trigger).

**Not fixed here** (gate pass, no product code changed). If it is worth
closing, the shape is to gate the section on `sending_allowed()` alone and let
the form work without the Smaily read-back when setup is unfinished — a
product decision for Erkki, since DECISIONS PRO-2513 names `completed()` as the
precondition deliberately. Recorded as a follow-up.

## 2. LOW — in the "preference unknown" state the shopper cannot opt out
until a read succeeds, while the gate still fails open

**Where:** `ProfilingConsentAccount::render()` → `ProfilingConsent::
known_preference()` returning `null` (PRO-2513).

`null` means no durable opt-out and no stale cache — a contact that has never
been read successfully, with the current read failing. The section then shows
"We couldn't load your preference right now. Please try again later." and no
form. In that same state `may_profile()` fails open (F3-31 / PRO-1194), so the
contact is profiled while the only self-service opt-out is withheld. Before the
delta the (inaccurate) ticked box was shown and unticking it would have taken
effect locally and on the engine even if the Smaily write failed.

**Why it is Low:** it needs a contact with no successful read ever AND a
failing read now (a Smaily outage or broken credentials); it is transient by
construction (the next successful read fills the stale cache and the form
returns); and the rationale for withholding the form is sound — submitting a
form whose checkbox state is a guess would turn "unknown" into an accidental
opt-out or opt-in. **Not fixed here.** A follow-up could offer an explicit
"Stop using my data" button in the unknown state (opt-out only, no checkbox),
which removes the gap without reintroducing the guess.

## 3. INFO — a hidden section cannot be submitted, and the POST handler's
gates are intact

`handle_post()` checks, in order: the submit field is present → `is_shown()`
→ `is_user_logged_in()` → `wp_verify_nonce( _wpnonce, 'smly_profiling_consent'
)` → the current user's own email. A crafted POST against a store where the
section is hidden returns before any state change (silently, no notice, no
redirect), so hiding the section also hides the write. The email acted on is
always `wp_get_current_user()->user_email`, never a request value, so no
capability check beyond login is needed — a shopper can only change their own
preference. The nonce + login + own-email chain is unchanged from 3.13.0; the
new gate only adds an earlier return.

## 4. INFO — the new notice is a static, escaped string

The unknown-state line is `esc_html_e()` of a fixed msgid; no user data,
email, error message or Smaily response is echoed. All other output in
`render()` is unchanged (`esc_html_e`, `esc_attr`, `checked()`,
`wp_nonce_field()`). Nothing new reaches a log: the delta adds no
`DebugLog::write`, `error_log` or stored exchange, and the pre-existing
profiling log lines write only exception messages from the Smaily / engine
clients, as before.

## 5. INFO — stale-cache semantics are display-only and fail safe

`known_preference()` calls `may_profile()` first (so a daily-cache miss still
reads back from Smaily, exactly as the pre-delta render did — including the
pre-existing side effect that a read resolving to "do not profile" fires the
engine opt-out), then returns `stored_preference()`: durable opt-out → the
stale cache → `null`. It deliberately ignores the daily cache, which can hold
the fail-open `true` written by `refresh()` on an error. `fallback_on_error()`
now reads the same `stored_preference() ?? true`, so the gate's answer is
byte-for-byte what it was: `false` for a durable opt-out, the stale value if
present, else `true`. Verified by reading `remember()` / `cache()` /
`remember_optout()`: a successful read or a WP-side choice writes all three
layers, an error writes only the daily cache — so `null` appears exactly in
the "nothing reliable known" case. One harmless corner: a persistent object
cache can evict the stale transient while the daily one survives; the section
then shows the unknown notice (no form) for up to a day — fail-safe, never a
wrong checkbox.

## 6. INFO — the suppression filters' new 2nd argument cannot be abused

Both callbacks are now `( $enabled, $order = null )` registered with
`accepted_args = 2`. WooCommerce fires `woocommerce_email_enabled_{id}` with
`( $enabled, $this->object, $this )`; for `customer_processing_order` and
`customer_completed_order` the object is the `WC_Order` being mailed (set in
`trigger()` before `is_enabled()`). `as_order()` admits only `instanceof
\WC_Order`; anything else — a crafted object from another plugin's
`apply_filters()`, a refund, `null` from WC's settings screen — falls back to
the language-less lookup, which is exactly the pre-delta behaviour. The
default `$order = null` also keeps a short `apply_filters()` from raising
`ArgumentCountError` (the CLAUDE.md hook-arg-tuple trap). A caller able to
pass a real `WC_Order` of another language is in-process code, not an input
boundary. **Suppression still matches the gate:** the send path
(`TransactionalEmailHookHandler`), the resend path (`TransactionalResend`) and
both filters call the same `TransactionalGate::resolve_if_open()` with the
same order, and `ContactLanguageResolver::for_order()` is context-independent
by design (F3-47), so the WC native email is suppressed exactly when the
Smaily send for that order will happen — and when the lookup finds no row for
the order's language and no fallback/default row, the gate closes on both
sides and WooCommerce's own email goes out (fail-safe).

## 7. INFO — the Router's transactional branch adds no new SQL and binds
every value

`resolve_transactional()` only re-sequences the two existing lookups;
`find_mapping()` / `find_fallback_mapping()` are unchanged and remain
`$wpdb->prepare()`d with `%s` for trigger and language, the table name built
from `$wpdb->prefix` + a private constant. The branch is entered only for keys
of `TransactionalGate::TRIGGERS` (a class constant), so the A/B/C automation
routing is untouched. The language value is shopper-influenced at most
indirectly (WPML's `wpml_language` order meta / `_user_preferred_language`
user meta), is clamped to the site's active languages by the resolver, and
can only select among workflow rows the merchant saved through the
`manage_options` Settings route — all on the transactional account, whose
credentials the gate still requires (condition 4). No cross-account or
cross-tenant routing is reachable from the storefront.

## 8. INFO — the admin and header changes carry no executable surface

`AutomationSection.tsx` only changes which rows render and which
`accountKey` each carries; it renders React text, no
`dangerouslySetInnerHTML`, no `href` from data. The persisted rows still pass
`SettingsEndpoint::replace_automation_mappings()`'s trigger allowlist,
`sanitize_key` / `sanitize_text_field` and a prepared INSERT (unchanged).
`smaily-connect.php` changes the header `Description` text only.

---

## Confirmed clean (checked, nothing to report)

- **No REST, capability, nonce or crypto change.** The delta adds no
  `register_rest_route`, `permission_callback`, `current_user_can`, nonce
  call, `wp_remote_*`, `$wpdb` statement, `$_POST`/`$_GET` read, secret or
  `Authorization` reference (grep over the added lines of the production
  delta: zero hits).
- **No new outbound destination or payload field.** Transactional sends go
  through the same `TransactionalFlusher` → `Client::send_message()` path
  with a different `workflow_id` only; nothing new is sent to Smaily or the
  engine.
- **No new data at rest.** No migration, option or transient key is added;
  `known_preference()` reads the existing profiling caches.
- **Public `/relay`, `LandingCapture`, both storefront bundles** — untouched.
- **`docs/site/index.html`** is merchant documentation, excluded from the ZIP
  by `.zipignore`; skimmed, no credentials.

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/Privacy/ProfilingConsentAccount.php` | **in** | consent surface: gate, POST handler, output (§1–§4) |
| `includes/Privacy/ProfilingConsent.php` | **in** | the display accessor and the shared fallback (§2, §5) |
| `includes/Bootstrap.php` | **in** | wiring of the new constructor argument (read; no gate touched) |
| `includes/Smaily/TransactionalSuppression.php` | **in** | filter signature change (§6) |
| `includes/Smaily/TransactionalGate.php` | **in** | order-language resolution (§6, §7) |
| `includes/Multilingual/Router.php` | **in** | new lookup branch over a custom table (§7) |
| `includes/Integrations/WooCommerce/TransactionalEmailHookHandler.php`, `includes/Smaily/TransactionalResend.php` | **in** | pass the order to the gate (§6) |
| `admin/src/components/steps/AutomationSection.tsx` | **in** | what the admin persists (§8) |
| `smaily-connect.php` | out | header description text only |
| `STATUS.md`, `docs/**`, `languages/**` | out | no executable content |
| `tests/**` | out | not shipped; skimmed for secrets, clean |

## Gates run for this pass

Recorded in the register row: `npm run ci:strict`, the full integration
suite, PCP against the BUILT ZIP and `bin/verify-release-zip.sh`.

## Follow-ups this audit leaves open

1. **Finding 1 (Low)** — decide whether the My Account opt-out should also
   show on an engine-connected store whose email setup is unfinished
   (`sending_allowed()` alone). Product decision; no change made here.
2. **Finding 2 (Low)** — consider an opt-out-only control in the
   "preference unknown" state, so a shopper can always say no while the gate
   fails open. No change made here.

---

## Addendum — re-audit of 3b0ede0..7cbdc75 (PRO-3189, PRO-3191)

- **Date:** 2026-09-24 (same day, after the gate above)
- **Delta:** `3b0ede0..7cbdc75` — **2 commits, 12 files, +598 / −41**; the
  shipped plugin code alone is **2 files, +45 / −12**
  (`includes/Privacy/ProfilingConsentAccount.php`,
  `includes/Privacy/ProfilingConsent.php`). The rest is tests,
  `languages/` (one new msgid + the ET label), `STATUS.md`,
  `docs/DECISIONS.md`, `docs/DATA_MODEL_GDPR.md`, `docs/site/index.html`.
  - **PRO-3189** (`d94bfbc`) — closes Lows 1 and 2 above: `is_shown()` =
    `sending_allowed()` alone; the "couldn't load" state renders an
    opt-out-only button (`OPT_OUT_FIELD`).
  - **PRO-3191** (`7cbdc75`) — a durable store-side opt-out now holds until
    an explicit opt-in; a found contact that lacks `smaily_rec_profiling`
    gets the opt-out written back through the existing upsert.
- **Auditor:** Claude (Opus 5.5)
- **Trigger:** GDPR/consent surface (re-audit policy point 2) inside the
  3.14.0 release window, before the bump.
- **Scope:** both production files read in full (not only the hunks), plus
  `Smaily\Client::get_contact_consent()`, `write_profiling_consent()`,
  `upsert_subscribers()` and `request()` (what a failure message can carry),
  and every caller of `may_profile()` / `known_preference()`
  (`BeaconEndpoint`, `IdentityHookHandler`, `ProfilingConsentAccount`).
  Tests, `.po`/`.pot` and docs skimmed for secrets (only the pre-existing
  fake fixture `'test-password'`).

### Verdict (addendum)

**0 Blocking, 0 Critical, 0 High, 0 Medium, 0 Low. 5 Info.** Both Low
findings of the gate above are **resolved**. **RESULT: 3.14.0 may proceed.**

### A1. INFO — the opt-out button's POST can only ever opt out

`handle_post()` enters on either the checkbox form's `_submit` field or
`OPT_OUT_FIELD`, then runs the unchanged chain: `is_shown()` → `is_user_logged_in()`
→ `wp_verify_nonce( _wpnonce, 'smly_profiling_consent' )` → the current
user's own email (`wp_get_current_user()`, never a request value). The
choice is `! $opt_out_button && isset( $_POST[ FIELD ] )`, so any request
carrying `OPT_OUT_FIELD` resolves to opt-out whatever else it carries —
a crafted post adding the checkbox field cannot opt in (unit-pinned:
`test_a_crafted_opt_out_button_post_cannot_opt_in`). CSRF: the button's
form carries `wp_nonce_field( ACTION )`, the same user-bound nonce the
checkbox form uses; a cross-site POST without it returns before any state
change (`test_opt_out_button_needs_a_valid_nonce`, `…_logged_in_shopper`).
The handler accepts the button in any state, not only when it is rendered —
harmless, since its only effect is the opt-out the checkbox form already
allows. Output: the button name is `esc_attr()` of a class constant, the
label `esc_html_e()` of a fixed msgid; no user data, email or response is
echoed.

### A2. INFO — the gate `sending_allowed()` alone widens where the section shows, nothing else

The section (and its POST) now also appear on an engine-connected store whose
email wizard is unfinished — exactly the state Low 1 flagged, where engine
ingest already runs. There the Smaily client factory yields `null`, so
`write()` is a no-op, `remember( false )` puts the email in the durable
registry and `engine_opt_out()` still reaches the engine (gated on the same
`sending_allowed()`); `known_preference()` then returns `false` and the
checkbox renders unticked. An opt-in from that checkbox is an explicit act
and clears the registry through `opt_in()` as before. A deactivated or
disconnected engine still hides the section and refuses its POST. The
section displays only the logged-in shopper's own preference, so showing it
on more stores exposes no other contact's data.

### A3. INFO — PRO-3191's write-back cannot create or subscribe a contact

The write-back fires only when **all** of: the Smaily read succeeded;
`is_allowed()` said yes (so `is_unsubscribed !== '1'` and the field is not
`'0'`); the field is not `'1'`; the email is in the durable registry;
`found === true`; and the field is `null`/`''`. So:
- **No creation:** a not-found contact (`found === false`) is never written
  (`test_contact_not_found_keeps_the_opt_out_and_writes_nothing`). The one
  residual path is a contact deleted in Smaily between the GET and the POST
  of the same refresh — a sub-second race needing a concurrent deletion;
  noted, not actionable.
- **No subscription change:** `write_profiling_consent()` posts only
  `email`, `smaily_rec_profiling`, `smaily_rec_profiling_ts` — no
  `is_unsubscribed` — and it is never reached for an unsubscribed contact
  (that resolves to "not allowed" before the branch).
- **No accidental opt-in:** the branch can only turn `allowed` from true to
  false; the registry is cleared only by a read-back of exactly `'1'` or by
  `opt_in()` (`test_explicit_opt_in_on_the_contact_clears_…`,
  `test_opt_in_from_my_account_clears_…`). The pure `is_allowed()` rule and
  F3-31's default-on for never-opted-out contacts are unchanged.

### A4. INFO — failure handling and logging of the write-back

`write()` catches every `Throwable` and logs only
`'[smaily-connect profiling-consent] write failed: ' . $e->getMessage()`
(pre-existing line). `Client::request()` builds its exception messages from
the HTTP status, method, endpoint and Smaily code, or the WP transport error
— the email travels in the POST body, not the URL, so it does not reach the
log; the `Authorization` header is never part of a message. A failed write
does not change the decision: `allowed` stays `false`, `remember( false )`
keeps all three layers at opt-out and the engine opt-out fires, so the
store-side answer is correct regardless and the write is retried at the
next refresh (at most once per contact per daily cache expiry). One
pre-existing gap, not introduced here: `write()` ignores the upsert's
response body, so an HTTP-200 Smaily error envelope counts as success — the
effect is only that the write-back repeats on a later refresh, the opt-out
itself is unaffected.

### A5. INFO — the write-back can add one Smaily call to a storefront request

`may_profile()` is also called from `/relay` (`BeaconEndpoint`) and
`IdentityHookHandler` on a daily-cache miss, so for a durably opted-out,
found contact lacking the field the first refresh of the day does a GET plus
one POST (30 s timeout, as every Smaily call). Bounded by the daily cache and
by the opt-out count; performance note only, no security effect.

### Morning Lows — status

| Finding | Status | Evidence |
|---|---|---|
| Low 1 — section hidden on an engine-connected store before the wizard's Finish | **Resolved** (PRO-3189) | `is_shown()` = `sending_allowed()`; `test_section_is_shown_when_active` (both setup states), `test_opt_out_button_opts_out` (both), integration `test_section_shows_while_the_email_setup_is_not_finished` |
| Low 2 — no opt-out in the "couldn't load" state | **Resolved** (PRO-3189) | opt-out-only button; `test_unknown_preference_renders_a_notice_and_an_opt_out_button`, integration `test_opt_out_button_round_trip_without_a_smaily_client` |
| Follow-on — an opt-out made without a working Smaily write was wiped at the next read (surfaced by PRO-3189) | **Resolved** (PRO-3191) | durable-until-explicit-opt-in rule; integration `test_opt_out_survives_a_later_read_of_a_contact_without_a_preference` |

### Confirmed clean (addendum)

- Grep over the added production lines: no `register_rest_route`,
  `permission_callback`, `current_user_can`, `wp_remote_*`, `$wpdb`,
  `$_GET`/`$_REQUEST`, `error_log` or new `DebugLog::write` — zero hits.
  The one new superglobal read is `isset( $_POST[ OPT_OUT_FIELD ] )` (value
  never used).
- No new option, transient, table or outbound destination; the write-back
  reuses the existing profiling upsert to the same Smaily account.

### Follow-ups this addendum leaves open

None required. Optional: have `write()` treat a non-101 Smaily envelope as a
failure (A4) so the log shows it — pre-existing, no privacy effect.
