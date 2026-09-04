# Security + code-quality re-audit — abandoned-cart rewrite / v3.7.0 gate delta

- **Date:** 2026-07-13
- **Baseline:** delta `aa86c9a..HEAD` (v3.6.1 → pre-3.7.0 main; commits `dfbfa38`…`700d870`, 16 commits, 63 files, +7115/-1100 lines)
- **Auditor:** Claude (Fable 5, clean-context agent — no involvement in writing the delta)
- **Trigger (re-audit policy):** release boundary (v3.7.0 gate, Linear PRO-1341) + size (>2,000 changed lines) + security-sensitive surfaces (new custom-table SQL, new PII-storing surface, `uninstall.php` destructive sweeps, GDPR/consent code, hooks reading user-submitted checkout data).
- **Scope:** full diff of the delta read file-by-file, security + code-quality lenses, per the task brief. High-risk surfaces explicitly swept: the new `smly_plus_cart_session` tracker (SQL + PII), the cart hooks (classic + Store API checkout email capture), `ProfilingConsent` fail-open hardening, `uninstall.php` sweeps, the disabled-workflow dropdown filter, event-type queue scoping. Explicitly verified NOT touched: REST route surface (beyond test-pinning), the public `/relay` beacon, auth/nonce paths, crypto. Read-only pass — no code changed.

## Verdict

**PASS. 0 Critical / 0 High / 0 Medium / 0 Low / 2 Info.** No release blocker found in this delta.

## What the delta is

The biggest piece (commit `f005f92`, PRO-1195) rewrites abandoned-cart onto the
namespaced pipeline used by the other ingest domains: `CartHookHandler` (WC
cart/checkout hooks) → `smly_plus_cart_session` tracker (migration 009, own
scalar JSON row shape, guest carts included) → `CartAbandonmentSweeper` (F3-37
backlog guard + cutoff, on the existing 15-min AS tick) → the shared Smaily
`EventQueue` (`automation.abandoned_cart`, now event-type-scoped away from the
main `Flusher`) → `CartFlusher` (its own AS action, F3-54 router-first/legacy-id
dispatch, F3-44 exchange capture). `Migration\LegacyCartDrain` does a one-time,
read-only copy of in-flight legacy rows into the new tracker on upgrade. The
legacy `Cart` class and Cron abandoned-cart callbacks are deregistered
(F3-53 discipline: nothing may still fire the retired pass).

Smaller pieces: `7855934`/`914fc42` filter `is_enabled=false` Smaily workflows
out of the CF7/Widget/Elementor/Gutenberg autoresponder dropdowns and
preserve-and-flag a saved-but-now-disabled binding; `31a8c0d` hardens
`ProfilingConsent`'s fail-open GDPR window (durable opt-out registry + stale
cache); `3fb7dc7`/`700d870` extend `uninstall.php` to sweep the profiling
opt-out registry and every `smly_rec_*` option/AS-hook; `f3f134d` pins the
existing `/events` route triple into `EndpointRegistry::expected_routes()`
(test-only, no route added); plus a contract sync (v1.4.1, doc-only), the
contract-staleness CI guard, and three new developer docs
(`ARCHITECTURE.md`/`DEVELOPER.md`/`API.md`).

## Security — surfaces checked

1. **`smly_plus_cart_session` SQL** (`includes/Smaily/CartSessionStore.php`,
   `includes/Migration/LegacyCartDrain.php`): every value-bearing query goes
   through `$wpdb->prepare()` (including the dynamic `due_rows`/`delete_expired`
   date-range queries and `LegacyCartDrain`'s `SHOW TABLES LIKE`); the only
   interpolated identifier is the table name, built from `$wpdb->prefix` + a
   class constant, never request input. No new `$wpdb->query()` call in the
   delta takes an unprepared value.
2. **Checkout-input capture** (`CartHookHandler::on_checkout_update_order_review`
   / `on_store_api_update_customer`): classic-checkout `billing_email` goes
   through `sanitize_email(wp_unslash())` + `is_email()` before use; Store-API
   values come off the already-validated `WC_Customer` object via
   `sanitize_email()`/`sanitize_text_field()`. No superglobal is read directly;
   `parse_str()` operates on the string WC itself already extracted from
   `$_POST`. Nothing captured is echoed back to a browser.
3. **Cart item capture** (`on_cart_updated`): only `product_id`/`variation_id`/
   `quantity` are pulled from the live `WC_Cart`, all int-cast — no product
   name/description/free-text line-item meta is persisted into the tracker row.
4. **PII minimization + retention**: the tracker stores email, first/last name
   and the scalar cart-item array; housekeeping (`delete_expired`/
   `prune_notified`) runs **unconditionally**, even when the feature is toggled
   off, bounding every row to the F3-37 backlog window (default 24h) regardless
   of send state — confirmed in `CartAbandonmentSweeper::sweep()` (expiry/prune
   calls precede the `enabled()` gate) and unit-tested
   (`test_disabled_toggle_still_runs_housekeeping_but_never_enqueues`).
   `CartHookHandler::tracking_enabled()` additionally gates capture itself on
   `smly_plus_setup_completed` — an un-wizarded store accumulates no cart PII.
5. **`ProfilingConsent` durable opt-out registry**: keyed by `md5()` of the
   lowercased/trimmed email (same hashing already used for the existing
   per-email transient keys — no new hashing scheme), `autoload=false`, stores
   opt-outs only (bounded to opt-out count, not the whole contact base). No
   email appears in any `DebugLog::write()` call on this path (checked every
   catch block in `refresh()`/`write()`/`engine_opt_out()`/`engine_opt_in()`).
   The fail-open residual is now correctly scoped to "genuinely never resolved"
   only; a durable opt-out can never be re-allowed by a transient read error.
6. **`uninstall.php` sweeps** (`3fb7dc7`, `700d870`): both new LIKE-prefix
   deletes use `$wpdb->prepare()` + `$wpdb->esc_like()`, mirroring the
   pre-existing `smly_plus_%` pattern; the AS-actions purge keeps its
   table-existence check before querying. `smly_plus_cart_session` is
   correctly added to the table-drop list and to `EnvScrub`/
   `SchemaMigrationTest` in the same commits (checked directly — present in
   all three places).
7. **REST/route surface**: `EndpointRegistry.php`'s only change is adding the
   three already-live `/events*` routes to `expected_routes()` (test-pin,
   PRO-1258 follow-through) — grepped the whole delta for
   `register_rest_route(` and found zero new calls, only a prose mention in
   `docs/API.md`. `EventsEndpoint::kick_flush()` (new) reuses the exact fixed
   whitelist / `as_next_scheduled_action` dedup pattern already audited for
   the other three flush-kick hooks (2026-07-10 report) inside the same
   `manage_options`-gated `/events/retry` route — no new capability/nonce
   surface, no request-controlled hook name.
8. **Confirmed untouched**: `/relay` beacon, `Client`/crypto code, nonce
   handling — no file under those paths appears in the delta's changed-file
   list.
9. **Autoresponder-dropdown escaping** (CF7 partial, classic Widget, Gutenberg
   block, Elementor description): every new echo path uses `esc_attr`/
   `esc_html`/`esc_html__`/React JSX text nodes — no raw interpolation of the
   engine-origin `title`/id values found.
10. **`maybe_unserialize()` in `LegacyCartDrain`**: unserializes the plugin's
    OWN pre-existing legacy table's `cart_content` column (written by the
    plugin's earlier version, not attacker-reachable network input) exactly
    once per row, guarded by `is_array()` + a per-row `Throwable` backstop.
    Same trust level as the code it replaces (the legacy Cart pass already did
    this); not a new risk surface, and the table is local DB access only
    (an attacker with write access to it already has full site compromise).

## Code quality — invariants checked against CLAUDE.md/DECISIONS

11. **Event-type scoping holds both directions**: `EventQueue::pending()` gained
    `$only_types`/`$exclude_types`; `CartFlusher::flush()` passes
    `array(self::EVENT_TYPE)` as the only-list, `Flusher::flush()` passes
    `array(CartFlusher::EVENT_TYPE)` as the exclude-list — verified in the
    diffs of both files. Unit-pinned
    (`CartFlusherTest::test_flush_drains_only_the_cart_event_type`).
12. **F3-37 backlog guard carried over intact**: same filter name
    (`smaily_connect_abandoned_cart_max_age_seconds`), same 24h default, same
    "expire without emailing, never mass-mail history" semantics — now in
    `CartSessionStore`/`CartAbandonmentSweeper` instead of the legacy Cron pass.
13. **Terminal-skip observability (F3-53 class) holds**: every dead-end in
    `CartAbandonmentSweeper::sweep()` and `CartFlusher::dispatch()` (unreadable
    payload, no workflow configured, non-101 legacy body code, decode failure,
    unexpected `Throwable`) is logged via `DebugLog` and terminally marked
    (`mark_reminder_enqueued`/`mark_sent`/`mark_failed`) — never a silent drop,
    never an eternal retry for a deterministic failure.
14. **F3-44 exchange capture holds**: `CartFlusher::record_exchange()` never
    reads/stores an Authorization header (it consumes `AutomationRouter::
    last_exchange()` / `Client::last_exchange()`, both audited 2026-06-30/
    2026-07-10 to build exchanges from method/endpoint/body/reply only), caps
    each field at the same 10 KB, and stores a `{outcome:"skipped"}` marker
    when nothing was POSTed — matching the existing convention exactly.
15. **F3-53 "never re-arm legacy WP-Cron" holds**: `cron.class.php` no longer
    registers the two legacy abandoned-cart hooks at all (not just no-ops);
    `Bootstrap::on_abandoned_cart_tick()` calls the new sweeper directly,
    doesn't `do_action()` into the legacy names. Directly tested
    (`LegacyCronScheduleTest::test_legacy_abandoned_cart_pass_has_no_registered_callbacks`).
16. **Upgrade continuity**: `LegacyCartDrain` runs from `Activation::run()`
    wrapped in try/catch (a drain failure can't fatal activation), stamped
    one-time (`smly_plus_cart_legacy_drained`), read-only on the legacy table
    (not dropped — matches the documented "safe rollback" design), and
    schedules nothing.
17. **SkuResolver / IsoDate / datetime discipline**: the cart pipeline doesn't
    touch the rec-engine ingest path at all (`SkuResolver` not applicable
    here); cart timestamps are hand-rolled `gmdate('Y-m-d H:i:s', …)` /
    `current_time('mysql', true)` UTC strings compared as MySQL DATETIME, not
    the Z-suffix ISO format IsoDate governs — correctly a different contract
    (Smaily legacy API, not the rec-engine's strict Zod `.datetime()`), so this
    is not a regression of the IsoDate rule.
18. **Test coverage matches every claim above**: `CartHookHandlerTest` (invalid
    email ignored, gated tracking, ungated order-completion clears),
    `CartAbandonmentSweeperTest` (housekeeping runs while disabled, poison-row
    backstop, cutoff/backlog window bounds), `CartFlusherTest` (event-type
    scoping, router-first/legacy fallback, terminal vs. transient error
    split), `CartPayloadBuilderTest` (JSON-shape guard, no-email null,
    escaping), `LegacyCartDrainTest` (poison survival, recent-reminds/
    stale-expires), `CartPipelineTest` (6 end-to-end scenarios incl. Bootstrap
    hook registration), `UninstallCleanupTest` (+4 new source-level pins) —
    read the assertions directly, not just the test names; they check what the
    docstrings claim.

## Findings

| # | Severity | Finding | Disposition |
|---|---|---|---|
| 1 | Info | `uninstall.php`'s `$legacy_options` array (deleted via `delete_option()`) contains `'smaily_connect_abandoned_carts'` — but that string is actually the legacy CART **TABLE** suffix (`Migration\LegacyCartDrain::LEGACY_TABLE_SUFFIX`), not an option name. `delete_option()` on it is a harmless no-op (nothing ever writes an option by that name), so it doesn't change uninstall behavior — but it's dead/misleading code that could confuse a future reader into thinking the legacy table is swept on uninstall (it deliberately isn't — LegacyCartDrain's docblock says the table is kept for rollback). **Pre-existing at the delta's baseline (`aa86c9a`), not introduced by this delta** — noticed while reading the file for the PRO-1336/1337 sweep review. | **FIXED (PRO-1342, 2026-07-17):** the stray `'smaily_connect_abandoned_carts'` entry was removed from `$legacy_options` in `uninstall.php`. No replacement option name exists — confirmed by grepping the codebase for any option ever written by that name (none; `smaily_connect_abandoned_cart_cutoff`/`_status`/`_fields` are the real abandoned-cart options and are already listed separately). The legacy cart table itself is intentionally untouched by uninstall.php (DECISIONS.md PRO-1195: "The legacy table is NOT dropped (safe rollback; schema removal is a later one-way door)") — this fix only removes the dead option-list entry, table-drop behavior is unchanged. |
| 2 | Info | The new `smly_plus_cart_session` table stores cart PII (email, first/last name, cart contents) locally in WordPress. It isn't mentioned in `docs/DATA_MODEL_GDPR.md` (that document's own scope note explicitly limits it to "ONLY rec-engine personal data" — WooCommerce/Smaily-marketing data is out of scope by design, consistent with how the legacy cart table was already handled) nor in the merchant privacy-policy template section of that same doc. Retention is well-bounded by design (F3-37, ~24h backlog guard, unconditional housekeeping) — a genuine mitigating factor. Not a regression: the table replaces an equally-undocumented legacy table with the same PII shape. | Not fixed (docs scope call, not a code defect). Follow-up: since PRO-1194 is actively closing GDPR-documentation gaps this cycle, consider a short mention of the cart tracker's local storage + auto-expiry in the merchant-facing privacy-policy template for completeness (optional, low priority given the existing 24h auto-purge). |

No Low/Medium/High/Critical findings. Every high-risk surface named in the
task brief (custom-table SQL, PII storage/logging, consent/GDPR, uninstall,
checkout-input hooks) was read directly and holds the repo's own invariants.

## What this audit does NOT cover

This is a read-only security/code-quality pass, not the full v3.7.0 release
gate: no `ci:strict`/integration run was executed in this pass (per the task
brief — not required for a docs-only audit commit; STATUS.md already records
green gates for every commit in this delta individually), no PCP-against-the-
built-ZIP run, no live-walk. Those remain separate release-gate steps before
tagging v3.7.0.
