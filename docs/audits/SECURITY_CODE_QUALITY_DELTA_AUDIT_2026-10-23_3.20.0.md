# Security + code-quality delta audit — 3.20.0 pre-release gate (3.19.0..bf8e9bd)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-23_3.19.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-10-23_3.19.0.md),
which covered everything up to the 3.19.0 tag (its addendum included). This
report covers what landed after the tag. The earlier reports stay valid for
what they covered.

- **Date:** 2026-10-23
- **Baseline:** delta `3.19.0..bf8e9bd` (tag `3.19.0` = `9c62e4f` → `origin/main`
  `bf8e9bd`): **5 commits, 37 files, +3 170 / −123**; outside `docs/`,
  `tests/` and `STATUS.md` **25 files, +1 410 / −44** (of it the `.pot` /
  `-et.po` catalogues +124). The shipped PHP is **13 files, +930 / −32**
  (all in `includes/`); the shipped TypeScript sources are **8 files,
  +224 / −12** (tests excluded). The work in it:
  - **#267** — docs only (the 3.19.0 release record).
  - **#268 CON-199** — a single-use WooCommerce discount code per welcome,
    first-order and abandoned-cart email:
    `Integrations\WooCommerce\AutomationDiscount` (settings, template filter,
    clone, expiry, daily cleanup), `Smaily\DiscountCodeIssuer`, the
    `AutomationRouter::trigger_automation()` `$before_send` callable, the
    Flusher / CartFlusher wiring, `EventQueue::update_payload()`, the
    `automationDiscounts` field of the Settings REST route
    (`SettingsEndpoint`), the `discountTemplates` / `discountValidDays` /
    `newCouponUrl` / `automationDiscounts` values of `EnvDetector`, the
    `QueueJanitor` tick, the admin `DiscountCodeSetting.tsx` card.
  - **#269 CON-213** — the personal-data exporter and eraser cover the
    plugin's codes (`AutomationDiscount::codes_for_address()` /
    `erase_for_address()`, `GdprHandler`, the `after_erasure()` second pass);
    the address match is `AddressMatch::json_string()` over the coupon's
    `customer_email` meta; `_used_by` / `customer_email` are written directly.
  - **#270 CON-214** — `AutomationDiscount::restricts_to_exactly()`: no code
    unless WooCommerce stored exactly the shopper's address.
  - **#271** — refactor: `Support\NumberInput::whole_number_in_range()`
    (shared by `TransactionalGate::delay_days_or_null()` and the discount
    day count), tidy-ups.
- **Auditor:** Claude (Opus 5.5), read-only on the code.
- **Trigger (re-audit policy):** point 1 (the 3.20.0 release boundary) and
  point 2 (**what gets stored**: a new store of personal data — one coupon per
  email, restricted to the shopper's address; **a changed REST route**: the
  Settings save takes `automationDiscounts`; **SQL**: the coupon address match,
  the cleanup meta query; **GDPR**: a new exporter and eraser path). Point 3
  too: the plugin now creates money-bearing objects (discount codes) on the
  send path.
- **Scope:** file-by-file read of the shipped PHP and TypeScript delta plus
  the call chains it reaches: `AutomationRouter::trigger_automation()`,
  `Flusher::dispatch()` / `dispatch_automation()` / `record_exchange()`,
  `CartFlusher::dispatch()` (consent gate, router path, legacy
  autoresponder path), `HookHandler`'s welcome enqueue
  (`enqueue_welcome()`, `maybe_welcome_checkout_optin()`,
  `welcome_optins_without_record()`), `GdprHandler::erase()` / `export()` /
  `after_erasure()`, `AddressMatch`, `SettingsEndpoint::permission_check()`,
  `uninstall.php`'s option sweep. WooCommerce 10.7 read in the dev wp-env
  for the facts the code relies on: `WC_Coupon::$data` and
  `set_email_restrictions()`, `DiscountsUtil::is_coupon_emails_allowed()`,
  the coupon data store's `_used_by` writes, `WC_Brands_Coupons`.
  DECISIONS CON-199 (+ addendum CON-214) and CON-213 read against the code;
  `readme.txt`, `docs/DATA_MODEL_GDPR.md` and the docs site checked for the
  new behaviour. No real customer data was used; addresses below are
  `example.test` placeholders.
- **Method:** static read; static tools read-only on the 13 changed PHP
  files: `vendor/bin/phpcs -n` exit 0; `vendor/bin/phpcs` with warnings: one
  warning, pre-existing (`QueueJanitor.php:263`, alignment, from `be00bb6f`);
  `vendor/bin/phpstan analyse` `[OK] No errors`. CI on `main`: "Lint and test
  the codebase" run 39997045871 on `bf8e9bd` green (both integration legs
  included); `Contract staleness` run 39997045934 green. **Release-gate build
  and Plugin Check** (below): ZIP built locally from `bf8e9bd`, verified, and
  checked with Plugin Check 2.1.0 in the dev wp-env. Not run: unit /
  integration suites locally (CI ran them), any live walk.

## Verdict

**0 Critical, 0 High, 2 Medium, 4 Low, 8 Info.**

**RESULT: 3.20.0 may proceed once M1 is accepted or narrowed, M2 is fixed
(or the docs say brand restrictions are not copied and Erkki accepts it),
and L1 and L2 are handled at the bump.**

The security-sensitive surfaces are correct. No code can be usable by
anyone: every path that would leave the allowed-email list empty, rewritten
or wildcarded makes no code (I2). The clone copies a fixed list of
WooCommerce props, never meta, usage, emails or dates. The Settings field
rides the existing `manage_options` + `wp_rest` nonce route, is validated
before anything is saved, and the template id is checked again at send time.
The new SQL is prepared; the address match is binary after lowering and is
re-checked entry by entry in PHP; a coupon without the plugin's marker meta
is never read, changed or deleted by the eraser or the janitor. The eraser
deletes unused codes and anonymises used ones without ever leaving an empty
allowed list. A code is made only after the workflow is found and, for a
reminder, after the CON-196 consent gate; a retry reuses the stored code.

The two Mediums are about money, not access. M1: nothing limits how many
codes one address can collect — in the modes without a consent record every
ticked checkout starts a welcome, and every welcome now carries a new code.
M2: WooCommerce's own Brands restriction (core since 9.6) is coupon meta,
which the clone does not copy — a "brand X only" template gives codes valid
on the whole catalogue, while the docs say the code copies the coupon's
restrictions.

---

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| M1 | Medium | `includes/Integrations/WooCommerce/AutomationDiscount.php:289`–`:321`; `includes/Integrations/WooCommerce/HookHandler.php:320`–`:328`, `:337`–`:353` | A new code per email, with no per-address limit. A welcome is de-duplicated only within one request, so a guest (or any shopper in the record-less modes) who ticks the box at every checkout gets a fresh welcome code after every order; every abandoned cart gives a reminder code | **Yes** — Erkki accepts (DECISIONS addendum + one docs-site sentence, naming the `smaily_connect_welcome_eligible` filter), or narrow it (no new welcome code while the address holds an unused, unexpired one) |
| M2 | Medium | `includes/Integrations/WooCommerce/AutomationDiscount.php:92`–`:105`, `:554`; docs site "Give each shopper a single-use discount code" | WooCommerce core's Brands restriction (`product_brands` / `exclude_product_brands` coupon meta, `WC_Brands_Coupons`, core since 9.6) is not copied: a template limited to one brand clones into a code valid on every product. The docs say "Each code copies the coupon's discount and restrictions" | **Yes** — copy the two meta keys (they are settings, not state), or refuse a template that has them; pin with an integration test |
| L1 | Low | `includes/Integrations/WooCommerce/AutomationDiscount.php:208`, `:369`, `:439` | Three new Plugin Check warnings: two `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` (the `phpcs:ignore` names `slow_query_meta_query`, a code that does not exist, so it suppresses nothing) and one `PluginCheck.Security.DirectDB.UnescapedDBParameter` (false positive) | Yes — at the bump |
| L2 | Low | `readme.txt` (description, data list, Changelog, Upgrade Notice) | The readme does not mention discount codes at all: that the plugin creates WooCommerce coupons holding the shopper's address, that the eraser / exporter cover them, the 30-day cleanup. Changelog is 3 800 / 5 000 characters | Yes — at the bump |
| L3 | Low | `includes/Integrations/WooCommerce/AutomationDiscount.php:113`–`:115`, `:354`–`:408` | The cleanup deletes at most 2 000 codes a day (10 × 200); a store that issues more falls behind for good. A coupon whose delete fails is fetched again by every batch and counted as deleted | No — backlog |
| L4 | Low | `includes/Integrations/WooCommerce/AutomationDiscount.php:195`–`:241`; `admin/src/components/steps/DiscountCodeSetting.tsx:40`–`:45` | The picker reads the first 200 coupons by title and drops expired ones afterwards; a saved, valid template beyond that window is shown as "#id — cannot be used" though sends use it | No — backlog |
| I1 | Info | WooCommerce `DiscountsUtil::is_coupon_emails_allowed()` | CON-216, accepted by Erkki 2026-10-23: WooCommerce matches an allowed email as an unescaped regex | — |
| I2 | Info | `AutomationDiscount.php:524`–`:526`, `:542`–`:598` | No code usable by anyone: every rewrite / empty / wildcard path verified | — |
| I3 | Info | `includes/REST/SettingsEndpoint.php:110`, `:129`, `:365`–`:398`; `includes/Wizard/EnvDetector.php:134`–`:143`, `:559`–`:569`; `DiscountCodeSetting.tsx` | Settings route: capability, nonce, validation, no trust in the template id | — |
| I4 | Info | `AutomationDiscount.php:421`–`:451`, `:354`–`:408`; `includes/Privacy/AddressMatch.php:87`–`:106`; `includes/Smaily/EventQueue.php:615`–`:624` | SQL: prepared; merchant coupons never touched; the marker meta is the only boundary | — |
| I5 | Info | `AutomationDiscount.php:465`–`:531`; `includes/Privacy/GdprHandler.php:163`–`:174`, `:282`–`:285`, `:467`–`:486` | GDPR export / erase verified: letter case, accents, never an empty list, second pass; what stays outside | — |
| I6 | Info | `includes/Smaily/AutomationRouter.php:120`–`:127`; `Flusher.php:280`–`:285`, `:320`; `CartFlusher.php:203`–`:242`, `:339`; `DiscountCodeIssuer.php:48`–`:79` | Send order: a code only after the workflow and the consent gate; a retry reuses it; the note is per row | — |
| I7 | Info | `AutomationDiscount.php:63`; `uninstall.php:54`–`:66`; `docs/DATA_MODEL_GDPR.md:213`–`:217` | Stored and logged data; codes after deactivation / uninstall | — |
| I8 | Info | `includes/Support/NumberInput.php`; tooling, CI, local build | Refactor equivalence, static gates, a local-build quirk | — |

---

## M1 — MEDIUM — a new code per email, no limit per address

**Where:** `AutomationDiscount::issue()` (`:289`–`:321`) makes a code for
every queue row whose automation has the switch on; the only "already has
one" check is the row's own payload (the retry case, `:297`). Upstream,
`HookHandler::enqueue_welcome()` (`:320`–`:328`) de-duplicates a welcome only
within one request (`self::$welcomed`, reset per request), and
`maybe_welcome_checkout_optin()` (`:337`–`:353`) starts a welcome for a guest
on every ticked checkout. CLAUDE.md records the rule: "Where the store keeps
no record (legitimate interest, checkout-only, guests) every ticked checkout
the contact sync sends starts it."

**Effect:** before 3.20.0 a repeated welcome was a repeated email. Now each
one also carries a new single-use code restricted to the same address, so a
returning guest who ticks the box gets a discount after every order — no
trickery needed. In consent mode an account can do the same by switching
the newsletter off and on in My Account. Every abandoned cart gives a
reminder code, which is the usual cart-abandonment trade-off but is also
unlimited. Each code is single-use and only for that address, so the
exposure is "one discount per trigger", not a shared code. DECISIONS CON-199
does not discuss repeated triggers; the docs site does not mention them.

**Recommendation (before release, one of):**
- **Accept** in a DECISIONS CON-199 addendum, and add one sentence (EN + ET)
  to the docs site: every email of an automation carries a new code, also a
  repeated welcome; the `smaily_connect_welcome_eligible` filter can stop a
  welcome for an address.
- **Narrow:** in `issue()`, make no new code for `welcome` (or any trigger)
  while `codes_for_address()` finds an unused, unexpired code of the same
  trigger for the address (needs a trigger marker meta beside
  `_smaily_connect_generated_from`). The email still goes, with its earlier
  code left on the Smaily contact (the field is left out, as for any send
  without a code).

## M2 — MEDIUM — WooCommerce's Brands restriction is not copied

**Where:** `AutomationDiscount::CLONED_PROPS` (`:92`–`:105`) and `create()`
(`:554`): the clone copies `WC_Coupon::get_data()` props only. WooCommerce
moved Brands into core in 9.6; its coupon restriction ("Brands" / "Exclude
brands" on the coupon's Usage restriction tab) is stored as coupon **meta**
`product_brands` / `exclude_product_brands` and enforced by
`WC_Brands_Coupons` through the `woocommerce_coupon_is_valid` and
`woocommerce_coupon_is_valid_for_product` filters (read in WooCommerce 10.7,
`includes/class-wc-brands-coupons.php:23`–`:24`, `:166`–`:171`). It is not
in `WC_Coupon::$data`.

**Effect:** a template "20 % off brand X only" gives codes that take 20 % off
any product. The merchant set the restriction on WooCommerce's own coupon
screen, and the docs site says "Each code copies the coupon's discount and
restrictions". DECISIONS CON-199 excludes "settings another plugin keeps in
its own coupon meta" on purpose (they can be state); Brands is WooCommerce
itself and is pure configuration (two lists of term ids). The pilot's
WooCommerce 6.9.4 has no core Brands; current stores (WooCommerce ≥ 9.6) do.

**Recommendation (before release):** copy `product_brands` and
`exclude_product_brands` from the template's meta onto the code (same
values, before the save), and pin it in `AutomationDiscountCodeTest` (a
brand-restricted template's code is refused for a product of another
brand). Alternative: list such a template as unusable with a reason, and
say so in the docs. Either way, make the docs sentence exact about what is
copied.

## L1 — LOW — three new Plugin Check warnings

Plugin Check on the built ZIP reports, all in `AutomationDiscount.php`:

- `:209` and `:370` — `WordPress.DB.SlowDBQuery.slow_db_query_meta_query`.
  The lines above carry `// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query`
  (`:208`, `:369`); WPCS's code is `slow_db_query_meta_query`, so the ignore
  suppresses nothing. The local ruleset does not report the sniff, which is
  why `phpcs` stayed silent.
- `:439` — `PluginCheck.Security.DirectDB.UnescapedDBParameter` ("Unescaped
  parameter `$sql`"). False positive: `$sql` holds `$wpdb->posts` /
  `$wpdb->postmeta` and the condition `AddressMatch::json_string()` builds
  from a column literal and `%s` placeholders; every value goes through
  `prepare()` on the same line.

**Recommendation (at the bump):** correct the two ignore codes; add
`PluginCheck.Security.DirectDB.UnescapedDBParameter` to the ignore at
`:438`. Or accept them as warnings 18–20.

## L2 — LOW — readme.txt does not mention discount codes

`readme.txt` has no "coupon" or "discount" anywhere. The feature makes the
plugin create WooCommerce coupons that hold a shopper's address; the
wordpress.org reader should find that in the description and the data list
(beside the cart tracker and the queues), with the eraser / exporter
coverage and the 30-day cleanup. The Changelog is 3 800 characters
including its heading; the 3.20.0 entry must keep the section under 5 000
(drop the oldest entry if needed, as at 3.19.0).

**Recommendation (at the bump):** description bullet, data-list line, a
`= 3.20.0 =` changelog entry and an upgrade notice.

## L3 — LOW — the cleanup has a fixed daily ceiling

`delete_expired_unused()` runs from the daily janitor tick and stops after
`DELETE_MAX_BATCHES` × `DELETE_BATCH` = 2 000 coupons. A store that issues
more than about 2 000 codes a day that expire unused never catches up, and
`wp_posts` / `wp_postmeta` grow with it (each code is a post plus some twenty
meta rows; WooCommerce's code lookup by `post_title` scans all coupon rows of
the `type_status_date` index). Second, smaller: when
`WC_Coupon::delete( true )` fails for a coupon (another plugin's hook, say),
the next batch selects the same ids again, so one bad coupon can spend the
whole run, and `$deleted` counts it each time.

**Recommendation (backlog):** continue past the first 2 000 while the run
has time (or re-queue an extra janitor pass when a full batch came back);
count only `delete()` calls that returned true and skip ids already tried
in this run.

## L4 — LOW — the picker's window

`templates()` asks for the first 200 published coupons of the three types by
title (`TEMPLATE_LIST_LIMIT`) and drops expired ones afterwards, so a store
with many expired coupons lists fewer than 200 usable ones. A saved template
outside the list is shown by `DiscountCodeSetting.tsx` as
"#id — cannot be used", although `issue()` checks it on its own
(`template_problem()`) and sends use it. Admin-only, no security effect.

**Recommendation (backlog):** add the saved template ids to the query (or
filter expiry in the query), and read the "cannot be used" label from
`template_problem()` instead of from list membership.

## I1 — INFO — CON-216: WooCommerce reads an allowed email as a regex

`DiscountsUtil::is_coupon_emails_allowed()` (WooCommerce 10.7) first tries an
exact match, then builds `'/^' . str_replace( '*', '(.+)?', $restriction ) . '$/'`
without `preg_quote()`. `restricts_to_exactly()` refuses `*`, but the other
metacharacters an address may legally contain stay live: in
`a+b@example.test` the `+` is a quantifier and `.` any character, so the code
also matches e.g. `ab@exampleXtest`; a `|` in the local part splits the
pattern into alternatives; a `/` ends the pattern and makes `preg_match()`
warn (the exact match still lets the owner use the code). Exposure needs the
code itself — 10 characters from 32 (`random_int()`, about 2^50), sent only to
the shopper — and the code works once. **Erkki accepted the risk on
2026-10-23 (CON-216).** Recorded here so the next audit does not re-raise it;
the upstream fix belongs in WooCommerce.

## I2 — INFO — no code usable by anyone

Verified against WooCommerce 10.7:

- `WC_Coupon::set_email_restrictions()` lowercases, `sanitize_email()`s and
  `array_filter()`s the list, then throws `WC_Data_Exception` for an entry
  `is_email()` refuses. Inside `set_props()` the throw becomes a `WP_Error`;
  `create()` (`:572`) then makes no code. `set_props()` keeps going after an
  error, so a refused restriction would otherwise leave the default `[]` —
  the check is what prevents an open code.
- A rewrite (`sanitize_email()` dropping non-ASCII letters, possibly to an
  empty list) is caught by `restricts_to_exactly()` (`:524`–`:526`): the
  stored list must be exactly `[ lowercased address ]`, the address non-empty
  and without `*`.
- The clone copies only `CLONED_PROPS`; `get_data()`'s `meta_data`,
  `usage_limit*`, `email_restrictions`, `used_by`, `usage_count`,
  `date_expires`, `status`, `code` are never copied. The template's own
  allowed emails are replaced, by design.
- `usage_limit` 1 and `usage_limit_per_user` 1 are set in the same
  `set_props()` call as the restriction; the expiry is capped at the
  template's (`expiry()`, `:334`–`:344`); a save exception returns no code.
- A template is re-checked at send time (`template_problem()`): published
  `shop_coupon`, not plugin-made, stored type in `TEMPLATE_TYPES` (read from
  meta, so another plugin's type is not mis-read as `fixed_cart`), not
  expired.

## I3 — INFO — the Settings route

`automationDiscounts` rides the existing `POST` settings route:
`permission_check()` requires `current_user_can( Constants::CAPABILITY )`
(`manage_options`), and REST cookie auth needs the `wp_rest` nonce. The
value is validated before any option is written (`from_request()`: a day
count outside 1–365 or not a whole number refuses the whole save with 400;
`NumberInput` takes an int or a digit string only); a missing automation
keeps its stored row. `template_id` is any non-negative int — not trusted:
`issue()` re-checks it. The option is a small array (autoload default),
swept by `uninstall.php`'s `smly_plus_%` delete. `EnvDetector` adds the
template list (coupon codes, types, amounts — admin-only, the same data
WooCommerce shows a `manage_options` user) and `admin_url()`'s add-coupon
URL; React renders the labels as text, the link with
`rel="noopener noreferrer"`.

## I4 — INFO — SQL

- `codes_for_address()` (`:433`–`:439`): the interpolations are
  `$wpdb->posts` / `$wpdb->postmeta` and the condition from
  `AddressMatch::json_string( 'LOWER( e.meta_value )', … )`, which carries
  only a column expression from code and `%s` placeholders; the patterns
  are `esc_like()`d, quoted (`"…"`, so a longer address does not match) and
  compared as `LIKE CAST( %s AS BINARY )`. The meta key is a placeholder.
- `templates()` and `delete_expired_unused()` go through `get_posts()` meta
  queries; `update_payload()` uses `$wpdb->update()` with formats.
- **Boundary:** every read, change and delete requires the
  `_smaily_connect_generated_from` meta (`INNER JOIN … g.meta_key = %s`;
  janitor `EXISTS`). A merchant's coupon has no such meta and is never
  touched. The meta is the only marker, though: a coupon that gets it by
  other means (a duplicate-post plugin copying a plugin-made code, a
  `meta_data` write through WooCommerce's REST API by a coupon editor)
  would be treated as plugin-made — deleted 30 days after expiry if unused,
  covered by the eraser. Needs a privileged act; noted, not a finding.
  `template_problem()` reads the meta as "plugin-made" only when non-empty,
  the SQL join on any value; the plugin always writes a non-empty id, so the
  two agree in practice.

## I5 — INFO — GDPR export and erasure

- **Match:** letter case does not matter, an accent does: binary `LIKE` over
  `LOWER()`, then `restrictions_without()` / `same_address()` entry by entry
  (`strtolower()` + `trim()`, bytes). Because CON-214 lets no code exist for
  a non-ASCII address, every plugin code holds an ASCII lowercase address,
  so the SQL and PHP lowering agree. No 3.19.0-era code exists to mismatch:
  #268 and #270 both land in 3.20.0.
- **Erasure:** an unused code (`usage_count` 0) is deleted; a used one keeps
  its usage record, loses the address from `_used_by` (only entries equal
  to the address) and from `customer_email`, which becomes
  `[ wp_privacy_anonymize_data( 'email' ) ]` when nothing else is left — never
  an empty list, so a cancelled order cannot reopen the code to anyone.
  Direct meta writes avoid `set_email_restrictions()`'s throw / rewrite.
  The pass runs on page 1 of the eraser and again on
  `wp_privacy_personal_data_erased` (`after_erasure()`), which covers a send
  already under way.
- **Export:** page 1 lists code, created, expires, used yes / no.
- **Stays outside, by design or by owner:** `_used_by` holds the **user id**
  (not the address) for a logged-in buyer — left as is; WooCommerce's
  checkout holds (`_maybe_used_by_*`, short-lived, DECISIONS CON-213); a copy
  of the coupon on the order's coupon line (older WooCommerce stores the
  coupon's data there) — the order is WooCommerce's to erase; the code and
  its last day on the Smaily contact (the eraser's message already says the
  Smaily contact is not removed).

## I6 — INFO — send order

`trigger_automation()` calls `$before_send` only after
`resolve_workflow()` found a workflow and before the post; after it, the
router always posts or throws, so a made code is never silently dropped. In
`CartFlusher::dispatch()` the consent gate runs first; the legacy
autoresponder path calls the same closure after it. `DiscountCodeIssuer::
fields()` writes the code into the row's payload (`update_payload()`) before
the post; a retry re-reads the row, finds the code in `fields`, makes none,
and posts the stored one. `array_merge()` order lets the code override any
same-named key. `noted()` attaches the reason to the stored exchange and
clears it for the next row. Not verified: whether two flush runs can ever
hold the same row at once (the queue has no claim column; pre-existing — it
would mean a second email, now also a second code).

## I7 — INFO — stored and logged data

New personal data: one `shop_coupon` post per code whose `customer_email`
meta is the shopper's address (and `_used_by` after use) — documented in
`docs/DATA_MODEL_GDPR.md:213`–`:217` and the docs site, exported and erased
(I5). The code and its last day also sit in the queue row's `payload` and
the stored exchange — erased with the row by `erase_for_privacy_request()`,
shown in the admin Event Log only. The note strings name a coupon id and a
reason, never an address. Nothing new is written to the debug log; a save
exception is swallowed into the note without its message. The coupon
description names the template's code (admin-only). **After deactivation or
uninstall** the codes stay as plain WooCommerce coupons: the janitor and the
eraser no longer run, `uninstall.php` removes the option but no coupon (a
valid code keeps working, which is wanted). The docs site could say so in
one line (backlog).

## I8 — INFO — refactor, static gates, CI, local build

- **#271 `NumberInput`:** `whole_number_in_range()` is the old
  `delay_days_or_null()` body with the bounds as parameters — same
  accept / refuse set (int, or a trimmed digit string; no sign, fraction,
  float or empty string).
- **PHPCS / PHPStan:** `phpcs -n` exit 0 on the 13 changed PHP files; with
  warnings one, pre-existing (`QueueJanitor.php:263`); PHPStan `[OK] No
  errors`.
- **CI:** "Lint and test the codebase" run 39997045871 and `Contract
  staleness` run 39997045934 on `bf8e9bd`, both green.
- **Local build quirk:** the first `composer run package` after a fresh
  `composer install` → `composer install --no-dev` zipped an **empty**
  `vendor/bin/` directory, and `verify-release-zip.sh` failed it ("shipped
  vendor/bin"). A second `--no-dev` install left no `vendor/bin/`, and the
  re-packaged ZIP verified OK. The release asset is built and verified by
  `release.yml`, so this does not reach merchants; noted for the next local
  gate.

---

## Release gate — built ZIP, verify, Plugin Check

- **Build** (worktree on `bf8e9bd`, macOS): `npm ci`, `composer install`,
  `npm run build:admin` (bundle-scope check OK),
  `composer run install-block-modules && composer run build`,
  `WP_CLI_BIN=vendor/bin/wp bash bin/build-i18n.sh` (committed `.pot` / `.po`
  restored afterwards), `composer install --no-dev --optimize-autoloader`,
  `composer run package`, `composer install` (see I8 for the one repeat).
- **ZIP:** `smaily-connect.zip`, **993 818 B**, SHA256
  `bd07c3a6dd351ea381ce52362dd4d3aeb5cd44f620c9320a0214f98612238d49`,
  build-hash `bf8e9bd` (clean), 409 entries. Version still 3.19.0 (the bump
  comes later); a local reference build, not the release asset.
- **`bash bin/verify-release-zip.sh smaily-connect.zip`** (repo root):
  **VERIFY OK** — every required file present (all four block builds, the
  admin catalogue JSON), no tests / docs / sources / maps / bin / CI / dev
  vendor, no `sourceMappingURL` trailer, bundles IIFE with no global leak,
  version 3.19.0 consistent.
- **Plugin Check 2.1.0** against the built ZIP in the dev wp-env (unzipped to
  `wp-content/plugins/smaily-connect-pkg`, `wp plugin check smaily-connect-pkg
  --slug=smaily-connect --format=csv --allow-root --exclude-directories=vendor`;
  the copy removed afterwards): **0 ERRORS / 20 WARNINGS.** The 17 accepted at
  the 3.19.0 gate, two at new lines: `DirectQuery` + `NoCaching` at
  `BackfillJob.php:677`, `IngestQueue.php:398`, `:427`, `QueueJanitor.php:229`
  (was `:225`), `EventsEndpoint.php:804`; `DynamicHooknameFound` at
  `TransactionalPayloadBuilder.php:159`, `:230`, `HookHandler.php:311`,
  `MarketingConsent.php:42`; `UnescapedDBParameter` at `GdprHandler.php:696`
  (was `:654`), `CartSessionStore.php:282`, `:306`. **NEW (L1):**
  `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` at
  `AutomationDiscount.php:209` and `:370`;
  `PluginCheck.Security.DirectDB.UnescapedDBParameter` at
  `AutomationDiscount.php:439`. No error.

## Release punch-list — 3.20.0

1. **M1** — Erkki accepts repeated codes (DECISIONS CON-199 addendum + one
   docs-site sentence, EN + ET), or `issue()` makes no new code while the
   address holds an unused, unexpired one of the same trigger.
2. **M2** — copy `product_brands` / `exclude_product_brands` onto the code
   (integration test), or refuse such a template; make the docs sentence on
   what is copied exact (EN + ET, Estonian proofread before publish).
3. **L1** — correct the two `phpcs:ignore` codes and suppress the
   `UnescapedDBParameter` false positive, or accept warnings 18–20.
4. **L2** — readme: description, data list, `= 3.20.0 =` changelog and
   upgrade notice; version bump in the nine places.
5. **PCP against the CI-built ZIP** after the bump — expect the 17 accepted
   warnings at the lines above, plus L1's unless suppressed.
6. Record this audit in STATUS.md (the release-bump PR).

Backlog: L3, L4; the I7 docs line on codes after uninstall.

## Conclusion

The delta adds a money-bearing object to the send path and a new store of
personal data without widening access: the Settings field is admin- and
nonce-gated and validated, no path creates a code that anyone can use, the
SQL is prepared, and the GDPR exporter and eraser cover the codes while never
touching a merchant's coupon. What needs a decision before the cut is how
generous the codes are: how many one address can collect (M1), and a
WooCommerce core restriction the clone leaves out (M2). Static gates are
clean, CI is green, the built ZIP verifies, and Plugin Check adds three
warnings, two of them from a misspelled ignore. **3.20.0 may proceed once M1
is accepted or narrowed, M2 is fixed or accepted with exact docs, and L1 and
L2 are handled at the bump.**

## Disposition (2026-10-23)

| # | Disposition |
|---|---|
| M1 (Medium) | **Fixed** in #273 (Refs CON-199) with Erkki's reuse rule (DECISIONS "CON-199" addendum "the 3.20.0 audit's M1 and M2"): before it makes a code, `issue()` looks for the address's code that the same automation made from the same template and that still works — published, unused, unexpired, allowed emails exactly the address (`AutomationDiscount::unused_code()`, over `codes_for_address()`). When one exists, the email carries that code and its own last day again and no coupon is made. Every new code records its automation in `_smaily_connect_trigger` (`META_TRIGGER`); a code without it is never sent again. Pinned in `AutomationDiscountCodeTest`. |
| M2 (Medium) | **Fixed** in #273: `create()` copies `product_brands` / `exclude_product_brands` from the template's meta (`CLONED_META`) when they hold a non-empty list. Pinned in `AutomationDiscountCodeTest`; the docs site says brands are copied. |
| L1 (Low) | **Fixed** in the 3.20.0 bump: the two `phpcs:ignore` comments in `AutomationDiscount.php` (`:227`, `:434`) name `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` — confirmed with `phpcs --standard=WordPress --sniffs=WordPress.DB.SlowDBQuery`, which reported that code on both lines before the fix and nothing after. `PluginCheck.Security.DirectDB.UnescapedDBParameter` joins the ignore above the `codes_for_address()` query (`:503`); the false positive is confirmed: `$sql` interpolates `$wpdb->posts`, `$wpdb->postmeta` and the condition `AddressMatch::json_string()` builds from a column expression from code and `%s` placeholders, and every value goes through `prepare()` on the next line. |
| L2 (Low) | **Fixed** in the 3.20.0 bump: `readme.txt` describes the discount codes under "Automate key WooCommerce moments", lists them under "Privacy and control" (WooCommerce coupons that hold the shopper's address, covered by export and erasure, unused codes deleted 30 days after expiry), adds the code and its last day to the Smaily Public API trigger line, and adds a `= 3.20.0 =` changelog entry and an upgrade notice that names the Disconnect / uninstall change (CON-219). The §16 line under Campaign Intelligence and the uninstall FAQ came with #275 and are not repeated. `= 3.17.1 =` is dropped from Changelog and Upgrade Notice; the Changelog section is 4 586 bytes (4 582 characters) including its heading. |
| L3, L4 (Low) | **Backlog** — CON-217. |
| I1 (Info) | CON-216, **accepted by Erkki** (2026-10-23), as recorded above. |
| I2–I8 (Info) | Accepted as recorded above. The I7 docs line on codes after uninstall stays a backlog candidate. |

## Addendum: post-audit delta (`def155e..2273c24`)

After this report (`def155e`), three PRs landed on `main` before the 3.20.0
bump: **3 commits, 23 files, +1 150 / −245**; outside `docs/`, `tests/`,
`STATUS.md` and `CLAUDE.md` 11 files, +205 / −43; shipped PHP 7 files
(`includes/` + `uninstall.php`), +198 / −37; shipped TypeScript 1 file,
+1 / −1 (the Disconnect confirm text).

- **#273 (Refs CON-199)** — the M1 reuse rule and the M2 brand copy (see
  Disposition), the docs site and DECISIONS.
- **#274** — the engine contract synced byte for byte to v1.18.0 (§16 now
  pauses a store and never deletes it; §23 field changes). The code changes
  are comments only: `Multilingual\Router`, `Settings\EmailDesignWorkflows`,
  `Settings\RecEngineSettings` (every changed line in `includes/` is a
  comment line). No wire shape that the plugin sends or reads changed.
- **#275 (Part of CON-219)** — Disconnect and uninstall send the contract's
  §16 disconnect request before they delete the key:
  `RecEngineSettings::end_engine_connection()` (`:227`), called first by
  `disconnect()` (`:257`) and by `uninstall.php` (`:74`–`:81`);
  `Client::store_uninstall()` (`:623`, endpoints-map key `store_uninstall`,
  fallback `PATH_STORE_UNINSTALL`). The readme, the docs site and the
  Disconnect confirm text say what the engine does.

**Security look at #273:**

- **No code reaches another address.** `unused_code()` reads only
  `codes_for_address( $email )` — the plugin's codes (the
  `_smaily_connect_generated_from` join) whose allowed emails hold the
  address — and accepts one only when `restricts_to_exactly()` still holds
  for that address, the trigger meta equals the automation (a string from
  code, `FIELDS` keys) and the template id matches. A used (`usage_count` >
  0), unpublished or expired code, or one without an expiry, is skipped. The
  reused code's last day is read from its own `date_expires`, so an email
  never promises a longer validity than WooCommerce enforces.
- **Brand copy.** The two meta values come from the admin-picked template
  (`template_problem()` still gates it), are copied only as non-empty
  arrays, and narrow the code — they never widen it. The "another plugin's
  meta is not copied" rule stands.
- **Stored data.** One new coupon meta per code (`_smaily_connect_trigger`,
  the automation's name — no personal data). Fewer coupons are made, so less
  personal data is stored. The eraser and the janitor are unchanged and
  still require the marker meta.

**Security look at #275 (the new external HTTP call, also during
uninstall):**

- **Who can start it.** Disconnect: the existing `POST
  /rec-engine/disconnect` route (`manage_options` + `wp_rest` nonce,
  `RecEngineEndpoint::permission_check()`); unchanged. Uninstall: only
  inside WordPress's plugin Delete flow (`WP_UNINSTALL_PLUGIN` guard, `:44`).
- **Code loaded during uninstall.** WordPress does not load the plugin for
  `uninstall.php`, so it requires the plugin's own `vendor/autoload.php`
  (PSR-4 `Smaily\Connect\` → `includes/`, no `files` autoload, so loading it
  runs no plugin code) and `includes/smaily-cypher.class.php` (the key is
  stored encrypted; the class only defines `Cypher`). Both are files of the
  plugin being deleted; nothing is loaded from outside it. The block runs
  only when `vendor/autoload.php` exists and catches every `Throwable`, so
  the option, table and schedule cleanup that follows always runs.
  Observation, not a finding: a `require_once` of a missing
  `smaily-cypher.class.php` is a fatal error that `catch` does not stop;
  every shipped ZIP holds both files (`verify-release-zip.sh` checks
  `vendor/autoload.php`), so only a hand-damaged install could reach it.
- **One short attempt.** `request_url( …, true )`: one attempt, `timeout`
  `BROWSE_TIMEOUT_SECONDS` (3 s, the whole request, connect included),
  `redirection` 0, no retry, no back-off, no Retry-After wait — the same
  mode as the `/relay` forward (PRO-3620). The admin's Disconnect or Delete
  request waits at most about 3 s; an unreachable engine cannot hold it.
- **The key.** Decrypted in memory by `api_key()` and sent only in the
  `Authorization` header to the stored base URL / endpoints-map URL — the
  same host as every other engine call, checked against the
  `intelligence.smaily.com` rule at exchange time for a connection made
  since PRO-3623. `redirection` 0 means a redirect cannot carry the header
  to another host. The gate is `is_connected()` with a stored key and base
  URL, not `sending_allowed()` (§16 does not answer `tenant_inactive`); a
  refused key costs one answered request. No request body.
- **What is logged.** On failure only `DebugLog::write()` (`WP_DEBUG`-gated):
  the HTTP status and the engine's error code, or the exception class —
  never the key, the URL, the engine's message or a body. The engine's
  answer (`status`) is returned, not stored. A `403 tenant_inactive` would
  set `smly_rec_refused_at`; `disconnect()` deletes that option in the same
  call and `uninstall.php`'s `smly_rec_%` sweep removes it right after.
- **Always deletes.** Any answer, error or exception still deletes the key
  (`disconnect()` deletes after the call; `uninstall.php` sweeps after the
  `try`). The plugin cannot be left connected by a failed §16 call.
- **No data deletion.** v1.18.0 §16 pauses a store and keeps its data
  (#274); the readme, the uninstall FAQ and the docs site say so. During
  uninstall `SMAILY_CONNECT_VERSION` is undefined, so the request's
  `User-Agent` names version `0.0.0` (cosmetic).

**Findings: none.** The addendum adds no Medium or Low finding. With M1 and
M2 fixed in #273, L1 and L2 fixed at the bump, L3 / L4 in the backlog
(CON-217) and I1 accepted (CON-216), **3.20.0 may proceed.**
