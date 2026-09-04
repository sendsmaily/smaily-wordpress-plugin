# Security delta audit — v3.8.0 gate (PRO-1389 ongoing-session identity injection)

- **Date:** 2026-07-21
- **Baseline:** delta `bc7bcc9..04789b8` (v3.7.2 bump to the pre-3.8.0-bump tip;
  16 commits — PRO-1389 ongoing-session identity injection on `/relay`
  (`90e2712`/`34d8c3d`/`895c7da`), a PRO-1402 decision record, a docs refresh
  (PRO-1197), and the PRO-1491 catalog default-category-fallback +
  auto-draft-skip fixes (`e98e092`/`642eae3`/`7d3c799`/`07e4f0c`/`04789b8`))
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** `90e2712`/`34d8c3d`/`895c7da` touch the public,
  unauthenticated `/relay` route's identity/consent surface (`includes/REST/
  BeaconEndpoint.php`) — a named high-risk surface in the re-audit policy
  (auth/capability logic + consent), independent of line count.
- **Scope:** full delta read file-by-file. Adversarial security focus on the
  three PRO-1389 commits per the release brief's explicit questions: does
  injection fire only for a validated logged-in cookie; can a forged/expired
  cookie cause injection; does the opted-out path really forward unchanged;
  is the email absent from every response/error path; any timing/enumeration
  angle via the consent lookup. The PRO-1491 catalog commits were checked for
  any new user-input surface (none — see below); the docs-only commits got a
  trivial read-through.

## Verdict

**0 Critical, 0 High, 0 Medium, 0 Low, 0 Info. RESULT: clean — v3.8.0 may
proceed.**

## What the delta is

16 commits since the v3.7.2 release record (`bc7bcc9`):

1. **`90e2712` — PRO-1389, the security-relevant commit.**
   `BeaconEndpoint::attach_logged_in_identity()` resolves the current visitor
   server-side via a new `resolve_logged_in_email()` (validates the real WP
   `logged_in` auth cookie with `wp_validate_auth_cookie()`) and, for a
   consenting logged-in visitor, attaches `customer_email` to every event in
   the batch before the outbound `/api/v1/ingest/browse` call. Wired into
   `handle()` between the field-whitelist validation and the (a).1 profiling
   drop.
2. **`34d8c3d`** — unit (`BeaconEndpointIdentityTest`) + integration
   (`RecEngineBrowseProxyTest`) coverage for the injection, plus a mock-gap
   fix (the mock's `last_browse_events` introspection had silently dropped
   `customer_email`).
3. **`895c7da`** — PRO-1389 follow-up: `attach_logged_in_identity()` now
   returns the resolved email + its `may_profile()` decision so
   `filter_by_profiling()` can reuse it instead of re-checking the same email
   once per event in the batch (pure perf/dedup change, no new code path).
4. **`27c0d98`** — docs-only (DECISIONS/CLAUDE/GDPR/site/STATUS), records the
   PRO-1389 decision and updates the privacy-policy template's wording.
5. **`ae08d4b`** — docs-only (PRO-1402 decision record).
6. **`ef1b911`** — docs-only (ARCHITECTURE/DEVELOPER/API refresh).
7. **`e98e092`/`642eae3`/`7d3c799`/`07e4f0c`/`04789b8`** — PRO-1491: the
   integration mock's catalog route now rejects an empty `category_path`
   (matching the live engine); `CatalogPayloadBuilder::primary_category_
   path()` falls back to the store's own `default_product_cat` term NAME,
   resolved at build time via `get_option()`/`get_term()`, only when a
   *published* product has zero `product_cat` terms (still `''` — engine
   fail-loud rejection — if even that default is unresolvable);
   `CatalogHookHandler::on_save_product()` now skips `auto-draft` status
   posts. Checked for a security angle: **none** — every new code path reads
   only internal WP options/terms already readable by any code on the site
   (`default_product_cat`, `get_term()`), takes no new external/user input,
   opens no new route, and touches no auth/consent/SQL/logging surface. Docs
   commits confirm this was checked deliberately, not assumed.

## Security — the PRO-1389 identity-injection change, in detail

### 1. Does injection fire only for a validated logged-in cookie? Can a forged/expired cookie cause injection?

Read `resolve_logged_in_email()` directly
(`includes/REST/BeaconEndpoint.php:359-369`):

```php
protected function resolve_logged_in_email(): string {
    $user_id = wp_validate_auth_cookie( '', 'logged_in' );
    if ( ! $user_id ) {
        return '';
    }
    $user = get_userdata( (int) $user_id );
    if ( ! $user instanceof \WP_User || $user->user_email === '' ) {
        return '';
    }
    return strtolower( trim( (string) $user->user_email ) );
}
```

`wp_validate_auth_cookie( '', 'logged_in' )` is WordPress core's own cookie
validator: passing `''` for the cookie value tells it to read the real
`$_COOKIE[LOGGED_IN_COOKIE]` itself (the standard core pattern used by
`wp_get_current_user()`), and it validates the full cookie — scheme, expiry,
username, and the HMAC-style hash keyed on `wp_hash()`/site auth
salts/keys — returning `false` on ANY mismatch (missing cookie, expired,
wrong scheme, tampered value, or a hash that doesn't verify against the
site's secret keys). There is no code path in `attach_logged_in_identity()`
or its caller that reads `$_COOKIE`/any request field directly for identity —
the only input is the real, server-validated `logged_in` cookie. A forged
cookie (no knowledge of the site's `AUTH_KEY`/`LOGGED_IN_KEY` secrets, which
never leave the server) cannot produce a valid `$user_id`; an expired one
fails the same validator's own expiry check. Either way `resolve_logged_in_
email()` returns `''`, and `attach_logged_in_identity()`'s own early return
means no email is attached — the visitor is treated as anonymous, never as
an error (matches the docblock's stated intent).

**Note on request-forgery, not identity-forgery:** because the route is
public/unauthenticated with no nonce/CSRF check (by design — anonymous
storefront visitors must be able to call it, and WP's cookie-nonce pairing
would break under full-page caching, PRO-1388's own finding), a third-party
page could make the logged-in visitor's own browser POST to `/relay` cross-
site, riding their real, valid cookie. This is not a NEW capability
introduced by PRO-1389 — the endpoint already accepted arbitrary
attacker-shaped `events[]` from anyone (logged in or not) before this change;
PRO-1389 only adds the plugin's own resolution of the caller's *own*, already
logged-in identity onto events the caller is already free to submit (which
could, without this feature, already include a self-supplied `customer_email`
in `EVENT_FIELDS` — pre-existing since 3.4.0, confirmed via `git log -S` on
`EVENT_FIELDS`, commit `cb06cb4`). PRO-1389 does not let an attacker attach
email to a VICTIM's events from a third-party origin; it can only ever attach
the requester's own validated identity.

Both the unit suite (`test_logged_in_and_consenting_gets_email_attached`,
`test_anonymous_visitor_gets_no_email_attached`) and the integration suite
(`test_logged_in_users_email_is_attached_server_side`,
`test_anonymous_visitor_has_no_customer_email_attached`) drive this end to
end — the integration test in particular uses a REAL `logged_in`-scheme auth
cookie value captured via the `set_logged_in_cookie` action (not a doubled
seam), so the actual WP cookie-validation code path is exercised, not a
stand-in.

**Conclusion: confirmed as claimed.** Injection is gated exclusively on WP
core's own auth-cookie validator; there is no path from a forged or expired
cookie to an attached email.

### 2. Does the opted-out path really forward the event unchanged, never dropped?

`attach_logged_in_identity()` (lines 324-352):

```php
$allowed = $this->profiling === null || $this->profiling->may_profile( $email );
if ( ! $allowed ) {
    return array(
        'events'           => $events,   // unmodified — no customer_email added
        'verified_email'   => $email,
        'verified_allowed' => false,
    );
}
```

When the resolved email is opted out, the method returns the `$events` array
**by value, untouched** (no `customer_email` key added to any event) — it
never removes or drops an event, it simply skips the attach loop. The event
continues down `handle()`'s normal path (the (a).1 `filter_by_profiling()`
gate below it) exactly as an anonymous visitor's event would, since it now
carries no `customer_email` for that gate to match against. Confirmed by
`test_opted_out_logged_in_user_is_forwarded_anonymous_not_dropped` (unit) and
`test_logged_in_but_opted_out_user_is_forwarded_anonymous_not_dropped`
(integration) — both assert the event count reaching the engine mock is
unchanged and the event carries no `customer_email`, not that it was
dropped.

**Conclusion: confirmed as claimed.** An opted-out contact's browse event is
forwarded, unchanged, anonymously — never dropped by this feature.

### 3. Is the email absent from every response/error path?

Read every `WP_REST_Response` constructed in `handle()`
(lines 182-287): the `not_found` 404, the `rate_limited` 429, the
`invalid_events` 400 (`field`/`message` only — both come from the pure,
input-shape `validate_batch()`, never from event content), the
`configuration_incomplete` 503, the `ApiException`-derived 502
(`error`/`message` — `$e->getMessage()` returns the ENGINE's own error text,
not anything the plugin constructs from event content), and the success 200
(`ok`/`processed`/`deduplicated`/`errors` — the engine's D6 body passed
through, which the contract's browse-ingest response never defines as
containing input echo). None of these six response shapes has a field that
could carry `customer_email`; the injected email exists only inside the
`$events` array handed to `$client->ingest_browse( $events )`, which becomes
the outbound HTTP request body — it never round-trips into anything the
proxy sends back to the browser. Directly pinned by
`test_email_never_appears_in_the_response_sent_back_to_the_browser` (unit),
which asserts the injected email string does not appear anywhere in the
serialized response.

Also checked: `DebugLog::write()` calls added/touched in this delta
(`resolve_cart_product_skus()`'s drop-count line, pre-existing;
`filter_by_profiling()`'s drop-count line, pre-existing) — neither logs an
email or any event content, only aggregate counts. No new log line was added
by `90e2712`/`34d8c3d`/`895c7da`.

**Conclusion: confirmed as claimed.** The resolved email reaches only the
outbound engine request; it is absent from every response and error path,
and from logging.

### 4. Timing/enumeration angle via the consent lookup

Two distinct questions here, kept separate:

- **Does `895c7da`'s dedup introduce a NEW enumeration angle?** No. The
  dedup only changes behavior for events whose `customer_email` equals the
  server-resolved `verified_email` (i.e., the requester's own identity,
  already proven above to require a validated cookie) — for those, one
  `may_profile()` call answers for the whole batch instead of one per event.
  This is strictly fewer lookups on a value the caller cannot themselves have
  chosen (it's server-derived), so it narrows, not widens, any timing
  surface. A client-supplied `customer_email` that DIFFERS from
  `verified_email` is still checked per-event exactly as before this delta
  (`test_differing_client_supplied_email_still_gets_checked_per_event`).
- **Is there a PRE-EXISTING enumeration angle via attacker-supplied
  `customer_email` values in the request body?** `customer_email` has been
  in `EVENT_FIELDS` (the field whitelist `validate_batch()` forwards)
  since the beacon's original 3.4.0 commit (`cb06cb4`, confirmed via
  `git log -S "'customer_email',"`), and `filter_by_profiling()`'s per-email
  `may_profile()` check has existed since the very next commit (`75bafdb`,
  the (a).1 profiling gate) — both predate PRO-1389 by many releases. An
  unauthenticated caller can already hand-craft a batch with an arbitrary
  guessed email in `customer_email` and, in principle, try to infer from the
  aggregate `processed`/`deduplicated`/`errors` counts (or from whether a
  same-email event survives to reach the engine) whether that email is
  opted out. This is real, but it is **not part of this delta** — it exists
  unchanged before and after the three commits under review, and is exactly
  as constrained today as before PRO-1389: a would-be prober still needs to
  guess a valid contact email address per attempt, gets no direct signal
  (the response is the engine's D6 body for the events actually forwarded,
  not a per-input echo), and the endpoint's existing rate limiting (60s
  fixed window, ≤120 req/IP, ≤30 req/session) throttles high-volume probing.
  Flagging this as a **pre-existing, out-of-scope Info-level observation**,
  not a new finding — worth a look in a future dedicated pass over the (a).1
  profiling gate itself, but not a regression introduced by, or blocking,
  v3.8.0.

**Conclusion: no NEW timing/enumeration surface from this delta.** The
pre-existing observation above is unchanged by, and unrelated to, the
commits under review.

### 5. Consent semantics unchanged; other gates untouched

Re-read `handle()` end-to-end (not just the diff hunks): event EXISTENCE is
still gated solely by the browser-side JS marketing-consent check (unchanged,
outside this delta's scope — no file in `public/js/` is touched by
`90e2712`/`34d8c3d`/`895c7da`); the hard gate (`is_enabled()`), rate
limiting, the size guard ordering (PRO-1446), `resolve_cart_product_skus()`,
`validate_batch()`'s field whitelist, and the `configuration_incomplete`/
`ApiException` paths are all untouched by this delta — confirmed via
`git diff bc7bcc9..04789b8 -- includes/REST/BeaconEndpoint.php` showing only
the `attach_logged_in_identity()`/`resolve_logged_in_email()` addition and
the `filter_by_profiling()` signature/dedup change.

## Test coverage confirmed

- **Unit** (`tests/Unit/REST/BeaconEndpointIdentityTest.php`, Brain\Monkey +
  a protected `resolve_logged_in_email()` seam): logged-in+consenting attach,
  anonymous no-attach, opted-out forward-unchanged, a gate-present-but-
  allowed control, email absent from the response, a multi-event batch all
  getting the same email, exactly-one consent lookup per batch, and a
  differing client-supplied email still checked per event — 8 tests, all
  the angles this audit asked about are directly pinned, not just implied.
- **Integration** (`tests/Integration/RecEngineBrowseProxyTest.php`): drives
  the REAL cookie-validation path (a genuine `logged_in`-scheme cookie value
  captured via the `set_logged_in_cookie` action, installed into `$_COOKIE`
  exactly as a browser would present it) for the logged-in, anonymous, and
  logged-in-but-opted-out cases against the real `BeaconEndpoint::handle()` —
  no doubled seam, so this is proof against the actual WP auth-cookie
  mechanism, not just the unit seam's assumption about it.

## PRO-1491 catalog commits — security angle explicitly checked, none found

Read `7d3c799`/`07e4f0c` directly (not just the commit messages): the new
`default_category_path()` method calls only `get_option( 'default_product_
cat', 0 )` and `get_term( $default_term_id, 'product_cat' )` — both read
internal WP state that any code running on the site can already read, take
no request/user input, and introduce no new SQL (both are core WP API calls
that already parameterize internally). The `on_save_product()` auto-draft
skip is a single added `post_status === 'auto-draft'` string comparison
before the existing save logic — no new input surface. Neither commit opens
a route, changes auth/capability logic, touches consent, or adds
logging/storage of new data. **No security-relevant change.**

## What this audit does NOT cover

Read-only, adversarial security pass on the three PRO-1389 identity-injection
commits (the flagged high-risk surface) plus a targeted check on the PRO-1491
catalog commits and a trivial pass on the doc-only commits — not the full
v3.8.0 release-gate checklist. `ci:strict`, the integration suite, and PCP
against the built ZIP were run separately as part of the same release prep
and are recorded in `STATUS.md` and the `INDEX.md` row for this gate, not
duplicated here.
