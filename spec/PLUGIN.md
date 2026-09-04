# Smaily Connect — WordPress Plugin Spec

**Version**: 0.6 (3 clarifications from the review of the engine-side `PLUGIN_IMPLEMENTATION_WP.md` v1.0)
**Base repo**: `sendsmaily/smaily-wordpress-plugin` (fork → `erkki/smaily-wordpress-plugin`)
**Working title**: "Smaily Connect (BETA)" during development; "Smaily Connect" after the upstream merge
**Plugin slug**: `smaily-connect` (preserved from upstream)
**Plugin version**: `2.0.0-beta.1` during the BETA phase → `2.0.0` at the upstream merge (the major-version bump marks the architectural shift)
**Pilot scope**: 1 Pet-sector client, ~2000–5000 orders in history, ~100–500 page views/day
**Owner**: Erkki
**Related documents**:
- `RECENGINE_API_CONTRACT.md` v1.0 — the authoritative API contract (from the engine side)
- `PROJECT_PLAN.md` — the phased plan aimed at Code
- `STYLE_MAPPING.md` — Smaily design system → Tailwind config (best-guess Variant 3 choices)
- `SUGGESTION.md` — prototype handoff guide
- Rec-engine `CLAUDE.md` v1.2 — multi-tenant, contexts welcome/cart_abandoned/cross_sell/win_back/newsletter/anniversary

---

## 1. Purpose and scope

Extend the Smaily Connect plugin into a data source for the rec-engine without breaking any existing functionality. The plugin collects every signal from the store (orders, products, contacts, browsing, cart events) and sends them simultaneously to **two** destinations:

1. **Smaily API** (existing): contact sync, automation triggers for welcome / first_order / abandoned_cart events
2. **Rec-engine API** (new): all business events + browsing telemetry, which the engine learns from to make personalised recommendations

The client uses the wizard to choose which features to activate. Browsing is opt-in (off by default, GDPR-sensitive); everything else is opt-out.

**In the MVP:**

- 6-step onboarding wizard (Connect → Subscribers → WooCommerce → Recommendations → Integrations → Done)
- Multilingual mode selection (separate accounts / separate automations / one automation with branching)
- Initial backfill: contacts to Smaily + orders/products/customers to the rec-engine, progress indicator, re-runnable from settings
- Real-time event push to the rec-engine (orders, products, customers, cart events) — Action Scheduler queue + retry
- Client-side beacon for browsing events, batched mode with a 30s window by default, server-side proxy for security
- Identity merge with **three** triggers: WP login/register, checkout, email-link click (`smaily_vt` + `smaily_rec` + `smaily_ctx` URL parameters from rec-engine campaign links)
- Cookie-consent integration (WP Consent API + Cookiebot/Complianz/CookieYes detection)
- Welcome + first_order + abandoned_cart automation triggers (multilingual)
- Settings tabs that mirror the wizard — **the same React component renders in both contexts** (`inSettings` prop)
- Mode switching mid-life (for B→A, the old mappings move under "default")
- HPOS-compatible (`declare_compatibility`, `wc_get_order()` only, never SQL against `wp_posts`)
- GDPR endpoints: opt-out, delete, export + WP Privacy hooks integration
- Engine version compatibility check (`X-Engine-Version` header)
- Translations: ET + EN at minimum
- Passes the WordPress.org Plugin Check (PCP) green

**Explicitly out (v1.x backlog):**

- CF7 / Elementor form rec-engine events (currently only to Smaily)
- Redis queue, dedicated workers (Action Scheduler is enough at pilot volume)
- Self-service A/B testing for measuring rec-block performance (in the rec-engine itself)
- Smaily-side client-side embed (rec-block iframe in the store) — that comes later
- Migration tooling from other ESPs
- Per-product opt-out from browsing (e.g. sensitive categories)
- WP Network (multisite) network-wide activation
- Webhook back-channel (rec-engine → plugin push) — reserved, v2 priority

---

## 2. Relationship to the upstream plugin and fork strategy

**Rationale for the fork decision:** Smaily Connect has already passed the WordPress.org marketplace QA (security review, escape/sanitize, i18n setup). The existing code is a **valuable base**, not ballast. The CF7 and Elementor integrations work and are stable — there's no point rewriting them from scratch. In the longer term, the upstream merge is a **diff** on top of the existing plugin, not a full replacement.

**At the file level**: the fork keeps the entire existing file structure (`includes/`, `admin/`, `integrations/`, `blocks/`, `public/`) and the existing integration with Contact Form 7, Elementor, WooCommerce, and WordPress core. Placement of the new code:

- `includes/RecEngine/` — all rec-engine classes (Client, EventQueue, Backfill, Beacon, Identity, GDPR)
- `includes/Wizard/` — wizard controller, step detection, env detection
- `includes/Settings/` — settings page controller
- `includes/Multilingual/` — WPML/Polylang/TranslatePress/site_locale adapters
- `includes/Smaily/` — extends the existing Smaily API class: AutomationRouter (multilingual-aware), BackfillJob
- `admin/src/` — React bundle (Settings + Wizard, the same component with the inSettings prop)
- `public/js/beacon.js` — the client-side tracker (separate entry, does not depend on the admin bundle)
- `migrations/` — new DB migration files (see §8)

**Plugin header in the BETA phase**:
```
Plugin Name: Smaily Connect (BETA)
Description: ... (BETA: extended e-commerce sync and recommendations engine integration)
Version: 2.0.0-beta.1
```

**Version**: during the BETA phase `2.0.0-beta.1`, `2.0.0-beta.2`, etc. At the upstream merge, `2.0.0` stable. The current official plugin is `1.6.1`.

**Distribution during the BETA phase**: as a GitHub Release tarball (`smaily-connect-2.0.0-beta.1.zip`), manual install for the client. **No** WordPress.org listing before the upstream merge.

**Conflict detection removed**: because the plugin slug is preserved (`smaily-connect/smaily-connect.php`), BETA and stable cannot be active at the same time — installing the new one replaces the old. There's no need for the `is_plugin_active()` check that was in the original spec.

**Upstream merge plan** (post-BETA):
1. BETA runs at the pilot client for 1–2 months
2. Features deemed stable → PR onto `sendsmaily/smaily-wordpress-plugin`
3. The Smaily team reviews, requests changes
4. Merge → release `2.0.0` to the WordPress.org directory

---

## 3. Architecture overview

```
┌────────────────────────────────────────────────────────────────┐
│  WordPress + WooCommerce (HPOS)                                │
│                                                                │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │ Wizard /     │    │ Settings     │    │ Beacon JS    │      │
│  │ Admin UI     │    │ Tabs         │    │ (public)     │      │
│  │ (React)      │    │ (React,      │    │ sendBeacon   │      │
│  │              │    │ same comps)  │    │              │      │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘      │
│         │                   │                   │              │
│         ▼                   ▼                   │              │
│  ┌────────────────────────────────┐             │              │
│  │ Plugin Core                    │             │              │
│  │  - Connection Manager          │             │              │
│  │  - Multilingual Router         │             │              │
│  │  - Event Producer (hooks)      │             │              │
│  │  - Identity Merger             │             │              │
│  └─────┬──────────────┬───────────┘             │              │
│        │              │                         │              │
│        ▼              ▼                         ▼              │
│  ┌──────────┐    ┌──────────────┐         ┌──────────────┐     │
│  │ Smaily   │    │ Event Queue  │         │ Beacon       │     │
│  │ Client   │    │ (DB table)   │         │ Buffer       │     │
│  │          │    │              │         │ (transient)  │     │
│  └────┬─────┘    └──────┬───────┘         └──────┬───────┘     │
│       │                 │                        │             │
│       │          ┌──────▼────────────────────────▼─────┐       │
│       │          │ Action Scheduler                    │       │
│       │          │ (durable queue + cron)              │       │
│       │          └──────┬──────────────────────────────┘       │
│       │                 │                                      │
└───────┼─────────────────┼──────────────────────────────────────┘
        │                 │
        ▼                 ▼
┌─────────────────┐   ┌─────────────────────────────────────────┐
│ Smaily API      │   │ Rec-Engine API                          │
│ (subdomain.     │   │ (intelligence.smaily.com or similar)    │
│  sendsmaily.net)│   │ Bearer-token auth, multi-platform       │
└─────────────────┘   └─────────────────────────────────────────┘
```

**Data flows:**

- **Smaily side**: contact sync (cron + real-time), invoking automation triggers (welcome / first_order / abandoned_cart, multilingual-routed). Existing code + additions for first_order.
- **Rec-engine side — durable**: catalog, customers, orders events through the Action Scheduler queue. Retry until success, exponential backoff.
- **Rec-engine side — best-effort**: browse events through a server-side proxy + 30s batched buffer + Action Scheduler flush. Failed batches are dropped after 1–2 retries (the engine's ML tolerates 5–10% loss).
- **Identity merge**: an anonymous visitor gets a `smaily_anon_sid` cookie (UUID v4). Three triggers (login/checkout/email-link) send an `identity.merge` event; the rec-engine binds the history to the email.
- **Beacon security**: the JS `sendBeacon` sends only to the WP REST endpoint (`/wp-json/smaily-connect/v1/beacon`). The server-side PHP adds the Authorization header and proxies to the rec-engine. **The API key is NEVER in client-side code.**

---

## 4. Multilingual model

The plugin detects the installed languages (in order of preference: **WPML → Polylang → TranslatePress → site_locale fallback**). If more than one language is detected, an extra question is shown in wizard Step 1:

> **How is your Smaily setup organized for languages?**
> ○ Separate Smaily accounts per language *(Mode A)*
> ○ One account, separate automations per language *(Mode B)*
> ○ One account, one automation with language branching *(Mode C)*

Default: **Mode B** (the most typical).

**Mode A** — Step 1 expands: for each detected language, its own "Smaily subdomain + API user/pass" section + "Test connection" button. Plus a "Default account for contacts without language" choice. Each language gets its own `SmailyClient` instance, which the `MultilingualRouter` selects based on the contact's `language`.

**Mode B** — one credential set in Step 1. In Step 3, a per-language table is shown at each trigger event. The **default fallback** is chosen with a radio button on one of the language rows (not a separate "Default" row) — a prototype Variant 1 design choice.

**Mode C** — one credential, a single dropdown per trigger event. The plugin sends the `language` field along in the contact; the Smaily-side workflow does the conditional branching itself. The plugin takes no part in that branching logic.

**Single-language store** — the question is not shown; it defaults to the single-dropdown model (mechanically the Mode C null variant).

**Multilingual catalog to the rec-engine**: `name`, `description`, `product_url` are sent as per-language objects (`{"et": "...", "en": "..."}`) via the WPML/Polylang/TranslatePress APIs. An untranslated language is not sent — the rec-engine falls back to the default. A single-language store sends string format, backward-compatible.

**Mode switching** mid-life: Settings → Connection → "Change multilingual mode" button. On a switch:
- **B → A**: the existing credential set takes the "Default account" role; the client adds new accounts for the new languages. Automation mappings stay under the "Default" row until the client overwrites them.
- **A → B**: asks which credential set becomes the new "only" one. The others are archived (not deleted — if the client switches back, the credentials are still there). Automation mappings under the "Default" row stay.
- **B ↔ C**: simple. For C → B, all language rows get the same workflow_id that was the only one in C. For B → C, it asks which language row becomes the new "only" one.

---

## 5. Wizard step by step

### Step 1 — Connect

**Content:**
- Smaily subdomain + API user + API password (existing flow from upstream)
- "Test connection" button → calls the Smaily `/api/account/` validation endpoint
- An interstitial extra question (shown after the first credential set is validated): "How is your Smaily setup organized for languages?" (see §4) — only if there are multiple languages
- For Mode A: additional credential sets per language, each with its own test connection
- **Optional "Recommendations engine" section**: pasting a setup-token URL (**one-time URL flow**, see §8). The plugin POSTs the URL and gets back `tenant_id`, `api_key`, `engine_base_url`, `engine_version`, `config` (cookie names, etc.). It shows: "Connected to tenant: My Pet Shop ✓"
- Skippable — if the client doesn't add the rec-engine, Step 4 shows marketing content (the 4b variant)

**Field storage:** encrypted in the `wp_options` table (the same approach as Smaily Connect, see the existing `Smaily\Options` class)

---

### Step 2 — Subscribers

**Content:**
- Checkbox "Sync contacts to Smaily" (on by default)
- Selection of the fields to sync (a group of checkboxes): `first_name`, `last_name`, `phone`, `birthday`, `gender`, `customer_group`, `customer_id`, `first_registered`, `nickname`, `site_title`
- Checkbox "Show subscription checkbox during WordPress registration"
- Checkbox "Show subscription checkbox during WooCommerce checkout"
- Info block "Subscription form" — how to use the Gutenberg block and the `[smaily_subscription_form]` shortcode
- **Initial backfill panel**: "Import existing users (X users) to Smaily" + "Start backfill" button, progress bar, status (idle / running / completed / failed), "Last run: [timestamp]"

**For Mode A:** the backfill shows a language split — if a WP user's `language` meta is `et`, they go into the Estonian credential set's account, etc. The default account is for those who have no language.

**Backfill mechanics:** an Action Scheduler job, batch of 100 users / batch, 30s interval. The application marks each user in `_smaily_synced_at` meta. A re-run is idempotent — it updates only those whose meta is older than X days or missing.

---

### Step 3 — WooCommerce

**Content in three sections:**

**3a. Welcome email** (on creating a new contact)
- Checkbox "Send welcome email to new subscribers" (on by default)
- Trigger: `user_register`, `woocommerce_created_customer`, or a subscription-form submit
- Workflow selection per multilingual mode (see §4)

**3b. First order email**
- Checkbox "Send first-order email to first-time buyers" (on by default)
- Trigger: `woocommerce_order_status_completed` **when** the customer's purchase history before this order is 0 (check: `wc_get_customer_order_count($customer_id) === 1`)
- Workflow selection per multilingual mode

**3c. Abandoned cart**
- Checkbox "Send abandoned cart reminders" (on by default)
- Cutoff time (minutes, default 30, min 10 — existing) + workflow selection per multilingual mode
- The existing cron every 15 min is migrated to Action Scheduler for consistency

**Implementation note:** all three use the same internal API `MultilingualRouter::triggerAutomation($trigger, $contact_data, $additional_fields)`, which picks the right workflow_id and the right API credential set (in Mode A).

---

### Step 4 — Recommendations

**Two variants, depending on the Step 1 choice:**

**4a. When the rec-engine is connected:** sync status + browse opt-in

Connecting the rec-engine activates syncing for **all domains at once** — there
are **no per-domain on/off toggles** (3.9). The system decides: good
recommendations need products, customers, and orders together, so "choose what to
sync" is the wrong question (partial sync = a worse engine). What's shown:
- An informational note that products, customers, and orders sync automatically
  while connected (the engine learns from the joined data)
- A combined backfill panel for orders/customers/products — three progress bars,
  one "Start" button (seed existing history into the engine)
- **Checkbox "Track browsing behavior" (OFF by default)** + a note about the
  consent requirement. This is the **only toggle** in Step 4: browse tracking is
  opt-in (GDPR-sensitive) and is additionally gated by end-user consent (WP
  Consent API / CookieYes) on top of this merchant preference. Cart events
  (`cart_add` / `cart_remove`) ride the same browse beacon and the same consent
  gate — there is no separate cart toggle.
- Browse-event batching (30s batched mode by default)

The browse preference is **preserved across a disconnect / re-connect**:
`disconnect()` clears only the connection options (api_key, base_url, tenant,
endpoints, config), so re-connecting restores the toggle state the merchant last
chose.

**4b. When the rec-engine is not connected:** marketing content
- Hero: a rec-block screenshot from a Smaily email (a static asset in the plugin)
- Headline: "Personalised product recommendations in every email"
- A short explanation of 6 contexts: welcome / cart-abandoned / cross-sell / win-back / newsletter / anniversary
- A separate box with a baseline: "Pilot clients see 2-8× revenue from targeted product emails compared to generic newsletters"
- CTA: "Activate recommendations engine →" (link to the Smaily page or a contact form)
- "Already have an endpoint?" link → back to the rec-engine section of Step 1

**Backfill mechanics to the rec-engine:** an Action Scheduler durable queue, batch of 100 entities at a time, the rec-engine returns `processed: N`. The plugin advances the cursor. SKU is the primary identifier; products with an empty SKU are skipped + listed in an admin notice.

**Variable products**: each variant is sent as a separate product with its own SKU (not in a parent/children hierarchy) — the rec-engine's Bayesian shrinkage `lift_local` accounts for variants separately.

---

### Step 5 — Integrations

**Content** — informational only, not configuration:
- "Elementor: Smaily subscription form widget is available in Elementor editor. [Open Elementor →]"
- "Contact Form 7: Configure individual forms in Forms → [select form] → Smaily tab. [Open Forms →]"
- "Smaily Landing Pages: Embed via Gutenberg block. [Add new page →]"

Each line links to the corresponding WP admin view (`admin_url()`, staying in-window — not `target="_blank"`). In the design these are shown as "cards", not a dense list.

---

### Step 6 — Done

**Content:**
- Headline "You're all set"
- A summary of what was activated (live state reflection with ✓/○ indicators)
- "View Settings →" button
- "Open Smaily dashboard →" link (dynamic URL from the subdomain)
- "Open Recommendations dashboard →" link (when the rec-engine is connected, dynamic URL from the tenant info)
- A reference to the Event Log, where all sync events are visible

---

## 6. Settings tabs

**The same React component renders in both views** — a wizard step and a settings tab are **one component** with the difference of the `inSettings` prop. `Step1Connect` becomes the "Connection" tab in Settings without duplication. This is a critical architectural decision from the prototype discussion that guarantees a single source of truth for UI logic and automatic state synchronisation.

The tabs after the wizard:
1. **Connection** — Smaily credentials + rec-engine credentials + multilingual mode + "Change mode" button + "Re-run setup wizard" button
2. **Subscribers** — selection of fields to sync, subscription checkboxes, backfill re-run
3. **WooCommerce** — welcome / first_order / abandoned_cart workflow mappings + cutoff time
4. **Recommendations** — the browse-tracking toggle (the only Step-4 toggle) + backfill re-run per data type; products/customers/orders sync automatically while connected
5. **Integrations** — informational, the same as wizard Step 5
6. **Event Log** — a separate tab (see §13)

Each tab has a "Save changes" button that applies only to that tab's choices.

---

## 7. Data model (local DB)

All tables have the prefix `{$wpdb->prefix}smly_plus_` (Smaily side) and `{$wpdb->prefix}smly_rec_` (rec-engine side).

### `smly_plus_event_queue`
The Smaily-side event queue (contact sync, automation triggers).

```sql
CREATE TABLE {$prefix}smly_plus_event_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128),
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED DEFAULT 0,
  last_error TEXT,
  status ENUM('pending','sent','failed') DEFAULT 'pending',
  INDEX idx_status_created (status, created_at)
);
```

### `smly_rec_event_queue`
The rec-engine-side durable events (catalog, customers, orders). **NB**: browse events do **NOT** get written to this table — they go to a transient buffer, not the durable queue.

```sql
CREATE TABLE {$prefix}smly_rec_event_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128),
  event_uuid CHAR(36) NOT NULL,  -- per-event UUID for idempotency
  depends_on_event_id CHAR(36) NULL,  -- event_uuid of the previous event (see §11)
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED DEFAULT 0,
  max_attempts SMALLINT UNSIGNED DEFAULT 5,
  last_error TEXT,
  status ENUM('pending','sent','failed','blocked') DEFAULT 'pending',
  next_retry_at DATETIME,
  INDEX idx_status_retry (status, next_retry_at),
  INDEX idx_depends_on (depends_on_event_id),
  UNIQUE KEY uniq_event_uuid (event_uuid)
);
```

**Status `blocked`** marks an event whose `depends_on_event_id`-referenced event is not yet `sent`. Block lifecycle:
- Initially the event is inserted with `status='pending'`
- Before sending, the flush job checks the `depends_on_event_id` status
  - If the dependency is `sent` → the event is sent normally
  - If the dependency is `pending`/`blocked` → the event stays `pending`, continues on the next flush
  - If the dependency is `failed` → the event is marked `failed`, `last_error='dependency_failed'`

### `smly_plus_backfill_job`
Backfill state (contacts + rec-engine data types).

```sql
CREATE TABLE {$prefix}smly_plus_backfill_job (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_type VARCHAR(64) NOT NULL,
  target VARCHAR(64) NOT NULL,
  status ENUM('idle','running','completed','failed') DEFAULT 'idle',
  total_count INT UNSIGNED,
  processed_count INT UNSIGNED DEFAULT 0,
  cursor VARCHAR(255),
  started_at DATETIME,
  completed_at DATETIME,
  error_message TEXT,
  UNIQUE KEY uniq_type_target (job_type, target)
);
```

### `smly_plus_automation_mapping`
Multilingual-aware automation-workflow mapping.

```sql
CREATE TABLE {$prefix}smly_plus_automation_mapping (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_type ENUM('welcome','first_order','abandoned_cart') NOT NULL,
  language VARCHAR(10) NOT NULL,
  account_key VARCHAR(64) NOT NULL,
  workflow_id VARCHAR(128) NOT NULL,
  is_default_fallback BOOLEAN DEFAULT FALSE,
  UNIQUE KEY uniq_trigger_lang_account (trigger_type, language, account_key)
);
```

### `smly_rec_visitor`
Anonymous visitor → identified merge tracking.

```sql
CREATE TABLE {$prefix}smly_rec_visitor (
  visitor_id CHAR(36) PRIMARY KEY,
  email VARCHAR(255),
  identified_at DATETIME,
  identified_source ENUM('login','register','checkout','email_link'),
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  INDEX idx_email (email)
);
```

**Beacon buffer**: a transient table is **NOT** needed — we use the WordPress transient API (`set_transient`, `get_transient`) for the 30-second browse-event buffer. Action Scheduler flushes the transient content to the rec-engine every 30s and deletes the transient.

---

## 8. API contracts

### Smaily side (existing + additions)

**Existing** (Smaily Connect 1.6.1):
- `GET /api/account/` — connection validation
- `GET /api/autoresponder/` — workflow listing
- `POST /api/contact/` — single contact sync (upsert)
- `POST /api/autoresponder/{id}` — trigger automation

**Error handling** — the same policy as the rec-engine side below: 4xx is not retried (validation, auth, not-found); 429/5xx (and a transport error with no status) are retried with exponential backoff, honouring `Retry-After`, max 5 attempts. Implemented in `Smaily\RetryPolicy` and applied by the queue flushers; the `Client` itself never retries (see DECISIONS F3-10, PRO-1685). Exception: `TransactionalFlusher` bounds its retries by elapsed time instead (PRO-1519) — a pending row suppresses the customer's native WooCommerce email while it waits.

### Rec-engine side

**Authoritative contract**: see `RECENGINE_API_CONTRACT.md` v1.0. Below is a referenced summary for the plugin-side implementation.

**Auth flow — Setup-token URL paste:**

The client clicks "Setup new tenant" in the rec-engine admin UI → a one-time URL `{engine_base_url}/setup/{token}` is generated (24h TTL, one-time use) → the client copies the URL into the plugin's Step 1 setup field. The plugin extracts the token and calls:

```
POST {engine_base_url}/setup/exchange
Headers:
  Content-Type: application/json
  User-Agent: SmailyConnect/2.0.0-beta.1 (WordPress; WooCommerce)
Body:
{
  "setup_token": "abc123xyz",
  "plugin_info": {
    "name": "smaily-connect",
    "version": "2.0.0-beta.1",
    "platform": "wordpress",
    "platform_version": "6.4.2",
    "ecommerce_platform": "woocommerce",
    "ecommerce_platform_version": "8.5.1",
    "site_url": "https://shop.example.com"
  }
}
```

**Response 200 OK** contains (see API_CONTRACT §1 for the full version):
- `tenant_id`, `tenant_name`, `api_key`, `engine_base_url`, `engine_version`
- An `endpoints` object (full URLs for 9 endpoints)
- A `config` object: cookie names (4 names), TTLs, rate limits, supported_languages, URL-param names

**The plugin stores all of these values** in `wp_options`, encrypted (`autoload=false`). **Do not hardcode** any URL, cookie name, or URL-parameter name anywhere in the plugin code — always read from config.

**The engine base URL is variable**: the production engine is `https://intelligence.smaily.com`, but the plugin must not assume it. The plugin handles migration transparently — when the engine moves, the client does a new setup-token exchange and the config updates.

**Setup-URL override mechanism**: for the first setup call (when there's no `engine_base_url` in config yet) the plugin needs a starting URL. Default: `https://intelligence.smaily.com/setup/exchange` at the constant level in a `Constants` class. **Override mechanism via a filter**:

```php
// Filter: smaily_connect_setup_url
$setup_url = apply_filters(
    'smaily_connect_setup_url',
    'https://intelligence.smaily.com/setup/exchange'
);
```

For the production migration, Erkki updates the constant value with a one-line PR and releases a new plugin version. The client gets the plugin update and restarts the setup flow with the new URL. The filter also allows a **per-site override** (e.g. for a test environment).

**Auth header on every subsequent request**: `Authorization: Bearer {api_key}`.

**Engine version check**: every API response comes with an `X-Engine-Version: 1.0.0` header. The plugin knows its own `compatible_engine_version_range` (e.g. `>=1.0.0,<2.0.0`). Out of range: an admin notice "Plugin update available", but the plugin **does not refuse** to work (graceful degradation).

**Error handling**: see API_CONTRACT §"Error handling". 4xx is not retried (validation, auth, not_found); 429/5xx are retried with exponential backoff. Every error response contains a `request_id` — the plugin shows it in an admin notice for debugging.

**Rate limit**: 100 req/sec for catalog/customers/orders, 500 req/sec for browse, 10 req/min for setup-exchange. A 429 response has a `Retry-After` header — the plugin respects it, exponential backoff, max 5 attempts.

---

## 9. Event types

**Smaily-side events** (smly_plus_event_queue):

| Event type | WP hook | Target |
|------------|---------|--------|
| `contact.sync` | `user_register`, `profile_update`, `woocommerce_save_account_details` | `POST /api/contact/` |
| `automation.welcome` | `user_register`, subscription-form submit | `POST /api/autoresponder/{wf_id}` |
| `automation.first_order` | `woocommerce_order_status_completed` (when it's the first order) | `POST /api/autoresponder/{wf_id}` |
| `automation.abandoned_cart` | cron (15 min) | `POST /api/autoresponder/{wf_id}` |

**Rec-engine durable events** (smly_rec_event_queue):

| Event type | WP hook | Endpoint |
|------------|---------|----------|
| `catalog.upsert` | `save_post_product` (on create and change) | `/api/v1/ingest/catalog` |
| `catalog.delete` | `before_delete_post` (when it's a product) | `/api/v1/ingest/catalog` |
| `customer.upsert` | `woocommerce_created_customer`, `user_register`, `profile_update`, `woocommerce_save_account_details` | `/api/v1/ingest/customers` |
| `order.created` | `woocommerce_checkout_order_processed` (HPOS-aware) | `/api/v1/ingest/orders` |
| `order.updated` | `woocommerce_order_status_changed` | `/api/v1/ingest/orders` |
| `identity.merge` | synthetic (see §10) | `/api/v1/identity/merge` |

**Every rec-engine event contains** (in addition to the event-type-specific fields):
- `event_id` — UUID v4 for idempotency (the rec-engine dedupes within a 60-min window)
- `session_id` — the visitor's anonymous session ID (= `smaily_anon_sid` cookie). On **every** event, including `customer.upsert` and `order.created`, so the rec-engine can do retroactive session-to-customer binding

**Order-event attribution payload** (`order.created`, `order.updated`): the plugin reads **4 cookies** in the checkout hook and stores them **immediately into order meta** (in the `woocommerce_checkout_order_processed` hook). In later hooks (e.g. `woocommerce_order_status_completed` from the admin side) we read the data from order meta, not from the cookie — the cookies aren't available in those later contexts.

**Order-meta keys**:
- `_smaily_anon_session_id` (= `smaily_anon_sid` cookie at the time)
- `_smaily_visitor_token` (= `smaily_rec_uid` cookie at the time)
- `_smaily_rec_id` (= `smaily_rec_id` cookie at the time)
- `_smaily_rec_ctx` (= `smaily_rec_ctx` cookie at the time)

**The order-event payload** contains:
- `smaily_rec_id` (the last-touch rec-id the recommendation-click customer made)
- `smaily_visitor_token` (the visitor token obtained from an email click)
- `smaily_rec_ctx` (the last-touch context — welcome/cart_abandoned/etc.)
- `session_id` (the anonymous session ID)

The engine does attribution matching from these 4 fields — see API_CONTRACT §5 "Attribution flow".

**Identity-merge dependency**: to send an `identity.merge` event, a customer with the same email **must already exist** in the rec-engine (synced via `POST /api/v1/ingest/customers`). The plugin's event-queue mechanism must respect event ordering: customer.upsert **before** identity.merge for the same email. If customer.upsert fails, identity.merge **stays pending** until the former succeeds. See §11, the `event_dependency` mechanism.

**Browse events** (transient buffer → flush):

| Event type | Trigger (client-side JS) | Endpoint |
|------------|--------------------------|----------|
| `browse.page_view` | document load | `/api/v1/ingest/browse` |
| `browse.product_view` | product page detect | same |
| `browse.category_view` | category page detect | same |
| `browse.search` | search-result page | same |
| `browse.cart_add` | `add_to_cart` JS event | same |
| `browse.cart_remove` | `remove_from_cart` JS event | same |
| `browse.cart_view` | cart-page load | same |

Each browse event contains an `event_id` UUID for idempotency (the rec-engine dedupes within a 60-min window).

**Browse-event `source` field**: the plugin sends `source: "plugin_woo"` per the API_CONTRACT v1.0 §6 enum. This identifies the **technology platform** (WooCommerce), not a specific plugin implementation — the same logic as `plugin_shopify` (Shopify), `plugin_magento` (Magento) in the future. The plugin-side audit trail comes from the User-Agent header and the setup request's `plugin_info` object.

---

## 10. Cookies, identity, GDPR

### Cookie strategy

| Cookie | TTL | Purpose | HttpOnly | Secure | SameSite |
|--------|-----|---------|----------|--------|----------|
| `smaily_rec_uid` | 365 days | Visitor token (email-link click) | false | true | Lax |
| `smaily_anon_sid` | 30 days | Anonymous session ID (UUID v4) | false | true | Lax |
| `smaily_rec_id` | 30 days | Last-touch rec attribution | false | true | Lax |
| `smaily_rec_ctx` | 30 days | Last-touch context | false | true | Lax |
| `smly_btok` | 24h | Beacon HMAC token | false | true | Strict |

**Cookie names come from the setup-response `config` object** — do not hardcode.

**HttpOnly=false** is required because the JS beacon must read the cookies. Security is ensured by the server-side proxy pattern (§3).

**One persistent visitor_id** — returning with the same browser keeps `smaily_anon_sid` (30 days). A new browser/device → a new ID. Identity merge connects the previous ID's history with the email.

### Consent

- **WP Consent API** (WP 6.5+): `wp_set_consent`; the plugin registers in the `marketing` and `statistics` categories
- **Detect**: Cookiebot (`window.Cookiebot`), Complianz (`window.cmplz_*`), CookieYes (`window.getCkyConsent`)
- If consent is missing: the browsing beacon does not fire, and the `smaily_anon_sid` cookie is not set
- Backend server-side events (orders, server-side cart-add, customer) do **not depend** on consent — they are transactional
- If the client is identified (the email is known at checkout/login/email-link), browsing can continue if marketing consent is granted

### Identity-merge triggers

Three independent mechanisms:

1. **WP login / registration**: hooks `wp_login`, `user_register`, `woocommerce_created_customer`. The plugin reads the current visitor_id from the `smaily_anon_sid` cookie and sends an `identity.merge` event with the user's email and `source: 'login'` (or `'register'`).

2. **Checkout**: hook `woocommerce_checkout_order_processed`. The same logic, `source: 'checkout'`.

3. **Email link click** (`smaily_vt` URL parameter):
   - The rec-engine's campaign render adds query parameters to each link: `smaily_vt={signed_token}` (visitor token, JWT, containing `{ email, tenant_id, expires_at }`, 30-day TTL), `smaily_rec={rec_id}` (which recommendation was clicked), `smaily_ctx={context}` (welcome/cart_abandoned/etc.)
   - The plugin registers a WP `template_redirect` hook that detects the parameters from the URL
   - Verifies the HMAC signature (the rec-engine's pub-key, which the plugin gets in the setup-token exchange)
   - Decodes `email` from the payload
   - Checks the `smaily_rec_uid` cookie — if missing, sets a new one; if present, keeps it
   - Also sets the `smaily_rec_id` and `smaily_rec_ctx` cookies for last-touch attribution (30 days)
   - Sends an `identity.merge` event with `{ visitor_id, email, source: "email_link" }`
   - Strips `smaily_vt`, `smaily_rec`, `smaily_ctx` from the URL with `wp_safe_redirect` to a clean URL (browser history clean)
   - **NB**: `utm_source`, `utm_campaign` are left untouched in the URL (GA analytics uses them). Do NOT use `utm_content`, which would break GA A/B testing.

### GDPR endpoints

- **`DELETE /api/v1/customer/{email}`** — used from the WP `wp_privacy_personal_data_eraser_*` hooks
- **`GET /api/v1/customer/{email}/export`** — used from the WP `wp_privacy_personal_data_exporter_*` hooks; the response is added to the WP export ZIP
- **`POST /api/v1/customer/{email}/opt-out`** — used from a "My Account" → "Privacy" toggle "Don't use my data for recommendations"

---

## 11. Background jobs (Action Scheduler)

| Job slug | Frequency | Purpose |
|----------|-----------|---------|
| `smly_plus_flush_event_queue` | every 60s | Smaily-side queue flush |
| `smly_plus_retry_failed_events` | every 5 min | Re-try failed events (max 5 attempts) |
| `smly_plus_contact_sync` | every day | Daily contact sync to Smaily |
| `smly_plus_abandoned_cart` | every 15 min | Abandoned-cart trigger (migrated from the upstream WP-Cron) |
| `smly_plus_backfill_runner` | one-shot per job | Processing backfill batches |
| `smly_rec_flush_event_queue` | every 60s | Rec-engine durable queue flush |
| `smly_rec_flush_browse_buffer` | every 30s | Browse-event transient → rec-engine batch (up to 100) |
| `smly_rec_retry_failed_events` | every 5 min | Rec-engine durable retry, exponential backoff |
| `smly_rec_visitor_cleanup` | every day | Anonymous visitors > 365 days without an identify → deletion |

**Action Scheduler via composer** (`woocommerce/action-scheduler`), does **not depend** on WC being present. The plugin's `composer.json`:

```json
"require": {
  "php": ">=8.0",
  "woocommerce/action-scheduler": "^3.7"
}
```

### Deduplication within one window

To avoid duplication (e.g. `save_post_product` may fire several times in the same request — autosave + manual save), the plugin uses the Action Scheduler `as_next_scheduled_action` check:

```php
$hook_args = ['product_id' => $product_id];
if (as_next_scheduled_action('smly_rec_sync_catalog_product', $hook_args)) {
    return;  // Already queued, don't add duplicate
}
as_enqueue_async_action('smly_rec_sync_catalog_product', $hook_args, 'smaily-connect-catalog');
```

This is simpler than a custom dedupe table and works automatically — AS's own "scheduled" queue before the flush. For event types where multiple calls **are legitimate** (e.g. a `customer.upsert` for a profile + order at the same time), we use a separate `entity_id` logic (the same customer can get two different customer.upserts from `wp_login` and `woocommerce_checkout_order_processed`).

### Event-dependency mechanism

Some rec-engine events depend on previous events succeeding. The concrete case: an `identity.merge` event needs a `customer.upsert` event with the same email to have **previously** succeeded (the rec-engine returns 404 if the customer doesn't exist — see API_CONTRACT §7).

**Implementation in the `depends_on_event_id` column of `smly_rec_event_queue`** (see the §7 schema extension):

```sql
ALTER TABLE {$prefix}smly_rec_event_queue
  ADD COLUMN depends_on_event_id CHAR(36) NULL AFTER event_uuid,
  ADD INDEX idx_depends_on (depends_on_event_id);
```

**Flush logic**: `smly_rec_flush_event_queue` does not send an event whose `depends_on_event_id`-referenced event has `status != 'sent'`. When the dependency event eventually succeeds, the dependent event is released on the next flush. If the dependency event ultimately fails (max_attempts exhausted), the dependent event is marked `status='failed'` with `last_error='dependency_failed: {parent_event_id}'`.

**Use cases:**
- `identity.merge` → depends on `customer.upsert` with the same email
- `order.created` → depends on `customer.upsert` with the same email (if the customer is new, sync the customer first)
- `catalog.upsert` for variants → has no dependency (variants are sent independently)

**Plugin-side enqueue logic** (as pseudo-code):

```php
$customer_event_id = $event_queue->enqueue('customer.upsert', $email, $customer_payload);
$merge_event_id = $event_queue->enqueue(
    'identity.merge',
    $email,
    $merge_payload,
    depends_on: $customer_event_id
);
```

---

## 12. Backfill lifecycle

1. **Trigger**: the client clicks "Start backfill" in the wizard or settings
2. **Init**: the plugin creates a `backfill_job` row, status='running', `total_count` is computed:
   - Contacts: `count(get_users(['role__in' => ...]))`
   - Orders: `wc_get_orders(['return' => 'ids', 'limit' => -1])` count (HPOS-aware)
   - Products: `wp_count_posts('product')`
   - Customers: `count(get_users(['role' => 'customer']))`
3. **Schedule**: Action Scheduler runs the `smly_plus_backfill_runner` job
4. **Iteration**: each run takes the next 100 entities from the cursor, formats them, sends them to the corresponding endpoint. On success it updates `cursor` and `processed_count`. It schedules the next Action Scheduler iteration 30s later (to avoid the rate limit).
5. **Complete**: when the cursor reaches the end, status='completed', `completed_at` is filled
6. **Failure**: an API error → status='failed', `error_message` is filled. The client sees a "Retry" button.
7. **UI**: the WP REST endpoint `/wp-json/smaily-connect/v1/backfill/status?job_type=orders` returns JSON `{ status, processed, total, percent, eta_seconds }`. The wizard polls every 5s, settings every 30s.

**Idempotency**: a re-run updates existing records in the rec-engine (upsert by `sku` for products, `email` for customers, `order_id` for orders). The same entity creates no duplicates.

**Products with a missing SKU**: skip + an admin notice "X products skipped, missing SKU. [View list]" — a `Tools → Smaily Connect → SKU report` link shows the full list.

---

## 13. Event Log view

A separate tab in the Settings view. Content:

- **Table**: the last 7 days of events (paginated, 50 rows/page)
- **Columns**: timestamp, event_type, entity_id, source (Smaily/rec_engine), status, attempts/max_attempts, last_error preview (truncated)
- **Filter**: per event-type, per status (success/failed/pending/retrying), per source
- **Single-row drill-down** (click a row → modal): the full payload JSON, the full last_error, retry history
- **"Retry now" button** for a manual attempt at failed/retrying events
- **"Export failed events as CSV"** for debugging
- **Sticky failure banner** at the top: "X failed events in last 24h" + "View only failed" link

**Data source**: SELECT `smly_plus_event_queue` UNION `smly_rec_event_queue`, ORDER BY created_at DESC. WP REST endpoint `/wp-json/smaily-connect/v1/events` paginated.

**Action Scheduler integration**: in addition to plugin events, we show plugin jobs from the AS table (filter `hook LIKE 'smly_plus_%' OR hook LIKE 'smly_rec_%'`). A separate tab or toggle.

---

## 13a. Admin notifications + email notifications

The plugin notifies the user (the WP admin) of problems on **three levels**:

1. **Event Log entry** — all notable events are logged, **always**
2. **Admin notice** — a message shown in the WP admin panel (dismissible or sticky)
3. **Email** — sent with `wp_mail()` to the admin email (`get_option('admin_email')` or custom)

The levels are **cumulative** — every admin notice is also in the Event Log, every email is also an admin notice. But not every event reaches every level.

### Notification severity levels

| Severity | Event Log | Admin Notice | Email |
|----------|-----------|--------------|-------|
| **`info`** | ✓ | ✗ | ✗ |
| **`warning`** | ✓ | ✓ (dismissible) | ✗ |
| **`error`** | ✓ | ✓ (sticky until resolved) | ✓ (opt-out possible by default) |
| **`critical`** | ✓ | ✓ (sticky) | ✓ (on by default, opt-out available) |

### Concrete notification cases (v1.0 base set)

| Event | Severity | Trigger |
|-------|----------|---------|
| Backfill batch failed (retry succeeded) | `info` | Action Scheduler retry job |
| Products skipped for a missing SKU | `warning` | Backfill / catalog-sync, when count > 0 |
| Engine version mismatch (minor) | `warning` | `X-Engine-Version` parse |
| Engine version mismatch (major) | `error` | `X-Engine-Version` parse — incompatibility |
| Backfill failed (max retry exhausted) | `error` | Action Scheduler final-failure |
| Rec-engine connection failed (5xx >1h) | `error` | Health-check cron job |
| Smaily connection failed (5xx >1h) | `error` | Health-check cron job |
| Failed events count >50 in 24h | `error` | Health-check cron job |
| API key revoked (401 + `api_key_revoked`) | `critical` | Every API response is monitored |
| Setup token expired | `critical` | Setup-token exchange request |
| Plugin upstream-merge available | `info` | In a later phase |

### Email throttling

To avoid spam:
- **The same event-type + entity_id** is not re-sent **within 24h**
- **Critical** events are not throttled — they are always sent, because the user must know immediately
- Throttle table: a `wp_options` singleton (`smly_notification_throttle`), key = `event_type:entity_id`, value = `last_sent_at`

### Email template and language

- **Template**: in bundled plugin files `templates/email/*.php`. Plain-text + HTML version (a multipart email)
- **Content**: severity icon, event title, context info, "View in Event Log →" link
- **Language**: the user's WP admin language (`get_user_locale()`), fallback site_locale, fallback EN
- **From**: `wordpress@{site_domain}` (WP default) or custom (in Settings)
- **Subject**: in the format `[{site_name}] Smaily Connect: {event_title}`

### Settings UI

**Settings → Notifications** subpanel (part of the Connection tab or a separate tab):

- **Toggle**: "Send email notifications for critical events" — on by default
- **Toggle**: "Send email notifications for errors" — on by default
- **Toggle**: "Send email notifications for warnings" — off by default
- **Input**: "Notification email address" — defaults to `get_option('admin_email')`, with an override option
- **"Send test email" button** — to verify that emails arrive
- **Info block**: "Notifications are also visible in [Event Log →] regardless of email settings"

### Implementation

A `NotificationManager` class in the `includes/Notifications/` directory:

```php
class NotificationManager {
    public function notify(string $event_type, string $severity, array $context = []): void;
    private function logToEventLog(...): void;
    private function showAdminNotice(...): void;
    private function sendEmailIfAppropriate(...): void;
    private function isThrottled(string $event_type, string $entity_id): bool;
}
```

Usage example:
```php
$notifications->notify(
    'engine_connection_failed',
    'error',
    [
        'entity_id' => 'rec_engine',
        'message' => 'Rec-engine has been unreachable for over 1 hour',
        'request_id' => 'req_8f3k...',
        'failed_events_count' => 47,
    ]
);
```

**Admin notice rendering**: uses the standard WP `add_action('admin_notices', ...)` hooks. Notices are persisted in `wp_options` (`smly_active_notices`) — sticky ones stay shown until a manual dismiss or an automatic resolution (e.g. the next successful API call clears the "connection failed" notice).

**Dismiss handling**: dismissible notices have a `data-notice-id` attribute; JS with `wp.ajax` POSTs the dismiss to a WP REST endpoint. It does not come back.

### Future expansion (v1.x backlog)

- **In-app notification center** (a bell icon in the admin bar)
- **Slack/Discord webhook** integration (with the same severity logic)
- **Per-event-type custom-template** support (the client can change the email template themselves)
- **Email digest** (collect all info-level events of a day, send in the morning)

---

## 14. WordPress.org marketplace quality requirements

The plugin must pass the WordPress Plugin Check (PCP) tool green before the upstream merge. The concrete obligations:

**Scripts & styles:**
- All enqueues via `wp_enqueue_script` / `wp_enqueue_style` (not inline `<script>` tags)
- **No CDN-sourced resources** (fonts, JS libs — all bundled inside the plugin)
- Geist/Inter fonts are loaded from the plugin's `assets/fonts/` directory by WP

**SQL & data:**
- No direct `$wpdb->query("SELECT ... FROM wp_posts ...")` SQL
- HPOS: `wc_get_order()`, `wc_get_orders()`, not `wp_posts` queries
- `declare_compatibility('custom_order_tables', __FILE__, true)`
- All user input sanitized (`sanitize_text_field`, `sanitize_email`, `wp_kses_post`)
- All output escaped (`esc_html`, `esc_attr`, `esc_url`)

**Security:**
- Capability checks (`current_user_can('manage_options')`) before admin actions
- Nonces on all admin forms and AJAX calls (`wp_create_nonce`, `check_admin_referer`)
- API key encrypted in `wp_options` storage
- The beacon key is NEVER in client-side code (server-side proxy)

**i18n:**
- Text domain `smaily-connect` declared in the plugin header
- `load_plugin_textdomain()` called in the `plugins_loaded` hook
- All UI text uses `__()`, `_e()`, `_n()`
- In the React bundle, `wp.i18n.__()` usage, with `wp-i18n` as a dependency
- `.pot` file generated with `wp i18n make-pot` in the build
- Translations: ET + EN at minimum in v1

**Plugin lifecycle:**
- `register_activation_hook`: DB migrations, default options
- `register_deactivation_hook`: clear crons (Action Scheduler keeps queued jobs)
- `uninstall.php`: a "Remove all plugin data on uninstall" toggle in Settings (off by default)
  - Toggle on: deletes all plugin DB tables, options, user-meta, AS jobs
  - Toggle off: keeps the data

**Errors:**
- No PHP warning/notice in a `WP_DEBUG=true` environment
- Logging via `error_log`, not `var_dump` / `print_r`

**Multi-site:**
- Per-site activation; network-wide is not in the MVP
- An `is_multisite()` check where needed

---

## 15. Pet pilot-client acceptance criteria

An end-to-end test that must work before the client goes live:

1. The plugin is activated on a clean WP + WooCommerce install. Detect: languages (ET + EN), Elementor present, CF7 present. HPOS enabled.
2. Wizard Step 1 — I enter the Smaily credentials → test connection ✓. The multilingual question opens → I choose **Mode B**. I enter the rec-engine setup-token URL → test connection ✓ → it shows "Connected to tenant: [Pet Shop Name]".
3. Step 2 — field choices accepted. I start the backfill — 2000–5000 users sync to Smaily within 5–25 min, progress live.
4. Step 3 — the Welcome / First order / Abandoned cart sections show the Mode B table (ET + EN rows + Default-fallback radio). I choose a workflow for each row. I save.
5. Step 4 — products/customers/orders sync automatically (no per-domain toggles), browsing off by default. I start all 3 backfills (orders, customers, products) — all reach the rec-engine within 10–30 min, progress live.
6. Step 5 — the info cards are shown, the links work in-window (not target=_blank).
7. Step 6 — the summary shows all activated features.
8. **Live test 1 — Welcome**: I create a new WP user → in the ET context → the ET welcome workflow fires in Smaily. Repeat with EN.
9. **Live test 2 — First order**: an existing user's first order → the first_order workflow fires. A second order from the same user → does not fire.
10. **Live test 3 — Abandoned cart**: I add products to the cart, leave, 30 min later → cart.item_added events reach the rec-engine + the abandoned_cart workflow fires in Smaily.
11. **Live test 4 — Browsing (activated separately)**: I view 5 products anonymously → `browse.product_view` events reach the rec-engine under `visitor_id` within the 30s window. I log in → an `identity.merge` event is sent → the rec-engine connects the history to the email.
12. **Live test 5 — Email-link merge (cross-device)**: I send from the rec-engine a campaign with `smaily_vt`+`smaily_rec`+`smaily_ctx`-tokened links. I open the email in another browser (new cookie), click a link → `template_redirect` detects the parameters, decodes the email, sets all 4 cookies, sends `identity.merge` source='email_link'. The URL is cleaned. Subsequent browsing in that browser is automatically bound to the same email.
13. **Live test 6 — Product update**: I change a product's price → a `catalog.upsert` event reaches the rec-engine within 60s.
14. **Live test 7 — Mode change**: I switch B→A, add a separate Smaily account for ET → the old credential goes under "Default account" → the ET workflows use the new account, fallback default.
15. **Live test 8 — GDPR**: WP admin Tools → Erase Personal Data → email → confirm → `DELETE /api/v1/customer/{email}` is called → the rec-engine deletes the data.
16. **Live test 9 — Engine version mismatch**: simulate `X-Engine-Version: 2.0.0` → an admin notice is shown, the plugin keeps working.
17. **Failure test**: block the rec-engine endpoint (firewall) → events go to failed status → 5 retries × backoff → an admin notice in settings "Rec-engine connection failed, X events queued". Endpoint back → the flush continues.
18. **WP-CLI test**: `wp plugin check smaily-connect` → 0 warnings.
19. **Performance test**: a 5000-user backfill runs through in under 30 min without overloading the PHP-FPM workers.

---

## 16. Backlog (v1.x / v2)

- CF7 / Elementor form events to the rec-engine
- A/B testing for measuring rec-block performance
- Per-product opt-out from browsing (sensitive categories)
- Smaily-side client-side embed (rec-block iframe in the store)
- Migration tooling from other ESPs
- Redis queue + dedicated workers for large volume
- Sub-account / multi-store support under one plugin
- Webhook back-channel: rec-engine → plugin (sync.completed/failed, recs.updated, tenant.alert)
- WP Network (multisite) network-wide activation
- A native-plugin family for Shopify / Magento / PrestaShop — since the API is multi-platform agnostic, these come as separate plugins with the same API contract

---

## 17. Open questions (locked at v0.5, needed before v1.0)

1. **Geist vs Inter font** — STYLE_MAPPING.md assumes Inter (the most likely Smaily choice). If Smaily uses a different font, a switch is needed before Phase 2 starts. Decided based on Variant 3 (my estimates), needs confirmation in the pilot-client review phase.
2. **The exact hex values of the Smaily design system** — STYLE_MAPPING.md uses estimated values (the Variant 3 choice based on the logo `#E91E63` + a UI screenshot). Needs a check at the end of Phase 2 in the pilot-client review phase.
3. **Production engine_base_url** — the engine runs at `https://intelligence.smaily.com`. Code must still know that the URL is **variable** and that all references come from the setup response.
4. **Email notification opt-in default** — are critical-level notifications (API revoke, engine connection-down >1h) sent to the admin email by default, or must the client opt in? My lean: on by default for the critical level (the user must know when the plugin stops working), with an opt-out in Settings. See §13a.

## 18. Sync with RECENGINE_API_CONTRACT v1.0 (v0.4/v0.5 change log)

v0.3 → v0.4 changes:

- §0 Header: plugin version `2.0.0-beta.X` → `2.0.0` at the upstream merge (Erkki confirmed)
- §8 Rec-engine: the setup flow clarified per API_CONTRACT v1.0, incl. `plugin_info.name` = `smaily-connect`, the User-Agent string format
- §8 Engine_base_url: emphasized that the URL is variable (Vercel preview now, prod migration later) — all URLs from the setup response, not hardcoded
- §9 Event types: added the `event_id` UUID and `session_id` requirement on every event; the attribution payload's 4 cookie values for order events; the identity-merge dependency made explicit
- §7 `smly_rec_event_queue` schema: added the `depends_on_event_id` column and the `blocked` status
- §11 Background jobs: added the event-dependency mechanism as a sub-point (`identity.merge` depends on `customer.upsert`)
- §17 Open questions: removed "API_CONTRACT missing" (resolved), added the production URL question

v0.5 → v0.6 changes (from the review of the engine-side `PLUGIN_IMPLEMENTATION_WP.md` v1.0):

- §8 Setup-URL: added the override mechanism `apply_filters('smaily_connect_setup_url', ...)` for the first setup call (flexibility for the production migration)
- §9 Order-attribution: store the cookies into order meta immediately in the `woocommerce_checkout_order_processed` hook (not from the cookie in later contexts — the cookies aren't available in those hooks)
- §11 Deduplication: added the `as_next_scheduled_action()` check in Action Scheduler (avoids duplicates in the `save_post_product` autosave + manual save and similar cases)

**Additional reference**: the engine-side `PLUGIN_IMPLEMENTATION_WP.md` v1.0 (a separate file) contains concrete WP/WC code examples (WPML/Polylang API usage, the HPOS order reader, beacon JS, GDPR exporter/eraser registration, EngineClient retry logic). It is a **reference document with code examples** that my PROJECT_PLAN.md refers to. When the engine-side document's code examples conflict with our PLUGIN.md spec (e.g. the plugin name `smaily-rec-engine`, the `src/` layout), **our spec wins** — the fork strategy means the `smaily-connect` name and the `includes/` layout. The engine-side document is instructive at the level of code examples, but not authoritative architecturally.
