# Mitmekeelsus: disain + tenant-onboardingu juhend

**Versioon:** 1.0 (2026-06-13)
**Skoop:** kuidas mitmekeelsus läbib kogu ahela (pood → plugin → engine → Smaily),
millal on vaja eraldi tenant'it, ja kuidas uut (potentsiaalselt mitmekeelset)
tenant'it käivitada. Autoritatiivne wire-leping: `RECENGINE_API_CONTRACT.md`.

---

## 1. Mõttemudel

**Keel on SISU (toode) ja KONTAKTI omadus — mitte eraldi äri.**

- Üks toode jääb **üheks tooteks**, olgu tal nimi/kirjeldus/URL N keeles.
- Üks klient on **üks klient**, tal on **üks eelistatud keel** (`customers.language`).
- **Soovituste arvutus (millised SKU-d) on keele-agnostiline** ja tehakse korra
  kliendi kohta. Keel rakendub alles **renderdamisel / Smaily-sünkis**.

Tagajärjed (miks see mudel on õige JA efektiivne):
- Ei dubleerita tooteid → õpitud kaalud (popularity, edges, cadence, co-purchase)
  koonduvad ühele kanoonilisele SKU-le, mitte ei killustu keelte vahel.
- Ei dubleerita arvutust → üks top-9 arvutus kliendi kohta (haakub delta-loogika
  ressursi-otsusega — vt CLAUDE.md §6.10).
- Lokaliseerimine on viimase sammu (render/sünk) mure, mitte arvutuse oma.

---

## 2. Keele-teadlik pipeline

```
Pood → Plugin → Engine → Smaily → klient
```

| Hop | Mis kannab keelt |
|---|---|
| **Pood** (WPML/Polylang/Shopify Translate) | toode = N tõlget; kasutajal/tellimusel keel |
| **Plugin → engine** | **üks kanooniline toode**, `name/description/product_url` = `{ "et": …, "lv": … }`; `customers.language` |
| **Engine (salvestus)** | `name_i18n`/`description_i18n`/`product_url_i18n` ÜHEL SKU-l; `customers.language`; `tenant_settings.default_language` |
| **Engine (arvutus)** | keele-agnostiline — valib SKU-d |
| **Engine → Smaily** | resolvib iga rec-välja kliendi keeles (`getLocalizedField`); lükkab kontaktile `language` atribuudi |
| **Smaily** | toote-väljad `{{rec_1_name}}` on JUBA õiges keeles; staatiline copy keele-segmendi / Liquid-tingimusega |

**Võti:** engine lokaliseerib ENNE Smaily-sse lükkamist. Smailys pole vaja
per-keele toote-välju — iga kontakt saab oma keele sisu. Smaily keele-segment on
vaja ainult staatilise copy jaoks (tervitus/CTA/jalus).

---

## 3. Komponentide vastutus (sender-agnostiline)

### Plugin / connector (Woo, Shopify, Make — kõik sama leping)
1. **Üks rida kanoonilise toote kohta** — ära saada eraldi rida per keel. Stabiilne
   `sku` üle keelte ja sünkide.
2. **Mitmekeelne sisu** — `name`/`description`/`product_url` kujul `{lang: value}`,
   kui pood on mitmekeelne (ühe-keelne pood saadab stringi).
3. **Kliendi keel** — `customers.language` (ISO 639-1) iga kontakti ingest'is.
4. **Ainult reaalsed tooted** — ära saada kinkekaarte, annetusi, keele-vahetit,
   virtuaalseid config-artefakte.

### Engine
- Salvestab i18n-veerud ühel SKU-l; õpib kanoonilise SKU peal.
- Arvutab top-9 keele-agnostiliselt.
- Sünkis: `getLocalizedField(name_i18n, name, customer.language, tenant.default_language)`
  iga rec-välja kohta; fallback-ahel keel → tenant-default → en → default → esimene → plain.
- Lükkab Smaily kontaktile `language` (kui teada).
- `recommendable` lipp välistab mitte-tooted (defense-in-depth, kui plugin siiski saadab).

### Smaily
- Kontakti `language` väli → keele-segmenteerimine + keele-tingimuslik staatiline copy.
- Template: `{{rec_N_name}}` on juba lokaliseeritud → ei vaja per-keele välju.
- Staatiline copy (pealkiri/CTA/jalus): Liquid-tingimus `language` järgi VÕI eraldi
  automatsioon per keele-segment ühes Smaily kontos.

---

## 4. Kolm stsenaariumi

**A. Ühe-keelne pood** (nt MiuMjau). Tooted/kliendid/Smaily üks keel.
→ **ÜKS tenant.** Plugin saadab ühe keele (stringid). i18n-masinavärki pole vaja.
**Töötab täna.**

**B. Mitmekeelne pood, ÜKS Smaily konto** (segakeelsed kontaktid, jagatud kataloog).
→ **ÜKS tenant + i18n.** Kavandatud mudel. Plugin saadab kanoonilised tooted
`{lang:value}` sisuga + kontakti keele; engine renderdab iga kontakti keeles;
Smaily segmenteerib staatilise copy keele järgi. Jagatud õppimine, üks arvutus/klient.

**C. Eraldi turud / eraldi Smaily kontod** (eraldi Smaily konto per turg VÕI
päriselt erinev kataloog/hinnad per turg).
→ **ÜKS tenant per turg**, igaüks ühe-keelne (= A kordununa).

---

## 5. Millal eraldi tenant — reegel

Tenant seob end **ühe Smaily kontoga** (subdomain + creds) ja kirjutab rec-väljad
selle konto kontaktidele. Sellest:

- **Üks Smaily konto, segakeelsed kontaktid → PEAB olema üks tenant** (i18n-render).
  Per-keele tenant'id EI tööta — nad sihiksid samu kontakte ja konfliktiksid.
- **Eraldi Smaily konto per keel/turg → üks tenant igaühe kohta.**
- **Kataloog päriselt erinev per turg → eraldi tenant** (sisuliselt eri pood).

> Lihtsustatult: **eraldi tenant tuleneb eraldi Smaily kontost / eraldi kataloogist,
> MITTE keelest endast.**

---

## 6. Onboardingu otsustuspuu

```
Uus pood:
 1. Ühe-keelne?
      → ÜKS tenant (stsenaarium A). Valmis täna.
 2. Mitmekeelne:
    2a. Üks Smaily konto kõigile keeltele (segakeelsed kontaktid)?
        → ÜKS tenant + i18n (stsenaarium B).
          Eeldab: plugin saadab {lang:value} + customers.language.
          Engine-pool on valmis (vt §7).
    2b. Eraldi Smaily konto per turg, VÕI eri kataloog/hinnad per turg?
        → ÜKS tenant per turg (stsenaarium C), igaüks ühe-keelne (= A).
```

---

## 7. Implementatsiooni seis (2026-06-13)

**Engine — VALMIS ✓**
- Kataloogi i18n-veerud (`name_i18n`/`description_i18n`/`product_url_i18n`) +
  `getLocalizedField` fallback (`lib/catalog/i18n.ts`).
- `customers.language` (ingest populeerib), `tenant_settings.default_language`.
- Render-kiht lokaliseerib copy (`lib/engine/render/`).
- **Rec-toote-väljad lokaliseeritakse kliendi keeles** (`sync-tenant.ts`, commit
  eaa1e2e) — `rec_N_name/description/link_url` läbi `getLocalizedField`.
- **Smaily kontaktile lükatakse `language` väli** (`contact-sync.ts`).
- `recommendable` lipp (migration 0039) — mitte-toodete välistus.

→ **Stsenaarium B on engine-poolelt täielikult valmis.** Kuni plugin saadab ainult
ühte keelt, on `name_i18n = {default:…}` ja lokaliseerimine tagastab vaikeväärtuse
(kahjutu no-op).

**Lokaliseerimise mudeli OTSUS (2026-06-13):** mõlemad pluginad → **(B) `{lang:value}`**
(Woo + Shopify kinnitasid). B taandub ühe-keelse poe puhul graatsiliselt A-ks.

**Plugin — TEHA (vt PLUGIN_BRIEF_catalog_correctness.md)**
- Kanooniline toode (kollab tõlked), stabiilne SKU.
- `{lang:value}` sisu + `customers.language`.
- Mitte-toodete filter.

**Teadolik piirang:** `image_url`-il pole i18n-veergu (pildid tavaliselt keele-
agnostilised) — `rec_N_image_url` jääb üks väärtus.

---

## 8. Per-platvorm märkmed

Nõuded on **lepingu-tasandil** (`RECENGINE_API_CONTRACT.md`) → kehtivad KÕIGILE
connector'itele võrdselt.

- **WooCommerce (Smaily Connect):** Polylang/WPML salvestab tõlked **eraldi
  post'idena** → suurim duplikatsiooni-risk. Vaja kollata tõlked kanooniliseks
  (`PolylangAdapter` olemas, aga kataloogi-rajal kasutamata). Vt eraldi briif.
- **Shopify (shopify-connect):** Shopify hoiab tõlkeid **sama toote** locale-
  tõlgetena (Translate & Adapt / Translations API), MITTE eraldi toodetena →
  duplikatsiooni-risk väiksem. AGA Shopify tiim peab SAMUTI: (a) saatma
  `{lang:value}` sisu (tõmmatud Translations API-st) mitmekeelse poe puhul,
  (b) saatma kliendi locale → `customers.language`, (c) saatma mitte-toote
  **SIGNAALI** (mitte filtreerima): Shopify `isGiftCard=true` → `product_type:"gift_card"`
  (engine välistab selle automaatselt), digi-kaup `requiresShipping=false` →
  `is_virtual:true` (salvestatakse, EI välista); ÄRA saada draft/archived tooteid
  (status != active) või saada `in_stock=false`-ga. **NB:** ära pane Shopify vaba-
  teksti `productType` (merchandising-kategooria) `product_type` välja — see on
  kategooria/tag, mitte struktuurne tüüp. (d) sünteetilise SKU SKU-ta variantidele
  (stabiilne). Ühe-keelne Shopify pood = stsenaarium A, midagi erilist pole vaja.
- **Make-flow:** sama leping; mitmekeelsus sõltub sellest, mida flow ehitab.

---

## 9. Viited

- Wire-leping: `RECENGINE_API_CONTRACT.md` §3 (catalog identity & lifecycle,
  multilingual), §4 (customers.language).
- Woo plugin briif: `PLUGIN_BRIEF_catalog_correctness.md`.
- Engine: `lib/catalog/i18n.ts`, `lib/engine/render/context-builder.ts`,
  `lib/smaily/sync-tenant.ts`, `lib/smaily/contact-sync.ts`.
