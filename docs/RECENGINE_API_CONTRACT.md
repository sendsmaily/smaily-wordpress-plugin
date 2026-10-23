# Smaily Recommendation Engine — API Contract v1.18

**Version**: 1.18.0
**Published**: 2026-05-19
**Last updated**: 2026-10-23 (v1.18.0 — §16 becomes "disconnect": it revokes the calling connection's key, a store with no connection left is paused, never deleted, and a store's data is deleted when the merchant closes the Smaily account; the test send is a button, not a go-live condition; the store switches real sends on without waiting for its Smaily sender, and the email starts once its workflow is active (§12 `notice`); the ramp counts from the first real send after the switch-on; an automatic stop holds until an operator review, and a test email and the reactions to it do not count toward it; the design page may be framed in the store's admin and posts a message on save and close; §23 gives a workflow id and its Smaily account for every email the plugin triggers and marks the workflows the engine created. MINOR bump: one live behaviour change (§16). §12 `notice`, §19, §20, §22 Embedding and the §23 additions are published before they are live: see the availability note in each section — INT-145, INT-151, INT-150, INT-147, INT-146)
**Status**: Stable — basis for plugin implementation

---

## Document location and synchronization

This contract lives in four repositories and must stay byte-for-byte synchronized:
- `connect/docs/RECENGINE_API_CONTRACT.md` (Smaily Connect — WooCommerce plugin)
- `shopify-connect/docs/RECENGINE_API_CONTRACT.md` (Smaily Connect — Shopify app)
- `magento-connect/docs/RECENGINE_API_CONTRACT.md` (Smaily Connect — Magento extension)
- `re/docs/RECENGINE_API_CONTRACT.md` (rec engine)

When proposing a change (either side):
1. Discuss in shared channel before implementing
2. Update both repos in the same work session, one commit per repo
3. Verify diffs match before merging either

When you spot a drift (one side has a field, the other doesn't; an endpoint moved; a response shape changed) — fix both copies immediately, don't defer. Past drifts (`/api` path prefix, `event_id` body coverage) caused integration bugs that took days to trace.

Why not git submodule or a separate contracts repo right now: manual byte-sync has held across four consumers so far. Revisit if drift incidents recur at this consumer count.

---

## Overview

This document consolidates the earlier dialogue (`RECENGINE_API_ANALYSIS.md` + `RECENGINE_API_ROUND2.md`) into a clean REST API specification. It is platform-agnostic — the same contract serves WooCommerce, Shopify, Magento, PrestaShop, custom stores, and Make-flow integrations.

**WordPress-specific notes** (Action Scheduler, HPOS, hooks, REST endpoint patterns) live in a separate document, `docs/archive/spec/PLUGIN_IMPLEMENTATION_WP.md` (archived).

---

## Table of contents

1. [Base context](#base-context)
2. [Authentication](#authentication)
3. [Versioning](#versioning)
4. [URL namespace and cookie names](#url-namespace-and-cookie-names)
5. [Error handling](#error-handling)
6. [Rate limiting](#rate-limiting)
7. [Idempotency](#idempotency)
8. [Endpoints](#endpoints)
   - [POST /api/setup/exchange](#1-post-apisetupexchange)
   - [GET /api/v1/ingest/ping](#2-get-apiv1ingestping)
   - [POST /api/v1/ingest/catalog](#3-post-apiv1ingestcatalog)
   - [POST /api/v1/ingest/catalog/remove](#3b-post-apiv1ingestcatalogremove)
   - [POST /api/v1/ingest/catalog/manifest](#3c-post-apiv1ingestcatalogmanifest)
   - [POST /api/v1/ingest/customers](#4-post-apiv1ingestcustomers)
   - [POST /api/v1/ingest/orders](#5-post-apiv1ingestorders)
   - [POST /api/v1/ingest/browse](#6-post-apiv1ingestbrowse)
   - [POST /api/v1/identity/merge](#7-post-apiv1identitymerge)
   - [GET /api/v1/customer/{email}/export](#8-get-apiv1customeremailexport)
   - [DELETE /api/v1/customer/{email}](#9-delete-apiv1customeremail)
   - [POST /api/v1/customer/{email}/opt-out](#10-post-apiv1customeremailopt-out)
   - [GET /api/v1/automations/catalog](#11-get-apiv1automationscatalog)
   - [GET /api/v1/automations/config](#12-get-apiv1automationsconfig)
   - [PUT /api/v1/automations/config](#13-put-apiv1automationsconfig)
   - [POST /api/v1/notifications/ingest](#14-post-apiv1notificationsingest)
   - [POST /api/v1/recommendations/customer](#15-post-apiv1recommendationscustomer)
   - [POST /api/v1/store/uninstall](#16-post-apiv1storeuninstall)
   - [POST /api/v1/automations/{key}/setup-link](#17-post-apiv1automationskeysetup-link)
   - [PUT /api/v1/store/branding](#18-put-apiv1storebranding)
   - [POST /api/v1/automations/{key}/test-send](#19-post-apiv1automationskeytest-send)
   - [POST /api/v1/automations/{key}/go-live](#20-post-apiv1automationskeygo-live)
   - [POST /api/v1/onboarding/start](#21-post-apiv1onboardingstart)
   - [POST /api/v1/emails/{email_key}/design-link](#22-post-apiv1emailsemail_keydesign-link)
   - [GET /api/v1/emails/designs](#23-get-apiv1emailsdesigns)
9. [Appendices](#appendices)

---

## Base context

**Base URL**: varies by deployment environment, available as `engine_base_url` in the setup-exchange response.

**Production base URL**: `https://intelligence.smaily.com`

> The engine serves from its production domain `https://intelligence.smaily.com`. Earlier pilot preview deploys are retired (the previous alias still resolves for existing installs). The runtime base always comes from `engine_base_url` in the setup-exchange response, so installs auto-adapt to the live host regardless of this static default; all URL examples below use the current production base.

**Path prefix**: ALL plugin-to-engine requests use the `/api` prefix, including setup-exchange itself (`/api/setup/exchange`, NOT `/setup/exchange`). An earlier draft of spec v1.0 documented setup without `/api` — that was a defect fixed in sub-PR 3.1.2. The engine's actual route table serves everything under `/api/*`. The centralized constants list lives on the plugin side at `Smaily\Connect\Smaily\RecEngine\Client::PATH_*`.

**Content-Type**: all requests and responses use `application/json; charset=utf-8`.
- The engine **always** returns `Content-Type: application/json`, including in error responses.
- The engine **always** returns `Cache-Control: no-store` for authenticated endpoint responses (requests carrying an API key).

**Character encoding**: all request and response strings are UTF-8. JSON keys use lowercase + underscore (`first_name`, not `firstName`).

**Timezone**: all timestamp fields use ISO 8601 in UTC (`2026-05-19T10:15:23Z`). The engine converts internally to the tenant's timezone when needed.

**ID formats**:
- Tenant IDs: UUID v4
- Customer IDs: UUID v4 (generated engine-side from the first customer ingest)
- Recommendation IDs: UUID v4
- Visitor tokens: opaque strings of up to 64 characters — store, forward and compare a token exactly as received; do not depend on its length or characters. Engine-issued (email links, `smaily_vt`): `vt_` + exactly 10 characters `[a-z0-9]`. Store-created at checkout (v1.11.0, [§5](#store-created-visitor-token)): `vs_` + exactly 22 characters `[A-Za-z0-9]`
- Order `external_id`: text, plugin/platform-defined
- SKU: text, max 64 characters

**Paid Smaily package required.** Connect works only with a paid Smaily package: the engine reads and writes the store's Smaily data through the Smaily API, and Smaily gives API access only to paid packages. An account without one gets Smaily code 227 ("A paid package is required"). When the engine reports `smaily_paid_package_required` (§21), the plugin tells the store that its Smaily package does not include API access and offers the package upgrade instead of onboarding.

---

## Authentication

**Scheme**: HTTP Bearer Token

```
Authorization: Bearer sk_<random_32_chars>
```

The API key is tenant-scoped, obtained via `POST /api/setup/exchange`. **The API key must never appear in client-side code** (JavaScript, mobile app bundle). A plugin-side server proxy is required for browse events.

The setup endpoint (`POST /api/setup/exchange`) and, since v1.16.0, the onboarding start (`POST /api/v1/onboarding/start`, [§21](#21-post-apiv1onboardingstart)) are the **only** unauthenticated endpoints. The setup endpoint accepts a setup token instead of an API key; the onboarding start accepts the store's Smaily API login.

**Auth failure responses**:
- `401 Unauthorized` if the `Authorization` header is missing or the API key is invalid
- `401 Unauthorized` with `error: "api_key_revoked"` and a `regenerate_url` if the API key has been revoked in the admin UI

```json
{
  "error": "api_key_revoked",
  "regenerate_url": "https://intelligence.smaily.com/setup/regenerate/{tenant_id}",
  "message": "Your API key was revoked. Use the regenerate URL to obtain a new one."
}
```

---

## Versioning

**Engine version** is sent in every response header:

```
X-Engine-Version: 1.0.0
```

**Versioning rules** (Semantic Versioning 2.0):
- **MAJOR** (`2.0.0`): breaking changes to the API contract. The plugin must update before working with the new major.
- **MINOR** (`1.1.0`): new endpoints or new optional fields. Backward-compatible.
- **PATCH** (`1.0.1`): bug fixes; no contract changes.

**Plugin-side behavior** on version mismatch:
- The plugin declares its `compatible_engine_version_range` (e.g. `>=1.0.0,<2.0.0`)
- After every response, the plugin checks the `X-Engine-Version` header
- On mismatch: **graceful degradation** — the plugin continues operating and displays an admin notice ("Engine version X.Y.Z is newer than this plugin supports")
- The plugin **does not refuse to operate** on a version mismatch — data loss is a larger risk than a compatibility issue

**Setup response includes `engine_version`** so the plugin knows at install time which version it's working with.

**Note on version consistency**: the engine reads `ENGINE_VERSION` from environment configuration. The same value appears in the `X-Engine-Version` HTTP header and in any Smaily contact-sync payload field (`rec_engine_version`). One source of truth — if the env var is `1.0.0`, both surfaces show `1.0.0`.

---

## URL namespace and cookie names

### URL parameters (on campaign links)

The engine renders Smaily contact-field `product_url` values with these parameters appended:

```
https://shop.example.com/product/widget?
  utm_source=smaily&
  utm_medium=email&
  smaily_rec=3fa85f64-5717-4562-b3fc-2c963f66afa6&
  smaily_ctx=cart_abandoned&
  smaily_vt=vt_8f3k2a9xq4
```

**Reserved Smaily-prefixed parameters**:

| Parameter | Content | Use |
|-----------|---------|-----|
| `smaily_vt` | Visitor token (opaque, prefix `vt_`) | Identity resolution (anonymous → customer_id) |
| `smaily_rec` | Recommendation ID (UUID) | Attribution: which recommendation was clicked |
| `smaily_ctx` | Context string (`welcome`, `cart_abandoned`, `cross_sell`, etc.; `email` on an email slot without an intent; `storefront` on a storefront slot link, §15) | Attribution: in which context the click occurred. **Every link that carries `smaily_rec` also carries `smaily_ctx`** (v1.9.0). |

**UTM namespace** is reserved for the client's marketing tools (Google Analytics, ad platforms). The engine does NOT use `utm_content` for `rec_id` — that would pollute GA attribution data.

**Plugin-side capture**: when the plugin sees a URL with `smaily_*` parameters, it stores them in cookies (see below) and forwards them in subsequent API calls to the engine.

### Cookie names (plugin-side management)

The plugin manages four cookies. Names come from the **engine setup-response config** (allowing per-deployment overrides):

| Cookie | Default name | TTL | Content | SameSite/Secure |
|--------|--------------|-----|---------|-----------------|
| Visitor token | `smaily_rec_uid` | 365 days | URL `smaily_vt` value | Lax / Secure |
| Anonymous session ID | `smaily_anon_sid` | 30 days | UUID v4 (plugin-generated on first visit) | Lax / Secure |
| Recommendation ID | `smaily_rec_id` | 30 days | URL `smaily_rec` value (last-touch) | Lax / Secure |
| Context | `smaily_rec_ctx` | 30 days | URL `smaily_ctx` value (last-touch) | Lax / Secure |

**HttpOnly** = `false` — cookies are JavaScript-accessible (the beacon proxy uses them).
**Domain**: `auto` (uses the `Domain=.example.com` pattern so both `www.example.com` and `example.com` are covered).

**Last-touch overwrite**: each new email click overwrites `smaily_rec_id` and `smaily_rec_ctx`. Last touch wins on the cookie. **The engine retains first-touch info** in the `rec_attribution` table; the cookie carries only last touch.

**Context cookie rule** (v1.9.0): a landing whose URL carries `smaily_rec` sets `smaily_rec_id` to it AND sets `smaily_rec_ctx` to the URL's `smaily_ctx` — or **clears** `smaily_rec_ctx` when the URL has no `smaily_ctx`. The two cookies always describe the same landing. This applies to every landing with `smaily_rec`, storefront slot links (§15) included. Without it, an email click after a storefront click would leave the `storefront` context standing, and the purchase would be credited to the store instead of the email.

---

## Error handling

### HTTP status codes

| Code | Meaning | Plugin retry? |
|------|---------|---------------|
| 200 | OK, success | — |
| 201 | Created (resource created) | — |
| 204 | No Content (success, no body) | — |
| 400 | Bad Request — invalid request body | NO |
| 401 | Unauthorized — API key invalid or revoked | NO (display admin notice) |
| 403 | Forbidden — tenant deactivated or access denied | NO |
| 404 | Not Found — resource does not exist | NO |
| 409 | Conflict — idempotency conflict (rare) | NO |
| 429 | Too Many Requests — rate limit | YES (exponential backoff, honor `Retry-After` / `retry_after_seconds`) |
| 500 | Internal Server Error — engine down | YES (exponential backoff, up to 3 retries) |
| 502/503/504 | Bad Gateway / Service Unavailable / Gateway Timeout | YES (same as 500) |

### Error response format

Every error response contains JSON:

```json
{
  "error": "error_code_snake_case",
  "message": "Human-readable explanation",
  "details": {
    "field": "Optional context (validation errors)",
    "valid_values": ["array of valid values if applicable"]
  },
  "timestamp": "2026-05-19T10:15:23Z"
}
```

> **`request_id` scope**: a `request_id` (`req_…` UUID, useful for support tickets) is currently emitted **only by `/api/setup/exchange`** responses — see §1. The v1 ingest, customer/GDPR, identity, and automations endpoints do **not** emit `request_id`; their error bodies are `{error, message?, details?}` only. Do not depend on `request_id` outside setup/exchange. (Exception on the error-body shape: `PUT /api/v1/automations/config` returns its validation errors in a top-level `errors[]` array instead of `details` — see §13.)

### Validation error example

```json
HTTP 400 Bad Request

{
  "error": "validation_failed",
  "message": "One or more fields failed validation",
  "details": {
    "errors": [
      {
        "field": "products[0].sku",
        "code": "required",
        "message": "SKU is required and cannot be empty"
      },
      {
        "field": "products[2].price",
        "code": "invalid_type",
        "message": "Price must be a positive number",
        "actual_value": -5.99
      }
    ]
  },
  "timestamp": "2026-05-19T10:15:23Z"
}
```

**Plugin behavior**: on 400, parse `details.errors` and surface in an admin notice. **Do not retry** — a bad request will not improve with retries.

---

## Rate limiting

**Default limits**:

| Endpoint prefix | Limit | Window |
|-----------------|-------|--------|
| `/api/v1/ingest/browse` | 500 requests | per 1 second |
| `/api/v1/ingest/catalog` | 100 requests | per 1 second |
| `/api/v1/ingest/customers` | 100 requests | per 1 second |
| `/api/v1/ingest/orders` | 100 requests | per 1 second |
| `/api/v1/identity/merge` | 100 requests | per 1 second |
| `/api/v1/automations/{key}/setup-link` | 10 requests | per 60 seconds |
| `/api/v1/automations/{key}/test-send` | 10 requests | per 3600 seconds |
| `/api/v1/automations/{key}/go-live` | 10 requests | per 60 seconds |
| `/api/v1/automations/...` | 100 requests | per 1 second |
| `/api/v1/emails/{email_key}/design-link` | 10 requests | per 60 seconds |
| `/api/v1/emails/...` | 100 requests | per 1 second |
| `/api/v1/notifications/ingest` | 100 requests | per 1 second |
| `/api/v1/recommendations/customer` | 100 requests | per 1 second |
| `/api/v1/store/branding` | 10 requests | per 60 seconds |
| `/api/v1/customer/...` (GDPR) | 10 requests | per 60 seconds |
| `/api/setup/exchange` | 10 requests | per 60 seconds (per IP) |
| `/api/v1/onboarding/...` | 10 requests | per 60 seconds (per IP) |

**Identity**: authenticated requests are tracked by `<tenant_id>:<endpoint_prefix>`. The anonymous setup-exchange and onboarding endpoints are tracked by `ip:<ip>`.

A more specific prefix wins: the three `/api/v1/automations/{key}/...` rows come before the `/api/v1/automations/...` row (v1.15.0). For the three `{key}` rows the prefix does not include the key: one limit covers all emails of the store. Test sends from the setup page and the design page (§22), for every email, count toward the same test-send limit. The `/api/v1/emails/{email_key}/design-link` row comes before the `/api/v1/emails/...` row; its prefix does not include the key: one limit covers all emails of the store (v1.17.0). Design links and setup links (§17) count separately. The onboarding start window's own calls (reading its link, submitting the form) share the `/api/v1/onboarding/...` bucket with the start call, per IP (v1.16.0). The setup-link, branding and both emails rows are live; the test-send and go-live rows apply once their endpoints are live (§19, §20).

**Storage**: in-memory per Vercel instance (MVP; not shared across instances). Redis-backed global rate limiting is deferred to v2. For the pilot scale (one tenant, single Vercel instance handling traffic), per-instance limits are sufficient.

**429 Too Many Requests response**:

```
HTTP 429 Too Many Requests
X-RateLimit-Limit: 500
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 2026-05-19T10:15:28Z

{
  "error": "rate_limit_exceeded",
  "message": "Rate limit exceeded. Retry after 5 seconds.",
  "retry_after_seconds": 5,
  "timestamp": "2026-05-19T10:15:23Z"
}
```

> **Header note**: the engine emits the `X-RateLimit-*` trio (Limit, Remaining, Reset). The literal `Retry-After` header is not currently emitted — the `retry_after_seconds` field in the JSON body conveys the same information. Plugin should read from the body.

**Plugin behavior**:
- Honor `retry_after_seconds` from the response body (or `X-RateLimit-Reset` timestamp)
- Exponential backoff: 1s, 2s, 4s, 8s, 16s (max 5 retries)
- After 5 retries: log the error, display an admin notice, **do not lose the event** (keep it in the local queue)
- Browse events: **batch mode auto-activates** on 429 (collects events in a 5-second window, sends up to 100 per batch)

---

## Idempotency

Two layers of idempotency protect against duplicate processing:

### Layer 1: Natural-key UPSERT (always active)

Each ingest endpoint has a natural business key that uniquely identifies the record:

| Endpoint | Natural key |
|----------|-------------|
| `/api/v1/ingest/catalog` | `(tenant_id, sku)` |
| `/api/v1/ingest/customers` | `(tenant_id, email)` |
| `/api/v1/ingest/orders` | `(tenant_id, external_order_id)` |
| `/api/v1/ingest/browse` | (none — browse events are not semantically idempotent) |

Sending the same record twice updates (UPSERT) — no duplicates. This protects against any retry, regardless of whether the plugin sends an `event_id`.

### Layer 2: Transport-level deduplication (`event_id`, optional, per-item)

To handle queue-level retries cleanly, the plugin may send an `event_id` field **on each item** — every `products[]` / `customers[]` / `orders[]` / `events[]` object may carry its own `event_id` (string, UUID v4). The engine records `(tenant_id, event_id)` in the `ingest_event_log` table with a 90-day permanent retention window (cleaned by the daily retention cron).

**Behavior** (per-item, integer counts):
- Each item carrying a **new** `event_id` → processed, and that `event_id` is logged.
- Each item whose `event_id` is **already** in `ingest_event_log` → skipped; counted in `deduplicated`.
- The response returns integer counts: `{"processed": N, "deduplicated": M}`, where `M` is the number of items whose `event_id` was already seen. When `M` equals the total item count (a pure no-op retry), the response also includes `"deduplicated_all": true`.
- **Intra-batch duplicates** (the same `event_id` twice in one request): the first occurrence is `processed`, the second is `deduplicated`.
- Items **without** an `event_id` use Layer 1 (natural-key UPSERT) only and count toward `processed` when the row is created/updated.
- **Wrapper-level `event_id` is not supported.** An `event_id` placed at the top level of the request body (outside the item objects) is **silently ignored** — the request proceeds using per-item semantics only. There is no whole-request boolean short-circuit; the response is always the integer-count shape.

The per-item `event_id` field is **optional** on catalog / customers / orders — if omitted, only Layer 1 (natural-key UPSERT) is used. Browse has no Layer-1 fallback, so `event_id` is effectively required there (see the browse endpoint section).

**Endpoints accepting `event_id`**:
- `/api/v1/ingest/catalog` — per product (each `products[]` object)
- `/api/v1/ingest/customers` — per customer (each `customers[]` object)
- `/api/v1/ingest/orders` — per order (each `orders[]` object)
- `/api/v1/ingest/browse` — per event (each `events[]` object)

**Wire field name**: `event_id` (snake_case, string, UUID v4). The plugin's internal queue column may use a different name (the Smaily Connect plugin uses `event_uuid` internally) — the PayloadBuilder maps it to `event_id` on the wire.

**Browse events** are semantically not idempotent: the same customer may view the same SKU three times in a day, producing three legitimate browse events. The `event_id` layer protects against retry duplicates (network errors, queue replays), not against legitimate repeated activity.

### Idempotency examples

**Catalog, two requests with the same `event_id`**:
```bash
# Request 1
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-1>","name":"...","price":9.99,...}]}
# → 200 {"ok":true,"processed":1,"deduplicated":0,"errors":[]}

# Request 2 (retry with same per-item event_id)
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-1>","name":"...","price":9.99,...}]}
# → 200 {"ok":true,"processed":0,"deduplicated":1,"errors":[],"deduplicated_all":true}
```

**Catalog, two requests with different `event_id` but same SKU**:
```bash
# Request 1
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-1>","name":"...","price":9.99,...}]}
# → 200 {"ok":true,"processed":1,"deduplicated":0,"errors":[]}

# Request 2 (legitimate update — different event_id, same SKU)
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-2>","name":"...","price":12.99,...}]}
# → 200 {"ok":true,"processed":1,"deduplicated":0,"errors":[]} (Layer 1 UPSERT)
```

---

## Endpoints

### 1. POST /api/setup/exchange

Setup-token exchange. **Unauthenticated endpoint** (one of two, with the onboarding start, [§21](#21-post-apiv1onboardingstart)).

**Use case**: after tenant creation in the admin UI, Erkki gets a setup URL (e.g. `https://intelligence.smaily.com/setup/abc123xyz`). The client pastes this URL into the plugin's Settings; the plugin extracts the token (`abc123xyz`) and calls this endpoint to obtain its technical configuration.

**Where the setup token comes from.** The operator's admin, the store's console (Settings → Connections), or — since v1.16.0 — the start window of a new store (§21), which sends it to the plugin's window in a browser message. The exchange is the same in every case.

**URL**: `POST /api/setup/exchange`

**Auth**: none

**Rate limit**: 10 requests per 60 seconds (per IP)

**Headers**:
```
Content-Type: application/json
User-Agent: <plugin-identifier>/<version>  (e.g. "SmailyRecEngine-WooPlugin/0.1.0")
```

**Request body**:
```json
{
  "setup_token": "abc123xyz",
  "plugin_info": {
    "name": "smaily-rec-woo",
    "version": "0.1.0",
    "platform": "wordpress",
    "platform_version": "6.4.2",
    "ecommerce_platform": "woocommerce",
    "ecommerce_platform_version": "8.5.1",
    "site_url": "https://erkkipood.ee"
  }
}
```

`plugin_info` is recorded in the audit log (`tenant_setup_tokens.used_from_plugin`). This tells Erkki which plugin version the client connected with.

**Response 200 OK**:
```json
{
  "tenant_id": "550e8400-e29b-41d4-a716-446655440000",
  "tenant_name": "Erkki Pood",
  "api_key": "sk_8f3k2a4e1c4d8a9b2f7e3d1a6c8b9e0f",
  "engine_base_url": "https://intelligence.smaily.com",
  "engine_version": "1.0.0",
  "endpoints": {
    "ingest_ping":       "https://intelligence.smaily.com/api/v1/ingest/ping",
    "ingest_catalog":    "https://intelligence.smaily.com/api/v1/ingest/catalog",
    "ingest_catalog_remove": "https://intelligence.smaily.com/api/v1/ingest/catalog/remove",
    "ingest_catalog_manifest": "https://intelligence.smaily.com/api/v1/ingest/catalog/manifest",
    "ingest_customers":  "https://intelligence.smaily.com/api/v1/ingest/customers",
    "ingest_orders":     "https://intelligence.smaily.com/api/v1/ingest/orders",
    "ingest_browse":     "https://intelligence.smaily.com/api/v1/ingest/browse",
    "identity_merge":    "https://intelligence.smaily.com/api/v1/identity/merge",
    "customer_export":   "https://intelligence.smaily.com/api/v1/customer/{email}/export",
    "customer_delete":   "https://intelligence.smaily.com/api/v1/customer/{email}",
    "customer_opt_out":  "https://intelligence.smaily.com/api/v1/customer/{email}/opt-out",
    "recommendations_preview": "https://intelligence.smaily.com/api/v1/recommendations/preview",
    "recommendations_issue":   "https://intelligence.smaily.com/api/v1/recommendations/issue",
    "recommendations_customer": "https://intelligence.smaily.com/api/v1/recommendations/customer",
    "automations_catalog":     "https://intelligence.smaily.com/api/v1/automations/catalog",
    "automations_config":      "https://intelligence.smaily.com/api/v1/automations/config",
    "notifications_ingest":    "https://intelligence.smaily.com/api/v1/notifications/ingest",
    "store_uninstall":         "https://intelligence.smaily.com/api/v1/store/uninstall",
    "automations_setup_link":  "https://intelligence.smaily.com/api/v1/automations/{key}/setup-link",
    "store_branding":          "https://intelligence.smaily.com/api/v1/store/branding",
    "automations_test_send":   "https://intelligence.smaily.com/api/v1/automations/{key}/test-send",
    "automations_go_live":     "https://intelligence.smaily.com/api/v1/automations/{key}/go-live",
    "emails_design_link":      "https://intelligence.smaily.com/api/v1/emails/{email_key}/design-link",
    "emails_designs":          "https://intelligence.smaily.com/api/v1/emails/designs"
  },
  "config": {
    "tracking_cookie_name": "smaily_rec_uid",
    "session_cookie_name": "smaily_anon_sid",
    "rec_id_cookie_name": "smaily_rec_id",
    "context_cookie_name": "smaily_rec_ctx",
    "cookie_ttl_days": 365,
    "session_ttl_days": 30,
    "rec_id_ttl_days": 30,
    "context_ttl_days": 30,
    "rate_limit_browse": 500,
    "rate_limit_other": 100,
    "batch_size_max": 100,
    "supported_languages": ["et", "en"],
    "url_param_visitor_token": "smaily_vt",
    "url_param_rec_id": "smaily_rec",
    "url_param_context": "smaily_ctx"
  },
  "issued_at": "2026-05-19T10:15:23Z"
}
```

**Endpoint map convention**: keys use `ingest_*`, `identity_*`, `customer_*`, `recommendations_*`, `automations_*`, `store_*`, `emails_*` prefixes for the categories. Plugin code should read endpoint URLs from this map (`endpoints[ingest_catalog]`) rather than concatenating base URL + hardcoded paths. This way, future path migrations on the engine side don't require plugin updates — only the setup-response map changes.

> **Deprecated keys** (v1.9.1, PRO-3793): `recommendations_preview` and `recommendations_issue` point at routes that the engine retired on 2026-07-13 (PRO-1295); they answer `404`. The setup response still carries both keys, so the map shape does not change, but plugins must not call them. Contract 2.0 removes both keys. Storefront recommendations use `recommendations_customer` ([§15](#15-post-apiv1recommendationscustomer)).

> **Map age**: a connection keeps the endpoints map it received at exchange time. Connections established before a key existed (e.g. the `automations_*` keys, added v1.1.0) won't have it in their stored map — the plugin ships fallback path constants for exactly this case (the existing `resolve_url()` pattern). New keys serve future path migrations, not retroactive updates.

> **Automation setup keys** (v1.15.0): `automations_setup_link` ([§17](#17-post-apiv1automationskeysetup-link)), `store_branding` ([§18](#18-put-apiv1storebranding)), `automations_test_send` ([§19](#19-post-apiv1automationskeytest-send)) and `automations_go_live` ([§20](#20-post-apiv1automationskeygo-live)). `{key}` is a placeholder, like `{email}` in `customer_export`: the plugin replaces it with a catalogue `key` (URL-encoded). **The engine returns `automations_setup_link` and `store_branding` now** (since 2026-10-22); `automations_test_send` and `automations_go_live` are returned once §19 and §20 are live, and until then the key is absent. A connection set up before v1.15.0, or before the endpoint went live, has no key and uses the fallback path constant. An endpoint that is not live yet answers `404`; the plugin treats `404` as "not available yet" and keeps its current behaviour.

> **Email design keys** (v1.17.0): `emails_design_link` ([§22](#22-post-apiv1emailsemail_keydesign-link)) and `emails_designs` ([§23](#23-get-apiv1emailsdesigns)). `{email_key}` is a placeholder: the plugin replaces it with an email key (URL-encoded; the dot needs no encoding). **The engine returns both keys now** (since 2026-10-22). A connection set up before the endpoints went live has no key and uses the fallback path constant.

**Response 410 Gone** (token expired or used):
```json
{
  "error": "setup_token_expired_or_used",
  "message": "This setup token has expired or has already been used. Ask the engine administrator to generate a new one.",
  "regenerate_url": "https://intelligence.smaily.com/admin/tenants/{tenant_id}/regenerate-setup-token",
  "request_id": "req_..."
}
```

**Response 404 Not Found** (token does not exist):
```json
{
  "error": "setup_token_not_found",
  "message": "Setup token not found. Verify the URL is correct.",
  "request_id": "req_..."
}
```

**Idempotency**: setup tokens are **one-time use**. The first exchange marks `used_at = NOW()`. A subsequent exchange returns 410. The plugin must securely store the API key from the first exchange.

**Plugin-side**: after a successful exchange, the plugin stores `api_key`, `engine_base_url`, and `config` in its WordPress options table (api_key encrypted, `wp_options` with `autoload=false`).

---

### 2. GET /api/v1/ingest/ping

Health-check endpoint. The plugin's "Test Connection" button in Settings calls this.

**URL**: `GET /api/v1/ingest/ping`

**Auth**: `Authorization: Bearer sk_...`

**Headers**:
```
Authorization: Bearer sk_...
User-Agent: SmailyRecEngine-WooPlugin/0.1.0
```

**Response 200 OK**:
```json
{
  "ok": true,
  "pong": true,
  "tenant_id": "550e8400-...",
  "industry": "pet",
  "engine_version": "1.0.0",
  "ts": "2026-05-19T10:15:23Z"
}
```

**Response 401** if the API key is invalid (see Authentication).

**Response 403** if the tenant is deactivated — **suspended or purged/offboarded**:
```json
{
  "error": "tenant_inactive",
  "message": "This tenant is currently deactivated. Contact engine administrator.",
  "tenant_status": "suspended"
}
```

> **This response is live as of 2026-08-04** (PRO-1690) and applies to **every** API-key-authenticated endpoint in this contract (§2–§14), not only to ping — a deactivated tenant is refused at the shared authentication step. The body above is exact and identical in all cases: the engine distinguishes deactivation reasons internally, but never on the wire. `401` still means "this key is not valid"; `403 tenant_inactive` means "this key is valid, this account is not". **Sender rule**: treat `403 tenant_inactive` as non-retryable — stop sending and surface an admin notice, exactly as for `401`. Retries will not clear it; only the engine operator can.

> **Covered states** (v1.8.1, PRO-1820): **operator suspension** (billing or abuse — temporary, an operator can lift it) and **purge/offboarding** (the client relationship ended and the tenant's data was deleted under GDPR — permanent). Both answer with the byte-identical body above, including the literal `"tenant_status": "suspended"`; that value is a fixed string, **not** a state discriminator — do not branch on it. A purged tenant's credentials are permanently invalid: no key, setup token or regenerate flow can revive them, and data sent to a purged tenant after the purge is rejected at the gate, never stored.

**Rate limit**: 100 req/sec (default for non-browse endpoints).

**Idempotency**: not applicable (read-only endpoint).

---

### 3. POST /api/v1/ingest/catalog

Batch upload of the product catalog. The engine UPSERTs each product (same `sku` = update, new `sku` = insert).

**URL**: `POST /api/v1/ingest/catalog`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, up to 100 products per request

**Request body**:
```json
{
  "products": [
    {
      "event_id": "evt_b3f1a2c4-...",
      "sku": "ACA-DOG-3KG",
      "name": "Acana Adult Dog 3kg",
      "category_path": "food/dry",
      "price": 22.99,
      "compare_price": 25.99,
      "on_sale_until": "2026-06-01T00:00:00Z",
      "currency": "EUR",
      "in_stock": true,
      "description": "Premium dry food for adult dogs",
      "image_url": "https://erkkipood.ee/wp-content/uploads/aca-dog-3kg.jpg",
      "product_url": "https://erkkipood.ee/product/acana-adult-dog-3kg/",
      "external_id": "12345",
      "tags": {
        "brand": "Acana",
        "category_path": "food/dry",
        "product_id": "7620134"
      },
      "raw_attributes": {
        "pa_species": ["dog"],
        "pa_life_stage": ["adult"],
        "pa_protein": ["chicken"],
        "meta_typical_pack_size": "3kg",
        "_wc_categories": ["Food", "Dry Food", "Acana"]
      }
    }
  ]
}
```

**Multilingual variant** (`name`, `description`, `product_url` accept object form):
```json
{
  "products": [
    {
      "sku": "ACA-DOG-3KG",
      "name": {
        "et": "Acana Täiskasvanud Koer 3kg",
        "en": "Acana Adult Dog 3kg"
      },
      "description": {
        "et": "Premium kuivtoit täiskasvanud koertele",
        "en": "Premium dry food for adult dogs"
      },
      "product_url": {
        "et": "https://erkkipood.ee/et/toode/acana-koer-3kg/",
        "en": "https://erkkipood.ee/en/product/acana-dog-3kg/"
      },
      "price": 22.99,
      "in_stock": true,
      "tags": { "brand": "Acana", "category_path": "food/dry" },
      "raw_attributes": { }
    }
  ]
}
```

The engine accepts both forms — field type is checked at runtime. Storage behavior:
- A single-language `string` is wrapped as `{default: "..."}` in the field's `*_i18n` JSONB column (`name_i18n`, `description_i18n`, `product_url_i18n`), with the string also kept in the plain text column.
- An object (`{et: ..., en: ...}`) is stored verbatim in the `*_i18n` column, plus a representative scalar (`default` → tenant default → `en` → first key) in the plain text column for the non-i18n render fallback.
- `description` is truncated to **500 characters per language** (the row is not rejected).
- `image_url` has no `*_i18n` column — only a representative scalar is stored.

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | NO | Per-product transport-level dedup key. See [Idempotency](#idempotency). |
| `sku` | string (max 64) | YES | **Canonical product-identity token — NOT the merchant's SKU field.** The stable platform product/variant id, namespaced by source (`shp-<variant_id>`, `woo-<id>`). Never the merchant-entered SKU string, and never a fallback to it. See [Product identity](#catalog-identity). |
| `name` | string \| `{lang: string}` | YES | Product name |
| `category_path` | string | YES | Hierarchical category (`food/dry`, `accessories/leashes`) |
| `price` | number | YES | Customer's current selling price (NOT regular_price) |
| `compare_price` | number | NO | Pre-sale ("was") price. A sale exists **iff `compare_price > price`** (Shopify convention). Null / equal to / less than `price` → no sale. See [Sale semantics](#sale-semantics) below. |
| `on_sale_until` | ISO 8601 string | NO | Informational only — stored but does **not** gate sale display (a sale is driven by `compare_price > price` alone). |
| `currency` | string (ISO 4217) | NO | Default `EUR`. Stored **as sent** — not strictly ISO-validated (loose format check: 3 uppercase letters). One currency per tenant remains the assumed model — mirrors `orders.currency` ([§5](#5-post-apiv1ingestorders)). |
| `in_stock` | boolean | YES | Whether the product is available |
| `description` | string \| `{lang: string}` | NO | Short description (max 500 characters) |
| `image_url` | string (URL) \| `{lang: string}` | NO | Product image URL. **Stored as a representative scalar only** — there is no `image_url_i18n` column, so the `{lang}` form is accepted but not stored per-language. |
| `product_url` | string (URL) \| `{lang: string}` | YES | Product page URL. **Required, non-empty** — an empty string `""` is rejected (400), mirroring `category_path`. No silent fallback to `product_base_url + sku`. |
| `external_id` | string | NO | Plugin/platform internal ID (for debugging/traceability). |
| `tags` | object | NO | Best-effort mapping (engine uses immediately). Includes the optional `category_defaulted` marker — see [below](#category-defaulted). |
| `raw_attributes` | object | NO | Raw platform data. **Currently stored verbatim and not processed** — the AI mapping wizard / `unmapped_attributes` flow is planned, not yet implemented. |
| `product_type` | string | NO | Platform product type — WC `simple`/`variable`/`grouped`/`external` **plus gift-card plugins' custom types** (`pw-gift-card`, `gift-card`, `gift_card`, `wc_gc`, …). The **robust non-product signal**: the engine derives `recommendable` from this (gift-card types → excluded). Send it; do not hard-filter on it yourself. |
| `is_virtual` | boolean | NO | WC virtual flag. **Stored as signal, not auto-excluding** — a legitimate digital/virtual-goods store sells these. Lets the engine distinguish a digital store from a config artifact. |
| `is_downloadable` | boolean | NO | WC downloadable flag. Same semantics as `is_virtual` — stored, not auto-excluding. |

<a name="sale-semantics"></a>
**Sale semantics** (D2 Variant 1, Shopify convention):
- `price` is the current selling price; `compare_price` is the pre-sale ("was") price.
- A **sale exists iff `compare_price > price`**. Savings amount = `compare_price - price`; savings percentage = `(compare_price - price) / compare_price`.
- If `compare_price` is **null**, **equal to** `price`, or **less than** `price`, there is **no sale** — no strikethrough price and no negative/zero savings are shown.
- `on_sale_until` is stored as informational metadata only; it does **not** affect whether a sale is displayed (there is no expiry gating). Send a current `compare_price` to express a live sale.

> The engine has no separate "discount price" field. A discounted product is expressed purely as `price` (the discounted price the customer pays) plus `compare_price` (the higher pre-sale price).

<a name="catalog-identity"></a>
**Catalog identity, multilingual & lifecycle** (added 2026-06-13; identity rule sharpened 2026-07-09):

- **`sku` is the platform product/variant id — NEVER the merchant SKU field.** The engine's `sku` is a join/identity key, not a human-facing code. It **must** be the platform's stable internal id, namespaced by source: Shopify → `shp-<variant_id>` (the order line's `variant_id`, **not** `line_item.sku`); WooCommerce → `woo-<variation_id>` for variable products, `woo-<product_id>` for simple; **Magento → the catalog `sku` field itself** (Magento enforces the SKU field as mandatory + store-unique, so it *is* Magento's own canonical identifier — the one platform where the merchant `sku` field is the correct key), with `mag-<entity_id>` as a fallback **only** when the SKU field is empty. The `mag-<entity_id>` fallback must be applied **identically on catalog and order lines** for the same empty-SKU product (see "Same key from every path" below), or the two paths diverge onto different keys. **The "never the merchant SKU field" rule that follows is specific to Shopify/WooCommerce**, where "SKU" is a free-text, optional, reusable merchant field distinct from the platform id; it does **not** apply to Magento, whose SKU is the platform-canonical key. The merchant-entered "SKU" field on Shopify/Woo is **optional, frequently blank, reused, or garbage** (real-world examples seen: a price `"63.00"`, a sequence number `"12"` shared by dozens of products, an EAN barcode) — using it, **even as a fallback when the platform id is momentarily unavailable**, collapses distinct products onto one `(tenant_id, sku)` key and silently destroys history. If you need the merchant SKU for display/debugging, send it as `tags.merchant_sku` — **never** as `sku`, and **never** in `external_id`. (`catalog.external_id` carries the platform variant id and drives the engine's same-SKU collision detection; putting the reused/blank merchant SKU there would break it. The engine does not consume or surface `tags.merchant_sku` today — send it only if an operator genuinely needs it, per data-minimization.)
  - **Same key from every path.** The identical token must be emitted from catalog **and** order-line **and** browse ingest for the same product. Consistency is required only *within one tenant/source* (one store = one plugin); `shp-` and `woo-` namespaces never cross-join.
  - **Fail-loud enforcement (rolling out — see PRO-1223).** The engine will validate that each `sku` matches the sender's declared namespace pattern and route a non-conforming row to `errors[]` / `import_errors` rather than silently UPSERTing it. Senders must not rely on silent acceptance of off-scheme keys.
- **One row per canonical product — collapse translations.** Send exactly one catalog row per real, purchasable product. Do **NOT** send a separate row per language: a multilingual product (WPML/Polylang) must be a **single `sku`** whose translations are carried in the `{lang: value}` object form of `name` / `description` / `product_url` (see *Multilingual variant* above). Keep the `sku` identical across languages and across syncs. Emitting one row per translation creates duplicate SKUs that the engine **cannot** dedupe (there is no language tag or parent link), producing language-mixed recommendations.
- **Parent product id — `tags.product_id`.** Alongside the variant-level `sku`, emit the platform **parent product id** as `tags.product_id` (Shopify `<product_id>`, Woo `<product_id>`). All variants of one product share one `tags.product_id`. The engine uses it for **product-level removal** (see [§3b](#3b-post-apiv1ingestcatalogremove)) and for **cross-variant grouping** — it groups catalog variants sharing a `tags.product_id` into one product family for cross-variant cadence and `sample_to_full` (live since PRO-1227). `sku` stays the variant-level key and `external_id` stays the variant id; only the grouping is by parent. Shopify and Woo emit it today; Magento rolls it in with its canonical-key work. Where a sender does not emit it, product-level removal is unavailable and cadence/grouping degrade to per-SKU for that sender (per-SKU `in_stock=false` still works).
- **Real products only.** Do not send non-purchasable artifacts: language-switcher pseudo-products, gift cards, donation items, or virtual config entries. *(The engine additionally derives an internal `recommendable` flag at ingest to defensively exclude such items — see [Engine-internal fields](#engine-internal). The source should still not send them, to avoid catalog bloat.)*
- **Lifecycle is UPSERT-only; removal is soft, never a hard delete.** The engine UPSERTs by `sku` and never removes a `sku` merely because it stopped appearing in a §3 sync or a full import. The one place absence counts is the nightly [manifest (§3c)](#3c-post-apiv1ingestcatalogmanifest), which lists the store's complete catalog. **Removal is explicit and always *soft*:** either re-send the product with `in_stock=false` (per-SKU), or call [`POST /api/v1/ingest/catalog/remove`](#3b-post-apiv1ingestcatalogremove) with the parent `product_id` to tombstone all of a product's SKUs at once (the path for a platform hard-delete, where the webhook gives only the product id). A tombstone sets `in_stock=false` + `recommendable=false` — it drops the product from every recommendation path but **keeps the row**. Catalog rows (like `orders` / `order_items`) are **retained as a learning corpus** and are **never hard-deleted** except on GDPR erasure or tenant offboarding; the engine offers no full-catalog replace and deletes by absence only through the §3c manifest. A product missing from the nightly [manifest (§3c)](#3c-post-apiv1ingestcatalogmanifest) is tombstoned the same way. When to send what is the [catalog sync lifecycle](#catalog-sync-lifecycle) below. **Consequence when changing the SKU scheme:** migrated old SKUs are **not** auto-removed — they linger as stale rows; orphan removal at a SKU-scheme migration is a **one-time manual purge** on the engine side, coordinated with the sender.

<a name="catalog-sync-lifecycle"></a>
**Catalog sync lifecycle** (v1.8.3, PRO-3740):

1. **Full import at setup.** After [setup exchange](#1-post-apisetupexchange), the sender sends the whole catalog once, in §3 batches.
2. **Changes after that.** The sender then sends only what changes, when it changes:
   - **Product save** (any field the sender maps) → re-send that product's rows via §3.
   - **Stock change** → re-send the row via §3 with the new `in_stock`.
   - **Archive / unpublish** → re-send the row via §3 with `in_stock=false`.
   - **Platform hard delete** → [§3b](#3b-post-apiv1ingestcatalogremove) with the parent `product_id`.

   **Send the whole row, not a patch.** The UPSERT replaces every sender-owned column with the value in the request, so an optional field that a change omits (`compare_price`, `description`, `image_url`, …) is cleared. Only `tags` is merged (keys the request does not set are kept).
3. **Full import by hand.** The merchant can start a full import at any time. It uses the same §3 batches as the setup import and is safe to repeat (natural-key UPSERT, [Idempotency](#idempotency)). It does **not** remove products that are missing from it — absence counts only in the nightly [manifest (§3c)](#3c-post-apiv1ingestcatalogmanifest).
4. **No scheduled full re-sync.** The engine does not need a periodic full re-sync and does not expect one. A sender that still runs one causes no harm (the UPSERT is idempotent, and back-in-stock detection only reacts to a real stock transition), but it is not part of this contract.
5. **Nightly manifest (v1.12.0).** Once a night the sender sends the store's complete product list — `sku` + `in_stock` only — to [§3c](#3c-post-apiv1ingestcatalogmanifest). It heals lost stock-change and delete events, and tells the operator when a full import is needed.

**What the engine reconciles itself.** Every engine-derived catalog value is computed from data the engine already stores on the row (`name`, `name_i18n`, `category_path`, `product_type`, `raw_attributes`, `tags`) or from other engine data. When the engine changes its own rules, it re-derives the values engine-side, from the stored rows. **No plugin re-sync is needed and the engine never asks for one for this reason.**
- `recommendable` (see [Engine-internal fields](#engine-internal)) — computed on every upsert; after a classifier change the engine re-classifies stored rows with an engine-side backfill.
- Slug / name tag derivation (`lib/ingest/attribute-mapping.ts`) — computed on every upsert; after a lexicon change the engine re-derives stored rows with an engine-side backfill.
- Nightly AI tag sweep (`sweep-catalog-tags`) — classifies the tags the lexicon leaves unset. It marks each row with a tag version; when the engine raises that version, the sweep re-classifies every row once, by itself.
- `popularity_score` — computed nightly from orders, not from the catalog sync.
- Operator per-SKU recommendable override — engine-only; no sync touches it.

**When a full import from the plugin is required.** Only when the engine needs data that it does not store:
- A contract change that adds a catalog field, or changes what a field must hold. The changelog entry for that version says so explicitly (as the v1.4.0 entry did for the order gross-amount re-sync). Without such a note, no re-sync is needed after an engine release.
- A sender-side mapping fix: the sender corrects what it sends (for example attribute labels instead of term ids). The corrected values exist only in the store.
- After the sender knows that it lost change events, or the engine's operator reports that the [manifest (§3c)](#3c-post-apiv1ingestcatalogmanifest) named products the engine does not have.

**Lost change events are healed by the nightly manifest.** A change event that never reaches the engine (a failed webhook, a dropped queue item, an outage longer than the sender's retries) used to stay wrong until that product changed again or the merchant ran a full import. Since v1.12.0 the [manifest (§3c)](#3c-post-apiv1ingestcatalogmanifest) heals the two cases that keep a wrong product in recommendations within a night: a lost stock change (the engine takes the manifest's `in_stock`) and a lost delete (a product missing from the manifest is tombstoned as §3b does). Other lost field changes (name, price, category, …) are not in the manifest and still wait for the next change of that product or a full import; a product the store has and the engine does not is reported to the operator as "full import needed". Senders **SHOULD** still send change events from a durable queue with retries, and show the merchant when events fail.

<a name="engine-internal"></a>
**Engine-internal fields** (not part of the request — do not send): the engine derives some columns at ingest that senders never supply. Notably `recommendable` (boolean): the engine's **exclusion decision** (a per-store/business-model call the connector must NOT make). Derived primarily from the **`product_type` signal** (gift-card types → excluded), with `sku`/`category_path`/`name` heuristics as fallback (test artifacts `LIVE-*`/`live-test`, name-matched gift cards/donations). `is_virtual`/`is_downloadable` are **stored but do NOT auto-exclude** (digital-goods stores sell those). Recomputed on every upsert, so a corrected sync self-heals; tunable engine-side without redeploying connectors. Excluded products are never recommended via any path. **Division of labour: the connector sends structural signal; the engine owns the exclusion.**

<a name="category-defaulted"></a>
**`tags.category_defaulted`** (added v1.6.0, PRO-1500): optional string tag, value `"true"` — **omit-on-false** (send it only when true; there is no `"false"` value, absence means "not defaulted"). Set this when the sender substituted the store's fallback/default category because the product genuinely has none (e.g. WooCommerce's default-category behavior), or on a delete-tombstone row the sender still has to sync with *some* `category_path`. Semantics: on such a row, `category_path` is a **placeholder, not real product taxonomy**. The engine skips every category-**slug**-keyed derivation for it — species-from-category, `category_canonical`, and replenishable-from-category (`lib/ingest/attribute-mapping.ts`) — while **name**-keyed derivations (species/life-stage-from-name, brand lexicon match) are unaffected and still run; an explicit `tags.species` / `tags.replenishable` etc. always wins regardless of this flag. A row left without `category_canonical` this way stays eligible for the nightly AI category sweep (`lib/catalog/category-sweep.ts`), which classifies from the product name when the category axis carries no signal. **Re-sync honesty**: evaluated fresh from each request's own `tags` — omit the flag on a later sync (once a real category is known) and normal slug-derivation resumes for that sync; nothing is "sticky" in the engine's derivation logic.

**Response 200 OK** (all products valid):
```json
{
  "ok": true,
  "processed": 47,
  "deduplicated": 0,
  "errors": []
}
```

`processed` = products UPSERTed; `deduplicated` = products whose per-item `event_id` was already seen (see [Idempotency](#idempotency)); `errors` = products that failed per-item validation (see partial success below). **Invariant:** `processed + deduplicated + errors.length == total products`.

**Response 200 OK (partial success — the D6 contract)**:
```json
{
  "ok": true,
  "processed": 1,
  "deduplicated": 0,
  "errors": [
    {"index": 1, "sku": "ACA-BAD", "field": "product_url", "message": "Invalid input"}
  ]
}
```

Each product is validated **independently**: a product that fails validation goes to `errors[]` as `{index, sku?, field, message}` (`index` is its position in `products[]`; `sku` is included when present) and is **not** written; valid products in the same batch are still processed. A rejected product's `event_id` is **not** registered, so a corrected retry of that product still processes.

> **Not yet implemented:** the `unmapped_attributes` response array and the AI mapping wizard are planned but not built. `raw_attributes` is currently stored verbatim and not processed, so no `unmapped_attributes` is emitted.

**Response 200 OK (deduplicated retry, all items)**:
```json
{
  "ok": true,
  "processed": 0,
  "deduplicated": 5,
  "errors": [],
  "deduplicated_all": true
}
```

Returned when every product carrying an `event_id` in the request was already processed (matching `event_id` already in `ingest_event_log`). No catalog row was created or updated. `deduplicated` is the integer count of skipped items, and `deduplicated_all: true` flags a pure no-op retry. Plugin should treat this as a successful no-op — the original request was processed earlier. (A *mixed* batch returns e.g. `{"processed":3,"deduplicated":2,"errors":[]}` with no `deduplicated_all`.)

**Wrapper is all-or-nothing; products are per-item.** Per-product validation failures go to `errors[]` (above) — they do not reject the batch. Only a malformed **wrapper** (a non-array / empty / >1000 `products`) is a hard `400 validation_failed`:
```json
{
  "error": "validation_failed",
  "details": {
    "formErrors": [],
    "fieldErrors": {
      "products": ["Expected array, received object"]
    }
  }
}
```

**Idempotency**: two layers, as described in [Idempotency](#idempotency):
- **Layer 1** (always active): `(tenant_id, sku)` natural-key UPSERT. Same SKU sent twice → second call updates.
- **Layer 2** (optional, per-item `event_id`): an item whose `event_id` was already seen is counted in `deduplicated` and not re-UPSERTed (the whole request is a no-op when `deduplicated_all: true`).

---

### 3b. POST /api/v1/ingest/catalog/remove

**Product-level soft removal (tombstone).** Removes every SKU of one or more products from all recommendation paths in a single call, without the sender needing to know the products' SKUs. This is the path for a platform **hard-delete** (Shopify `products/delete`, Woo product delete, …) where the webhook gives only the product id, not its variants/SKUs. Archive / unpublish / out-of-stock do **not** need this — send those as `in_stock=false` on the normal catalog sync (§3).

**URL**: `POST /api/v1/ingest/catalog/remove`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, up to 1000 product ids per request

**Request body**:
```json
{
  "product_ids": ["7620134", "7620135"]
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `product_ids` | string[] | YES | Platform **parent product ids** — the value the sender emits as `tags.product_id` (1–1000 per request). Matched against `catalog.tags.product_id` for the calling tenant. |

**Semantics**:
- **Soft tombstone, never a row delete.** For every catalog row of the tenant whose `tags.product_id` is in `product_ids`, the engine sets `in_stock=false` **and** `recommendable=false`. The rows — and their attributes, orders and attribution — are kept (see the retention invariant under [Catalog identity & lifecycle](#catalog-identity)). A hard row delete is **never** performed here.
- **All SKUs at once.** A product's variants share one `tags.product_id`, so one id removes the whole product.
- **Idempotent.** Re-removing an already-removed product is a no-op. A product id matching no rows is counted in `not_found`, not an error.
- **Effect on serving.** A tombstoned product is excluded from every recommendation path (hard-gate, tier-0, orchestrator); if it was in a customer's replenishment set, the `stock_status_change` trigger surfaces a substitute.
- **The direct delete signal.** A full import does not remove a product that is missing from it, so this call (or an `in_stock=false` row via §3) is how the engine learns at once that a product is gone. Send it from a durable queue with retries; a lost delete event is healed by the next nightly [manifest (§3c)](#3c-post-apiv1ingestcatalogmanifest), which tombstones each SKU missing from the store's list the same way.

**Response 200 OK**:
```json
{
  "ok": true,
  "removed_products": 2,
  "rows_tombstoned": 7,
  "not_found": []
}
```

`removed_products` = ids that matched ≥1 row; `rows_tombstoned` = catalog rows set to `in_stock=false` + `recommendable=false`; `not_found` = ids that matched no row for this tenant (safe to ignore — already removed, or never sent).

**Errors**: a malformed wrapper (`product_ids` missing / not an array / empty / >1000) → `400 validation_failed` (same shape as §3). Auth and rate-limit behave as the other ingest routes.

---

### 3c. POST /api/v1/ingest/catalog/manifest

**Nightly catalog manifest** (v1.12.0, PRO-3763). Once a night the sender sends the store's **complete** product list — the product code and its stock, nothing else. The engine compares it with its own catalog for the store and heals what lost change events left wrong.

**URL**: `POST /api/v1/ingest/catalog/manifest`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: shares the `/api/v1/ingest/catalog` bucket (100 req/sec). One request per night is expected.

**Request body**:
```json
{
  "products": [
    {"sku": "woo-101", "in_stock": true},
    {"sku": "woo-102", "in_stock": false}
  ]
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `products` | array | YES | The store's **complete** product list, **0–50,000** items, in **one** request. Send every product the store has, in stock or not. |
| `products[].sku` | string (1–64) | YES | The same `sku` value §3 sends for the product (the canonical identity token — see [Product identity](#catalog-identity)). One item per catalog row, variants included. |
| `products[].in_stock` | boolean | YES | The product's stock now, with the same meaning as §3 `in_stock`. |

Send nothing else (no names, prices or descriptions). Unknown keys are ignored. Send compact JSON: the request body must stay under 4.5 MB (the hosting limit; about 50,000 items with typical SKUs).

**When and what to send.**
- Once a night, at a quiet hour. Build the list immediately before sending it.
- The list covers the **whole store catalog**, however many connections feed this tenant. A product left out is treated as deleted.
- A store with more than 50,000 products cannot use this endpoint yet; it must not send a partial list.

**What the engine does** with each difference:

| Case | Engine action |
|------|---------------|
| Product in the engine, **missing** from the list | Tombstoned exactly as [§3b](#3b-post-apiv1ingestcatalogremove) does: `in_stock=false` + `recommendable=false`, row kept. It is no longer recommended. A row that is already tombstoned is left alone. |
| Product in both, `in_stock` **differs** | The engine takes the list's value. The back-in-stock clock is kept as for a §3 stock change, but a product found back in stock **here sends no back-in-stock email** — the real time of the restock is unknown. |
| Product in the list, **missing** in the engine | Not created (the list has no name or price). Counted and reported to the engine's operator as "full import needed". |
| Same `sku` twice in the list | The last occurrence wins. |

**Truncation guard.** If the list is **empty**, or would remove **more than 20%** of the store's live catalog rows (rows not already tombstoned), the engine removes **nothing**. Stock corrections still apply. The engine's operator gets a warning. The guard does not apply when nothing would be removed. Nightly drift is one day of lost delete events — far below 20%; a list cut short by a paging bug or a timeout is usually far above it. When the guard trips on a real cleanup, the engine's operator removes the products by hand.

**Idempotent.** Re-sending the same list changes nothing: removed products are no longer live, corrected stock already matches. Each manifest overwrites the engine's record of the last one.

**Response 200 OK**:
```json
{
  "ok": true,
  "products_in_manifest": 1840,
  "removed": 3,
  "stock_fixed": 12,
  "missing_in_engine": 0,
  "guard_tripped": false,
  "guard_reason": null,
  "would_remove": 3
}
```

`products_in_manifest` = distinct SKUs in the list; `removed` = rows tombstoned; `stock_fixed` = rows whose `in_stock` changed; `missing_in_engine` = SKUs in the list that the engine does not have; `guard_tripped` / `guard_reason` (`"empty_list"` | `"too_many_removals"` | `null`) = whether the guard stopped the removals; `would_remove` = rows the list would remove (equal to `removed` unless the guard tripped). The sender does not need to act on any of these; the engine's operator sees them.

**Errors**: a malformed wrapper or item (`products` missing / not an array / more than 50,000 items; an item without a non-empty `sku` of at most 64 characters or without a boolean `in_stock`) → `400 validation_failed` (same shape as §3). The whole list is rejected: a partly accepted list would read as missing products. Auth and rate limit behave as the other ingest routes.

---

### 4. POST /api/v1/ingest/customers

Batch upload of customers. **Identity is `email`** (W4 / D1): UPSERT by `(tenant_id, email)`; email is lowercased on ingest and matched case-insensitively. There is no `smaily_contact_id`.

**URL**: `POST /api/v1/ingest/customers`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, **1..100 customers per request**. The `customers` wrapper must be an array of 1–100 items — a non-array (or empty / >100) `customers` is a `400 validation_failed` (the wrapper is all-or-nothing). Per-**item** validation failures do not fail the batch; they are reported per item in `errors[]` (see the response below).

**Request body**:
```json
{
  "customers": [
    {
      "event_id": "evt_a1b2c3d4-...",
      "email": "mari@example.com",
      "first_name": "Mari",
      "last_name": "Tamm",
      "country": "EE",
      "language": "et",
      "phone": "+372...",
      "first_seen_at": "2026-01-15T10:30:00Z",
      "external_id": "67"
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | NO | Per-customer transport-level dedup key. See [Idempotency](#idempotency). |
| `email` | string (valid email) | YES | **Identity** — natural key for the `(tenant_id, email)` UPSERT. Lowercased on ingest; matched case-insensitively. |
| `first_name` | string | NO | |
| `last_name` | string | NO | |
| `country` | string (ISO 3166-1 alpha-2) | NO | E.g. "EE", "FI", "US". Stored **as sent** — not strictly ISO-validated (N-8). |
| `language` | string (ISO 639-1) | NO | E.g. "et", "en", "ru". Drives **per-customer localization** of the recommendation fields pushed to Smaily — `rec_N_name` / `rec_N_description` / `rec_N_link_url` are resolved from the catalog `*_i18n` columns in this language (fallback: `tenant_settings.default_language` → `en` → `default` → first). Also pushed to the Smaily contact's native `language` field for segmentation — only when sent. When absent, localization and copy use the tenant default, and the Smaily `language` field is left as it is (never overwritten with the default). Stored **as sent** — not strictly ISO-validated (N-8). See `MULTILINGUAL_DESIGN.md`. |
| `phone` | string | NO | |
| `first_seen_at` | ISO 8601 | NO | Registration timestamp (if different from row creation). Not overwritten on update (earliest wins). |
| `external_id` | string | NO | Platform-internal user_id |

> **Note on consent**: consent is **not part of the customers contract**. The engine does not accept, store, or process any `consent.*` fields — Smaily owns consent (it will not send to a contact without marketing consent regardless of engine data). Do not send consent fields; they are ignored.

**Response 200 OK** (per-item partial success — the D6 contract):
```json
{
  "ok": true,
  "processed": 28,
  "deduplicated": 1,
  "errors": [
    {"index": 3, "email": "bad-email", "field": "email", "message": "Invalid email"}
  ]
}
```

**Each item has exactly one fate:**
- **processed** — valid and new → UPSERTed by `(tenant_id, email)`, counted in `processed`.
- **deduplicated** — valid, but its `event_id` was already seen → counted in `deduplicated`, not re-UPSERTed.
- **error** — failed per-item validation → an entry in `errors[]`; **not** processed and **not** dedup-registered (so a corrected retry of that item still processes — its `event_id` was never marked seen).

**Invariant:** `processed + deduplicated + errors.length == total items in the batch`.

`deduplicated_all: true` is added when **every** item was deduplicated (a pure no-op retry), e.g. `{"ok":true,"processed":0,"deduplicated":3,"errors":[],"deduplicated_all":true}`.

**Error object shape**: `{index, email?, field, message}` — `index` is the item's position in the batch; `email` is included when present (helps map the error to a row); `field` + `message` describe the failure. (No `request_id` — the engine emits none.)

**Idempotency**: two layers, as described in [Idempotency](#idempotency):
- **Layer 1**: `(tenant_id, email)` natural-key UPSERT.
- **Layer 2**: optional per-item `event_id` for retry deduplication (integer counts).

---

### 5. POST /api/v1/ingest/orders

Batch upload of orders + order items. HPOS-aware payload.

Batch upload of orders + line items. **Order natural key is `(tenant_id, external_order_id)`**; the customer is referenced by `customer_email` (lowercased, auto-created if absent — W4 email identity).

**URL**: `POST /api/v1/ingest/orders`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, **1..50 orders per request** (orders + items can be sizable together). The `orders` wrapper must be an array of 1–50 items — a non-array (or empty / >50) `orders` is a `400 validation_failed` (the wrapper is all-or-nothing). Per-**order** validation failures do not fail the batch; they are reported per item in `errors[]` (see the response below).

**Request body**:
```json
{
  "orders": [
    {
      "event_id": "evt_c1d2e3f4-...",
      "external_order_id": "WC-12345",
      "customer_email": "mari@example.com",
      "ordered_at": "2026-05-15T14:30:00Z",
      "total_amount": 67.50,
      "discount_amount": 5.00,
      "currency": "EUR",
      "status": "completed",
      "smaily_rec_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
      "smaily_visitor_token": "vt_8f3k2a9xq4",
      "smaily_rec_ctx": "cart_abandoned",
      "session_id": "wp_sess_abc123",
      "items": [
        {
          "sku": "ACA-DOG-3KG",
          "qty": 1,
          "unit_price": 22.99,
          "line_total": 22.99,
          "discount_amount": 0.00
        },
        {
          "sku": "POC-DENT",
          "qty": 2,
          "unit_price": 22.255,
          "line_total": 44.51,
          "discount_amount": 5.00
        }
      ]
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | NO | Per-order transport-level dedup key. See [Idempotency](#idempotency). |
| `external_order_id` | string | YES | Plugin/platform order ID (UNIQUE per tenant — natural key) |
| `customer_email` | string | YES | Customer reference is by email (not external_id). Engine lowercases on ingest. |
| `ordered_at` | ISO 8601 | YES | Order placement timestamp |
| `total_amount` | number | YES | Grand total charged — **gross/tax-inclusive**, incl. shipping, after discounts (see Amount semantics) |
| `discount_amount` | number | NO | Total discount (default 0) |
| `currency` | string (ISO 4217) | NO | Default `EUR`. Stored **as sent** — not strictly ISO-validated. |
| `status` | enum | YES | `completed` / `processing` / `cancelled` / `refunded`. **Required** — a missing or out-of-enum `status` is a per-item `errors[]` entry. |
| `smaily_rec_id` | UUID v4 string | NO | Attribution: which recommendation was clicked pre-purchase (from cookie). Stored; consumed by the async attribution cron (see below). **UUID-validated — a malformed value rejects the whole order** (see below). |
| `smaily_visitor_token` | string | NO | Visitor token from the visitor-token cookie: an engine-issued `vt_` token, or a token the store created at checkout (`vs_`, v1.11.0 — [below](#store-created-visitor-token)). Stored; attribution is async. Also binds the token to the order's customer (below). |
| `smaily_rec_ctx` | string | NO | Attribution: context (from the `smaily_rec_ctx` cookie). Never decides *which* recommendation matches. Since v1.9.0 it decides the **channel**: `storefront` with a cookie match = a storefront credit (§15). Forward it on every order that carries `smaily_rec_id`. |
| `session_id` | string | NO | Accept-and-ignore (PRO-1544): stored on the order, but no longer read by the attribution matcher — the browse-event/`session_id` matching step it used to feed was removed in PRO-1524. Kept accepting it so senders don't need a plugin update. |
| `items[]` | array | YES | Order line items |
| `items[].sku` | string | YES | |
| `items[].qty` | integer | YES | Quantity |
| `items[].unit_price` | number | YES | Per-unit price, **gross** (see Amount semantics) |
| `items[].line_total` | number | YES | Line total, **gross**, after line discounts (see Amount semantics) |
| `items[].discount_amount` | number | NO | Line-specific discount |
| `items[].returned_at` | ISO 8601 | NO | When this line came back. See [Return signals](#return-signals). |
| `items[].return_reason_standardised` | enum | NO | Why it came back, from the engine's 7-value vocabulary. **An unrecognised value is never rejected** — see [Return signals](#return-signals). |
| `items[].return_reason_raw` | string | NO | The platform's verbatim reason string (Shopify `returnReasonNote` or reason-definition handle, Woo's free-text refund reason). Truncated to 500 chars, never rejected. Diagnostic only. |

<a id="return-signals"></a>
**Return signals** (v1.8.0) — all three fields are **optional and nullable**, on the line, not the order:

- **`returned_at`** marks this line as returned. It has been accepted since the first release and is documented here for the first time. It drives returned-item suppression (the same SKU is not recommended back to that customer for 180 days), the fit-anxiety learner, and the "was it kept?" preconditions on several triggers. A *different* SKU (a substitute) always stays recommendable — a return suppresses the item, not the recovery.
- **`return_reason_standardised`** is one of: `size_small`, `size_large`, `not_as_pictured`, `defect`, `wrong_item`, `changed_mind`, `other`. Nothing in the engine consumes it yet; it is stored so the signal accumulates before the rules that need it exist.
- **Known vocabulary divergence (engine-internal, no sender impact).** The fashion sector source document groups return reasons into five families (fit / expectation / quality / damage / remorse) rather than these seven values; a mapping from this enum onto those families will be defined when the fashion return-recovery consumer lands. Senders keep sending the 7-value enum above — nothing on the wire changes.
- **Unrecognised reason values are stored as `other` — never rejected.** A value the engine has not deployed does not fail the item, does not fail the order, and does not produce an `errors[]` entry; the original string is preserved in `return_reason_raw` if the sender did not supply its own. This is deliberate: it means the vocabulary can be widened later with an engine deploy alone, with no plugin release and no coordinated rollout. Senders must therefore **not** validate against a closed copy of this list. (Removing or renaming a value would be breaking, so the list starts narrow.)
- **Do not guess.** Send a standardised value only where the platform's own value maps unambiguously (Shopify's return reasons do; a merchant's free-text RMA note does not). Keyword-guessing free text into `defect` or `size_small` is worse than sending nothing — it feeds invented verdicts into learning. When in doubt: `other` plus the raw string, or omit the reason entirely.
- **`return_reason_raw` is diagnostic only** — never a ranking or gating input, never rendered into an email. Send the merchant/system-side note (Shopify `returnReasonNote`, the definition handle, Woo's refund reason). **Do not send buyer-written free text** (Shopify's `customerNote`): it carries personal data and no analytical value the enum lacks.
- **A fully-refunded order is derived engine-side as all lines returned.** When `status: "refunded"` arrives, every line of that order is stamped returned at the order's `ordered_at` — so **senders need not send `returned_at` for a full refund**. An explicitly sent `returned_at` always wins over the derivation. Senders **SHOULD** still send `returned_at` per line for **partial or line-level returns**, which the order status cannot express (on WooCommerce a partial refund does not even change the order status).
- **A return is whole-line, not per-unit — a partially-refunded quantity stays KEPT.** `returned_at` marks the *line*; there is no per-unit return field. So a line of `qty: 3` with one unit refunded is **not** flagged returned: the customer still owns two of them, and the engine keeps treating that SKU as kept and recommendable. Only send `returned_at` when the line came back in full. This deliberately under-reports returns rather than suppressing a product the customer still has — a wrong suppression costs a real recommendation slot, a missed return costs only a signal the consumers already tolerate as best-effort (above). A `returned_qty` widening is **deferred** until a fashion pilot shows real partial-return volume; nothing on the wire changes until then. (Erkki decision, 2026-08-05, PRO-1597.)
- **Return signals are best-effort.** Smaller stores routinely process refunds off-platform and will send nothing at all here; WooCommerce has no returns concept in core and no structured reason anywhere in its ecosystem. The engine degrades gracefully on NULL — every consumer treats "no return recorded" as "kept", which is the correct default.
- **Forward-only.** No historical backfill of returns is expected or required. Both consumers are windowed (180 days / 365 days), so the signal fully matures within ~6 months of switch-on. Do not read a low return rate in the first months as a real one.
- ⚠️ **Items are fully replaced on order re-ingest** (see below), so a later sync of a returned order that omits these fields **erases the return**. Whoever pushes a return must keep pushing it on every subsequent sync of that order. (The derived full-refund case is self-healing — it is re-derived from `status` on every sync.)

**Amount semantics (tax basis)** (v1.4.0):
- All money fields on this endpoint are **gross amounts — tax-inclusive, in the order's `currency`**: what the customer actually paid.
- `total_amount` = the order's grand total as charged (products + shipping + tax − discounts). This was already every sender's de-facto behavior.
- `items[].line_total` = the line's charged amount **including its share of tax**, after line-level discounts. `items[].unit_price` = the same gross basis ÷ qty. `discount_amount` fields are gross.
- Platform notes: **Shopify** — `taxes_included=true` shops send line prices as-is (already gross); `taxes_included=false` shops add the line's `tax_lines` sum. **WooCommerce** — `$item->get_total() + $item->get_total_tax()` (NOT bare `get_total()`, which is net). **Magento** — `row_total_incl_tax` (current Magento payloads already conform).
- Sender invariant: `Σ items[].line_total + shipping ≈ total_amount` (± rounding). The engine may monitor drift as a data-quality signal; it does not reject on it.
- The engine stores amounts as sent and never recomputes tax. Rows ingested on the wrong basis are corrected by re-syncing the affected orders (re-ingest fully replaces line items — see [Idempotency](#idempotency)).

**Items are fully replaced on update.** Re-ingesting an existing `external_order_id` UPSERTs the order and **fully replaces its line items** (the previous `order_items` are deleted and the new set inserted) — the order is the unit, not the line item. Send the complete item set on every order.

<a id="attribution-fields-on-resync"></a>
**Attribution fields on a later sync** (v1.12.1, PRO-4051). Unlike the items, the order's attribution fields are not cleared by omission. When a later sync of the same `external_order_id` omits `smaily_rec_id`, `smaily_rec_ctx`, `smaily_visitor_token` or `session_id`, the engine keeps the value that an earlier sync stored. A later sync that carries a value replaces the stored value. To keep a stored value, **omit** the field:
- `null` on any of these four fields fails per-order validation: the order lands in `errors[]` and is not written, as for a [malformed `smaily_rec_id`](#rec-id-uuid-validation).
- `""` is a value, not an omission. On `smaily_rec_id` it rejects the order; on `smaily_rec_ctx`, `smaily_visitor_token` and `session_id` it replaces the stored value with an empty string.

A later sync that adds a `smaily_visitor_token` binds it to the order's customer ([below](#store-created-visitor-token)). Purchase credit is computed once per order, by the first attribution run after the order is written (see *Attribution is asynchronous* below): a field that arrives after that run is stored, but it does not change the credit already computed.

**Response 200 OK** (per-order partial success — the D6 contract):
```json
{
  "ok": true,
  "processed": 8,
  "deduplicated": 1,
  "errors": [
    {"index": 4, "external_order_id": "WC-99", "field": "status", "message": "Invalid enum value. Expected 'completed' | 'processing' | 'cancelled' | 'refunded', received 'shipped'"}
  ]
}
```

- `processed` = orders UPSERTed; `deduplicated` = orders whose `event_id` was already seen; `errors` = orders that failed per-item validation, as `{index, external_order_id?, field, message}` (`index` is position in `orders[]`). Each order is validated independently — an invalid order goes to `errors[]` and is not written, while valid orders in the same batch still process. A rejected order's `event_id` is not registered (a corrected retry processes).
- **Invariant:** `processed + deduplicated + errors.length == total orders`.
- `deduplicated_all: true` is added when every order was deduplicated (pure no-op retry), e.g. `{"ok":true,"processed":0,"deduplicated":1,"errors":[],"deduplicated_all":true}`.
- The response carries **no** `created`/`updated`/`skipped`, no `request_id`, and **no attribution counts** — attribution is computed asynchronously (below).

**Attribution is asynchronous.** The orders ingest route only **stores** the attribution signals (`smaily_rec_id` / `smaily_visitor_token` / `session_id` / `smaily_rec_ctx`) on the order. A separate cron (`process-order-attributions`, every 15 minutes) then computes `rec_attribution` rows via the 4-step matching below — **after** ingest, once browse events and recommendations have settled. Attribution counts are therefore **not** in the ingest response.

Matching steps (run by the cron; PRO-1524, 2026-07-23 — a former 4th-priority
`browse_events`/`session_id` fallback was removed here, see Appendix E):
1. If `smaily_rec_id` is present → look up `recommendations`, verify customer match → `rec_attribution` with `attribution_type` `direct` / `exact_later` / `indirect_*` (by SKU match + time gap).
2. Else if `smaily_visitor_token` is present → `visitor_tokens` → recent recommendations → match.
3. Else → most recent email `click` for this customer with a `recommendations` link, within the match window (default **30 days**; per-tenant override via `tenant_settings.attribution_match_window_days`) → match.
4. No match → `rec_attribution` with `attribution_type='control_purchase'`, `outcome_score=0.0` (a softer `assisted_open` tier may also apply here — see `lib/engine/attribution/match-purchase-to-rec.ts`).

`smaily_rec_ctx` never decides which recommendation matches. Since v1.9.0 it decides the channel of a step-1 (cookie) match: when the order carries `smaily_rec_ctx: "storefront"`, the purchase is credited to the storefront (§15) — no email click time and no email campaign — else to the email. Detailed logic lives in `lib/engine/attribution/`.

<a id="store-created-visitor-token"></a>
**Store-created visitor token** (v1.11.0, PRO-3844). An engine `vt_` token exists only for a shopper who clicked a Smaily email link. So that a **guest buyer** can be recognised on a later visit ([§15](#15-post-apiv1recommendationscustomer)), the store may create a token itself at checkout and send it on this order's existing `smaily_visitor_token` field. There is no new field.

- **Format.** `vs_` followed by exactly 22 characters from `[A-Za-z0-9]`, from a cryptographically secure random source (for example 16 random bytes, base62). The prefix shows in the data that the store created the token.
- **Consent.** Create and send a `vs_` token **only for a shopper who gave marketing consent** at checkout. Without consent, create no token and send none.
- **Cookie.** Store the token in the visitor-token cookie (default name `smaily_rec_uid`, [cookie names](#cookie-names-plugin-side-management)). A cookie the **server sets** (`Set-Cookie` header) is recommended: Safari deletes cookies set by page JavaScript after 7 days. When the shopper already has a visitor-token cookie (an engine `vt_` token from an email link), keep it and send that value — do not replace it with a new token.
- **What the engine does.** When the order is written, the engine binds the token to the order's customer (the `customer_email` customer). An unknown `vs_` token is created for that customer. A token that is already bound to the **same** customer is renewed. A token that is already bound to **another** customer does not move — the [one-customer-per-token rule](#one-customer-per-visitor-token) — and the order is still written. A customer who objected to profiling ([§10](#10-post-apiv1customeremailopt-out)) is not bound.
- **Lifetime** (v1.14.0, PRO-4116). Every visitor token lives **14 months from its last activity**: its creation, and each order that carries it. Every later order that carries the same token renews it to 14 months from that order; a token is never shortened. An engine `vt_` token that is never carried on an order lives 14 months from issue.
- **An invalid value is ignored, never rejected.** A value that starts with `vs_` but does not match the format is stored on the order like any other value and creates no binding; the order is written normally and there is no `errors[]` entry.
- **No credit by itself.** A store-created token names the shopper; it carries no recommendation, so it never makes a purchase an email or storefront credit. Purchase credit still comes from `smaily_rec_id` / `smaily_rec_ctx` and email clicks, as above.
- **Response.** The binding is not reported in the response; the response shape does not change.

<a id="rec-id-uuid-validation"></a>
**`smaily_rec_id` is UUID-validated, and a bad value costs the order — not just the field.** The value is a `recommendations.rec_id`, so the route types it as a UUID (`z.string().uuid()`). A present-but-malformed value (a truncated cookie, a `rec_abc123`-style placeholder, an empty string) fails per-order validation: that order lands in `errors[]` with `field: "smaily_rec_id"` and is **not written** — the order's revenue is lost to the engine, not merely its attribution. This is the standard per-order rejection path described above: the rest of the batch still processes, the rejected order's `event_id` is not registered, and a corrected retry writes it normally. **Sender rule**: omit the field entirely when there is no cookie or the cookie value is not a well-formed UUID. Omitted is always safe — the order then attributes through the visitor-token or email-click mechanism, or as `control_purchase`. Do not send `""` or `null` ([attribution fields on a later sync](#attribution-fields-on-resync)).

**Idempotency**: two layers, as described in [Idempotency](#idempotency):
- **Layer 1**: `(tenant_id, external_order_id)` natural-key UPSERT (items are fully replaced on update).
- **Layer 2**: optional per-order `event_id` for retry deduplication (integer counts).

---

### 6. POST /api/v1/ingest/browse

Browse events batch. The highest-volume endpoint.

**URL**: `POST /api/v1/ingest/browse`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 500 req/sec (higher than other endpoints), up to 100 events per request

**Event types** (the full `event_type` enum — exactly these 9; any other value → a per-item `errors[{field: event_type}]`):
- `product_view` — customer opened a product page
- `category_view` — customer opened a category page
- `search` — customer searched
- `cart_add` — customer added to cart
- `cart_remove` — customer removed from cart
- `wishlist_add` — customer added to wishlist (if the platform supports it)
- `wishlist_remove` — customer removed from wishlist
- `checkout_start` — customer began checkout
- `checkout_complete` — checkout completed (order created) — **in addition to the orders endpoint**

> `checkout_start` / `checkout_complete` are currently **accept + store only** — persisted as ordinary browse events; no checkout-specific logic (abandonment detection, checkout-driven recommendations) is implemented yet.

**Request body**:
```json
{
  "events": [
    {
      "event_id": "evt_7d8f3a2b-4e1c-...",
      "session_id": "wp_sess_abc123",
      "event_type": "product_view",
      "sku": "ACA-DOG-3KG",
      "category_path": "food/dry",
      "dwell_seconds": 45,
      "event_ts": "2026-05-19T10:15:23Z",
      "source": "plugin_woo",
      
      "customer_email": "mari@example.com",
      "smaily_visitor_token": "vt_8f3k2a9xq4",
      "smaily_rec_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
      "smaily_ctx": "cart_abandoned",
      "external_id": "67"
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | YES | Plugin-generated, transport-level dedup key. Required for browse (no natural key). |
| `session_id` | string | YES | Plugin manages the session cookie (`smaily_anon_sid`) |
| `event_type` | enum | YES | See event types list |
| `sku` | string | NO | Required for `product_view`, `cart_add`, `cart_remove` |
| `category_path` | string | NO | Required for `category_view` |
| `search_query` | string | NO | Required for `search` |
| `dwell_seconds` | integer | NO | Time on page (for `product_view`) |
| `event_ts` | ISO 8601 | YES | Event occurrence timestamp |
| `source` | string | NO | Defaults to `web` if omitted. Constant: `web`, `plugin_woo`, `plugin_shopify`, `plugin_magento`, `make`, `custom`. The engine stores `source` as an opaque label (not enum-validated); senders must use their listed constant so per-source analytics stay clean. |
| `customer_email` | string | NO | Identity hint (if user is logged in). **Deprecated for client-originated senders — see below.** |
| `smaily_visitor_token` | string | NO | Identity hint (from cookie) |
| `smaily_rec_id` | UUID v4 string | NO | **Deprecated, accept-and-ignore as of v1.7.0 — see below.** Accepted on the wire; no longer persisted or consulted. Still **UUID-validated** when present — a malformed value rejects that event. Prefer omitting it. |
| `smaily_ctx` | string | NO | **Deprecated, accept-and-ignore as of v1.7.0 — see below.** Accepted on the wire; no longer persisted or consulted. |
| `external_id` | string | NO | Platform user_id |

> **Deprecated for client-originated senders (2026-07-21 decision, PRO-1500): `customer_email` as a browse-event identity hint.** No legitimate client-side producer for this field exists — a storefront beacon has no verified email of its own to send, and a value handed to client-side JS is trivially spoofable. The supported identity path for a logged-in shopper is the visitor-token cookie (`smaily_visitor_token`, resolved server-side against `visitor_tokens`) plus the explicit [`POST /api/v1/identity/merge`](#7-post-apiv1identitymerge) call on login ([§7](#7-post-apiv1identitymerge)) — not a client-echoed email. **Server-side senders** (a Make-flow-style integration running on the merchant's own server, which already holds a verified email — typically `source: "make"`) **may continue sending `customer_email` on browse events during the grace period.** This release documents the deprecation only: per this contract's additive-MINOR discipline (nothing existing changes shape or behavior on a MINOR), the engine still accepts and resolves `customer_email` from every sender exactly as in the table above — no accept-and-ignore code change ships in v1.6.0. A future contract change will move client-originated `customer_email` to accept-and-ignore semantics (accepted on the wire, no longer consulted by the identity-resolution steps below); that is not yet implemented and is tracked separately.

> **Deprecated, accept-and-ignore effective this release (2026-07-23 decision, PRO-1524 / PRO-1465): `smaily_rec_id` and `smaily_ctx` as browse-event attribution hints.** Both are client-originated — read from the `smaily_rec_id` / `smaily_rec_ctx` cookies the plugin sets from a personalized product-link's `smaily_rec` / `smaily_ctx` [URL parameters](#url-parameters-on-campaign-links) — and, like `customer_email` above, spoofable client-side with no server-side verification. **Unlike `customer_email`, there is no grace period for a legitimate server-side sender to preserve**: rec-link attribution runs on the order-level cookie→order path (`smaily_rec_id` / `smaily_rec_ctx` on [`POST /api/v1/ingest/orders`](#5-post-apiv1ingestorders), §5) and identity resolution on the visitor-token path; the browse-event echo of these two fields only ever fed the 4th-priority (lowest) fallback in the order-attribution matching (`browse_events` row with a `smaily_rec_id` link within the match window), which had never actually matched a purchase in production. **Effective this release, the engine accepts both fields on the wire without error but no longer persists or consults them** — sending them is a harmless no-op, not a rejection, so no sender needs to change anything before their own next release. Server-side detail: both `browse_events.smaily_rec_id` and `browse_events.smaily_ctx` are dropped from the schema entirely (migrations `0080`/`0081`) — the 4th-priority fallback itself was removed from `lib/engine/attribution/match-purchase-to-rec.ts` in the same change (grep-verified zero remaining readers). §5's matching-steps list now reflects the resulting 3-mechanism ladder.

**Identity resolution flow** (engine-side):

For each event, the engine resolves `customer_id` as follows:
1. If `smaily_visitor_token` is present → look up `visitor_tokens` → resolve customer_id
2. Else if `customer_email` is present → look up `customers` by email → resolve customer_id
3. Else if `external_id` is present → look up `customers` by external_id → resolve customer_id
4. Otherwise → INSERT browse_event with `customer_id = NULL` (anonymous)

**Retroactive binding**: when `customer_id` is resolved (steps 1–3), the engine UPDATEs all earlier `browse_events` with the same `session_id` where `customer_id IS NULL`. The customer gets full session history even if their first click was anonymous. When the event also carries `smaily_visitor_token`, the engine does the same for earlier anonymous events with the same token (cross-session).

<a name="one-customer-per-visitor-token"></a>
**One customer per visitor token** (v1.8.2, PRO-3649): a link can set the `smaily_vt` cookie, so the token on a device says nothing about who uses that device next. Once a visitor token is bound to a customer, the engine never binds that token's browsing — earlier or later — to a different customer; the first binding wins, with no age limit. A token is bound when the engine issued it for a customer (`visitor_tokens`) or when earlier browse events carrying it already belong to a customer. A token that is not bound yet binds exactly as described above. When the token belongs to someone else, the event itself still resolves by `customer_email` / `external_id` (steps 2–3) and its session still binds; only the token-based retroactive binding is skipped. The response shape does not change (`retroactive_bound` simply excludes the skipped rows). Senders change nothing.

**Profiling opt-out (Art 21) — engine-side enforcement at ingest:** if the resolved customer has opted out ([§10](#10-post-apiv1customeremailopt-out)), the engine does **not** bind the event on any resolution path (visitor token, email, external_id); the event is stored **anonymous** (`customer_id = NULL`) and excluded from retroactive binding. Enforcement is engine-side because the visitor token is engine-issued — a sender cannot know which customer it maps to, so the token path cannot be filtered client-side.

**Sender-side anonymous mode (recommended, complementary):** when the shopper has not granted profiling consent, the beacon should omit the identity hints (`customer_email`, `external_id`, `smaily_visitor_token`) and still send the event with `session_id` + `event_id` (data minimization; anonymous events still feed popularity/co-view signals). Sender omission is a courtesy layer; the engine-side gate is the guarantee.

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 23,
  "deduplicated": 0,
  "errors": [],
  "with_customer_match": 18,
  "anonymous": 5,
  "retroactive_bound": 3,
  "duplicates_skipped": 0
}
```

- `processed` = events INSERTed (`= with_customer_match + anonymous`).
- `deduplicated` = events whose `event_id` was already in `ingest_event_log`. `duplicates_skipped` is a **backward-compatible alias** of the same count (both fields are always present).
- `with_customer_match` / `anonymous` / `retroactive_bound` = informational sub-counts of `processed`.
- `errors` = events that failed per-item validation (see partial success below).
- **Invariant:** `processed + deduplicated + errors.length == total events`.

**Per-item partial success (D6).** Each event is validated **independently**: an invalid event goes to `errors[]` as `{index, field, message}` (no natural key — browse events aren't natural-key identifiable) and is not INSERTed; valid events in the same batch are still processed. Because browse has **no Layer-1 natural-key fallback**, a **missing `event_id` is a per-item error** (not a silent no-dedup insert). Example — one invalid `event_type`, one missing `event_id`:
```json
{
  "ok": true,
  "processed": 1,
  "deduplicated": 0,
  "errors": [
    {"index": 1, "field": "event_type", "message": "Invalid enum value..."},
    {"index": 2, "field": "event_id", "message": "Required"}
  ],
  "with_customer_match": 0,
  "anonymous": 1,
  "retroactive_bound": 0,
  "duplicates_skipped": 0
}
```
The **wrapper stays all-or-nothing**: a non-array / empty / >1000 `events` → `400 validation_failed`.

**Response 200 OK (deduplicated retry, all events)**:
```json
{
  "ok": true,
  "processed": 0,
  "deduplicated": 5,
  "errors": [],
  "with_customer_match": 0,
  "anonymous": 0,
  "retroactive_bound": 0,
  "duplicates_skipped": 5,
  "deduplicated_all": true
}
```

Returned when every event in the request was already processed (matching `event_id` already in `ingest_event_log`). `deduplicated` is the integer count of skipped events (and `duplicates_skipped` is a backward-compatible alias of the same count). Because browse has no Layer-1 fallback, per-item `event_id` is what prevents a retry from duplicating events — each event deduplicates independently (a 5-event retry yields `deduplicated: 5`, not a single whole-request hit).

**Idempotency**: `event_id` is **required** for browse (unlike other ingest endpoints where it's optional). Browse events have no natural-key UPSERT to fall back on — the same customer may legitimately view the same SKU repeatedly. The `event_id` is the only protection against retry duplicates. Storage: `(tenant_id, event_id)` in `ingest_event_log`, 90-day permanent retention.

**Browse events are NOT semantically idempotent** — the same customer may view the same SKU three times in a day, producing three legitimate events. The `event_id` layer protects against transport-level retries (network errors, queue replays), not against legitimate repeated activity.

---

### 7. POST /api/v1/identity/merge

Anonymous visitor → known customer manual merge. The plugin calls this when a customer logs in and we have cookies tied to an anonymous session that we now want to bind to the customer.

**URL**: `POST /api/v1/identity/merge`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Use case**:
1. Mari arrives at the store via a `?smaily_vt=vt_8f3k2a9xq4` link → plugin sets the cookie, starts an anonymous session
2. Mari browses a few pages; the plugin sends browse events with `smaily_visitor_token` — the engine resolves customer_id (Mari) → events are linked to Mari
3. **However, when** the plugin sees Mari is **now logged in** (`is_user_logged_in()` returns true) — but `customer_email` wasn't previously known — the plugin calls `identity/merge` to explicitly preserve the **session-anon-history → known-customer** mapping.

**Request body**:
```json
{
  "anon_session_id": "anon_sess_uuid_v4_from_cookie",
  "smaily_visitor_token": "vt_8f3k2a9xq4",
  "customer_email": "mari@example.com",
  "customer_external_id": "67",
  "merge_ts": "2026-05-19T10:15:23Z",
  "merge_reason": "user_logged_in"
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `anon_session_id` | UUID | NO | Plugin-side anon-session cookie (`smaily_anon_sid`) |
| `smaily_visitor_token` | string | NO | Token from email click (`smaily_rec_uid` cookie) |
| `customer_email` | string | YES | Known customer identity |
| `customer_external_id` | string | NO | Platform user_id |
| `merge_ts` | ISO 8601 | YES | Merge timestamp |
| `merge_reason` | enum | NO | `user_logged_in`, `email_provided_at_checkout`, `manual_admin` |

At least **one** of `anon_session_id` or `smaily_visitor_token` must be present (otherwise there's nothing to merge).

**Response 200 OK**:
```json
{
  "ok": true,
  "customer_id": "550e8400-...",
  "merged": {
    "browse_events_updated": 12,
    "visitor_tokens_bound": 1,
    "session_history_days": 22
  }
}
```

`browse_events_updated` = anonymous events bound to customer_id on this call (already-bound rows are excluded by the `customer_id IS NULL` filter, so a repeat merge reports 0, not a separate already-bound count).
`session_history_days` = how many days the bound events reach back (gives a sense of how much history the customer recovered).

**Response 404 Not Found**:
```json
{
  "error": "customer_not_found",
  "message": "No customer found with email mari@example.com. Send via POST /api/v1/ingest/customers first."
}
```

**Idempotency**: same merge twice = no-op (events already bound; the second call's `browse_events_updated` reports 0).

**One customer per visitor token** (v1.8.2, PRO-3649 — same rule as [§6](#one-customer-per-visitor-token)): if `smaily_visitor_token` is already bound to a different customer, the merge binds nothing by the token — no browse events move and the `visitor_tokens` row keeps its customer. The `anon_session_id` part of the same call binds as before. The response is still `200` with the same shape; the token part simply contributes `0` to `browse_events_updated` and `visitor_tokens_bound`. The same customer merging their own token, or a token that is not bound yet, behaves as before. Senders change nothing and should not retry.

---

### 8. GET /api/v1/customer/{email}/export

GDPR data export. Returns all engine-held data for the customer.

**URL**: `GET /api/v1/customer/{email}/export`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 req/60 seconds per tenant (privacy-relevant endpoint)

**URL parameters**:
- `{email}` — URL-encoded email address

**Query parameters**:
- `format` — optional, `json` (default) or `ndjson` (for large datasets)
- `since` — optional, ISO 8601 — filter events from this timestamp forward

> **Consent is not exported.** The engine does not store consent (see §4) — Smaily owns it. The export contains **no `consent` object**; a reader must not assume consent state is recoverable from this endpoint. Identity is **email-only** (no `smaily_contact_id`, dropped in W4).

**Response 200 OK** (`format=json`) — the example below is the **real deployed body** (curl-verified). `customer`, `orders`, and `order_items` are full table rows; the event arrays (`browse_events`, `email_events`, `recommendations`, `visitor_tokens`) are returned as `[]` when empty and otherwise carry the full rows of the respective table:
```json
{
  "export_metadata": {
    "exported_at": "2026-06-04T09:43:37.540Z",
    "tenant_id": "550e8400-...",
    "customer_email": "mari@example.com",
    "customer_id": "660f9500-...",
    "data_retention_policy": {
      "browse_events": "14 months",
      "email_events": "14 months",
      "recommendations": "730 days",
      "orders": "indefinite: kept as long as the merchant uses the service, or until deleted on request",
      "replaced_recommendations": "180 days after the replacement; one still linked to a click or purchase record is kept as long as that record, at most 730 days",
      "decision_log": "30 days",
      "visitor_tokens": "14 months from the token's last activity (its creation, or the latest order that carried it)",
      "rec_attribution": "730 days",
      "customer_engagement_history": "730 days",
      "trigger_candidates": "30 days after the engine acted on the signal, or 90 days after detection if it never acted; one still linked to an issued recommendation is kept as long as that recommendation",
      "edge_weights_log": "90 days",
      "ingest_event_log": "90 days",
      "customers": "indefinite: kept as long as the merchant uses the service, or until deleted on request",
      "catalog": "indefinite: kept as long as the merchant uses the service, or until deleted on request"
    },
    "data_retention_purpose": {
      "browse_events": "comparison with the same month last year (seasonal purchases), plus two months of margin",
      "email_events": "comparison with the same month last year (seasonal purchases), plus two months of margin",
      "recommendations": "two-year reporting and purchase look-back",
      "orders": "providing the service",
      "replaced_recommendations": "crediting a later click or purchase to the email that showed the product, and not repeating products too soon",
      "decision_log": "explaining why a recommendation was chosen (support and error tracing)",
      "visitor_tokens": "recognising a returning shopper in the store (storefront recommendations, crediting a purchase) across a year of repeat purchases, plus two months of margin",
      "rec_attribution": "two-year reporting and purchase look-back",
      "customer_engagement_history": "two-year reporting and purchase look-back",
      "trigger_candidates": "acting on the signal in the next recommendations; a signal the engine never acted on no longer describes the customer",
      "edge_weights_log": "checking and undoing a wrong change to a rule weight",
      "ingest_event_log": "recognising data the store plugin sends twice, so that it is stored once",
      "customers": "providing the service",
      "catalog": "providing the service"
    }
  },
  "customer": {
    "customer_id": "660f9500-...",
    "tenant_id": "550e8400-...",
    "email": "mari@example.com",
    "first_seen_at": "2026-01-15T10:30:00Z",
    "last_purchase_at": "2026-05-15T14:30:00.000Z",
    "last_email_open_at": null,
    "last_email_click_at": null,
    "last_session_at": null,
    "rfm_recency": null,
    "rfm_frequency": null,
    "rfm_monetary": null,
    "segment": null,
    "segment_confidence": null,
    "engagement_state": null,
    "engagement_state_since": null,
    "engagement_score": null,
    "engagement_trajectory": null,
    "loyalty_signals": {},
    "discount_sensitivity": "0.50",
    "preferred_send_window": null,
    "exploration_credits": 0,
    "exploration_credits_at": null,
    "cold_start_tier": 0,
    "inferred_species": null,
    "inferred_attributes": {},
    "custom_fields": {},
    "first_name": "Mari",
    "opted_out": false,
    "opted_out_at": null,
    "external_id": null,
    "country": "EE",
    "last_name": "Tamm",
    "language": "et",
    "phone": null
  },
  "orders": [
    {
      "order_id": "82f231e1-...",
      "tenant_id": "550e8400-...",
      "customer_id": "660f9500-...",
      "external_order_id": "WC-12345",
      "ordered_at": "2026-05-15T14:30:00.000Z",
      "total_amount": "67.50",
      "discount_amount": "0.00",
      "currency": "EUR",
      "status": "completed",
      "smaily_rec_id": null,
      "smaily_visitor_token": null,
      "session_id": null,
      "smaily_rec_ctx": null
    }
  ],
  "order_items": [
    {
      "item_id": 36857,
      "order_id": "82f231e1-...",
      "tenant_id": "550e8400-...",
      "sku": "ACA-DOG-3KG",
      "qty": 1,
      "unit_price": "67.50",
      "discount_amount": "0.00",
      "line_total": "67.50",
      "returned_at": null,
      "source_rec_id": null
    }
  ],
  "browse_events": [],
  "email_events": [],
  "recommendations": [],
  "visitor_tokens": []
}
```

> **`data_retention_policy`** (v1.13.0, PRO-4108) lists every kind of data the engine's privacy policy (§4 "How long we keep data") lists, with the period after which the engine's nightly cleanup deletes it. Each value is a human-readable string — show it, do not parse it. `recommendations` is the period for an issued recommendation that was not replaced by a newer one; `replaced_recommendations` is the period for a replaced one. `orders`, `customers` and `catalog` are never deleted automatically while the store uses the service; their values start with `indefinite`. When the merchant closes the store's Smaily account, all of the store's data is deleted; removing the plugin only pauses the store ([§16](#16-post-apiv1storeuninstall)). Keys are only added, never renamed or removed, within contract 1.x.

> **`data_retention_purpose`** (v1.14.0, PRO-4116) has the same keys as `data_retention_policy` and says why each period is as long as it is. Each value is a human-readable string — show it, do not parse it. The three tiers: behavioural signals (`browse_events` for identified and anonymous visitors, `email_events`) are kept **14 months from the event**; visitor tokens **14 months from their last activity**; outcomes (`recommendations`, `rec_attribution`, `customer_engagement_history`) **730 days**; master data (`customers`, `orders`, `catalog`) while the store uses the service.

> **Note**: `order_items` is a **top-level** array (not nested under `orders`); join on `order_id`. The export does **not** include a `rec_attribution` array (the engine's export omits attribution rows) — if the plugin needs attribution data for a subject-access request, request it separately; it is not part of this endpoint's payload today.

**Response 404 Not Found** if the customer doesn't exist.

**Response 200 OK with empty data** (customer exists but has no data yet):
```json
{
  "export_metadata": { },
  "customer": { },
  "orders": [],
  "order_items": [],
  "browse_events": [],
  "email_events": [],
  "recommendations": [],
  "visitor_tokens": []
}
```

**Idempotency**: read-only; all exports are identical (same data state).

**Audit log**: every export is logged to the `gdpr_audit_log` table — tenant_id, customer_email, action=`'export'`, performed_at, source (`'plugin'` or `'admin_ui'`).

---

### 9. DELETE /api/v1/customer/{email}

GDPR full data deletion.

**URL**: `DELETE /api/v1/customer/{email}`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 req/60 seconds per tenant

**URL parameters**:
- `{email}` — URL-encoded email address

**Request body** (optional confirmation):
```json
{
  "confirm": true,
  "reason": "user_request"
}
```

`reason` may be: `user_request`, `admin_action`, `legal_obligation`, `account_closure`.

**Response 200 OK**:
```json
{
  "ok": true,
  "deleted": true,
  "customer_email": "mari@example.com",
  "records_removed": {
    "customer": 1,
    "orders": 5,
    "order_items": 18,
    "browse_events": 247,
    "email_events": 89,
    "recommendations": 145,
    "rec_attribution": 67,
    "visitor_tokens": 3,
    "trigger_candidates": 4
  },
  "audit_log_id": "audit_uuid_...",
  "deleted_at": "2026-05-19T10:15:23Z"
}
```

`audit_log_id` references the `gdpr_audit_log` row that records the deletion fact (not its content) — **required for GDPR compliance as proof**.

**What persists after DELETE**:
- The `gdpr_audit_log` row (date + email + action; no content)
- Aggregated metrics in `lift_metrics_daily` (anonymized; contains no email/customer_id)

**What is deleted**:
- All tables referencing customer_id (ON DELETE CASCADE)
- `visitor_tokens` (so future email clicks won't re-identify Mari)

**Response 404 Not Found** if the customer doesn't exist.

**Idempotency**: same DELETE twice = the second call returns 404 (customer already deleted). The plugin should treat 404 as a successful operation.

---

### 10. POST /api/v1/customer/{email}/opt-out

GDPR opt-out: data is retained, but the customer is excluded from future recommendations.

**URL**: `POST /api/v1/customer/{email}/opt-out`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Use case**: the customer clicked an "Exclude me from the recommendation system" link in a contact form. The plugin calls this endpoint. The engine flags the customer `opted_out=true` — every future `issue-daily-recommendations` cron run skips this customer.

**URL parameters**:
- `{email}` — URL-encoded email

**Request body**:
```json
{
  "opt_out": true,
  "reason": "user_preference",
  "opted_out_at": "2026-05-19T10:15:23Z"
}
```

**Reverse** (opt back in):
```json
{
  "opt_out": false,
  "reason": "user_preference"
}
```

**Response 200 OK**:
```json
{
  "ok": true,
  "customer_email": "mari@example.com",
  "opt_out_status": true,
  "previous_status": false,
  "effective_at": "2026-05-19T10:15:23Z",
  "next_recommendations_cron": "2026-05-20T05:00:00Z"
}
```

`next_recommendations_cron` tells when the next cron run will skip the customer (useful for audit).

**Response 404 Not Found** if the customer doesn't exist.

**Idempotency**: same opt-out twice = second call returns `previous_status: true, opt_out_status: true` (no-op but successful).

**Audit log**: logged to `gdpr_audit_log` (action=`'opt_out'` or `'opt_in'`).

---

### 11. GET /api/v1/automations/catalog

**Automated emails** (added v1.1.0; setup fields added v1.15.0): the catalogue lists the engine's own automated emails for this store — emails whose audience and moment the engine decides. The engine enrols an eligible contact into the email's Smaily workflow. A plugin's own emails (welcome, abandoned cart, first order, follow-up) and the transactional order emails are **not** in this catalogue: the plugin decides when they are sent, with its own settings (v1.17.0). Their design is made in the engine too: see §22 and §23.

The plugin's role is **configuration only**: it renders this list, opens the setup page for an email (§17), shows the state the engine returns (§12), saves the store's choices (§13), and passes on the store's test-send request (§19) and go-live switch (§20). The setup page edits subject, preheader and design; the plugin does not. Execution of a catalogue email never goes through the plugin. **Fail-closed rule**: an email the merchant has not enabled never sends; the plugin must never send `enabled=true` without an explicit merchant action. Real sends start only through the go-live action (§20), after the merchant confirms it; `test_mode: false` in §13 never starts them.

Returns the automated emails available **for this store**. The list is filtered by the tenant's sector (`tenants.industry`) — e.g. `life_stage` is served only to `pet` tenants. **Render the list dynamically**: a new email appears with an engine deploy and must not require a plugin release. Do not hardcode keys, settings, rules or a fixed count.

**URL**: `GET /api/v1/automations/catalog`

**Query parameters**: none. A query parameter is ignored. (v1.15.0 published `include=engine_less`; it was never live and leaves in v1.17.0.)

**Auth**: `Authorization: Bearer sk_...` (the same tenant API key as ingest — no separate credential)

**Rate limit**: 100 req/sec (the `rate_limit_other` default, same as ingest)

**Response 200 OK**:
```json
{
  "triggers": [
    {
      "key": "replenish_due",
      "name_et": "Taastäitumine",
      "name_en": "Replenishment due",
      "description_et": "Käivitub, kui kliendi korduvtoode hakkab ennustuse järgi otsa saama (85% isiklikust ostuintervallist täis).",
      "description_en": "Fires when a customer's recurring product is predicted to run out (85% of their personal purchase interval reached).",
      "recipe_et": "Ehita Smailys \"form submitted\" trigeriga automatsioon. Kiri: \"{{rec_rep_name}} saab otsa\" + toote pilt/hind/link väljadelt rec_rep_sku/rec_rep_name/rec_rep_price/rec_rep_image_url/rec_rep_link_path ja päevad otsasaamiseni väljalt rec_rep_days (0 = otsas või kohe otsas), soovi korral soovitusslotid. Need väljad lisatakse kontaktile ainult SELLE kirja saatmise hetkel (mitte öisel sünkil) — soovitusslot 1 ega rec_replenish_sku ei pruugi olla sama toode. Mootor enrollib kontakti õigel päeval.",
      "recipe_en": "Build a Smaily automation with a \"form submitted\" trigger. Email: \"{{rec_rep_name}} is running out\" + the product picture/price/link from rec_rep_sku/rec_rep_name/rec_rep_price/rec_rep_image_url/rec_rep_link_path and the days until it runs out from rec_rep_days (0 = out or about to run out), optionally followed by the recommendation slots. These fields are added to the contact only at the moment THIS email fires (not the nightly sync) — recommendation slot 1 and rec_replenish_sku may be a different product. The engine enrols the contact on the right day.",
      "engine_backed": true,
      "started_by": "engine",
      "go_live": "store",
      "editable_settings": [
        { "key": "subject", "type": "text", "min_length": 1, "max_length": 150, "required": true, "edited_in": "setup_page" },
        { "key": "preheader", "type": "text", "min_length": 0, "max_length": 150, "required": false, "edited_in": "setup_page" },
        { "key": "cooldown_days", "type": "integer", "min": 1, "max": 365, "default": 30, "unit": "days", "required": true, "edited_in": "plugin_or_setup_page" },
        { "key": "daily_cap", "type": "integer", "min": 1, "max": 100000, "nullable": true, "default": 10, "unit": "emails_per_day", "required": true, "edited_in": "plugin_or_setup_page" },
        { "key": "test_emails", "type": "email_list", "max_items": 50, "required": false, "edited_in": "plugin_or_setup_page" }
      ],
      "engine_rules": [
        { "key": "comparison_group", "label_et": "…", "label_en": "25% of customers get no recommendation emails, so the engine can measure the extra revenue.", "params": { "holdout_percent": 25 } },
        { "key": "suppression", "label_et": "…", "label_en": "No email to a customer who ever complained, unsubscribed in the last 30 days, or bought the product after it was picked.", "params": { "unsub_days": 30 } },
        { "key": "spacing", "label_et": "…", "label_en": "At most one engine email per customer in 3 days, and none within 24 hours of a newsletter.", "params": { "min_gap_days": 3, "newsletter_quiet_hours": 24 } },
        { "key": "automatic_stop", "label_et": "…", "label_en": "Real sends stop after 1 complaint, or when more than 1% unsubscribe over at least 50 sends in 7 days. A Smaily operator reviews the stop before real sends can start again.", "params": { "complaints": 1, "optout_rate": 0.01, "optout_min_sends": 50, "window_days": 7 } },
        { "key": "overdue_limit", "label_et": "…", "label_en": "No reminder when the product is more than twice its usual interval overdue.", "params": { "overdue_multiple": 2 } },
        { "key": "real_send_switch", "label_et": "…", "label_en": "Real sends start when you switch them on. If your sender domain is not verified in Smaily yet, they start by themselves once it is.", "params": {} },
        { "key": "ramp", "label_et": "…", "label_en": "From the first real email after you switch real sends on: at most 10 emails a day for 7 days, then 25 a day for 7 days, then 50 a day for 14 days, then your daily cap.", "params": { "steps": [ { "per_day": 10, "days": 7 }, { "per_day": 25, "days": 7 }, { "per_day": 50, "days": 14 } ] } },
        { "key": "discount", "label_et": "…", "label_en": "A discount is shown only when a Smaily operator has switched discounts on for this store.", "params": { "discounts_offered": false } }
      ]
    }
  ],
  "language_modes": ["single", "per_language"],
  "docs": "https://intelligence.smaily.com/docs/en/smaily-templates"
}
```

**Field reference** (per `triggers[]` item):

| Field | Type | Notes |
|-------|------|-------|
| `key` | string | Stable machine key — the natural key for config rows (§12/§13). |
| `name_et` / `name_en` | string | Localized display name. |
| `description_et` / `description_en` | string | Localized merchant-facing description (when the trigger fires). |
| `recipe_et` / `recipe_en` | string | Localized recipe for the merchant: what the Smaily automation must contain. (`recipe_en` added v1.2.0 — against an older engine the key is absent; treat it as optional and fall back to `recipe_et`.) |

**Top-level fields**:

| Field | Type | Notes |
|-------|------|-------|
| `triggers` | array | Sector-filtered trigger list (may grow/shrink per deploy — render dynamically). |
| `language_modes` | array of strings | The closed set of valid `language_mode` values for §13. Currently `["single", "per_language"]`. |
| `docs` | string (URL) | Help page ("Smaily templates and fields") for the merchant. **This field is stable** — the plugin should link to the URL from the response rather than hardcoding it. |

**New field reference** (per `triggers[]` item, v1.15.0):

| Field | Type | Notes |
| --- | --- | --- |
| `engine_backed` | boolean | Always `true` (v1.17.0): the engine decides audience and moment for every catalogue email. A plugin may ignore the field. |
| `started_by` | `"engine"` | Always `"engine"` (v1.17.0): the engine starts each send. A plugin may ignore the field. An unknown value: do not render the entry. |
| `go_live` | `"store"` \| `"operator"` | Who switches real sends on. `store`: the store switches them with the go-live action (§20), unless a go-live blocker exists (§12). In v1.15.0 every email carries `store`. `operator` is reserved: the engine may serve it when a Smaily operator must switch real sends on for this store (for example, a store under operator review). Then the plugin shows "A Smaily operator switches real sends on" and offers no go-live button; §20 answers `409 go_live_blocked` with `operator_switch_required`. |
| `editable_settings` | array | The settings the store may change for this email, with their limits. See the settings vocabulary below. A setting appears only when the engine applies it for this email. |
| `engine_rules` | array | The engine's send checks for this email, as **read-only labels**. The plugin may show them; it never offers a control for them. `label_*` is display text and may change with any engine deploy; `params` is informational; neither is a contract value to branch on. A rule `key` the plugin does not know is still rendered by its label. |

**`editable_settings[]` item**:

| Field | Type | Notes |
| --- | --- | --- |
| `key` | string | Setting key (vocabulary below). Unknown keys: render by `type` and limits, or hide; never fail. |
| `type` | `"text"` \| `"integer"` \| `"email_list"` | Input type. |
| `min_length`, `max_length` | integer | `text` only. Characters (Unicode code points). |
| `min`, `max` | integer | `integer` only. Inclusive. |
| `max_items` | integer | `email_list` only. |
| `nullable` | boolean | Optional, default `false`. `true`: `null` is a valid value (for `daily_cap`, `null` = no cap). |
| `default` | same as `type` | Optional. The value the setup page and the plugin show for a new email. |
| `unit` | string | Optional display unit: `days`, `emails_per_day`. |
| `required` | boolean | `true`: the email cannot be enabled without a value. |
| `edited_in` | `"setup_page"` \| `"plugin_or_setup_page"` | `setup_page`: only the setup page edits it; the plugin may show the stored value from §12. `plugin_or_setup_page`: the plugin may also edit it as a top-level §13 field (`cooldown_days`, `daily_cap`, `test_emails`); both write the same stored value. |

**Settings vocabulary (v1.15.0)** — the engine stores one value per (store, email, setting); §12 returns it:

| `key` | Type and limits | Default | Used by | `edited_in` |
| --- | --- | --- | --- | --- |
| `subject` | text, 1–150 | per email, engine copy in the store's language | every email | `setup_page` |
| `preheader` | text, 0–150 | empty | every email | `setup_page` |
| `cooldown_days` ("pause between emails") | integer 1–365 | 30 (`post_purchase` 14; see note) | engine-backed | `plugin_or_setup_page` |
| `daily_cap` | integer 1–100,000, or `null` | 10 (ramp start) | engine-backed | `plugin_or_setup_page` |
| `test_emails` | up to 50 addresses, optional | empty | every email | `plugin_or_setup_page` |

Notes:

- **Limits equal the v1.14.0 PUT limits.** `cooldown_days` and `daily_cap` keep the §13 bounds, so a v1.14.0 plugin's saved values stay valid. A tighter per-email floor (for example `post_purchase` ≥ 14 days) is not in v1.15.0: it would reject values that a v1.14.0 plugin sends today, which is a breaking change.
- **`life_stage`** has no `cooldown_days` setting: its detector sets a fixed 365-day cooldown, shown as an engine rule. §13 still requires the key on its row (v1.14.0 shape); the value is stored and not used.
- **Engine-backed timing** (the moment of the send) is decided by the engine and shown as an engine rule. A store-editable send hour is not in v1.15.0.
- **Subject and preheader are part of the email design** (v1.17.0). The engine stores them with the design (§22, §23), not as separate settings. §12 `settings` shows the values of the saved design. §13 has no field for them.
- **Test addresses and test sends**: `test_emails` is optional and the plugin needs no field for it. It is the list that test-mode sends reach (`setup_state` `test` or `stopped`); the setup page may edit it, and a plugin that still has the field may send it. To check an email, the store uses the test send (§19): one email to one address it types. The test send does not read or change `test_emails`.

**Engine rule labels (v1.15.0)** — keys an engine-backed email may carry; `params` show today's values:

| `key` | What the label says | Applies to |
| --- | --- | --- |
| `comparison_group` | A share of the store's customers (holdout, default 25%) gets no recommendation emails, so the engine can measure the extra revenue. `params.holdout_percent` is `0` when the store's holdout is off. | engine-backed |
| `suppression` | No email to a customer who ever complained, unsubscribed in the last 30 days, or bought the named product after it was picked (PRO-3903). | engine-backed |
| `spacing` | At most one engine email per customer in 3 days; none within 24 hours of a newsletter (PRO-3716). | engine-backed |
| `automatic_stop` | Real sends switch back to test after 1 complaint, or opt-outs above 1% over at least 50 sends in 7 days (PRO-3708). A Smaily operator reviews the stop before the store can switch real sends on again (§20, blocker `stopped_needs_review`). | engine-backed |
| `overdue_limit` | No reminder when the product is more than 2 × its usual interval overdue (PRO-3702). | `replenish_due` |
| `real_send_switch` | Real sends start when the store switches them on (§20). A test send is not needed (v1.18.0). When the email's Smaily workflow is not active yet (for example, the sender domain is not verified), real sends start by themselves once it is active (§12 `notice`). | every catalogue email |
| `ramp` | From the first real send after the store switches real sends on (§20): at most 10 a day for 7 days, 25 a day for 7 days, 50 a day for 14 days, then the store's `daily_cap`. A lower `daily_cap` wins. An email that waits for its workflow (§12 `notice`) still starts at 10 a day, however long it waited. The ramp starts again at 10 each time the store switches real sends on. | engine-backed |
| `discount` | A discount is shown only when a Smaily operator switched discounts on for the store (PRO-1924); the store cannot raise it. | `replenish_due`, `winback_rescue` |
| `fixed_cooldown` | One email per customer in 365 days. | `life_stage` |

**Plugin and transactional emails are not catalogue entries** (v1.17.0). The plugin keeps them, their settings and their sending. Their design lives in the engine: the plugin opens it with §22 and reads the Smaily ids back with §23.

> **Recipe example** (v1.15.0, documentation only): the example `recipe_*` for `replenish_due` is the live recipe. It names the per-send fields `rec_rep_sku` / `rec_rep_name` / `rec_rep_price` / `rec_rep_image_url` / `rec_rep_link_path` / `rec_rep_days`; the engine has served this recipe since PRO-3701.

> **Compatibility** (v1.15.0, v1.17.0): the response lists only the engine's own emails, with or without a query parameter. Their seven v1.14.0 fields keep their meaning. The five v1.15.0 fields are additive; a plugin that does not read them is unaffected. v1.17.0 removes `include=engine_less`, which was never live: the engine always answered without engine-less entries.

**Error responses**: `401` (invalid/revoked key — see [Authentication](#authentication)), `429` (see [Rate limiting](#rate-limiting)).

**Idempotency**: not applicable (read-only endpoint).

**Curl example**:
```bash
curl -X GET https://intelligence.smaily.com/api/v1/automations/catalog \
  -H "Authorization: Bearer sk_..."
```

---

### 12. GET /api/v1/automations/config

Returns the tenant's current automation configuration rows — whether configured via the plugin (§13) or engine-side by the operator. Used when (re-)opening the plugin's settings UI: **the engine's GET is the source of truth**; the plugin must not treat a local copy as authoritative (a local cache is fine).

Since v1.15.0 the response also has a top-level `setup` array: one setup state per catalogue email (see below). `configs` is unchanged.

**URL**: `GET /api/v1/automations/config`

**Query parameters**: none. `configs` holds the store's rows; `setup` has one item per §11 catalogue email.

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Response 200 OK**:
```json
{
  "configs": [
    {
      "trigger_key": "replenish_due",
      "enabled": false,
      "language_mode": "single",
      "automation_map": { "id": "123" },
      "cooldown_days": 7,
      "daily_cap": null,
      "test_mode": true,
      "test_emails": ["owner@shop.example"],
      "configured_via": "plugin",
      "updated_at": "2026-07-07T05:15:00.000Z"
    }
  ],
  "setup": [
    {
      "trigger_key": "replenish_due",
      "setup_state": "test",
      "stop_reason": null,
      "go_live_blockers": [],
      "notice": null,
      "live_since": null,
      "daily_limit": null,
      "last_test_send_at": "2026-10-21T07:55:00.000Z",
      "sender_state": "ok",
      "sender": { "from_name": "Shop Example", "from_address": "news@shop.example" },
      "sender_tested_at": "2026-10-21T07:55:00.000Z",
      "design_state": "edited",
      "settings": {
        "subject": "{{rec_rep_name}} is running out",
        "preheader": "Order again in one click",
        "cooldown_days": 30,
        "daily_cap": 10
      },
      "updated_at": "2026-10-20T08:00:00.000Z"
    }
  ]
}
```

- **Rows exist only for triggers that have been configured at least once.** A catalog trigger with no config row is simply off (fail-closed) — `configs` may be an empty array on a fresh tenant.
- Each row carries the eight §13 fields **plus two read-only informational fields**:
  - `configured_via` (`"plugin"` | `"admin"`) — which surface last wrote the row.
  - `updated_at` (ISO 8601, may carry milliseconds) — when the row was last written.
- **Do not round-trip the read-only fields**: `configured_via` and `updated_at` are set engine-side and are NOT part of the PUT body (§13). They are tolerated if sent (unknown keys are stripped, see the §13 note), but the engine always overwrites them itself.

**`setup[]`**: one item for **every** email in the store's §11 catalogue, configured or not. Every field is read-only; the plugin never sends any of them back.

| Field | Type | Notes |
| --- | --- | --- |
| `trigger_key` | string | Catalogue `key`. |
| `setup_state` | `"off"` \| `"set_up"` \| `"test"` \| `"live"` \| `"stopped"` | See the state table below. |
| `stop_reason` | string \| null | Set only when `setup_state` is `stopped`: `auto_stop_complaint`, `auto_stop_optout_rate`, `operator`. Unknown values: show "Stopped". (`sender_check_failed` leaves in v1.18.0: a workflow that Smaily switches off is shown with `notice`, not as a stop.) |
| `go_live_blockers` | array of strings | Why real sends cannot be switched on now (§20). Empty = nothing blocks. Values: `no_workflow` (no Smaily workflow is bound to this email or submitted for it), `no_design`, `design_check_failed`, `missing_subject`, `stopped_needs_review` (after an automatic or operator stop, until a Smaily operator reviews it), `operator_switch_required` (`go_live: "operator"`, §11). **The test send and the sender are not blockers** (v1.18.0): a store switches real sends on without a test send, and with a sender domain that Smaily has not verified yet. Unknown values: show the generic text "Setup is not complete". |
| `notice` | string \| null | **New in v1.18.0.** Something the store does not need to act on. `null` = no notice. Values: `starts_when_sender_verified` — the email is enabled (`setup_state` `test` or `live`) and its Smaily workflow is not active, as the engine last read it from Smaily. Smaily does not save or switch on a workflow whose sender domain is not verified, so an inactive workflow normally waits for the sender domain. The store does not switch anything again and the engine does not ask it to: the email starts by itself once the workflow is active. Show it as, for example, "Starts when your sender domain is verified in Smaily". Unknown values: show nothing. **Not served yet**: the engine omits `notice` until it reads the workflow state from Smaily; a missing `notice` means `null`. |
| `live_since` | ISO 8601 \| null | When the store last switched real sends on (§20). `null` unless `setup_state` is `live`. Real sends may start later, while the email waits for its workflow (`notice`); the ramp counts from the first real send the engine records for this email after `live_since`, and until then `daily_limit` is the ramp's first step (§20). |
| `daily_limit` | integer \| null | `live` only: the most real sends the engine makes today — the ramp step (§20) or `daily_cap`, whichever is lower. `null` otherwise, and `null` when neither limits (no cap after the ramp). |
| `last_test_send_at` | ISO 8601 \| null | When Smaily last accepted a test send of this email (§19). |
| `sender_state` | `"unknown"` \| `"unverified"` \| `"address_not_allowed"` \| `"records_invalid"` \| `"ok"` | The from-domain state as the engine last read it. `unknown`: the engine cannot read it (no Smaily API access for sender domains yet). Informational: no value blocks go-live (v1.18.0). A test send that Smaily refuses sets `unverified` or `address_not_allowed` (§19). |
| `sender` | object \| null | `from_name`, `from_address` used for this email. `null` when not set. |
| `sender_tested_at` | ISO 8601 \| null | When Smaily last accepted a test send from `sender.from_address`, for any email of this store. Informational: `null` blocks nothing (v1.18.0). A later refused test send from the same address sets it back to `null`. |
| `design_state` | `"none"` \| `"starter"` \| `"edited"` \| `"check_failed"` | `none`: no design saved. `starter`: the engine's starter design, unedited. `edited`: the store changed it. `check_failed`: the engine's design check failed (a required field, slot guard, unsubscribe link or `smaily_rec` parameter is missing). |
| `settings` | object | Current value of each setting in the email's `editable_settings` (§11), by `key`. A setting with no stored value is absent. |
| `updated_at` | ISO 8601 \| null | Last change of any field in this item. |

**`setup_state` values**:

| State | Meaning |
| --- | --- |
| `off` | Not set up and not enabled: `go_live_blockers` contains at least one of `no_workflow`, `no_design`, `missing_subject`, and the config row is absent or `enabled: false`. |
| `set_up` | Setup is complete (no `no_workflow`, `no_design`, `missing_subject` blocker), and the email is not enabled. |
| `test` | Enabled with `test_mode: true`: only `test_emails` receive it (nobody when `test_emails` is empty). |
| `live` | Enabled with `test_mode: false`: real sends are switched on, and real customers receive the email while its Smaily workflow is active. With `notice: "starts_when_sender_verified"`, nobody receives it yet; sends start by themselves once the workflow is active. Only the go-live action (§20) or a Smaily operator reaches this state. |
| `stopped` | Real sends were on and the engine or the operator switched them back to test (`stop_reason`). Test-mode sends continue; the config row reads `test_mode: true`. The state stays `stopped`, with blocker `stopped_needs_review`, until a Smaily operator reviews the stop (INT-150). Nothing else ends it: not the store's go-live switch (§20 `live: false` or `true`), not a §13 save, not `enabled: false` and `true` again. After the review the state is `test`, and the store can switch real sends on again (§20). |

> **Bridge note**: when an operator binds a hand-built Smaily workflow and the design lives in that workflow, `design_state` may be `none`, but `no_design` is not a blocker: the engine accepts the operator's statement that the workflow holds the design. A plugin shows the state as given.

> **Compatibility** (v1.15.0): `setup` is a new top-level key. A v1.14.0 plugin reads `configs` only; `configs` is unchanged. v1.18.0: `notice` is additive; `no_test_send`, `sender_unverified`, `sender_address_not_allowed`, `sender_records_invalid` and the stop reason `sender_check_failed` no longer appear (unknown values were always allowed, so no plugin needed them).

**Error responses**: `401`, `429`.

**Idempotency**: not applicable (read-only endpoint).

**Curl example**:
```bash
curl -X GET https://intelligence.smaily.com/api/v1/automations/config \
  -H "Authorization: Bearer sk_..."
```

---

### 13. PUT /api/v1/automations/config

Saves the merchant's automation configuration. **Full-selection upsert**: the plugin sends every row the user saw/edited in one PUT (the entire settings form, not a delta of the changed toggle).

**URL**: `PUT /api/v1/automations/config`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Request body**:
```json
{
  "configs": [
    {
      "trigger_key": "replenish_due",
      "enabled": true,
      "language_mode": "single",
      "automation_map": { "id": "123" },
      "cooldown_days": 7,
      "daily_cap": null,
      "test_mode": true,
      "test_emails": ["owner@shop.example"]
    }
  ]
}
```

**Wrapper**: `{configs: [...]}`, **1..50 rows** per request. A non-array / empty / >50 `configs` is rejected (422, wrapper-level error — see below).

**Field reference** (per config row). Every key below is required, except `automation_map`, `test_mode` and `test_emails` (optional since v1.15.0). No field has a server-side default, except that an omitted optional key keeps its stored value.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `trigger_key` | string | YES | A §11 catalogue key. Any other key — also `welcome`, `first_order`, `abandoned_cart`, `follow_up`, `plugin.*` and `transactional.*` — → 422 (`"tundmatu trigger"`), as in v1.14.0. Natural key for the `(tenant_id, trigger_key)` UPSERT. |
| `enabled` | boolean | YES | Fail-closed master switch. The plugin must never send `true` without an explicit merchant action. |
| `language_mode` | `"single"` \| `"per_language"` | YES | Must be one of the catalog's `language_modes` (§11). |
| `automation_map` | object `{string: string}` | NO (v1.15.0) | Smaily workflow/autoresponder ids. **Every value must be a numeric string** (`/^\d+$/`) — e.g. `"123"`, not `123` or `"abc"`. Keys are free-form: `"id"` (single mode), language codes + `"fallback"` (per_language mode). When sent, it replaces the stored map (as in v1.14.0; `{}` is valid on an `enabled=false` row). **When omitted, the stored map is kept** (the operator or the setup page may have set it). When `enabled=true`, the stored-or-sent map must satisfy the v1.14.0 rule (`single` → `id`; `per_language` → `fallback`), or the engine must hold a workflow for this email that it or the operator set up; otherwise 422 (`"automation_map.id on nõutav"` / `"automation_map.fallback on nõutav"`). |
| `cooldown_days` | integer 1–365 | YES | Minimum days between fires per customer per trigger. **Required even on `enabled=false` rows** (no server default — the plugin supplies its UI default, e.g. 7). |
| `daily_cap` | integer 1–100000 \| null | YES (nullable) | Max fires per day for this trigger. **Nullable but the key must be present** — `null` = no cap. |
| `test_mode` | boolean | NO (v1.15.0) | When `true`, sends reach only `test_emails` recipients. **When omitted, the stored value is kept**; a new row is stored with `true`. `true` is stored as sent and switches real sends off, as in v1.14.0. `false` **never** switches real sends on (see Semantics below). A plugin without a test-mode control omits the key. |
| `test_emails` | array of email strings, max 50 | NO (v1.15.0) | Each entry must be a valid email. **When omitted, the stored list is kept**; a new row is stored with `[]`. When sent, it replaces the stored list, as in v1.14.0. A plugin without a test-address field omits the key. |

> **Unknown keys are stripped, not rejected** (standard Zod object behavior): extra keys in a row — including round-tripped `configured_via` / `updated_at` from §12, and a `settings` object (published in v1.15.0, never live, removed in v1.17.0) — are silently ignored. Cleaner to not send them.

**Semantics**:
- **UPSERT by `(tenant_id, trigger_key)`** — a row in the body creates or fully replaces that trigger's config. Exceptions: the real-sends rule below, and (v1.15.0) an omitted optional key, which keeps its stored value.
- **PUT never deletes rows**: a trigger absent from the body keeps its stored config unchanged (and if it was never configured, it stays off). There is no delete operation — to disable a trigger, send its row with `enabled: false`.
- `configured_via` is written as `'plugin'` on every row this endpoint touches (the engine-side admin UI writes `'admin'`).
- **Real sends** (rewritten v1.15.0): **`test_mode: false` never switches real sends on.** This holds for every catalogue email and for every plugin version. Existing plugins may already send `test_mode: false`; the engine treats it exactly as in v1.14.0. The only ways to start real sends are the go-live action (§20, also used by the setup page) and a Smaily operator's switch in the engine admin.
  - **Legacy field behaviour** (the v1.14.0 pilot consent rule, unchanged for the `test_mode` field): a row with `enabled: true` and `test_mode: false` is stored with `test_mode: true` unless the email already sends to real customers. Every other field in the row is stored as sent, and the response is the normal `200`. An email whose real sends are already on keeps them when the plugin re-saves it. `enabled: false` and `test_mode: true` are always stored as sent, so both switch real sends off. Under this rule the merchant asks for real sends in writing and a Smaily operator switches them on in the engine admin; that path stays for a store whose plugin has no go-live button (v1.14.0).
  - **New path** (v1.15.0): the store switches real sends on itself with the go-live action (§20). The merchant's confirmation in the go-live dialog replaces the written yes. The engine records who switched (§20 audit).
  - The response shape is unchanged (`200 {ok, upserted}`); read the stored state back with §12.
- Every change of a row's `enabled` / `test_mode` state is recorded engine-side with the authenticating API key.
- **Validation is all-or-nothing** (unlike ingest's per-item D6 partial success): any invalid row → 422 and **nothing** is written. Retry with the whole corrected selection.

> **Compatibility — a plugin that still sends a workflow id** (v1.15.0): a v1.14.0 plugin sends all eight keys with `automation_map` set. The engine stores the row exactly as in v1.14.0: the sent map replaces the stored map, the real-sends rule is the v1.14.0 rule (as in v1.14.0), and the response is the same. **Nothing changes for that plugin.**
>
> Two interactions to know:
>
> - A sent `automation_map` — also `{}` on a disabled row — replaces a workflow id that the operator or the setup page stored. A v1.15.0 plugin therefore omits `automation_map` unless the merchant picks a workflow in the plugin.
> - A sent `test_mode: true` switches real sends off, also when the store switched them on with §20 or on the setup page. A sent `test_emails` replaces the list that the setup page stored. A v1.15.0 plugin therefore omits `test_mode` and `test_emails`. A plugin that still has the fields may keep sending them; the engine keeps storing them.

**Response 200 OK**:
```json
{
  "ok": true,
  "upserted": 4
}
```

`upserted` = number of rows written (equals `configs.length`).

**Response 400** (body is not valid JSON): `{"error": "invalid_json"}` — note: no `message`/`details`.

**Response 422 validation_failed** (indexed, D6-style — added v1.1.0; the earlier Zod-`flatten()` `details` shape is gone):
```json
{
  "error": "validation_failed",
  "errors": [
    {
      "index": 0,
      "trigger_key": "replenish_due",
      "field": "automation_map",
      "message": "automation_map.fallback on nõutav"
    },
    {
      "index": 2,
      "field": "test_emails.1",
      "message": "Invalid email"
    }
  ]
}
```

**Error object shape**: `{index?, trigger_key?, field, message}`:
- `index` — the row's position in `configs[]`.
- `trigger_key` — included when readable from the request body (helps the UI map the error to a row).
- `field` — path **within** the config row: `"automation_map"`, `"automation_map.fallback"`, `"test_emails.2"`, `"cooldown_days"`, … (`"unknown"` if the row itself is not an object).
- `message` — human-readable. **Messages may be Estonian** for the merchant-facing custom checks (`"tundmatu trigger"`, `"automation_map.id on nõutav"`, `"automation_map.fallback on nõutav"`, `"automatsiooni id peab olema number"`); structural Zod messages are English (`"Required"`, `"Invalid email"`).
- **Wrapper-level failures** (non-array / empty / >50 `configs`) use the same 422 shape but the error entry has **no `index`** and `field: "configs"`.

Multiple issues produce multiple entries (one per issue), so the plugin can bind every error to its field. This mirrors the ingest D6 `errors[]` pattern (`{index, <natural_key>?, field, message}`) — but remember the all-or-nothing note above: a 422 here means the whole PUT was rejected, not a partial success.

**Other error responses**: `401`, `429` (with `Retry-After` header + `retry_after_seconds` body field — see [Rate limiting](#rate-limiting)).

**Idempotency**: natural-key UPSERT on `(tenant_id, trigger_key)` (Layer 1 only — there is no `event_id` layer). Repeating the same PUT is a harmless no-op update.

**Curl example**:
```bash
curl -X PUT https://intelligence.smaily.com/api/v1/automations/config \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "configs": [
      {
        "trigger_key": "replenish_due",
        "enabled": true,
        "language_mode": "single",
        "automation_map": {"id": "123"},
        "cooldown_days": 7,
        "daily_cap": null,
        "test_mode": true,
        "test_emails": ["owner@shop.example"]
      }
    ]
  }'
```

---

### 14. POST /api/v1/notifications/ingest

External producer entry point for Notifications 2.0 (PRO-1438). Lets an
authenticated caller — a Connect plugin, Smaily core, or any future service —
push notification events into the merchant's console notification drawer
(badge, "all caught up" state, dismissible cards). This is a **generic
event-ingest contract**: which `type` values a given caller may actually fire
is controlled by an engine-side registry, not by this endpoint's shape (see
below).

**URL**: `POST /api/v1/notifications/ingest`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec (same tier as catalog/customers/orders — see [Rate limiting](#rate-limiting))

**Request body**:
```json
{
  "events": [
    {
      "type": "job.plugin_backfill_done",
      "severity": "info",
      "dedupe_key": "backfill-42",
      "title": "Backfill finished",
      "body": "4200 orders imported",
      "payload": { "count": 4200 },
      "occurred_at": "2026-07-17T09:00:00Z"
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `type` | string | YES | Registry key — must already exist in the engine's `notification_types` table with `source_kind` of `external` or `both`. Registering a new type is an engine-side ops task, not something this endpoint does at runtime. |
| `severity` | enum | YES | `info` \| `warning` \| `error` |
| `dedupe_key` | string | YES | The re-fire/idempotency unit for this `(tenant, type)` — see Idempotency below |
| `title` | string | YES | Shown in the drawer card |
| `body` | string | YES | Shown in the drawer card |
| `payload` | object | NO | Free-form (counts, job stats, an action spec). Defaults to `{}` |
| `occurred_at` | ISO 8601 | YES | When the producer says this happened |

Wrapper is **all-or-nothing**: `events` must be an array of 1..50 items — a
non-array / empty / >50 request → `400 validation_failed`. Individual events
within a valid wrapper are validated **independently (D6)** — one malformed
event does not reject the rest of the batch.

**What the caller does NOT control.** `tenant_id` and `audience` are never
read from the request body, even if present — the engine silently ignores
them:
- `tenant_id` is always the tenant that the bearer API key authenticates as. A
  caller can never address another tenant.
- `audience` is always forced to `merchant` server-side. An external caller
  can never create an operator/system-wide notification.
- `source` (shown nowhere on the wire, but recorded internally for
  attribution) is derived from the authenticating connection, not sent by the
  caller: `external:<connection label>` (the label set when the API key was
  issued), or `external:unlabeled` if the connection has no label.

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 1,
  "errors": []
}
```

**Per-item errors (D6, not all-or-nothing)** — each rejected event goes to
`errors[]`; valid events in the same batch still process:
```json
{
  "ok": true,
  "processed": 0,
  "errors": [
    {
      "index": 0,
      "field": "type",
      "message": "Type 'insight.win_back' is not externally-fireable",
      "error_type": "type_not_externally_allowed",
      "status": 403
    }
  ]
}
```

`errors[]` items carry `error_type` + `status` (in addition to the usual
`index`/`field`/`message`) so a caller can distinguish the failure class
without string-matching `message`:

| `error_type` | `status` | Meaning |
|---|---|---|
| `validation_failed` | 400 | The event didn't match the field reference above (missing/malformed field). |
| `unknown_notification_type` | 400 | `type` is not registered in `notification_types` at all. |
| `type_not_externally_allowed` | 403 | `type` is registered, but its `source_kind` is `internal` — reserved for the engine's own crons/evaluators. |
| `ingest_failed` | 500 | The event passed validation and the registry gate but the write itself failed (rare — a DB-level failure). |

**Unknown/disallowed `type` policy is fail-closed, by design:** a caller can
never spontaneously create UI-affecting notification chrome just by sending
an unrecognized `type` — accept-with-default-config was considered and
rejected. Registering a new externally-fireable type (`source_kind:
'external'` or `'both'`) is an engine-side ops/SQL task, done once per
integration need.

**Idempotency**: **upsert-on-open-dedupe** — a caller may safely retry or
periodically re-send the same `(type, dedupe_key)` for its tenant (e.g.
"backfill job N is still running" → "backfill job N finished") without
spawning duplicate drawer entries. If a matching notification is still open
(undismissed), its `title`/`body`/`payload`/`severity`/`occurred_at` are
updated **in place** rather than superseded; if none is open, a fresh one is
inserted. This is deliberately different from the natural-key-UPSERT Layer 1
/ `event_id` Layer 2 idempotency used by the bulk ingest endpoints
([Idempotency](#idempotency)) — a notification is a **fired instance**, not a
stored business record, so "send it again" means "refresh the still-open
instance," not "create a new one every time."

**Curl example**:
```bash
curl -X POST https://intelligence.smaily.com/api/v1/notifications/ingest \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "type": "job.plugin_backfill_done",
        "severity": "info",
        "dedupe_key": "backfill-42",
        "title": "Backfill finished",
        "body": "4200 orders imported",
        "payload": {"count": 4200},
        "occurred_at": "2026-07-17T09:00:00Z"
      }
    ]
  }'
```

---

### 15. POST /api/v1/recommendations/customer

Storefront recommendations (v1.9.0, PRO-3781; visitor token v1.10.0, PRO-3834): the current recommendations of one shopper, for the plugin to show in the store (product page, cart, account page). **The same products, in the same order and language, as in that customer's Smaily contact fields** (`rec_N_*`) — the engine serves the recommendations it already issued; nothing is computed on the request.

**URL**: `POST /api/v1/recommendations/customer`

**Auth**: `Authorization: Bearer sk_...`. **Server-side calls only** — the API key never reaches the browser. The plugin's backend calls this endpoint and renders the result.

**Rate limit**: 100 req/sec per tenant.

**Request body** — a logged-in shopper:
```json
{
  "customer_external_id": "1042",
  "limit": 4
}
```

A returning guest shopper (no store customer account), named by the visitor token from the store's cookie (v1.10.0):
```json
{
  "smaily_visitor_token": "vt_8f3k2a9xq4",
  "limit": 4
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `customer_external_id` | string | ONE OF | The **store's own customer id** of the logged-in shopper — the `external_id` the plugin sends on [`POST /api/v1/ingest/customers`](#4-post-apiv1ingestcustomers) (WooCommerce user id, Shopify customer id, Magento customer id). 1–255 characters. |
| `smaily_visitor_token` | string | ONE OF | The visitor token from the visitor-token cookie (default name `smaily_rec_uid`, [cookie names](#cookie-names-plugin-side-management)) — the `smaily_vt` value of an engine link, or the token the store created at checkout and sent on the order (v1.11.0, [§5](#store-created-visitor-token)). 1–255 characters. Send it for a shopper who has no store customer id, so a guest shopper can be shown their recommendations. |
| `limit` | integer | NO | Default `9`, max `9`. A value above 9 is clamped, not rejected. |

**At least one of `customer_external_id` and `smaily_visitor_token` is required.** When the request carries both, **`customer_external_id` wins and the token is ignored entirely** — the engine does not fall back to the token when the customer id finds nobody, because on a shared browser the cookie can belong to a different person than the logged-in shopper.

**Which customer a visitor token names** (v1.11.0; one rule since v1.13.0). The customer the token is bound to in this tenant, while the token is valid. An engine email-link token (`vt_`) is bound to the customer the engine issued it for. Every token is valid for **14 months from its last activity** (v1.14.0): its issue, or the latest order of its customer that carried it — a store-created `vs_` token or a `vt_` token; an order never shortens a token's lifetime ([§5](#store-created-visitor-token)). The cookie and the token may expire at different times. An expired token, a token of another tenant, and a value the engine does not know are all answered as an unknown shopper (the empty answer below). Send the token only for a shopper who has given marketing consent in the store (Erkki's decision 2026-10-06).

**Never send an email address, name or other contact detail** in this request. The shopper is named by the store's id or the visitor token only. Unknown fields are ignored.

**Response 200 OK**:
```json
{
  "slots": [
    {
      "position": 1,
      "rec_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
      "sku": "woo-24150",
      "external_id": "24150",
      "name": "Grain-free adult food 3 kg",
      "description": "…",
      "price": 29.99,
      "compare_price": 34.99,
      "in_stock": true,
      "image_url": "https://shop.example.com/img/24150.jpg",
      "product_url": "https://shop.example.com/product/24150?smaily_rec=3fa85f64-5717-4562-b3fc-2c963f66afa6&smaily_ctx=storefront",
      "headline": "…",
      "cta": "…",
      "reason": "…"
    }
  ]
}
```

| Field | Notes |
|-------|-------|
| `slots` | Up to `limit` slots, in the email's order. **Can be empty** (see below). Never padded: a slot whose product is gone from the catalog is left out, as in the email. |
| `position` | 1-based, consecutive. |
| `rec_id` | The recommendation (UUID). The same id is in the customer's email fields. |
| `sku` | The canonical catalog key ([§3](#3-post-apiv1ingestcatalog)) — the stable product identity. |
| `external_id` | The catalog row's `external_id`, **nullable**. When null, use `sku` (for `woo-` and `shp-` keys the platform id is the `sku` without its prefix). On a multilingual stand-in row it can differ from the id in `sku`; render with `external_id`, identify with `sku`. |
| `name`, `description`, `product_url` | Localized in the customer's language (`customers.language`), else the tenant default — as in the email. `description` is not truncated. |
| `price`, `compare_price` | Numbers; `compare_price` nullable. Read live from the catalog at request time. No currency — render in the store's own. |
| `in_stock` | Live from the catalog. **Informational** — an out-of-stock slot is not removed. Re-check price and stock locally before rendering. |
| `image_url` | Nullable. |
| `product_url` | `<product url>?smaily_rec=<rec_id>&smaily_ctx=storefront` — **no `utm_*` and no `smaily_vt`** (an on-site click is not email traffic). Null when the product has no valid absolute URL. Capture it like any landing with `smaily_rec` ([context cookie rule](#cookie-names-plugin-side-management)), and forward `smaily_rec_id` + `smaily_rec_ctx` on the order. |
| `headline`, `cta`, `reason` | The slot copy the email renders, in the customer's language. Rendered **without the customer's first name** — a greeting falls back to its default word. Nullable. |

**Empty answer.** `{ "slots": [] }` with status 200 for: an unknown `customer_external_id`; an unknown, expired or other-tenant `smaily_visitor_token`; an id that several customers share in the tenant; a customer in the holdout group of a running measurement; a customer who objected to profiling ([§10](#10-post-apiv1customeremailopt-out)) or whom Smaily reports unsubscribed; a customer with no current recommendations. **The response is the same in every case and does not say which** — render nothing (or your own fallback). Do not retry.

**Attribution.** A purchase whose last landing was a storefront link (order `smaily_rec_ctx: "storefront"`, matched by `smaily_rec_id`) is credited to the **storefront**: it names no email campaign, and an earlier email click on the same `rec_id` does not count. An email click after the storefront click overwrites both cookies, and the purchase is credited to the email. Last touch wins.

**Caching.** The engine answers with `Cache-Control: no-store, private`. Cache in the plugin's own server-side store, keyed by tenant + a **hash** of the identifier the request sent (`customer_external_id`, else `smaily_visitor_token`), for about 1 hour. Never let a shared or CDN cache hold a response across shoppers.

**Timeouts.** Ask from a background request after the page has loaded, so a slower answer does not delay the page. The 1-second client timeout of v1.9.x no longer applies (v1.10.0): the engine ends a request after 10 seconds, so use a client timeout of at most 10 seconds, and render nothing (or the last cached answer) on a timeout or an error.

**Errors**: `400 validation_failed` (neither `customer_external_id` nor `smaily_visitor_token` — reported under `details.customer_external_id`; an empty or over-long identifier; bad `limit`), `400 invalid_json`, `401`, `403 tenant_inactive`, `429` — the standard shapes ([Error handling](#error-handling)).

**Read only.** The call writes nothing in the engine — a visitor-token request does not mark the token as seen either. The engine logs no identifier from the request.

**Endpoint map key**: `recommendations_customer` (setup-exchange response, [§1](#1-post-apisetupexchange)). A connection set up before v1.9.0 does not have the key — use the fallback path constant.

**Curl example**:
```bash
curl -X POST https://intelligence.smaily.com/api/v1/recommendations/customer \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{"customer_external_id": "1042", "limit": 4}'

# A returning guest shopper, by the visitor token (v1.10.0)
curl -X POST https://intelligence.smaily.com/api/v1/recommendations/customer \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{"smaily_visitor_token": "vt_8f3k2a9xq4", "limit": 4}'
```

### 16. POST /api/v1/store/uninstall

**Disconnect: remove this connection; a store with no connection left is paused, never deleted** (v1.14.0; changed in v1.18.0, INT-145, Erkki's decision 2026-10-23). Call it when the shop admin uninstalls **or disconnects** the plugin or app, before the plugin deletes its own stored API key. A merchant who removes the plugin may only be moving the store to another platform: the store's data stays in the engine, and a new connection ([§1](#1-post-apisetupexchange)) finds it.

**URL**: `POST /api/v1/store/uninstall`

**Auth**: `Authorization: Bearer sk_...` — the connection's own API key.

**Request body**: none.

**What the engine does**:
1. The engine revokes the **calling** connection's API key. Other connections of the same store keep working. Nothing is deleted and nothing is marked for deletion.
2. When the store has **another active connection** after that (another plugin or shop with its own key), nothing else happens. The response says `connection_removed`.
3. When the calling connection was the store's **last active connection**, the store is **paused**: no recommendations are issued, nothing is written to the store's Smaily account, and no engine automated email is sent. The store's data stays. The store's Smaily contacts keep the `rec_*` fields of the last sync: the engine does not clear them. The engine keeps reading the Smaily events that still arrive, and the store's console stays available. The response says `store_paused`.
4. A paused store resumes **by itself** as soon as it has an active connection again: a new setup exchange ([§1](#1-post-apisetupexchange)) or a key a Smaily operator creates. There is no separate "resume" call.

A store that was never connected (still being set up) is not paused.

**Response 200 OK — another connection remains**:
```json
{
  "ok": true,
  "status": "connection_removed",
  "other_connections_remain": true
}
```

**Response 200 OK — no connection remains, the store is paused**:
```json
{
  "ok": true,
  "status": "store_paused",
  "other_connections_remain": false
}
```

**Idempotency**: a retry with the same key — for example after a network error — changes nothing and answers the store's current state (`connection_removed` or `store_paused`).

**Errors**: `401 unauthorised` (header missing, key unknown, key revoked other than by this endpoint, or the store's data was deleted). A suspended store may still disconnect: the endpoint does not answer `403 tenant_inactive`.

**After the call**: delete the stored API key and stop all calls to the engine. To connect the store again later, run a new setup exchange ([§1](#1-post-apisetupexchange)) — possible as long as the store's deletion was not requested; a store whose data was deleted is set up again by the engine operator.

**Endpoint map key**: `store_uninstall` (setup-exchange response, [§1](#1-post-apisetupexchange)). A connection set up before v1.14.0 does not have the key — use the fallback path constant.

**Audit log**: the engine records every request that changes something (`admin_audit_log`, action `tenant.disconnect`, with the store's tenant id and the API key id, no other identifier). When a store becomes paused, the Smaily operator sees a notice in the engine admin (one open notice per store, closed when a connection returns).

**When the data is deleted**: when the merchant closes the store's Smaily account. Today a Smaily operator starts the deletion by hand. Later Smaily's subscription service sends the signal; the contract names that caller and its credential when it exists.

**What deletion does**: from the request on, the engine stops using the store — no recommendations are issued, nothing is pushed to Smaily, no automated email is sent and no Smaily data is pulled; every key of the store answers `403 tenant_inactive`, and a new setup exchange for the store answers `404 tenant_not_found`. The store's data is deleted **within 30 days of the request** — normally the next night (00:30 UTC). All of it is deleted: customers, orders, catalog, events, recommendations, attribution and the store's settings. The engine keeps only a record that the deletion happened (no shopper data) and the store's monthly usage counts. Data the engine pushed to the store's Smaily contacts (the `rec_*` fields) stays in Smaily; the engine does not write to Smaily after the request. Audit: `tenant.deletion_request` and `tenant.deletion_purge`.

> **Compatibility** (v1.18.0): `store_paused` replaces `deletion_scheduled`; `deletion_requested_at` and `delete_by` leave the response. A plugin built for v1.17.0 keeps working: both answers are `200` and mean "done". It should no longer tell the shop admin that the store's data will be deleted.

**Curl example**:
```bash
curl -X POST https://intelligence.smaily.com/api/v1/store/uninstall \
  -H "Authorization: Bearer sk_..."
```

---

### 17. POST /api/v1/automations/{key}/setup-link

**One-time link to the engine-hosted setup page** (v1.15.0, PRO-4166). The store sets up one automated email on that page: sender check, settings, design in the embedded editor, test send, and go-live (the store's own switch, for every catalogue email). Call it when the shop admin presses "Set up" (or "Edit") on an email in the plugin.

**URL**: `POST /api/v1/automations/{key}/setup-link` — `{key}` is a §11 catalogue key.

**Auth**: `Authorization: Bearer sk_...` — the connection's own API key. The store is the API key's store; the request cannot name another store.

**Rate limit**: 10 requests per 60 seconds per store.

**Request body** (all fields optional; an empty body `{}` is valid):

```json
{
  "return_url": "https://shop.example/wp-admin/admin.php?page=smaily-connect&tab=automations",
  "language": "et"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `return_url` | string (URL), max 2048 | Where the page's "Back to your store" button goes. Must be `https`. Its host must equal the host of the connection's `plugin_info.site_url` (§1), or be the platform's admin host (`admin.shopify.com` for Shopify). Otherwise → 422. When absent, the page shows no back button. |
| `language` | ISO 639-1 | Language of the setup page. When absent or not supported, the store's default language. |

**Response 200 OK**:

```json
{
  "url": "https://intelligence.smaily.com/setup/automation/sl_9Qe2…",
  "expires_at": "2026-10-20T08:05:00.000Z"
}
```

| Field | Notes |
| --- | --- |
| `url` | Opaque one-time URL. Open it at once in a **new browser tab** (top-level navigation). A setup link is never framed: the engine refuses to show it in a frame. A design link (§22) may be framed once [§22 Embedding](#embedding) is live. Do not store, log or send it anywhere else. |
| `expires_at` | Issue time + **5 minutes**. |

**Rules**:

1. **One time.** The first opening redeems the link. A second opening, or an opening after `expires_at`, shows "This link has expired — open the setup again from your store" and nothing else. Mint a new link for each "Set up" press.
2. **Own store only.** The page opens a setup session for the API key's store. The session reaches only that store's automation setup pages and the store's brand kit (§18): the email named in the link opens first, and the store can switch to its other catalogue emails. It gives no access to the merchant console, other stores, customer lists or reports. The same session also reaches the design page of the store's plugin and transactional emails (§22).
3. **Session.** The setup session ends 60 minutes after the last action, or when the tab closes. It names no person: the engine records the connection (API key id), not a user.
4. **What the page writes.** The page shows the email's state, the brand kit (§18) and the design, and saves the subject, preheader, sender and design (stored as HTML in the engine). Settings (timing and frequency within the §11 limits, test addresses), test send and go-live are not on the page yet; when they come, they follow the same rules, limits and audit as §19 and §20, and their audit rows name the setup session and the API key that minted the link.
5. **After the page.** When the store returns to the plugin, the plugin reads §12 again; it does not wait for a callback.

**Errors**:

| Status | `error` | Meaning |
| --- | --- | --- |
| 401 | `unauthorised` / `api_key_revoked` | As in [Authentication](#authentication). |
| 403 | `tenant_inactive` | The store is suspended or marked for deletion (§2, §16). |
| 404 | `unknown_automation` | `{key}` is not in this store's catalogue (another sector, or unknown). |
| 422 | `validation_failed` | `return_url` or `language` is invalid. Body: `{"error":"validation_failed","errors":[{"field":"return_url","message":"…"}]}` (the §13 shape, without `index`). |
| 429 | `rate_limit_exceeded` | See [Rate limiting](#rate-limiting). |

**Idempotency**: none needed. Each call mints a new link; earlier unredeemed links stay valid until they expire.

**Endpoint map key**: `automations_setup_link` (§1). A connection set up before v1.15.0 does not have the key — use the fallback path constant.

**Audit log**: every mint (`admin_audit_log`, action `automation.setup_link.mint`) and every redemption (`automation.setup_link.redeem`), with the store's tenant id, the email key and the API key id; no other identifier.

**Curl example**:

```bash
curl -X POST https://intelligence.smaily.com/api/v1/automations/replenish_due/setup-link \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{"return_url":"https://shop.example/wp-admin/admin.php?page=smaily-connect","language":"et"}'
```

---

### 18. PUT /api/v1/store/branding

**The store's brand kit** (v1.15.0, PRO-4166). The engine builds the starter design of every email from it — engine, plugin and transactional emails (§22) — (logo, colours, font, footer, sender). The plugin reads the fields from the shop platform's own settings and sends them with their source. Send it after setup exchange, and again when a source setting changes in the shop admin.

**URL**: `PUT /api/v1/store/branding` (write) · `GET /api/v1/store/branding` (read the stored kit)

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 requests per 60 seconds per store.

**Request body** — every field is optional; each sent field is `{value, source, source_setting?}`:

```json
{
  "fields": {
    "store_name":       { "value": "Shop Example", "source": "platform", "source_setting": "blogname" },
    "store_url":        { "value": "https://shop.example", "source": "platform", "source_setting": "home" },
    "postal_address":   { "value": "Example street 1, 10111 Tallinn, Estonia", "source": "platform", "source_setting": "woocommerce_store_address" },
    "logo_url":         { "value": "https://shop.example/logo.png", "source": "platform", "source_setting": "woocommerce_email_header_image" },
    "primary_colour":   { "value": "#7F54B3", "source": "platform", "source_setting": "woocommerce_email_base_color" },
    "background_colour":{ "value": "#F7F7F7", "source": "platform", "source_setting": "woocommerce_email_background_color" },
    "text_colour":      { "value": "#3C3C3C", "source": "platform", "source_setting": "woocommerce_email_text_color" },
    "font_family":      { "value": "Helvetica", "source": "merchant" },
    "footer_text":      { "value": "Shop Example — thank you for shopping with us.", "source": "platform", "source_setting": "woocommerce_email_footer_text" },
    "sender_name":      { "value": "Shop Example", "source": "platform", "source_setting": "woocommerce_email_from_name" },
    "sender_address":   { "value": "news@shop.example", "source": "platform", "source_setting": "woocommerce_email_from_address" },
    "language":         { "value": "et", "source": "platform", "source_setting": "WPLANG" }
  }
}
```

**Field reference and validation**:

| Field | `value` type and limits |
| --- | --- |
| `store_name` | text, 1–100 characters |
| `store_url` | `https` URL, max 2048 |
| `postal_address` | text, 1–300 characters, line breaks allowed (the legal footer address) |
| `logo_url` | `https` URL, max 2048, path ending `.png`, `.jpg`, `.jpeg`, `.gif` or `.svg`, or a URL that answers with one of those image types. The engine does not copy the file in v1.15.0; it must stay reachable. |
| `primary_colour`, `background_colour`, `text_colour` | `#RRGGBB` (6 hex digits, case-insensitive; stored upper case) |
| `font_family` | text, 1–100 characters, a font family name without CSS (letters, digits, spaces, `-`). The starter design adds a safe fallback stack. |
| `footer_text` | plain text, 0–1000 characters, line breaks allowed; HTML tags are rejected |
| `sender_name` | text, 1–100 characters |
| `sender_address` | email address, max 254 |
| `language` | ISO 639-1 code (two letters) |

| Envelope field | Type and limits |
| --- | --- |
| `source` | `"platform"` (read from the shop platform's settings) \| `"merchant"` (typed by the shop admin in the plugin). Required. |
| `source_setting` | text, max 200 — the platform setting the value came from (e.g. a WooCommerce option name, a Shopify `brand` field path, a Magento config path). Optional; required when `source` is `platform`. Display and support only. |
| `null` instead of the envelope | Clears the stored field. |

**Semantics**:

- **Merge by field.** A sent field replaces the stored field; a field not sent keeps its stored value. An empty `fields: {}` is valid and changes nothing.
- **A store's own edit wins.** A field that the store edited on the setup page (stored source `setup_page`) is **not** replaced by a PUT with `source: "platform"`. It is replaced by a PUT with `source: "merchant"`, or by a later setup-page edit. The response lists such fields in `kept`.
- **Website fallback.** For a field the plugin does not send, the engine may fill a value from the store's website (stored source `website`). A sent field always replaces a `website` value.
- **Validation is all-or-nothing**, as in §13: any invalid field → 422 and nothing is written.
- **No shopper data.** The brand kit holds store data only. Do not send customer names, addresses or emails.

**Response 200 OK** (PUT):

```json
{
  "ok": true,
  "stored": ["store_name", "logo_url", "primary_colour"],
  "kept": [{ "field": "footer_text", "reason": "edited_on_setup_page" }]
}
```

**Response 200 OK** (GET):

```json
{
  "fields": {
    "store_name": { "value": "Shop Example", "source": "platform", "source_setting": "blogname", "updated_at": "2026-10-20T08:00:00.000Z" },
    "footer_text": { "value": "…", "source": "setup_page", "updated_at": "2026-10-20T08:10:00.000Z" }
  }
}
```

The GET `source` may also be `setup_page` or `website`. A field with no stored value is absent.

**Errors**: `400 invalid_json`; `401`; `403 tenant_inactive`; `422 validation_failed` with `{"error":"validation_failed","errors":[{"field":"fields.primary_colour.value","message":"…"}]}`; `429`.

**Idempotency**: natural-key UPSERT on (store, field). Repeating the same PUT is a no-op update.

**Endpoint map key**: `store_branding` (§1). A connection set up before v1.15.0 does not have the key — use the fallback path constant.

**Where each platform's values come from** (the plugin reads them; the engine does not care which setting it was):

| Field | WooCommerce | Shopify | Magento |
| --- | --- | --- | --- |
| store name, URL | site title, home URL | shop name, primary domain | store name, base URL |
| postal address | store address settings | shop address | store information address |
| logo | email header image | `brand` logo (Admin API) | email logo, else header logo |
| colours | email base / background / text colour | `brand` colours (fields unverified) | none in core — `merchant` or omit |
| font | none in core — `merchant` or omit | none — `merchant` or omit | none in core — `merchant` or omit |
| footer | email footer text | none — `merchant` or omit | none in core — `merchant` or omit |
| sender name, address | email "From" name and address | sender email, shop name | store email identity (general contact) |
| language | site language | shop primary locale | store locale |

---

### 19. POST /api/v1/automations/{key}/test-send

> **Not live yet**: the engine answers `404` until it switches this endpoint on (a release flag, as for §21). A connector must treat `404` as "not available yet" and keep its current behaviour. The setup-exchange endpoint map returns this endpoint's key only once it is switched on.

**One test email to one address** (v1.15.0, INT-12; changed in v1.18.0, INT-151). The engine sends one email of one automated email to one address that the store types, with real products and no customer's name or email. Call it when the shop admin presses "Send test email" in the plugin. The setup page and the design page (§22) offer the same action, for every email the store designs.

**A test send is a button, not a condition** (Erkki, 2026-10-23). The store can switch real sends on without one (§20). A test that is refused, fails or is never sent does not stop real sends and does not block go-live.

**The address joins the store's Smaily list.** The test goes through the email's Smaily workflow: the typed address becomes a test contact of the store's Smaily list. Before the send, the plugin and the page say so, for example: "This address joins your Smaily list as a test contact." **Today the test shows the design that the Smaily workflow holds, not the design stored in the engine**: until the Smaily push is live (§23 Smaily dependency), the two can differ. The plugin and the page say so too.

**URL**: `POST /api/v1/automations/{key}/test-send` — `{key}` is a §11 catalogue key. A plugin or transactional email key → `404 unknown_automation`: the plugin does not send their test through this endpoint.

**Auth**: `Authorization: Bearer sk_...` — the connection's own API key. The store is the API key's store.

**Rate limit**: 10 requests per 3600 seconds per store, all emails together, setup page and design page included.

**Request body**:

```json
{
  "email": "owner@shop.example",
  "language": "et"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `email` | email address, max 254 | Required. Exactly one address. The engine passes it to Smaily for this send. The engine keeps no copy of it in its own records of the send (audit, logs) and does not add it to `test_emails`. Smaily keeps it as a contact of the store's list (see "How the test goes"). |
| `language` | ISO 639-1 | Optional. Language of the copy and the product names, when the store has that language. Otherwise the store's default language. |

**What the engine sends**:

1. The email as its Smaily workflow sends it today: the workflow's design and subject, with the per-send field values below. Once the Smaily push is live, the workflow holds the engine's saved design (§23).
2. **Real products.** Engine-backed email: the current recommendations of one customer of the store who could receive this email (not in the holdout, not opted out, not unsubscribed), chosen by the engine; the per-send fields (for example `rec_rep_*` for `replenish_due`) come from the same customer. Plugin or transactional email (design page only): products from the store's catalogue (best sellers) in place of the cart lines or the order lines; a recommendation block shows products as in an engine email.
3. **No customer's name or email.** Personal fields (for example the first name) are empty. Nothing in the email names or identifies the customer whose products it shows.
4. **Links** go to the real product pages without `smaily_rec`, `smaily_vt` or `smaily_ctx`. A click or a purchase from a test email never credits a recommendation.
5. **Not a send to a customer.** A test send writes no recommendation. It does not count toward `daily_cap`, the ramp, spacing or cooldown. Smaily records the test contact's send, opens and clicks like any contact's, and the engine's action-log poll stores them as email events like any contact's: on the store's customer with that address, if there is one, otherwise on no customer. **The test send and the reactions to that test email do not count toward the automatic stop rules** (§20; Erkki 2026-10-23). The same person's reactions to real emails count like any contact's. The engine keeps no copy of the test address.
6. A test send works in every `setup_state` and does not change it.
7. **No workflow, no test.** A test needs an active Smaily workflow for the email. An engine email without one answers `409 not_ready` with `no_workflow` or `workflow_not_active`. A plugin or transactional email has a test send on the design page only once the engine knows its workflow (§23 `smaily_workflow_id`).

**Sender state** (informational since v1.18.0):

- **Smaily accepts the send** → the engine sets §12 `sender_tested_at` for the from-address (for every email of the store that uses the same address) and `last_test_send_at` for this email.
- **Smaily refuses the send** because the from-domain is not verified or the from-address is not allowed → `409 sender_refused`. The engine sets §12 `sender_state` to `unverified` or `address_not_allowed` and `sender_tested_at` to `null`. **Nothing else changes**: real sends that are on stay on, and go-live is not blocked.
- **Accepted is not delivered.** The engine knows only that Smaily accepted the send. The store checks its inbox.
- A test send of a plugin or transactional email from the design page sets and clears `sender_tested_at` for its from-address in the same way. It changes no §12 `setup[]` item of its own: plugin and transactional emails have none.

**Response 200 OK**:

```json
{
  "ok": true,
  "trigger_key": "replenish_due",
  "accepted_at": "2026-10-21T07:55:00.000Z",
  "sender": { "from_name": "Shop Example", "from_address": "news@shop.example" }
}
```

The response does not repeat the recipient address.

**Errors** (body `{error, message?, details?}`, as in [Error response format](#error-response-format)):

| Status | `error` | Meaning |
| --- | --- | --- |
| 400 | `invalid_json` | The body is not valid JSON. |
| 401 | `unauthorised` / `api_key_revoked` | As in [Authentication](#authentication). |
| 403 | `tenant_inactive` | The store is suspended or marked for deletion (§2, §16). |
| 404 | `unknown_automation` | `{key}` is not in this store's catalogue. |
| 409 | `not_ready` | The email cannot be sent yet. `details.reasons` lists one or more of: `no_workflow`, `workflow_not_active` (new in v1.18.0: the email's Smaily workflow exists and is not active — for example, its sender domain is not verified yet), `no_design`, `design_check_failed` (the design check failed — a required field, slot guard, unsubscribe link or `smaily_rec` parameter is missing), `missing_subject`, `no_sender` (no from-address), `no_products` (no customer of the store has recommendations for this email yet). Unknown values: show "Setup is not complete". |
| 409 | `sender_refused` | Smaily refused a send from this from-address. `details.sender_state` is `unverified` or `address_not_allowed`; `details.from_address` names the store's from-address. Show it as information: the store may fix the sender domain in Smaily. Real sends and go-live are not affected. |
| 422 | `validation_failed` | `email` or `language` is invalid. Body: `{"error":"validation_failed","errors":[{"field":"email","message":"…"}]}` (the §13 shape, without `index`). |
| 429 | `rate_limit_exceeded` | See [Rate limiting](#rate-limiting). |
| 502 | `smaily_unavailable` | Smaily did not answer or failed. Nothing changes. **Do not retry automatically** — a retry can send a second email; let the shop admin press again. |

**Idempotency**: none. Each accepted call sends one email.

**How the test goes** (v1.18.0; today's bridge): the engine adds the typed address to the store's Smaily list as a test contact, with the test field values (real products, empty personal fields), and enrols it into the email's Smaily workflow. Smaily keeps the address as a contact of the store's list after the test; the store removes it in Smaily when it wants to. When Smaily gives the engine a test-send call for the store's account, the engine may use it instead; the contract then says so.

**Endpoint map key**: `automations_test_send` (§1). A connection set up before v1.15.0 does not have the key — use the fallback path constant.

**Audit log**: every call (`admin_audit_log`, action `automation.test_send`) with the store's tenant id, the email key, the API key id or the setup or design session, the result (`accepted`, `sender_refused`, `not_ready`, `smaily_unavailable`) and the from-address domain. The recipient address is not recorded.

**Curl example**:

```bash
curl -X POST https://intelligence.smaily.com/api/v1/automations/replenish_due/test-send \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{"email":"owner@shop.example","language":"et"}'
```

---

### 20. POST /api/v1/automations/{key}/go-live

> **Not live yet**: the engine answers `404` until it switches this endpoint on (a release flag, as for §21). A connector must treat `404` as "not available yet" and keep its current behaviour. The setup-exchange endpoint map returns this endpoint's key only once it is switched on. Until then, real sends start only as in v1.14.0: the merchant asks in writing and a Smaily operator switches them on in the engine admin ([§13](#13-put-apiv1automationsconfig)).

**The store switches real sends on or off for one email** (v1.15.0, INT-12). Call it when the shop admin presses "Go live" (and confirms) or "Stop real sends". The setup page offers the same switch. **This is the only way a plugin or the setup page starts real sends.** The store never waits for the engine or for Smaily (v1.18.0): no test send is needed, and an email whose Smaily workflow is not active yet is switched on and starts by itself once the workflow is active. A §13 row with `test_mode: false` never starts them ([§13](#13-put-apiv1automationsconfig)). A Smaily operator can still switch real sends on and off in the engine admin.

**URL**: `POST /api/v1/automations/{key}/go-live` — `{key}` is a §11 catalogue key. A plugin or transactional email key → `404 unknown_automation`.

**Auth**: `Authorization: Bearer sk_...` — the connection's own API key. The store is the API key's store.

**Rate limit**: 10 requests per 60 seconds per store.

**Request body**:

```json
{
  "live": true,
  "confirmed": true,
  "switched_by": "17"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `live` | boolean | Required. `true`: switch real sends on. `false`: switch real sends off. |
| `confirmed` | boolean | Required and must be `true` when `live` is `true`, otherwise 422. It states that the shop admin confirmed a dialog that names the email and says that the store's customers will receive it. Ignored when `live` is `false`. |
| `switched_by` | string, max 100 | Optional, recommended. The shop admin's user id on the platform (opaque, for example a WordPress user id). Never an email address or a name. The engine stores it only in the audit row. |

**`live: true`**:

1. The engine reads §12 `go_live_blockers` for this email at the moment of the call. **Any blocker** → `409 go_live_blocked`, and nothing changes. The blockers are `no_workflow`, `no_design`, `design_check_failed`, `missing_subject`, `stopped_needs_review` and `operator_switch_required` (§12). A test send, the sender state and a workflow that is not active are **not** blockers.
2. With no blocker, the engine stores `enabled: true` and `test_mode: false`. §12 `setup_state` becomes `live` and `live_since` is set to the moment of the call.
3. When the email's Smaily workflow is active, the engine starts real sends at ramp step 1 (table below). When it is not active, §12 shows `notice: "starts_when_sender_verified"`; the engine does not ask the store to switch again, and real sends start by themselves once the workflow is active, at ramp step 1 — the ramp counts from that first real send. The holdout, suppression, spacing, overdue limit and automatic stop apply as before.
4. An email that is already `live` answers `200` with `changed: false`; the ramp does not restart.

**`live: false`**:

- Always allowed; no confirmation and no blocker check. The engine stores `test_mode: true`; `enabled` is unchanged. A `live` email becomes `test`. An email that is not `live` answers `200` with `changed: false`.
- To stop all sends, including test-mode sends, save the row with `enabled: false` (§13).

**Ramp** (today's values — §11 `engine_rules` `ramp` shows the current values, §12 `daily_limit` today's limit):

| Step | Real sends per day, at most | Length |
| --- | --- | --- |
| 1 | 10 | 7 days |
| 2 | 25 | 7 days |
| 3 | 50 | 14 days |
| after step 3 | the store's `daily_cap` (`null` = no cap) | — |

A lower `daily_cap` wins at every step. The days count from the **first real send after the store switched real sends on**: the engine derives it from the first real send it records for this email after the latest `live_since`. Days while the email waits for its workflow (`notice`) do not count, so an email whose workflow becomes active weeks after the switch-on still starts at 10 a day. Until that first real send, §12 `daily_limit` is step 1. The ramp starts again at step 1 each time the store switches real sends on.

**Automatic stop** (rule PRO-3708; review rule INT-150): after 1 complaint, or when opt-outs exceed 1% over at least 50 sends in 7 days, the engine switches real sends back to test mode. §12 shows `setup_state: stopped`, the `stop_reason` and the blocker `stopped_needs_review`. **Only a Smaily operator's review ends the stop** (audit row `automation.stop_reviewed`). The store's switch does not end it: this action answers `409 go_live_blocked` with `stopped_needs_review` until the review, and `live: false`, a §13 save or `enabled: false` and `true` again change nothing about the stop. After the review the state is `test`, and the store can switch real sends on again with this action; the stop rules then count only sends made at or after the review. A stop by an operator (`stop_reason: operator`) ends the same way. **A test email does not count** (v1.18.0, Erkki 2026-10-23): a §19 test send and the reactions to that test email count toward neither rule — not the complaint, not the opt-outs, not the 50 sends. The same person's reactions to real emails count like any contact's. The engine keeps no copy of the test address.

**Response 200 OK**:

```json
{
  "ok": true,
  "trigger_key": "replenish_due",
  "changed": true,
  "setup_state": "live",
  "live_since": "2026-10-21T08:00:00.000Z",
  "daily_limit": 10
}
```

`setup_state`, `live_since` and `daily_limit` have the §12 meaning.

**Errors** (body `{error, message?, details?}`, as in [Error response format](#error-response-format)):

| Status | `error` | Meaning |
| --- | --- | --- |
| 400 | `invalid_json` | The body is not valid JSON. |
| 401 | `unauthorised` / `api_key_revoked` | As in [Authentication](#authentication). |
| 403 | `tenant_inactive` | The store is suspended or marked for deletion (§2, §16). |
| 404 | `unknown_automation` | `{key}` is not in this store's catalogue. |
| 409 | `go_live_blocked` | Real sends cannot be switched on now. `details.blockers` has the §12 `go_live_blockers` values, for example `{"error":"go_live_blocked","message":"…","details":{"blockers":["stopped_needs_review"]}}`. Show the reasons. For `stopped_needs_review`, say that a Smaily operator reviews the stop first. |
| 422 | `validation_failed` | `live` is missing, or `live` is `true` and `confirmed` is not `true`, or `switched_by` is invalid. Body: `{"error":"validation_failed","errors":[{"field":"confirmed","message":"…"}]}` (the §13 shape, without `index`). |
| 429 | `rate_limit_exceeded` | See [Rate limiting](#rate-limiting). |

**Idempotency**: the request names the target state. Repeating it answers `200` with `changed: false`.

**Endpoint map key**: `automations_go_live` (§1). A connection set up before v1.15.0 does not have the key — use the fallback path constant. A plugin with neither the key nor the constant has no go-live button; a Smaily operator switches real sends on for its store, as in v1.14.0.

**Audit log**: every change (`admin_audit_log`, action `automation.real_sends.on` or `automation.real_sends.off`), every refused `live: true` (`automation.real_sends.refused`, with the blockers) and every operator review of a stop (`automation.stop_reviewed`, with the stop's rule and time). Each row holds the store's tenant id, the email key, the API key id or the setup session (the operator for a review), `switched_by` when sent, and the state before the call. No other identifier. The `automation.real_sends.on` row is the store's go-live consent record, also when real sends start later because the workflow was not active yet; it replaces the merchant's written yes of the pilot rule ([§13](#13-put-apiv1automationsconfig)).

**Curl example**:

```bash
curl -X POST https://intelligence.smaily.com/api/v1/automations/replenish_due/go-live \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{"live":true,"confirmed":true,"switched_by":"17"}'
```

---

### 21. POST /api/v1/onboarding/start

> **Built; switched off until the terms text is published** (published 2026-10-22): the engine answers `404 not_found` until then. A connector must treat `404` as "not available yet", keep its current behaviour and show the operator path (contact Smaily). This endpoint has no setup-exchange endpoint map key: the plugin calls it before it has a connection, so it uses its path constant.

**Start Campaign Intelligence for a shop that has no store in the engine** (v1.16.0, INT-134, Erkki's decision 2026-10-21). Call it when the shop admin presses "Start Campaign Intelligence" in the plugin and the plugin has no engine connection yet.

**URL**: `POST /api/v1/onboarding/start`

**Auth**: none. Call it **server-to-server** (from the shop's backend, never from the browser): the body carries the Smaily API password. The engine checks the Smaily API login with one Smaily call (`GET https://{subdomain}.sendsmaily.net/api/list.php`).

**Rate limit**: 10 requests per 60 seconds per IP.

**Request body**:

```json
{
  "shop_url": "https://shop.example",
  "platform": "woocommerce",
  "smaily_subdomain": "myshop",
  "smaily_username": "api-user",
  "smaily_password": "••••••••",
  "opener_origin": "https://admin.shopify.com"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `shop_url` | string (URL), max 2048 | The shop's public address, `http` or `https`. It becomes the store's product link base. Its origin is the default target origin of the window message. |
| `platform` | string, 1–32 | `woocommerce`, `shopify` or `magento`. Recorded with the store creation. |
| `smaily_subdomain` | string, max 64 | Only the subdomain (`myshop`), not `myshop.sendsmaily.net`. Letters, digits and `-`; case does not matter. |
| `smaily_username` | string, 1–255 | Smaily API username. |
| `smaily_password` | string, 1–255 | Smaily API password. |
| `opener_origin` | string, optional, max 2048 | The origin of the plugin's window that opens `start_url` (scheme and host, optional port, no path), for example `https://admin.shopify.com`. The start window sends the setup token to this origin only. Omitted: the origin of `shop_url`. Accepted values: the origin of `shop_url`, `https://admin.shopify.com`, and the app hosts the engine lists (ask Smaily to add a Shopify app host). Any other value: `400 opener_origin_not_allowed`. |

**Response 200 OK** — the account has no store yet:

```json
{
  "start_url": "https://intelligence.smaily.com/onboarding/start#t=AQ3f…",
  "expires_at": "2026-10-21T09:05:00.000Z"
}
```

| Field | Notes |
| --- | --- |
| `start_url` | Opaque one-time URL. Open it at once with `window.open(start_url)` from the shop admin page (a new window or tab, top level, not an iframe). Do not store, log or send it anywhere else. The token is in the URL fragment (`#t=…`), which the browser does not send to any server. |
| `expires_at` | Issue time + **5 minutes**. |

**Response 409** — the account already has a store (a live or suspended one; a deleted store does not count, see rule 6):

```json
{
  "error": "store_exists",
  "message": "This Smaily account already has a store.",
  "console_url": "https://intelligence.smaily.com/console/<tenant_id>/home"
}
```

Open `console_url` for the shop admin. The console asks the person to sign in. To connect this plugin to the existing store, the store creates a setup link in its console (Settings → Connections) and the plugin uses §1.

**Errors**:

| Status | `error` | Meaning | What the plugin shows |
| --- | --- | --- | --- |
| 400 | `validation_failed` | A field is missing or malformed. | Its own settings error. |
| 400 | `opener_origin_not_allowed` | `opener_origin` is not an origin, or not an accepted one. `message` says why. | Its own error; a plugin bug, not a store problem. |
| 401 | `smaily_login_refused` | Smaily refused the API login. | "Check the Smaily API login in the plugin settings." |
| 403 | `smaily_paid_package_required` | Smaily answered code 227: the package has no API access. | "Your Smaily package does not include API access", and the package upgrade. No onboarding. |
| 404 | `not_found` | The self-service path is off. | The operator path (contact Smaily). |
| 409 | `store_exists` | See above. | Open `console_url`. |
| 429 | `rate_limit_exceeded` | See [Rate limiting](#rate-limiting). | Try again later. |
| 502 | `smaily_unavailable` | Smaily did not answer. | Try again shortly. |

**The start window** (engine-hosted; the plugin builds nothing for it):

1. Shows the shop address from the link for confirmation. A wrong address is corrected in the plugin settings and the start is repeated.
2. Asks the store to accept the terms of service, pick its industry and give the email for its console account. Only the industries open for self-service can be picked (today pet and beauty); the others say "set up by our team".
3. On submit, the engine creates in one step: the store; a console **editor** account for the email, with a sign-in link sent to it; the Smaily API login, stored encrypted; a record of the terms acceptance. The campaign statistics sync starts at once. Contact sync stays off: **nothing is written to any Smaily contact before the operator activates the store.**
4. Sends the setup token to the window that opened it:

   ```js
   window.opener.postMessage(
     { type: 'smaily_intelligence_setup', setup_token: '…', setup_url: 'https://intelligence.smaily.com/setup/…' },
     '<opener_origin>'
   );
   ```

   The target origin is the `opener_origin` of the start request, by default the origin of `shop_url` — never `*`. The window also shows the setup link with a copy button, for the case where the message cannot arrive.

**What the plugin does with the message**:

```js
window.addEventListener('message', (event) => {
  if (event.origin !== 'https://intelligence.smaily.com') return;
  if (event.data?.type !== 'smaily_intelligence_setup') return;
  // Send event.data.setup_token to the shop backend, which calls §1 POST /api/setup/exchange.
});
```

Check `event.origin` against the engine base URL. Send the token to the shop backend, which runs the §1 exchange and stores the API key as usual. Do not store or log the setup token. When no message arrives, the store pastes the setup link from the window into the plugin's existing setup-link field.

**Rules**:

1. **Five minutes.** The start link stops working at `expires_at`, 5 minutes after it was issued. The window then says "This link has expired or is not valid. Start again from your plugin."
2. **One store.** A start link creates at most one store. A second submit of the same link — or any later start for the same Smaily account while its store exists — answers "this Smaily account already has a store" with the console link, and hands out no setup token.
3. **One Smaily account, one store.** The subdomain identifies the account; case and surrounding spaces do not matter. Stores with several Smaily accounts are not covered: such a store is set up by the operator.
4. **No contact writes before activation.** The new store has contact sync off until the operator activates it.
5. **Credentials.** The engine never returns the Smaily API login, and never writes it to a log or an audit record. It is stored encrypted, as when the operator enters it.
6. **A deleted store frees its account** (Erkki 2026-10-21). A store that is purged, or whose deletion was requested (§16 "What deletion does"), no longer holds its Smaily account. A start for that account answers `200` with a start link, and the submit creates a new store; the old store gives up the account at that moment. The new store starts empty: nothing of the deleted store's data comes back. A paused store (§16, no connection left) still holds it: `start` answers `store_exists`, and the store is connected again with a new setup exchange.
7. **Window origin.** The setup token goes only to the origin named by `opener_origin` (default: the shop's origin). The origin travels inside the encrypted start link; the engine stores it nowhere else.

**Audit log**: the store creation (`admin_audit_log`, action `tenant.create`, `metadata.via = "self_service"`, with the industry, the platform and the Smaily subdomain) and the terms acceptance (`terms.accept`, with the terms version), both with the console email as the actor. When the account was held by a deleted store (rule 6), one more record: `tenant.smaily_account_released` on the old store, which names the old store only. The setup exchange writes its existing records (§1).

**Curl example**:

```bash
curl -X POST https://intelligence.smaily.com/api/v1/onboarding/start \
  -H "Content-Type: application/json" \
  -d '{"shop_url":"https://shop.example","platform":"woocommerce","smaily_subdomain":"myshop","smaily_username":"api-user","smaily_password":"…"}'
```

---

### 22. POST /api/v1/emails/{email_key}/design-link

> **Live since 2026-10-22; the Smaily push is not live yet.** A design saved on the page is stored in the engine. It reaches Smaily only when Smaily's automations API extension is available ([§23 Smaily dependency](#smaily-dependency)). The page's test send (rule 4) comes with §19.

**One-time link to the engine design page for any email the store sends** (v1.17.0, INT-11). The store edits the email's subject, sender and design in the embedded editor, on a starter built from the brand kit (§18), and sends a test email of it (§19 rules). The engine stores the design and pushes it to Smaily (§23). Call it when the shop admin presses "Edit design" on an email in the plugin — for an engine email, a plugin email (for example the abandoned-cart email) or a transactional email (for example the order confirmation).

**The engine never decides when a plugin or transactional email is sent.** The plugin keeps its own settings and state for those emails. The design page shows no on/off switch, timing, test mode or go-live for them, and the plugin does not ask the engine before a send run. The design page offers a test send for them (rule 4).

**URL**: `POST /api/v1/emails/{email_key}/design-link`

**`{email_key}`** — one of:

| Kind | Form | Accepted when |
| --- | --- | --- |
| Engine email | a §11 catalogue `key`, for example `replenish_due` | The key is in this store's catalogue. The link opens the §17 setup page (design, settings, test send, go-live) for that email. |
| Plugin email | `plugin.<name>` | The engine has a starter for the key (table below). |
| Transactional email | `transactional.<name>` | The engine has a starter for the key (table below). |

`<name>` matches `^[a-z][a-z0-9_]{0,49}$`. The plugin names its own keys from the table below; the engine accepts only keys it has a starter for. §23 lists the keys this store can use; render that list, do not hardcode it.

**Starters in v1.17.0**:

| `email_key` | What it is | Sent through | Locked blocks |
| --- | --- | --- | --- |
| `plugin.welcome` | Welcome email when a contact joins the newsletter | Smaily workflow | unsubscribe link |
| `plugin.first_order` | Email after a customer's first order | Smaily workflow | unsubscribe link |
| `plugin.abandoned_cart` | Email with the products left in a cart | Smaily workflow | unsubscribe link, cart block |
| `plugin.follow_up` | Email some days after an order | Smaily workflow | unsubscribe link |
| `transactional.order_confirmation` | Order confirmation | Smaily transactional sending | order block, recommendation block |
| `transactional.shipping_confirmation` | Shipping confirmation | Smaily transactional sending | order block |

Engine emails keep the v1.15.0 design check: locked recommendation blocks, the unsubscribe link and the `smaily_rec` tracking parameter on every recommendation link. **Recommendation blocks are allowed in plugin and transactional emails** (Erkki, 2026-10-22). A transactional starter may include one: the `transactional.order_confirmation` starter does. In a plugin email the store may add one. A recommendation block in a plugin or transactional design is locked, and its links carry `smaily_rec`. "Locked" means: the store can move the block, not remove it; the design check (§23 `design_state: "check_failed"`) fails when it is missing.

**Auth**: `Authorization: Bearer sk_...` — the connection's own API key. The store is the API key's store.

**Rate limit**: 10 requests per 60 seconds per store.

**Request body** (all fields optional; `{}` is valid) — the same fields and rules as §17:

```json
{
  "return_url": "https://shop.example/wp-admin/admin.php?page=smaily-connect&tab=emails",
  "language": "et"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `return_url` | string (URL), max 2048 | As in §17. |
| `language` | ISO 639-1 | Language of the design page and of the starter copy. When absent or not supported, the store's default language. |

**Response 200 OK** — as in §17:

```json
{
  "url": "https://intelligence.smaily.com/design/email/dl_7Hc2…",
  "expires_at": "2026-10-23T08:05:00.000Z"
}
```

**Rules**: §17 rules 1–5 apply unchanged (one time, own store only, a 60-minute session that names no person, read §23 again on return; no callback). In addition:

1. **What the page writes for a plugin or transactional email**: subject, preheader (plugin emails only), sender name and address, and the design. Nothing else. **Subject and preheader are part of the email design**: the engine stores them with the design, not as separate settings, and no §13 field holds them.
2. **Save pushes.** Each save replaces the stored design, raises `version` by 1 and starts the push to Smaily (§23). The page shows the push result. A design that fails the design check is stored and not pushed.
3. **Reopen.** The next link for the same email key opens the stored design source, not a new starter.
4. **Test send.** The page sends one test email of the design to one address the store types, for every email key — engine, plugin and transactional. The §19 rules apply: real products, no customer's name or email, the same rate limit and audit; the sender state is information only (v1.18.0). For a plugin or transactional email, products from the store's catalogue (best sellers) take the place of the cart lines or the order lines.
5. **History.** The engine keeps only the current design and its `version`; it keeps no earlier versions. Smaily's template revisions keep the history of pushed designs.
6. **Embedding.** A design link may be opened in a frame inside the store's admin, under the rules in [Embedding](#embedding) below. A §17 setup link may not.

#### Embedding

> **Not live yet** (v1.18.0, INT-147): the engine still serves every page with `frame-ancestors 'self'` and keeps the page's session in a `SameSite=Lax` cookie, so a design page in a frame shows nothing. Until the engine announces Embedding, open the design link in a new tab (§17) as today.

**(v1.18.0, INT-147)** The page that a design link opens — the design page, or the setup page for an engine email key — may be shown in a frame (`<iframe>`) inside the store's admin. A new tab (§17) stays allowed.

1. **Who may frame it.** The same origins that §21 `opener_origin` accepts (Erkki 2026-10-23), for the connection whose API key minted the link: (a) the origin of the connection's `plugin_info.site_url` (§1); (b) `https://admin.shopify.com`; (c) the app hosts the engine lists — the same list as for `opener_origin` (ask Smaily to add a Shopify app host, or a Magento admin that runs on its own host). The engine serves the page with `Content-Security-Policy: frame-ancestors <those origins>`, for example `frame-ancestors https://shop.example https://admin.shopify.com`. Scheme, host and port must match exactly; subdomains do not match. Browsers check every ancestor frame, so every page between the top window and the design page must be on one of those origins: an embedded Shopify app (`admin.shopify.com`, then the app host, then the design page) can frame it once its app host is listed. An origin under (a) that is not `https` is not accepted.
2. **Session in a frame.** The page keeps its §17 session in a cookie that the browser may treat as a third-party cookie in a frame. When the browser refuses it, the page shows "Your browser does not allow this page inside your store — open it in a new tab" and nothing else. The plugin keeps an "Open in a new tab" choice next to the frame: it mints a new link and opens it top-level, as in §17.
3. **Close.** In a frame, the page shows a "Close" button in place of "Back to your store".
4. **Message to the parent.** When the store saves the design, and when it presses "Close", the page posts one message to its parent window:

   ```js
   window.parent.postMessage(
     { type: 'smaily_intelligence_design', event: 'saved', email_key: 'plugin.abandoned_cart', version: 4 },
     'https://shop.example'
   );
   ```

   | Field | Type | Notes |
   | --- | --- | --- |
   | `type` | `"smaily_intelligence_design"` | Fixed. |
   | `event` | `"saved"` \| `"closed"` | `saved`: a save stored a new `version` (it may still fail the design check or wait for its push — read §23). `closed`: the store pressed "Close". Unknown value: ignore the message. |
   | `email_key` | string | The email key of the link (§22). After the store switches to another email on the page, the key of that email. |
   | `version` | integer | `saved` only: the stored design version (§23 `version`). Absent for `closed`. |

   The target origin follows rule 1 — never `*`: the page posts the message once with each origin of rule 1 as the target, and the browser delivers it only where the target matches the parent's origin. The message carries no session token, no link, no name and no email address. A page in a top-level tab posts no message.
5. **What the plugin does with the message**:

   ```js
   window.addEventListener('message', (event) => {
     if (event.origin !== 'https://intelligence.smaily.com') return; // the origin of engine_base_url (§1)
     if (event.data?.type !== 'smaily_intelligence_design') return;
     if (event.data.event === 'saved') { /* read §23 again for event.data.email_key */ }
     if (event.data.event === 'closed') { /* remove the frame, read §23 again */ }
   });
   ```

   The message is a hint, not a callback: the plugin reads §23 for the state and never takes a design state or a Smaily id from the message.

**Not in v1.18.0**: creating a Smaily automation from the plugin (it waits on Smaily's automations API).


**Errors**:

| Status | `error` | Meaning |
| --- | --- | --- |
| 401 | `unauthorised` / `api_key_revoked` | As in [Authentication](#authentication). |
| 403 | `tenant_inactive` | The store is suspended or marked for deletion (§2, §16). |
| 404 | `unknown_email` | `{email_key}` is not a catalogue key of this store and has no starter. |
| 422 | `validation_failed` | `return_url` or `language` is invalid (the §17 shape). |
| 429 | `rate_limit_exceeded` | See [Rate limiting](#rate-limiting). |

**Idempotency**: none needed. Each call mints a new link.

**Endpoint map key**: `emails_design_link` (§1).

**Audit log**: every mint (`admin_audit_log`, action `email.design_link.mint`), every redemption (`email.design_link.redeem`) and every saved version (`email.design.save`), with the store's tenant id, the email key, the API key id or the setup session; no other identifier.

**Curl example**:

```bash
curl -X POST https://intelligence.smaily.com/api/v1/emails/plugin.abandoned_cart/design-link \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{"return_url":"https://shop.example/wp-admin/admin.php?page=smaily-connect","language":"et"}'
```

---

### 23. GET /api/v1/emails/designs

> **Live since 2026-10-22; the Smaily push is not live yet.** Until Smaily's automations API extension is available, every item carries `smaily_state: "not_available"` and `null` Smaily ids ([Smaily dependency](#smaily-dependency)).
>
> **Not live yet** (v1.18.0 additions, INT-146): until the engine announces them, items omit `smaily_subdomain` and `created_by`, and an engine email's item does not yet name the workflow that an operator bound by hand (`smaily_workflow_id` stays `null` for it). A missing field means `null`. A plugin that implements the v1.18.0 rules below keeps its current workflows while the ids are `null`, so nothing changes until the engine announces it.

**Read back every email design of the store** (v1.17.0, INT-11): the design state and the Smaily ids per email key. Call it when the shop admin opens the plugin's email settings and when the store returns from the design page. **Do not call it before a send run**: the plugin stores the ids it read and uses them until the next read.

**URL**: `GET /api/v1/emails/designs`

**Query parameters**:

| Parameter | Notes |
| --- | --- |
| `email_key` | Optional. Return only this email key. Unknown → `404 unknown_email`. |

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec (the `/api/v1/emails/...` row).

**Response 200 OK**:

```json
{
  "emails": [
    {
      "email_key": "plugin.abandoned_cart",
      "kind": "plugin",
      "name_et": "Mahajäetud ostukorv",
      "name_en": "Abandoned cart",
      "sent_through": "workflow",
      "design_state": "edited",
      "subject": "You left something in your cart",
      "sender": { "from_name": "Shop Example", "from_address": "news@shop.example" },
      "version": 3,
      "smaily_state": "pushed",
      "smaily_template_id": "123",
      "smaily_workflow_id": "456",
      "smaily_subdomain": "shopexample",
      "created_by": "engine",
      "smaily_pushed_at": "2026-10-23T08:10:00.000Z",
      "updated_at": "2026-10-23T08:09:58.000Z"
    },
    {
      "email_key": "transactional.order_confirmation",
      "kind": "transactional",
      "name_et": "Tellimuse kinnitus",
      "name_en": "Order confirmation",
      "sent_through": "transactional",
      "design_state": "none",
      "subject": null,
      "sender": null,
      "version": 0,
      "smaily_state": "not_available",
      "smaily_template_id": null,
      "smaily_workflow_id": null,
      "smaily_subdomain": null,
      "created_by": null,
      "smaily_pushed_at": null,
      "updated_at": null
    }
  ]
}
```

One item for **every** email key this store can design: every §11 catalogue email, and every plugin and transactional starter. An item exists before any design is saved (`design_state: "none"`).

**Field reference** (per `emails[]` item):

| Field | Type | Notes |
| --- | --- | --- |
| `email_key` | string | The email key (§22). |
| `kind` | `"engine"` \| `"plugin"` \| `"transactional"` | Engine email (§11), plugin email or transactional email. Unknown value: ignore the item. |
| `name_et` / `name_en` | string | Display name. |
| `sent_through` | `"workflow"` \| `"transactional"` | How the email starts: `workflow` — a contact is enrolled into the workflow (engine and plugin emails); `transactional` — the plugin sends one message through Smaily's transactional sending, naming the workflow (`smaily_workflow_id`). Both use a Smaily workflow (v1.18.0). |
| `design_state` | `"none"` \| `"starter"` \| `"edited"` \| `"check_failed"` | As in §12. `check_failed`: a locked block, the unsubscribe link (not for transactional emails) or the `smaily_rec` parameter on a recommendation link is missing. |
| `subject` | string \| null | The saved subject. `null` when no design is saved. |
| `sender` | object \| null | `from_name`, `from_address`. `null` when not set. |
| `version` | integer | The saved design version; `0` when none. It grows by 1 at each save. The engine keeps only the current version. |
| `smaily_state` | `"not_available"` \| `"pending"` \| `"pushed"` \| `"failed"` | `not_available`: the engine cannot push to Smaily for this store yet. `pending`: a saved version waits for its push. `pushed`: Smaily holds the saved version. `failed`: the last push failed; the ids, when present, belong to the last pushed version. Unknown value: treat as `not_available`. |
| `smaily_template_id` | string \| null | The Smaily template that holds the pushed HTML. Numeric string. `null` until the first push. Informational: the plugin starts every email with `smaily_workflow_id`, not with the template id (v1.18.0). |
| `smaily_workflow_id` | string \| null | **Every kind** (v1.18.0): the Smaily workflow that sends this email — an engine email, a plugin email, or a transactional email such as the order or shipping confirmation. Numeric string. For a plugin or transactional email, `null` until the engine's first push. For an engine email, the workflow the engine enrols contacts into — the same id as §12 `configs[].automation_map`, also when an operator bound a hand-built workflow; `null` when none. |
| `smaily_subdomain` | string \| null | **New in v1.18.0.** The Smaily account that holds `smaily_workflow_id` and `smaily_template_id`: its subdomain, as in `https://{subdomain}.sendsmaily.net`. `null` when both ids are `null`. Smaily numbers workflows per account, so the id is valid only in this account. |
| `created_by` | `"engine"` \| `"other"` \| null | **New in v1.18.0.** Who created the workflow named by `smaily_workflow_id`. `engine`: the engine created it with a push. `other`: the engine uses a workflow it did not create (for example a hand-built workflow that an operator bound to an engine email). `null` when `smaily_workflow_id` is `null`. Unknown value: treat as `other`. |
| `smaily_pushed_at` | ISO 8601 \| null | When the last push succeeded. |
| `updated_at` | ISO 8601 \| null | When the design was last saved. |

**What the plugin does with the ids**:

- **Plugin email and transactional email** (the same rule for both since v1.18.0): when `smaily_workflow_id` is present **and** `smaily_subdomain` is the Smaily account the plugin sends this email from, the plugin starts the email with that workflow, in place of the workflow that the shop admin picked or that the plugin created — for a transactional email, as the workflow of its transactional send. Otherwise the plugin keeps its current workflow; it never uses the id with another account. The plugin's own settings (on/off, timing, cart cutoff, test mode) decide when the email is sent, as before.
- **Engine email**: the plugin does nothing with the ids. The engine enrols contacts into its own workflow; §12 `configs[].automation_map` shows the workflow id the engine uses.
- **Edit button next to an automation.** Where the plugin lists Smaily automations (workflows) — for example in a workflow picker — it shows its "Edit design" button (§22) next to a workflow only when a §23 item names that workflow (`smaily_workflow_id` and `smaily_subdomain`) with `created_by: "engine"`. A workflow with `created_by: "other"`, or one that no item names, is edited in Smaily. The "Edit design" button on an email card stays as in v1.17.0, except when the item has `created_by: "other"`: then the card links to Smaily instead, because the hand-built workflow holds the design.
- An id can change after a save (a new template or workflow). The plugin reads §23 again on return from the design page, or after a `saved` or `closed` message ([§22 Embedding](#embedding)), and uses the ids it read last.

#### Smaily dependency

**The engine starts pushing when Smaily's automations API extension is available.** Until then, `smaily_state` is `not_available`, the ids are `null`, and the design is stored in the engine only.

- **Workflows.** Creating, switching on and updating a workflow come with Smaily's automations API extension.
- **Smaily v1 accounts.** Access for a v1 account comes with the same extension.
- **Contact fields.** A contact field (for example `rec_1_name`) is a Smaily merge tag inside the template HTML. The engine already writes these contact fields to the contact (contact sync).
- **Creating an automation from the plugin** is not in v1.18.0: it waits on Smaily's automations API.

The push is **not live yet**. The contract does not name the Smaily calls; it names only the ids the plugin reads.

**Error responses**: `401`, `403 tenant_inactive`, `404 unknown_email` (with `email_key`), `429`.

**Idempotency**: not applicable (read-only endpoint).

**Endpoint map key**: `emails_designs` (§1).

**Curl example**:

```bash
curl -X GET "https://intelligence.smaily.com/api/v1/emails/designs?email_key=plugin.abandoned_cart" \
  -H "Authorization: Bearer sk_..."
```

---

## Appendices

### Appendix A: Pagination (future)

v1.0 does **not** support pagination — all batch endpoints are **single-request, max 100 (50 for orders) per call**. The plugin splits larger batches itself.

v2.0 plans cursor-based pagination for GET endpoints (e.g. `GET /api/v1/customers?cursor=...` for admin UI).

### Appendix B: Webhooks (future)

v1.0 does **not** support webhook back-channel. The plugin polls for data.

v2.0 plans registrable webhooks:
```
POST /api/v1/webhooks
{ "url": "https://shop.com/webhook", "events": ["sync.completed", "recs.updated"] }
```

JSON schema (reserved for v2):
```json
{
  "webhook_id": "wh_uuid",
  "event_type": "sync.completed",
  "tenant_id": "tnt_uuid",
  "occurred_at": "2026-05-19T10:15:23Z",
  "data": { }
}
```

HMAC signature in the `X-Smaily-Signature` header.

### Appendix C: Multi-tenant single-WP (future)

v1.0 = **1 plugin = 1 tenant**. The plugin's WordPress options table stores only one `api_key` + `tenant_id`.

v2.0 plans multi-tenant single-WP (agencies + WPMU + WC Multistore). The API design (setup-token + tenant-bound config) already supports this.

### Appendix D: Curl examples

**Test connection**:
```bash
curl -X GET https://intelligence.smaily.com/api/v1/ingest/ping \
  -H "Authorization: Bearer sk_..."
```

**Catalog upload**:
```bash
curl -X POST https://intelligence.smaily.com/api/v1/ingest/catalog \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "products": [
      {
        "event_id": "evt_b3f1a2c4-4e1c-4d8a-9b2f-7e3d1a6c8b9e",
        "sku": "TEST-001",
        "name": "Test Product",
        "category_path": "test",
        "price": 9.99,
        "in_stock": true,
        "product_url": "https://example.com/test"
      }
    ]
  }'
```

**Browse event**:
```bash
curl -X POST https://intelligence.smaily.com/api/v1/ingest/browse \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "event_id": "evt_7d8f3a2b-4e1c-4d8a-9b2f-7e3d1a6c8b9e",
        "session_id": "test_session_001",
        "event_type": "product_view",
        "sku": "TEST-001",
        "event_ts": "2026-05-19T10:15:23Z",
        "source": "plugin_woo"
      }
    ]
  }'
```

### Appendix E: Changelog

**v1.0.0** (2026-05-19) — Initial stable release.
- 10 endpoints documented
- URL namespace + cookie names finalized
- Plugin-agent feedback integrated (`event_id` idempotency, `compare_price`, multilingual, GDPR endpoint set, `smaily_rec` parameter, rate limit + `Retry-After`)
- Attribution flow documented (within `/api/v1/ingest/orders`)
- Retroactive session binding documented (within `/api/v1/ingest/browse`)
- GDPR endpoint set (export, delete, opt-out)

**v1.0.0 — 2026-05-22 documentation update** (no breaking changes):
- Translated to English
- Added document-sync header note (this contract lives in two repos)
- Added §7 [Idempotency](#idempotency) covering both natural-key UPSERT (Layer 1) and `event_id` deduplication (Layer 2)
- Extended `event_id` documentation: catalog/customers/orders endpoints now show it explicitly (optional); browse continues to require it
- Corrected dedup window: 90-day permanent (via `ingest_event_log` table), not 60-minute (the earlier draft pre-dated the migration 0025 implementation)
- Added 11th endpoint key to setup-response example: `recommendations_preview`, `recommendations_issue` (previously omitted from the map — the endpoints existed but weren't surfaced)
- Renamed setup-response endpoint map keys to use `ingest_*` / `customer_*` / `recommendations_*` prefixes consistently (e.g. `catalog` → `ingest_catalog`) — the plugin can now read `endpoints[ingest_catalog]` rather than constructing paths
- Added note on engine version consistency (single ENGINE_VERSION env var feeds both HTTP header and Smaily contact-sync payload)
- Added note on consent (Smaily is source of truth; engine accepts but doesn't gate on consent fields)

**v1.0.0 — W1 idempotency contract sync** (no breaking changes for plugin clients; no prior consumers of the removed path):
- **Per-item `event_id` dedup on all four ingest endpoints.** Each `products[]` / `customers[]` / `orders[]` / `events[]` object may carry its own `event_id`; dedup is per item, including intra-batch duplicates (first occurrence `processed`, second `deduplicated`). Engine commit `1c9b4e9`.
- **Integer `processed` / `deduplicated` counts** replace the old boolean `{"deduplicated": true}` retry response, plus an optional `"deduplicated_all": true` flag for a pure no-op retry. §7 and all four endpoints' Layer-2 response examples updated accordingly.
- **Wrapper-level `event_id` removed** (no consumers — plugin ingest had no prior clients; MiuMjau flows through the admin CSV path). A stray top-level `event_id` is now silently ignored (Zod strips it); there is no whole-request boolean short-circuit. Engine commit `01b7950`.
- Added **Implementation status** notes to §4 Customers and §5 Orders: the engine currently accepts single-object payloads; `customers[]` / `orders[]` batch arrays are target state for W4 / W5. (The `products` → `items` catalog wrapper-key correction remains a separate tracked item.)

**v1.0.0 — W2 catalog field expansion** (no breaking changes for the plugin — it already sends the full field set):
- **Catalog ingest expanded to the full §3 field set**, mapped through a shared `ProductSchema` + `toCatalogInsert()` (single source of truth for plugin ingest + admin CSV). Engine commits `b5b1295`, `81b0936`.
- **Wrapper renamed `items[]` → `products[]`** (clean break; an `items[]`-wrapped payload now fails validation). Engine commit `b5b1295`.
- **Multilingual** `name` / `description` / `product_url`: `string | {lang: string}`; string wrapped as `{default}`, object stored in the `*_i18n` JSONB column + a representative plain scalar; `description` truncated to 500/language.
- **`external_id` + `raw_attributes` columns added** (migration `0026`, additive/nullable). `raw_attributes` is raw-store only (the AI wizard / `unmapped_attributes` is not implemented).
- **`compare_price` / `on_sale_until` accepted and stored** (store-only; sale-display math is W3).
- **`product_url` (required + non-empty) and `in_stock` (required) tightened** to match spec §3, per F3-17 (the plugin always sends both). Empty `product_url` fails loud (400) rather than falling back to `product_base_url + sku`. This brief's commits.
- §3 response example corrected to the real shape `{ok, processed, deduplicated, errors}` (removed `created`/`updated`/`skipped`/`unmapped_attributes`/`request_id`); documented all-or-nothing validation and the scalar-only `image_url` limitation.

**v1.0.0 — W3 price rationalization** (no breaking changes for the plugin — it already sends `compare_price`/`on_sale_until`):
- **`discount_price` / `discount_until` removed**; `compare_price` / `on_sale_until` are now the canonical sale fields. Engine commit `2cd7d26` (migration `0028` drops the old columns; `0027` backfilled).
- **Sale semantics = `compare_price > price`** (D2 Variant 1, Shopify): savings = `compare_price - price`; null / equal / less than `price` → no sale (no strikethrough, no negative savings). Engine commit `3aa5707`.
- **`on_sale_until` is informational only** — stored, does not gate sale display.
- **Smaily contact-sync slot renamed** `rec_N_discount_price` → `rec_N_compare_price` (clean rename, no alias; value from `compare_price`). Engine commit `3aa5707`.
- **Admin/plugin validation aligned (N-4a)**: the admin CSV path now also requires `product_url` (non-empty) + `in_stock`, matching `ProductSchema`. Engine commit `852ea04`.
- ⚠️ **Migrating legacy `discount_price` is NOT a literal copy** — see [Appendix F: Migration notes](#appendix-f-migration-notes) (N-6 semantic inversion).

**v1.0.0 — W4 customers email-first identity** (plugin sends batch + email; the 3.3 customers contract):
- **`smaily_contact_id` dropped — `email` is the identity (D1)**. UPSERT by `(tenant_id, email)`; email lowercased on ingest, matched case-insensitively. Engine commits `04ac1ad`, `f8494ea` (migrations `0029` add `(tenant_id,email)` unique + columns; `0030` drops `smaily_contact_id`).
- **Batch wrapper**: `{ customers: [...] }`, 1..100 per request. Non-array / empty / >100 → 400 (wrapper all-or-nothing). Engine commit `76a7a64`.
- **D6 per-item `errors[]`**: each item is processed / deduplicated / error; partial success (valid items written when others fail); a rejected item's `event_id` is not registered (corrected retry processes); response `{ok, processed, deduplicated, errors:[{index,email?,field,message}]}` (+ `deduplicated_all`); invariant `processed + deduplicated + errors.length == total`. Engine commit `76a7a64`. (This is the canonical D6 shape; catalog/browse retrofit is N-7.)
- **Consent removed entirely** — `consent.*` is no longer accepted, stored, or processed (Smaily owns consent).
- **N-8**: `country` / `language` stored as-sent, not strictly ISO-validated.
- This sync commit reconciles §4 with the above.

**v1.0.0 — N-7 catalog + browse D6 retrofit** (catalog: no behavior change beyond per-item errors; browse: `event_id` now required):
- **Catalog + browse converted from all-or-nothing to D6 per-item `errors[]`** — an invalid item goes to `errors[]` (`{index, sku?, field, message}` for catalog; `{index, field, message}` for browse) and the valid items in the same batch still process. The wrapper stays all-or-nothing (non-array → 400). Engine commits `63d0332` (catalog), `731510a` (browse).
- **Browse `event_id` optional → required** — browse has no Layer-1 natural-key fallback, so a missing `event_id` is now a per-item error (was a silent no-dedup insert). The one behavior change in N-7.
- **All four ingest endpoints now share the D6 contract** (`{ok, processed, deduplicated, errors[]}` + `deduplicated_all`, invariant `processed + deduplicated + errors.length == total`). The plugin can consolidate to a single shared D6 flusher.
- This sync commit reconciles §3 + §6 with the above.

**v1.0.0 — §6 browse event-type extension** (additive; no breaking change):
- **`checkout_start` + `checkout_complete` added to the browse `event_type` enum** (Zod + the DB CHECK constraint, migration `0031`). **Accept + store only** — persisted as ordinary browse events; no checkout-specific logic (abandonment detection, checkout-driven recommendations) yet. Unknown event types are still rejected per-item.
- **`source` documented as optional, default `web`** (the engine schema has `.default('web')` — the spec previously marked it required). Doc aligned to the lenient engine.

**v1.0.0 — W5 orders batch + D6 + async attribution** (the 3.3 orders contract):
- **Orders single-object → batch**: `{ orders: [...] }`, 1..50 per request; non-array / empty / >50 → 400 (wrapper all-or-nothing). Engine commit `343773f`.
- **Email customer reference** (W4 identity): `customer_email` required, lowercased, auto-creates the customer (race-safe). Order natural key is `(tenant_id, external_order_id)`.
- **Rich fields**: `status` (required enum), `currency` (default EUR), `smaily_rec_ctx`, per-line `discount_amount` — migration `0032`. Engine commit `328bccb`.
- **D6 per-item `errors[]`** `{index, external_order_id?, field, message}`; response `{ok, processed, deduplicated, errors}` (+ `deduplicated_all`); invariant `processed + deduplicated + errors.length == total`. Removed the stale `created`/`updated`/`skipped`/`request_id`/`{deduplicated:true}` boolean.
- **Items fully replaced** on re-ingest of an existing `external_order_id` (order is the dedup unit).
- **Attribution is async** (N-10): the ingest route stores attribution signals; the `process-order-attributions` cron computes `rec_attribution` afterward via the unchanged 4-step matching. **No attribution counts in the ingest response** (the old inline `attribution_resolved`/`attribution_control` were aspirational). `smaily_rec_ctx` stored + available, not yet consumed by matching. Engine commit `e06a002`.
- **Customer-UPSERT fixes** (plugin path aligned to admin): Bug 1 — sparse guest UPSERT now `COALESCE(EXCLUDED.x, existing)` so it **preserves** the registered profile (was NULL-wiping it); `first_seen_at` → `LEAST`. Bug 2 — orders auto-create uses `ON CONFLICT (tenant_id,email) DO NOTHING` (was select-then-insert → concurrent-first-order 500). Engine commit `984dab0`.
- This sync commit reconciles §5 with the above.

**v1.0.0 — final spec cleanup: `request_id` + GDPR consent** (documentation-only; no code/schema change):
- **`request_id` removed from every response example where the engine does not emit it** — §7 merge (200 + 404), §9 delete, §10 opt-out, and the generic error / 429 / validation examples. Curl-verified: only `/api/setup/exchange` emits a `request_id` (a `req_…` UUID); §1's examples keep it and a scope note now states the v1 ingest/customer/identity endpoints do not. (§3/§4/§5 were already cleaned during their syncs.)
- **§8 GDPR export `consent.*` removed** — the engine does not store consent (W4 dropped it; Smaily owns it); a `consent` object in the export example was misleading for a compliance reader. Added an explicit "consent is not exported" note.
- **§8 export example replaced with the real curl-verified body** — confirmed **email-only identity** (no `smaily_contact_id`); dropped the non-existent `rec_attribution` array and its retention-policy line (the engine's export omits attribution); `order_items` documented as a **top-level** array (not nested under `orders`); added `visitor_tokens`; `customer` shown as the full row. Empty-data example aligned to the real top-level keys.
- After this pass the spec is reconciled doc-wide: no response example shows `request_id` as emitted outside setup/exchange, and no `consent.*` / `smaily_contact_id` survives where it would mislead.

**v1.1.0** (2026-07-07) — **Automations config API (T2)**. MINOR bump per the [Versioning](#versioning) rule (new endpoints; backward-compatible — nothing existing changed shape):
- **Three new endpoints, §11–§13**: `GET /api/v1/automations/catalog` (sector-filtered trigger catalog + `language_modes` + stable `docs` help URL), `GET /api/v1/automations/config` (current rows incl. read-only `configured_via` + `updated_at`), `PUT /api/v1/automations/config` (full-selection UPSERT, 1..50 rows, all 8 row fields required, `configured_via='plugin'`, never deletes rows). Auth = the same tenant API key as ingest; rate limit = 100 req/s (`rate_limit_other`; the `/api/v1/automations/` prefix is now registered in the limiter — previously the routes' limit checks were no-ops because the prefix was missing, so the documented 429 could never fire).
- **Setup-exchange endpoints map gains `automations_catalog` + `automations_config`** (new `automations_*` prefix in the map convention). Existing connections keep their exchange-time map without these keys — the plugin's fallback path constants cover that (see the "Map age" note in §1).
- **PUT 422 shape is now indexed, D6-style**: `{error: "validation_failed", errors: [{index?, trigger_key?, field, message}]}` — replaces the Zod `flatten()` `details` object, which collapsed every row error under one `fieldErrors.configs` key and made field-level display impossible. Wrapper-level failures return `field: "configs"` with no `index`. Unlike ingest D6, validation is **all-or-nothing** (422 = nothing written). Engine commit `c16377e`.
- This resolves the plugin-team T2 gap brief (`CODE_BRIEF_T2_automations_contract_gaps.md`): §11–§13 document live behavior (`app/api/v1/automations/*`, `lib/automations/config-schema.ts`), verified against code, not the plan.

**v1.2.0** (2026-07-07) — **`recipe_en` on §11 catalog triggers**. MINOR bump per the [Versioning](#versioning) rule (new optional field; backward-compatible — nothing existing changed shape):
- **Every `triggers[]` item now carries `recipe_en`** alongside `recipe_et` (pilot feedback 2026-07-07: a WooCommerce store with an English admin locale saw the Estonian-only recipe). Content-equivalent English recipe, same guidance as `recipe_et`; `name_*` / `description_*` were already bilingual.
- Plugin side: treat `recipe_en` as optional and fall back to `recipe_et` when absent (an older engine won't send it).

**v1.3.0** (2026-07-10) — **Product-level soft removal + `tags.product_id`**. MINOR bump per the [Versioning](#versioning) rule (new endpoint + new optional field; backward-compatible — nothing existing changed shape). PRO-1229 / PRO-1228:
- **New endpoint [§3b `POST /api/v1/ingest/catalog/remove`](#3b-post-apiv1ingestcatalogremove)** — tombstones all SKUs of a product (`in_stock=false` + `recommendable=false`) by parent `product_id`, for platform hard-deletes where the webhook gives only the product id. **Soft only:** catalog rows are never hard-deleted (retained as a learning corpus; GDPR / offboarding is the sole hard-delete path). Idempotent; response `{ok, removed_products, rows_tombstoned, not_found}`.
- **`tags.product_id` documented** (§3 identity) — the platform parent product id, shared by a product's variants; consumed by §3b removal and future cross-variant grouping. Shopify emits it (PRO-1226); Woo (PRO-1224) / Magento follow.
- **Lifecycle bullet clarified**: removal is always *soft*; a product-`delete` webhook is a best-effort fast-path; the **periodic full re-sync is the reconciler** (still no delete-by-absence, no full reconcile).
- **Setup-exchange `endpoints` map gains `ingest_catalog_remove`** (§1) — plugins discover the new endpoint via the map; older installs whose exchange-time map predates it use the absolute path.
- **Merchant-SKU placement clarified** (§3 identity): if ever sent, the merchant SKU goes in `tags.merchant_sku` — never in `external_id` (which carries the platform variant id and drives collision detection). Not consumed by the engine today.
- Plugin side: subscribe the platform product-delete webhook and forward the parent product id to §3b — no local SKU map needed. An older engine returns 404 on this path; treat its absence as "not yet available."

**v1.4.0** (2026-07-10) — **Order amount semantics (gross/tax-inclusive) + `plugin_magento` source constant + browse profiling opt-out documented**. MINOR bump per the [Versioning](#versioning) rule (wire shapes unchanged; normative tightening of amount semantics + additive source constant + documenting existing opt-out behavior). PRO-1202:
- **§5 "Amount semantics (tax basis)" block added** — all money fields on the orders endpoint are normatively **gross (tax-inclusive)**, in the order's `currency`: what the customer actually paid. `total_amount` = grand total as charged (products + shipping + tax − discounts); `line_total` / `unit_price` / `discount_amount` on the same gross basis. Per-platform sender rules documented (Shopify `taxes_included` handling, Woo `get_total() + get_total_tax()`, Magento `row_total_incl_tax`). Sender invariant `Σ items[].line_total + shipping ≈ total_amount` (± rounding; engine may monitor drift, does not reject). The engine stores amounts as sent and never recomputes tax; wrong-basis rows are corrected by re-syncing the affected orders (re-ingest fully replaces line items). `total_amount` / `unit_price` / `line_total` field notes updated accordingly.
- ⚠️ **Woo sender remediation + one-time MiuMjau order re-sync required for the gross basis (tracked in Woo Connect PRO-1241)** — bare `get_total()` is net, i.e. the wrong basis.
- **§6 `source` constants gain `plugin_magento`** (after `plugin_shopify`); documented that the engine stores `source` as an opaque label (not enum-validated) — senders must use their listed constant so per-source analytics stay clean.
- **§6 profiling opt-out (Art 21) enforcement documented** (existing engine behavior, now normative): an opted-out (§10) customer is never bound at browse ingest on any resolution path (visitor token, email, external_id) — the event is stored anonymous and excluded from retroactive binding. Enforcement is engine-side because the visitor token is engine-issued. Sender-side anonymous mode (omit identity hints when profiling consent is absent) documented as a recommended, complementary data-minimization layer.
- **Document sync list gains the Magento Connect repo** (`magento-connect/docs/RECENGINE_API_CONTRACT.md`) as the 4th byte-identical consumer.

**v1.4.1** (2026-07-12) — **`tags.product_id` example + live consumption, and Magento product-identity rule**. PATCH bump per the [Versioning](#versioning) rule (documentation-only; no new endpoint and no new wire field — `tags.product_id` was already introduced optional in v1.3.0, and `tags` is free-form). PRO-1228 / PRO-1267:
- **§3 `tags` example gains `product_id`**, and the identity bullet now states cross-variant grouping is **live**: the engine groups catalog variants sharing `tags.product_id` into a product family for cross-variant cadence + `sample_to_full` (PRO-1227, engine commit `d668108`; was documented as "future" in v1.3.0). `sku` stays the variant key, `external_id` the variant id. Shopify (`9a6ca9f`) and Woo now emit `tags.product_id`; Magento follows with its canonical-key work.
- **§3 Product identity gains an explicit Magento rule** — Magento's catalog `sku` field *is* the platform-canonical key (Magento enforces SKU mandatory + store-unique); `mag-<entity_id>` is a fallback only when the SKU field is empty. Clarified that the "never the merchant SKU field" rule is Shopify/Woo-specific. Order lines read `getSku()`; the fallback must be applied identically on catalog + order lines for an empty-SKU product (Magento Connect follow-up, PRO-1267).

**v1.5.0** (2026-07-17) — **`POST /api/v1/notifications/ingest` (Notifications 2.0 external HTTP ingest)**. MINOR bump per the [Versioning](#versioning) rule (new endpoint; backward-compatible — nothing existing changed shape). PRO-1438, contract locked PRO-1444 (2026-07-17):
- **New endpoint [§14](#14-post-apiv1notificationsingest)** — lets an authenticated caller (Connect plugin, Smaily core, any future service) push notification events into the merchant's console notification drawer. Same bearer auth as every other ingest endpoint (`tenant_api_keys`), same 100 req/sec tier, `{events: [...]}` wrapper (1..50, all-or-nothing) with D6 per-item `errors[]` inside.
- **Registry-gated, fail-closed**: a `type` must already exist in the engine's `notification_types` table with `source_kind` `external`/`both` — unknown type → per-item `400 unknown_notification_type`; a registered-but-internal-only type → per-item `403 type_not_externally_allowed`. No accept-with-default-config path. Registering a new externally-fireable type is an engine-side ops/SQL task.
- **`tenant_id` and `audience` are never read from the body** — always the authenticated tenant, always forced to `merchant`. An external caller can never address another tenant or create an operator/system-wide notification.
- **Idempotency is upsert-on-open-dedupe**, not the Layer 1/Layer 2 scheme used by the bulk ingest endpoints ([Idempotency](#idempotency)): resending the same `(type, dedupe_key)` for a tenant updates the still-open notification in place instead of creating a duplicate drawer entry.
- **Setup-exchange endpoints map gains `notifications_ingest`** (§1) — existing connections whose exchange-time map predates this key fall back to the plugin's own path constants, same "map age" behavior as every prior additive key.
- No consumer calls this yet (no plugin/Smaily-core caller exists at lock time) — documented ahead of any integration so the contract, not a specific caller's behavior, is the source of truth from day one.

**v1.6.0** (2026-07-21) — **`tags.category_defaulted` on catalog ingest, and a deprecation notice for browse `customer_email`**. MINOR bump per the [Versioning](#versioning) rule (new optional field + a wording-only deprecation notice; nothing existing changes shape or behavior). PRO-1500, Erkki-approved 2026-07-21:
- **§3 new optional catalog field [`tags.category_defaulted`](#category-defaulted)** — `"true"`, omit-on-false. Marks a row whose `category_path` is a **placeholder** (a store default-category fallback, or a delete-tombstone row synced with *some* category) rather than real taxonomy. Engine behavior: `lib/ingest/attribute-mapping.ts` `mapRawAttributes()` gains a `categoryDefaulted` parameter that skips every category-**slug**-keyed derivation (species-from-category, `category_canonical`, replenishable-from-category) for such a row; **name**-keyed derivations (species/life-stage-from-name, brand) are unaffected, and explicit tenant-sent tags still always win. A row left without `category_canonical` this way stays eligible for the nightly AI category sweep (verified against `lib/catalog/category-sweep.ts` / `lib/catalog/tag-meta.ts` `needsCategorySweep()` — its selection is keyed only on `category_canonical` + `_tag_meta`, so it already includes these rows without any change). Evaluated fresh per upsert from the request's own `tags` — omitting the flag on a later sync (once a real category is known) re-enables normal derivation for that sync, with no stored state to reset.
- **§6 `customer_email` on browse events marked deprecated for client-originated senders** (documentation only in this release — no code change, no accept-and-ignore yet): no legitimate client-side producer exists, the field is spoofable from browser JS, and the supported logged-in-identity path is the visitor-token cookie + [§7 `identity/merge`](#7-post-apiv1identitymerge). Server-side senders (Make-flow style) may keep sending it during the grace period.

**v1.6.0 — §7 merge response example correction** (documentation-only; no code/schema change). PRO-1533:
- **Removed the non-existent `browse_events_already_bound` field** from the §7 `identity/merge` response example and idempotency note — the route (`app/api/v1/identity/merge/route.ts`) only ever returns `browse_events_updated` / `visitor_tokens_bound` / `session_history_days`; a repeat merge reports `browse_events_updated: 0` rather than a separate already-bound count.

**v1.7.0** (2026-07-23) — **§3 optional `currency` catalog field, and §6 `smaily_rec_id`/`smaily_ctx` browse-attribution-hint deprecation shipped as accept-and-ignore**. MINOR bump per the [Versioning](#versioning) rule (new optional field; and a behavior change confined to two fields with a verified-zero-effect fallback, wire-compatible — nothing existing changes shape). PRO-1524, PRO-1465, Erkki-approved 2026-07-23:
- **§3 new optional catalog field `currency`** (ISO 4217, loosely validated — 3 uppercase letters; default `EUR`) — mirrors `orders.currency` (migration `0032`). Stored on `catalog.currency` (migration `0079`, `NOT NULL DEFAULT 'EUR'`, same pattern as orders). One currency per tenant remains the assumed model. `lib/ingest/catalog-schema.ts` `toCatalogInsert()` omits the key entirely when the sender doesn't send it, so the DB default applies and the write stays safe even mid-deploy, before the migration lands.
- **§6 `smaily_rec_id` / `smaily_ctx` marked deprecated, accept-and-ignore, effective immediately (no grace period needed)** — both are client-originated cookie-echoes and the browse-event fallback they fed (`match-purchase-to-rec.ts` step 4) has never fired in production; real rec-link attribution runs on the order-level cookie→order path (§5) and identity on the visitor-token path. `app/api/v1/ingest/browse/route.ts` still accepts both fields (no validation error) but no longer writes them into `browse_events`. `browse_events.smaily_rec_id` is now always inserted `NULL` (column kept — still read by the same dormant fallback); `browse_events.smaily_ctx` is dropped from the schema (migration `0080`) — grep-verified zero readers anywhere in the engine.
- Unlike the v1.6.0 `customer_email` precedent (documentation-only, code deferred), this release ships the code change in the same commit as the doc update — there is no working behavior to preserve during a grace period, so accept-and-ignore is effective for every sender immediately.

**v1.7.0 — errata: §5 attribution ladder + example value fixes** (documentation-only; no code/schema change beyond what PRO-1524's follow-up batch also shipped — see below). PRO-1524:
- **§5 "Matching steps" corrected to match the actual code** — two stale claims fixed: (1) the lookback window was documented as "the last 7 days" but the code (`DEFAULT_MATCH_WINDOW_DAYS`) has been **30 days** since 2026-06-30 (tenant-overridable via `tenant_settings.attribution_match_window_days`); (2) step 3 was mislabeled as a `session_id`-gated `browse_events` lookup — the actual 3rd mechanism is an email **`click`** lookup (`customer_email`/`email_events`), unconditional on `session_id`. The former 4th-priority `browse_events`/`smaily_rec_id` fallback (already documented as permanently dormant in the v1.7.0 entry above) is removed in this same batch (`lib/engine/attribution/match-purchase-to-rec.ts`, migration `0081` — drops `browse_events.smaily_rec_id`); the ladder is now the 3 mechanisms that actually run, plus `control_purchase` / `assisted_open` on no match.
- **§6 request-body example (`"smaily_rec_id": "rec_abc123"`) and the identical §5 example fixed to a format-valid UUID** — the placeholder value would fail both routes' actual `z.string().uuid()` validation.

**v1.7.0 — errata: dead `session_id` field-reference note + URL-parameters example correction** (documentation-only; small internal-only code cleanup alongside — no wire/schema change). PRO-1544:
- **§5 `session_id` field-reference row corrected** — was described as "used for retroactive attribution"; the browse-event/`session_id` matching step it fed was already removed in PRO-1524, so it has been accept-and-ignore (stored on the order, not read by the matcher) since then. The internal-only `ProcessOrderInput.session_id` field that carried it into the now-removed matching step is deleted accordingly (`lib/engine/attribution/types.ts`, and its two callers); the wire field is unchanged — orders ingest still accepts and stores `session_id` on the order.
- **"URL parameters (on campaign links)" example corrected** — showed a stale `utm_campaign=welcome_series` param (removed from `lib/sync/url-builder.ts` before this contract was written) and was missing the real `utm_medium=email` param. Example now matches `buildPersonalizedProductLink()`'s actual output. Also brought the `smaily_rec` placeholder value in line with the same-day §5/§6 fix above (`rec_abc123` → the format-valid UUID `3fa85f64-5717-4562-b3fc-2c963f66afa6`) — the actual field is a `recommendations.rec_id` UUID, and the example should read consistently with every other `smaily_rec_id`/`smaily_rec` example in this document.

**v1.8.0** (2026-07-30) — **§5 return signals: `items[].returned_at` documented, two optional reason fields added, full refunds derived engine-side**. MINOR bump per the [Versioning](#versioning) rule (new optional fields; backward-compatible — nothing existing changes shape). PRO-1597, Erkki-approved 2026-07-30, research `docs/RESEARCH_return_reason_ingest.md`:
- **`items[].returned_at` is now documented** (§5 field reference + the new "Return signals" block). It is not new — the route has accepted it since the first release and it appeared only inside a GDPR-export example — but no sender has ever sent it, so every `returned_at` in production was NULL and its four consumers (180-day same-SKU suppression, the fit-anxiety learner, the `not_returned` trigger anchors) were inert.
- **Two new optional line fields: `return_reason_standardised`** (7-value engine-owned enum: `size_small` / `size_large` / `not_as_pictured` / `defect` / `wrong_item` / `changed_mind` / `other`) **and `return_reason_raw`** (verbatim platform string, ≤500 chars, diagnostic-only, merchant/system note only — never the buyer-written `customerNote`). Stored on `order_items` (migration `0082`, both nullable). **No engine consumer exists yet** — the fashion return-recovery family is separate later work; this release is contract + storage so the signal can start accumulating.
- **Never-reject rule, normative**: an unrecognised `return_reason_standardised` is stored as `other` with the original string preserved in `return_reason_raw` — it does not fail the item, the order, or produce an `errors[]` entry. The wire type is a plain string, not a closed enum, precisely so widening the vocabulary later is an engine deploy with no plugin release. Senders must not validate against a closed copy of the list. An over-long `return_reason_raw` is truncated, not refused.
- **Engine-side full-refund derivation (behavior change, no wire change)**: an order arriving with `status: "refunded"` now has **every line stamped returned** at the order's own `ordered_at`. Senders need not send `returned_at` for a full refund; an explicitly sent `returned_at` always wins. `ordered_at` rather than ingest time is deliberate — items are fully replaced on re-ingest, so a `now()` derivation would move the date on every re-sync and a full historical re-sync would restamp old refunds as fresh returns. Senders **SHOULD** still send per-line `returned_at` for **partial** returns, which order status cannot express (a WooCommerce partial refund does not change the order status at all).
- **Expectation-setting, stated in §5**: return signals are best-effort and **forward-only**. Many stores process refunds off-platform and will send nothing; WooCommerce has no returns concept in core. The engine degrades gracefully on NULL (no return recorded = kept). Both consumers are windowed (180 d / 365 d), so no historical backfill is expected — and an early low return rate should not be read as a real one.
- ⚠️ **Sender obligation**: because items are fully replaced on order re-ingest, a later sync of a returned order that omits these fields erases the return. Only the derived full-refund case is self-healing.

**v1.8.0 — errata: §5/§6 `smaily_rec_id` is a UUID, not a free string** (documentation-only; no code/schema change — the contract is being aligned to validation that has been live since the first release). PRO-1713, raised by the WooCommerce plugin team:
- **§5 and §6 field-reference rows retyped `string` → `UUID v4 string`.** Both routes have always validated the field with `z.string().uuid()` (`app/api/v1/ingest/orders/route.ts`, `app/api/v1/ingest/browse/route.ts`); the contract's plain-`string` typing invited senders to forward whatever the cookie held. Every example value in this document was already a well-formed UUID (fixed in the v1.7.0 errata batch), so the tables were the last place still saying otherwise.
- **§5 gains a normative note on the cost of a bad value** ([`smaily_rec_id` is UUID-validated](#rec-id-uuid-validation)): a malformed value fails per-order validation, so the **whole order** is rejected into `errors[]` and not written — the order's revenue is lost to the engine, not merely its attribution. This is the ordinary per-order rejection path (batch continues, `event_id` not registered, corrected retry writes normally), but it is worth stating because senders reasonably assume an optional attribution hint degrades to "no attribution" rather than "no order". **Sender rule stated explicitly**: omit the field when there is no cookie or the cookie is not a well-formed UUID; never send `""`.
- **§6 row notes that the deprecated field is still validated** — accept-and-ignore (v1.7.0) means the value is never persisted or consulted, but a malformed value still fails that event's validation. Omitting it is the only fully safe option.
- No semantics change and no code change: this errata documents enforced reality. Widening the validation was considered and **not** done — the field is a `recommendations.rec_id` lookup key, and a loose type would only move the failure to a silent no-match.

**v1.8.0 — errata: §2's `403 tenant_inactive` now actually happens** (documentation-only on the wire — the response shape is unchanged from what this contract has always specified; the engine side gained the tenant state that can produce it). PRO-1690:
- **The documented response was, until now, unreachable.** No engine code path emitted `tenant_inactive` and `tenants` had no status column: deactivating a tenant meant revoking its API keys one at a time. The engine now has an operator suspension state, so the response is real.
- **Scope stated explicitly in §2**: it applies to every API-key-authenticated endpoint (§2–§14), because enforcement sits at the shared authentication step rather than per route. The body is byte-identical everywhere.
- **No new error code, field or status.** Suspension reasons (billing vs. abuse) are an engine/merchant-console distinction and are deliberately NOT exposed on the wire — a sender's correct reaction is the same either way.
- **Sender obligation restated**: `403 tenant_inactive` is non-retryable, like `401`. A plugin that backs off and retries will simply keep failing.

**v1.8.1** (2026-08-06) — **§2's `403 tenant_inactive` extends to purged/offboarded tenants**. PATCH bump per the [Versioning](#versioning) rule (no new endpoint, no new field, no shape change — a fix to *which engine states* emit a response this contract already specifies). PRO-1820, Erkki-approved 2026-08-06:
- **The gate read only one of the two deactivation stamps.** A GDPR-purged (offboarded) tenant's API key kept authenticating, so a plugin that outlived its purge re-created customer and order rows inside a tombstoned tenant — observed in production on 2026-08-04 for two tenants purged on 2026-07-30. Both key-resolution paths (per-connection keys and the legacy single-key fallback) now carry the tombstone, and the shared authentication step refuses it.
- **Deliberately the SAME response, not a new one.** A purged tenant answers byte-identically to a suspended one, `"tenant_status": "suspended"` literal included. That field is a fixed string, never a state discriminator — senders must not branch on it. The plugin contract gains no state: a sender's correct reaction (stop sending, surface an admin notice, do not retry) is already the documented one.
- **Nothing to implement plugin-side.** A plugin that already handles `403 tenant_inactive` per the §2 sender rule is correct as-is. The semantics are informational: unlike suspension, a purge is permanent — the credentials cannot be revived by any key, setup token or regenerate flow (PRO-1820 closed those mints on 2026-08-05), and data sent after the purge is rejected at the gate and never stored.

**v1.8.2** (2026-10-02) — **§6/§7: one customer per visitor token**. PATCH bump per the [Versioning](#versioning) rule (no new endpoint, no new field, no shape change — a fix to *which rows* the engine binds). PRO-3649, Erkki's decision 2026-10-02:
- **The gap.** A campaign or other link can set the visitor-token cookie. The identity merge bound the token's browsing history — retroactively too — to whoever logged in next on that device, and the browse ingest did the same whenever an event carrying the token resolved a customer. One person's browsing could land on another customer's profile, and a shared link could push invented browsing onto someone else.
- **The rule.** Once a visitor token is bound to a customer, the engine never binds that token's browsing (past or future) to a different customer. The first binding wins; no age limit. An unbound token binds as before; the same customer with their own token behaves as before.
- **What still binds.** The merge's `anon_session_id` and the browse event's own `customer_email` / `external_id` resolution are unchanged — only the token-based binding is refused.
- **Nothing to implement sender-side.** Request and response shapes are unchanged; a refused token binding reports `0` in the existing counts, not an error.

**v1.8.2 — clarification: §13 cannot switch real sends on** (no new endpoint, field, status or shape). PRO-3705, Erkki's decision 2026-10-02:
- **The rule.** For the pilot, consent for engine-sent emails is the merchant's written yes plus a Smaily operator switching real sends on in the engine admin. A §13 row with `enabled: true` + `test_mode: false` is stored with `test_mode: true` unless the trigger already sends to real customers; the rest of the row is stored as sent and the response stays `200 {ok, upserted}`.
- **What the plugin sees.** §12 returns the stored state, so after such a save the row reads `test_mode: true`. The plugin's UI should show the §12 state, not its own request.

**v1.8.3** (2026-10-05) — **§3/§3b: catalog sync lifecycle**. PATCH bump per the [Versioning](#versioning) rule (no new endpoint, no new field, no shape change — the contract now states the sync pattern the plugins already follow). PRO-3740, Erkki's decision 2026-10-04:
- **The gap.** §3 called a periodic full re-sync "the reconciler" and §3b said to keep sending the full catalog "on your normal cadence". The intended pattern is different, and the WooCommerce plugin already follows it; the Magento connector drops its extra nightly full re-sync (PRO-1968).
- **The rule ([catalog sync lifecycle](#catalog-sync-lifecycle)).** A full import at setup; after that only changes (product save, stock change, archive, delete); a full import by hand whenever the merchant starts one. No scheduled full re-sync. A change re-sends the whole row, because the UPSERT clears an optional field that the request omits (only `tags` is merged) — this was already the engine's behavior, now stated.
- **What the engine reconciles itself.** Every engine-derived value (`recommendable`, lexicon tags, AI-sweep tags, popularity) is re-derived engine-side from stored rows when the engine's own rules change. A plugin full import is required only for a contract change that says so, a sender-side mapping fix, or after lost change events.
- **Known gap, stated honestly.** A lost change event (or a lost delete event, which a full re-sync never healed either) stays wrong until the product changes again or the merchant runs a full import; the engine cannot detect it today. Senders SHOULD queue change events durably with retries and show failures to the merchant.
- **What the plugin does.** Nothing new if it already works this way. A sender that keeps a scheduled full re-sync is not wrong, but it can drop it.

**v1.9.0** (2026-10-05) — **§15 `POST /api/v1/recommendations/customer`: storefront recommendations**. MINOR bump per the [Versioning](#versioning) rule (new endpoint; backward-compatible — nothing existing changes shape). PRO-3781, Erkki's decisions 2026-10-05, design `docs/RFC_storefront_recommendations.md` §10b (spike PRO-3698):
- **The endpoint.** `POST` with `{customer_external_id, limit?}` — the shopper is named by the store's own customer id, never an email. The answer is the products already in the customer's Smaily contact fields, same order and language, with live price and stock. Unknown, holdout, objected-to-profiling and unsubscribed customers all get the same `{"slots": []}`.
- **Storefront links.** `<product url>?smaily_rec=<rec_id>&smaily_ctx=storefront` — no `utm_*`, no `smaily_vt`. This replaces the RFC draft's `smaily_src=store`, which the engine would never have seen.
- **Channel of a purchase.** Derived, no new field: a cookie match whose order carries `smaily_rec_ctx: "storefront"` is a storefront credit (no email campaign, no email click time); everything else is email. §5's `smaily_rec_ctx` is now read for this.
- **Every email link carries `smaily_ctx`.** Email slots without an intent now carry `smaily_ctx=email` (before: no context). Contacts get the new links as their slots are next synced.
- **Context cookie rule.** A landing with `smaily_rec` sets `smaily_rec_ctx` to the URL's `smaily_ctx`, or clears it when the URL has none ([cookie names](#cookie-names-plugin-side-management)).
- **What the plugin does.** To show recommendations: call §15 server-side and render the slots. For correct credit: apply the context cookie rule, and forward `smaily_rec_ctx` on every order that carries `smaily_rec_id`. A plugin that does neither keeps working; its storefront sales then count as email.

**v1.9.1** (2026-10-05) — **§1: `recommendations_preview` and `recommendations_issue` deprecated**. PATCH bump per the [Versioning](#versioning) rule (wording only — no new endpoint, no new field, no shape change; the setup response still carries both keys). PRO-3793, Erkki's decision 2026-10-05:
- **The gap.** The §1 endpoints map lists two keys whose routes the engine retired on 2026-07-13 (PRO-1295). They never served real recommendations, and a call now answers `404`.
- **The rule.** Plugins must not call `recommendations_preview` or `recommendations_issue`. Contract 2.0 removes both keys from the setup response.
- **What the plugin does.** Nothing, if it never calls them. Storefront recommendations use `recommendations_customer` (§15).

**v1.10.0** (2026-10-06) — **§15: a returning guest shopper is named by the visitor token**. MINOR bump per the [Versioning](#versioning) rule (new optional field; backward-compatible — a v1.9.x request is still valid and answers the same). PRO-3834, Erkki's decision 2026-10-06:
- **The gap.** §15 named the shopper only by the store's customer id. At the pilot store nearly every purchase is a guest checkout, so the call answered empty for almost every shopper.
- **The field.** `smaily_visitor_token` — the visitor token from the store's visitor-token cookie. `customer_external_id` is no longer required on its own: a request carries at least one of the two; with neither, the answer is `400 validation_failed` as before.
- **Which identifier wins.** When both are sent, `customer_external_id` wins and the token is ignored entirely, also when the customer id finds nobody: on a shared browser the cookie can belong to someone else.
- **Same answer rules.** A token names the customer the engine issued it for, in this tenant, for 90 days after issue. That customer gets the same slots the customer id gives, under the same empty-answer rules. An expired, other-tenant or unknown token gets the same `{"slots": []}` as an unknown customer id, and the answer does not say which case applies.
- **Timeouts.** Ask from a background request after the page has loaded. The 1-second client timeout no longer applies; use at most 10 seconds (the engine ends a request after 10 seconds).
- **What the plugin does.** For a shopper without a store customer id who has given marketing consent, send `smaily_visitor_token` from the visitor-token cookie. A plugin that sends only `customer_external_id` keeps working unchanged.

**v1.11.0** (2026-10-06) — **§5: a store may create a visitor token at checkout; a guest buyer is recognised on a later visit**. MINOR bump per the [Versioning](#versioning) rule (a new accepted token format on an existing field; no new field, no shape change — a v1.10.x order is still valid and is written the same). PRO-3844, Erkki's decision 2026-10-06:
- **The gap.** v1.10.0 lets §15 name a guest shopper by a visitor token, but the engine issued tokens only on email links. A guest buyer who never clicked a Smaily email had no token, so the store could not show them their recommendations.
- **The format.** `vs_` + exactly 22 characters `[A-Za-z0-9]`, created by the store from a secure random source. Engine tokens keep `vt_`.
- **Where it travels.** On the order's existing `smaily_visitor_token` field ([§5](#store-created-visitor-token)). Create and send it only for a shopper who gave marketing consent. A server-set cookie is recommended (Safari deletes script-set cookies after 7 days); an existing visitor-token cookie is kept, not replaced.
- **What the engine does.** It binds the token to the order's customer: an unknown `vs_` token is created, a token of the same customer is renewed, a token of another customer does not move (one-customer-per-token rule), and the order is written in every case. A customer who objected to profiling is not bound. An invalid `vs_` value is ignored, not rejected.
- **Lifetime.** A token carried on an order lives 365 days from the latest order that carries it. A `vt_` token never carried on an order keeps 90 days from issue.
- **§15.** Unchanged in shape. The token now also names a guest buyer; the customer id still wins when both are sent; the empty-answer rules are unchanged.
- **What the plugin does.** At checkout, for a guest shopper who gave marketing consent and has no visitor-token cookie: create a `vs_` token, set it in the visitor-token cookie (server-set), and send it on the order's `smaily_visitor_token`. On later visits, send the cookie value to §15 as in v1.10.0. A plugin that does none of this keeps working unchanged.

**v1.12.0** (2026-10-06) — **§3c `POST /api/v1/ingest/catalog/manifest`: nightly catalog manifest**. MINOR bump per the [Versioning](#versioning) rule (new endpoint; backward-compatible — nothing existing changes shape). PRO-3763, Erkki's decisions 2026-10-06:
- **The gap.** Since v1.8.3 a sender sends the full catalog once and only changes after that. A lost stock-change or delete event stayed wrong, so an out-of-stock or deleted product could stay in recommendations.
- **The endpoint.** Once a night, the store's complete product list in one request: `{"products":[{"sku","in_stock"}]}`, 0–50,000 items, nothing else. Setup-exchange `endpoints` map gains `ingest_catalog_manifest` (§1).
- **What the engine does.** A product missing from the list is tombstoned exactly as §3b; a stock difference takes the list's value (no back-in-stock email for a restock found this way); a product the engine does not have is counted and reported to the engine's operator as "full import needed".
- **Truncation guard.** An empty list, or one that would remove more than 20% of the live catalog, removes nothing; stock corrections still apply and the operator is warned.
- **One list per store.** The list covers the whole store catalog, however many connections feed the tenant.
- **Lifecycle wording.** §3 "no delete-by-absence" and §3b "the only delete signal" now name the manifest as the one place absence counts; the "known gap" paragraph now says what the manifest heals and what still needs a full import.
- **What the plugin does.** Send the manifest once a night from the store's full product list. A plugin that does not send it keeps working; lost events then stay unhealed as before.

**v1.12.1** (2026-10-15) — **§5: a later sync of the same order keeps the attribution fields it omits**. PATCH bump per the [Versioning](#versioning) rule (wording only — no new endpoint, no new field, no shape change; the contract now states what the engine already does). PRO-4051:
- **The gap.** §5 said that items are fully replaced on re-ingest, but not what happens to the order's attribution fields. A sender that sends an order first and its visit identity later could not know whether a sync without the fields erases them.
- **The rule ([attribution fields on a later sync](#attribution-fields-on-resync)).** An omitted `smaily_rec_id`, `smaily_rec_ctx`, `smaily_visitor_token` or `session_id` keeps the stored value; a sent value replaces it. `null` on any of the four rejects the order. `""` rejects the order on `smaily_rec_id` and replaces the stored value on the other three.
- **Correction.** The `smaily_rec_id` sender rule said that `null` is always safe. It is not: `null` has always rejected the order. Omit the field instead.
- **Credit timing.** Purchase credit is computed once per order, by the first attribution run after the order is written. A field that arrives later is stored and binds a visitor token, but it does not change the credit already computed.
- **What the plugin does.** Omit an attribution field that has no value; never send it as `null`. A plugin that already omits empty fields changes nothing.

**v1.13.0** (2026-10-17) — **§8: the export's retention block lists every kind of data the privacy policy lists; visitor-token and attribution-timing corrections**. MINOR bump per the [Versioning](#versioning) rule (new fields in an existing response; backward-compatible — no key is renamed or removed, no request changes). PRO-4108, PRO-4088:
- **The gap (§8).** `export_metadata.data_retention_policy` listed four kinds of data; the privacy policy §4 lists twelve. A shopper's data export said less about retention than the policy.
- **The block ([§8](#8-get-apiv1customeremailexport)).** Ten keys are added: `replaced_recommendations`, `decision_log`, `visitor_tokens`, `rec_attribution`, `customer_engagement_history`, `trigger_candidates`, `edge_weights_log`, `ingest_event_log`, `customers`, `catalog`. `browse_events`, `email_events` and `recommendations` keep their values; `recommendations` is now explicitly the period for a recommendation that was not replaced. The `orders` value changes from `indefinite` to `indefinite: kept as long as the merchant uses the service, or until deleted on request` — the same meaning, stated in full. No retention period changes.
- **Token formats (Base context).** An engine email-link token is `vt_` + exactly 10 characters `[a-z0-9]` (the contract said 8–12); a store-created token is `vs_` + exactly 22 characters `[A-Za-z0-9]` (unchanged). Connectors treat every token as an opaque string of up to 64 characters. The examples now show a valid `vt_` token.
- **Token lifetime (§15).** One rule replaces two paragraphs that disagreed: an email-link token is valid for 90 days from issue; a token carried on an order is valid for 365 days from the latest order of its customer that carried it, and is never shortened. The engine behaviour is unchanged since v1.11.0.
- **Attribution timing (§5).** The `process-order-attributions` run is every 15 minutes, not ~every 30 minutes.
- **What the plugin does.** Nothing is required. A plugin that shows the export's retention block shows the new keys; a plugin that checks token length accepts up to 64 characters.

**v1.14.0** (2026-10-19) — **Retention periods as long as competitors keep data, with a purpose for each**. MINOR bump per the [Versioning](#versioning) rule (a new field in an existing response; backward-compatible — no key is renamed or removed). PRO-4116, Erkki's decision 2026-10-19:
- **The ask.** Shopify asked that data be kept at least as long as competitors keep it, and that each period state its purpose.
- **Periods.** Browse events (identified and anonymous) and email events: **14 months from the event** (were 90 and 365 days). Visitor tokens: **14 months from their last activity** — the token's creation, or the latest order that carried it (were 90 days from issue, or 365 days from the latest order). Recommendations, attribution and engagement history: 730 days, unchanged. Customers, orders and catalog: while the store uses the service, unchanged. The other periods are unchanged.
- **The block ([§8](#8-get-apiv1customeremailexport)).** `data_retention_policy` carries the new values for `browse_events`, `email_events` and `visitor_tokens`. A new sibling object `export_metadata.data_retention_purpose` has the same keys and states each period's purpose.
- **Token lifetime ([§5](#store-created-visitor-token), [§15](#15-post-apiv1recommendationscustomer)).** One lifetime for every token: 14 months from its last activity. A token issued or renewed before this version keeps its expiry until its next activity (an order that carries it) moves it; the engine does not extend existing tokens.
- **Store deletion ([§16](#16-post-apiv1storeuninstall)).** New endpoint `POST /api/v1/store/uninstall`, endpoint map key `store_uninstall`. It revokes the calling connection's key; when that was the store's last active connection, the engine stops using the store and deletes all of its data within 30 days of the request (normally the next night). The response says which of the two happened, and `delete_by` gives the deadline.
- **What the plugin does.** Call `POST /api/v1/store/uninstall` when the shop admin uninstalls the plugin or app, then delete the stored API key. Nothing else is required. A plugin that shows the export's retention block can show the purpose next to each period.

**v1.15.0** (2026-10-22) — **One setup place for every automated email**. MINOR bump per the [Versioning](#versioning) rule (new endpoints, new optional request fields, new response keys; backward-compatible — a v1.14.0 request is still valid and answers the same). PRO-4166, Erkki's decisions 2026-10-19 and 2026-10-21 (INT-12):
- **Availability.** Published 2026-10-22, before the engine builds it (Erkki's decision). Until each part is built, the engine answers `404` for §17–§20 and omits the new §11 fields and §12 `setup`; §13 keeps the v1.14.0 rules. A connector treats `404` or a missing field as "not available yet" and keeps its current behaviour. The setup-exchange endpoint map returns each new key only once its endpoint is live.
- **The gap.** Each plugin had its own list: engine emails bound to a workflow id, and its own welcome, first-order, abandoned-cart and follow-up settings. No plugin could set subject, sender check or design, and the three plugins showed different states.
- **§11 catalogue.** With `include=engine_less`, the list also has `welcome`, `first_order`, `abandoned_cart` and `follow_up`. Every entry gains `engine_backed`, `started_by`, `go_live`, `editable_settings[]` (subject, preheader, timing, pause between emails, daily cap, test addresses — each with its limits) and `engine_rules[]` (comparison group, suppression, spacing, automatic stop, overdue limit, real-send switch, ramp, discount — read-only labels). Every email has `go_live: "store"`. The example recipe now shows the live `rec_rep_*` recipe (documentation only).
- **§12 config read.** New top-level `setup[]`: one item per catalogue email with `setup_state` (`off`, `set_up`, `test`, `live`, `stopped`), `sender_state`, `sender_tested_at`, `design_state`, `go_live_blockers`, `live_since`, `daily_limit`, `last_test_send_at` and current `settings`.
- **§13 config write.** Engine-less keys are accepted. `automation_map`, `test_mode` and `test_emails` may be omitted (the stored value is kept). Optional `settings` for plugin-editable settings. **`test_mode: false` never switches real sends on**, for any email — it is stored as in v1.14.0 (the pilot consent rule now describes only this legacy field).
- **§17 setup link** (new). `POST /api/v1/automations/{key}/setup-link` returns a one-time URL to the engine-hosted setup page for the API key's store, valid 5 minutes. Endpoint map key `automations_setup_link`.
- **§18 brand kit** (new). `PUT` / `GET /api/v1/store/branding`: store name, URL, postal address, logo, colours, font, footer, sender name and address, language, each with its source. Endpoint map key `store_branding`.
- **§19 test send** (new). `POST /api/v1/automations/{key}/test-send` sends one email to one address the store types, with real products and no customer's name or email. Smaily's acceptance is the sender check. Endpoint map key `automations_test_send`.
- **§20 go-live** (new). `POST /api/v1/automations/{key}/go-live` lets the store switch real sends on (after confirmation, with no blocker) and off for one email, and records who switched it. Engine-backed emails keep the ramp 10 → 25 → 50 per day and the automatic stop; a stop needs an operator's review before the store can switch real sends on again. This replaces the pilot rule that a Smaily operator switches real sends on after the store's written yes (v1.8.2); the operator switch stays for a plugin without the go-live button. Endpoint map key `automations_go_live`.
- **Not in the contract.** What runs an email (a hand-built Smaily workflow today) and which editor the setup page embeds. The engine stores the design as HTML; no Smaily template holds it.
- **What the plugin does.** To use the setup page: ask for `include=engine_less`, render `editable_settings` and `engine_rules`, show `setup[]` state, open §17 links in a new tab, send the brand kit (§18), call §19 for a test email and §20 to go live, omit `test_mode` and `test_emails` from §13, and run engine-less emails by the §12 state and settings. **A plugin that does none of this, and still sends a workflow id, keeps working unchanged.**

**v1.16.0** (2026-10-22) — **A plugin starts Campaign Intelligence for a shop with no store**. MINOR bump per the [Versioning](#versioning) rule (a new endpoint; backward-compatible). INT-134, Erkki's decision 2026-10-21:
- **Availability.** Built; switched off until the terms text is published (INT-2): the engine answers `404 not_found` until then. A connector treats `404` as "not available yet" and keeps its current behaviour.
- **The gap.** A new store needed the operator: create the store, enter the Smaily login, mint a setup link, send it to the store. The plugin already held the Smaily login.
- **§21 start** (new). `POST /api/v1/onboarding/start` with the shop address and the Smaily API login. The engine checks the login with one Smaily call; a refused login (`401 smaily_login_refused`) or a package without API access (`403 smaily_paid_package_required`, Smaily code 227) stops the start. An account with a store gets `409 store_exists` and the console link. Otherwise a one-time start link, 5 minutes.
- **Start window.** Terms, industry (self-service packs only), shop address, console email. One submit creates the store, the editor account, the encrypted Smaily login and the terms record, starts the campaign statistics sync, and sends the setup token to the plugin's window (`postMessage`, type `smaily_intelligence_setup`, target origin = `opener_origin`, by default the shop's origin). No Smaily contact is written before the operator activates the store.
- **Window origin.** Optional `opener_origin` in the start request: the shop's origin (default), `https://admin.shopify.com`, or an app host the engine lists. Any other value: `400 opener_origin_not_allowed`.
- **Deleted store.** A store that is purged or whose deletion was requested (§16) no longer holds its Smaily account: a new start creates a new store.
- **Base context.** Connect works only with a paid Smaily package.
- **Authentication.** §21 is the second unauthenticated endpoint, after the setup exchange (§1).
- **What the plugin does.** To use the start: call §21 from the shop backend, open `start_url` in a new window, listen for the message, run the §1 exchange with its token. **A plugin that does none of this keeps working unchanged.**

**v1.17.0** (2026-10-22) — **One design place for every email; the engine runs only its own emails**. MINOR bump per the [Versioning](#versioning) rule (two new endpoints; the parts that leave were published in v1.15.0 under a "Not live yet" note and never served, so no live behaviour changes). INT-11, Erkki's decision 2026-10-22:
- **Availability.** §22 and §23 are "Not live yet": the engine answers `404` until each is built. Pushing to Smaily waits on Smaily's automations API extension, which brings workflow switch-on and update and access for a v1 account; until then `smaily_state` is `not_available`. Contact fields are Smaily merge tags in the template HTML.
- **The change.** v1.15.0 let the engine hold the plugin's own emails (welcome, first order, abandoned cart, follow-up): their settings, their state, and a rule that the plugin reads the engine's state before each run. Erkki decided to keep it simple: when such an email is sent stays in the plugin. The engine runs and configures only its own emails, and is the one design place for every email.
- **Removed (never live).** §11 `include=engine_less`, the four engine-less entries, the settings `abandoned_cutoff_minutes` and `follow_up_delay_days`, the engine rule `store_plugin_runs`, `started_by: "store_plugin"`. §12 `include` and engine-less `setup[]` items. §13 engine-less keys (refused again as `"tundmatu trigger"`, as in v1.14.0), the `settings` field, and the rule "run engine-less emails by the §12 state". The engine-less parts of §17, §19 and §20.
- **Kept.** §11 `go_live`, `editable_settings`, `engine_rules` (and `engine_backed`, `started_by` with one value each); §12 `setup[]` for catalogue emails; §13 optional `automation_map`, `test_mode`, `test_emails` and the rule that `test_mode: false` never starts real sends; §17 setup link, §18 brand kit, §19 test send, §20 go-live — for catalogue emails.
- **§22 design link** (new). `POST /api/v1/emails/{email_key}/design-link` returns a one-time URL to the engine design page for any email: a catalogue key, or a `plugin.*` / `transactional.*` key the engine has a starter for. Same rules as §17. On the page the store edits subject, sender and design, and sends a test email under the §19 rules, for every email. Subject and preheader are part of the design. Recommendation blocks are allowed in plugin and transactional emails; the order-confirmation starter has one. The engine keeps only the current design; Smaily template revisions keep the history. Endpoint map key `emails_design_link`.
- **§23 design read-back** (new). `GET /api/v1/emails/designs`: per email key the design state, subject, sender, version and the Smaily template and workflow ids. Endpoint map key `emails_designs`.
- **§19 test send.** The endpoint stays for catalogue emails. The design page (§22) offers the same test send for plugin and transactional emails; their test sends count toward the same limit and are the same sender check.
- **What the plugin does.** Keep its own emails, their settings and their sending. Stop any work on the engine-less parts of v1.15.0. Offer "Edit design" on every email (§22) and use the Smaily ids from §23 when they are present. A test of a plugin or transactional email is sent from the design page, not through §19. **A plugin that does none of this keeps working unchanged.**
- 2026-10-22: §11–§13, §17, §18, §22 and §23 are live.

**v1.18.0** (2026-10-23) — **§16 disconnect pauses, never deletes; test send and switch-on without waiting; embedded design page; a workflow id for every plugin email**. MINOR bump per the [Versioning](#versioning) rule (new optional fields and a new permission; the changed parts of §19, §20, §22 Embedding and the §23 additions are not live yet; §16 is the one live behaviour change, and a plugin that calls it still gets `200`). INT-145, INT-151, INT-150, INT-147, INT-146, Erkki's decisions and answers 2026-10-23:
- **§16 disconnect.** Connectors call it on uninstall or disconnect. It revokes the calling connection's key and deletes nothing. When the store has had a connection and none is active any more, the store is paused — no recommendations are issued, nothing is written to its Smaily account, no engine automated email is sent; its data stays and its Smaily contacts keep the `rec_*` fields of the last sync — until a new connection (setup exchange or operator key) ends the pause by itself. The response is `200` with `connection_removed` or the new `store_paused`; `deletion_scheduled`, `deletion_requested_at` and `delete_by` leave. The engine writes an audit row (`tenant.disconnect`) and shows the operator a notice. A store's data is deleted when the merchant closes the Smaily account: today an operator starts it by hand, later Smaily's subscription service sends the signal. §8 and §21 say the same.
- **§19 test send.** A button, not a condition for go-live; a refused test stops nothing. The test goes through the email's Smaily workflow: the typed address joins the store's Smaily list as a test contact, and the test shows the workflow's design until the Smaily push is live. Smaily records the test contact's events like any contact's; the test send and the reactions to that test email do not count toward the automatic stop, the same person's reactions to real emails do. The engine keeps no copy of the test address. New `not_ready` reason `workflow_not_active`. `sender_refused` is information only.
- **§20 go-live.** Blockers: `no_workflow`, `no_design`, `design_check_failed`, `missing_subject`, `stopped_needs_review`, `operator_switch_required`. No test send or sender blocker; a workflow that is not active is not a blocker. The ramp 10 → 25 → 50 per day counts from the first real send after the switch-on (`live_since` is the switch-on), so an email that waits for its workflow still starts at 10 a day. An automatic or operator stop holds until a Smaily operator reviews it (`automation.stop_reviewed`); the store's switch does not end it. A test send and the reactions to that test email do not count toward the stop rules; the same person's reactions to real emails do.
- **§11 and §12.** The `real_send_switch` and `ramp` labels follow §20. New field `notice` (`starts_when_sender_verified`): the email is enabled and its Smaily workflow is not active; it starts by itself once the workflow is active. `no_test_send`, `sender_unverified`, `sender_address_not_allowed`, `sender_records_invalid` and the stop reason `sender_check_failed` leave; `sender_state` and `sender_tested_at` are informational.
- **§22 embedding.** The design page may be framed by the origins that §21 `opener_origin` accepts — the origin of the connection's `plugin_info.site_url`, `https://admin.shopify.com` and the app hosts the engine lists (`frame-ancestors`) — and posts `{type: "smaily_intelligence_design", event: "saved" | "closed", email_key, version?}` to its parent, with those origins as the only targets. §17 setup links stay top-level.
- **§23.** `smaily_workflow_id` for every email kind, order and shipping confirmations included; new `smaily_subdomain` (the account that holds the ids) and `created_by` (`engine` / `other`). The plugin starts plugin and transactional emails with the workflow id, on that account only, and shows its edit button next to an automation only for `created_by: "engine"`. Creating a Smaily automation from the plugin is not in this version.
- **What the plugin does.** Call §16 on uninstall and on disconnect; treat `store_paused` like `connection_removed`, and do not tell the shop admin that the store's data will be deleted. Do not require a test send before go-live; tell the shop admin that a test address joins the Smaily list; show §12 `notice`. Use §23 workflow ids with `smaily_subdomain`, for transactional emails too. Optionally frame the design page and listen for its messages. **A plugin that does none of this keeps working unchanged.**
- 2026-10-23: §16 (disconnect and pause), the §11 labels and the §12 blocker list are live. §12 `notice`, §19, §20, §22 Embedding and the §23 additions are not live yet.

### Appendix F: Migration notes

**N-6 — `discount_price` → `compare_price` is a semantic inversion, not a literal copy.**
The legacy `discount_price` was the *lower* (on-sale) price; `compare_price` is the *higher* (pre-sale, "was") price. A literal `compare_price ← discount_price` copy therefore produces `compare_price < price` for genuinely discounted rows, which the new logic reads as **"no sale"** (sale exists only when `compare_price > price`). A simple value-match verification does **not** catch this — the copy succeeds technically while the meaning is wrong.

Correct migration paths:
- **Preferred:** re-ingest from the plugin, which sends the correct `price` + `compare_price` directly.
- **Alternatively:** a transform that places the old *regular* price into `compare_price` (not the discounted price), so `compare_price > price` holds for actually-discounted items.

(The W3 backfill, migration `0027`, did the literal copy by design — acceptable there because the data was test-only and 0 rows carried `discount_price`; production data arrives fresh from the plugin.)

---

**End of document**
