# Changelog

All notable changes to the Smaily Connect plugin. The `readme.txt` changelog
carries the same content in WordPress.org format; this file is the fuller
repo-side log.

## 3.5.0 — backfill progress: users walked vs contacts synced (F3-55) (2026-07-08)

Prike: "contact sync shows 30k contacts going to Smaily, we have 16k opt-ins." The wire
was correct (the F3-48 audience filter POSTs only the sync mode's audience) — but the
progress UI labelled the WALK count "contacts synced":

- **Migration 008:** cumulative `synced_count` on the backfill-job row — audience members
  handled (POSTed + already-fresh). The walk (`processed/total`) keeps driving
  percent/ETA; `synced` is the only number the UI may label "contacts synced".
- **`ContactAudience::count_audience()`:** the mode-aware SQL audience count, living next
  to `should_sync_user()`; an integration test asserts the two halves of the audience
  definition agree in every mode.
- **`/backfill/status`** carries `synced` + `audience_estimate` (contacts only; the
  estimate is computed only on non-running polls).
- **UI (wizard + Settings):** pre-start hint "about N of them will be synced to Smaily as
  contacts" (shown only when the mode narrows the audience); running "Checked X of Y
  users — Z contacts synced"; done "Done — Z contacts synced (X users checked)". The
  wizard Done summary uses the synced count. Estonian translations included.

No data-flow change — only honest reporting. Details: DECISIONS F3-55.

## 3.4.3 — abandoned-cart status option: normalized shape + router-first dispatch (F3-54) (2026-07-08)

The REAL Prike fatal (client-dev correction to F3-53's diagnosis — the crash was at the
option guard, not the cart-item loop):

- **The writer↔reader seam fixed:** the new Settings/wizard wrote
  `smaily_connect_abandoned_cart_status` as a bare boolean (WP stores `'1'`/`''`) while
  the legacy cron pass, `Options::get_woocommerce_settings_from_db()` and (inverted)
  `EnvDetector` read it as an array — `$status['enabled']` on the stored string is a
  PHP 8 TypeError every 15 minutes, and toggling the feature off just wrote the other
  string. Now: `Options::abandoned_cart_status()` normalizes every shape in one place,
  all consumers read through it (already-corrupted stores heal automatically), and the
  Settings writer produces the array shape.
- **Abandoned-cart sends are router-first:** the legacy email pass resolves the workflow
  through `AutomationRouter` (the wizard's automation-mapping row; multilingual modes,
  the F3-48 force_opt_in policy, F3-44 exchange capture), falling back to the legacy
  `autoresponder_id` on pre-wizard stores — which the Settings save now PRESERVES
  instead of destroying. Enabled with neither source logs one line per pass; carts stay
  pending until a workflow is mapped.
- **Hydrate fix:** a disabled legacy array no longer `(bool)`-casts to "enabled" in the
  wizard.
- Tests: +6 unit (normalizer), +5 integration (`AbandonedCartSettingsSeamTest` — the
  REAL writer and REAL reader in one scenario; the old guard test seeded the option
  itself and structurally couldn't see the seam).

Details: DECISIONS F3-54, LESSONS §2.19.

## 3.4.2 — abandoned-cart hardening + legacy WP-Cron removal (F3-53) (2026-07-08)

Fixes from the Prike incident (new module installed over the old one, no in-place
upgrade — a PHP 8 fatal loop on the abandoned-cart tick + resurrected legacy WP-Cron):

- **Poison-row hardening (legacy abandoned-cart email pass):** `cart_content` rows an
  older/foreign plugin version wrote can deserialize to non-array values or string
  items; the unguarded `$cart_item['product_id']` read was a PHP 8 fatal that aborted
  the WHOLE pass every 15 minutes forever (the bad cart stayed `mail_sent NULL`).
  Non-array cart_content is now terminal-marked + logged; non-array/keyless items are
  skipped; a per-cart `try/catch (Throwable)` backstop terminal-marks a throwing cart.
- **Legacy WP-Cron scheduler removed for good:** `Lifecycle::set_scheduled_actions()`
  and its call sites (activation, WooCommerce re-activation) are gone — the re-arm ran
  AFTER WPCronAuditor's one-time clear and resurrected a duplicate scheduler. A plugin
  update now heals polluted sites (`maybe_run_upgrade → Activation::run → auditor
  clear`) and nothing re-arms. `deactivate()`'s clears stay as residue defense.
- **Legacy daily subscriber mass-send made uninvocable:** the
  `smaily_connect_cron_sync_subscribers` callback registration is removed (F3-48.3's
  "orphaned" intent made structural) — a surviving legacy WP-Cron event could otherwise
  run the F3-47 language-clobber path daily.
- **Abandoned-cart `language` via ContactLanguageResolver:** the legacy helper's
  fallback is context-dependent and produced an empty/wrong language in cron runs
  (Smaily treats `''` as "wipe the contact's language") — the same F3-47 class at
  abandoned-cart scale. Now resolver-routed; the key is omitted when unresolved.

Details: DECISIONS F3-53 (+ addendum), LESSONS §2.18.

## 3.4.1 — engine-automations settings fixes from real-store testing (2026-07-07)

React-admin-only fixes (T2.4): store-global language mode for automation rows, cooldown
input typing UX, empty test-address warning, human-readable connection-failure messages,
English recipe support (`recipe_en`). Contract synced to v1.2.0. See readme.txt 3.4.1
and DECISIONS F3-52 (addendum).

## 3.4.0 — engine-run recommendation automations settings (T2) (2026-07-07)

New settings section (WooCommerce automations tab + wizard Step 3) for the engine-run
automation triggers: catalog-driven trigger list, per-language workflow binding on
multilingual stores, fail-closed defaults (off + test mode; going live is a separate
confirmed action). The plugin is a stateless proxy — the engine stores and validates
the config (DECISIONS F3-51/F3-52). Verified by the T2.3 live-walk (15/15) + a T2
security re-audit.

## 3.3.2 — browse consent back on the standard WP Consent API (2026-07-03)

Reverts 3.3.1's CookieYes-specific consent reading; consent comes from the WP Consent
API standard (which CookieYes supports via the companion plugin). Adds an admin notice
when browse tracking is on but no consent signal exists — the fix is installing the
free `wp-consent-api` plugin (DECISIONS F3-50, the MiuMjau 0-events root cause).

## 3.3.1 — CookieYes consent reading (2026-07-03; superseded by 3.3.2)

Vendor-specific CookieYes cookie parsing for browse consent — shipped on the wrong
assumption and reverted in 3.3.2 in favour of the standard.

## 3.3.0 — visitor token on browse events (F3-49) (2026-07-03)

Browse events carry the opaque `smaily_visitor_token` (cold-start personalization) when
the cookie is present. Deliberately NOT attribution: no rec id / email on browse events
(data minimization) — purchase attribution rides order signals.

## 3.2.1 — contact-sync mode selector UX (2026-07-01)

Contact-sync mode selector shown only when sync is enabled; "checkout opt-in only" mode
selectable only with the checkout checkbox on (F3-48.5a).

## 3.2.0 — contact-sync correctness: language resolver + sync modes (2026-06-30)

The F3-47/F3-48 line: `ContactLanguageResolver` as the single, cron-safe source of a
contact's Smaily `language` (the Prike site-locale clobber fix), audience/sync-mode
work (F3-48), and rec-attribution edge-case hardening on `LandingCapture`. See
DECISIONS F3-47/F3-48.

## 3.1.0 — recommendation attribution: server-side landing capture (2026-06-26)

- **Rec attribution (F3-46):** new `Integrations\WooCommerce\LandingCapture` captures the
  recommendation id an email rec link carries (`smaily_rec`, or `utm_content` guarded by
  `utm_source=smaily`) **server-side** on `template_redirect` into the first-party cookie the
  checkout already stamps onto the order — so the engine can attribute purchases to email
  recommendations. Fixes the pilot's empty attribution (374 orders / 30d, 0 `smaily_rec_id`):
  the only capture path used to be client-side JS gated behind browse-tracking + marketing
  consent + not-ad-blocked, so it never fired. Now decoupled from the browse beacon: captured
  unconditionally when the engine is connected (a first-party functional signal — a rec id +
  an opaque visitor token, no personal data on their own), gated by the
  `smaily_connect_capture_attribution` filter. Follows the contract's cookie model
  (`smaily_rec` → `smaily_rec_id`), not the brief's `smre_*`. Zero downstream change
  (`HookHandler` / `OrderPayloadBuilder` already read these cookies/meta). DECISIONS F3-46.

## 3.0.1 — internationalization + Estonian translation (2026-06-25)

- **i18n (W-7):** the React admin UI (~244 strings / 24 components) is now wrapped
  with a `wp.i18n` shim (`admin/src/lib/i18n.ts`); `wizard.php` enqueues `wp-i18n` +
  `wp_set_script_translations`. Reproducible catalog build via `bin/build-i18n.sh`
  (esbuild-transpiles `.tsx` so make-pot can read it; renames the catalog to WP's
  expected `…-et-464ceaab….json`).
- **l10n:** complete Estonian translation — all 275 previously-untranslated strings
  (admin UI + blocks + PHP), plurals + printf placeholders preserved; Playwright-verified
  full-wizard render.
- **W-5:** the admin-notice dismiss moved from an inline `<script>` to an enqueued
  `admin/js/notice-dismiss.js`.

No functional change. (These are the fork-side upstream-readiness items; the
`Update URI` clobber-guard stays until the actual wordpress.org merge.)

## 3.0.0 — first general-availability release (2026-06-25)

Graduates the `2.1.0-beta` line (beta.1–beta.10) to GA. Existing settings,
credentials and connections are preserved; an in-place update needs no re-import.

- **Sync:** WooCommerce → Smaily Campaign Intelligence — catalog, customers, orders
  and consent-gated browse tracking; backfill of existing data; Event Log with
  per-row retry and the stored request/response (F3-44); GDPR export / erase /
  opt-out; multilingual canonical SKU + `{lang:value}` payloads (CC.1–CC.4);
  order-sync data-loss fixes (F3-42/F3-43); trashed products kept as
  `in_stock=false` (F3-40); browse beacon renamed off ad-block lists (F3-41).
- **Hardening:** WordPress.org Plugin Check pass — ABSPATH guards across the legacy
  layer, `error_log` gated behind `WP_DEBUG` via `Support\DebugLog`, justified
  `phpcs:ignore`s for custom-table queries / internal exceptions; editor blocks
  moved to Block API v3 for the WordPress 7.0 iframe editor; `.zipignore` slimmed.
- The `Update URI` clobber-guard (F3-35) stays until the upstream merge.

## 2.1.0-beta.6 — engine default URL → intelligence.smaily.com (2026-06-16)

The recommendation engine moved to its production domain
**`https://intelligence.smaily.com`** (migrated from the earlier
`*.vercel.app` preview deploys). Updated every **static** reference to the
new host: the `Constants::SETUP_BASE_URL` default used for the first
setup-exchange, the connection-screen setup-URL placeholder, code-comment
examples, the API contract base/setup/`engine_base_url`/curl examples, and the
integration connectivity-test base. No contract, data, field, header
(`X-Engine-Version`), or setup-token-flow change — only the host.

The runtime path is unchanged and self-adapting: the engine returns its live
`engine_base_url` in the setup-exchange response and the plugin extracts the
host from whatever setup URL the merchant pastes, so existing installs keep
working without action. The old `*.vercel.app` alias still resolves and is
still accepted — the new URL is only a default, not a hard requirement.

## 2.1.0-beta.5 — product rename: Smaily Campaign Intelligence (2026-06-15)

Display-name change only — no contract, data, or behaviour change. The
recommendation-engine product is now **Smaily Campaign Intelligence** (short
form **Campaign Intelligence**) across all user-facing plugin surfaces: the
Settings / wizard tab and step, the connection + data-sync screens, the Step-6
summary, admin health notices, GDPR export/erasure data labels, the My Account
profiling section, the plugin header description, README and readme.txt.
Internal identifiers (option keys, REST routes, `recEngine` / `rec_engine`
symbols), the engine API contract, endpoint paths and headers are unchanged.
The Estonian translations (`.po` / `.pot` / `.mo`) were updated to match — the
brand name stays in English. Supersedes the short-lived "Smaily Intelligence
Engine" name (`docs/ENGINE_TEAM_product_name.md`).

## 2.1.0-beta.3 — catalog correctness: multilingual + non-product signal (2026-06-14)

Multilingual catalog correctness (DECISIONS F3-38; sub-PRs CC.1–CC.4). On
WPML/Polylang stores the recommendation catalog previously got **one row per
language translation** — duplicate products the engine can't merge, producing
language-mixed recommendations — and non-products (gift cards, donations,
language-switcher pseudo-products) leaked in. MiuMjau (the pilot) runs WPML +
WooCommerce Multilingual.

- **Translations collapse to one canonical product.** Every translation now maps
  to a single stable key (`wc-{canonical_id}`) across catalog, orders AND
  browse-tracking, so the engine sees one product, learns on one key, and never
  recommends the same product twice in different languages. The collapse happens
  in the catalog enumeration (backfill + live hooks); the synthetic key is
  canonicalized in the shared `SkuResolver`, so all three surfaces stay
  consistent. Variations are linked across languages via WooCommerce
  Multilingual. Deleting one translation re-syncs the canonical product instead
  of removing it while other languages remain (P4).
- **Localized content (model B).** `name` / `description` / `product_url` are
  sent per language as `{lang: value}` (description clamped to 500 chars per
  language); the engine localizes each customer's recommendations to their
  language. Single-language stores are unaffected — sent as plain strings, the
  prior behaviour.
- **Structural signal for non-product exclusion, not a filter.** Each product
  now sends `product_type` (incl. gift-card plugin types), `is_virtual` and
  `is_downloadable`, so the engine reliably classifies and excludes gift
  cards/donations without name-guessing. The plugin deliberately does NOT filter
  products itself — "is this recommendable?" is a business-model decision (a
  digital-goods store sells virtual/downloadable products), so the engine's
  `recommendable` flag owns the exclusion (DECISIONS F3-38).
- Multilingual detection runs through the existing `DetectorFactory` (WPML /
  Polylang / TranslatePress / single-language fallback), so the same wire shape
  is produced regardless of the i18n plugin. Proven against the real engine:
  `bin/walk-cc3-multilingual.cjs` 9/9 (the engine accepts the `{lang:value}`
  object form + the signal fields).

After upgrading on a multilingual store: re-run the catalog import — coordinate
with Smaily, the recommendation engine resets the product graph (catalog +
orders + recommendations + cadence + co-purchase + browse) for a clean canonical
re-sync.

## 2.1.0-beta.2 — pilot debugging (2026-06-12)

First fixes from real pilot data (DECISIONS F3-36): the pilot store has **no
SKUs at all** and its old orders reference deleted products — both broke
rec-engine sync, one of them silently.

- **SkuResolver — synthetic `wc-{id}` product keys** (`Support/SkuResolver.php`):
  the engine keys catalog, order items, AND browse events on `sku`, but WC
  doesn't require SKUs. One shared resolver now supplies the key on all three
  surfaces: the real SKU when set, else `wc-{product/variation id}`. Before:
  catalog silently dropped SKU-less units *before enqueue* (zero Event Log
  trace — the engine just never saw the store), orders built empty `items[]`
  and were D6-rejected on every retry, browse omitted `sku` and the engine
  rejected `product_view`/`cart_*` events.
- **Deleted-product orders stop failing**: current WC ZEROES the order items'
  product reference on permanent deletion (verified empirically on WC 10.7 —
  the initial assumption that the id survives was wrong; the integration test
  caught it), so those lines are unkeyable. Such orders are now terminal-
  skipped cleanly; on WC data where the reference survives, the line keys
  `wc-{id}` and ingests (unit-covered). Either way: no more red rows.
  (Non-catalog SKUs in orders/browse are engine-accepted — proven by the
  existing green live-walks, which always sent SKUs without catalog rows.)
- **Empty-`items[]` orders are terminal-skipped, not sent**: an order whose
  every line drops (deleted products, fee-only orders) can never pass the
  engine's `items` min-1 — OrderFlusher now skips it (third terminal-skip
  case) instead of send-and-fail-forever flooding the Event Log.
- **Mock hardened in the same pass** (LESSONS §2.3 discipline): the mock
  orders route now enforces `items` min 1 — it was the divergence that kept
  integration green while the live engine D6-failed.
- Catalog live-walk D6 lock-proof lever updated: empty-sku is no longer
  producible through the builder; the proof now uses an over-64-char SKU
  (contract §3 cap).
- **Variation stock changes now refresh the catalog**: variations fire
  `woocommerce_variation_set_stock_status`, not the parent-product hook —
  only the parent hook was registered, so a variation selling out never
  updated its catalog row's `in_stock` and the engine could keep
  recommending it. The variation hook is now wired to the same handler
  (identical signature); integration-tested through a real WC save.
- **Catalog attributes now wire term LABELS, not term ids** (engine ask
  2026-06-12, their #1 priority): a taxonomy attribute's `get_options()`
  returns term IDS (`pa_kaubamargid: ["398"]`) and a variation's value is
  the term SLUG — both were forwarded as-is, blocking the engine's brand /
  life_stage / pack_size rule derivation. The builder now resolves taxonomy
  options via `wc_get_product_terms(fields=names)` and variation slugs via
  `get_term_by`, with raw-value fallbacks. Custom attributes unchanged.
  NB: deployed stores need a catalog backfill re-run to refresh existing
  engine rows with labels.
- **Health-notice placement fixed on the plugin's own pages**: the admin
  wrapper now emits the `wp-header-end` marker, so WP core relocates admin
  notices above the React app (full-width, like every other admin page)
  instead of injecting them inside the React header next to the Settings
  tabs (core's fallback target is the first `h1` in `.wrap` — which was the
  React flex-header's own h1).
- **Abandoned-cart backlog guard + per-cart error handling** (F3-37): the
  legacy reminder pass now (1) only emails carts the customer touched within
  the last 24h (`smaily_connect_abandoned_cart_max_age_seconds` filter) —
  older carts are expired without emailing — and (2) logs-and-continues on a
  per-cart API failure instead of aborting the whole loop unmarked. Before,
  a dormant cron period accumulated an unbounded backlog that the first
  working tick after re-arming would mass-mail. (The pilot's day-1 mass
  email actually came from a third-party cart plugin's identical backlog
  drain when the plugin swap revived the site's dead cron — but our pipeline
  carried the same flaw, enabled, one working autoresponder away from the
  same flood.)

## 2.1.0-beta.1 — in development (feature-complete for pilot 2026-06-09; audited + hardened 2026-06-11)

> Version note: this release was developed as 2.0.0-beta.1 and renumbered to
> 2.1.0-beta.1 on 2026-06-12 — upstream (sendsmaily) released its own,
> unrelated 2.0.0 on wordpress.org (a 1.x-line WordPress-7.0 compatibility
> bump), and a lower-versioned fork install would have been offered (or
> auto-updated into) that package. The plugin also now declares an
> `Update URI` so wordpress.org updates never apply to the fork. See
> DECISIONS F3-35.

A major release built **alongside** the 1.x feature set, not replacing it:
existing installs upgrade in place and legacy behaviour continues until the new
setup wizard is completed.

### Added
- **Setup wizard** — guided 6-step first-run flow (Smaily credentials → subscriber
  sync → WooCommerce automations → recommendations → integrations → done) with a
  redesigned, mobile-first React admin.
- **Recommendation-engine integration** (optional, token-connected): durable,
  idempotent ingest of the product catalog, customers and orders (batched, with
  per-item engine error handling); one-click backfill of existing store data
  (cursor-resumable); anonymous-session → known-customer identity merge on login.
- **Browse tracking (opt-in, double-gated)** — a storefront beacon for product
  views / searches / cart and checkout events; off by default, fires only with
  shopper cookie consent (WP Consent API; escape-hatch filter + JS override for
  non-compatible consent plugins).
- **Event Log** (Settings tab) over both durable queues with filters, payload
  drill-down, per-row and bulk **Retry** of failed events.
- **Proactive health notices** — recurring health check raises dismissible admin
  notices for failure spikes, rec-engine downtime, and Smaily API downtime.
- **Privacy**: WordPress personal-data **export/erase** integration covering
  rec-engine data (HPOS-safe); shopper **profiling opt-out** (My Account toggle,
  opt-out model synced with Smaily; opted-out shoppers' browse events are
  dropped and never retroactively bound).
- **Queue janitor** — daily retention prune of terminal queue rows
  (sent 30d / failed 90d, filterable; pending rows never pruned).
- **Multilingual-aware routing** — Polylang / WPML / TranslatePress adapters for
  per-language Smaily accounts and automation workflows.
- **Product RSS feed URL builder** on the Integrations step/tab (wizard and
  Settings) — category / limit / ordering / tax-rate pickers with a live,
  copyable feed URL, prefilled from previously-saved 1.x RSS settings. The
  feed itself is the unchanged 1.x endpoint (all parameters travel in the
  URL), so existing template URLs keep working.
- Estonian translations for all new 2.0 strings.

### Changed
- Background work moved from WP-Cron to **Action Scheduler**.
- Stored API credentials re-encrypted with **AES-256-GCM** (versioned format,
  automatic migration on upgrade; the legacy CBC format remains readable).
- Version floors: WordPress 6.6+ (tested 7.0), WooCommerce 6.9+ (tested 10.7),
  PHP 8.0+.

### Fixed
- All applicable upstream 1.6.2-line fixes are included (empty abandoned carts,
  gender mapping, birthday parse failure, profile-data save, Elementor
  failure_url, checkout-optin asset path).
- A leftover diagnostic that logged the connection payload to `debug.log` on
  settings save was removed.

## 1.6.1

- Fixed discounted-price calculation in the RSS feed when using the tax-rate
  parameter.
- Unified the discount-percentage display in the RSS feed (at most one decimal,
  no trailing zeros).

## 1.6.0

- Added tax-rate support for RSS-feed product prices.
- Elementor widget: custom hidden fields on the subscription form.

## 1.5.1

- Added a label to the hidden-fields section of the subscription block settings.

## 1.5.0

- Customizable hidden fields on the Smaily subscription block form.

## 1.4.3

- Abandoned-cart records are deleted on more hooks, so completed purchases don't
  leave records lingering.

## 1.4.2

- Fixed Elementor integration assets being excluded from the plugin package.

## 1.4.1

- Abandoned-cart cutoff minimum corrected from 30 to 10 minutes.

## 1.4.0

- RSS-feed items show prices including taxes; Discount Rules for WooCommerce
  supported in the feed and abandoned-cart reminders.

## 1.3.3

- Elementor widget performance: fewer API calls during render.

## 1.3.2

- RSS-feed `pubDate` formatted per RFC 822.

## 1.3.1

- Invalid-credentials admin notice rendered closer to the input fields;
  autoresponder listing validation hardened.

## 1.3.0

- Contact Form 7 integration configurable per form.

## 1.2.4

- Admin notices rendered outside the form element.

## 1.2.3

- Text domain loaded in `init` (WordPress 6.7+ standard).

## 1.2.2

- RSS-feed product query: random ordering removed (empty-feed bug).

## 1.2.1

- Fixed abandoned-cart reminder emails not sending (query syntax error).

## 1.2.0

- New block for embedding Smaily Landing Pages.

## 1.1.0

- New Elementor widget for Smaily subscription forms.

## 1.0.0

- Combined Smaily for Contact Form 7, Smaily for WP, and Smaily for WooCommerce
  into a single plugin.
