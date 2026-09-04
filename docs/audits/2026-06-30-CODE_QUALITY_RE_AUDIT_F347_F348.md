# 2026-06-30 — Code-quality + correctness re-audit: F3-47 (language resolver) + F3-48 (contact-sync mode engine)

> **Type:** delta code-quality + correctness audit (not security; not PCP).
> **Scope:** commits `522b305..HEAD` on `main` (the F3-47 + F3-48 epics).
> **Auditor stance:** independent; report substantiated correctness bugs, edge-case
> gaps, and meaningful quality/maintainability issues only — trivial style is left
> to the green gates (PHPCS/PHPStan/ESLint/tsc). Each finding is verified against
> the code with file:line + a concrete failing scenario.
> **Disposition:** findings only — no production code or other docs were edited.

## Method

- Read the full diff (`git diff 522b305..HEAD`, 37 files, +3023/-58) and every
  changed source file.
- Read the design intent first: `docs/CONTACT_SYNC_MODES.md` (the preset matrix
  §2/§3, the architecture §7, the UI §9), `docs/CONSENT_STRATEGY_COMPARISON.md`,
  and `docs/DECISIONS.md` F3-47/F3-48 — then checked the code against it.
- Cross-checked the two Smaily endpoints the reconciler relies on against the
  hand-written API reference (`../re/docs/smaily-api/reference/action-log.md`,
  `.../subscribers.md`) for cursor/offset/segment semantics.
- Traced the WordPress meta-hook firing order (`update_metadata()` /
  `add_metadata()`) for the opt-in/opt-out transition handler.
- Traced the React state round-trip both ways: `state → buildTabPayload →
  SettingsEndpoint::save_subscribers` and `EnvDetector::saved_settings →
  hydrate/settings-reducer → state`.
- Grepped for callers of every new public method to find inert/dead surfaces.

## Findings

| # | Severity | File:line | Issue | Scenario | Suggested fix |
|---|---|---|---|---|---|
| F1 | **Medium (high-impact for preset 3)** | `includes/Smaily/ContactAudience.php:57`; `includes/Smaily/ContactSyncMode.php:82`; `includes/Integrations/WooCommerce/HookHandler.php:184-232` | `include_guests` / `should_sync_guest()` are **inert** — no production code enqueues a guest / checkout-opt-in `contact.sync`. `should_sync_guest()` is called only by a unit test; `include_guests()` is consumed only by that dead method. `on_checkout_order_processed()` never enqueues a contact for the billing email, and in `checkout_optin` mode `should_sync_user()` returns `false` for every account. The legacy `Subscriber_Synchronization` (which did sync checkout/guest opt-ins) is stripped by `LegacyHookBridge` after wizard finish. | Merchant selects **"Checkout opt-in only"** (preset 3, whose entire purpose is guest checkout opt-in); a guest ticks the box at checkout → **no contact ever reaches Smaily**. Likewise the new **"Also sync guest-order email addresses"** checkbox (shipped F3-48.5, persisted + validated end-to-end) changes nothing in presets 1/2. Diverges from `CONTACT_SYNC_MODES.md` §2/§3 (guests "the whole point" of preset 3; `include_guests` "include guest-order emails as contacts"). | Wire `on_checkout_order_processed()` to consult `ContactAudience::should_sync_guest()` + the checkout-checkbox state and enqueue a `contact.sync` (is_unsubscribed=0) for the billing email — **or** explicitly scope-defer preset 3, hide/disable the `include_guests` toggle, and mark the preset "coming soon" until wired, so no inert control ships. |
| F2 | **Medium** | `includes/Smaily/ContactReconciler.php:162` (the `update_user_meta` write) ↔ `includes/Integrations/WooCommerce/HookHandler.php:154-173,352-383` | **Reconciler write echoes back to Smaily.** `apply()` calls `update_user_meta('user_newsletter', …)`, which fires the `update_user_meta` action → `on_user_newsletter_meta_update` → `handle_newsletter_change` (consent mode, gate open) → `enqueue_consent_change` → a `contact.sync:{id}:consent` row with `is_unsubscribed` → flushed **back** to Smaily. The reconcile is meant to be a one-directional Smaily→WP mirror; this turns every mirrored change into a Smaily→WP→Smaily round-trip. | **Common case:** idempotent but redundant — a `rebaseline()` (or a large delta batch) mirroring N changes enqueues N extra upserts + N POSTs, defeating the "delta-first, light on shared hosting" intent (`CONTACT_SYNC_MODES.md` §7). **Race case (consent-correctness):** if the contact's consent changes again in Smaily between the reconcile read and the echo flush (≥60s flush window), the stale echo overwrites Smaily's newer state (self-heals on the next daily reconcile, but transiently wrong). Not covered by tests — `ContactReconcilerTest` stubs `update_user_meta`, so the hook chain never runs. | Suppress the transition handler during reconciler writes: `remove_action`/`add_action` around the write, a re-entrancy guard flag the handler checks, or a write path that doesn't fire `update_user_meta` (e.g. set a "reconciling" static the handler short-circuits on). Add a regression test that runs the real hook + asserts no consent row is enqueued. |
| F3 | Low (latent — `rebaseline()` is unwired) | `includes/Smaily/ContactReconciler.php:109-138`; `includes/Smaily/Client.php:235-252` | `rebaseline()` calls `list_contacts()` which sends `list => 1`, assuming **segment 1 == the full subscriber base**. The Smaily API (`GET /api/contact.php?list={segment_id}`, `subscribers.md:159`) treats `list` as a **required segment ID**, not "all contacts"; segment 1 being "everyone" is an unverified wire assumption (the project's own scar §"don't assume a wire shape — live-probe it"). The page-index `offset` loop itself **is** correct (the API doc confirms `offset` is a 25k-record page index, increment by 1). | A future wiring of `rebaseline()` against a tenant where segment 1 is a specific/absent segment would re-baseline against the wrong (or empty) set, silently mis-mirroring consent. Currently latent: no production caller, no live-walk. | Before `rebaseline()` is wired to any automatic/admin path, live-probe which `list` argument returns the full base (or resolve the all-contacts segment id from `/api/segments`); add a one-curl check per the CC-8 "sync is not code-complete" rule. |
| F4 | Low (maintainability) | `admin/src/state/settings-reducer.ts` (`buildSettingsInitialState`, the `contactSyncMode` ternary) vs `admin/src/state/hydrate.ts` (`normalizeContactSyncMode`) | The Settings init path coerces the mode by checking only `legitimate_interest`/`checkout_optin`, else `DEFAULT_CONTACT_SYNC_MODE`. It is correct **only because** `DEFAULT === 'consent'`; the wizard path (`hydrate.ts`) uses a proper 3-value `normalizeContactSyncMode()`. Two coercions for one concept, one of them fragile. | If `DEFAULT_CONTACT_SYNC_MODE` ever changes from `consent`, a stored/boot `consent` value would be silently coerced to the new default in Settings (not the wizard) — a subtle, hard-to-spot regression. No current functional bug. | Reuse one shared `normalizeContactSyncMode()` (check all three valid values) in both `hydrate.ts` and `settings-reducer.ts`. |
| F5 | Low (release gate, known/deferred) | `admin/src/components/steps/Step2Subscribers.tsx` (the ~9 new `__()` strings) vs `languages/smaily-connect.pot` / `…-et.po` | The new admin strings ("Contact sync mode", "Subscribers only (consent)", "Force opt-in on automation triggers", "Also sync guest-order email addresses", the warning banners, …) are **not** in the `.pot` or `-et.po` (last regen 2026-06-25, before this work). | A release cut now ships these English-only on an ET site, and the `.pot` is stale. Flagged as a packaging step in `CLAUDE.md`/DECISIONS (`bin/build-i18n.sh`), but currently outstanding — it is a hard pre-condition of the release gate, not optional. | Run `bin/build-i18n.sh` (regenerate `.pot` + update `-et.po` + ET translation) before any ZIP, per the release checklist. |

### Notes on F1 (the headline divergence)

The `include_guests → should_sync_guest()` chain was added by F3-48 as if to gate
guest sync, and the `includeGuests` checkbox was shipped in the UI (F3-48.5), but
nothing in the live sync path ever calls it. Verified by grep across the whole
repo: the only references to `should_sync_guest()` are its definition and one unit
test (`tests/Unit/Smaily/ContactAudienceTest.php:74`); `include_guests()` is
referenced only by `should_sync_guest()` and the `SettingsEndpoint` write. Preset 1
(Prike, legitimate interest, account-based) — the imminent cutover — is unaffected,
which is likely why this slipped: the only preset exercised end-to-end doesn't use
guests. But the toggle and preset 3 are both currently non-functional.

## What I checked and found clean

- **Meta-transition old-value read (update vs add routing).** `update_user_meta`
  fires inside `update_metadata()` **before** the DB write and the cache
  invalidation, so `get_user_meta()` in `on_user_newsletter_meta_update` returns the
  OLD value — correct. `add_user_meta` covers the first-ever set (WP routes a
  never-seen key through `add_metadata()`), with `old = 0`. The `(int)` cast on both
  old and new neutralises the `'1' === 1` strict-compare edge so a no-op write fires
  no spurious consent event. No double-fire (update vs add are mutually exclusive).
- **The `:consent` dedupe really avoids being swallowed.** `enqueue_consent_change`
  uses entity id `{id}:consent` → a distinct `$seen` key from the data sync's `{id}`,
  AND `EventQueue::enqueue` is a plain `INSERT` (no entity-key collapsing,
  `EventQueue.php:65`) → a distinct DB row. FIFO `pending()` ordering keeps the final
  Smaily state correct regardless of which row flushes first (the data row omits
  `is_unsubscribed`, so it can't un-set the consent row's value — absent = preserve).
- **A routine sync never leaks `is_unsubscribed`.** `build_contact_payload` /
  `build_automation_payload` never set it; `Flusher::dispatch_contact_sync` forwards
  it only `if isset(...)` (`Flusher.php:182`); the backfill upsert never sets it.
- **Reconcile cursor advance + pagination bounds.** `since_seq_id` is exclusive
  ("after this sequence number", `action-log.md:27`), so the max-seq cursor advance
  is correct and gap-free; the `>= 10000` full-page heuristic matches the documented
  10k cap; `MAX_PAGES = 50` backstops a runaway. `rebaseline()`'s page-index offset
  increment matches the documented 25k-page pagination.
- **Flusher forwarding (F3-48.6 fix).** The live `contact.sync` path now forwards the
  top-level `language` (the silently-dropped F3-47 field) and `is_unsubscribed`
  alongside `email + fields`. Verified by `FlusherTest::test_contact_sync_forwards_
  language_and_is_unsubscribed`.
- **Language resolver (F3-47).** Priority chains (`for_user`: user-meta → latest order
  → multilingual default → site locale; `for_order`: order → customer meta → default
  → locale), short-code normalisation, omit-on-empty, and the active-language clamp
  (no-op on empty allowlist; the `smaily_connect_contact_language` filter runs AFTER
  the clamp, so an override is the last word) all match the design and are unit-proven
  with a mock detector. Context-independence (no `ICL_LANGUAGE_CODE`/`get_user_locale`)
  is preserved — the exact property the Prike `en`-leak needed.
- **AutomationRouter `force_opt_in` is mode-driven (F3-48.4).** `trigger_automation`
  now passes `ContactSyncMode::automation_force_opt_in()` (consent/checkout → always
  `false`; legitimate interest → `false` unless the advanced toggle is on), replacing
  the hard-coded `true`. Matches §6.
- **`should_start_refresh()` re-arm + `start(false)` non-clearing path.** running →
  skip (won't reset a live walk's cursor); completed-within-freshness → skip; else
  re-arm; null state → true. `start(false)` keeps the `_smaily_synced_at` markers
  (DELETE guarded by `$reset_freshness && $wpdb instanceof \wpdb`) while still resetting
  the job row, so the refresh re-syncs only stale users. Correct.
- **Audience gate per mode.** `should_sync_user`: checkout → false; legitimate →
  true; consent → opted-in only. Applied consistently in the live `HookHandler`
  (per event) and `BackfillJob::process_batch` (cursor still advances on an
  audience-skip, no stall). Default `consent` matches legacy's `user_newsletter=1`
  filter (no silent broadening on upgrade).
- **Mode validation + persistence round-trip.** `SettingsEndpoint::save_subscribers`
  sanitises with `sanitize_key` + `ContactSyncMode::is_valid_mode` + safe default;
  `EnvDetector::saved_settings` boots all three new fields; `buildTabPayload`,
  `action-to-tab`, `wizard-reducer` carry the three new actions. The wizard hydrate
  path (`hydrate.ts`) normalises robustly. (Settings path fragility is F4.)
- **A11y (design §9).** `Banner tone="warning"` renders `role="alert"`
  (`Banner.tsx:30`), matching the design's interrupt-AT requirement for the
  legitimate-interest warning. The radio-cards get an accessible name from the
  wrapping `<label>`; shared `name` forms an implicit radio group. (Minor: the
  mode card hand-rolls `<input type="radio">` rather than reusing the `Radio`
  primitive — justified by the card layout, not a finding.)
- **Orphaned legacy cron.** `on_contact_sync_tick` no longer fires
  `Cron::smaily_sync_subscribers`; the legacy callback is left registered but never
  invoked (dead, harmless — removed at upstream merge). Safe: nothing else triggers
  that hook name. `on_abandoned_cart_tick` is untouched.

## Severity-ranked summary

1. **F1 (Medium, high-impact for preset-3 stores):** `include_guests` /
   `should_sync_guest()` inert; preset 3 ("Checkout opt-in only") and the guest
   toggle are not implemented in the new sync path.
2. **F2 (Medium):** reconciler `update_user_meta` write echoes back to Smaily via the
   F3-48.6 transition handler — redundant upserts + a race-window consent overwrite.
3. **F3 (Low, latent):** `rebaseline()` assumes `list=1` == full base; unverified
   segment semantics; unwired/untested.
4. **F4 (Low):** Settings-path mode coercion inconsistent with the wizard path; fragile
   if the default changes.
5. **F5 (Low, release gate):** new admin strings missing from `.pot`/`-et.po`.
</content>
</invoke>
