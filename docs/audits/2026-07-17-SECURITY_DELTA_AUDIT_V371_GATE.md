# Security delta audit — v3.7.1 gate (PRO-1390 `/relay` cart-sku fix)

- **Date:** 2026-07-17
- **Baseline:** delta `a2326df..HEAD` (HEAD `a0a86ac`; 4 commits, 13 files,
  +424/-29 lines)
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** small delta by line count, but it changes the
  **public, unauthenticated `/relay` route** (`BeaconEndpoint`) — an explicitly
  named high-risk surface in the re-audit policy — so a focused security pass
  is required regardless of size.
- **Scope:** full delta read file-by-file. Security focus on the `/relay`
  change (`674c04c`, PRO-1390): input validation of the new `product_id`
  field (type coercion, injection, unbounded values, DoS via huge batches /
  repeated `wc_get_product` lookups on the unauthenticated route), internal-
  field stripping before the engine wire, logging (PII/secrets), abuse-filter
  interaction. Quality pass (trivial) on the remaining three commits
  (STATUS-only live-walk record, one-line `uninstall.php` dead-entry removal,
  an audit-docs wording correction).

## Disposition (PRO-1446, 2026-07-17)

**Finding 1 (HIGH) — ✅ FIXED, `5ee1366`.** `BeaconEndpoint::handle()` now runs
a cheap, pure `size_guard()` (the non-empty / `MAX_EVENTS=100` check,
extracted out of `validate_batch()`, which now delegates to it) on the raw
`events` array *before* `resolve_cart_product_skus()` runs — so the
`wc_get_product()` loop can never execute over more than 100 events. A
genuinely oversized batch gets the exact same `400 invalid_events` /
`"Batch exceeds the 100-event cap."` response it always did; only the
ordering (before vs. after resolution) changed. Resolution still runs before
`validate_batch()`'s field whitelist strips the proxy-internal `product_id`,
so the `woo-<id>` resolution behavior for `cart_add`/`cart_remove` on a
valid (≤100-event) batch is unchanged — confirmed by the existing
PRO-1390 unit/integration tests staying green. New regression test
`RecEngineBrowseProxyTest::test_oversized_batch_is_rejected_before_any_product_lookup`
sends a 101-event `cart_add` batch and asserts, via a `woocommerce_product_class`
filter counter, that `wc_get_product()` is called zero times and the batch is
rejected with a 400 before the engine is ever contacted. `ci:strict` and
`sg docker -c "composer run test:integration"` both green post-fix
(157/157 integration tests, sandbox tenant "Smaily Connect test" restored
correctly, not MiuMjau). **v3.7.1 may now proceed** once the rest of the
release-gate checklist is satisfied.

**Independent re-verification (adversarial, 2026-07-17, Claude/Fable 5).**
Read `handle()`/`resolve_cart_product_skus()`/`size_guard()`/`validate_batch()`
directly (not just the diff) and confirmed: (1) `resolve_cart_product_skus()`
has exactly one caller (`handle()`), reached only after `size_guard()` returns
null — no other route/preprocessing path skips the gate, and non-array/missing
`events` degrades to `array()` which `size_guard()` itself rejects as empty,
never entering the resolve loop; (2) the ≤100 `wc_get_product()`/`SkuResolver::
resolve()` calls left under the cap are bounded, single-lookup-per-event, and
of the same order the route already tolerated pre-PRO-1390 — no remaining
disproportionate cost found; (3) mentally reverting `5ee1366`'s ordering, the
new regression test's `assertSame( 0, $lookups, … )` would fail (the
`woocommerce_product_class` filter fires on the very first `wc_get_product()`
call), so the test genuinely pins the property, not just happy-path shape; (4)
`size_guard()`'s return shape is byte-identical to the pre-existing
`invalid()`/`invalid_response()` contract (same `field`/`message` for the
100-cap case) — no JS-facing error-shape drift, and `validate_batch()`
delegating to `size_guard()` (rather than duplicating the check) rules out
drift between the two call sites. Re-ran `sg docker -c "bash
bin/run-integration-tests.sh --filter RecEngineBrowseProxyTest"` (16/16 green,
sandbox tenant correctly restored) and the unit subset (`BeaconEndpointTest`,
10/10 green). No bypass or new issue found — Finding 1 holds fixed.

## Verdict

**1 HIGH — release-blocking. RESULT: escalate.**

Findings: **1 High, 0 Medium, 0 Low, 0 Info.** The High finding is a genuine
regression introduced by `674c04c`: the new server-side SKU-resolution step
runs `wc_get_product()` (a DB-backed lookup) over the *entire* raw event
array **before** the batch-size cap (`MAX_EVENTS = 100`) is enforced, on the
one route in the plugin that is public and unauthenticated by design. A
single crafted POST with a large `events` array (no auth, no rate-limit
bypass needed — one request is enough) forces the server to do one DB lookup
per event before rejecting the batch as oversized. This was not present
before PRO-1390 (the old code validated size/shape first, with zero WP/DB
calls, before doing any per-event work) — it is a new DoS surface on exactly
the surface CLAUDE.md flags as needing the most caution ("an unprotected
`/relay` is a real attack surface").

Recommend: **do not tag v3.7.1** until this is fixed (or the release is
re-scoped to exclude/patch `674c04c`) and the fix is verified. This is a
finding, not a fix — no code was changed by this audit.

## What the delta is

Four commits since the v3.7.0 tag:

1. **`674c04c` — PRO-1390, the security-relevant commit.** Browse
   `cart_add`/`cart_remove` events previously keyed on WooCommerce's raw
   `data-product_sku` DOM attribute (the merchant SKU field — optional,
   reused, or garbage per `SkuResolver`'s own docblock). The JS
   (`beacon-core.ts`) now reads `data-product_id` (WC's own add-to-cart.js
   won't fire without one) and sends it as a new proxy-internal
   `product_id` field on the wire event (`rec-engine-client.ts`). Server-side,
   `BeaconEndpoint::resolve_cart_product_skus()` (new private method) loads
   the product via `wc_get_product()` and resolves the canonical
   `woo-<id>` key through the same `Support\SkuResolver` catalog/orders use,
   writing it into `event['sku']`; an unresolvable id drops `sku` rather than
   forwarding a guess (logged once per batch, not per event). `product_id` is
   not in `EVENT_FIELDS`, so `validate_batch()`'s whitelist strips it before
   the request reaches the engine.
2. **`d091e72`** — STATUS.md-only: records a live-walk against the real
   sandbox engine confirming `cart_add` resolves to `sku=woo-4334` (not the
   merchant SKU) on a single-event batch. No code change.
3. **`bd72c58`** — PRO-1342: removes one bogus entry
   (`'smaily_connect_abandoned_carts'`) from `uninstall.php`'s
   `$legacy_options` array — it was the legacy cart **table-suffix** string,
   not an option name, so `delete_option()` on it was a harmless no-op. One
   line removed, matches the commit message and the referenced audit finding
   exactly (verified: no code anywhere writes an option by that name — grep
   confirmed clean before removal).
4. **`a0a86ac`** — docs-only: corrects an overstated closure claim in the
   2026-07-16 delta audit report + `INDEX.md` row (Info finding #2 was
   closed by that delta, not finding #1 — #1 was `bd72c58`, commit `3`
   above). Wording fix, no code, no security relevance.

## Security — the `/relay` surface, in detail

### Finding 1 (HIGH): unbounded pre-validation `wc_get_product()` loop on the public route

**Where:** `includes/REST/BeaconEndpoint.php`, `handle()` (line 205) and
`resolve_cart_product_skus()` (lines 341–376).

**The code path:**

```php
$raw = $request->get_param( 'events' );
if ( ! is_array( $raw ) ) { $raw = array(); }
$raw = $this->resolve_cart_product_skus( $raw );   // <-- runs over ALL of $raw
$validation = self::validate_batch( $raw );        // <-- MAX_EVENTS=100 cap enforced HERE
```

`resolve_cart_product_skus()` iterates every element of `$raw` and, for each
`cart_add`/`cart_remove` event carrying a `product_id`, calls
`wc_get_product( $product_id )` (a post/meta lookup — a real DB round-trip
on a cold cache) followed by `SkuResolver::resolve()`, which does a further
multilingual canonical-id lookup on translated stores. **This runs before
`validate_batch()`'s `count( $events ) > self::MAX_EVENTS` check.** Before
PRO-1390, the very first thing `handle()` did with the parsed body was
`validate_batch()` — pure PHP, zero WP/DB calls, reject-fast on size/shape.
PRO-1390 inserted an expensive, WP/DB-backed operation ahead of that
reject-fast gate.

**Why this is exploitable and cheap for an attacker:**

- The route is `permission_callback => '__return_true'` — no auth, by
  design (browse events come from anonymous visitors). It's gated only on
  `is_enabled()` (engine connected + browse-tracking on) — true for any
  merchant who has turned browse tracking on, which is the intended,
  supported configuration this route exists to serve.
- `events` is parsed straight from the JSON request body
  (`WP_REST_Request::get_param`); PHP's `json_decode` has no element-count
  limit (only a nesting-depth limit, default 512, irrelevant to a flat
  array). The only ceiling is the PHP `post_max_size`/body-size limit
  (commonly 8 MB default, often raised by hosts) — comfortably enough for
  tens of thousands of minimal events (`{"event_type":"cart_add",
  "product_id":"1","event_id":"x"}` is well under 100 bytes).
- The existing rate limiter (`rate_limited()`, IP + session, fixed 60s
  window) runs **before** the parsing/resolve step and only throttles
  *request count*, not *events per request*. It does not mitigate this: the
  **first** request from a fresh IP/session already pays the full cost —
  no rate-limit bypass is needed to do damage once.
- Each triggering event costs at least one `wc_get_product()` call (DB
  query on a cold object cache) plus a canonical-id lookup in
  `SkuResolver::resolve()`. A single POST with, say, 20–50k well-formed
  `cart_add` events (well within an 8 MB body) forces that many synchronous
  DB-backed lookups in one PHP request before the 100-event cap ever
  rejects it — a realistic path to a PHP execution-time-limit hit / DB load
  spike / slow-loris-style resource exhaustion from ONE unauthenticated
  request, repeatable at the rate-limit's own ceiling (up to 120 req/60s per
  IP) for sustained amplification.
- No data is exposed and there's no injection — this is a pure availability
  (DoS/resource-exhaustion) finding, not a confidentiality/integrity one.
  Severity is High rather than Critical because impact is availability-only,
  requires the (common, supported) browse-tracking-enabled configuration,
  and a request that ultimately gets rejected leaves no lasting state
  change — but the ease of triggering it (one unauthenticated `curl`, no
  rate-limit evasion needed) and the direct site-availability impact place
  it above Medium.

**Suggested direction for a fix (not applied — audit only):** enforce the
size/shape cap (`count( $raw ) > self::MAX_EVENTS`, and ideally the
non-empty/array-of-arrays shape check) *before* `resolve_cart_product_skus()`
runs — e.g. reorder so `validate_batch()`'s cheap checks gate the batch
first, and do the SKU resolution only on the already-size-bounded, already-
shape-checked set of ≤100 events. (`resolve_cart_product_skus()` would need
to either run after a first light validation pass, or `validate_batch()`
would need to preserve `product_id` through the whitelist so resolution can
happen on its `clean` output instead of raw `$raw`.) Either way, the
per-event DB cost must never run on an attacker-controlled unbounded array.

### Other `/relay`-surface checks (clean)

- **Type coercion:** `$product_id = (int) $event['product_id'];` — WP/JS
  send a numeric string; a hostile array/bool/object here casts to `0`/`1`
  via ordinary PHP scalar-cast rules (a PHP `E_WARNING` on an array cast,
  suppressed in production), never a fatal, and `$product_id > 0` guards
  the `wc_get_product()` call from `0`. No type-confusion or crash path.
- **Injection:** `product_id` is cast to `int` before ever touching
  `wc_get_product()` (which itself does parameterized/cached post lookups,
  not raw SQL built from the request) — no SQL/command-injection surface.
- **Internal-field stripping confirmed:** `product_id` is deliberately
  absent from `EVENT_FIELDS` (the `/relay` whitelist), so
  `validate_batch()` drops it unconditionally — verified directly in the
  whitelist array (`includes/REST/BeaconEndpoint.php:114-129`) and pinned
  by the new unit test `test_product_id_is_dropped_by_the_whitelist`
  (`tests/Unit/REST/BeaconEndpointTest.php`) and the integration assertion
  that the mock engine never receives it
  (`tests/Integration/RecEngineBrowseProxyTest.php`,
  `test_cart_add_with_unresolvable_product_id_drops_sku_instead_of_guessing`
  and siblings — read the assertions directly, not just the test names).
- **Logging:** the only new log line
  (`'[smaily-connect beacon] dropped sku on %d cart event(s)…'`) carries an
  integer count only — no product id, no email, no session/IP, no secret.
  Matches the existing profiling-drop log's one-line-per-batch convention
  (never per-event, so a heavy browser can't flood the log either way).
- **Abuse-filter interaction (aside from Finding 1):** the 9-type
  `event_type` allowlist, the `event_id` non-empty check, and the 100-event
  cap are otherwise unchanged and still correctly enforced — for a batch
  ≤100 events, the added `wc_get_product()` cost (≤100 lookups/request,
  ≤120 requests/60s/IP under the default filter) is within the same rough
  order of magnitude the route already tolerated pre-PRO-1390 for its
  single synchronous `Client::ingest_browse()` HTTP call per batch; it is
  specifically the **pre-cap unboundedness** that is the finding, not the
  per-event cost in isolation.
- **`resolve_cart_product_skus()` scope is correctly narrow:** it only acts
  on `event_type === 'cart_add' || 'cart_remove'` with an `isset(
  product_id )` guard; `product_view` (already resolved server-side by
  `StorefrontBeacon::page_context()`, PRO-1224) and every other event type
  pass through untouched — confirmed by
  `test_product_view_still_takes_an_explicit_sku_unaffected_by_cart_resolution`.
- **A hand-crafted request can still forge an arbitrary `sku` directly**
  (send `sku` with no `product_id` — `resolve_cart_product_skus()` then
  `continue`s and the whitelist passes the caller's raw `sku` through
  unchanged). This is **pre-existing behavior**, not a PRO-1390 regression —
  the route has never server-side-validated the *content* of a client-
  supplied `sku`, matching the documented abuse model ("our own JS client
  never produces an invalid type… a violation signals tampering"). Noted for
  completeness, not counted as a new finding.

## Quality pass (trivial commits — no findings)

- **`d091e72`** (STATUS-only live-walk record): matches its own claim —
  confirms `sku=woo-4334` on the real sandbox for a single-event `cart_add`
  batch. Note: the live-walk exercises the happy path only (one event); it
  does not and cannot exercise Finding 1 (an oversized batch would be
  deliberately hostile input, not something a verification walk sends).
- **`bd72c58`** (PRO-1342 `uninstall.php` one-liner): the removed string
  (`'smaily_connect_abandoned_carts'`) is confirmed to be the legacy cart
  table-suffix constant
  (`integrations/woocommerce/cart.class.php::ABANDONED_CART_TABLE_NAME`),
  not an option name; grep confirms no code ever `add_option`/`update_
  option`s that literal key. Removal is a correct no-op cleanup; the real
  abandoned-cart options (`…_cutoff`/`…_status`) remain listed. No
  functional or security change.
- **`a0a86ac`** (docs correction): wording-only fix to a prior audit report
  + the `INDEX.md` row, correctly attributing which commit closed which
  Info finding. No code touched.

## What this audit does NOT cover

Read-only security delta pass, not the full v3.7.1 release gate: no
`ci:strict`/integration run was executed in this pass (the PRO-1390 commit's
own STATUS.md entry already records green gates + the sandbox live-walk for
its happy path); no PCP-against-the-built-ZIP run. Those remain separate
release-gate steps — and per this audit's verdict, **the v3.7.1 tag itself
should not proceed** until Finding 1 is fixed and re-verified (a regression
test asserting the resolve step never runs — or runs bounded — ahead of the
size cap would close it).
