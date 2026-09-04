# Security + code-quality delta audit — v3.7.0 gate (post-PRO-1195 audit)

- **Date:** 2026-07-17
- **Baseline:** delta `af9b52f..HEAD` (HEAD `bc5d6bc`; 8 commits, 19 files,
  +1176/-47 lines)
- **Auditor:** Claude (Fable 5, clean-context agent — no involvement in
  writing the delta)
- **Trigger (re-audit policy):** the v3.7.0 gate (Linear PRO-1341) requires a
  delta pass since the 2026-07-13 audit's baseline, because the delta touches
  the GDPR surface (PRO-1343 exporter/eraser extension over a new local PII
  table). Below the 2,000-line threshold on its own, but security-sensitive
  (custom-table SQL added, PII export/erase code changed, a new boot-payload
  field) — audited on that basis, not the line count.
- **Scope:** full diff read file-by-file. High-risk surfaces swept: the WP
  Privacy exporter/eraser's new `smly_plus_cart_session` coverage (SQL
  correctness, data scoping, no PII leaks), the new `docsUrl` boot-payload
  field (source → PHP → JS → React render), the new admin React UI (clipboard
  copy button, injection via translated strings), the merchant docs-site HTML
  additions (external deps, EN/ET parity). Explicitly confirmed NOT touched:
  REST route surface, the public `/relay` beacon, auth/nonce paths, crypto,
  rec-engine ingest code.

## Verdict

**PASS. 0 Critical / 0 High / 0 Medium / 0 Low / 1 Info.** No release blocker
found in this delta.

## What the delta is

Eight commits, two unrelated workstreams:

1. **GDPR exporter/eraser widened to the local cart tracker** (`c022ebe`,
   `ab736ba`, `c0b1e86`, `740f9b2` — PRO-1194/PRO-1343/PRO-1405): the previous
   audit's Info finding #2 ("`smly_plus_cart_session` stores PII but isn't
   covered by `GdprHandler` or documented") is now closed. `GdprHandler`
   gained a `CartSessionStore` constructor dependency (wired once, in
   `Bootstrap::gdpr_handler()`-equivalent registration, via the existing
   `cart_session_store()` accessor); `export()`/`erase()` now also surface/
   delete cart-session rows matched by email (+ a defensive WP-user-id OR
   match). The exporter/eraser friendly name was renamed from "Smaily
   Campaign Intelligence data" to "Smaily Connect data" since it no longer
   only covers the rec-engine connection. `docs/DATA_MODEL_GDPR.md` gained a
   documented subsection for the tracker (fields, purpose, retention, now
   including "covered by GdprHandler"); the merchant privacy-policy template
   (EN+ET) gained an abandoned-cart bullet + retention sentence, was formally
   signed off by Erkki (entity = Sendsmaily OÜ, URL =
   `https://connect.smaily.com/privacy`, lawful-basis framing confirmed), and
   was ported verbatim into `docs/site/index.html` under a new `#pr-policy`
   section (EN+ET siblings, sidebar nav updated).
2. **PRO-1430 "How to add a Smaily signup form" guide** (`6e257a5`,
   `6a0024a`, `fdf07cf`, `bc5d6bc`): `EnvDetector::snapshot()` gained a
   `docsUrl` field (`Constants::docs_url()`, same filterable constant used
   elsewhere); threaded through `hydrate.ts`/`settings-reducer.ts`/
   `wizard-reducer.ts`/`WizardState.env.docsUrl` (optional, empty-string
   default, same gating convention as the pre-existing `env.rss`).
   `Step5Integrations.tsx` gained a `SignupFormGuide` block: five cards
   (Shortcode with a copy button, Gutenberg block, Elementor widget, Classic
   Widget, Contact Form 7), Elementor/CF7 branching reusing the pre-existing
   `state.env.elementorPresent`/`cf7Present` detection. `docs/site/index.html`
   gained matching how-to paragraphs (EN+ET) under the existing
   `#set-integrations` anchor. New msgids added to the `.pot`/`-et.po`.

## Security — surfaces checked

1. **`CartSessionStore::rows_for_privacy_request()` /
   `delete_rows_for_privacy_request()` / `privacy_request_where()`**
   (`includes/Smaily/CartSessionStore.php`): both new public methods route
   through one shared private WHERE builder, so the export lookup and the
   erase delete can never target different row sets. Every value goes
   through `$wpdb->prepare()` with matching placeholder/argument counts in
   all three branches (`email = %s OR user_id = %d`, `email = %s`,
   `user_id = %d`); the table name is `$wpdb->prefix` + a class constant,
   never request input — same pattern as every other method already on this
   class (audited clean 2026-07-13). No new `$wpdb->query()`/`get_results()`
   call in the delta takes an unprepared value.
2. **Export data scoping**: `GdprHandler::cart_session_export_items()`
   explicitly drops the internal `id` column and any null/empty field before
   building the export item — the subject only sees their own data, no
   internal row-id leak. Confirmed by
   `test_export_omits_the_id_column_and_empty_fields`.
3. **Erase scoping matches export scoping exactly**: both call through
   `privacy_request_where()`, so there is no way for erase to remove more or
   fewer rows than export would have surfaced — a real risk class (over-
   or under-broad erasure) closed by construction, not by convention.
4. **`GdprHandler::user_id_for()`**: a straightforward `get_user_by('email',
   …)` lookup, same idiom as the rest of the class; no new capability check
   needed (WP Privacy request handling itself is `manage_options`-gated by
   WordPress core, unchanged surface).
5. **Friendly-name rename** (`c0b1e86`): string-only change
   (`register_exporter()`/`register_eraser()`), no logic touched — verified
   the diff contains exactly the two `__()` calls + `.pot`/`.po` msgid/msgstr
   updates, nothing else.
6. **New boot-payload field `docsUrl`** (`EnvDetector::snapshot()` →
   `admin/wizard.php`'s existing `wp_json_encode( $boot )` inline-script
   emission, unchanged serialization mechanism): source is
   `Constants::docs_url()` — a hardcoded `https://smaily.com/connect-woo/`
   default, filterable only via the `smaily_connect_docs_url` PHP filter
   (i.e., only by code running in-process with plugin/theme-author trust,
   not by any request-controlled value). React renders it only as a JSX
   `href` attribute (auto-escaped, no `dangerouslySetInnerHTML`), gated
   `docsUrl !== ''`, always paired with `target="_blank" rel="noopener
   noreferrer"`. Confirmed no new echo path introduced in PHP.
7. **Clipboard-copy button** (`ShortcodeCopyButton` in
   `Step5Integrations.tsx`): copies a single hardcoded module constant
   (`SIGNUP_SHORTCODE = '[smaily_connect_newsletter_form]'`) via
   `navigator.clipboard.writeText()`, with a `document.createRange()`/
   `window.getSelection()` fallback for non-secure contexts — mirrors the
   pre-existing `RssFeedSection` copy pattern. No user- or engine-origin
   string ever reaches the clipboard call or the fallback selection; no
   injection surface.
8. **New translated strings** (Gutenberg/Elementor/Classic-Widget/CF7 card
   copy, EN + ET): rendered exclusively as React JSX text children — no
   `dangerouslySetInnerHTML`, no string concatenated into an `href`/`src`.
   Grepped the diff for any new `dangerouslySetInnerHTML` or raw
   `innerHTML` assignment: none.
9. **`docs/site/index.html` additions** (`#pr-policy` section +
   Gutenberg/Elementor/Widget/CF7 paragraphs): plain static HTML in the
   existing single-file page, no `<script src>`/`<link>` to any external
   host, no new JS behavior — consistent with the page's documented
   dependency-free constraint. EN/ET blocks are present as siblings for
   every new element (`data-lang="en"`/`data-lang="et"` pairs); spot-checked
   content parity (same structure, same placeholders, same cookie-name/
   retention-figure claims in both languages).
10. **Confirmed untouched**: `git diff --stat` shows no file under
    `includes/RestApi/`, `includes/Smaily/RecEngine/`, `public/js/beacon*`,
    or any crypto/auth path. No `register_rest_route(` call added or
    changed.

## Code quality — invariants checked against CLAUDE.md/DECISIONS

11. **Single wiring point preserved**: `GdprHandler`'s new `CartSessionStore`
    parameter is threaded through exactly one constructor call in
    `Bootstrap.php` via the pre-existing `cart_session_store()` accessor —
    the same shared-instance pattern `CartHookHandler` and the sweeper
    already use, not a second store instantiation.
12. **DRY match-condition**: `privacy_request_where()` is the single source
    of "what counts as this subject's rows" for both the export and erase
    paths, explicitly commented as existing to prevent drift between them —
    a direct, well-targeted response to the class of bug this repo's
    LESSONS file generally warns about (scoped read vs. scoped write
    diverging).
13. **Doc-currency discipline held**: `docs/DATA_MODEL_GDPR.md` and
    `docs/DECISIONS.md` were updated in the same commits that changed the
    behavior they describe (`c022ebe` docs-scope widening precedes/
    accompanies the code in `ab736ba`; the PRO-1194 sign-off decision landed
    in `docs/DECISIONS.md` in the same commit, `740f9b2`, that ported the
    template to the merchant docs site). `STATUS.md` carries a dated entry
    for every commit with test counts and gate results recorded — spot-
    checked against `git log`, matches.
14. **Previous audit's own follow-up item closed by this delta**: Info
    finding #2 from the 2026-07-13 report (no WP Privacy coverage for the cart
    tracker; the tracker undocumented in `DATA_MODEL_GDPR.md`/the privacy
    template) is the explicit subject of `c022ebe`/`ab736ba`/`740f9b2` —
    confirmed by re-reading the referenced sections, not just trusting the
    commit messages. (Finding #1, the bogus `uninstall.php` legacy-options
    entry, is unrelated to this delta and was fixed separately under
    PRO-1342, 2026-07-17.)
15. **Test coverage matches the claims**: `tests/Unit/Privacy/
    GdprHandlerTest.php` (new, 251 lines) isolates the cart-session logic
    with a fake-store double mirroring the existing `CartAbandonmentSweeper
    Test` pattern — export includes a row, `id`/empty/null columns omitted,
    WP-user-id lookup happens with the right arguments, erase reports
    `items_removed` truthfully in both directions. `tests/Integration/
    RecEngineGdprTest.php` gained three cases against the real DB (export
    surfaces a row, erase deletes it, erase matches via `user_id` when the
    `email` column is deliberately drifted — the defensive branch). `admin/
    src/components/steps/Step5Integrations.test.tsx` (new) covers the copy
    button, docs-link presence/absence, and all four host-plugin-presence
    branches. `EnvDetectorTest` gained a direct pin
    (`test_snapshot_carries_docs_url_from_constants`) that the emitted value
    is exactly `Constants::DOCS_URL`. Read the assertions directly, not just
    the test names.
16. **STATUS.md records green gates per code-touching commit**: PRO-1343
    (`ci:strict` + `sg docker -c "composer run test:integration"` both
    green), PRO-1405 (`ci:strict` green — no test asserted the old string
    literal, none needed changing), PRO-1430 (`ci:strict` green: PHPCS 0
    errors, PHPStan clean, PHPUnit unit 570, JS 244 + typecheck; integration
    green 151 tests/770 assertions, dev-site sandbox connection verified
    restored to "Smaily Connect test", not MiuMjau). The two docs-only
    commits (`c022ebe`, `740f9b2`) correctly record `ci:strict` as not
    required.

## Findings

| # | Severity | Finding | Disposition |
|---|---|---|---|
| 1 | Info | The new `docsUrl` boot-payload value is rendered as an anchor `href` without an explicit scheme allowlist (unlike the T2-audit-fixed pattern for *engine-origin* URLs, which added an `isHttpUrl()` guard because that string arrives over the network from a third party). Here the source is `Constants::docs_url()` — a hardcoded `https://` default, changeable only via a PHP filter that only code with in-process execution trust (another installed plugin/theme, or the site owner's own `functions.php`) can register. That's a materially different trust boundary than an engine HTTP response, so a scheme guard isn't warranted by the same logic that drove the T2 fix. Not a vulnerability at the current trust level. | Not fixed (no action needed at this trust level). Follow-up: if `docs_url()` is ever changed to accept a runtime/admin-settings-supplied value (rather than only a developer-registered filter), revisit and apply the same `isHttpUrl()`-style guard used for the T2 automations `docs` link. |

No Low/Medium/High/Critical findings. Every high-risk surface named in the
task brief (GDPR exporter/eraser correctness and scoping, the new
boot-payload field, the new React UI's clipboard handling, docs-site HTML)
was read directly and holds the repo's own invariants. Info finding #2 from
the 2026-07-13 audit is now resolved by this delta, not merely deferred;
finding #1 was fixed separately under PRO-1342.

## What this audit does NOT cover

Read-only security/code-quality pass, not the full v3.7.0 release gate: no
`ci:strict`/integration run was executed IN THIS PASS (not required —
STATUS.md already records green gates for every code-touching commit in this
delta individually, and the two docs-only commits correctly record no gate
run needed), no PCP-against-the-built-ZIP run, no live-walk. Those remain
separate release-gate steps before tagging v3.7.0.
