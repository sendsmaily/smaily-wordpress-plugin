# Security re-audit — F3-47 (contact-sync language resolver) + F3-48 (contact-sync mode engine)

- **Date:** 2026-06-30
- **Auditor:** independent security pass (adversarial, delta-only)
- **Scope (delta):** commits `522b305..HEAD` on `main` — 12 commits, the F3-47 +
  F3-48 epics. Plugin code surfaces only (PHP + TS/TSX); docs/tests reviewed for
  corroboration, not audited as attack surface.
- **Baseline:** the standing `docs/audits/SECURITY_AUDIT.md` covers the pre-delta
  tree; this report covers only what F3-47/F3-48 added or changed.

## Files reviewed (the delta's security-relevant surface)

| File | What changed |
|------|--------------|
| `includes/REST/SettingsEndpoint.php` | `save_subscribers()` now writes the 3 new mode options |
| `includes/Smaily/Client.php` | new `get_action_log()` + `list_contacts()` (external HTTP, pull) |
| `includes/Smaily/ContactReconciler.php` | NEW — Smaily→WP marketing-consent mirror |
| `includes/Smaily/ContactSyncMode.php` | NEW — preset/policy reader |
| `includes/Smaily/ContactAudience.php` | NEW — audience gate |
| `includes/Smaily/BackfillJob.php` | audience filter + `start(reset_freshness)` + `should_start_refresh`/`current_state` SQL |
| `includes/Smaily/Flusher.php` | `dispatch_contact_sync` forwards `language` + `is_unsubscribed` |
| `includes/Smaily/AutomationRouter.php` | `force_opt_in` is mode-driven |
| `includes/Integrations/WooCommerce/HookHandler.php` | audience gate + `user_newsletter` meta-transition handlers |
| `includes/Integrations/WooCommerce/Hooks.php` | binds `update_user_meta` + `add_user_meta` globally |
| `includes/Bootstrap.php` | `on_contact_sync_tick` rewrite (reconcile + mode-aware refresh) |
| `includes/Support/ContactLanguageResolver.php` | NEW — language resolution |
| `includes/Wizard/EnvDetector.php` | boots the 3 new options into the React payload |
| `admin/src/**` | Step 2 mode UI + reducer/state |

## Method

Read each changed file in full (not just the hunk), traced data flow from the
REST boundary and the WP hook boundary through to the external Smaily HTTP calls
and the custom-table SQL. For each focus area I attempted a concrete exploit /
failure and dropped anything I could not substantiate against the code.

---

## Findings

| # | Severity | File:line | Issue |
|---|----------|-----------|-------|
| 1 | **Medium** | `includes/Smaily/ContactReconciler.php:162` + `includes/Integrations/WooCommerce/Hooks.php:51-52` | Reconcile→meta-hook echo loop has no loopback suppression; a contact **deleted** in Smaily is **re-created** there (as unsubscribed) on the next reconcile. GDPR data-hygiene regression. |
| 2 | Low | same root cause as #1 | For ordinary `optin`/`optout` reconciled rows the same echo re-POSTs the just-mirrored state back to Smaily — redundant API writes (idempotent, not harmful beyond cost). |

### Finding 1 (Medium) — reconcile→meta-hook echo recreates a Smaily-deleted contact

**Root cause:** the bidirectional consent sync added in F3-48 has no loopback
guard between its two halves.

- The **Smaily→WP** half (`ContactReconciler::apply()`,
  `ContactReconciler.php:162`) mirrors a Smaily action onto WP by calling
  `update_user_meta( $user->ID, 'user_newsletter', $desired )`.
- The **WP→Smaily** half (`Hooks.php:51-52`) binds `update_user_meta` /
  `add_user_meta` **globally**, so that very `update_user_meta` write fires
  `HookHandler::on_user_newsletter_meta_update()` →
  `handle_newsletter_change()` (`HookHandler.php:352`) which, in consent mode,
  enqueues a `contact.sync` carrying `is_unsubscribed` back to Smaily.

It is not infinite recursion (the enqueue writes the event-queue table, not user
meta), and the flush back to Smaily does not write user meta — so it terminates.
The problem is the *content* of the echo for the `delete` action:

**Scenario (substantiated by tracing):**
1. Merchant deletes contact `x@example.test` in Smaily (e.g. honoring a GDPR
   erasure request). Smaily's action log gets a `delete` row — `delete` is in
   `ContactReconciler::RECONCILE_ACTIONS` (line 43), so the standing reconcile
   polls it.
2. The matching WP user still has `user_newsletter = 1`. `apply('delete')` maps
   to `$desired = 0` (line 156), writes `user_newsletter = 0`, returns 1.
3. That write fires `on_user_newsletter_meta_update` → `handle_newsletter_change(old=1, new=0)`
   → `enqueue_consent_change( $user, is_unsubscribed = 1 )` (HookHandler.php:370-382).
   (Gate is open and mode is `consent` — exactly the mode the reconciler runs in.)
4. The flusher dispatches `upsert_subscribers([{ email: x@example.test, ...fields, is_unsubscribed: 1 }])`
   (`Flusher::dispatch_contact_sync`, Flusher.php:172-194). A Smaily contact
   upsert **re-creates** the deleted contact (as unsubscribed).

**Impact:** a contact the merchant intentionally deleted in Smaily reappears
there. The recreated contact is unsubscribed, so it receives no marketing — but
resurrecting a deleted (potentially erasure-requested) contact into the
marketing platform is a genuine data-protection regression, and it directly
fights the GDPR-erasure flow. Bounded: only fires when the still-present WP user
has `user_newsletter = 1` at delete time.

**Fix suggestion:** suppress the loopback while the reconciler applies mirrored
changes — e.g. wrap `ContactReconciler::apply()`'s `update_user_meta` in a
`remove_action`/`add_action` around the two meta hooks, or set a transient
in-reconcile flag that `handle_newsletter_change()` checks and skips. (Standard
two-way-sync echo suppression — mark writes that originated from the remote so
they are not echoed back.) Separately, `delete` arguably should not be mapped to
an `is_unsubscribed=1` *upsert* at all.

### Finding 2 (Low) — redundant consent re-POST for optin/optout

Same mechanism as #1 but for `optin`/`optout`/`complaint` reconciled rows: the
echo re-sends the contact's *current* state to Smaily, which already holds it, so
it is idempotent. The only cost is extra event-queue rows + one Smaily API write
per reconciled change per run. Fixing #1 fixes this too.

---

## Areas checked and found clean

**REST surface (`SettingsEndpoint::save_subscribers`) — clean.**
- Capability-gated: `permission_check()` requires `current_user_can(Constants::CAPABILITY)`
  and `Constants::CAPABILITY === 'manage_options'` (Constants.php:59). The mode
  write is reachable only through `handle()` behind that callback. REST cookie-auth
  also enforces the `X-WP-Nonce` via WP core — unchanged by this delta.
- `contactSyncMode` is `sanitize_key()`-normalised then validated with
  `ContactSyncMode::is_valid_mode()`; an unknown value falls back to the lawful-safe
  default and is never stored raw (SettingsEndpoint.php:308-311).
- `includeGuests` / `automationForceOptIn` are hard-coerced to bool via `! empty()`
  (lines 312-313). No injection: every persisted value is a validated enum or a bool.

**External HTTP (`Client::get_action_log` / `list_contacts`) — clean.**
- Query strings are built with `http_build_query()`; the params (`since_seq_id`,
  `limit`, `offset` are ints; `actions` is the hardcoded `RECONCILE_ACTIONS`
  const, not caller input). No parameter injection.
- The host is `sprintf('https://%s.sendsmaily.net/api/%s.php', $subdomain, $endpoint)`
  where `$subdomain` is admin-set credential data and `$endpoint` is an internal
  slug constant — no untrusted-input SSRF introduced by this delta. (The general
  observation that a malicious admin-set `subdomain` could redirect the Basic-auth
  header is pre-existing in `request()`, not part of F3-47/F3-48.)
- **Authorization header is never captured.** `Client::request()` records
  `last_exchange` from `{method, endpoint, body}` (the `$data` payload) and the
  reply only; the `Authorization: Basic …` header lives in the separate `$args`
  array and is never copied into `last_exchange` (Client.php:269-311). The
  established rule holds for the two new methods too.
- **The action-log / contact-list pulls never persist their PII responses.**
  `ContactReconciler` calls `get_action_log()` / `list_contacts()` but never reads
  `last_exchange()` and never calls `store_exchange()` (confirmed: the only
  `store_exchange` callers are `Flusher` and the rec-engine D6 flusher). The bulk
  email lists those pulls return stay in process memory and are discarded. No
  Event-Log leak. Even when the reconciler and the flusher share Bootstrap's cached
  `Client` in one request, `Flusher::dispatch_contact_sync` reads `last_exchange()`
  only *after* its own `upsert_subscribers()` call has overwritten it — so a prior
  action-log response can't be stored under a contact-sync row.

**Global meta hooks (`update_user_meta` + `add_user_meta`) — no DoS, no recursion, correct old-value read.**
- DoS: the handlers fire on every user-meta write site-wide but bail on the first
  line with a single `!==` string comparison against `'user_newsletter'`
  (HookHandler.php:155, 170). Negligible per-write cost; the heavier path
  (`get_user_meta` + payload build) only runs on an actual `user_newsletter`
  transition.
- Recursion: `handle_newsletter_change()` writes the event-queue table, not user
  meta, so the meta hook cannot re-enter itself. Terminates. (The cross-component
  echo is Finding 1, a different concern.)
- Old-value correctness: `update_user_meta` fires **before** the DB write, so
  `get_user_meta()` inside `on_user_newsletter_meta_update` returns the OLD value
  (HookHandler.php:158) — matches the documented contract. `add_user_meta`
  correctly assumes old = 0 (first-ever set). Handler arities match the core action
  signatures (4 args for update, 3 for add — Hooks.php:51-52). WP also suppresses
  the `update_user_meta` action on a no-op write, and `handle_newsletter_change`
  re-guards `$old === $new`, so spurious sends are double-prevented.

**Consent / PII separation — clean.**
- Marketing vs profiling consent stay separate: `ContactReconciler` and the
  `user_newsletter` handlers touch only the marketing opt-in flag and
  `is_unsubscribed`; profiling consent (`smaily_rec_profiling`) is untouched here
  (handled solely by `Privacy\ProfilingConsent`). The reconciler's docblock states
  the invariant and the code honors it.
- `is_unsubscribed` is sent only on a real opt-state transition
  (`handle_newsletter_change`), never on a routine `on_profile_update`, so a profile
  edit can't resurrect a Smaily unsubscribe.
- The reconcile cursor (`smly_plus_contact_reconcile_seq`) stores only an integer
  seq_id, non-autoloaded; no PII.

**`force_opt_in` posture — clean / defense-in-depth.**
- `AutomationRouter` is the only caller of `Client::trigger_automation` and always
  passes `$this->mode->automation_force_opt_in()` explicitly (AutomationRouter.php:147);
  the Client's `true` default is never reached in production.
- `ContactSyncMode::automation_force_opt_in()` returns `false` for any mode other
  than `legitimate_interest` regardless of the stored option, so a stale
  `OPTION_AUTOMATION_FORCE_OPT_IN=true` left over from a mode switch is inert
  (ContactSyncMode.php:101-106). The UI surfaces the toggle only under
  legitimate-interest, with a lawful-basis warning banner.

**SQL (`BackfillJob`) — clean.**
- `start()`'s freshness DELETE (`DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s`),
  the INSERT…ON DUPLICATE KEY UPDATE, the `current_state` SELECT, and the
  `process_batch` SELECT/UPDATE all bind real arguments via `%s`/`%d` through
  `$wpdb->prepare()`/`$wpdb->update()`. Interpolated identifiers are only the table
  name (`$wpdb->prefix` + a private const) and `$wpdb->usermeta` — no user input in
  any identifier. `current_state()` reads `status`/`completed_at` and parses
  `completed_at` via `strtotime` — no injection.

**Admin TS/TSX (`admin/src/**`) — clean.**
- Pure client-side reducer/state + a radio/checkbox UI. The bundle loads on admin
  requests only. All persisted values are re-validated server-side
  (`save_subscribers`), so client coercions (`normalizeContactSyncMode`,
  `?? false`) are convenience, not a trust boundary. No `dangerouslySetInnerHTML`,
  no injected markup.

**Capability/authorization on new admin actions — clean.**
- The only new operator-invokable surface is the same `POST /settings`. The
  reconcile + refresh run from Action Scheduler (`on_contact_sync_tick`), an
  internal cron callback with no direct user invocation and no parameters derived
  from a request.

---

## Verdict

No Critical or High issues introduced by F3-47 / F3-48. One **Medium**
(bidirectional-sync echo with no loopback suppression — recreates a
Smaily-deleted contact) and its **Low** redundant-write twin, both from the same
missing-echo-guard root cause. Everything else in scope — REST validation +
auth, external HTTP / credential capture, the global meta hooks, consent/PII
separation, and the custom-table SQL — checked clean.
