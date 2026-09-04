# Security re-audit — T2 automations config surface (F3-51 PHP proxy + F3-52 React UI)

- **Date:** 2026-07-07
- **Auditor:** security pass, delta-only (v3.4.0 release gate)
- **Scope (delta):** commits `4b6fd3f..8d22022` on `main` — the T2 epic
  (`9ec2ff8..8d22022`: contract v1.1.0 sync, `Client::automations_*`,
  `REST\AutomationsEndpoint`, the React "Engine-run recommendation automations"
  section) **plus** the two smaller security-relevant changes since the last
  audited baseline that never got a register row: F3-49 (browse
  `smaily_visitor_token`) and F3-50 (consent-API admin advisory).
- **Baseline:** the standing `docs/audits/SECURITY_AUDIT.md` +
  `2026-06-30-SECURITY_RE_AUDIT_F347_F348.md` cover the pre-delta tree.

## Files reviewed (the delta's security-relevant surface)

| File | What changed |
|------|--------------|
| `includes/REST/AutomationsEndpoint.php` | NEW — 3 admin REST routes (GET catalog / GET config / PUT config), PUT forwards admin input to the engine |
| `includes/REST/EndpointRegistry.php` | wires the endpoint + expected_routes |
| `includes/Smaily/RecEngine/Client.php` | new `automations_catalog()/automations_config()/put_automations_config()` (first PUT verb) + `PATH_AUTOMATIONS_*` fallbacks |
| `admin/src/api/automations.ts` | NEW — typed proxy client + failure classification |
| `admin/src/state/engine-automations.ts` | NEW — pure rows/validation/save orchestration |
| `admin/src/hooks/useAutomationsData.ts` | NEW — fetch-on-open |
| `admin/src/components/steps/EngineAutomationsSection.tsx` | NEW — renders engine-origin strings (`name_*`, `description_*`, `recipe_et`, `docs`, 422 `errors[]`) |
| `includes/Notifications/NotificationManager.php` | F3-50 — consent-API advisory (admin notice HTML) |
| `public/js/lib/rec-engine-client.ts` | F3-49 — browse events carry the `smaily_visitor_token` cookie value |

## Method

Read each changed file in full; traced (a) the browser→REST→engine PUT path for
injection/reflection/SSRF, (b) the engine→REST→browser GET path for XSS sinks
and secret leakage, (c) what gets logged. Attempted a concrete exploit per focus
area; dropped anything not substantiable against the code.

---

## Findings

**0 Critical / 0 High / 0 Medium. 1 Low (fixed in this pass). 3 Info (accepted).**

### LOW-1 (FIXED) — engine-origin `docs` URL rendered as an unvalidated anchor href

`EngineAutomationsSection.tsx` rendered `<a href={docsUrl}>` straight from the
§11 catalog response. The engine is an authenticated TLS peer, but an anchor
href is a scheme-sensitive sink — a compromised engine (or a future mock/config
mistake) serving `javascript:…` would execute in wp-admin under a
`manage_options` session. React only warns on `javascript:` URLs; it does not
block them. **Fix (same pass):** the link renders only when the URL matches
`^https?://` (`isHttpUrl()` guard); otherwise the recipe text renders without a
link. Defense-in-depth — every other engine-origin string in the section
(`name_*`, `description_*`, `recipe_et`, 422 `field`/`message`) is rendered as
JSX text nodes (auto-escaped), and no `dangerouslySetInnerHTML` exists anywhere
in the new code.

### INFO-1 — engine error `message`/`error` echoed to the admin UI

`engine_error_response()` (non-401/422 case) forwards the engine's `error` code
and `message` to the browser. Both render as escaped text (React text nodes /
`Banner` children). Engine-controlled text shown to an admin is the intended
diagnostic surface; no HTML sink. Accepted.

### INFO-2 — no dedicated unit test for `permission_check()`

The capability gate (`current_user_can('manage_options')` on all three routes)
follows the identical pattern of every other admin endpoint (pattern-tested
there); nonce enforcement is WP core's REST cookie-auth (`X-WP-Nonce` sent by
`admin/src/api/client.ts`). Behavior verified by reading; a copy-paste test adds
little. Accepted.

### INFO-3 — `test_emails` are PII-light data forwarded to and stored by the engine

Admin-entered addresses go to the engine over TLS and come back in GET config
(admin-only routes). Nothing on this path is logged plugin-side (the automations
calls do not run through the queue/`store_exchange` mechanics; `DebugLog` writes
only the version-drift line). Accepted — same class as the contact-sync PII the
standing audit covers.

## Confirmed clean (attack surface walked)

- **Capability + nonce on all 3 routes**: `permission_callback` →
  `manage_options`; cookie-auth nonce enforced by core; JS client sends
  `X-WP-Nonce`. A subscriber/shop-manager gets 403; a nonce-less cross-site
  request gets 401 (`rest_cookie_invalid_nonce`).
- **PUT passthrough is not an injection vector**: `configs` is forwarded as a
  JSON body (`wp_json_encode`) to a fixed engine URL — no SQL, no eval, no file
  I/O, no shell, no header interpolation. The engine's Zod is the validator
  (F3-51); a hostile row can at worst 422.
- **No SSRF surface added**: the request URL comes from the stored endpoints
  map / `base_url` (set at setup-exchange from the engine, admin-gated —
  the standing audit's known Low), never from request input.
- **api_key cannot leak**: the key exists only server-side (proxy decrypts and
  sends `Authorization: Bearer`); `ApiException` carries only the engine's
  response body; no error path echoes headers; the React layer never sees it.
  The 401→502 `api_key_rejected` mapping returns a fixed message.
- **422 `errors[]` passthrough**: rendered exclusively as text
  (`FieldIssues` / banner join) — no HTML sink for engine-authored `field`/
  `message` strings.
- **No new unauthenticated entry point**: all three routes are admin-gated; the
  connection gate (503) runs before any engine call.
- **F3-49 (`smaily_visitor_token` on browse)**: the value is an engine-issued
  opaque cookie read client-side and sent to the existing `/relay` proxy whose
  server-side field whitelist already carried the key; omit-on-empty; no
  rec_id/email added (data-minimization held).
- **F3-50 (consent advisory)**: admin-notice HTML fully escaped
  (`esc_html__`/`esc_url`/`wp_kses_post`); dismiss link reuses the existing
  nonce'd dismiss action; no new input.

## Gates

Targeted vitest (`EngineAutomationsSection.test.tsx` 9/9) + `tsc --noEmit`
green after the LOW-1 fix; full `ci:strict` + PCP against the built ZIP run as
part of the v3.4.0 release gate (see INDEX row).
