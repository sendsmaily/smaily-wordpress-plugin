# Plugin brief: kataloogi-sünki korrektsus (mitmekeelsus + mitte-tooted)

**Kellele:** Smaily Connect (WooCommerce plugin) tiim
**Kellelt:** Recommendation engine tiim
**Kuupäev:** 2026-06-13
**Staatus:** vajab plugina-poolset parandust enne MiuMjau go-live'i

---

## 1. Mida me engine-poolel näeme

MiuMjau pilot-sünk tõi engine-i `catalog` tabelisse **5783 SKU rida**, millest **~5201 on sünteetilised `wc-*` võtmed** (poel pole reaalseid SKU-sid). Probleemid, mis otse rikuvad soovitusi:

1. **Sama toode kordub eraldi SKU-na iga keele kohta.** Näide — üks shampoon, 6 rida:
   `wc-59199` (ET), `wc-59221` (LV), `wc-59222` (LT), `wc-59223` (FI), `wc-59224` (EN), `wc-59225` (RU) — kõik sama Eesti kategooria all. Soovituste top-9 segab seetõttu keeli (kliendile pakutakse leedu- ja läti-keelseid tootenimesid).
2. **Mitte-tooted jõuavad kataloogi:** keele-vaheti pseudo-toode (`wc-49143` · "🇱🇻 Latvian (Latviešu)"), kinkekaardid, "MiuMjau annetuskampaania". Need pakutakse soovitustena (annetuskampaania reastus isegi Tier-0 top best-selleriks).

> Engine-poolel oleme juba lisanud kaitse (vt §5), aga **õige andmevoog peab tulema plugina poolelt** — engine ei suuda tõlke-duplikaate usaldusväärselt kokku liita ega keelt nimest tuvastada.

---

## 2. Juurpõhjus plugina koodis

**Polylang/WPML salvestab iga tõlke eraldi `wp_posts` reana** (oma post ID). Plugina kataloogi-enumeratsioon ei ole keele-teadlik:

- **Backfill** — `includes/Smaily/RecEngine/Backfill/CatalogBackfillJob.php:49-71`
  Raw SQL `SELECT ID FROM wp_posts WHERE post_type='product' AND post_status='publish' ORDER BY ID` — **ei filtreeri keelt, ei kasuta `suppress_filters`-it ega Polylangi**. Tagastab KÕIK tõlke-postitused eraldi toodetena.
- **Live hook** — `includes/Integrations/WooCommerce/CatalogHookHandler.php:64-75`
  `save_post_product` käivitub iga tõlke-postituse salvestamisel → upsert tehakse selle tõlke `wc-{id}` peale (mitte kanoonilise toote peale).
- **SKU** — `includes/Smaily/RecEngine/Support/SkuResolver.php:57-63`
  `get_sku()` else `"wc-" . get_id()`. Loogika ise on OK, AGA kuna `get_id()` on iga tõlke oma post-ID → iga keel saab oma SKU.
- **Mitmekeelne payload puudub** — `includes/Smaily/RecEngine/CatalogPayloadBuilder.php:49-51, 90-135`
  `name`/`description`/`product_url` saadetakse üksik-stringina, mitte `{lang: value}` objektina (kuigi engine VÕTAB objekti vastu — vt §3).
- **Mitte-toodete filter puudub** — `CatalogBackfillJob` võtab kõik `post_type=product, post_status=publish`; tüübi/virtuaalsuse/pseudo-toote kontrolli pole.

**NB:** Plugina-l on JUBA `includes/Multilingual/PolylangAdapter.php` (mh `get_translations()` → `{name, description, product_url}` keele kaupa) + `DetectorFactory`. Seda kasutatakse praegu **ainult Smaily kontakti-väljade** jaoks, **mitte kataloogi-sünkis**. Lahendus on suuresti olemasoleva adapteri rakendamine kataloogi-rajale.

---

## 3. Mida engine juba toetab (sihtkontrakt)

- **Mitmekeelne content:** `/api/v1/ingest/catalog` aktsepteerib `name`/`description`/`product_url` kujul `{"<lang>": "<value>"}` (RECENGINE_API_CONTRACT.md §3). Engine salvestab `name_i18n` jne ja oskab keele-teadlikult renderdada.
- **Kliendi keel:** `/api/v1/ingest/customers` võtab vastu `language` välja (salvestatud `customers.language`). Seega per-kliendi lokaliseeritud soovitused on **end-to-end võimalikud**, kui plugin saadab toote-keeled + kontakti-keele.

---

## 4. Mida palume plugina poolelt (prioriteedi järjekorras)

### P1 — Keele-teadlik enumeratsioon (üks toode, mitte N tõlget)
Nii **backfill** kui **live-hook** peavad tõlke-postitused kokku liitma kanooniliseks tooteks:
- Vali **kanooniline post** (nt Polylangi vaikekeel) ja sünki AINULT seda toodet üks kord.
- **SKU stabiilsus:** sünteetiline SKU peab põhinema **kanoonilise toote ID-l** (`wc-{canonical_id}`), et kõik keeled mapuks ühele püsivale SKU-le. Nii ei teki sünkidel uusi ridu ega duplikaate.
- Live-hook: tõlke-postituse salvestamine peab uuendama **kanoonilist** SKU-rida, mitte looma uut. Kasuta `PolylangAdapter::get_translated_post_id()` kanoonilise ID leidmiseks.

### P2 — Lokaliseerimise mudel (valida üks)
- **(A) Ainult primaarkeel** — saada ainult kanoonilise (vaikekeele) toote väljad. Lihtsaim; sobib MiuMjau-le (saadab ainult eesti kirju). Sobib ka mudeliga, kus iga keel = **eraldi tenant/pood** (puhtaim tõsiselt mitmekeelse poe puhul).
- **(B) Mitmekeelne objekt** — saada `name`/`description`/`product_url` kujul `{ "et": "...", "en": "...", ... }` (`PolylangAdapter::get_translations()` annab selle juba). Engine on valmis (§3), võimaldab per-kliendi keelt ilma eraldi tenantita.

→ **Soovitus:** kui pood on/jääb ühe-keelseks per tenant (MiuMjau täna), tee **(A)**. Kui plaanis on üks pood mitme keelega ühes Smaily kontos, tee **(B)**. Palun andke teada, kumb suund teie arhitektuuriga sobib (see mõjutab ka, kas läheme per-keel-tenant teed). Täielik mudel: vt `MULTILINGUAL_DESIGN.md`.

### P2b — Kliendi keel kontakti-ingest'is
Mõlemad mudelid (A ja B) vajavad **`customers.language`** (ISO 639-1) saatmist
`/api/v1/ingest/customers` kaudu — Woo/WPML kasutaja-keel või tellimuse locale.
Engine lokaliseerib rec-väljad selle järgi (vaikekeel `tenant_settings.default_language`,
kui kliendi keel puudub). Ilma selleta renderdab engine kõik tenant'i vaikekeeles.

### P3 — Mitte-toodete väljajätt enumeratsioonis
Ärge saatke kataloogi:
- keele-vaheti pseudo-tooteid (Polylangi "language switcher"),
- kinkekaarte / annetus-tooteid,
- virtuaalseid/mitte-müüdavaid config-artefakte (kus tuvastatav, nt `$product->is_virtual()`, tooteliik, kahtlane slug).
Enumeratsioon peab piirduma reaalsete, ostetavate toodetega.

### P4 — Kustutus/merge käitumine
Tõlke-postituse kustutamisel ÄRA kustuta kanoonilist SKU-d (`in_stock=false` peab tulema ainult siis kui kanooniline toode reaalselt kaob/läheb laost välja).

---

## 5. Engine-poolne kaitse (juba tehtud — defense-in-depth, mitte asendus)

- **`catalog.recommendable` lipp** (migration 0039): engine välistab soovitustest test-artefaktid (`LIVE-*`/`live-test`) ja mitte-tooted (kinkekaart/gift card/annetus/donation) — **igal ingest-upsert'il uuesti arvutatud**, nii et re-sync ei too neid tagasi. See on ohutusvõrk; ideaalis plugin neid üldse ei saada (§P3), et vältida kataloogi paisumist.
- **Slug-põhine tag-tuletus** (species/category_canonical/replenishable) ka ilma AI-ta.
- **Rec-väljade lokaliseerimine on nüüd valmis** (2026-06-13): engine renderdab
  `rec_N_name/description/link_url` kliendi keeles (`customers.language`) i18n-veergudest
  ja lükkab Smaily kontaktile `language` atribuudi. **Stsenaarium B on engine-poolelt
  täielikult valmis** — ootab ainult, et plugin saadaks `{lang:value}` sisu + `customers.language`.
- Mitmekeelset duplikatsiooni (eraldi SKU per tõlge) engine **ei** suuda kaitsta
  (puudub keele-signaal + parent-link) — see vajab plugina-poolset parandust (§P1-P2).

---

## 6. Küsimused plugina tiimile

1. Kas toetate ka **WPML**-i (lisaks Polylangile), ja kas mõlema jaoks on `MultilingualAdapter` valmis kataloogi-rajale rakendamiseks?
2. Kuidas valite **kanoonilise** keele/post'i (Polylangi default language? konfigureeritav?)?
3. Eelistus **(A) primaarkeel** vs **(B) `{lang:value}` objekt** — kumb teie roadmap'iga sobib (vrd sub-PR 3.3+, mis mitmekeelse vormi nagunii planeeris)?
4. Kas mitte-toodete (kinkekaart/annetus/keele-vaheti) tuvastus on plugina poolel teostatav tüübi/meta järgi, või vajate meilt nimekirja mustritest?

---

## 7. Viited

- Engine kontrakt: `docs/RECENGINE_API_CONTRACT.md` §3 (catalog, mitmekeelne vorm), §4 (customers.language)
- Varasem engine→plugin brief: `docs/PROMPT_woo_plugin_team.md` (item 6 = mitte-toodete filter, sama teema)
- Plugina võtmefailid: `CatalogBackfillJob.php:49-71`, `CatalogHookHandler.php:64-75`, `SkuResolver.php:57-63`, `CatalogPayloadBuilder.php:49-51,90-135`, `includes/Multilingual/PolylangAdapter.php`
