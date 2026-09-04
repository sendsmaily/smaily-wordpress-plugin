# Smaily Connect Plus — Üleandmise pakett Claude Code'ile

Selle paketi sees on:

1. **`smaily-connect-plus-wizard.jsx`** — UX-prototüüp, kõik 6 wizard-step'i + Settings + Event Log placeholderid
2. **`PLUGIN.md`** — plugin'i tehnili-funktsionaalne spec (v0.2)
3. **`RECENGINE_API_ANALYSIS.md`** — rec-engine API-vastused, voor 1
4. **`RECENGINE_API_ROUND2.md`** — rec-engine API-vastused, voor 2 (final consensus)
5. **`SUGGESTION.md`** — *käesolev fail*, üleandmise-juhend

Prototüüp ise on **UX-mustand** — kogu visuaal, state-loogika, animatsioonid ja stsenaariumid on demonstreeritavad — aga kood **ei sobi sellisena** production-buildi.

---

## Sisukord

1. [Strateegilised otsused, mis on **lukus**](#1-strateegilised-otsused-mis-on-lukus)
2. [Stiilide migratsioon Tailwindiks](#2-stiilide-migratsioon-tailwindiks)
3. [Failistruktuur ja arhitektuur](#3-failistruktuur-ja-arhitektuur)
4. [State management](#4-state-management)
5. [Mida prototüüp **ei kata**](#5-mida-prototüüp-ei-kata)
6. [Mis on prototüübis **juba õigesti**](#6-mis-on-prototüübis-juba-õigesti)
7. [Etapp-haaval implementatsiooni-plaan](#7-etapp-haaval-implementatsiooni-plaan)
8. [Lukus TODO-d failis](#8-lukus-todo-d-failis)
9. [Mida prototüübis **ignoreerida**](#9-mida-prototüübis-ignoreerida)

---

## 1. Strateegilised otsused, mis on **lukus**

Need otsused on tehtud kahe ringi spec-discussion'i järel (vt `RECENGINE_API_ANALYSIS.md` + `RECENGINE_API_ROUND2.md`). **Mitte muuta ilma Erkki kinnituseta.**

### KRIITILINE arhitektuurne otsus — komponentide taaskasutamine

**Settings vaate iga tab renderdab täpselt sama komponendi mis vastav wizard-step**, mitte ei duplikeeri UI-d:

```
Wizard step                  Settings tab               Komponent
─────────────────────────────────────────────────────────────────
Step 1 — Connect      ←→     Connection tab        →   <Step1Connect />
Step 2 — Subscribers  ←→     Subscribers tab       →   <Step2Subscribers />
Step 3 — WooCommerce  ←→     WooCommerce tab       →   <Step3WooCommerce />
Step 4 — Recs         ←→     Recommendations tab   →   <Step4Recommendations />
Step 5 — Integrations ←→     Integrations tab      →   <Step5Integrations />
```

**`inSettings` prop** ainuke erinevus — peidab `SectionHeader`-i ja wizard-eyebrow-d ("Step X of 6"), kuna Settings'is on juba tab-page-header üleval.

**Miks see kriitiline:**
- **Üks tõe-allikas** UI-loogikale. Bug-fix Connection vormis parandab nii wizard'i kui Settings'i samaaegselt.
- **State'i sünk automaatne** — sama `wizardReducer`, sama state, mõlemad vaated peegeldavad sama. Settings'is muudetud workflow-mapping uuendab kohe ka wizard'is.
- **Code-tööd vähem 5x** — viis komponenti, mitte kümme.
- **Visuaalne järjepidevus garanteeritud** — võimatu juhuslikult tekitada erinevusi.

**Code MUST:** kasutada täpselt sama mustrit production'is. Ei tohi luua eraldi `ConnectionTabSettings.tsx` ja `Step1ConnectWizard.tsx`. **Üks komponent, kaks renderdamise-konteksti.**

Sama põhimõte kehtib **sub-komponentidele**: `SmailyCredentialBlock`, `RecEngineBlock`, `MultilingualModePicker`, `AutomationSection`, `RecBackfillPanel`, `BrowsingPrivacyCard`, `IntegrationCard`, `FieldSelectionCard` — kõik on **agnostsed**, taaskasutatavad iga konteksti puhul, kus neid vaja läheb.

---

### Plugin-poole arhitektuur

| Otsus | Detail |
|-------|--------|
| **Min PHP** | 8.0+ (named args, match, nullsafe, modern features) |
| **Min WordPress** | 6.2+ (`wp_get_environment_type()`, modern hooks) |
| **Min WooCommerce** | 7.0+, HPOS-deklareeritud compatible |
| **WC dependency** | **Soft-require**, mitte hard. Plugin töötab ilma WC-ta piiratud featuuridega (kontaktide sünk, welcome email). WC-spetsiifilised featuurid (first order, abandoned cart, rec-engine) keelatakse ilma WC-ta. |
| **Queue mehhanism** | **Action Scheduler bundled composer-iga** (`woocommerce/action-scheduler`). Ei sõltu WC olemasolust. |
| **HPOS** | `declare_compatibility('custom_order_tables', __FILE__, true)`. **Kasuta alati `wc_get_order()`, `wc_get_orders()`, MITTE otsest SQL-i `wp_posts`-i vastu.** |
| **Settings persist** | localStorage + WP `usermeta` (mitte `option_*`) — wizard-state on per-user. |

### Multi-tenant lähenemine

**1 plugin = 1 tenant.** Kui omanik haldab kahte poodi, need on kaks eraldi WP-installation'i kaks eraldi rec-engine tenant'i. **Mitte** multi-tenant single-WP. Multi-tenant single-WP on V2 backlog'is.

### SKU primary identifier

- `sku` on toote primaarne võti. `external_id` (WP post_id) on metadata.
- **Tühja SKU-ga tooted skip'itakse** koos admin notice'iga: "X products skipped, missing SKU. [View list]"
- **Variable products** lähevad iga variant'ina eraldi tootena (mitte parent/children). Bayes shrinkage `lift_local` arvestab Acana 3kg vs Acana 6kg eraldi (mootori OSA E spec).

### URL parameter namespace (KRIITILINE)

Mootor saadab kampaania-linkidel:
```
?utm_source=smaily&utm_campaign={id}&smaily_vt={token}&smaily_rec={rec_id}&smaily_ctx={context}
```

- `smaily_vt` → cookie `smaily_rec_uid` (365 päeva)
- `smaily_rec` → cookie `smaily_rec_id` (30 päeva) — **MITTE `utm_content`**, mis rikub Google Analytics A/B-testid
- `smaily_ctx` → cookie `smaily_rec_ctx` (30 päeva)
- `utm_source`, `utm_campaign` → **ei puutu**, jäävad GA jaoks puhtaks

Cookie nimed tulevad rec-engine setup-response'i `config` objektist (mitte hardcode'ida).

### Cookie strateegia

| Cookie | TTL | Eesmärk |
|--------|-----|---------|
| `smaily_rec_uid` | 365 päeva | Visitor token email-clickist (rec-engine identity-merge) |
| `smaily_anon_sid` | 30 päeva | Anonymous session ID (plugin-poolt UUID v4) |
| `smaily_rec_id` | 30 päeva | Last-touch attribution: milline soovitus käivitas viimase clicki |
| `smaily_rec_ctx` | 30 päeva | Last-touch kontekst (welcome/cart_abandoned/jne) |
| `smly_btok` | 24h | Beacon HMAC token (vt PLUGIN.md §10) |

Kõik cookie'd: `Secure=true`, `SameSite=Lax`, `HttpOnly=false` (JS beacon peab lugema).

### Browse-events: batched-mode (vaikimisi) + single-event-mode (toggle)

**Vaikimisi (MVP):** 30-sekundi batched-mode
- JS `sendBeacon` → PHP REST endpoint → buffer transient → AS-job flush'ib 30s aknas → batch (kuni 100) → rec-engine
- Vähendab DB-rea-arvu **30-60x** kõrgmahulistel saitidel
- Latency 30s — aktsepteeritav, kuna mootori decision-pipeline on 24-96h tsükkel (öine batch-cron)

**Optional toggle (Settings → Recommendations):** single-event-mode
- Iga event saadetakse kohe (mitte buffer'isse)
- Reaal-time decision-pipeline'i jaoks (mootori Faas 4)
- Praegu disable'd UI's, aga state-flag olemas

### Retry-strateegia

Tasandiline:
- **Browse events** → best-effort + batched flush. Kui AS-job ebaõnnestub, drop pärast 1-2 retry'd. **EI kirjutata `smly_plus_event_queue`-i**.
- **Catalog / Customers / Orders** → Action Scheduler durable queue, retry kuni õnnestumiseni, alarm pärast 3 ebaõnnestumist. **DB-tabel `smly_plus_event_queue`.**
- **429 (rate limit)** → austa `Retry-After` header'it, exponentsi-backoff
- **5xx** → exponentsi-backoff, kuni 3 katset, siis queue

### Beacon turvalisus (KRIITILINE)

**Mootori API-key MITTE KUNAGI client-side JS-is.** Plugin on **server-side proxy**:
1. JS `sendBeacon('/wp-json/smaily-rec/v1/beacon', payload)`
2. PHP REST endpoint võtab vastu, **valideerib origin** (CORS-style)
3. Buffer'isse, AS flush 30s aknas
4. AS-job lisab Authorization header'i, saadab rec-engine'isse

### Pilot-onboarding strateegia: Variant C (hybrid)

- **Etapp 2 lõpus** (~2 nädalat alates Code-le üleandmisest): pilot live **ainult Smaily-osaga** (kontaktid + automation'id + first-order email + abandoned cart). **EI rec-engine'it.**
- **Etapp 3 lõpus** (~3 nädalat): lisada rec-engine integration "version 2 update'ina"
- Vältib Make-flow vahepealset hoidmist
- Pilot saab Smaily-poolse väärtuse kohe

### Multi-platform vaade

API-leping (rec-engine) on **platvormi-agnostiline**. Sama API toetab tulevikus Shopify, Magento, Presta natiivseid plugin'eid. **Tenant-level platform-migration EI ole MVP-scope**, aga API toetab seda implicit (SKU primaarne, customer email primaarne).

Implementeeritud Smaily Connect Plus plugin on **WordPress/WooCommerce only**. Teised platvormid tulevad eraldi plugin'idena.

---

## 2. Stiilide migratsioon Tailwindiks

**Prototüübi olukord:** kogu stiil on inline `style={}` objektides. Värvid, vahemikud, fondid — kõik tulevad `t` (`tokens`) objektist faili tipus. Globaalsed pseudo-class-d (hover, focus) on `GLOBAL_CSS` blokis, mis süstitakse `useEffect`-iga.

**Miks see valik tehti:** prototüüp peab töötama eri keskkondades (claude.ai artifact-preview, plain CRA, online sandboxid) sõltumatult Tailwindi konfist. Inline-stiilid on garanteeritud render.

**Productionis tee see:**

- Reservi olemasolev `t` objekt → Tailwind config `theme.extend.colors`, `theme.extend.fontFamily`. Iga tokeni võtmel on selge mapping (`t.c.brand` → `colors.brand.DEFAULT`, `t.c.brandHover` → `colors.brand.hover`).
- Konverdi iga `style={{...}}` objekt Tailwindi utility-klassideks. Suur osa on otse-mapping:
  - `display: 'flex'` → `flex`
  - `padding: 16` → `p-4`
  - `gap: 12` → `gap-3`
  - `color: t.c.textPrimary` → `text-primary`
  - `background: t.c.brand` → `bg-brand`
- Tingimusliku stiili-loogika (`isActive ? t.c.brand : 'transparent'`) muuda `cn()` helperisse: `cn('border', isActive && 'border-brand bg-brand-soft')`.
- `GLOBAL_CSS` hover/focus state'id muuda Tailwindi hover/focus variantideks (`hover:bg-brand-hover`, `focus-visible:ring-2`).
- Kustuta `GlobalStyles` komponent ja `useEffect` mis süstib fontid + CSS.
- Geist font: lisa `next/font` (Next.js) või PostCSS @import (Vite).

**Tagasi-õpetus:** inline-stiilid pole "halvad", lihtsalt eba-optimaalsed productionis. Tailwind annab klassi-cache'i, deduplikatsiooni, dark-mode-toetuse, ja paremad devtools'id. Inline-stiilid maksid prototüübis töökindlust.

---

## 3. Failistruktuur ja arhitektuur

**Prototüüp:** üks fail, ~3400 rida.

**Production:**

```
smaily-connect-plus/
├── smaily-connect-plus.php             # Plugin bootstrap, hooks-registration
├── composer.json                       # PHP dependencies (action-scheduler, etc.)
├── vendor/                             # Composer artifacts (gitignored)
├── includes/
│   ├── Wizard/                         # Wizard controller, step-detection (PHP)
│   ├── Settings/                       # Settings page controller (PHP)
│   ├── Multilingual/                   # WPML/Polylang/TranslatePress adapters
│   ├── Smaily/                         # Smaily API client + retry logic
│   │   ├── Client.php
│   │   ├── EventQueue.php
│   │   ├── AutomationRouter.php       # multilingual-aware workflow routing
│   │   └── BackfillJob.php
│   ├── RecEngine/                      # Rec-engine API client (Etapp 3)
│   │   ├── Client.php
│   │   ├── BeaconBuffer.php           # 30s batched-mode beacon flush
│   │   ├── BackfillJob.php
│   │   ├── IdentityMerger.php         # cookie + URL param resolution
│   │   └── GDPRHandler.php
│   ├── Integrations/                   # CF7, Elementor, Smaily LP adapters
│   ├── REST/                           # WP REST endpoints (beacon proxy, status)
│   └── DB/                             # Migration runners
├── migrations/                         # SQL files per version (vt PLUGIN.md §7)
├── admin/
│   ├── settings.php                    # Settings page React-mount
│   ├── wizard.php                      # Wizard React-mount
│   └── src/
│       ├── components/
│       │   ├── primitives/             # Button, Input, Select, Checkbox, Toggle, Card, Banner, Pill, PillTabs, ProgressBar, Radio, NumberInput
│       │   ├── wizard/                 # StepRail, WizardFooter, MultilingualModePicker
│       │   ├── steps/                  # Step1Connect.tsx, ..., Step6Done.tsx
│       │   └── settings/               # ConnectionTab.tsx, SubscribersTab.tsx, ..., EventLogTab.tsx
│       ├── state/                      # wizard-reducer, settings-reducer, types.ts
│       └── api/                        # AJAX wrappers (test-connection, backfill, etc.)
├── public/
│   ├── beacon.js                       # Client-side beacon script (sendBeacon)
│   └── subscription-form.css           # Front-end styles for [smaily_subscription_form]
└── languages/                          # .po/.mo translation files
```

**Migration märkused:**

- Mock-data (`MOCK` objekt) **ei migreeru** — see asendub päris-detektsiooniga:
  - `env.detectedLanguages` ← WPML/Polylang/TranslatePress API
  - `env.upstreamPluginActive` ← `is_plugin_active('smaily-connect/smaily-connect.php')`
  - `env.elementorPresent` ← `did_action('elementor/loaded') > 0`
  - `env.cf7Present` ← `class_exists('WPCF7')`
  - `MOCK.storeTotals.customers` ← `count(get_users(['role__in' => ...]))`
  - `MOCK.storeTotals.orders` ← `wc_get_orders(['return' => 'ids', 'limit' => -1])` count
  - `MOCK.storeTotals.products` ← `wp_count_posts('product')`
  - `MOCK.workflows` ← `GET https://{subdomain}.sendsmaily.net/api/autoresponder/`
  - `MOCK.backfillSplit` ← `wp_users` query grouped by language meta
- `sleep()` callid test-connectionides ja backfill-tickeris **ei migreeru** — need olid prototüübi animatsioonide jaoks. Reaal-callid teevad päris HTTP-päringuid.
- Dev panel **eemaldatakse täielikult**.

---

## 4. State management

**Prototüüp:** kogu wizard-state ühe `useReducer`-i sees, mock-data hardcoded.

**Productionis:**

- Säilita reducer-pattern — see on selge ja test-itav. Konverdi `state/types.ts` TypeScripti.
- Persist'i state localStorage + WP `usermeta` iga muudatuse järel (et user ei kaotaks progressi kui ta WP admini sulgeb keset wizardit).
- AJAX-callid (test-connection, backfill start, jne) on **asünkroonsed** — kasuta TanStack Query (eemaldab manual loading-state, automaatne retry, cache invalidation).
- Backfill progress'i polli WP REST endpoint'ist iga 5s **kui wizard'is**, iga 30s **kui Settings**. Endpoint loeb `smly_plus_backfill_job` tabelist.
- Settings-state'i jaoks **eraldi reducer** (`settingsReducer`) — taaskasutab `wizardReducer`'i action-tüüpe, aga eraldi initial state (read'itud serverist mount'i ajal).

---

## 5. Mida prototüüp **ei kata**

Need on Code-i ehitamiseks:

### PHP-poolne integration
- Plugin bootstrap, hooks-registration, activation/deactivation handlers
- Test-connection endpointid: `admin-ajax.php?action=smly_plus_test_smaily`, `smly_plus_test_rec_engine`
- Tegelik Smaily API call: `https://{subdomain}.sendsmaily.net/api/account/`
- Tegelik rec-engine API call: setup-token + `GET /api/v1/ingest/ping`
- Konflikt-detection upstream pluginaga: `is_plugin_active()` + `deactivate_plugins()`

### Andmemudel
- `smly_plus_event_queue` — Smaily-poolsete eventide queue (vt PLUGIN.md §7)
- `smly_plus_backfill_job` — backfill staatus per data-type
- `smly_plus_automation_mapping` — multilingual workflow-mapping
- `smly_plus_visitor` — anon ↔ identified merge tracking
- Rec-engine-poolsetele eventidele eraldi tabelid samas struktuuris (kõik `smly_rec_*` prefix'iga)

### Background jobs (Action Scheduler)
- `smly_plus_flush_event_queue` — iga 60s, Smaily-pool
- `smly_plus_retry_failed_events` — iga 5 min, retry exponentsi-backoff
- `smly_plus_contact_sync` — daily, kontaktid Smaily-sse
- `smly_plus_abandoned_cart` — iga 15 min, abandoned cart trigger
- `smly_plus_backfill_runner` — one-shot per backfill job
- `smly_rec_flush_beacon_buffer` — iga 30s, browse-events batch flush
- `smly_rec_send_event` — per-event durable send (catalog/customers/orders)
- `smly_plus_visitor_cleanup` — daily, anon visitorid > 365 päeva ilma identify'ta

### Multilingual detection
- WPML → Polylang → TranslatePress → site_locale fallback chain
- Iga lib oma adapter klassiga `Multilingual\Adapters\WPMLAdapter`, jne
- Adapterid implementeerivad `MultilingualInterface`: `getDetectedLanguages()`, `getTranslatedURL($post_id, $lang)`, `getTranslatedFields($post_id, $lang)`

### Settings vaate sisu
- Viis tab'i: Connection, Subscribers, WooCommerce, Recommendations, Integrations
- Iga tab taaskasutab wizard-step'i komponente
- Lisaks "Change multilingual mode" Settings → Connection
- "Re-run setup wizard" link
- Event Log tab — eraldi (vt allpool)

### Event Log vaade
- Prototüübis **placeholder** (sidebar'is + Step 6 Banner viitega)
- Productionis: tabel viimaste 7 päeva eventidest
- Filtreeri: per event-type, per status (success/failed/pending), per data-type (Smaily/rec-engine)
- Üksiku event'i drill-down: payload JSON, last_error, retry-history
- "Retry now" nupp manualseks katseks
- "Export failed events as CSV" nupp Erkki jaoks debug'iks

### Identity-merge mehhanism (kogu loogika)
- URL `smaily_vt` capture → cookie set
- URL `smaily_rec` capture → cookie set
- URL `smaily_ctx` capture → cookie set
- Login/register/checkout hooks → `identity.merge` event
- `template_redirect` hook → detect `smaily_vt`, strip URL-ist, send merge event
- Vt PLUGIN.md §10 täielik mehhanism

### GDPR integratsioon
- WP Privacy Data Eraser: kutsub rec-engine `DELETE /api/v1/customer/{email}`
- WP Privacy Data Exporter: kutsub rec-engine `GET /api/v1/customer/{email}/export`, lisab export ZIP-i
- Opt-out endpoint: kutsub `POST /api/v1/customer/{email}/opt-out`
- My Account page'l: "Don't use my data for recommendations" toggle (kutsub opt-out)

---

## 6. Mis on prototüübis **juba õigesti**

Need on lõpetatud disainiotsused, mida võib **otse kasutada**:

- **Disain-süsteem** (`t` objekt) — värvid, fondid, vahemikud on lõpetatud valikud. Kasuta neid Tailwind config'i baasi.
- **Multilingual mode-pickeri loogika** — kolm radio-cardi, Mode B vaikimisi valitud, selge selgitusega. Spec §4 järgi.
- **Mode A tab-pattern** + Default account row — UX-uuring valmis.
- **Step 1 conditional logic** — kuidas mode-valikut näidata ainult multi-lang puhul, kuidas accountite-vorm muutub Mode A-ga, kuidas rec-engine kuvada eraldi optional sektsioonina.
- **Backfill UI-pattern** — idle → running → completed seisundid. Mode A puhul per-language progress-barid sihtkonto-siltidega.
- **Field selection grid** — 10 välja 2-veerulises grid'is, "Select all / Deselect all", counter ülal, footer-note email-välja kohta.
- **`canAdvance` + `advanceHint` loogika** — kuidas Continue nupp gating-ud on, mis tekst seal näha on kui veel ei saa edasi.
- **Step 3 stacked AutomationSection pattern** — per-mode UI variandid (single dropdown / per-lang radio+dropdown), dimmed-but-visible disabled state.
- **Variant 1 default-fallback radio** — üks radio-button keele-real tähistab Default-fallback'i. Ei eraldi "Default" rida.
- **Step 4 dual-variant** — 4a aktiivne, 4b turundus-pitch'iga (SVG hero + 6 konteksti + CTA).
- **Step 4 browsing privacy card** — eraldi kaart consent-info'ga, "Requires consent" pilliga.
- **Step 5 IntegrationCard** — installed vs not-installed dual-mode, "Open" vs "Install" CTAs.
- **Step 6 SummaryCard pattern** — live state-reflection, ✓/○ status-indikaatorid.
- **WP sidebar struktuur** — Smaily menu expanded: Setup Wizard, Settings, Event Log, Help.

---

## 7. Etapp-haaval implementatsiooni-plaan

### Etapp 1 — Prototype lõpetamine (mina, ~1-2 päeva veel)

- ~~Step 1-6~~ valmis
- **Settings-tab'ide ehitus** — viis tab'i, taaskasutab wizard-komponente
- **Event Log placeholder-vaade** — tabel-skelett, et Code teaks struktuuri
- **Final review + handoff document polishing**

### Etapp 2 — Smaily Connect Plus core (Code, ~8-10 päeva)

**EI vaja mootori-poole valmidust.** Võib alata kohe.

- PHP plugin scaffolding (composer, autoload, activation hooks)
- Multilingual detection adapters
- React-bundle Settings + Wizard mount'i jaoks (taaskasutab prototüübi komponente)
- Smaily API client + retry logic
- WP/Woo hooks (user_register, woocommerce_created_customer, order_status_completed, profile_update, etc.)
- Action Scheduler queue + DB-tabelid (event_queue, backfill_job, automation_mapping)
- Multilingual mode router (`MultilingualRouter::triggerAutomation()`)
- HPOS compatibility deklareerimine
- Soft-require WC graceful degradation
- Subscription form Gutenberg block + shortcode `[smaily_subscription_form]`
- CF7 + Elementor integration tabs
- Welcome / First order / Abandoned cart automation triggers
- WP-Admin Settings UI integration
- **Pilot-klient saab Smaily-poole live-ühenduse Etapp 2 lõpus** (Variant C onboarding)

### Etapp 3 — Rec-engine integration (Code, ~5-7 päeva)

**Vajab mootori `API_CONTRACT.md v1.0` valmidust** (~7 päeva post-OSA E mootori-poolelt).

- Setup-token paste flow (üks URL → setup-vastus → kõik 4 endpoint config'i)
- Cookie management: `smaily_rec_uid`, `smaily_anon_sid`, `smaily_rec_id`, `smaily_rec_ctx`
- 4 ingest endpoint'i wrapperid:
  - Catalog (save_post_product hook, batch 100, raw_attributes + best-effort tags)
  - Customers (user_register, profile_update, woocommerce_save_account_details)
  - Orders (woocommerce_order_status_completed, HPOS-aware, dual format orders+items)
  - Browse (beacon WP REST endpoint + buffer + AS flush 30s)
- Multilingual catalog: `name`, `description`, `product_url` object-formaat WPML/Polylang API-de kaudu
- `event_id` UUID per browse-event (idempotency)
- Beacon-proxy: server-side, API-key turvas (NEVER client-side)
- Identity-merge:
  - URL param capture (`smaily_vt`, `smaily_rec`, `smaily_ctx`)
  - `template_redirect` strip + redirect
  - Hooks: `wp_login`, `user_register`, `woocommerce_checkout_order_processed`
- GDPR integratsioon:
  - WP Privacy Data Eraser → `DELETE /api/v1/customer/{email}`
  - WP Privacy Data Exporter → `GET /api/v1/customer/{email}/export`
  - My Account opt-out toggle → `POST /api/v1/customer/{email}/opt-out`
- Rate limiting: `Retry-After` header austamine + exponentsi-backoff
- Engine version check: `X-Engine-Version` header, graceful degradation notice
- **Pilot-klient saab täisfunktsiooni Etapp 3 lõpus**

### Etapp 4 — Polish + acceptance (Code, ~2-3 päeva)

- E2E test PLUGIN.md §13 stsenaariumide järgi
- Tõlgete-failid (`languages/`)
- Plugin info-fail (`readme.txt` WP-stiil)
- Error log viewer Settings → Event Log
- Admin notices ja warnings
- Performance test 2k-5k kontakti backfill'iga

---

## 8. Lukus TODO-d failis

Otsi prototüübist `TODO(claude-code)` kommentaare. Hetkel olemas:

| Asukoht | TODO |
|---------|------|
| Step 4 marketing CTA | `<a href="https://smaily.com/recommendations/">` — asenda lõpliku müügilehe URL-iga enne release'i |
| Step 5 IntegrationCard | `openHref` peab kasutama `admin_url()` PHP-poolelt ja staying in-window, mitte `target="_blank"`. Praegu placeholder URL-id. |
| Step 6 dashboard linkid | Smaily/rec-engine URL'id ehitatakse jooksvalt subdomeenist + tenant info'st. Praegu placeholder loogika. |

---

## 9. Mida prototüübis **ignoreerida**

- **Dev panel** (paremas allnurgas must pill-nupp "Prototype controls") — eemalda täielikult.
- **`sleep()` callid** kõik — need olid animatsioonide jaoks, reaal API-callid asendavad need.
- **`MOCK` objekt** — kogu sisu replace'itakse päris-detektsiooniga (vt §3 Migration märkused).
- **Inline-stiilid** — replace Tailwindiga (vt §2).
- **`GlobalStyles` komponent** — eemalda, asenda Tailwind setup'iga.
- **Geist font lazy-loader** `useEffect`-iga — kasuta `next/font` või PostCSS @import.
- **`scp-*` CSS-klassid** (`scp-spin`, `scp-fadein`, `scp-input-wrap` jne) — Tailwindi animations API + variantidesse.
- **Single-file fail** — jaga komponentideks (vt §3 Failistruktuur).

---

## Final note

Prototüübi sees on **~95% disainilõppotsuseid lukus**, ülejäänud 5% on Code-i jaoks puhtalt implementatsiooni-otsused (queue-mehhanism, DB-skeem detailides, error-handling spetsiifika). 

Kui Code-i ehitamise käigus tekib **kasutaja-UX küsimusi**, mis prototüüpi otseselt ei kata (näit. "kuidas peaks veast Settings'is visualizeerima?") — peatu, kirjuta Erkkile, paranda enne edasi minekut. **Ära kunagi tee UX-disainilõpetotsust ilma Erkki valideerimiseta.**

Tehnilised ja arhitektuursed otsused (queue, retry, DB-skeem, jne) on Code'i autonomy'is.
