# Smaily Recommendation Engine — Multi-Platform Roadmap

**Versioon**: 1.0
**Avaldatud**: 2026-05-19
**Owner**: Erkki
**Strateegia**: Variant C — jagatud npm-pakk browse-tracking jaoks + platvormi-spetsiifilised plugin'id

---

## 1. Kõrge-tasemeline ülevaade

```
                ┌──────────────────────────────────────────────┐
                │  RECENGINE_API_CONTRACT.md v1.x              │
                │  (platvormi-agnostiline REST API)            │
                └──┬──────────────────┬─────────────────────┬──┘
                   │                  │                     │
                   │                  │                     │
          ┌────────▼────────┐ ┌───────▼────────┐  ┌────────▼─────────┐
          │ smaily-connect  │ │ smaily-shopify │  │ smaily-magento   │
          │ (WordPress/Woo) │ │      -app      │  │ (TBD, võib-olla) │
          │   PHP plugin    │ │  Node.js app   │  │                  │
          └────────┬────────┘ └───────┬────────┘  └────────┬─────────┘
                   │                  │                     │
                   └──────────────────┴─────────────────────┘
                                      │
                              ┌───────▼─────────┐
                              │ @smaily/        │
                              │ recengine-      │
                              │ client (npm)    │
                              │ Browse tracking │
                              │ Cookies         │
                              │ URL capture     │
                              │ Identity merge  │
                              │ (frontend)      │
                              └─────────────────┘
```

**Jagatud:**
- `@smaily/recengine-client` — TypeScript-kirjutatud, kompileerib ES2017+ JS-ile, töötab kõigis browser'ites + Node 18+
- `RECENGINE_API_CONTRACT.md` — autoritatiivne API-leping kõikide plugin'ide jaoks

**Plugin-spetsiifiline:**
- Backend hooks/webhooks (catalog/customers/orders ingestion)
- Admin UI (Settings + Wizard)
- GDPR API integration (WP Privacy / Shopify GDPR webhooks / Magento Privacy)
- Marketplace KK (WP.org / Shopify App Store / Magento Marketplace)

---

## 2. Mailstone 1 — WooCommerce-plugin (käimasolev)

**Status**: Faas 1 sub-PR 5.A in progress, Faas 2-4 ees.
**Sihtkuupäev**: ~6-8 nädalat (Faas 1 lõpp + Faas 2 + Faas 3 + Faas 4 + pilootklient go-live).

### Mis muutub praeguses plaanis Variant C tõttu:

#### 2.1. Sub-PR 5.A — ei muutu

Bootstrap lazy-getters + Settings\Credentials. Pole browse-tracking koodi. Las Code lõpetab nagu plaanitud.

#### 2.2. Faas 3 sub-PR 6 — muutub väike

Mu PROJECT_PLAN.md §5.1 ütleb:
> `public/js/beacon.js` — client-side tracker:
> - Detekteerib page-type'i (product, category, search, cart)
> - Saadab event'e WP REST endpoint'ile
> - jne

**Uus juhis Code-le Faas 3 alguses:**

`public/js/beacon.js` ei ole originaalkirjutamine — see on wrapper, mis impordib `@smaily/recengine-client` npm-paketi'st brovse-loogika ja konfigureerib selle WP-spetsiifilise context'iga (cookie nimed setup-config'st, WP REST endpoint URL).

NB: Mailstone 2 (npm-paketi eraldamine) toimub pärast WooCommerce'i pilootkliendi stabiliseerimist. Faas 3-s kirjuta beacon.js kood otse plugin'i sisse, aga disainib see **ekstraheeritav'na**:

- Kogu loogika **ühte klassi** `RecEngineClient` (mitte global helpers)
- **Konfiguratsioon konstruktor-injectiooni kaudu** (cookie nimed, endpoint URL — mitte hardcode)
- **Mitte ühtki WordPress-spetsiifilist viidet klassi sees** (mitte `wp.ajax`, mitte `window.ajaxurl`, mitte WP-i nonces)
- WP-spetsiifiline kood (nonce, REST URL) jääb **väikese wrapper-failina** plugin'i sees, kasutab `RecEngineClient`-i

See on ettevalmistus Mailstone 2-le — kui me Mailstone 2-s eraldame, ekstraheerime ainult `RecEngineClient` klassi. WP-wrapper jääb plugin'i sisse.

#### 2.3. Konkreetne failistruktuur Faas 3 sub-PR 6-s

```
public/js/
├── beacon.js              # WP-wrapper, ~50 rida, kasutab RecEngineClient
└── lib/
    └── rec-engine-client.js   # Tulevane npm-paketi sisu, ~400 rida
```

**`beacon.js` näide:**

```javascript
import { RecEngineClient } from './lib/rec-engine-client.js';

(function() {
  const config = window.SmailyRec || {};
  if (!config.config) return;

  const client = new RecEngineClient({
    beaconUrl: config.beacon_url,                    // WP REST endpoint
    cookieNames: {
      visitor: config.config.tracking_cookie_name,
      session: config.config.session_cookie_name,
      recId:   config.config.rec_id_cookie_name,
      context: config.config.context_cookie_name,
    },
    sessionTtlDays: config.config.session_ttl_days,
    batchWindowMs: 30_000,
    customerEmail: config.customer_email,
    customerExternalId: config.user_id,
  });

  // Page-type detection (WP-specific helpers — see is_product() jne)
  const event = detectEvent(config.page);
  if (event) {
    client.track(event);
  }
})();

function detectEvent(pageInfo) {
  // ... WP-spetsiifiline detect
}
```

**`lib/rec-engine-client.js`** (TypeScript-allikas → kompileeritud JS):

```typescript
export class RecEngineClient {
  constructor(config: RecEngineClientConfig) { ... }
  track(event: TrackingEvent): void { ... }
  flush(): Promise<void> { ... }
  captureUrlParams(): void { ... }
  mergeIdentity(email: string, reason: MergeReason): Promise<void> { ... }
  // ... kõik kuni-platvormi-agnostilised meetodid
}
```

**Code-le juhis**: kirjuta `lib/rec-engine-client.js` **TypeScript'is algusest peale** (`lib/rec-engine-client.ts`), Vite kompileerib JS-iks bundle-aega. See on mailstone 2-le ettevalmistus — npm-pakk on niikuinii TypeScript.

#### 2.4. Code'ile praegune juhis

**Tagasiside Code'ile** (pärast sub-PR 5.A review'd, või järgmise sub-PR-i alguses):

> Strateegiline uuendus: ehitame `@smaily/recengine-client` jagatud npm-paketi peale WooCommerce'i pilootkliendi stabiliseerimist. Shopify-plugin järgneb. Praegused tagajärjed:
>
> - **Faas 3 sub-PR 6 (Beacon)**: kirjuta `public/js/lib/rec-engine-client.ts` TypeScript-is, **WordPress-vaba** (mitte ühtki `wp.*` või `window.ajaxurl` selle klassi sees). WP-spetsiifiline kood `public/js/beacon.js`-is, mis kasutab `RecEngineClient`-i. Detail-skeem ROADMAP.md §2.3-s.
> - **Faas 2 build-setup (sub-PR 1, Vite config)**: lisame TypeScript-toetus `lib/`-kausta jaoks. Vite ei kompileeri ainult React-bundle'i, vaid ka `public/js/lib/*.ts` → `public/js/lib/*.js`. Build-skript: `npm run build:beacon` lisaks `npm run build:admin`-le.
> - **Mitte muuta Faas 1 ega Sub-PR 5.A-d.** Browse-tracking koodi pole praeguses sub-PR-is, niiet need otsused tulevad mängu hiljem.
>
> Vt ROADMAP.md täielik plaan.

---

## 3. Mailstone 2 — npm-paketi `@smaily/recengine-client` eraldamine

**Status**: TBD, alustab pärast WooCommerce-pilootkliendi 2-4 nädalat live'i.
**Sihtkuupäev**: ~10-12 nädalat alates praegust (eeldades Faas 1-4 + pilootklient + stabiliseerimine).

### Eeldused:
- WooCommerce-plugin on WP.org marketplace'is published (või vähemalt GitHub Release-na BETA-faasi piloodile saadetud)
- Pilootkliendil pole kriitilisi bugid rakenduses 2-4 nädalat
- RECENGINE_API_CONTRACT.md v1.0 või v1.1 on stabiilne (mootori-tiim ei kavanda breaking muudatusi)

### Mis tehakse:

#### 3.1. Repository setup

Uus repo: `sendsmaily/recengine-client-js` (või `smailyrec/client-js`, sõltuvalt Smaily-tiimi otsusest).

```
recengine-client-js/
├── package.json
├── tsconfig.json
├── tsup.config.ts             # Build: ESM + CJS + types
├── README.md
├── LICENSE
├── .github/
│   └── workflows/
│       ├── ci.yml             # lint + test + build
│       └── publish.yml        # npm publish on git tag v*
├── src/
│   ├── index.ts               # public API exports
│   ├── client.ts              # RecEngineClient class
│   ├── cookies.ts             # cookie management
│   ├── url-capture.ts         # URL-param capture
│   ├── identity.ts            # identity-merge helpers
│   ├── batching.ts            # 30s batch window
│   ├── transport.ts           # sendBeacon + fetch fallback
│   ├── types.ts               # TypeScript types
│   └── utils/
│       ├── uuid.ts
│       └── consent.ts         # WP Consent API + Cookiebot/Complianz/CookieYes detect
├── tests/
│   ├── client.test.ts
│   ├── cookies.test.ts
│   ├── url-capture.test.ts
│   └── identity.test.ts
└── examples/
    ├── vanilla-html/          # plain HTML demo
    ├── wordpress/             # WP-wrapper näide
    └── shopify/               # Shopify-wrapper näide
```

#### 3.2. Public API kontur (esialgne)

```typescript
// @smaily/recengine-client
export interface RecEngineClientConfig {
  /** WP REST endpoint või Shopify proxy URL, kuhu beacon-requestid saadetakse */
  beaconUrl: string;

  /** Cookie nimed (mootori setup-config'st) */
  cookieNames: {
    visitor: string;   // smaily_rec_uid
    session: string;   // smaily_anon_sid
    recId:   string;   // smaily_rec_id
    context: string;   // smaily_rec_ctx
  };

  /** Sessioon TTL päevades (vaikimisi 30) */
  sessionTtlDays?: number;

  /** Batch-window millisekundites (vaikimisi 30000) */
  batchWindowMs?: number;

  /** Identifeeritud kasutaja info (kui sisselogitud) */
  customerEmail?: string | null;
  customerExternalId?: string | null;

  /** Consent check funktsioon (platvorm pakub) */
  consentChecker?: () => boolean;

  /** Logger (vaikimisi console) */
  logger?: { log: (...args: any[]) => void; warn: (...args: any[]) => void };
}

export type EventType =
  | 'product_view'
  | 'category_view'
  | 'search'
  | 'cart_add'
  | 'cart_remove'
  | 'checkout_start'
  | 'checkout_complete'
  | 'wishlist_add';

export interface TrackingEvent {
  event_type: EventType;
  sku?: string;
  category_path?: string;
  search_query?: string;
  dwell_seconds?: number;
}

export type MergeReason =
  | 'user_logged_in'
  | 'email_provided_at_checkout'
  | 'email_link_click';

export class RecEngineClient {
  constructor(config: RecEngineClientConfig);

  /** Tracki üksik event (lükatakse batch-buffer'isse) */
  track(event: TrackingEvent): void;

  /** Flush buffer kohe (mitte oodata batch-window'i lõppu) */
  flush(): Promise<void>;

  /** Loe URL-st smaily_vt/smaily_rec/smaily_ctx parameetrid + seada cookies */
  captureUrlParams(): void;

  /** Identity merge: anon → known */
  mergeIdentity(email: string, reason: MergeReason): Promise<void>;

  /** Cleanup (call enne page unload) */
  destroy(): void;
}
```

#### 3.3. Migration WooCommerce-plugin'ist npm-pakk'i

Sammud:

1. **Extract** `wp-plugin/public/js/lib/rec-engine-client.ts` → uue repo `src/`-kausta
2. **Rename** klassinime, kui kollide (näit kui plugin'is on `RecEngineClient` + utility classes, paneme nad eraldi failidesse)
3. **Test-cases'i migratsioon** plugin-side tests → npm-pakk tests (Jest või Vitest)
4. **Build-setup** `tsup`-iga (ESM + CJS + TypeScript types)
5. **First version** `v1.0.0` npm-i publish (PEALE RECENGINE_API_CONTRACT.md versiooni-paika)
6. **Update WP-plugin** versioon `2.1.0`:
   - `package.json`-i lisada `"@smaily/recengine-client": "^1.0.0"` dependency
   - `public/js/lib/`-kaust kustutada (kood nüüd npm-paketi'st)
   - `public/js/beacon.js` impordib npm-paketi'st (Vite bundle'b)
   - Re-test plugin pilootkliendi vastu (regression)
   - Release GitHub Release-na (BETA → upstream-merge järel WP.org marketplace'i)

#### 3.4. Versioonipoliitika

`@smaily/recengine-client` **semantic versioning**:
- **MAJOR**: breaking changes public API-s (näit `track()` signatuur muutub)
- **MINOR**: uued meetodid, optional parameters lisanduvad
- **PATCH**: bug-fixes

**Plugin'i dependency-pin**:
- WordPress-plugin `package.json` pin'ib `"@smaily/recengine-client": "^1.0.0"` (lubab MINOR + PATCH automaatselt)
- Shopify-app sama
- MAJOR muudatuse korral plugin update'itakse käsitsi

**Synkroonsus mootori-API-ga**:
- `RECENGINE_API_CONTRACT.md` v1.x ↔ `@smaily/recengine-client` v1.x
- API breaking change → mõlemad MAJOR bump
- API additive change → mõlemad MINOR bump
- Iga npm-paketi README.md viitab konkreetsele API contract versioonile

#### 3.5. Distribution

- **npm registry**: `@smaily/recengine-client` (eeldab Smaily-orga npm-is)
- **Alternatiiv**: `@erkki/recengine-client` (Erkki personal scope, pärast Smaily-tiimi üleandmist re-publish'itakse `@smaily/` alla)
- **GitHub Packages** kui alternative npm-i — pole vaja avalikku npm-i, paketi ainult auth'iga ligipääsetav. Kasulik, kui Smaily ei taha praegu avalikult publish'da.

**Mu eelistus**: avalik npm `@smaily/`-scope alla, Smaily-tiimi nõusolekul. Kui Smaily ei ole valmis, läheme `@erkki/` ajutiseks.

---

## 4. Mailstone 3 — Shopify-app

**Status**: TBD, alustab pärast Mailstone 2 lõppu.
**Sihtkuupäev**: ~16-20 nädalat alates praegust.

### Eeldused:
- `@smaily/recengine-client` v1.0+ published ja stabiilne
- RECENGINE_API_CONTRACT.md v1.x lukus
- Pilootklient WooCommerce'is on näidanud, et kontseptsioon töötab (rec-engine ML annab kasulikke soovitusi)

### Põhilised erinevused WordPress'ist:

| Aspekt | WordPress (Woo) | Shopify |
|--------|----------------|---------|
| Plugin-tüüp | PHP plugin | Node.js app (Express + Polaris UI) |
| Hosting | Klient hostib | Smaily hostib (Heroku/Vercel/Fly.io) või klient |
| Authentication | WP nonce + capabilities | OAuth 2.0 (Shopify Partners) |
| Catalog data | `WC_Product` PHP | Shopify Admin API (GraphQL/REST) |
| Customer data | `WP_User` + `wp_users` | Shopify Customer API |
| Order data | HPOS `wc_get_order()` | Shopify Order API |
| Events | WP hooks (`save_post_product`) | Shopify webhooks (`products/update`) |
| Settings UI | WP admin React-bundle | Shopify Polaris React App |
| Browse-tracking | `beacon.js` → WP REST proxy | `beacon.js` → Shopify App Proxy (server-side proxy sama mustriga) |
| GDPR | WP Privacy API hooks | Shopify GDPR webhooks (3 nõutud webhook'i) |
| Multilingual | WPML/Polylang/TranslatePress | Shopify Markets API |
| Marketplace | WP.org | Shopify App Store |
| App approval | PCP automaatne | Manual Shopify review (võib võtta 1-2 nädalat) |

#### 4.1. Repository setup

Uus repo: `sendsmaily/smaily-shopify-app` (või Erkki personal).

```
smaily-shopify-app/
├── package.json
├── shopify.app.toml          # Shopify CLI config
├── tsconfig.json
├── README.md
├── src/
│   ├── server/                # Backend (Node.js + Express + Shopify SDK)
│   │   ├── index.ts
│   │   ├── routes/
│   │   │   ├── setup.ts       # Setup-token exchange
│   │   │   ├── webhooks/
│   │   │   │   ├── products.ts
│   │   │   │   ├── customers.ts
│   │   │   │   ├── orders.ts
│   │   │   │   └── gdpr.ts    # 3 GDPR webhook'i
│   │   │   └── app-proxy/
│   │   │       └── beacon.ts  # Server-side beacon proxy
│   │   ├── lib/
│   │   │   ├── recengine-api.ts   # Wrapper @smaily/recengine-client server-side
│   │   │   ├── shopify-mapper.ts  # Shopify → API contract mapping
│   │   │   └── db.ts              # Tenant config storage (kus?)
│   │   └── jobs/
│   │       ├── catalog-sync.ts    # Background job (BullMQ vol sarnane)
│   │       └── backfill.ts
│   ├── admin/                 # Frontend Polaris UI (kui custom)
│   │   ├── App.tsx
│   │   ├── pages/
│   │   │   ├── Connection.tsx
│   │   │   ├── Subscribers.tsx
│   │   │   ├── ShopifySetup.tsx   # Shopify-spetsiifiline (vs WC)
│   │   │   ├── Recommendations.tsx
│   │   │   └── EventLog.tsx
│   │   └── components/
│   └── theme-extension/       # Theme App Extension (beacon.js install)
│       ├── blocks/
│       └── assets/
│           └── beacon.js      # @smaily/recengine-client wrapper Shopify-le
└── tests/
```

#### 4.2. Backend-arhitektuur

Erinevalt WP-plugin'ist, Shopify-app vajab **serverit** (Node.js host). Põhjused:
- Shopify webhooks pole iga WP-installi sees, vaid Shopify saadab HTTP POSTe Smaily-poolse hostingu peale
- App Proxy (browser → Shopify → Smaily-server → rec-engine) vajab serverpoolt
- OAuth-tokens hoitakse server-side

**Hosting otsus**: TBD. Variants:
- **Vercel** (serverless, lihtne setup, hea Shopify-app'idele)
- **Fly.io** (Docker-based, persistent connections, paindlik)
- **Heroku** (klassikaline, aga aeglustub)
- **Smaily oma infra** (kui Smaily-tiim võtab üle)

**Mu eelistus**: Vercel BETA-faasis, Smaily oma infra production'is.

#### 4.3. Tenant config storage

Erinevalt WP-st (kus iga klient hostib oma plugin'i), Shopify-app on **jagatud** — üks app, palju shop'e. Vaja tenant-config DB:

```
Tabelid:
- tenants (shop_id, smaily_tenant_id, api_key_encrypted, ...)
- shopify_oauth_tokens (shop_id, access_token, scopes, ...)
- sync_jobs (shop_id, job_type, status, cursor, ...)
```

**Database otsus**: Vercel Postgres / Supabase / PlanetScale. Mu kalle Supabase'le (sa juba kasutad Tuduaeg'is).

#### 4.4. App Proxy + beacon

Shopify Theme App Extension installib `beacon.js`-i theme'i. Beacon'i URL on Shopify App Proxy (`/apps/smaily/beacon`), mis Shopify forwardib `https://smaily-shopify-app.vercel.app/proxy/beacon` peale. Server-side proxy lisab Authorization header'i ja saadab `https://intelligence.smaily.com/api/v1/ingest/browse`.

`beacon.js` koodist tähtsam osa on **identne WP-versiooniga** — `@smaily/recengine-client` annab kogu loogika.

#### 4.5. GDPR webhookid

Shopify nõuab **3 mandatoorset GDPR webhook'i** kõikidele App Store-i app'idele:
1. `customers/data_request` → fetch + return customer data
2. `customers/redact` → delete customer data
3. `shop/redact` → delete kogu tenant'i data 48h pärast app-i deinstalli

Plugin peab vasta 48h jooksul (Shopify-i KK nõue). Kasutame samu API-endpoints rec-engine'iga (`GET /customer/{email}/export`, `DELETE /customer/{email}`).

#### 4.6. Shopify App Store submission

App ei lähe submission'i enne, kui:
- 3 GDPR webhook'i implementeeritud
- App Bridge UI Polaris-stiilis
- Privacy Policy URL
- App Listing screenshots (5+ pilti)
- Test'imisjuhend Shopify-review-tiimi jaoks

**Approval-aeg**: 1-2 nädalat.

---

## 5. Mailstone 4 — Magento (väljas tee'st)

**Status**: **Mitte plaanis v1-s.** Kui konkreetne klient küsib, hindame eraldi.

**Märkused tulevikku**:
- Magento on Adobe Commerce — enterprise-suunatud, väike SMB-osakond
- Magento Marketplace KK on kõige rangem kolme platvormi seas
- Magento PHP-arhitektuur on kompleksne — Dependency Injection, Plugins (interceptors), Observers, EAV-data-model
- Beacon: sama `@smaily/recengine-client` npm-pakk töötab — Magento-theme installib JS-i, server-side proxy on Magento module
- Cataloog-mapping: Magento-l on **konfigureeritavad atribuudid** (laius, värv, suurus), mis on rohkem nagu WooCommerce variations. Aga **bundle products** ja **grouped products** on Magento-spetsiifilised

**Eeldused, mille korral teeme**:
- WooCommerce + Shopify on stabiilsed (kokku >50 pilootklient)
- Konkreetne Smaily-klient küsib (mitte ennetav)
- Smaily-tiim võtab Magento-osa üle (Erkki + Claude-agendid pole skaleeritav 4-le platvormile)

---

## 6. Erkki orchestration — paralleelne töö

**Praegu sa juhid**:
- 1 mootori-vestlus (rec-engine ML/API)
- 1 plugin-vestlus (see, kus me oleme — meta-plan, spec, review)
- 1 Code-agent (terminal, WP-plugin)
- 1 prototyping-vestlus (UI iteratsioonid)

**Mailstone 2 jooksul**:
- +1 Code-agent (npm-paketi eraldamine ja test'imine)

**Mailstone 3 jooksul**:
- +1 spec-vestlus (Shopify-spetsiifiline arutelu, sarnaselt sellele, kus me oleme)
- +1 Code-agent (Shopify-app)

= **6 paralleelset konteksti** Shopify-faasis. See on piir, kus Erkki üksi käib raskeks.

### Mu soovitused orchestration'iks:

1. **Iga vestluse-pildi vahetus** (näit Code-agent → spec-agent → tagasi Code-agent) on konteksti-vahetus, mis võtab energiat. Püüa vahetada vestlust **harva** — koonda küsimused, anna agentile batch korraga.
2. **Spec-vestlus (see, kus oleme)** on "ülemus-konsultant" — siin teeme arhitektuurseid otsuseid, mis lähevad teistesse vestlustesse. Hoia seda vestlust käes igapäev. Teised vestlused on executors.
3. **Code-agendid annavad sulle iga sub-PR-i järel kokkuvõtte** (mu eelmised tagasiside-promptid). Sa paste'd kokkuvõtte siia, ma annan tagasiside. See on **iteratiivne mitte paralleelne** rütm.
4. **Shopify-faas peaks alustama alles, kui WooCommerce-plugin on vähemalt 2 nädalat ilma kriitiliste bugideta**. Mitte enne — sa unustasid muidu, mida WP-plugin teeb, kui Shopify-arutelu sind ära viib.
5. **Magento ootab** — ära käivita seda enne, kui Shopify-app on App Store'is approved ja >5 piloodi-klienti seda kasutavad.
