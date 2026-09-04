# Security delta audit — v3.7.2 gate (PRO-1388 consent-independent attribution capture)

- **Date:** 2026-07-21
- **Baseline:** delta `f470faf..HEAD` (HEAD `5f9b031` at audit time; 3 commits,
  the v3.7.1 release record commit to the pre-bump tip — PRO-1388 browser-side
  attribution capture + its tests, the PRO-1447 contract sync, the PRO-1436 PCP
  suppressions)
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** small delta by line count, but `6d8bd6d`
  touches the consent surface (`public/js/beacon-core.ts`,
  `public/js/lib/rec-engine-client.ts`) — an explicitly named high-risk
  surface (consent/GDPR) in the re-audit policy, so a focused security pass is
  required regardless of size.
- **Scope:** full delta read file-by-file. Security focus on the two beacon
  commits (`6d8bd6d` code, `f2ffd50` tests): does `captureUrlParams()` still
  write only the three attribution cookies and nothing else; does it create a
  session or send any event pre-consent; any new injection/XSS surface in the
  URL-param handling; cookie flags. The other two commits in the delta
  (`ea76f8d` contract doc sync, `5f9b031` PCP-suppression comments) are
  doc/comment-only and got a trivial read-through, no security relevance.

## Verdict

**0 High, 0 Medium, 0 Low, 0 Info. RESULT: clean — v3.7.2 may proceed.**

## What the delta is

Three commits since the v3.7.1 release record (`f470faf`):

1. **`6d8bd6d` — PRO-1388, the security-relevant commit.**
   `RecEngineClient.captureUrlParams()` (`public/js/lib/rec-engine-client.ts`)
   drops its `if ( ! this.hasConsent() ) { return false; }` early-return, so it
   now always inspects the URL for the three campaign-click params
   (`smaily_vt`/`smaily_rec`/`smaily_ctx` by default) and persists any present
   value to its matching cookie, then strips the params from the visible URL.
   `beacon-core.ts`'s `init()` calls it once, unconditionally, immediately
   after constructing the client and before consent is resolved; it used to be
   the first thing done inside the consent-gated `start()` closure and is
   removed from there.
2. **`ea76f8d`** — contract doc sync to v1.5.0 (§14 notifications-ingest
   addition). Doc-only, additive, no plugin code touched (matches the CC-8
   sync-discipline note — verified via `git show --stat`: only
   `docs/RECENGINE_API_CONTRACT.md` + `STATUS.md`).
3. **`5f9b031`** — comment-only `phpcs:ignore` suppressions on four
   pre-existing false-positive PCP warnings in the cart code
   (`CartAbandonmentSweeper.php`, `CartSessionStore.php`). No behavior change,
   confirmed by reading the diff: every hunk is a single inserted comment line
   (plus one `phpcbf`-realigned assignment).

## Security — the beacon consent-surface change, in detail

### 1. Does `captureUrlParams()` write only the three attribution cookies, and never create a session or send anything, pre-consent?

Read `captureUrlParams()` directly (`rec-engine-client.ts:252-287`), not just
the diff. The method:

- builds a `mapping` array of exactly three `{param, cookie, ttl}` triples
  (`cookieNames.visitor` / `.recId` / `.context`);
- for each, calls `this.setCookie(cookie, value, ttl)` **only** when the URL
  param is present and non-empty;
- strips matched params from the URL via `window.history.replaceState` after
  all cookie writes (order is deliberate per the existing docblock — writes
  land before the strip, so a throwing cookie write can't silently lose
  attribution);
- contains no call to `ensureSession()`, `track()`, `flush()`, or `fetch`
  anywhere in its body, and no `document.write`/DOM-injection call.

`beacon-core.ts`'s `init()` (lines 155-198) confirms the call site: `client.
captureUrlParams()` runs once at the top, unconditionally; the session cookie
(`ensureSession()`), the page-view `track()` call, and `attachCartListeners()`
all remain inside `start()`, which still early-returns on `!detectConsent(
boot)` (line 174) and is the only place those three things happen. No other
code path in the diff reaches `ensureSession`/`track`/`flush` outside `start()`.

New tests pin this directly rather than just asserting happy-path shape:
`beacon-core.test.ts`'s `'captures campaign URL params on init even without
consent (PRO-1388), but sends nothing'` asserts the attribution cookie is
written, no `smaily_anon_sid` session cookie appears, and `fetchMock` is never
called after an explicit `flush()`; `rec-engine-client.test.ts`'s
`'captureUrlParams without consent writes no session cookie and sends
nothing'` asserts the same at the client-method level, plus a `track()` +
`flush()` call after capture still produces zero fetch calls. A third test
(`'a token captured pre-consent...rides later events once consent is
granted'`) proves the token survives to a later, correctly consent-gated send
— i.e. the captured value only ever leaves the browser once consent exists,
never as a side effect of capture itself.

**Conclusion: confirmed as claimed.** The method's blast radius is exactly the
three attribution cookies; it is inert with respect to session creation or any
network send.

### 2. Any new injection/XSS surface in the URL-param handling?

- Params are read via the browser-native `URLSearchParams.get()`
  (`new URL(window.location.href)` → `url.searchParams`), which handles
  percent-decoding itself — no manual string parsing, no `eval`, no
  `innerHTML`/`outerHTML` write anywhere in the method or its call sites.
- Captured values are written to cookies via the pre-existing `setCookie()`
  helper (`rec-engine-client.ts:455-462`), unchanged by this diff:
  `document.cookie = name + '=' + encodeURIComponent(value) + ...` —
  `encodeURIComponent` neutralizes `;`/`=`/control characters that would
  otherwise break the cookie-pair syntax or allow attribute injection via a
  crafted param value (e.g. `smaily_vt=x; Domain=evil`). The cookie *name* is
  never attacker-controlled (it comes from `this.config.cookieNames.*`, a
  server-supplied config object, not the URL).
- The URL is only ever rewritten via `history.replaceState` with a
  same-origin path/search/hash reconstructed from the already-parsed `URL`
  object — no navigation, no attacker-controlled full-URL write, no open
  redirect.
- This is a **consent-gate removal**, not new parsing/output logic —
  `captureUrlParams()`'s parsing and cookie-writing code is unchanged from
  before `6d8bd6d` (confirmed via the diff: only the early `hasConsent()`
  return is removed); whatever injection-surface review applied when this
  method was first written (F3-46/3.4.2 era) still applies unchanged. No new
  surface was introduced by making it run earlier/unconditionally.

**Conclusion: no new injection/XSS surface found.**

### 3. Cookie flags sane?

`setCookie()` (untouched by this diff): `Max-Age` derived from a non-negative
`Math.floor(ttlDays * 86400)`, `Path=/`, `SameSite=Lax` always, `Secure`
appended when `window.location.protocol === 'https:'`. No `HttpOnly` — correct
for this cookie class, since the same JS that writes it must also read it back
(`sessionId()`/`visitorToken()`) and re-send it as a request field; these are
non-authentication, first-party attribution cookies, not session-auth tokens
that would warrant `HttpOnly`. `SameSite=Lax` is appropriate for a same-site,
top-level-navigation attribution flow (the email-click landing is a normal
top-level GET). No flag regression introduced by this delta — `setCookie()`'s
body is byte-identical before/after `6d8bd6d`.

### 4. Consent-gating for the rest of the beacon is unchanged

Re-read `init()`/`start()` end-to-end (not just the diff hunk): `detectConsent
(boot)` still gates `start()` entirely (session creation, the page-view track
call, and cart-listener attachment), and the `wp_listen_for_consent_change`
listener still exists to retry `start()` once consent arrives later. Nothing
else in the delta touches the consent-check function (`detectConsent`) or the
WP Consent API wiring (`window.wp_has_consent`) itself.

## Doc/comment-only commits (no findings)

- **`ea76f8d`** (contract sync): additive-only per the diff (`git diff
  --stat` shows only `docs/RECENGINE_API_CONTRACT.md` and `STATUS.md`); no
  plugin code, no wire-shape change to any existing field the plugin sends —
  matches the sync's own commit message and the CC-8 discipline note.
- **`5f9b031`** (PCP suppressions): every hunk in the diff is a single
  `phpcs:ignore` comment line (plus a `phpcbf` realignment); no logic
  changed. Re-confirmed at this gate's own PCP-on-ZIP run (below) — the
  suppressed warnings do not reappear and no new PCP finding surfaced.

## What this audit does NOT cover

Read-only security delta pass on the two beacon commits (the flagged
high-risk surface) plus a trivial pass on the doc/comment-only commits — not
the full v3.7.2 release-gate checklist. `ci:strict`, the integration suite,
and PCP-against-the-built-ZIP were run separately as part of the same release
prep and are recorded in `STATUS.md` and the `INDEX.md` row for this gate, not
duplicated here.
