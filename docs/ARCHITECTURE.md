# Architecture

High-level map of the Smaily Connect plugin for a developer new to the repo —
an upstream-merge reviewer, a support engineer, or a fresh agent. Accuracy over
exhaustiveness: where a topic has a deeper canonical doc, this file links to it
instead of restating it (duplicated docs drift).

Companion docs:

- [`DECISIONS.md`](DECISIONS.md) — *why* things are the way they are (F-numbered log).
- [`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md) — the outbound engine API (byte-synced with the engine repo).
- [`DATA_MODEL_GDPR.md`](DATA_MODEL_GDPR.md) — what personal data flows where, GDPR handling.
- [`API.md`](API.md) — the plugin's *own* surfaces (REST routes, hooks/filters, JS globals).
- [`DEVELOPER.md`](DEVELOPER.md) — dev environment, build/test, conventions.
- `/CLAUDE.md` (repo root) — operational knowledge and scars; the agent entry point.

---

## 1. What the plugin talks to

Two independent external destinations:

```
                          ┌──────────────────────────────┐
  WordPress /             │  Smaily contact API           │  email marketing:
  WooCommerce store  ───► │  (https://<sub>.sendsmaily.net)│  contacts, autoresponders,
        │                 └──────────────────────────────┘  RSS blocks, opt-ins
        │
        │                 ┌──────────────────────────────┐
        └───────────────► │  Smaily rec-engine            │  "Campaign Intelligence":
                          │  (per-connection base URL +   │  catalog / customers / orders /
                          │   API key from setup exchange)│  browse ingest, automations config
                          └──────────────────────────────┘
```

They are gated **independently** (see §2) and there is no double-sync conflict:
the contact path writes to the Smaily contact API, the ingest path writes to
the rec-engine.

## 2. Dual namespace coexistence + the two gates

The repo carries two coexisting PHP codebases:

| | Legacy | New (v3 rewrite) |
|---|---|---|
| Namespace | `Smaily_Connect\*` | `Smaily\Connect\*` |
| Files | `includes/*.class.php`, `integrations/` (woocommerce, cf7, elementor), `admin/settings.php` partials | `includes/{Bootstrap,Constants,…}.php` + subdirectories (`REST/`, `Smaily/`, `Settings/`, …) |
| Role | The upstream wordpress.org plugin, loaded verbatim (forms, widget, CF7/Elementor integrations) | Wizard, settings UI, contact-sync engine, abandoned-cart pipeline (PRO-1195), rec-engine ingest, storefront beacon |

Two independent feature gates decide which code is live:

- **`setup_completed`** (`smly_plus_setup_completed` option) — set when the
  email wizard's Step 6 Finish runs. Switches Smaily *contact sync* from the
  legacy path to the new one: before Finish the new `HookHandler` stays dormant
  and only the legacy subscriber-sync runs; after Finish
  `Integrations\WooCommerce\LegacyHookBridge` strips the legacy service's hooks
  from `$wp_filter` so the sync is never owned by both paths at once.
- **`is_connected()`** (`Settings\RecEngineSettings`) — true after the
  rec-engine setup-token exchange (wizard Step 4, optional). Gates ALL
  rec-engine ingest (catalog / customers / orders / browse / attribution),
  independent of the wizard state.

Scheduling note: legacy WP-Cron scheduling was deliberately removed (F3-53) —
recurring work is owned by Action Scheduler `smly_plus_*` / `smly_rec_*`
actions (§6). Do not re-add WP-Cron re-arming; see CLAUDE.md "Things NOT to do".

## 3. The ingest pipeline pattern (every rec-engine domain)

Established for catalog (F3-16), mirrored by customers (F3-19) and orders.
Every domain follows the same four-part shape:

```
WC hook ──► HookHandler ──► IngestQueue row ──► Flusher (AS job, 60 s) ──► engine batch POST
                 │           (event_type +          │
                 │            entity_id, EMPTY      └─ builds the wire payload FRESH at
                 │            payload, event_uuid)     send time via the PayloadBuilder
                 │
                 └─ e.g. CatalogHookHandler: save_post_product, stock changes,
                    wp_trash_post (→ in_stock=false), before_delete_post (→ §3b remove)
```

- **PayloadBuilder** (`Smaily\Connect\Smaily\RecEngine\*PayloadBuilder`) — WC
  object → contract wire shape. All datetimes via `Support\IsoDate` (Z-suffix);
  all product keys via `Support\SkuResolver` (always `woo-<canonical_id>`,
  never the merchant SKU — PRO-1224).
- **IngestQueue** (`wp_smly_rec_event_queue` table) — generic, event_type-scoped.
  Each flusher drains ONLY its own event types, so one flusher can never consume
  another's rows.
- **Flusher** — extends `AbstractD6Flusher`; drains a batch on its own Action
  Scheduler hook and applies the **D6 error contract** (F3-18): the batch
  endpoint returns `200 {ok, processed, deduplicated, errors:[{index, field,
  message}]}` with the invariant `processed + deduplicated + errors.length ==
  total`; `errors[].index` maps back to the batch rows (index-aligned), errored
  rows are marked `failed`, the rest `sent`. `CustomerFlusher` is the reference
  implementation. Exception: `CatalogRemoveFlusher` (§3b tombstones, PRO-1230)
  is NOT D6 — its response has no per-item `errors[]` and `not_found` counts as
  success — so it overrides the `apply_response()` seam.
- Every row stores its send-time exchange (`sent_payload` + `last_response`,
  F3-44) so the admin Event Log shows the real request/response; the
  Authorization header is never stored.

The Smaily contact side mirrors the same shape with its own queue
(`wp_smly_plus_event_queue`, `Smaily\EventQueue` + `Smaily\Flusher`) posting to
the Smaily contact API. Contact-specific rules: language only via
`Support\ContactLanguageResolver` (F3-47 — never `get_user_locale()` on the
contact path; omit `language` when unresolved), audience selection via
`ContactSyncMode`/`ContactAudience` (see [`CONTACT_SYNC_MODES.md`](CONTACT_SYNC_MODES.md)),
and workflow dispatch via `AutomationRouter`.

**Backfills** reuse the same queues/flushers: `Bootstrap::make_backfill_job()`
dispatches `contacts` (legacy Smaily), `products`, `customers`, `orders`
(`Smaily\RecEngine\Backfill\*BackfillJob`), driven by chained
single Action Scheduler actions and reported through `/backfill/status`.
`OrderBackfillJob` is HPOS-aware (reads `wc_orders` or `wp_posts` via
`table_spec()`).

## 4. Storefront pieces

Three separate mechanisms — do not conflate them (F3-46/F3-49):

1. **Browse beacon** — `dist/public/js/sc-runtime.js` (source
   `public/js/beacon.ts` + `beacon-core.ts`), enqueued by
   `Integrations\WooCommerce\StorefrontBeacon` with a
   `window.smailyConnectBeacon` boot blob (relay URL, cookie/URL-param names +
   TTLs from the engine config, page context). It POSTs §6 browse events to the
   plugin's **`/wp-json/smaily-connect/v1/relay`** proxy (`REST\BeaconEndpoint`
   — public route, but hard-gated: 404 unless connected + browse tracking on;
   event-type + field whitelists; transient rate limits). The browser-visible
   names are deliberately neutral (`sc-runtime`, `relay`) because "beacon"
   is on ad-block filter lists (F3-41). The client carries `session_id` and
   the opaque `smaily_visitor_token` (cold-start personalization) — deliberately
   NOT `smaily_rec_id`/email (data minimization, F3-49); `cart_add`/`cart_remove`
   send a proxy-internal `product_id` that `BeaconEndpoint` resolves server-side
   to the canonical `woo-<id>` sku via `Support\SkuResolver` before forwarding
   (PRO-1390 — the JS cart listener used to read WooCommerce's raw merchant-SKU
   markup attribute, the same collision class SkuResolver exists to avoid).
2. **Attribution capture** — two consent-independent paths write the SAME
   first-party cookies (`smaily_rec_id`, `smaily_rec_uid`, `smaily_rec_ctx`):
   server-side `Integrations\WooCommerce\LandingCapture` (`template_redirect`,
   F3-46) and, since PRO-1388, browser-side
   `RecEngineClient.captureUrlParams()` (called from `beacon-core.ts`'s
   `init()` *before* consent is resolved). The browser path exists because a
   full-page cache (confirmed on MiuMjau) serves most landing hits without
   ever running PHP, so `LandingCapture` never fires there — `captureUrlParams()`
   is the only thing left that can see the URL param; it writes only those
   three cookies and never sends anything, so it's safe to run consent-
   independently. Checkout stamps the cookies onto the order either way.
   **Consent-UNgated** by design (F3-46/PRO-1388) — first-party functional
   signal, independent of the beacon toggle/consent gate.
3. **Identity merge** — two mechanisms bind a browse identity to a known
   customer: `Integrations\WooCommerce\IdentityHookHandler` on `wp_login`
   calls the engine's `/identity/merge` (§7) to bind an anonymous browse
   session at login time (F3-27); and, since PRO-1389,
   `REST\BeaconEndpoint::attach_logged_in_identity()` validates the real WP
   `logged_in` auth cookie directly (not a REST nonce — the beacon sends
   none, and a page-embedded nonce would be stale under full-page caching)
   on every `/relay` batch and attaches `customer_email` to the outbound
   engine events for an *ongoing* logged-in session — closing the gap where a
   customer who never re-logs-in browsed forever with no identity attached.
   Gated by the same profiling check as the consent model below: an
   opted-out contact's events stay anonymous, never dropped. This is the one
   sanctioned server-side exception to F3-49 — the client itself still never
   sends `customer_email`.

**Consent model:** browse telemetry is **fail-closed on the WP Consent API**
(F3-50): the JS sends only when `window.wp_has_consent(category) === true`
(category `marketing` by default, filterable), else the `consentOverride` JS
hatch, else nothing — silently. The signal comes from the free `wp-consent-api`
companion plugin (which CMPs like CookieYes/Complianz register into); a missing
companion plugin means 0 events, surfaced by a `NotificationManager` admin
notice. No per-vendor CMP code (the 3.3.1 CookieYes parser was reverted).
Known-contact profiling opt-out is enforced by `Privacy\ProfilingConsent`
(beacon-side email gate; engine enforces the token path) with a My Account
opt-out UI (`Privacy\ProfilingConsentAccount`) and WP Privacy export/erase
integration (`Privacy\GdprHandler`). Deep dive: [`DATA_MODEL_GDPR.md`](DATA_MODEL_GDPR.md),
[`SMAILY_PROFILING_CONSENT_SPEC.md`](SMAILY_PROFILING_CONSENT_SPEC.md).

## 5. Multilingual layer

`Multilingual\DetectorFactory` picks an adapter (WPML / Polylang /
TranslatePress / site-locale fallback) behind `DetectorInterface`;
`Multilingual\Router` maps content to languages for catalog payloads and
automation routing. Contact language resolution is separate and
context-independent (`Support\ContactLanguageResolver`, F3-47). Design doc:
[`MULTILINGUAL_DESIGN.md`](MULTILINGUAL_DESIGN.md).

## 6. Background work — Action Scheduler

All recurring work runs on Action Scheduler (bundled via
`woocommerce/action-scheduler`), registered idempotently in
`Bootstrap::register_action_scheduler_jobs()`:

| Hook | Cadence | Work |
|---|---|---|
| `smly_plus_flush_event_queue` | 60 s | Smaily contact queue flush |
| `smly_plus_retry_failed_events` | 5 min | same callback, retry sweep |
| `smly_rec_flush_ingest` | 60 s | catalog ingest flush |
| `smly_rec_flush_catalog_remove` | 60 s | §3b tombstone flush |
| `smly_rec_flush_customers` | 60 s | customer ingest flush |
| `smly_rec_flush_orders` | 60 s | order ingest flush |
| `smly_plus_health_check` | 15 min | `NotificationManager` health sweep |
| `smly_plus_queue_janitor` | daily | prune old sent/failed queue rows |
| `smly_plus_contact_sync` | daily | contact-sync tick (F3-48 mode engine) |
| `smly_plus_abandoned_cart` | 15 min | abandonment sweep (`CartAbandonmentSweeper`, PRO-1195 — cutoff + backlog guard + enqueue) |
| `smly_plus_flush_cart_events` | 60 s | abandoned-cart event flush (`CartFlusher`) |

Backfills use chained `as_schedule_single_action` ticks
(`BackfillEndpoint::TICK_HOOK`) rather than a recurring action.

## 7. Storage — custom tables, migrations, options

Custom tables (created/evolved by `DB\Migrator`, plain SQL files in
`/migrations/*.sql` applied dbDelta-style — a schema change restates the full
`CREATE TABLE`; schema version in the `smly_plus_schema_version` option):

| Table | Purpose |
|---|---|
| `wp_smly_plus_event_queue` | Smaily contact-sync queue |
| `wp_smly_rec_event_queue` | rec-engine ingest queue (all domains, event_type-scoped) |
| `wp_smly_plus_backfill_job` | backfill job state (cursor, counts, `synced_count`) |
| `wp_smly_plus_automation_mapping` | wizard automation-trigger → Smaily workflow mapping |
| `wp_smly_plus_cart_session` | abandoned-cart session tracker (PRO-1195 — one row per WC session, guest carts included, scalar-JSON cart shape) |
| `wp_smly_rec_visitor` | anonymous-visitor merge tracker (created Phase 1; no runtime writer today — identity merge is engine-side, §4.3) |

Options: `smly_plus_*` (wizard/settings/credentials — the Smaily API password
is encrypted via the legacy `Smaily_Connect\Includes\Cypher`, deliberately not
re-implemented in the new namespace) and `smly_rec_*`
(`Settings\RecEngineSettings`: per-connection API key, base URL, tenant,
endpoints map, engine config). `uninstall.php` removes all of it — options
(both prefixes, LIKE-swept), the two tables above, and the AS actions
(`smly_plus_*` + the four rec-engine flush hooks) — a full irrecoverable
local delete (PRO-1337). No engine-side revoke call is made: the api_key
stays valid on the engine until rotated/revoked in the engine admin, and a
re-install needs a fresh setup token (per-connection keys since engine
migration 0036, so this can't affect any other store).

`DB\QueueJanitor` prunes `sent`/`failed` rows past retention (filterable).

## 8. Admin app — React wizard + settings

One Vite-built React 18 bundle (`admin/src/` → `dist/admin/admin.js`), mounted
by `admin/wizard.php` on two wp-admin pages (menu `smaily-connect-wizard`,
submenu `smaily-connect-settings`) with a `data-view` switch; wizard-first gate
bounces Settings to the wizard until `setup_completed`. The app talks ONLY to
the plugin's own REST routes (`smaily-connect/v1/*`, cookie + `wp_rest` nonce,
`manage_options`) — never directly to Smaily or the engine; the PHP endpoints
proxy outbound calls (`REST\EndpointRegistry` is the single route registry —
see [`API.md`](API.md)). Six wizard steps: Connect (Smaily credentials) →
Subscribers (sync mode + backfill) → WooCommerce (automations incl. engine-run
automations section) → Recommendations (engine setup-token exchange) →
Integrations → Done. The Settings view reuses the same step components as tabs
and adds the Event Log (queue observability, F3-44 request/response details,
retry).

Gutenberg blocks live separately in `blocks/` (`newsletter-signup`,
`checkout-optin`, `landingpage`) with their own `@wordpress/scripts` build.

## 9. i18n architecture

- PHP strings: standard gettext, text domain `smaily-connect`; committed
  sources are `languages/smaily-connect.pot` + `…-et.po` (the `.mo`/`.json`
  are gitignored build artifacts shipped in the ZIP).
- React admin strings: a thin runtime shim (`admin/src/lib/i18n.ts`) reads
  `window.wp.i18n` at call time (no bundled `@wordpress/i18n`); the bundle is
  enqueued with a `wp-i18n` dependency + `wp_set_script_translations()`.
- The build is NOT the stock `wp i18n` chain: `bin/build-i18n.sh` first
  esbuild-transpiles the TSX (make-pot can't parse it) and renames the
  script-translation JSON to the md5-of-script-path name WordPress actually
  loads. Details + gotchas: CLAUDE.md "React admin i18n".

## 10. Where to read next

- Wire shapes / endpoints map / cookie names: [`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md).
- Why any of the above is shaped this way: [`DECISIONS.md`](DECISIONS.md) (F-numbers referenced throughout).
- Known deferred work: [`/BACKLOG.md`](../BACKLOG.md); current state: [`/STATUS.md`](../STATUS.md).
- Audits (security, code quality, upstream comparison): [`audits/INDEX.md`](audits/INDEX.md).
