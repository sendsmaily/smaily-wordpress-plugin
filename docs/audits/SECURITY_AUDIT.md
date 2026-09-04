# Smaily Connect — Security Audit

**Date:** 2026-06-25 · **Auditor:** Claude (Opus 4.8), read-only — no code modified.
**Repo state:** `main` @ v2.1.0-beta.10 (commit `2597888`).
**Scope:** the delta since the prior full audit (`FABLE_AUDIT.md`, baseline
`906cf3d`, 2026-06-11) — ~151 files / +10.4k lines — plus a broad opportunistic
sweep of the high-risk surfaces. Gate purpose: **3.0 GA + upstream merge into the
public wordpress.org plugin** (so the WordPress.org plugin-review security bar is
applied).
**Method:** three parallel read-only exploration passes (security / wp.org-review /
code-quality) + `wp plugin check` (PCP 2.0.0) + `composer audit` + `npm audit`;
every cross-agent disagreement spot-verified by hand (see §ABSPATH reconciliation).

> **Headline: no Critical or High findings. No release-blocking security issue.**
> The codebase is security-conscious. Everything below is Low / Info / polish.

---

## High-risk surfaces — verdicts

| Surface | Verdict |
|---|---|
| Public unauthenticated `/relay` beacon (`REST/BeaconEndpoint`) | **SAFE.** The only `__return_true` route; compensated — hard 404 when disabled (zero work before gating), 9-type event allowlist, `event_id` required, 100-event cap, per-event field whitelist, profiling opt-out filter, per-IP + per-session rate-limit. **No SSRF**: forward target is server-stored (engine base_url + endpoints map), never caller-influenced; body is whitelisted, not reflected. |
| Admin REST routes (Settings/Events/Backfill/RecEngine/TestConnection/Workflows/legacy smaily-api) | **SAFE.** Every route enforces `current_user_can('manage_options')`; none use `__return_true`. State-changing routes rely on the WP REST cookie-nonce (`X-WP-Nonce`). Inputs sanitized (`sanitize_key`/`sanitize_text_field`/int casts/allowlists). |
| SQL / `$wpdb` (queues, backfill, events UNION) | **SAFE.** Every interpolated value parameterized via `$wpdb->prepare` (`%s`/`%d`); dynamic `IN(...)`/`NOT IN(...)` build placeholder lists and pass values as args; interpolated identifiers are fixed constants (`table_spec` literals, `$wpdb->prefix`), never user input. No injection found. |
| Crypto (`smaily-cypher.class.php`, Cypher v2) | **SAFE.** v2 = AES-256-GCM, random 12-byte nonce per message, 16-byte tag, key = sha256(SECURE_AUTH_KEY). `encrypt()` only writes v2; legacy CBC+HMAC is decrypt-only, selected by absence of the `smy2:` prefix — not downgrade-abusable. |
| Event Log exchange storage (F3-44, migration 007) | **SAFE.** Both `Client` classes capture the exchange from method/endpoint/body+reply and **explicitly exclude the `Authorization` header** (verified in source). Terminal skip → `last_response={outcome:skipped}`, NULL `sent_payload`. Detail read endpoint is `manage_options`-gated. |
| admin-post / AJAX (`NotificationManager::handle_dismiss`, `ProfilingConsentAccount`) | **SAFE.** Nonce + capability checked; dismiss link `wp_nonce_url`-signed; the My-Account consent form operates only on the current user's own email. |
| Storefront output (`StorefrontBeacon`) | **SAFE.** `window.smailyConnectBeacon` emitted via `wp_json_encode()`; `beaconUrl` via `esc_url_raw`; logged-in email deliberately NOT placed in the JS blob. |
| GDPR (`Privacy/GdprHandler`) | **SAFE.** Registered via the WP Privacy API exporter/eraser (core's authenticated + email-verified flow); email `rawurlencode`d into the path; decision-logic fields stripped from export. |
| Broad sink sweep | **CLEAN.** No `eval`/`system`/`exec`/`shell_exec`/`passthru`, no `unserialize`, no variable `include`/`require`, no `wp_redirect` (all `wp_safe_redirect`), no `echo` of raw superglobals. `base64_*` hits are legit (HTTP Basic auth header, GCM ciphertext blob). |

---

## Findings

| # | Severity | Area | Location | Issue | Recommendation |
|---|---|---|---|---|---|
| S-1 | Low | SSRF (admin-gated) | `Smaily/RecEngine/SetupExchange.php:130-153`, `RecEngineEndpoint.php:128-163` | The engine `base_url` comes from the admin-pasted setup URL / `base_url` body param, is persisted, and becomes the forward target for all later ingest/GDPR calls. No scheme allowlist (http accepted), no host allowlist. | Acceptable for GA (gated by `manage_options`; api_key is per-connection since engine migration 0036, limiting blast radius). Optional hardening: require `https://` on `base_url`; document the setup URL as trusted input. |
| S-2 | Low | PII at rest | `Smaily/Client.php` exchange capture; `IngestQueue`/`EventQueue` `sent_payload`/`last_response`; `REST/EventsEndpoint` | Event Log stores full request/response bodies in cleartext (customer emails, order data, browse events). Read path is `manage_options`-gated and the auth header is excluded. | No change required for GA — documented design, matches what `payload` already held; janitor-prune + ~10 KB trim bound growth. Document the retention window for the DPA. Confirmed no credential ever rides inside a payload body. |
| S-3 | Info | Rate-limit robustness | `REST/BeaconEndpoint.php:386-414` | Per-IP/per-session throttle uses non-atomic transients (`get`+`set`) → concurrent requests can race past the ceiling; session counter is bypassable by rotating the cookie (per-IP still applies). | Accept (documented as approximate). REMOTE_ADDR-only is the correct choice (XFF spoofing rejected). Engine-side rate-limiting is the real backstop. |
| S-4 | Info | Debug logging volume | `REST/BackfillEndpoint.php:138/152/166`, `BeaconEndpoint.php:306`, `Bootstrap.php:256`, `Smaily/BackfillJob.php`, `Privacy/*`, others (~21 call sites) | Unconditional `error_log()` diagnostics (job_type, counts, row ids). **No credentials/tokens/PII logged** (each call site checked) — NOT a regression of FABLE_AUDIT F1. | Optional but recommended before a public submission: gate the diagnostics behind `WP_DEBUG` / a constant. wordpress.org reviewers sometimes flag unconditional `error_log` in shipping code. (PCP `error_log_error_log`, see CODE_QUALITY_AUDIT.) |

---

## ABSPATH reconciliation (why hand-verification mattered)

The security pass initially reported "all files carry the `defined('ABSPATH')`
guard"; the wp.org-review pass reported ~10 missing. **Both were partly wrong.**
Hand-verification (`git ls-files '*.php'` + grep) established the truth:

- **The new PSR-4 `Smaily\Connect\*` code is guarded.** The **inherited legacy
  layer is NOT** — ~29 shipped files (`includes/smaily-*.class.php`,
  `integrations/**`, `blocks/**`, `public/smaily-public.class.php`,
  `admin/partials/notices/*`, `admin/smaily-admin-notices.class.php`,
  `migrations/upgrade-1-3-0.php`) declare `namespace` directly with no guard.
- `uninstall.php` **is** correctly guarded — `defined('WP_UNINSTALL_PLUGIN')`
  (the right convention for uninstall), not ABSPATH.

This is a **defense-in-depth / project-convention gap**, not an exploit (PHP
class-definition files with no file-level side effects do nothing when hit
directly). PCP hard-flags only the 4 output/side-effect files. It is tracked in
CODE_QUALITY_AUDIT (the punch-list item) because the project's own rule mandates
the guard in every PHP file and a public submission wants it. **Lesson:** when two
audit passes disagree on a concrete, listable fact, verify — don't average.

---

## Dependencies

- `composer audit` → **No security vulnerability advisories found.**
- `npm audit --omit=dev` → **found 0 vulnerabilities.**

---

## Verdict

**No release-blocking security issue for 3.0 GA or the wordpress.org merge.** The
two Low items (admin-gated SSRF scheme/host hardening; documenting cleartext
PII-at-rest) are worth a glance but defensible as-is. The Info items
(approximate rate-limit, unconditional `error_log`) are polish — the `error_log`
gating is the one most worth doing before a public submission. Re-run this audit
after the next class of bigger changes (see `docs/audits/INDEX.md` § Re-audit
policy).
