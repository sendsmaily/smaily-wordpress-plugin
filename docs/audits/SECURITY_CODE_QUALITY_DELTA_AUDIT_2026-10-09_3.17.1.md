# Security + code-quality delta audit — 3.17.1 pre-release gate (3.17.0..feb168b)

Follows [`SECURITY_DELTA_AUDIT_2026-10-08_3.17.0.md`](SECURITY_DELTA_AUDIT_2026-10-08_3.17.0.md)
and [`CODE_QUALITY_DELTA_AUDIT_2026-10-08_3.17.0.md`](CODE_QUALITY_DELTA_AUDIT_2026-10-08_3.17.0.md),
which covered everything up to the 3.17.0 tag. This report covers what landed
after the tag. The earlier reports stay valid for what they covered.

- **Date:** 2026-10-09
- **Baseline:** delta `3.17.0..feb168b` (tag `3.17.0` = `400de4f` → `origin/main`
  `feb168b`): **7 commits; 18 files outside `docs/` and `tests/`, +1 692 /
  −1 017** (most of it is lockfiles). The shipped plugin code is **6 files,
  +377 / −87**, all in `includes/`. `bin/` is dev-only and is excluded from the
  ZIP (`.zipignore`: `/bin*`). The work in it:
  - **#215 PRO-3435** — CI runs the integration suite on legacy and HPOS order
    storage; the suite bootstrap prints the storage and refuses a mismatch;
    `bin/run-integration-tests.sh` passes `SMAILY_CONNECT_TEST_ORDER_STORAGE`.
  - **#216 PRO-3909 + PRO-3997** — new `includes/Privacy/AddressMatch.php`
    (`column_equals()`, `json_string()`) shared by the Smaily queue, the
    Campaign Intelligence queue, the order lookup and the cart tracker; the
    personal-data exporter and eraser page the orders 10 per page and do the
    one-off work on page 1.
  - **#217 PRO-3989** — `CatalogManifest` adds a run-scoped `shutdown` hook
    that marks a run PHP stopped as failed; new dev script
    `bin/measure-manifest-walk.php`.
  - **#218 PRO-3996** — `CartSessionStore::delete_by_email()` and
    `delete_other_rows_for_email()` use `AddressMatch::column_equals()`.
  - **#219 PRO-3858** — dev-dependency lockfile updates and npm `overrides`
    (root and `blocks/`), dev Composer packages.
  - **#214, #220** — docs only (STATUS, DECISIONS incl. PRO-4001: 3.17.0 stays
    GitHub-only, wordpress.org gets 3.17.1).
- **Auditor:** Claude (Opus 5.5), read-only.
- **Trigger (re-audit policy):** point 1 (the 3.17.1 release boundary — the
  next wordpress.org version, PRO-4001) and point 2 (**GDPR**: the eraser /
  exporter; **SQL against custom tables**: the shared address match, the cart
  tracker deletes; **what gets stored**: the manifest failure reason).
- **Scope:** file-by-file read of the shipped delta (`AddressMatch`,
  `GdprHandler`, `EventQueue`, `IngestQueue`, `CartSessionStore`,
  `CatalogManifest`) plus the call chains it reaches: `CartHookHandler`
  (`on_cart_updated()`, `clear_for_order()`), migration
  `009-create-smly-plus-cart-session.sql` (indexes), `CartAbandonmentSweeper`
  (row lifetime), `IngestQueue::mark_failed()` / `store_exchange()`,
  `OrderBackfillJob::table_spec()`. WooCommerce's
  `includes/class-wc-cart-session.php` and `class-wc-checkout.php` read from the
  local wp-env copy (when `woocommerce_cart_updated` fires; no transaction
  around `woocommerce_checkout_order_processed`). The CI workflow diff, the
  `bin/` changes, `package.json` / `blocks/package.json` overrides, a
  version-by-version diff of all three lockfiles (prod vs dev), the `.pot` /
  `-et.po` diff, the docs-site diff (EN/ET pair), `readme.txt` (changelog size).
  Tests were read to see what is pinned (`GdprHandlerTest`, `AddressMatchTest`,
  `RecEngineGdprTest`, `CartPipelineTest`, `CatalogManifestTest`). DECISIONS
  PRO-3435, PRO-3909, PRO-3997, PRO-3989, PRO-3996, PRO-3858, PRO-4001 read
  against the code. No real customer data was used.
- **Method:** static read. Static tools run read-only on the 7 changed PHP
  files: `vendor/bin/phpcs -n` (exit 0), `vendor/bin/phpcs` with warnings
  (2 new warnings, finding L1), `vendor/bin/phpstan analyse` (`[OK] No
  errors`). CI on `main` checked with `gh run view`: run 37889846035 on
  `7372d6c` is green on every job, both integration legs (legacy, HPOS)
  included. Not run: unit / integration suites locally, PCP (needs the
  CI-built ZIP), a block build, any live walk. Nothing in the repo was
  changed.

## Verdict

**0 Critical, 0 High, 1 Medium, 2 Low, 9 Info.**

**RESULT: 3.17.1 may proceed once M1 is fixed or explicitly accepted.**

The GDPR work is correct. The shared `AddressMatch` is a byte-for-byte
extraction of the conditions the three call sites had in 3.17.0 (verified
condition by condition), so the queue erasers neither over- nor under-match
differently from the audited 3.17.0 code. The eraser paging does the engine
§9 delete, the queue erasures, the cart and refused-list erasure and the
"remove it in Smaily too" message once, on page 1; the waiting-update drop
takes every order id before the engine call; the exporter adds no data of
another person. Every new SQL statement goes through `$wpdb->prepare()`, and
the only interpolations are column names and table names from code. The
manifest's shutdown hook is scoped to the run, removed in `finally`, cannot
fire in another request, and stores only the stop kind and
`basename(file):line` — never the error message (PRO-3890). #219 changes no
runtime dependency: the only production Composer package is still
`woocommerce/action-scheduler` 3.9.3, and every changed npm package is a dev
package.

The one Medium is a performance regression in #218: wrapping the cart
tracker's `email` column in `CAST( LOWER() … )` makes `idx_email` unusable, and
one of the two deletes runs on the storefront's cart path, not only at login.

---

## Findings

| # | Severity | Where | Summary |
|---|---|---|---|
| M1 | Medium (magnitude unmeasured) | `includes/Smaily/CartSessionStore.php:191`, `:211`; callers `includes/Integrations/WooCommerce/CartHookHandler.php:119`, `:235`; `migrations/009-create-smly-plus-cart-session.sql` (`KEY idx_email`) | The cart tracker's address deletes can no longer use `idx_email`: every tracked cart update of a shopper with a known address now scans and locks the whole tracker table |
| L1 | Low | `includes/Smaily/CartSessionStore.php:191`, `:210` | Two new PHPCS warnings (`PreparedSQLPlaceholders.UnfinishedPrepare`, `…ReplacementsWrongNumber`) — likely two new Plugin Check warnings |
| L2 | Low | `includes/Privacy/GdprHandler.php:238`–`:250`, `:681`–`:700` | Offset paging over a re-read id list: an order permanently deleted between two eraser pages shifts the list, and the order at the page boundary keeps its markers |
| I1 | Info | `includes/Privacy/AddressMatch.php`; `EventQueue.php` `privacy_request_where()`; `IngestQueue.php` `delete_for_privacy_request()`; `CartSessionStore.php:343`–`:357`; `GdprHandler.php:713`–`:718` | The shared match is equivalent to the 3.17.0 conditions; LIKE escaping, `\uXXXX` / `\/` forms, binary compare and the closing quote verified |
| I2 | Info | `AddressMatch::column_equals()`; `GdprHandler::order_ids_for()` (`:647`) | `column_equals()` has no empty-address guard (`json_string()` has one); `order_ids_for()` relies on WordPress having validated the address |
| I3 | Info | `GdprHandler.php:204`–`:226`, `:238`–`:287`, `:159`–`:166` | Paging: one-off work once on page 1, every order id dropped before the engine call, `done` right, `after_erasure()` re-drops; the exporter leaks nothing new |
| I4 | Info | `GdprHandler.php:647`–`:657`, `:713`–`:718` | The order id query runs once per page (and again in `after_erasure()`), and cannot use an index; legacy SQL has no `DISTINCT` / `post_type` filter (pre-existing) |
| I5 | Info | `includes/Smaily/RecEngine/CatalogManifest.php:140`–`:156`, `:167`–`:244`, `:299`–`:303` | Shutdown hook: scoped and removed; stores kind + `basename:line` only; real PHP time / memory fatal not exercised (unit seam only) |
| I6 | Info | `composer.lock`, `package-lock.json`, `blocks/package-lock.json`, `package.json`, `blocks/package.json` | #219: no production dependency changed; block bundles get webpack 5.111.1 / terser 5.51.2 / Babel 7.29; four forced majors are build-time only |
| I7 | Info | `.github/workflows/lint_and_test.yml`; `bin/run-integration-tests.sh`; `tests/Integration/bootstrap.php` | CI matrix: constant values only, no new secret, trigger or permission; both legs green |
| I8 | Info | `bin/measure-manifest-walk.php` | Dev-only, excluded from the ZIP; seeds and deletes `product` posts by slug prefix with raw SQL — run it only on `tests-cli` |
| I9 | Info | `languages/*.pot`, `*-et.po`; `docs/site/index.html`; `docs/DECISIONS.md` PRO-3996; `readme.txt` | Two new strings translated; docs EN+ET added as a pair (ET proofread pending); PRO-3996 wording; changelog budget |

---

## M1 — MEDIUM — the cart tracker's address deletes lose their index, on the storefront path

**Where:** `includes/Smaily/CartSessionStore.php:191` (`delete_by_email()`) and
`:211` (`delete_other_rows_for_email()`):

```php
"DELETE FROM {$this->table_name()} WHERE " . AddressMatch::column_equals( 'email' ) . ' AND cart_token != %s'
// = … WHERE CAST( LOWER( email ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY ) AND cart_token != %s
```

Before #218 these were `email = %s` (and `$wpdb->delete( … array( 'email' => … ) )`),
which use `KEY idx_email (email)` from migration 009. A function on the column
makes the index unusable in MySQL and MariaDB, so both statements now read
every row of `{prefix}smly_plus_cart_session`.

**How often.** `delete_other_rows_for_email()` is not a login-only call.
`CartHookHandler::on_cart_updated()` (`:64`–`:120`) calls it after every
tracked upsert whenever the current identity has an address (`:118`–`:120`) —
a logged-in shopper, or a guest whose billing address WooCommerce has on the
session. `woocommerce_cart_updated` fires from `WC_Cart_Session::set_session()`,
which runs on `woocommerce_after_calculate_totals` (WooCommerce
`class-wc-cart-session.php:76`, `:436`): every add / remove / quantity change,
the cart and checkout pages, and every checkout `update_order_review`
refresh. The per-request guard limits it to once per request, not once per
cart. `delete_by_email()` runs on `woocommerce_checkout_order_processed` and
again on every `woocommerce_thankyou` view (`Bootstrap.php:520`, `:522`).

**Cost.** The table holds a row per WooCommerce cart session — guests
included — for up to the reminder window (`CartAbandonmentSweeper` expires
rows after `smaily_connect_…max_age`, default one day, plus reminded rows until
pruned). Under InnoDB's default REPEATABLE READ, a `DELETE` that has to scan
the whole table sets next-key locks on every row it scans ("every row of the
table becomes locked, which in turn blocks all inserts by other users to the
table" — MySQL reference manual, Locks Set by Different SQL Statements). Each
shopper's tracker write (`SELECT … WHERE cart_token` then `insert` / `update`)
therefore waits for any other shopper's scan in progress. On a store with
tens of thousands of cart sessions a day, each checkout field change does a
full scan of that table and serialises cart writes for its duration. This is
latency and lock waits, not data loss; the size is unmeasured.

**Not a correctness defect.** The match itself is right: letter case does not
matter, an accent does, and the integration tests
(`CartPipelineTest::test_an_order_clears_the_buyers_carts_but_not_an_accented_address`,
`…_a_login_clears_the_guest_cart_but_not_an_accented_address`) pin it. No
over-delete is possible: the binary-lowered match is a subset of the old
collation match.

**Recommendation (small, before the cut):** keep the index as a prefilter and
the binary match as the filter:

```php
"DELETE FROM {table} WHERE email = %s AND " . AddressMatch::column_equals( 'email' ) . ' AND cart_token != %s',
$email, $email, $keep_token
```

Under the case- and accent-insensitive collations WordPress tables use,
`email = %s` returns a superset of the binary-lowered match, so the result is
unchanged and the scan is limited to the `idx_email` range (one shopper's
rows). Caveat: on a table with a case-sensitive collation (`utf8mb4_bin`) the
prefilter would also make the match case-sensitive; if that has to be covered,
add it as a helper option (`AddressMatch::column_equals_indexed()`) that the
two hot-path deletes use, and keep `privacy_request_where()` (an admin
one-off) as it is. The same prefilter could serve the HPOS order lookup
(I4). Also correct DECISIONS PRO-3996: it says "when a shopper logs in, the
cart update calls `delete_other_rows_for_email()`" — the call runs on every
tracked cart update with an address.

## L1 — LOW — two new PHPCS warnings, likely new Plugin Check warnings

`vendor/bin/phpcs includes/Smaily/CartSessionStore.php` (warnings on) reports
`WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` at `:191` and
"Incorrect number of replacements passed to `$wpdb->prepare()`" at `:210`.
The same file at the `3.17.0` tag reports none. Both are false positives: the
sniff cannot see the `%s` inside `AddressMatch::column_equals()`. The 3.17.0
code-quality audit's L1 was the same class at `IngestQueue.php`; it was
silenced with a `phpcs:disable` / `enable` pair before the PCP run.
`IngestQueue` and `EventQueue` keep their existing suppressions through the
refactor, and `GdprHandler::order_ids_sql()` builds the string in a separate
method, so the sniff does not fire there.

**Recommendation:** add `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare`
and `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` to a
`phpcs:disable` / `phpcs:enable` pair around the two statements (or to the
file-level disable at the top of `CartSessionStore.php`), with the reason
"the placeholder is in AddressMatch::column_equals()". Watch for them in the
PCP run on the CI-built ZIP.

## L2 — LOW — offset paging skips the boundary order when an order is deleted between pages

**Where:** `GdprHandler::erase()` (`:238`–`:250`) and `export()` (`:204`–`:226`)
re-read the whole id list on every page (`order_ids_for()`) and take
`array_slice( $ids, ( $page - 1 ) * 10, 10 )` (`orders_on_page()`, `:681`).

**What holds.** The eraser does not change what the list is built from (it
deletes `_smaily_*` meta, never `_billing_email` / `billing_email`), WordPress
runs one eraser's pages before the next eraser starts, and a new order gets a
higher id and joins the last page. Trash and status changes keep the order
in the list (no status filter). So in the normal case no order is skipped or
repeated.

**Failure scenario.** Ids `1…12`. Page 1 erases the markers of `1…10`. Before
page 2, order `3` is deleted permanently (by an admin, a cleanup plugin, or
WooCommerce's scheduled trash purge). Page 2 reads `1, 2, 4…12` and slices
from position 10: `12` only. Order `11` keeps `_smaily_rec_id`,
`_smaily_rec_ctx`, `_smaily_anon_session_id`, `_smaily_visitor_token` and the
newsletter consent marker, and the eraser reports `done`. The exporter has
the mirror effect (one order missing from the export). A permanent delete
inside the seconds between two AJAX pages is rare; the effect is an
incomplete erasure of one order's markers, not exposure of another person's
data.

**Recommendation (backlog):** the erase is idempotent, so a page can start
one page early and absorb a shift of up to 10: page `p` erases
`array_slice( $ids, max( 0, ( $p - 2 ) * 10 ), 20 )` (the exporter must not
overlap — WordPress merges its items). Alternatively, accept and note it in
DATA_MODEL_GDPR next to the paging paragraph. Not a 3.17.1 blocker.

## I1 — INFO — the shared address match is equivalent to the 3.17.0 conditions

Checked condition by condition against the 3.17.0 code:

- `EventQueue::privacy_request_where()`: `json_string( 'LOWER( payload )', …,
  [ '"email":', '"to":' ] )` produces the same four patterns
  (`'%' . esc_like( key . form ) . '%'`, forms `"addr"` and
  `wp_json_encode( addr )`, deduplicated) in the same order and the same
  `LOWER( payload ) LIKE CAST( %s AS BINARY )` OR-list; the helper returns it
  parenthesised, so `contact_key IS NULL AND ( … )` keeps its precedence. The
  argument order (`contact_key`, then patterns) is unchanged.
- `IngestQueue::delete_for_privacy_request()`: `json_string( 'sent_payload',
  $email )` with the default `[ '' ]` prefix gives the same two patterns;
  `WHERE ( A OR B )` equals the old `WHERE A OR B`.
- `CartSessionStore::privacy_request_where()` and `GdprHandler::order_ids_sql()`:
  the string `column_equals()` returns is identical to the literal it
  replaces (`CAST( LOWER( col ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY )`).
  `order_ids_sql()` still builds table and id-column names from
  `OrderBackfillJob::table_spec()` and `$wpdb->prefix` only.
- LIKE metacharacters (`%`, `_`, `\`) go through `$wpdb->esc_like()` before
  `prepare()` (`AddressMatchTest::test_like_wildcards_in_the_address_are_escaped`);
  the closing quote keeps `jane@…x` and `xjane@…` out; the binary comparison
  keeps `jäne@` ≠ `jane@`.
- Carried over from 3.17.0 security I2, unchanged: `json_string()` lowercases
  with PHP `strtolower()` (ASCII only) while `column_equals()` lowers in the
  database (Unicode), so an address that differs only in the case of a
  non-ASCII letter is matched by the order and cart lookups but not by the
  queue text match. Rare; recorded, not new.

## I2 — INFO — `column_equals()` has no empty-address guard

`json_string()` returns `null` for an empty or blank address; `column_equals()`
returns SQL that, with `''`, matches every row whose column is empty. The cart
callers guard `$email === ''` (`CartSessionStore.php:187`, `:206`,
`privacy_request_where()`), but `GdprHandler::order_ids_for()` (`:647`) does
not: with `''` it would return every order with an empty billing address
(manual / POS orders), and the eraser would strip their markers and drop
their waiting updates. Not reachable today — WordPress validates the request
address with `is_email()` before it calls an exporter or eraser, and
`after_erasure()` reads it from the stored request — and the same was true in
3.17.0 (`orders_for()`). Suggested hardening: `if ( trim( $email ) === '' )
return array();` in `order_ids_for()`, mirroring `json_string()`.

## I3 — INFO — eraser / exporter paging verified

- **Once, on page 1:** `drop_waiting_updates( $email, $order_ids )` with
  **every** order id (ids only, nothing loaded) runs **before**
  `erase_engine()` (`:254`–`:255`); then the engine §9 delete, the identity
  marker, the cart sessions, `IngestQueue::delete_for_privacy_request()`,
  the refused-contact list, the Smaily queue erasure and its messages, and
  the "remove the contact in Smaily too" message (`:279`). Pages > 1 return
  early (`:243`–`:249`) with only `erase_order_meta()` for their slice and an
  empty `messages`. `GdprHandlerTest::test_the_eraser_takes_ten_orders_a_page_and_does_the_rest_once_on_page_one`
  pins every "once" (`unsent_calls`, `erase_calls`, `delete_calls`) and the
  message; `RecEngineGdprTest::test_the_eraser_works_through_many_orders_page_by_page`
  runs it against real orders.
- **`done`:** `count( $ids ) <= $page * 10` — page 1 with no orders is done;
  `max( 1, $page )` guards 0 / negative.
- **Orders added during the request:** a new order (higher id) lands on the
  last page and loses its markers; its waiting `order.upsert` was not in the
  page-1 drop, but `after_erasure()` (`:159`–`:166`) runs the drop again with
  a fresh id list once every eraser has finished.
- **A failed page:** WordPress stops; a re-run starts again at page 1, and the
  engine delete is idempotent (a 404 counts as success).
- **Exporter:** page 1 adds the engine record (one engine call), the identity
  marker, cart sessions and Smaily queue rows (metadata only, no payload);
  every page adds only its slice's order markers. The orders come from the
  binary-lowered match and the WP user from `user_for()`'s exact check, so no
  other person's orders or account are exported. No new leak.

## I4 — INFO — the order id query runs per page and cannot use an index

`order_ids_for()` runs on every eraser page, every exporter page and in
`after_erasure()`. The binary-lowered match cannot use `wc_orders.billing_email`'s
index (HPOS) and the legacy query reads every `_billing_email` meta row
(`meta_value` is not indexed). A customer with 100 orders costs 10 + 10 + 1
full reads of the order address column for one export plus one erasure —
each in its own request, so the per-request cost is one read plus ten order
loads, which is better than 3.17.0's "load every order in one request"
(3.17.0 code-quality L4, fixed here). Admin one-off; acceptable. The HPOS
query could take the M1 prefilter (`billing_email = %s AND …`).
Pre-existing, unchanged: the legacy query has no `DISTINCT` (a post with two
`_billing_email` rows would appear twice — harmless, the erase is idempotent)
and no `post_type` filter (a WooCommerce Subscriptions `shop_subscription`
billed to the address is handled as one of the requester's orders — their
own data).

## I5 — INFO — the manifest's shutdown hook

- **Scope:** `run()` sets `$this->in_flight` and adds a `Closure` built once
  (`:149`–`:150`); `finally` clears `in_flight` and removes the same closure
  (`:153`–`:156`), so `remove_action()` finds it. `on_shutdown()` is a no-op
  when `in_flight` is null. The state lives on the instance, so the hook
  cannot act in another request, and a normal return, an early `return` or a
  caught `Throwable` always runs `finally`. Only a real fatal (time limit,
  memory limit, uncatchable error) or an `exit` / `wp_die()` during the run
  leaves the row open for the hook.
- **Wrong marking:** only one theoretical case — a fatal inside the final
  `store_exchange()` after `mark_sent()` (`:324`–`:337`) would turn a sent row
  into failed ("may not have received it"). `in_flight` is not cleared after
  `mark_sent()`; clearing it there would close the gap. Negligible.
- **What is stored:** `stopped_by()` reads `error_get_last()`'s message only
  to tell the time limit from the memory limit, and stores `PHP time limit |
  PHP memory limit | PHP fatal error | exit without a PHP error` plus
  `basename( file ):line` — no path, no message, consistent with PRO-3890.
  The exchange stored is the already-capped request copy (send phase) or
  `null` (build phase) plus `{outcome, reason: run_stopped}`; no header.
- **Not proven end to end:** `CatalogManifestTest` drives `on_shutdown()`
  through the `last_error()` seam. Whether WordPress's `shutdown` action runs
  after a real time-limit or memory-limit fatal (WordPress's fatal-error
  handler runs first and calls `wp_die()` with `exit => false`; a memory
  fatal leaves little headroom for `__()` and two `$wpdb->update()` calls) is
  not exercised. No regression either way — a row the hook cannot update
  stays `pending`, as in 3.17.0. Suggested before the changelog claims it:
  one manual wp-env check (a `smaily_connect_…` filter or a temporary
  `set_time_limit( 1 )` in a dev copy) that the row ends `failed`.
- **i18n:** the two new strings are in the `.pot` and translated in
  `-et.po` with translator comments; the `%s` fragment (`PHP time limit at
  Client.php:210`) stays English in the Estonian text — the same as the
  3.17.0 manifest strings.

## I6 — INFO — #219 dependency updates

- **Composer:** `packages` (production) is unchanged — only
  `woocommerce/action-scheduler` 3.9.3; all changes are in `packages-dev`
  (113 entries before and after). The release ZIP is built with
  `--no-dev`.
- **npm:** a version diff of `package-lock.json` (47 changed) and
  `blocks/package-lock.json` (118 changed) finds **no** package without the
  `dev` flag, and no `@wordpress/*` package moved (the PRO-3858 trap avoided).
  Build-relevant moves: root Babel 7.29.x, PostCSS 8.5.29; `blocks/` webpack
  5.99.7 → 5.111.1, terser 5.39.0 → 5.51.2, terser-webpack-plugin 5.6.1,
  Babel 7.27 → 7.29, serialize-javascript 6.0.2 → 7.1.2.
- **Shipped behaviour:** `blocks/*/build/*` (gitignored, built by CI) ships
  with the new webpack runtime. DECISIONS PRO-3858 records that admin,
  storefront and CSS bundles are byte-identical and the block bundles keep
  the same literals, properties and dependency lists. Not re-verified here (no
  build run). CI's block job and the integration render tests
  (`LandingPageBlockRender`, `StorefrontRecommendationsRender`) are green on
  `7372d6c`. Suggested: at the release gate, insert the sign-up, landing-page
  and recommendations blocks in the editor once.
- **Overrides:** every override raises a version; none pins an older or
  weaker one. Four force a major the parent did not ask for — `tinypool`
  ^2 (Vitest 3), `serialize-javascript` ^7 (copy-webpack-plugin 10), `uuid`
  ^11 for `<11.1.1`, `postcss-selector-parser` ^7 — all build/test-time only;
  the gates are green with them.

## I7 — INFO — CI

The new `order-storage` matrix interpolates only the constant values `legacy`
/ `hpos` into `run:` (no untrusted input). No new secret, trigger
(`push` to main, `pull_request` — not `pull_request_target`) or permission;
the missing `permissions:` block is pre-existing and already accepted (3.17.0
security I11). `bin/run-integration-tests.sh` whitelists the variable
(`legacy|hpos`, else exit 4) before it builds the `docker exec -e …` string,
so no value reaches the shell unvalidated. The bootstrap's `exit( 1 )` on a
storage mismatch is test-only. Both legs green on `7372d6c` (run
37889846035).

## I8 — INFO — `bin/measure-manifest-walk.php`

Dev-only; `/bin*` in `.zipignore` keeps it out of the ZIP, and
`bin/verify-release-zip.sh:108` fails a ZIP that contains `bin/`. It inserts and later deletes
`product` posts, their meta and term links by raw SQL, matched on the
`smaily-measure-` slug prefix; values go through `prepare()`, the `IN()`
lists are `intval`'d ids. Its header says to run it with `tests-cli`. On the
dev site (`cli`) the dev Action Scheduler runner could send a real manifest
that includes the seeded products if 03:00 falls inside the run (the next
night tombstones them again) — keep it on `tests-cli`. A real product whose
slug starts with `smaily-measure-` would be deleted by `cleanup`; not a
realistic slug.

## I9 — INFO — docs, i18n, readme

- **Docs site:** one sentence added to the nightly product list paragraph,
  EN and ET as a pair (`docs/site/index.html`). The ET sentence needs
  Erkki's proofread before the FTPS publish, like the 3.17.0 ones.
- **DATA_MODEL_GDPR.md** describes the paging and `AddressMatch` correctly.
- **DECISIONS PRO-3996** understates how often the cart delete runs (see M1).
- **i18n:** the only new translatable strings are the two in I5; both
  translated.
- **readme.txt** (measured on `feb168b`, which still says `Stable tag:
  3.17.0`): the Changelog section is **4 572 characters / 738 words** —
  intro 133, `= 3.17.0 =` 1 613, `= 3.16.1 =` 695, `= 3.16.0 =` 2 131.
  Against the 5 000-character limit the repo has used since the 3.16.0 gate,
  **a 3.17.1 entry has 428 characters of room with `= 3.16.0 =` kept**, or
  about **2 559** if `= 3.16.0 =` is dropped (allowed; PRO-4001 requires only
  `= 3.17.0 =` and `= 3.16.1 =`). (The 3.11.1 gate row records the parser
  limit as 5 000 *words*; the characters reading is the stricter one.) Upgrade
  Notice entries are 213 / 194 / 262 bytes against PCP's 300.

---

## Release punch-list — 3.17.1

1. **M1** — add the `email = %s` prefilter to the two cart tracker deletes
   (or Erkki accepts with a DECISIONS note), and correct the PRO-3996 wording.
2. **L1** — suppress the two false-positive `PreparedSQLPlaceholders`
   warnings in `CartSessionStore.php` before the PCP run.
3. **Version bump** in the nine places CLAUDE.md lists (incl. the `.pot`
   `Project-Id-Version`); `Stable tag: 3.17.1`; keep `= 3.17.0 =` and
   `= 3.16.1 =` in Changelog and Upgrade Notice.
4. **PCP against the CI-built ZIP** — expect the 15 accepted 3.17.0 warnings at
   shifted lines; watch `CartSessionStore.php` (L1).
5. Optional before the changelog claims PRO-3989: the manual stopped-run
   check in I5. Optional: the block editor smoke check in I6.
6. Erkki's Estonian proofread of the new docs-site sentence and the two
   `-et.po` strings.
7. Record this audit in `docs/audits/INDEX.md` and STATUS.md.

## Suggested 3.17.1 changelog (396 characters incl. header — fits the 428 left with 3.16.0 kept)

```
= 3.17.1 =
* Improved: personal-data export and erasure work through a customer's orders ten at a time, so a customer with many orders no longer risks a timeout.
* Improved: if the server stops the nightly product list, its Event Log row shows as failed and where it stopped.
* Fixed: an order or a login no longer removes the abandoned cart of an address that differs only by accented letters.
```

Upgrade Notice (153 bytes):

```
= 3.17.1 =
Privacy export and erasure handle many orders page by page; a stopped nightly product list shows as failed; an accented address keeps its abandoned cart.
```

Stores updating from wordpress.org come from 3.16.0, so the `= 3.17.0 =`
entry carries most of what they get; the dev-tool updates (#219) and CI
change (#215) are not merchant-visible and stay out.

## Conclusion

The delta is small and careful. The shared address match removes three
hand-written copies of a GDPR-critical condition without changing what any of
them matches, the paging fixes the 3.17.0 L4 load-everything risk while
keeping every one-off erasure step exactly once, and the shutdown hook closes
the "killed run stays pending without a reason" gap without widening what
the Event Log stores. Static gates are clean apart from two false-positive
warnings, and CI is green on both order storages. **3.17.1 may proceed once
M1 is fixed or accepted and L1 is applied.**

---

## Disposition (2026-10-09)

| # | Disposition |
|---|---|
| M1 (Medium) | **Fixed** in PRO-4004 (#221, the PR that records this audit): the new `AddressMatch::column_equals_indexed()` puts `email = %s` before the binary match, and both cart tracker removals use it, so `idx_email` narrows the rows first. Result unchanged (an accented address is another person, letter case does not matter); `CartPipelineTest` runs `EXPLAIN` on the DELETE each removal sends and requires `key = idx_email`. The DECISIONS PRO-3996 wording is corrected (the removal runs on every tracked cart update with a known address); DECISIONS PRO-4004 records the change. The privacy export / erasure matches and the order lookup are unchanged. |
| L1 (Low) | **Fixed** in the same PR: a `phpcs:disable` / `enable` pair for `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` and `…ReplacementsWrongNumber` around the two statements, with the reason (the `%s` placeholders come from `AddressMatch`). `phpcs` with warnings on is clean for `CartSessionStore.php`. |
| L2 (Low) | Backlog **PRO-4005** (offset paging skips the boundary order when an order is deleted between eraser / exporter pages). Not a 3.17.1 blocker. |
| I1–I9 (Info) | Accepted as recorded above. |
