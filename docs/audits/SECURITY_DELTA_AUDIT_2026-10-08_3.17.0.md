# Security delta audit — 3.17.0 pre-release gate (e0e5f71..91d1c9b)

Follows [`SECURITY_DELTA_AUDIT_2026-10-06_3.16.0.md`](SECURITY_DELTA_AUDIT_2026-10-06_3.16.0.md)
and its parts 2 and 3, which covered everything up to the 3.16.0 release.
This report covers what landed after the released 3.16.0 tag. The earlier
reports stay valid for what they covered.

- **Date:** 2026-10-08
- **Baseline:** delta `e0e5f71..91d1c9b` (tag `3.16.0` → `main`): **31
  commits; 46 files outside `docs/` and `tests/`, +1 645 / −289.** The shipped
  plugin code (`includes/`, `integrations/`, `admin/`, `uninstall.php`,
  `smaily-connect.php`) is most of that; `bin/` is dev-only and is excluded
  from the ZIP (`.zipignore`: `/bin*`). The work in it:
  - **GDPR eraser / exporter:** #195 (PRO-2384, the eraser deletes
    Campaign Intelligence queue rows whose stored copy carries the address),
    #196 (PRO-2448, exact-address and non-ASCII match in the Smaily queue
    fallback), #199 (PRO-3906, the eraser drops the customer's waiting
    customer and order updates), #204 (PRO-3908, the order lookup reads the
    order table directly, with no status filter and `LOWER()` on both sides).
  - **Own-table SQL:** #193 (PRO-3897, `id` breaks a same-second `created_at`
    tie in both queues), `IngestQueue::has_pending()`, `delete_unsent()`,
    `delete_for_privacy_request()`.
  - **New outbound engine call:** #186 / #189 / #198 (PRO-3859, PRO-3899, the
    nightly §3c catalog manifest).
  - **Other:** #185 (Retry-After cap), #187 (Contact Form 7 sends
    `force_opt_in = true` explicitly), #188 (draft / private / pending products
    go out as the `in_stock=false` removal), #184 / #190 / #194 / #197 / #200 /
    #203 / #206 (import failure handling, the failure reason on screen, stall
    detection and the daily restart), #178 (every refusing Smaily body code is
    failed or retried, never marked sent), #191 (legacy
    `Smaily_Client::trigger_automation()` defaults `force_opt_in` to `false`),
    #181 (admin menu), #180 (Elementor repeater defaults), #192 (dev snapshot
    guard pings the engine), #202 / #205 (CI action majors, runner image), the
    3.16.1 bump.
- **Auditor:** Claude (Opus 5.5)
- **Trigger (re-audit policy):** point 1 (the 3.17.0 release boundary) and
  point 2 (**GDPR/consent**, **SQL against custom tables**, **what gets
  stored/logged**), plus point 3 (a new outbound call: the §3c manifest — same
  destination host, new data set).
- **Scope:** file-by-file read of the production delta (`git diff
  3.16.0..main` without `docs/` and `tests/`), plus the call chains it
  reaches: `GdprHandler` in full (export, erase, `orders_for()`,
  `user_id_for()`), `EventQueue::privacy_request_where()` and
  `contact_key()`, `IngestQueue` (new methods, `store_exchange()`),
  `OrderPayloadBuilder::build()` and `CustomerPayloadBuilder::email()` (how the
  address is normalised in the stored copy), `OrderBackfillJob::table_spec()`,
  `CatalogManifest` in full, `CatalogBackfillJob::manifest_items()` /
  `units_to_send()`, `CatalogPayloadBuilder::manifest_item*()`,
  `Client::catalog_manifest()` / `request_url()`, `Bootstrap` scheduling and
  `Deactivation` / `uninstall.php`, `BackfillEndpoint::status()` /
  `failure_reason()` and how `BackfillPanel` renders it, both import jobs'
  failure paths, `RetryPolicy`, every `trigger_automation()` caller, the CI
  workflows, and the new `bin/` helpers. Tests were read only to see what is
  pinned (`RecEngineGdprTest`, `RecEngineQueuePrivacyTest`,
  `SmailyQueuePrivacyTest`). DECISIONS entries PRO-2384, PRO-3859 (+ PRO-3899
  addendum), PRO-3824, PRO-3902, PRO-3906, PRO-3908 and PRO-3981 were read
  against the code. No real customer data was used.
- **Method:** static read only. No gates, PHPStan or PHPCS were run, no
  Docker / wp-env was touched, and **PCP was not run** — the release gate runs
  it on the CI-built ZIP. The CI runs for #205 and #206 on `main` were checked
  for a green result (`gh run list`).

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium, 1 Low, 11 Info.**

**RESULT: 3.17.0 may proceed.** Every new SQL statement is built with
`$wpdb->prepare()`; the only interpolations are table names from
`$wpdb->prefix` plus constants and placeholder lists the code generates. The
queue erasers match the address as a whole JSON string with a binary
comparison, so they neither over-match an accented or longer address nor miss
a `wp_json_encode()`-escaped one. The nightly manifest sends only `{sku,
in_stock}` (`woo-<id>` keys, no personal data), is gated on
`sending_allowed()`, refuses to send more than 50 000 items, goes only to the
exchange-validated engine host, and stores a capped request copy and the
engine's counts — no header. The import failure reason the admin panel shows
is masked for addresses and rendered as React text.

The one Low is in PRO-3908's order lookup: `LOWER()` does not make the match
independent of the collation as the code comment and DECISIONS say. On the
usual accent-insensitive collations, a request for one address also finds the
orders of an address that differs only by accents, so the exporter can list
another person's attribution markers (incl. the visitor token) and the eraser
can strip them. The behaviour is believed to be older than this delta (the
replaced `wc_get_orders( billing_email )` compared with `=` on the same
columns), and WooCommerce's own exporter returns far more for the same lookup —
but the neighbouring queue code fixed exactly this class in the same release,
and the new claim is wrong.

---

## Findings

| # | Severity | Where | Summary |
|---|---|---|---|
| 1 | Low | `GdprHandler.php:533`–`:538` (`order_ids_sql()`), `:507` (`orders_for()`), `:490` (`user_id_for()`) | `LOWER() = LOWER()` still compares with the column's accent-insensitive collation: an erasure/export for `jane@…` also finds `jäne@…`'s orders (and possibly user) |
| 2 | Info | `IngestQueue.php:388`–`:406`, `EventQueue.php:459`–`:480` | Queue erasers: binary, quote-bounded, escape-aware match verified; residual non-ASCII-case and unanchored-value edges |
| 3 | Info | `IngestQueue.php:423`–`:445`, `GdprHandler.php:391`–`:405` | `delete_unsent()` — prepared, chunked, scoped to one event type and the found ids; ordering before the engine call verified |
| 4 | Info | `CatalogManifest.php` (whole), `CatalogBackfillJob.php` `manifest_items()`, `Client.php` `catalog_manifest()` | Manifest: `{sku, in_stock}` only, `sending_allowed()` gate, 50 000 cap, host allow-list, no header stored |
| 5 | Info | `Client.php:696` | Retry-After capped at 60 s; a non-numeric or huge header cannot hold a worker |
| 6 | Info | `BackfillEndpoint.php:281`–`:297`, `BackfillJob.php:425`–`:433`, `AbstractBackfillJob.php` `record_failure()` | Failure reason on screen: masked and escaped; the stored row and the debug log keep Smaily's unmasked text (admin-only, pre-existing) |
| 7 | Info | `integrations/cf7/public.class.php:119`, `includes/smaily-client.class.php:81` | CF7 resubscribes explicitly (decided, PRO-3824); the legacy default flip to `false` breaks no in-tree caller |
| 8 | Info | `CatalogHookHandler.php` `enqueue_sync()` / `is_published()` | Draft / private / pending products are no longer recommendable — a disclosure fix; their data still reaches the engine as a removal |
| 9 | Info | `IngestQueue.php` `has_pending()`, queue `ORDER BY … id ASC` | New SQL reviewed: prepared, constant identifiers; tie-break is harmless |
| 10 | Info | `admin/wizard.php`, `includes/REST/BackfillEndpoint.php` | No new REST route; the changed status route and the menu keep `Constants::CAPABILITY` |
| 11 | Info | `.github/workflows/*.yml` | Action major bumps and runner pin only; no new secret or trigger. Pre-existing: no `permissions:` block, tag-pinned third-party actions in `release.yml` |
| 12 | Info | `bin/probe-smly-rec-connection.php`, `bin/lib-smly-snapshot.sh`, `bin/walk-pro3859-manifest.php` | Dev-only, excluded from the ZIP; print no key or token |

---

## 1. LOW — the order lookup's `LOWER()` does not make the match collation-independent

**Where:** `includes/Privacy/GdprHandler.php:533`–`:538`:

```php
"SELECT {$spec['id_col']} FROM {$spec['table']} WHERE LOWER( billing_email ) = LOWER( %s ) …"
"… WHERE m.meta_key = '_billing_email' AND LOWER( m.meta_value ) = LOWER( %s ) …"
```

called from `orders_for()` (`:507`), which feeds both the **exporter**
(`plugin_meta_export_items()`, `:269`–`:298`) and the **eraser**
(`erase_plugin_meta()` and `drop_waiting_updates()`, `:391`–`:405`).

**What is wrong.** `LOWER()` returns a string in the column's own collation,
and a column operand outranks a literal in MySQL/MariaDB collation coercion,
so `LOWER(col) = LOWER('…')` is still compared under the column collation.
`wc_orders.billing_email` and `postmeta.meta_value` use the database default
(`utf8mb4_unicode_520_ci`, `utf8mb4_unicode_ci`, `utf8mb4_general_ci`,
`utf8mb4_0900_ai_ci`, MariaDB `uca1400_ai_ci`) — all accent-insensitive, so
`jane@example.com` = `jäne@example.com`. The repo already proved this in
wp-env: PRO-2384 notes that "an integration test fails without" the binary
comparison in the queue eraser. The docblock (`:526`–`:532`) and DECISIONS
PRO-3908 ("`LOWER()` makes the match independent of the collation") state the
opposite. No test pins the accented case for orders
(`RecEngineGdprTest::test_erasure_finds_the_customers_orders_in_every_status_and_any_letter_case`
checks letter case and an unrelated address only).

`user_id_for()` (`:490`, `get_user_by( 'email' )`) has the same property
(WordPress core compares `user_email` with `=`), and since PRO-3906 its result
picks which `customer.upsert` rows the eraser deletes.

**Failure scenario.** Customer B has orders billed to `jäne@example.com`. A
different person controls the mailbox `jane@example.com` (same domain, or a
provider that accepts both forms) and files a WordPress personal-data export
request; the request is confirmed from `jane@example.com` and the admin
processes it. The plugin's exporter then lists, per order of B, the
`_smaily_rec_id`, `_smaily_rec_ctx`, `_smaily_anon_session_id` and
**`_smaily_visitor_token`** values and B's order numbers with "Newsletter
consent given at checkout". A store `vs_` token set as the visitor-token cookie
in the requester's own browser would let them ask `/recommendations` about B
(the 3.16.0 part-2 M1 read path). An erasure request from the same address
strips those markers from B's orders and drops B's waiting `order.upsert`
(and possibly `customer.upsert`) rows, so B's order updates never reach the
engine.

**Why Low.** It needs a requester-controlled address that differs from the
victim's only by accents (or trailing spaces under a PAD SPACE collation), a
confirmed request and an admin who processes it. WooCommerce's own exporter in
the same request returns B's full orders through an equivalent `=` lookup, so
the plugin adds little. The behaviour is **believed pre-existing**: the
replaced `wc_get_orders( billing_email )` builds `=` on the same column / meta
value (not re-verified against WooCommerce source in this pass). The delta
widens it from registered statuses to every status, and PRO-3906 adds the
dropped waiting rows as a new effect.

**Proposed fix (small, before or after 3.17.0):** compare bytes after
lowering both sides in SQL, e.g. `CAST( LOWER( billing_email ) AS BINARY ) =
CAST( LOWER( %s ) AS BINARY )` (and the same for `m.meta_value`) — both sides
lowered by the database, so the Unicode lowering agrees; pin it with an
accented-address integration case beside the existing letter-case one; and
correct the `order_ids_sql()` docblock and DECISIONS PRO-3908. For
`user_id_for()`, check `strtolower( $user->user_email ) === strtolower( $email )`
after `get_user_by()` before using the id (or accept it explicitly as core
behaviour). Note: the binary form gives up the index on `billing_email`, which
`LOWER()` already gave up; an erasure is an admin one-off.

## 2. INFO — queue erasers: match verified, two residual edges

`IngestQueue::delete_for_privacy_request()` and the Smaily queue fallback
(`EventQueue::privacy_request_where()`) were checked against how the copies
are stored:

- The address is matched as a whole JSON string (`"…"`), in the typed form and
  the `wp_json_encode()` form (`\uXXXX`, `\/`), through `$wpdb->esc_like()`
  (escapes `_`, `%`, `\`) and `prepare()`. The closing quote keeps
  `xjane@…` / `jane@…x` out. The comparison is `CAST( %s AS BINARY )`, so
  `jäne@` ≠ `jane@`. Pinned by `RecEngineQueuePrivacyTest` and
  `SmailyQueuePrivacyTest`.
- The stored copies are lowercased the way the request is:
  `OrderPayloadBuilder.php:160` and `CustomerPayloadBuilder.php:101`
  (`strtolower( trim() )`), and the Smaily queue applies `LOWER( payload )`.
- Edge (under-match, rare): PHP `strtolower()` is ASCII-only, so an address
  that differs only in the case of a non-ASCII letter (`JÄNE@` stored vs
  `jäne@` requested) is not matched; an address with both a non-ASCII letter
  and a `/`, stored by an encoder that escaped only one of them, is not
  matched either. Neither form is plausible in practice.
- Edge (over-match, harmless): the Campaign Intelligence pattern is not
  anchored to an `email` / `customer_email` key, so a row whose copy holds the
  address as any JSON string value (e.g. a product named exactly like the
  address) is deleted too. The row is a diagnostic copy (DECISIONS PRO-2384),
  so nothing of value is lost.
- The eraser returns `done => true` in one page and the exporter likewise
  (pre-existing, "paginate later"); a customer with very many orders loads
  them all in one request. Admin-triggered only.

## 3. INFO — dropping the waiting updates (PRO-3906)

`IngestQueue::delete_unsent()` deletes only `status IN ('pending','failed')`
rows of one named event type whose `entity_id` is in the id list, in prepared
chunks of 500. The ids come from `user_id_for()` and `orders_for()` (see #1
for their match). `erase()` runs it before `erase_engine()`, so no waiting row
is sent after the engine's DELETE. The accepted gaps in DECISIONS PRO-3906 (a
batch a flusher has already claimed; an import whose cursor has not reached
the customer) were confirmed and are not re-raised. Orders a registered user
placed under a different billing address are not dropped; their payload
carries that other address (`customer_email` is the only customer key on the
order wire), so they do not resend the erased address.

## 4. INFO — the nightly catalog manifest (PRO-3859, PRO-3899)

- **Data leaving the store:** `CatalogPayloadBuilder::manifest_item()` /
  `manifest_item_unresolvable()` return exactly `{sku, in_stock}`; `sku` is
  `SkuResolver`'s `woo-<id>`. No name, price, URL or personal data. The list
  is the import's own walk (publish + trash), so a private, draft or pending
  product is not even named.
- **Gates:** `sending_allowed()` (connection present and not refused), the
  products import not active (a stalled import does not block), no pending
  `catalog.*` rows, no exception while building. Over `MAX_PRODUCTS` (50 000)
  nothing is sent and a failed Event Log row says why.
- **Destination:** `resolve_url( 'ingest_catalog_manifest', … )` — the stored,
  exchange-validated endpoints map or the stored base URL (PRO-3623); no new
  host.
- **Stored / logged:** `last_response` holds `http`, `outcome` and the engine
  count fields filtered by `RESPONSE_FIELDS`, or `IngestQueue::http_error_response()`
  (status, error code, engine message). `sent_payload` is the first ~334 items
  capped at 10 000 chars. The `Authorization` header is never part of either.
  Skips go to the debug log with an exception message from catalog building
  (no personal data on that path).
- **Resource bounds:** one request with a 60 s timeout and 2 attempts
  (`rec_client( 2, 60 )`) plus a Retry-After of at most 60 s; the walk
  releases each batch's object-cache entries (`wp_cache_flush_runtime()` or
  per-key deletes — never a site-wide flush). WP floor 6.6 has every function
  used.
- **Lifecycle:** group in `Deactivation::AS_GROUPS`; uninstall's
  `smly_rec_%` sweep covers the hook.

## 5. INFO — Retry-After cap (#185)

`Client.php:696`: `$sleep_for = $retry_after > 0 ? min( $retry_after, 60 ) :
$backoff`. A date-form header casts to 0 (back-off used); an oversized number
saturates to `PHP_INT_MAX` and is capped. The worst case per call is now
bounded by attempts × (timeout + 60 s).

## 6. INFO — the failure reason on the import panel

`BackfillEndpoint::failure_reason()` returns text only for `failed`, strips the
`permanent_envelope_<code>:` class, and replaces every `[^\s@]+@[^\s@]+`
token with `[email]`. `BackfillPanel` renders it as a JSX text node (no
`dangerouslySetInnerHTML` anywhere in `admin/src`), so markup in a Smaily
message is shown as text. The route keeps its `current_user_can(
Constants::CAPABILITY )` permission callback. Campaign Intelligence imports
and the contact import's non-Smaily errors store `<class> at <file>:<line>`
only. The contact import's Smaily error is stored and debug-logged unmasked in
`error_message` (`BackfillJob.php:425`, `:433`) — admin-only, pre-existing
since PRO-3868. The mask does not catch a percent- or entity-encoded address;
Smaily's messages are not known to use either.

## 7. INFO — Contact Form 7 opt-in and the legacy client default

`Public_Base::submit()` now passes `force_opt_in = true` explicitly; the
behaviour is unchanged (the old default was `true`). Anyone who can submit an
enabled CF7 form can therefore re-subscribe an address that unsubscribed — a
single opt-in without address verification. This is the decided rule
(DECISIONS PRO-3824, same as Elementor PRO-3806) and the same class Erkki
accepted in PRO-3433; double opt-in remains the long-term mitigation. The
legacy `Smaily_Client::trigger_automation()` default flips to `false`
(#191): every in-tree caller passes the argument explicitly (CF7 `true`,
legacy cart cron, CartFlusher, AutomationRouter and Elementor `false`), so the
flip changes only third-party callers, in the safe direction.

## 8. INFO — unpublished products leave the recommendable set (#188)

Before PRO-3884 a save of a draft or private product enqueued a normal upsert
(`in_stock` from WooCommerce), so a private product could be recommended in
emails and on the storefront. Now any parent status but `publish` goes out as
the `in_stock=false` removal, on save, stock change and translation re-sync.
FIFO with the new `id` tie-break keeps an earlier upsert from overtaking the
removal. The removal object still carries the product's catalog fields to the
engine (the merchant's processor) — not a public exposure.

## 9. INFO — new SQL

All new statements were read: `has_pending()`, `delete_unsent()`,
`delete_for_privacy_request()`, the EventQueue fallback, `order_ids_sql()`,
the import state reads (`status`, `error_message` columns added) and the
compare-and-set `UPDATE … WHERE id = %d AND status = %s`. Values go through
`prepare()`; identifiers are `$wpdb->prefix` + class constants or
`OrderBackfillJob::table_spec()` constants; `IN ( … )` lists are generated
`%s` placeholders. `has_pending()` would emit invalid SQL for an empty list,
but its only caller passes three constants. The `ORDER BY created_at ASC, id
ASC` change is a constant clause.

## 10. INFO — routes, capabilities, menu

No REST route was added. `/backfill/status` gained `error` and the stall
mapping, behind the unchanged permission callback. The admin menu change
(#181) registers the same two page slugs with the same `$capability`.

## 11. INFO — CI

#202 / #205 move `actions/checkout`, `setup-node`, `cache`,
`upload-artifact` and `softprops/action-gh-release` to new majors and pin the
runner to `ubuntu-26.04`; both workflows ran green on `main`. No trigger,
secret or permission changed; `pull_request` (not `pull_request_target`) is
still the PR trigger. Pre-existing optional hardening, as noted in the 3.15.0
audit: no `permissions:` block (most relevant in `release.yml`, whose job holds
a token that can upload the release asset), and third-party actions
(`softprops/action-gh-release@v3`, `shivammathur/setup-php@v2`) pinned by a
movable tag rather than a commit SHA. Pinning those two by SHA and adding
`permissions: contents: write` (release) / `contents: read` (lint) would
narrow the supply-chain path to the shipped ZIP.

## 12. INFO — dev tooling

`bin/` is excluded from the ZIP. `probe-smly-rec-connection.php` exits outside
the CLI SAPI and prints only `probe=<word> http=<int>`;
`lib-smly-snapshot.sh` pipes it over STDIN and prints the tenant name, never
the key; `walk-pro3859-manifest.php` aborts unless the stored tenant is the
named sandbox on `https://intelligence.smaily.com` and prints only gate values
and the engine's counts.

---

## Accepted-risk notes (carried, not re-raised)

- DECISIONS PRO-3906: a batch already claimed by a flusher, and an import whose
  cursor has not reached the customer, can still send the erased customer.
- DECISIONS PRO-3908: a guest order billed to a different address is not found.
- DECISIONS PRO-3824 / PRO-3433: a form or checkout tick is a single opt-in and
  may override a prior unsubscribe.
- 3.16.0 part 3 Low 1 (engine-side retro-binding of an invented `vt_` token)
  is unchanged by this delta.

## Conclusion

The delta adds no capability, nonce, crypto or public-route surface. The
privacy work closes real gaps (sent copies and waiting updates in the
Campaign Intelligence queue, custom-status and trashed orders) and the queue
matching is precise. The one Low — the order and user lookup is still
accent-blind — is narrow, believed older than this delta, and does not block
3.17.0; fixing it is a two-line SQL change plus a test, and the PRO-3908
docblock and DECISIONS entry should be corrected either way. PCP runs at the
release gate on the CI-built ZIP; no new `$wpdb` direct-query site outside
already-ignored files is expected to add a new warning class, but
`GdprHandler::orders_for()` is a new direct query with a line-level
`phpcs:ignore` and should be checked in that run.

---

## Disposition (2026-10-08)

| # | Disposition |
|---|---|
| 1 (Low) | **Fixed** in PRO-3986 (#207): the order lookup compares `CAST( LOWER( … ) AS BINARY )` on both sides, the WP user is used only when its address matches after `strtolower()`, and the PRO-3908 rationale in DECISIONS is corrected; an accented-neighbour integration case pins it. The same accent-blind match in the exporter's and eraser's abandoned-cart session lookup is **fixed** in PRO-3993 (#210). The cart tracker's other, non-privacy matches by address are in the backlog as PRO-3996. |
| 2–10, 12 (Info) | Accepted as recorded above. Info 7 (Contact Form 7 sends `force_opt_in = true`) is Erkki's decision in PRO-3824. |
| 11 (Info) | Accepted as recorded above. The missing `permissions:` block and the tag-pinned third-party actions in `release.yml` were already noted in the 3.15.0 audit. |

The code-quality delta audit of the same date
([`CODE_QUALITY_DELTA_AUDIT_2026-10-08_3.17.0.md`](CODE_QUALITY_DELTA_AUDIT_2026-10-08_3.17.0.md))
records the dispositions of its own findings.
