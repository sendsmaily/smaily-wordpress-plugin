# Smaily Recommendation Engine — API Contract v1.0

**Versioon**: 1.0.0
**Avaldatud**: 2026-05-19
**Status**: Stabiilne, plugin-implementatsiooni alus

Dokument konsolideerib varasema dialoogi (`RECENGINE_API_ANALYSIS.md` + `RECENGINE_API_ROUND2.md`) puhtaks REST API spetsifikatsiooniks. Platvormi-agnostiline - sama kontrakt sobib WooCommerce'i, Shopify'le, Magento'le, Presta'le, custom-poodidele ja Make-flow integrations'idele.

**WordPress-spetsiifilised märkused** (Action Scheduler, HPOS, hooks, REST-endpoint patterns) elavad eraldi dokumendis `PLUGIN_IMPLEMENTATION_WP.md`.

---

## Sisukord

1. [Baaskontekst](#baaskontekst)
2. [Autentimine](#autentimine)
3. [Versioonihaldus](#versioonihaldus)
4. [URL-namespace ja cookie nimed](#url-namespace-ja-cookie-nimed)
5. [Vea-käsitsemine](#vea-käsitsemine)
6. [Rate limiting](#rate-limiting)
7. [Endpoint-id](#endpoint-id)
   - [POST /api/setup/exchange](#1-post-apisetupexchange)
   - [GET /api/v1/ingest/ping](#2-get-apiv1ingestping)
   - [POST /api/v1/ingest/catalog](#3-post-apiv1ingestcatalog)
   - [POST /api/v1/ingest/customers](#4-post-apiv1ingestcustomers)
   - [POST /api/v1/ingest/orders](#5-post-apiv1ingestorders)
   - [POST /api/v1/ingest/browse](#6-post-apiv1ingestbrowse)
   - [POST /api/v1/identity/merge](#7-post-apiv1identitymerge)
   - [GET /api/v1/customer/{email}/export](#8-get-apiv1customeremailexport)
   - [DELETE /api/v1/customer/{email}](#9-delete-apiv1customeremail)
   - [POST /api/v1/customer/{email}/opt-out](#10-post-apiv1customeremailopt-out)

---

## Baaskontekst

**Base URL**: erineb deploy-keskkonnas, kättesaadav `engine_base_url` setup-response'is.

**Production base URL**: `https://intelligence.smaily.com`

> Mootor töötab oma produktsiooni-domeenil `https://intelligence.smaily.com`. Varasemad pilot-preview-deploy'd on aegunud (vana alias resolvib endiselt olemasolevate installide jaoks). Runtime base tuleb alati setup-response'i `engine_base_url` väljast, seega installid kohanduvad live-hostiga sõltumata sellest staatilisest default'ist; kõik URL-näited allpool kasutavad praegust produktsiooni-base'i.

**Path-prefiks**: KÕIK plugin-poolt mootorile saadetavad request'id kasutavad `/api` prefiksit, sh setup-exchange ise (`/api/setup/exchange`, mitte `/setup/exchange`). Spec v1.0 eelmine versioon dokumenteeris setup'i ilma `/api`-ta — see oli viga, mille sub-PR 3.1.2 paikkas. Engine'i tegelik route-tabel pakub kõike `/api/*` all. Tsentraliseeritud konstantide list elab plugin'i poolel `Smaily\Connect\Smaily\RecEngine\Client::PATH_*` peal.

**Content-Type**: kõik requests + responses kasutavad `application/json; charset=utf-8`.
- Mootor **alati** tagastab `Content-Type: application/json`, sealhulgas error-vastustes.
- Mootor **alati** tagastab `Cache-Control: no-store` autentitud endpoint'ide vastustes (API-key sisalduvad requests).

**Tähemark**: kõik request- ja response-stringid on UTF-8. JSON keys on lowercase + underscore (`first_name`, mitte `firstName`).

**Timezone**: kõik timestamp-väljad ISO 8601 formaadis UTC-ga (`2026-05-19T10:15:23Z`). Mootor konverteerib siseselt tenant-timezone'i, kui vaja.

**ID-formaadid**:
- Tenant IDs: UUID v4
- Customer IDs: UUID v4 (genereeritud mootori-side esimesest customer-ingestist)
- Recommendation IDs: UUID v4
- Visitor tokens: opaque string 8-12 sümbolit, prefiks `vt_`
- Order external_id: text, plugin-platform määratud
- SKU: text, max 64 sümbolit

---

## Autentimine

**Schema**: HTTP Bearer Token

```
Authorization: Bearer sk_<random_32_chars>
```

API-key on tenant-tasandi, omandatud `POST /api/setup/exchange` käigus. **API-key ei tohi kunagi olla client-side koodis** (JavaScript, mobile app bundle). Plugin-side server-proxy on kohustuslik browse-eventide jaoks.

**Setup endpoint** (`POST /api/setup/exchange`) on **autentimata** (vt allpool) - see on ainus endpoint, kus API-key ei ole vajalik.

**Vastus autentimise vea puhul**:
- `401 Unauthorized` kui Authorization header puudub või API-key on vale
- `401 Unauthorized` + `error: "api_key_revoked"` + `regenerate_url` kui API-key on revoke'itud admin UI's

```json
{
  "error": "api_key_revoked",
  "regenerate_url": "https://intelligence.smaily.com/setup/regenerate/{tenant_id}",
  "message": "Your API key was revoked. Click the regenerate URL to obtain a new one."
}
```

---

## Versioonihaldus

**Engine version** edastatakse iga response'i header'is:

```
X-Engine-Version: 1.0.0
```

**Versioneeringu reeglid** (Semantic Versioning 2.0):
- **MAJOR** (`2.0.0`): breaking changes API contract'is. Plugin peab uuendama enne uue major'iga töötamist.
- **MINOR** (`1.1.0`): uued endpoint'id või uued optional väljad. Backward-compatible.
- **PATCH** (`1.0.1`): bug-fixes, ei muuda contract'i.

**Plugin-side käitumine** mismatch'i puhul:
- Plugin teab oma `compatible_engine_version_range` (näit. `>=1.0.0,<2.0.0`)
- Iga response peale plugin kontrollib `X-Engine-Version` header'it
- Kui mismatch: **graceful degradation** - plugin jätkab tööd, kuvab admin notice "Engine version X.Y.Z is newer than this plugin supports"
- Plugin **ei keeldu töötamast** version-mismatch'i tõttu - data-loss on suurem risk kui compatibility-issue

**Setup-response sisaldab** `engine_version`-i, et plugin teaks juba install-ajal, mis versiooniga töötab.

---

## URL-namespace ja cookie nimed

### URL-parameetrid (kampaania linkidel)

Mootor renderdab Smaily kontakti väljadel olevad `product_url`-id koos järgnevate parameetritega:

```
https://shop.example.com/product/widget?
  utm_source=smaily&
  utm_campaign=welcome_series&
  smaily_vt=vt_8f3k2a&
  smaily_rec=rec_abc123&
  smaily_ctx=cart_abandoned
```

**Reserveeritud Smaily-prefiksiga parameetrid**:

| Parameter | Sisu | Kasutus |
|-----------|------|---------|
| `smaily_vt` | Visitor token (opaque, prefiks `vt_`) | Identity resolution (anonymous → customer_id) |
| `smaily_rec` | Recommendation ID (UUID) | Attribution: mis soovitus klikiti |
| `smaily_ctx` | Kontekst-string (`welcome`, `cart_abandoned`, `cross_sell`, jne) | Attribution: millises kontekstis klikiti |

**UTM-namespace** jääb **kliendi turundus-tööriistadele** (Google Analytics, ad-platforms). Mootor ei kasuta `utm_content` rec_id jaoks - see oleks GA-attribution-andmete rikkumine.

**Plugin-side** capture: kui plugin näeb URL-i koos `smaily_*` parameetritega, salvestab need cookie'desse (vt allpool) ja edastab edasiste API-calls'i kaudu mootorile.

### Cookie-nimed (plugin-side haldus)

Plugin haldab 4 cookie'd, nimed tulevad **mootori setup-response config'st** (võimaldab override'imist):

| Cookie | Default name | TTL | Sisu | SameSite/Secure |
|--------|--------------|-----|------|-----------------|
| Visitor token | `smaily_rec_uid` | 365d | URL-i `smaily_vt` väärtus | Lax / Secure |
| Anonymous session ID | `smaily_anon_sid` | 30d | UUID v4 (plugin genereerib esimesel visiidil) | Lax / Secure |
| Recommendation ID | `smaily_rec_id` | 30d | URL-i `smaily_rec` väärtus (last-touch) | Lax / Secure |
| Context | `smaily_rec_ctx` | 30d | URL-i `smaily_ctx` väärtus (last-touch) | Lax / Secure |

**HttpOnly** = `false` - cookies on JavaScript-le ligipääsetavad (beacon-proxy kasutab neid).
**Domain**: cookie domain on `auto` (kasutab `Domain=.example.com` mustrit, et töötaks www.example.com + example.com).

**Last-touch override'imine**: iga uue email-clicki puhul `smaily_rec_id` + `smaily_rec_ctx` cookies üle-kirjutatakse. Last-touch wins. **Mootor-side säilib esimese-touch info** `rec_attribution` tabelis (vt Faas 2.5 brief), aga **cookie ainult last-touch**.

---

## Vea-käsitsemine

### HTTP-staatused

| Kood | Tähendus | Plugin retry? |
|------|----------|---------------|
| 200 | OK, success | - |
| 201 | Created (resource created) | - |
| 204 | No Content (success, no body) | - |
| 400 | Bad Request - request body invalid | EI |
| 401 | Unauthorized - API-key vale või revoked | EI (kuva admin notice) |
| 403 | Forbidden - tenant deaktiveeritud või access denied | EI |
| 404 | Not Found - resource ei eksisteeri | EI |
| 409 | Conflict - idempotency conflict (rare) | EI |
| 429 | Too Many Requests - rate limit | JAH (exponential backoff, austa `Retry-After`) |
| 500 | Internal Server Error - mootor maas | JAH (exponential backoff, kuni 3 katset) |
| 502/503/504 | Bad Gateway / Service Unavailable / Gateway Timeout | JAH (sama mis 500) |

### Error response formaat

Iga error-response sisaldab JSON-i:

```json
{
  "error": "error_code_snake_case",
  "message": "Human-readable explanation",
  "details": {
    "field": "Optional context (validation errors)",
    "valid_values": ["array of valid values if applicable"]
  },
  "request_id": "req_uuid_v4",
  "timestamp": "2026-05-19T10:15:23Z"
}
```

`request_id` on debugging jaoks - kui plugin näeb vea, kuvame selle admin notice'is, et Erkki saaks teha tugipäringut viidatega request_id'iga.

### Validation-vigade näide

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
  "request_id": "req_8f3k2a-...",
  "timestamp": "2026-05-19T10:15:23Z"
}
```

**Plugin käitumine**: kui 400 võtab vastu, parse'i `details.errors` ja kuva admin notice'is. **Mitte retry** - bad request ei muutu retry'ga heaks.

---

## Rate limiting

**Default limit per tenant**:
- **Browse endpoint**: 500 req/sec
- **Kõik teised**: 100 req/sec
- **Setup exchange**: 10 req/min per IP

**429 Too Many Requests** response:

```
HTTP 429 Too Many Requests
Retry-After: 5
X-RateLimit-Limit: 500
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 2026-05-19T10:15:28Z

{
  "error": "rate_limit_exceeded",
  "message": "Rate limit exceeded. Retry after 5 seconds.",
  "retry_after_seconds": 5,
  "request_id": "req_...",
  "timestamp": "2026-05-19T10:15:23Z"
}
```

**Plugin käitumine**:
- Austa `Retry-After` header'it (sekundites)
- Exponential backoff: 1s, 2s, 4s, 8s, 16s (max 5 katset)
- 5 katse järel - logi viga, kuva admin notice, **ei kaota event'i** (jää local queue'sse)
- Browse-event'idele: **batch-mode aktiveerub automaatselt** 429 puhul (kogub 5s aknas, saadab kuni 100-ga batch'ina)

---

## Endpoint-id

### 1. POST /api/setup/exchange

Setup-token exchange. **Autentimata endpoint** (ainus).

**Use-case**: pärast tenant create'imist admin UI's, Erkki saab setup URL-i (näit. `https://intelligence.smaily.com/setup/abc123xyz`). Plugin'i seadetes klient kleebib URL-i, plugin extract'b token'i (`abc123xyz`) ja kutsub seda endpoint'i tehnilise config'i saamiseks.

**URL**: `POST /api/setup/exchange`

**Auth**: puudub

**Rate limit**: 10 req/min per IP

**Headers**:
```
Content-Type: application/json
User-Agent: <plugin-identifier>/<version>  (näit. "SmailyRecEngine-WooPlugin/0.1.0")
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

**Plugin info** salvestatakse audit-logis (`tenant_setup_tokens.used_from_plugin`). Aitab Erkkit teada, mis plugin-versiooniga klient ühines.

**Response 200 OK**:
```json
{
  "tenant_id": "550e8400-e29b-41d4-a716-446655440000",
  "tenant_name": "Erkki Pood",
  "api_key": "sk_8f3k2a4e1c4d8a9b2f7e3d1a6c8b9e0f",
  "engine_base_url": "https://intelligence.smaily.com",
  "engine_version": "1.0.0",
  "endpoints": {
    "ping":       "https://intelligence.smaily.com/api/v1/ingest/ping",
    "catalog":    "https://intelligence.smaily.com/api/v1/ingest/catalog",
    "customers":  "https://intelligence.smaily.com/api/v1/ingest/customers",
    "orders":     "https://intelligence.smaily.com/api/v1/ingest/orders",
    "browse":     "https://intelligence.smaily.com/api/v1/ingest/browse",
    "identity_merge": "https://intelligence.smaily.com/api/v1/identity/merge",
    "customer_export": "https://intelligence.smaily.com/api/v1/customer/{email}/export",
    "customer_delete": "https://intelligence.smaily.com/api/v1/customer/{email}",
    "customer_opt_out": "https://intelligence.smaily.com/api/v1/customer/{email}/opt-out"
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

**Response 410 Gone** (token expired või kasutatud):
```json
{
  "error": "setup_token_expired_or_used",
  "message": "This setup token has expired or has already been used. Ask the engine administrator to generate a new one.",
  "regenerate_url": "https://intelligence.smaily.com/admin/tenants/{tenant_id}/regenerate-setup-token",
  "request_id": "req_..."
}
```

**Response 404 Not Found** (token ei eksisteeri):
```json
{
  "error": "setup_token_not_found",
  "message": "Setup token not found. Verify the URL is correct.",
  "request_id": "req_..."
}
```

**Idempotency**: setup_token on **one-time use**. Esimene exchange märgib `used_at = NOW()`. Järgnev exchange tagastab 410. Plugin peab API-key turvaliselt salvestama esimesest exchange'st.

**Plugin-side**: pärast edukat exchange'i, plugin salvestab `api_key`, `engine_base_url` ja `config` oma WordPress options table'isse (krüpteeritult api_key jaoks, `wp_options` autoload=false).

---

### 2. GET /api/v1/ingest/ping

Health-check endpoint. Plugin "Test Connection" nupp Settings UI's kutsub seda.

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
  "tenant_id": "550e8400-...",
  "tenant_name": "Erkki Pood",
  "engine_version": "1.0.0",
  "tenant_status": "active",
  "endpoints_available": [
    "catalog", "customers", "orders", "browse",
    "identity_merge", "customer_export", "customer_delete", "customer_opt_out"
  ],
  "server_time": "2026-05-19T10:15:23Z"
}
```

**Response 401** kui API-key vale (vt autentimine).
**Response 403** kui tenant deaktiveeritud:
```json
{
  "error": "tenant_inactive",
  "message": "This tenant is currently deactivated. Contact engine administrator.",
  "tenant_status": "suspended"
}
```

**Rate limit**: 100 req/sec (default)

**Idempotency**: pole vaja (read-only endpoint).

---

### 3. POST /api/v1/ingest/catalog

Batch-upload tootekataloog. Mootor teeb UPSERT iga toote kohta (sama SKU = update, uus SKU = insert).

**URL**: `POST /api/v1/ingest/catalog`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, kuni 100 toodet per request

**Request body**:
```json
{
  "products": [
    {
      "sku": "ACA-DOG-3KG",
      "name": "Acana Adult Dog 3kg",
      "category_path": "food/dry",
      "price": 22.99,
      "compare_price": 25.99,
      "on_sale_until": "2026-06-01T00:00:00Z",
      "in_stock": true,
      "description": "Premium dry food for adult dogs",
      "image_url": "https://erkkipood.ee/wp-content/uploads/aca-dog-3kg.jpg",
      "product_url": "https://erkkipood.ee/product/acana-adult-dog-3kg/",
      "external_id": "12345",
      "tags": {
        "brand": "Acana",
        "category_path": "food/dry"
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

**Mitmekeelne variant** (`name`, `description`, `product_url` toetavad object-formaati):
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
      "raw_attributes": { ... }
    }
  ]
}
```

Mootor aktsepteerib mõlemat formaati - kontrollib field-tüüpi runtime'is. Single-language `string` = mootor wrap'b `{default: "..."}` siseselt.

**Väljade selgitus**:

| Väli | Tüüp | Kohustuslik | Selgitus |
|------|------|-------------|----------|
| `sku` | string (max 64) | JAH | Unikaalne ID toote jaoks |
| `name` | string \| {lang: string} | JAH | Toote nimi |
| `category_path` | string | JAH | Hierarhiline kategooria (`food/dry`, `accessories/leashes`) |
| `price` | number | JAH | Kliendi praegune hind (mitte regular_price) |
| `compare_price` | number | EI | Soodushinna puhul - mida maksaks ilma sooduseta |
| `on_sale_until` | ISO 8601 string | EI | Soodustuse lõpp (kui kohaldatav) |
| `in_stock` | boolean | JAH | Kas toodet on saadaval |
| `description` | string \| {lang: string} | EI | Lühikirjeldus (max 500 sümbolit) |
| `image_url` | string (URL) \| {lang: string} | EI | Toote pildi URL |
| `product_url` | string (URL) \| {lang: string} | JAH | Lehe URL |
| `external_id` | string | EI | Plugin-platform'i sisene ID (debug'imiseks) |
| `tags` | object | EI | Best-effort mapping (mootor kasutab kohe) |
| `raw_attributes` | object | EI | Toore platform-data (AI-mapping wizard'isse) |

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 47,
  "created": 12,
  "updated": 35,
  "skipped": 0,
  "errors": [],
  "unmapped_attributes": [
    "pa_unknown_thing",
    "meta_legacy_field"
  ],
  "request_id": "req_..."
}
```

`unmapped_attributes` loetleb `raw_attributes`-võtmed, mida mootor ei tundnud ära. Plugin saab WP-admin'is kuvada notice'i: "5 attribuuti pole mappitud - vaata mapping settings".

**Response 200 OK koos osaliste vigadega**:
```json
{
  "ok": true,
  "processed": 45,
  "created": 12,
  "updated": 33,
  "skipped": 2,
  "errors": [
    {
      "index": 23,
      "sku": "BAD-SKU",
      "error": "validation_failed",
      "field": "price",
      "message": "Price must be positive, got -5.99"
    },
    {
      "index": 31,
      "sku": "MISSING-NAME",
      "error": "validation_failed",
      "field": "name",
      "message": "Name is required"
    }
  ],
  "request_id": "req_..."
}
```

Mootor töötleb **partial success** - korrektsed rida'd lähevad sisse, vealised raporteeritakse. Plugin saab admin notice'is kuvada.

**Idempotency**: SKU on PRIMARY KEY. Sama `sku` 2× = teine kõne UPDATE'b. Plugin võib agressiivselt retry'da.

---

### 4. POST /api/v1/ingest/customers

Batch-upload kliendid. UPSERT by email.

**URL**: `POST /api/v1/ingest/customers`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, kuni 100 klienti per request

**Request body**:
```json
{
  "customers": [
    {
      "email": "mari@example.com",
      "first_name": "Mari",
      "last_name": "Tamm",
      "country": "EE",
      "language": "et",
      "phone": "+372...",
      "first_seen_at": "2026-01-15T10:30:00Z",
      "external_id": "67",
      "consent": {
        "marketing": true,
        "recommendations": true,
        "consent_at": "2026-01-15T10:30:00Z"
      }
    }
  ]
}
```

**Väljade selgitus**:

| Väli | Tüüp | Kohustuslik | Selgitus |
|------|------|-------------|----------|
| `email` | string (valid email) | JAH | Primaarne identifikaator |
| `first_name` | string | EI | |
| `last_name` | string | EI | |
| `country` | string (ISO 3166-1 alpha-2) | EI | Näit. "EE", "FI", "US" |
| `language` | string (ISO 639-1) | EI | Näit. "et", "en", "ru" - kasutame template-renderdamisel |
| `phone` | string | EI | |
| `first_seen_at` | ISO 8601 | EI | Registreerimise hetk (kui erinev customer-rea loomisest) |
| `external_id` | string | EI | Platform'i sisene user_id |
| `consent.marketing` | boolean | EI | GDPR consent marketing'ule |
| `consent.recommendations` | boolean | EI | GDPR consent rec-engine'ile |
| `consent.consent_at` | ISO 8601 | EI | Consent'i hetk |

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 30,
  "created": 5,
  "updated": 25,
  "skipped": 0,
  "errors": [],
  "request_id": "req_..."
}
```

**Idempotency**: email on UNIQUE. Sama email 2× = UPDATE.

---

### 5. POST /api/v1/ingest/orders

Batch-upload tellimused + tellimuste read. HPOS-aware payload.

**URL**: `POST /api/v1/ingest/orders`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, kuni 50 tellimust per request (orders + items koos võivad olla suured)

**Request body**:
```json
{
  "orders": [
    {
      "external_order_id": "WC-12345",
      "customer_email": "mari@example.com",
      "ordered_at": "2026-05-15T14:30:00Z",
      "total_amount": 67.50,
      "discount_amount": 5.00,
      "currency": "EUR",
      "status": "completed",
      "smaily_rec_id": "rec_abc123",
      "smaily_visitor_token": "vt_8f3k2a",
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

**Väljade selgitus**:

| Väli | Tüüp | Kohustuslik | Selgitus |
|------|------|-------------|----------|
| `external_order_id` | string | JAH | Plugin-platform'i tellimuse ID (UNIQUE per tenant) |
| `customer_email` | string | JAH | Klienti viidatakse email'iga (mitte external_id'iga) |
| `ordered_at` | ISO 8601 | JAH | Tellimuse esitamise hetk |
| `total_amount` | number | JAH | Kogu summa peale soodustusi |
| `discount_amount` | number | EI | Kogu soodustus (default 0) |
| `currency` | string (ISO 4217) | EI | Default "EUR" |
| `status` | enum | JAH | `completed`, `processing`, `cancelled`, `refunded` |
| `smaily_rec_id` | string | EI | Attribution: mis soovitus klikiti enne ostu (cookie'st) |
| `smaily_visitor_token` | string | EI | Attribution: visitor token (cookie'st) |
| `smaily_rec_ctx` | string | EI | Attribution: kontekst (cookie'st) |
| `session_id` | string | EI | Session ID - kasutame retroactive attribution'iks |
| `items[]` | array | JAH | Tellimuse read |
| `items[].sku` | string | JAH | |
| `items[].qty` | integer | JAH | Kogus |
| `items[].unit_price` | number | JAH | Hind ühe tükki kohta |
| `items[].line_total` | number | JAH | Rea kogusumma (qty × unit_price - discount) |
| `items[].discount_amount` | number | EI | Rea-spetsiifiline soodustus |

**Attribution flow** (mootor-side):

Mootor võtab order'i ja teeb attribution-matching'u järgnevalt:
1. Kui `smaily_rec_id` olemas → otsi `recommendations` tabelist, kontrolli kas customer match'b → INSERT `rec_attribution` (`attribution_type='direct'` või `'exact_later'` või `'indirect_*'` sõltuvalt SKU match'st ja ajavahest)
2. Muidu kui `smaily_visitor_token` olemas → lookup `visitor_tokens` → leia recent rec'e → match
3. Muidu kui `session_id` olemas → vaata `browse_events` viimase 7 päeva sees, kas seal oli rec_id-link → match
4. Kui mitte ühtegi → INSERT `rec_attribution` koos `attribution_type='control_purchase'`, outcome_score=0.0

Detailne attribution-loogika on `lib/engine/attribution/` mooduli vastutus (Faas 2.5 brief).

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 10,
  "created": 7,
  "updated": 3,
  "skipped": 0,
  "attribution_resolved": 6,
  "attribution_control": 4,
  "errors": [],
  "request_id": "req_..."
}
```

`attribution_resolved` = order'ites, kus suudasime matchida soovituse.
`attribution_control` = order'ites, mis arvati kontrollgrupi (no engine influence).

**Idempotency**: `external_order_id` + `tenant_id` on UNIQUE. Sama order 2× = UPDATE (items täielik asendus).

---

### 6. POST /api/v1/ingest/browse

Browse-event'ide batch. Kõige tihedam endpoint.

**URL**: `POST /api/v1/ingest/browse`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 500 req/sec (kõrgem kui teised), kuni 100 event'i per request

**Event-tüübid**:
- `product_view` - klient avas toote-lehte
- `category_view` - klient avas kategooria-lehte
- `search` - klient otsis
- `cart_add` - klient lisas korvi
- `cart_remove` - klient eemaldas korvist
- `checkout_start` - klient alustas check-outi
- `checkout_complete` - check-out lõpetatud (tellimus loodud) - **lisaks orders endpoint'ile**
- `wishlist_add` - klient lisas wishlist'i (kui platform toetab)

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
      "smaily_visitor_token": "vt_8f3k2a",
      "smaily_rec_id": "rec_abc123",
      "smaily_ctx": "cart_abandoned",
      "external_id": "67"
    }
  ]
}
```

**Väljade selgitus**:

| Väli | Tüüp | Kohustuslik | Selgitus |
|------|------|-------------|----------|
| `event_id` | UUID v4 | JAH | Plugin genereerib, dedupliceerimise jaoks |
| `session_id` | string | JAH | Plugin haldab session-cookie'd (`smaily_anon_sid`) |
| `event_type` | enum | JAH | Vt event-tüüpide list |
| `sku` | string | EI | Vajalik product_view, cart_add, cart_remove jaoks |
| `category_path` | string | EI | Vajalik category_view jaoks |
| `search_query` | string | EI | Vajalik search jaoks |
| `dwell_seconds` | integer | EI | Kaua lehel viibis (product_view jaoks) |
| `event_ts` | ISO 8601 | JAH | Eventi toimumise hetk |
| `source` | string | JAH | Constant: `plugin_woo`, `plugin_shopify`, `make`, `custom` |
| `customer_email` | string | EI | Identity hint (kui kasutaja on sisse logitud) |
| `smaily_visitor_token` | string | EI | Identity hint (cookie'st) |
| `smaily_rec_id` | string | EI | Attribution (cookie'st) |
| `smaily_ctx` | string | EI | Attribution (cookie'st) |
| `external_id` | string | EI | Platform user_id |

**Identity resolution flow** (mootor-side):

Iga eventi puhul mootor resolvitakse `customer_id` järgnevalt:
1. Kui `smaily_visitor_token` olemas → lookup `visitor_tokens` → resolve customer_id
2. Muidu kui `customer_email` olemas → lookup `customers` by email → resolve customer_id
3. Muidu kui `external_id` olemas → lookup `customers` by external_id → resolve customer_id
4. Muidu → INSERT browse_event koos `customer_id = NULL` (anonymous)

**Retroactive binding**: kui customer_id resolvitakse (step 1-3), mootor UPDATE'b kõik varasemad `browse_events` sama `session_id`-iga, kus `customer_id IS NULL`. Klient saab täieliku ajaloo isegi kui esimene click oli anonüümne.

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 23,
  "with_customer_match": 18,
  "anonymous": 5,
  "retroactive_bound": 3,
  "duplicates_skipped": 0,
  "errors": [],
  "request_id": "req_..."
}
```

`duplicates_skipped` = `event_id`-id, mis olid juba viimase 60 minuti sees salvestatud (idempotency).
`retroactive_bound` = anonüümseid event'eid, mis sai customer_id'ga seotud retroactively.

**Idempotency**: `event_id` UUID + `tenant_id` on UNIQUE 60-minutilise akna sees. Sama `event_id` 2× sees 60 min = teine ignoreeritakse. Pärast 60 min võib sama UUID korduda (väga ebatõenäoline, aga ei taha igavest indeksit).

**Browse-event'id EI OLE semantiliselt idempotent** - sama klient võib vaadata sama SKU 3 korda päevas, 3 erinevat event'i. Transport-level idempotency (`event_id`) kaitseb retry-duplikaatide eest.

---

### 7. POST /api/v1/identity/merge

Anonymous visitor → known customer manual merge. Plugin kutsub seda, kui klient sisse logib ja meil on cookie'd seotud anonüümse session'iga, mille tahame nüüd customer'iga siduda.

**URL**: `POST /api/v1/identity/merge`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Use-case**:
1. Mari saabub e-poodi `?smaily_vt=vt_xyz` lingilt → plugin set'ib cookie, alustab anonüümse session'i
2. Mari liigub paar lehte, plugin saadab browse_events koos `smaily_visitor_token`-iga - mootor resolvitakse customer_id (Mari) → events seotakse Mari'ga
3. **Kuid kui** plugin näeb, et Mari on **nüüd sisse loginud** (`is_user_logged_in()` true) - aga `customer_email` ei olnud varem teada - plugin kutsub `identity/merge` et säilitada **session-anon-history → known-customer** mapping eksplitsiitselt.

**Request body**:
```json
{
  "anon_session_id": "anon_sess_uuid_v4_from_cookie",
  "smaily_visitor_token": "vt_8f3k2a",
  "customer_email": "mari@example.com",
  "customer_external_id": "67",
  "merge_ts": "2026-05-19T10:15:23Z",
  "merge_reason": "user_logged_in"
}
```

**Väljade selgitus**:

| Väli | Tüüp | Kohustuslik | Selgitus |
|------|------|-------------|----------|
| `anon_session_id` | UUID | EI | Plugin-side anon-session cookie (`smaily_anon_sid`) |
| `smaily_visitor_token` | string | EI | Email-clickilt saadud token (`smaily_rec_uid` cookie) |
| `customer_email` | string | JAH | Teadlik kliendi-identiteet |
| `customer_external_id` | string | EI | Platform user_id |
| `merge_ts` | ISO 8601 | JAH | Merge'i hetk |
| `merge_reason` | enum | EI | `user_logged_in`, `email_provided_at_checkout`, `manual_admin` |

Vähemalt **üks** `anon_session_id` või `smaily_visitor_token` peab olema (muidu pole midagi merge'da).

**Response 200 OK**:
```json
{
  "ok": true,
  "customer_id": "550e8400-...",
  "merged": {
    "browse_events_updated": 12,
    "browse_events_already_bound": 3,
    "visitor_tokens_bound": 1,
    "session_history_days": 22
  },
  "request_id": "req_..."
}
```

`browse_events_updated` = anonüümseid events'eid, mille seota customer_id'iga.
`session_history_days` = kuipalju päevi tagasi ulatuvad bind'itud event'id (annab pildi, kui palju ajalugu klient sai).

**Response 404 Not Found**:
```json
{
  "error": "customer_not_found",
  "message": "No customer found with email mari@example.com. Send via POST /api/v1/ingest/customers first.",
  "request_id": "req_..."
}
```

**Idempotency**: sama merge 2× = no-op (event'id juba bind'itud, vastus näitab `browse_events_already_bound`).

---

### 8. GET /api/v1/customer/{email}/export

GDPR data export. Tagastab kõik kliendi-andmed mootor-poolt.

**URL**: `GET /api/v1/customer/{email}/export`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 req/min per tenant (privacy-relevant endpoint)

**URL-parameetrid**:
- `{email}` - URL-encoded email aadress

**Query parameters**:
- `format` - optional, `json` (default) või `ndjson` (large datasets)
- `since` - optional, ISO 8601 - filter events alates kuupäevast

**Response 200 OK** (`format=json`):
```json
{
  "export_metadata": {
    "exported_at": "2026-05-19T10:15:23Z",
    "tenant_id": "550e8400-...",
    "customer_email": "mari@example.com",
    "customer_id": "660f9500-...",
    "data_retention_policy": {
      "browse_events": "90 days",
      "email_events": "365 days",
      "recommendations": "730 days",
      "rec_attribution": "730 days",
      "orders": "indefinite"
    }
  },
  "customer": {
    "email": "mari@example.com",
    "first_name": "Mari",
    "last_name": "Tamm",
    "country": "EE",
    "language": "et",
    "first_seen_at": "2026-01-15T10:30:00Z",
    "cold_start_tier": 3,
    "engagement_state": "warm",
    "inferred_species": "dog",
    "consent": {
      "marketing": true,
      "recommendations": true,
      "consent_at": "2026-01-15T10:30:00Z"
    }
  },
  "orders": [
    {
      "external_order_id": "WC-12345",
      "ordered_at": "2026-05-15T14:30:00Z",
      "total_amount": 67.50,
      "items": [...]
    }
  ],
  "browse_events": [
    {
      "event_ts": "2026-05-19T10:15:23Z",
      "event_type": "product_view",
      "sku": "ACA-DOG-3KG",
      "dwell_seconds": 45
    }
  ],
  "email_events": [
    {
      "event_ts": "2026-05-18T09:00:00Z",
      "event_type": "click",
      "campaign_id": "welcome_series",
      "rec_id": "rec_abc123"
    }
  ],
  "recommendations": [
    {
      "rec_id": "rec_abc123",
      "issued_at": "2026-05-17T05:00:00Z",
      "sku": "ACA-DOG-3KG",
      "intent_type": "complement",
      "status": "closed_win",
      "outcome_score": 1.0
    }
  ],
  "rec_attribution": [
    {
      "rec_id": "rec_abc123",
      "order_id": "WC-12345",
      "attribution_type": "direct",
      "outcome_score": 1.0,
      "click_ts": "2026-05-18T09:05:00Z",
      "purchase_ts": "2026-05-18T09:12:00Z"
    }
  ]
}
```

**Response 404 Not Found** kui customer ei eksisteeri.

**Response 200 OK** koos tühja data'ga (kui customer existeerib, aga andmeid pole):
```json
{
  "export_metadata": { ... },
  "customer": { ... },
  "orders": [],
  "browse_events": [],
  "email_events": [],
  "recommendations": [],
  "rec_attribution": []
}
```

**Idempotency**: read-only, kõik export'id on identsed (sama data state'is).

**Audit log**: iga export logitakse `gdpr_audit_log` tabelisse - tenant_id, customer_email, action='export', performed_at, source (`'plugin'` või `'admin_ui'`).

---

### 9. DELETE /api/v1/customer/{email}

GDPR täielik andmete kustutamine.

**URL**: `DELETE /api/v1/customer/{email}`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 req/min per tenant

**URL-parameetrid**:
- `{email}` - URL-encoded email aadress

**Request body** (optional confirmation):
```json
{
  "confirm": true,
  "reason": "user_request"
}
```

`reason` võib olla: `user_request`, `admin_action`, `legal_obligation`, `account_closure`.

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
  "deleted_at": "2026-05-19T10:15:23Z",
  "request_id": "req_..."
}
```

`audit_log_id` viitab `gdpr_audit_log` real, mis salvestab kustutamise faktit (mitte sisu) - **kohustuslik GDPR-vastavuse jaoks** tõendina.

**Mida säilib pärast DELETE**:
- `gdpr_audit_log` rida (kuupäev + email + action, mitte sisu)
- Aggregated metrics `lift_metrics_daily` (anonymized, ei sisalda email/customer_id)

**Mida kustutatakse**:
- Kõik tabelid, mis viitavad customer_id'le (ON DELETE CASCADE)
- visitor_tokens (et hiljemate emaili-clickide puhul ei tunneks Mari'd ära)

**Response 404 Not Found** kui customer ei eksisteeri.

**Idempotency**: sama DELETE 2× = teine kõne 404 (customer juba kustutatud). Plugin peaks käsitlema 404'i kui edukat operatsiooni.

---

### 10. POST /api/v1/customer/{email}/opt-out

GDPR opt-out: andmed jäävad alles, aga klient ei lisata enam soovitustele.

**URL**: `POST /api/v1/customer/{email}/opt-out`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Use-case**: klient klikkis "Ära kasuta mu andmeid soovituste-süsteemis" linki kontaktivormis. Plugin kutsub endpoint'i. Mootor flag'b customer'i `opted_out=true` - kõik tulevased `issue-daily-recommendations` cron-jooksud jätavad selle kliendi vahele.

**URL-parameetrid**:
- `{email}` - URL-encoded email

**Request body**:
```json
{
  "opt_out": true,
  "reason": "user_preference",
  "opted_out_at": "2026-05-19T10:15:23Z"
}
```

**Reverse** (opt-in tagasi):
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
  "next_recommendations_cron": "2026-05-20T05:00:00Z",
  "request_id": "req_..."
}
```

`next_recommendations_cron` ütleb, millal järgmine cron jätab kliendi vahele (kasulik audit'i jaoks).

**Response 404 Not Found** kui customer ei eksisteeri.

**Idempotency**: sama opt-out 2× = teine kõne `previous_status: true, opt_out_status: true` (no-op aga edukas).

**Audit log**: lokitakse `gdpr_audit_log`-i (action='opt_out' või 'opt_in').

---

## Lisad

### Lisa A: Pagination (tulevikus)

V1.0-s **ei toeta pagination'i** - kõik batch-endpoints on **single-request, max 100 (50 orders) per call**. Plugin peab ise jaotama suuremaid batch'e.

V2.0-s plaanis cursor-based pagination GET-endpoint'idele (vaja näit. `GET /api/v1/customers?cursor=...` admin UI's).

### Lisa B: Webhooks (tulevikus)

V1.0-s **ei toeta webhook-back-channel'it**. Plugin küsib data'd pull'ides.

V2.0-s plaanis registreeritavad webhook'id:
```
POST /api/v1/webhooks
{ "url": "https://shop.com/webhook", "events": ["sync.completed", "recs.updated"] }
```

JSON-schema (reserveeritud V2-le):
```json
{
  "webhook_id": "wh_uuid",
  "event_type": "sync.completed",
  "tenant_id": "tnt_uuid",
  "occurred_at": "2026-05-19T10:15:23Z",
  "data": { ... }
}
```

HMAC-allkiri `X-Smaily-Signature` header'is.

### Lisa C: Multi-tenant single-WP (tulevikus)

V1.0-s **1 plugin = 1 tenant**. Plugin'i WordPress options table salvestab ainult ühe `api_key` + `tenant_id`.

V2.0-s plaanis multi-tenant single-WP (agentuurid + WPMU + WC Multistore), aga API-design (setup-token + tenant-bound config) toetab seda juba praegu.

### Lisa D: Curl-näited

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

### Lisa E: Changelog

**v1.0.0** (2026-05-19) - Esimene stabiilne versioon.
- 10 endpoint'i dokumenteeritud
- URL-namespace + cookie nimed kinnitatud
- Plugin-agendi feedback integreeritud (event_id idempotency, compare_price, multilingual, GDPR endpoint-set, smaily_rec parameter, rate limit + Retry-After)
- Attribution-flow dokumenteeritud (sees `/api/v1/ingest/orders`)
- Retroactive session binding dokumenteeritud (sees `/api/v1/ingest/browse`)
- GDPR endpoint-set (export, delete, opt-out)

---

**Lõpp dokumendist**

Erkki Markus järgmine samm:
1. Konsolideeri see API_CONTRACT.md v1.0 Code'i-le antavasse paketti
2. Eraldi: PLUGIN_IMPLEMENTATION_WP.md (WP-spetsiifilised märkused) v1.0 - vajab kirjutamist (Faas 2.5 osana)
3. Anda mõlemad dokumendid plugin-agentile Etapp 3 ehitamiseks
4. Code'ile Faas 2.5 brief'i andmiseks need on autoritatiivne contract
