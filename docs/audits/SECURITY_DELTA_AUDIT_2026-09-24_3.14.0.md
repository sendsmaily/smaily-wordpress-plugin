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
