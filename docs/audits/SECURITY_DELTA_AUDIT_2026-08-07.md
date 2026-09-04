# Security delta audit — v3.11.0 release gate (v3.10.0..HEAD)

- **Date:** 2026-08-07
- **Baseline:** delta `36977de..5b3dbbc` (the v3.10.0 tag to the pre-cut main
  tip; **100 commits, 157 files, +12 080 / −2 166 lines**). Two distinct
  bodies of work in one delta:
  1. the **sendsmaily upstream merge** (`4a3d979`, folding
     `upstream/main` in to unblock PR #135 — their legacy quality/PHPStan
     pass, the wp-env dev env, `release.sh` fixes);
  2. the **defect sweep + feature work** since: PRO-1679 (first-order on
     block checkout), PRO-1680/1729 (abandoned-cart product + name fields),
     PRO-1685 (Smaily retry policy + migration 010), PRO-1681 (automation
     run markers), PRO-1683/1684/1743/1772 (contact-field selection
     interpreter), PRO-1682 (welcome trigger narrowed), PRO-1742 (contact-sync
     switch), PRO-1716 (force opt-in retired), PRO-1715/1769 (contact
     backfill), PRO-1686 (Smaily refusal classification), PRO-1710 (rec-id
     UUID validation), PRO-1633 (line-level return signals), PRO-1767
     (standalone attribution bundle), PRO-1712 (`/relay` field-whitelist
     narrowing), contract syncs v1.6.0 → v1.8.1.
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** release boundary (cutting v3.11.0) — policy
  point 1 applies regardless of size. Independently qualifies on point 2 many
  times over: >2 000 changed plugin lines, the public `/relay` route, a new
  storefront JS bundle that writes cookies pre-consent, custom-table SQL
  (migration 010 + `EventQueue`/`BackfillJob` queries), REST admin routes,
  consent/GDPR posture (`force_opt_in`, welcome-trigger eligibility), and
  what gets stored/logged.
- **Scope:** full file-by-file read of the production delta
  (`git diff v3.10.0..HEAD -- includes/ public/ integrations/ blocks/ admin/
  migrations/ bin/ vite.config.ts .zipignore package.json`). Adversarial pass
  on the six focus surfaces the release brief named. Test-only, docs-only and
  i18n-only files were skimmed for accidental secrets, not audited as
  behaviour. PCP against the built ZIP is OUT of scope here — it belongs to
  the release-build pass.

## Verdict

**0 Blocking, 0 Critical, 0 High. 1 Medium (should-fix, NOT a release
blocker — see §1), 1 Low, 4 Info. RESULT: v3.11.0 may proceed.**

The delta's net effect on the security posture is **positive**: five separate
changes tighten input validation, consent handling or failure containment
(PRO-1712, PRO-1710, PRO-1716, PRO-1742, PRO-1685's `MAX_DELAY` clamp), and
three legacy SQL statements that were mis-`prepare()`d were corrected. The one
Medium is an inherited validation gap that the delta *widens the reach of*
rather than creates.

---

## 1. MEDIUM (should-fix, non-blocking) — the JS attribution writer does not apply the shape/length checks its PHP twin does, and PRO-1767 gives it to stores that previously only had the PHP one

**Where:** `public/js/lib/attribution.ts` (`captureAttributionParams`) vs
`includes/Integrations/WooCommerce/LandingCapture.php` (`resolve()`).

The two writers of the same three first-party cookies validate differently:

| Slot | `LandingCapture` (PHP) | `attribution.ts` (JS) |
|---|---|---|
| `smaily_rec` → `smaily_rec_id` | `RecId::is_valid()` — UUID | `REC_ID_PATTERN` — UUID ✅ same |
| `smaily_vt` → `smaily_rec_uid` | `/^vt_[A-Za-z0-9]{1,64}$/` | **none** — any value, any length |
| `smaily_ctx` → `smaily_rec_ctx` | `/^[A-Za-z0-9._-]{1,64}$/` | **none** — any value, any length |

PRO-1710 correctly ported the rec-id rule to both ends (that is the whole
point of `RecId` + `REC_ID_PATTERN` being one definition twice). The other two
slots were left asymmetric.

**Why the delta makes it matter more.** Before PRO-1767 the JS writer only ran
on stores with browse tracking ON (it lives inside `RecEngineClient`). Since
`5965554`, `StorefrontBeacon::is_attribution_only_enabled()` loads
`sc-landing.js` on **every front-end page of any connected store with browse
tracking off** — precisely the stores whose only writer used to be the strict
PHP one. So the permissive path is newly reachable on a class of stores where
it previously was not.

**Reachable impact** (verified by reading the chain end to end):

1. A crafted link `…/?smaily_ctx=<arbitrary>` (or `smaily_vt=`) writes the raw
   value into a `Path=/`, 30/365-day first-party cookie.
2. `HookHandler::save_attribution_cookies_to_order()` reads that cookie at
   checkout with `sanitize_text_field()` and **no length or shape check**, and
   stamps it onto order meta.
3. `OrderPayloadBuilder` forwards `smaily_visitor_token` / `smaily_rec_ctx`
   to the engine's §5 orders wire unvalidated (only `smaily_rec_id` is
   shape-checked there, PRO-1710).

That is (a) attribution-data pollution the engine types as bare
`z.string()`, and (b) a small self-inflicted availability problem for the
victim — an oversized `smaily_ctx` planted by a link becomes an oversized
`Cookie` header on every subsequent request to that store, which fronting
proxies answer with 400/431 until it expires.

**Not blocking**, for three reasons: it is **not** a privilege boundary (a
shopper can always self-attribute their own order — that is what the feature
is), the same permissive path has been live on every browse-enabled store
since 3.4 without incident, and no secret or other user's data is exposed.

**Recommended fix (fast-follow, not applied here):** give `attribution.ts`'s
`mapping` an `isValid` for the other two slots mirroring the PHP regexes, and
add a length cap in `HookHandler::save_attribution_cookies_to_order()` so a
cookie planted before the fix cannot ride an order. Deliberately not applied
in this pass — it is not a one-liner (two validators + vitest coverage + a
rebuild of both storefront bundles), and the release brief scopes this pass to
reporting.

## 2. LOW — a retired consent option is left on disk, unread

`ContactSyncMode::OPTION_AUTOMATION_FORCE_OPT_IN`
(`smly_plus_contact_sync_automation_force_opt_in`) was removed in PRO-1716
along with every reader; `SettingsEndpoint::save_subscribers()` no longer
writes it and `EnvDetector` no longer echoes it. The stored row is **not
deleted on upgrade**, so a store that had enabled it keeps a truthy option
nothing reads.

Direction of the change is the safe one — `AutomationRouter` now passes
`force_opt_in: false` unconditionally, so an automation trigger can no longer
re-subscribe a contact who unsubscribed in Smaily (GDPR Art. 21), whatever the
stale option says. Low only because a future reader added by mistake would
inherit a value the merchant can no longer see or change. Worth a line in the
uninstall/`EnvScrub` sweep.

## 3. INFO — `/relay` (PRO-1712) is a pure narrowing; verified as such

Read `includes/REST/BeaconEndpoint.php` directly, both `EVENT_FIELDS` and the
copy loop that applies it. The delta only **removes** `smaily_rec_id` and
`smaily_ctx` from the whitelist; the applying code is unchanged and is a flat,
exact-key, case-sensitive `array_key_exists` copy into a fresh `$row`, so a
stripped field cannot re-enter under a case variant, an alias, or nested
inside an allowed field (a nested value stays inside its own field's value and
is never promoted to a top-level key the forwarder reads).

`attach_logged_in_identity()` — the one sanctioned server-side writer of
`customer_email`, assigned after the whitelist — is untouched, still gated on
`wp_validate_auth_cookie()` plus the `ProfilingConsent` check, and still never
reaches the JS blob or the `/relay` response. Route auth is unchanged
(`permission_callback => '__return_true'` by design; the abuse filter and rate
limit are the gate, and the endpoint forwards only whitelisted scalars).

**Net: the delta removes attack surface from the only unauthenticated route in
the plugin.** No finding.

## 4. INFO — `sc-landing.js` boot blob and bundle contain nothing sensitive

`StorefrontBeacon::attribution_config()` was deliberately built as a strict
subset of `beacon_config()`: cookie names, URL-param names, TTL integers. It
carries **no `beaconUrl`**, no session cookie name, no tenant id, no key. The
built artifact was inspected directly: `dist/public/js/sc-landing.js` is
1 194 bytes with **zero** occurrences of `fetch` / `XMLHttpRequest` /
`sendBeacon` / a top-level `import` — it genuinely cannot transmit anything,
which is what justifies loading it consent-independently.

The consent posture is unchanged and matches the standing decision (F3-46):
attribution cookies are first-party functional signal, gated on
`is_connected()` plus the `smaily_connect_capture_attribution` master switch,
while browse telemetry stays behind the WP Consent API. The three cookies and
their TTLs are already disclosed in the merchant docs' privacy template in
both EN and ET (`docs/site/index.html`) — the delta adds a second *writer*,
not a new cookie, so the disclosure stays accurate.

Also verified: the enqueue is mutually exclusive with the runtime
(`is_attribution_only_enabled()` returns false whenever `is_enabled()` is
true), so no store gets two writers of the same cookies.

## 5. INFO — new and rewritten SQL is correctly parameterised

Every SQL statement in the delta was read:

- **Migration 010** (`010-add-event-queue-next-retry-at.sql`) — a restated
  `CREATE TABLE` for `dbDelta`, no interpolation, dbDelta formatting
  invariants preserved.
- **`EventQueue::record_attempt()`** — `next_retry_at = ( UTC_TIMESTAMP() +
  INTERVAL %d SECOND )` inside `$wpdb->prepare()`; `%d` emits an unquoted
  integer, which is exactly what `INTERVAL` needs, and the value comes from
  `RetryPolicy::delay_seconds()` (clamped, see below). `pending()`'s new
  `next_retry_at <= %s` binds `current_time( 'mysql', true )` (UTC, matching
  `UTC_TIMESTAMP()`). Table names are `$wpdb->prefix`-derived constants.
- **`BackfillJob::fetch_user_ids_after()`** — new direct query replacing a
  `get_users()` + PHP-prune; `"SELECT ID FROM {$wpdb->users} WHERE ID > %d
  ORDER BY ID ASC LIMIT %d"` prepared with both values. No user input reaches
  it (cursor is an int column read back from the plugin's own job row).
- **Legacy `Cron::get_abandoned_carts()` / `set_abandoned_carts()` and
  `Lifecycle::delete_tables()`** — the upstream merge replaced three
  statements that used `$wpdb->prepare()` with `%1$s` **for the table name**
  (which quotes it as a string literal — broken, and it hid the fact that the
  *values* were being interpolated into quoted placeholders). The new form
  concatenates the plugin-constant table name and binds the values with `%s`.
  This is a correctness **and** a hygiene improvement; no external input
  reaches any of them.

**`RetryPolicy` bounds a server-supplied value.** `Retry-After` is read from
Smaily's response and passed to `delay_seconds()`, which does
`min( $retry_after, MAX_DELAY /* 21600 */ )` — a hostile or fat-fingered
header cannot park a queue row for days. The attempt ceiling
(`MAX_ATTEMPTS = 5`) plus the permanent/temporary split also removes the
pre-existing "re-POST a 401 every 60 s forever" behaviour, which was a
self-inflicted outbound-request amplifier.

## 6. INFO — consent / PII posture of the contact-sync changes

- **PRO-1716** flips `Client::trigger_automation()`'s `$force_opt_in` default
  from `true` to `false` and hard-codes `false` at the only call site. A
  Smaily-side unsubscribe can no longer be overridden by any preset or any new
  trigger added by omission. Strictly safer.
- **PRO-1682** narrows the welcome automation from any `user_register` to
  WooCommerce's own account-creation flow, with a documented filter to widen
  it. Fewer people are enrolled into marketing without a customer
  relationship. Strictly safer.
- **PRO-1742** makes the master "Sync contacts to Smaily" switch actually stop
  the backfill and the audience count, not just the live hooks. Strictly
  safer. The `'1'`/`''` storage shape correctly works around
  `update_option( $key, false )` writing nothing for a never-saved option.
- **PRO-1683/1684/1743/1772** route every reader of the field selection
  through one interpreter that intersects with `SUPPORTED_FIELDS`, so no
  arbitrary key can reach a Smaily contact payload. `SettingsEndpoint` also
  `sanitize_key()`s each submitted name before storing. No new PII category is
  introduced; the payload's field set is the documented one.
- **PRO-1681 `AutomationMarker`** adds three contact fields whose values are
  `gmdate('Y-m-d H:i:s')` — a timestamp, no personal data.
- **Standing (not a delta finding):** the Event Log's F3-44 `sent_payload`
  holds the full contact-sync request body — email, names, phone, birthday —
  in a custom table, readable in wp-admin behind `Constants::CAPABILITY` and
  janitor-pruned. The delta adds marker timestamps to those bodies but no new
  PII class. Likewise the *legacy* `Cron` / `Subscriber_Synchronization` error
  paths log `wp_json_encode( $response )`, which for `list_unsubscribers()`
  can contain subscriber emails; the delta only reshaped
  `return $this->logger->error(…)` into two statements and did not change what
  is logged. Both are pre-existing and out of this delta's scope — flagged so
  they are not mistaken for new.

## 7. INFO — admin surfaces touched by the sweep

- REST permission callbacks are unchanged and all admin routes still gate on
  `current_user_can( Constants::CAPABILITY )`; only `/relay` is public, by
  design. `BackfillEndpoint::start()`'s change is pure ordering (read the row
  before deciding whether to schedule a tick) with no auth implication.
- `TestConnectionEndpoint` now returns one of three merchant-facing messages
  instead of one. All three are static translated strings — **no part of the
  Smaily response body is echoed to the browser**, so a hostile upstream
  response cannot reach the admin UI. The classification input is the HTTP
  status plus Smaily's own numeric `code`, both cast to `int`.
- `Step1Connect.tsx` opens `https://{subdomain}.sendsmaily.net` via
  `window.open(..., 'noopener,noreferrer')`. The subdomain is the
  capability-gated admin's own typed value in their own browser — no boundary
  is crossed — and the new doc links are `target="_blank" rel="noopener
  noreferrer"` on a filterable-but-admin-controlled `docsUrl`. No finding.
- `NotificationManager`'s two new notices use `esc_html__()` for text and
  `wp_kses_post()` for the generated links, matching the existing advisory.
- `bin/exchange-setup-token.php` (new) carries both a `defined( 'ABSPATH' )`
  guard and a `php_sapi_name() !== 'cli'` exit, prints only non-secret fields,
  and reads the token from STDIN. `/bin*` is excluded by `.zipignore`, so it
  does not ship. The `.zipignore` edits in this delta only drop entries for
  files the delta deleted (`Dockerfile`, `compose*.yaml`) and add the new
  `.wp-env.min.json`. No dev artifact newly enters the ZIP.

## Gates run for this pass

- `npm run ci:strict` **exit=0** — PHPCS 0 errors, PHPStan `[OK] No errors`,
  PHPUnit unit **739 tests / 2 087 assertions**, eslint/tsc clean, vitest
  **275/275**.
- No product code was changed by this audit, so the integration suite was not
  re-run for it (the tree is as the previous pass left it green).
- PCP against the built ZIP is the release-build pass's gate, not this one.

## Follow-ups filed out of this pass

1. **Medium §1** — port the `smaily_vt` / `smaily_ctx` shape checks into
   `attribution.ts`, and cap cookie length in
   `HookHandler::save_attribution_cookies_to_order()`.
   **RESOLVED 2026-08-10 (PRO-1896, `ac3f9c9` + `bd6bed4`)** — both halves
   applied as described; DECISIONS PRO-1896.
2. **Low §2** — delete `smly_plus_contact_sync_automation_force_opt_in` in the
   option-scrub path.
   **RESOLVED 2026-08-10 (PRO-1897)** — the key joined the retired-option list
   `Activation::cleanup_retired_options()` (renamed from
   `cleanup_removed_rec_feature_options()`) deletes on every upgrade-detect, so
   an upgraded store no longer keeps the orphan row; uninstall + `EnvScrub`
   already caught it via their `smly_plus_` LIKE-sweeps. Pinned by
   `BackwardCompatTest::test_upgrade_trigger_deletes_the_retired_force_opt_in_option`.
3. **Doc hygiene** — `MOCK_DIVERGENCE_AUDIT.md` §3's *header* still carries the
   pre-3.3 `📋 engine-team-reported, ⏳ to verify` marks for orders even though
   the endpoint has been live-walked repeatedly (the §3 body is now correct);
   the same is true of §2 and §4. A pass over that document's legend marks is
   overdue.
