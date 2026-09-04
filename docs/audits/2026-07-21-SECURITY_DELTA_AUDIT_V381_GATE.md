# Security delta audit — v3.8.1 gate (PRO-1486 /relay strip + PRO-1498/1499 catalog)

- **Date:** 2026-07-21
- **Baseline:** delta `9cd41be..251a29f` (v3.8.0 release-gate tip to the
  v3.8.1 version-bump commit; 10 commits — PRO-1498 catalog tombstone
  force-fill (`5fe4811`/`296377c`/`33a2f35`/`c816d89`), PRO-1486 `/relay`
  client-supplied `customer_email` strip (`3a47eb3`), PRO-1499 contract
  v1.6.0 sync + `tags.category_defaulted` (`1303abc`/`0910b06`/`45cf1f3`/
  `8c5e5b6`), the v3.8.1 version bump (`251a29f`))
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** release boundary (cutting v3.8.1) — policy
  point 1 applies regardless of delta size. `3a47eb3` also independently
  qualifies as a named high-risk surface (the public `/relay` route). Note:
  `3a47eb3` already got a same-day REGISTER NOTE (`docs/audits/INDEX.md`,
  "PRO-1486 register note") that explicitly deferred the full re-audit "to
  the next release-gate pass" — this is that pass, so `3a47eb3` gets the
  full adversarial treatment here rather than being waved through.
- **Scope:** full delta read file-by-file. Adversarial focus on `3a47eb3`
  per the release brief's explicit questions (pure narrowing vs. a bypass
  via nested/array shapes; injection ordering unchanged). The PRO-1498/1499
  catalog commits (`5fe4811`, `296377c`, `c816d89`, `1303abc`, `0910b06`,
  `45cf1f3`) were checked for any new input surface. Pure-docs commits
  (`33a2f35`, `8c5e5b6`) got a trivial read-through.

## Verdict

**0 Critical, 0 High, 0 Medium, 0 Low, 0 Info. RESULT: clean — v3.8.1 may
proceed.**

## PRO-1486 — `/relay` customer_email strip (`3a47eb3`)

Read `includes/REST/BeaconEndpoint.php` directly, both the whitelist and the
validator that applies it.

**The whitelist copy (`validate_batch()`, lines ~520-557) is a flat,
top-level, exact-key copy — not a merge or deep sanitize:**

```php
$row = array();
foreach ( self::EVENT_FIELDS as $field ) {
    if ( array_key_exists( $field, $event ) ) {
        $row[ $field ] = $event[ $field ];
    }
}
```

`customer_email` is no longer in `EVENT_FIELDS` (confirmed: the constant's
14 remaining entries are `event_id`, `session_id`, `event_type`, `sku`,
`category_path`, `search_query`, `dwell_seconds`, `event_ts`, `source`,
`smaily_visitor_token`, `smaily_rec_id`, `smaily_ctx`, `external_id` — all
contract-scalar fields). Three ways a client could try to smuggle the value
back in, all checked and closed:

1. **Top-level key with a different case/alias** (`Customer_Email`,
   `customerEmail`) — `array_key_exists` is exact-string, case-sensitive;
   none of these match `EVENT_FIELDS` so nothing is copied under any key the
   rest of the code reads as `customer_email`.
2. **Nested under an allowed field** (e.g. `event['source'] =
   ['customer_email' => 'x']`, or `event['category_path'] = ['customer_email'
   => 'x']`) — the copy takes the field's value as-is (whatever shape it is)
   under its OWN key (`source`, `category_path`, …). Every place in this file
   that reads an email specifically reads the literal key
   `$event['customer_email']` (`attach_logged_in_identity()`'s caller,
   `filter_by_profiling()`'s now-removed per-event branch) — a value nested
   under `source` or `category_path` is never inspected for a `customer_email`
   sub-key anywhere in this class. It would ride to the engine as garbage
   inside that field's value, but it can never reach the plugin's own
   identity/consent logic, which is the property being protected here.
3. **Array-vs-scalar type confusion** on `customer_email` itself — moot,
   since the key isn't copied at all regardless of the value's shape.

**Injection ordering is unchanged and remains the sole source.** `handle()`
(lines 198-297): `size_guard()` → `resolve_cart_product_skus()` →
`validate_batch()` (strips any client `customer_email`) →
`attach_logged_in_identity()` (the ONLY place that can now set
`customer_email`, gated on `wp_validate_auth_cookie()` — unchanged since
PRO-1389, not touched by this commit) → `filter_by_profiling( $events,
$identity['verified_allowed'] )`. The diff only drops the now-dead
`$verified_email`/differing-email re-check branch from
`filter_by_profiling()` — once the client-supplied value can't exist, a
"client email differs from the server-resolved one" case is structurally
unreachable, so removing that branch removes dead code, not a security
control. The remaining check (`$has_email && ! $verified_allowed`) still
drops any event carrying a `customer_email` when the resolved identity is
opted out, exactly as before.

**Conclusion:** `3a47eb3` is a pure narrowing — it removes an accepted input
without introducing any bypass path (case, nesting, or type confusion), and
the identity-injection ordering established under PRO-1389 is unchanged. The
one open item the commit message itself flags (`smaily_rec_id`/`smaily_ctx`
remain client-suppliable) is an explicit, recorded, out-of-scope decision
(DECISIONS.md), not an oversight — noted here as a carry-forward, not a
finding.

## PRO-1498 / PRO-1499 catalog changes — new input surface check

Read `CatalogPayloadBuilder::build_unresolvable()`/`ensure_valid_removal()`,
`CatalogHookHandler::enqueue_delete_unresolvable()`,
`CatalogBackfillJob::enqueue_unavailable_unresolvable()`, and
`SkuResolver::resolve_id()` (all of `5fe4811`), plus the `tags.
category_defaulted` stamping in `0910b06` and the constant-extraction cleanup
in `c816d89`.

- **No new route, no new external HTTP, no new SQL.** These changes operate
  entirely on data already in-process: a WP post id from `before_delete_post`/
  `wp_trash_post` (internal WP hook, not request input), or a captured catalog
  object already built from a `WC_Product`. `enqueue_delete_unresolvable()`
  gates on `get_post_type( $post_id ) === 'product'|'product_variation'`
  (an internal DB lookup, not attacker-influenced) before doing anything.
- **The synthetic fallback URL is safe.** `fallback_product_url()` builds
  `home_url( '/?smaily_connect_removed_product=' . $product_id )` from an
  `int` (`(int) $product_id` throughout the call chain) — no string
  concatenation of untrusted data, no injectable value.
- **`tags.category_defaulted`** is a hardcoded `'true'` string stamped by
  the plugin's own logic when it substitutes a placeholder value; it carries
  no external input at all.
- **Conclusion:** confirmed as claimed in the release brief — these commits
  add no new input surface. They are a reliability fix (never silently skip
  a tombstone) plus a wire-shape/contract-sync addition, not a
  security-relevant change.

## Gates run for this pass

- `npm run ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit unit
  595/595, vitest 248/248, tsc/eslint clean).
- `sg docker -c "composer run test:integration"` OK (163 tests, 826
  assertions), sandbox tenant "Smaily Connect test" correctly restored
  post-run (not MiuMjau).
- PCP against the built v3.8.1 ZIP (unzipped to `smaily-connect-pkg`, never
  the mounted `smaily-connect` dir; `--slug=smaily-connect`): exactly the one
  expected intentional finding, `plugin_updater_detected` (F3-35). No
  `upgrade_notice_limit` warning (the 3.8.1 upgrade notice is 267 chars,
  under PCP's 300-char limit).

**Publication (GH release + tag) deferred to the orchestrator in this
session; not performed by this pass.**
