# Security delta audit — 3.12.0 pre-release gate (3.11.3..HEAD)

- **Date:** 2026-09-07
- **Baseline:** delta `3.11.3..d302050` (the v3.11.3 tag to the pre-bump main
  tip; **33 commits, 46 files, +3 272 / −525**; the shipped plugin code alone —
  `includes/`, `admin/src/`, `migrations/` — is **18 files, +1 187 / −107**).
  The work in it:
  - **PRO-2324** — the Event Log's "Send again" action: a new admin REST route
    `POST /events/resend`, a `TransactionalResend` service, `enqueue_resend()`
    on `TransactionalFlusher`, `TransactionalRetryGuard::resendable()`, the
    `can_send_again` field on the list projection and the button that reads it.
  - **PRO-1723** — the abandoned-cart purchase marker: migration 011
    (`contact_key CHAR(64)` + `idx_type_contact_status` on the Smaily queue),
    `EventQueue::withdraw_pending_for()`, `AutomationMarker::purchase_stamp()`,
    and the two checkout call sites in `HookHandler`.
  - **PRO-2372** — a withdrawn reminder renders as `cancelled`
    (`EventQueue::is_cancelled_response()` + `last_response` in the list
    projection).
  - **PRO-2368 / PRO-2369** — merchant-readable refusal sentences for both
    Event Log actions, and the admin client's `errorMessage()` helper.
  - **PRO-2323** — wording/naming only: "kick" → "make sure a pass is
    scheduled" across five schedulers.
  - Non-code: `readme.txt` (marketing's wordpress.org listing copy), six
    replacement listing screenshots, `docs/`, `CLAUDE.md`, `languages/`, tests.
- **Auditor:** Claude (Fable 5.1)
- **Trigger (re-audit policy):** policy point 2 — the delta touches three named
  high-risk surfaces regardless of its size: a **new admin REST route**, **SQL
  against a custom table** (migration 011 + two new queries), and **what is
  stored / sent** (a hashed contact key at rest, a new contact field on the
  Smaily wire). Policy point 1 (release boundary) applies as soon as 3.12.0 is
  cut; this pass is the gate for it.
- **Scope:** file-by-file read of the production delta (`git diff 3.11.3..HEAD
  -- includes/ admin/src/ migrations/`), plus the call chains those files reach
  into (`TransactionalFlusher`, `TransactionalGate`, `Flusher`,
  `CartAbandonmentSweeper`, `CartPayloadBuilder`, `Client`, `Migrator`,
  `GdprHandler`). Test-only and docs-only files were skimmed for accidental
  secrets, not audited as behaviour; the six replacement screenshots were
  opened and read for credential/PII leakage. PCP against the built ZIP is
  reported in the register row for this gate, not here.

## Verdict

**0 Blocking, 0 Critical, 0 High, 0 Medium. 1 Low, 5 Info. RESULT: 3.12.0 may
proceed.**

Nothing in the delta widens who can reach a surface, what is stored in the
clear, or what leaves the site. The new REST route sits behind the same
capability + nonce gate as every other admin route in this plugin and
re-validates its own preconditions server-side; the new SQL is prepared; the
new column stores a hash of an address the same row already holds in
cleartext; the new contact field carries the marker and nothing else.

---

## 1. LOW — a checkout-supplied billing address is the key to another
contact's reminder withdrawal and purchase marker

**Where:** `Integrations/WooCommerce/HookHandler::maybe_mark_abandoned_cart_purchase()`
→ `EventQueue::withdraw_pending_for()` (PRO-1723).

`buyer_emails()` takes `$order->get_billing_email()` — a value the person
placing the order types — and `withdraw_pending_for()` cancels every still-
pending `automation.abandoned_cart` row keyed to that address, then (when a
reminder to it really was delivered) enqueues a `contact.sync` writing
`abandoned_cart_purchased_at` onto that Smaily contact. `woocommerce_
checkout_order_processed` fires at order CREATION, before payment, so no money
has to change hands.

An unauthenticated visitor who knows a third party's address can therefore
(a) suppress a reminder queued for them, and (b) stamp a purchase timestamp on
their Smaily contact, which the merchant's workflow reads as "stop the
follow-up series".

**Why it is Low, not higher:**

- It is not a disclosure. The checkout response says nothing about whether a
  row existed — the withdrawal and the marker are both write-only from the
  actor's point of view, so it is not an oracle for "was this person reminded".
- The marker can only land on a contact the store has **already emailed** (the
  `sent` + non-empty `sent_payload` proof), so it can never create a contact
  or reach someone the store has no relationship with.
- The suppression half is **inherited, not introduced**: `CartHookHandler::
  clear_for_order()` already deletes tracker rows for the billing address
  ungated on the same hook (recorded in DECISIONS PRO-1723 as pre-existing
  behaviour (a)), so an arbitrary address at checkout could already stop that
  address's *future* reminders. This delta extends the same key to an
  already-queued row and one contact field.
- The worst outcome is one marketing reminder not sent and one timestamp field
  written — no PII disclosed, no privilege crossed, nothing irreversible.

**Not fixed here** (audit pass, no product code changed). If it is ever worth
tightening, the shape is the same one WooCommerce uses elsewhere: honour the
billing address only for a guest order whose cart session token matches, and
the account address only for the logged-in customer. Recorded as a follow-up.

## 2. INFO — the new REST route is correctly gated, and a stale page cannot
send behind the merchant's back

`POST /wp-json/smaily-connect/v1/events/resend` registers with
`permission_callback => permission_check`, the same `current_user_can(
Constants::CAPABILITY )` (`manage_options`) the three existing Event Log routes
use; the admin bundle sends `X-WP-Nonce` on every call, so WP's cookie-auth
CSRF gate applies unchanged. Input is two scalars, both hard-validated: `id`
cast to `int` and refused at `<= 0`, `source` compared for exact identity with
`smaily` (the route deliberately accepts no other queue). Everything else the
action needs is read from the row itself, never from the request.

A stale Event Log page is therefore harmless: `resend()` re-reads the row and
re-asks `TransactionalRetryGuard::resendable()` (transactional event type +
status `sent`) and `TransactionalResend::resend()` re-loads the order and
re-runs `TransactionalGate::resolve_if_open()`. A row that has changed since
the page rendered is refused with 409, not sent. The button's `can_send_again`
and the route read the same rule from the same class, so they cannot drift.

The 4xx bodies carry an internal error CODE plus a merchant sentence and
nothing else (§5).

## 3. INFO — the once-per-order guard and fail-open are stepped over
deliberately, and only for a row a human asked for

`enqueue_resend()` stamps `TransactionalFlusher::PAYLOAD_KEY_RESEND` on the
queued payload; `is_resend()` then (a) skips `set_meta( …, META_STATUS_SENT )`
so the once-per-order-per-type order-meta guard never moves, and (b) returns
early from `fail_open()` so a failed re-send never mails WooCommerce's own
copy on top of the confirmation the shopper already has.

Both are the right way round from a security standpoint: the automatic
status-transition path keeps its guard intact (a merchant toggling an order out
of and back into a shipped status still sends nothing), and the manual path
cannot be used to unlock a second automatic send. The flag is set server-side
in a payload built server-side — no request field reaches it, so it cannot be
forged by a client.

## 4. INFO — the new SQL is prepared, the migration is dbDelta-shaped, and the
new column stores a hash rather than an address

- `EventQueue::withdraw_pending_for()` — `$wpdb->prepare( "… WHERE event_type
  = %s AND contact_key = %s" )`; the only interpolation is `$table`
  (`$wpdb->prefix . self::TABLE_SUFFIX`, an internal constant). It selects
  three columns and writes through the existing `mark_sent()` /
  `store_exchange()` pair — no new write path.
- `EventsEndpoint::fetch_row()` — `prepare( "… WHERE id = %d" )`, same table
  source; extracted verbatim from `detail()`, no behaviour change.
- `EventsEndpoint::conditional_column_expr()` — the only new SQL-building
  helper. Its `$column` arguments are PHP literals (`'payload'`,
  `'last_response'`), its `$status` and event-type values go through
  `quote()` (`$wpdb->_escape`) and come from class constants, and its
  `$source` is the enum `sanitize_source()` already reduced to
  `rec_engine|smaily|''`. No request value reaches the expression.
- **Migration 011** restates the full `CREATE TABLE` for dbDelta (the supported
  way to add a column + index), keeps the formatting invariants (two spaces
  after `PRIMARY KEY`, `KEY` not `INDEX`), and is picked up automatically by
  `Migrator::discover()`'s `NNN-*.sql` glob — no registry to forget.
- `contact_key` is `hash( 'sha256', strtolower( trim( $email ) ) )` — **the
  address is never written to the column**. It is unsalted, so it is not a
  privacy control against someone who already has the row; it does not need to
  be, because the same row stores the address in cleartext in `payload`
  anyway. The hash adds no new class of data at rest, and it is deleted with
  its row by the QueueJanitor's existing retention. (The Smaily event queue is
  not covered by `GdprHandler`'s eraser — pre-existing scope, unchanged by
  this delta: the queue is transit state with bounded retention.)

## 5. INFO — the refusal sentences leak nothing about the installation

The four new merchant-readable strings (`TransactionalResend::message()` ×3,
`TransactionalRetryGuard::message()`'s `REASON_RESEND_FAILED`) were read in
full: they name the feature and the outcome ("Transactional emails are switched
off for this confirmation…", "The second confirmation could not be queued…",
"This confirmation can no longer be sent again…", "This second confirmation
could not be sent…"). No file path, class, table name, SQL, HTTP detail,
account key, workflow id or stack detail appears in any of them. The
machine-readable `error` codes beside them (`resend_not_available`,
`transactional_sending_disabled`, `resend_enqueue_failed`) are opaque tokens.

`errorMessage()` (admin/src/api/client.ts) reads only `body.message` off an
`ApiError` and otherwise falls back to the caller's own sentence — which is
what stops the previous behaviour of showing the raw request line. The value
renders as React text (auto-escaped) inside a `Banner`; it is never
`dangerouslySetInnerHTML`, never an `href`.

## 6. INFO — `last_response` in the list projection carries no new data class

The list query now selects `last_response` for SENT rows of the Smaily queue
(`conditional_column_expr`). That column holds what the flushers store per
F3-44: either the Smaily reply (`{http, body}` — captured in `Client::
request()` from the reply only) or a synthetic terminal marker
(`{outcome: skipped|cancelled, note}`). It never holds the request, and the
`Authorization` header is not part of `last_exchange()` at all, by
construction. `detail()` has returned the same column to the same
`manage_options` audience since F3-44, so the list adds volume (≤200 rows per
page), not a new exposure. The heavier `sent_payload` is deliberately still not
selected by the list.

## 7. INFO — the purchase marker's field set and consent posture are as
DECISIONS PRO-1723 records them

The enqueued row is `contact.sync` with exactly `{ email, fields: {
abandoned_cart_purchased_at } }`. `Flusher::dispatch_contact_sync()` merges
that into one Smaily address row and adds `language` / `is_unsubscribed` only
when the payload carries them — it does not here, so no other field is touched
and PRO-1678's "absent ≠ empty" holds (nothing can be wiped). No new syncable
field beyond the marker is introduced; `AutomationMarker::FIELD_ABANDONED_CART_
PURCHASED` sits outside `FIELDS` precisely because it triggers nothing.

The gate is `SetupState::completed()` plus the delivered-reminder proof
(`status = sent` AND non-empty `sent_payload`), which is what keeps it from
creating a contact out of an ordinary purchase. The contact-sync master switch
and the audience modes are deliberately not consulted — the recorded decision
(PRO-1723, following PRO-1678) puts this on the same legitimate-interest basis
as the PRO-1681 automation markers, and it can only touch a contact the store
has already emailed. Confirmed as intended, not drift.

---

## Confirmed clean (checked, nothing to report)

- **Capability + nonce** on all four Event Log routes; `Constants::CAPABILITY`
  is unchanged (`manage_options`).
- **No new outbound destination.** `TransactionalResend` reaches Smaily only
  through the existing `TransactionalFlusher` → `Client::send_message()` path
  on its own scheduled pass; no new host, endpoint or credential use.
- **No new client input reaches the engine or Smaily.** The public `/relay`
  route, `BeaconEndpoint::EVENT_FIELDS`, `LandingCapture` and both storefront
  bundles are untouched by this delta (grep-confirmed).
- **No crypto, no file I/O, no secrets handling** changed. No `Authorization`,
  API key, password or token appears anywhere in the production delta (grep);
  the only credential-shaped strings are the integration fixture's
  `'test-password'` and `wp_generate_password()` calls in test support code.
- **PRO-2323 is naming only** — `kick_flush()` → `ensure_flush_scheduled()`
  and the three doc rewrites in `EventQueue` / `IngestQueue` /
  `CartAbandonmentSweeper` change no scheduling behaviour (the same
  `as_next_scheduled_action` dedup, the same async one-off).
- **The six replacement listing screenshots** (`assets/screenshot-1..6.png`)
  were opened and read: WordPress admin UI only, the site host and the engine
  tenant name blurred, no API key, password, subdomain credential, customer
  address or order data visible. `screenshot-5` shows a demo store's public
  RSS-feed URL (`woocakes.smaily.dev`), which is a marketing demo host, not a
  secret.
- **`readme.txt`, `docs/`, `CLAUDE.md`, `languages/`** carry no code.

## Changed files — in or out of security scope

| File | In scope? | Why |
|---|---|---|
| `includes/REST/EventsEndpoint.php` | **in** | new REST route, new SQL projection (§2, §4, §6) |
| `includes/REST/EndpointRegistry.php` | **in** | route registration + the resend factory (§2) |
| `includes/Smaily/TransactionalResend.php` | **in** | new service behind the route (§2, §5) |
| `includes/Smaily/TransactionalFlusher.php` | **in** | guard/fail-open interplay (§3) |
| `includes/Smaily/TransactionalRetryGuard.php` | **in** | the rule both actions read (§2, §5) |
| `includes/Smaily/EventQueue.php` | **in** | new SQL + the hashed contact key (§4) |
| `migrations/011-add-event-queue-contact-key.sql` | **in** | custom-table DDL (§4) |
| `includes/Integrations/WooCommerce/HookHandler.php` | **in** | checkout-input-keyed lookup + what reaches Smaily (§1, §7) |
| `includes/Smaily/AutomationMarker.php` | **in** | the new contact field (§7) |
| `includes/Bootstrap.php` | **in** | wiring of the new service (read; no gate touched) |
| `admin/src/api/client.ts`, `admin/src/api/events.ts`, `admin/src/components/settings/EventLog.tsx` | **in** | what the admin renders from a server message (§5) |
| `includes/Smaily/CartAbandonmentSweeper.php`, `CartFlusher.php`, `Flusher.php`, `RecEngine/IngestQueue.php` | out | rename + constant extraction + comments only (PRO-2323 / `OUTCOME_SKIPPED`); no behaviour |
| `assets/screenshot-*.png` | out (checked anyway) | shipped listing images — read for leakage, clean |
| `readme.txt`, `docs/**`, `CLAUDE.md`, `languages/**` | out | no executable content |
| `tests/**` | out | not shipped; skimmed for secrets, clean |

## Gates run for this pass

- `npm run ci:strict` — **exit=0**: PHPCS **0 errors** (warnings are the
  pre-existing accepted set), PHPStan `[OK] No errors`, PHPUnit unit **787
  tests / 2 231 assertions**, vitest **312 tests / 41 files**, tsc + eslint
  clean.
- The integration suite was not re-run for this pass (audit only, no product
  code changed); STATUS.md records it green per code-touching commit in the
  delta.
- PCP against the built ZIP + `bin/verify-release-zip.sh` were run as the
  release-build half of this gate — result in the register row.

## Follow-ups this audit leaves open

1. **Finding 1 (Low)** — decide whether the checkout-supplied billing address
   should be honoured for the withdrawal/marker lookup only when it matches the
   session's own cart identity. No change made here.
2. **PCP warning count moved 5 → 7** — `EventsEndpoint::fetch_row()`, extracted
   from `detail()`, kept only the `PreparedSQL.InterpolatedNotPrepared`
   suppression the inline block had, dropping
   `DirectDatabaseQuery.DirectQuery` / `.NoCaching`. Same accepted class as the
   other five, no new class, but the count is avoidable noise.
3. **The committed `.pot`/`-et.po` are behind the delta by 5 msgids** (four PHP
   refusal sentences + the JS `cancelled` label). `bin/build-i18n.sh` produces
   them; the ET translations do not exist yet and need Erkki's proofread before
   3.12.0 ships.
