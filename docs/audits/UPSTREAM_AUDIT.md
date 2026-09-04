# Upstream Audit — sendsmaily/smaily-wordpress-plugin

**Audit kuupäev:** 2026-06-03
**Audiitor:** Claude Code (implementatsiooni-agent)
**Staatus:** AINULT AUDIT — mitte midagi cherry-pick'itud. Erkki vaatab üle ja otsustab.

> **RESOLUTSIOON (lisatud 2026-06-11 — see märge jäi omal ajal lisamata):**
> kõik 6 bug-fix'i cherry-pick'iti SAMAL päeval auditi järel (2026-06-03
> 15:38–15:42): `fbf181b` #123, `64f75bb` #125, `07f9067` #124, `57ca299` #126,
> `d05e866` #122, `10c520b` #121. Allpool olevad "🔴 VIGA ALLES" read kirjeldavad
> auditi-hetke, MITTE praegust seisu — kood on verifitseeritud korras (2026-06-11
> kontroll kõigis 6 asukohas). Lahtised on ainult kolm aruta-Erkki-ga punkti:
> **#120** (tõlked, käsitsi .pot-ühildamine), **#128** (WP7/min-versioonid —
> konflikt meie WC 6.9 floor'iga, EI rakenda enne pilooti), **#132** (release.sh,
> ainult kui kasutame wp.org SVN-flow'd).

## Kontekst

Plugin on fork `sendsmaily/smaily-wordpress-plugin`-st. Fork-punkti (common ancestor)
järel on upstream liikunud **14 commit'i** edasi.

| | Commit | Kuupäev | Sõnum |
|---|---|---|---|
| **Fork-punkt** (merge-base) | `a7e9f65` | 2026-03-10 | woocommerce/rss: Fix discount prices… (#117) |
| **Meie main HEAD** | `39ade27` | 2026-06-03 | docs: sync RECENGINE_API_CONTRACT… |
| **Upstream HEAD** | `86da046` | 2026-06-03 | chore: Recover from HTTP 429… (#132) |

Meie fork on samal ajal liikunud **89 commit'i** edasi (uus `src/`-arhitektuur, RecEngine,
testid jne). Pärand-failid (`integrations/woocommerce/*`, `integrations/elementor/*`,
`blocks/checkout-optin/*`, `admin/*`) on fork's **endiselt alles** — seega upstream'i
bug-fix'id puudutavad reaalselt meie koodi.

## Kategooria-loendus

| Kategooria | Arv | Commit'id |
|---|---|---|
| 🔴 Security | **0** | — |
| 🟠 Bug fix | **6** | #121, #122, #123, #124, #125, #126 |
| 🟡 Compat | **3** | #120, #128, #132 |
| 🟢 Ekvivalent meil olemas | **1** | #129 |
| ⚪ Mitte-relevantne | **2** | #118, #130 |
| 🔵 Stiil-only | **2** | #119, #127 |

> **🔴 Security: 0** — ühtegi otsest turvaparandust pole. `wp_kses`/`esc_attr`
> muudatused (#119, #127) on stiili-ühtlustus; väljund oli juba korrektselt escape'itud.

> **⚠️ Tähelepanu: kõik 6 bug-fix'i on meie fork's praegu reprodutseeritavad.**
> Igal real allpool on kontrollitud, et viga on meie failis ALLES (rea-number lisatud).
> Need on tugevad cherry-pick-kandidaadid.

---

## Audit-tabel (vanim → uusim)

### ⚪ #118 — `c6a2a7a` maint: Code quality improvements — 1.6.2 release
- **Diff:** `readme.txt` (+changelog 1.6.2), `smaily-connect.php` (versiooni-bump). 2 faili.
- **Kategooria:** ⚪ Mitte-relevantne — puhas release-mehaanika upstream'i versiooniliinile.
- **Soovitus:** **Ignoreerida.** Meie versioneerime eraldi (Smaily Connect). Changelog-tekst
  on hea **viide**, mis 1.6.2-s parandati (= #121–#126 nimekiri).

### 🔵 #119 — `ce1c0f8` maintanance: Improve codebase quality
- **Diff:** 27 faili. Surnud koodi eemaldus, ABSPATH-guard'id puuduvatesse failidesse,
  `esc_attr` literaalide ümber, globaalse scope'i `\` prefiksid, DRY-refaktor, PHP4-stiilis
  `_protected` muutujate ümbernimetus, `phpcs:ignore` kommentaarid.
- **Kategooria:** 🔵 Stiil-only (osalt 🟡 hardening).
- **Soovitus:** **Ignoreerida / selektiivne.** Puudutab palju pärand-faile, mida meie oleme
  juba restruktureerinud → konfliktid garanteeritud. Meie fork jõustab ABSPATH-guard'i niikuinii
  ([[feedback_abspath_guard]]). Ei tasu cherry-pick'i; kui mõni guard meil tõesti puudu, lisame käsitsi.

### 🟠 #121 — `d726c3f` fix: Add correct asset path for checkout-optin block
- **Diff:** `blocks/checkout-optin/smaily-integration.class.php` (1 rida). Asset-manifest
  oli `newsletter-block-frontend.asset.php`, peab olema `smaily-checkout-optin-block-frontend.asset.php`.
- **Meie kood:** 🔴 **VIGA ALLES** — `smaily-integration.class.php:94` viitab endiselt
  `newsletter-block-frontend.asset.php`-le.
- **Kategooria:** 🟠 Bug fix.
- **Soovitus:** **Cherry-pick.** Triviaalne, eraldiseisev, frontend-skript ei laadi õigesti ilma selleta.

### 🟠 #122 — `78c137b` fix: Use failure URL for failure_url parameter in Elementor block
- **Diff:** `integrations/elementor/newsletter-widget.class.php` (1 rida). `failure_url` peidetud
  väli kasutas `success_url` väärtust.
- **Meie kood:** 🔴 **VIGA ALLES** — `newsletter-widget.class.php:194` value kasutab
  `$parameters['success_url']` `failure_url` input'i jaoks.
- **Kategooria:** 🟠 Bug fix.
- **Soovitus:** **Cherry-pick.** Vale suunamine ebaõnnestunud tellimuse järel.

### 🟠 #123 — `6a4abf0` fix: Abandoned cart syncing empty carts
- **Diff:** `integrations/woocommerce/cron.class.php` (1 rida). `empty( $cart )` → `empty( $cart_content )`.
  Vale muutuja kontroll saatis tühjad korvid Smaily API-le.
- **Meie kood:** 🔴 **VIGA ALLES** — `cron.class.php:172` on endiselt `if ( empty( $cart ) )`.
- **Kategooria:** 🟠 Bug fix.
- **Soovitus:** **Cherry-pick.** Mõjutab abandoned-cart automaatika kvaliteeti.

### 🟠 #124 — `17a0528` fix: User gender always defaulting to male
- **Diff:** `integrations/woocommerce/data-handler.class.php` (+7/-3). Eksplitsiitne
  `gender_map` (`'1'=>'Male'`, `'2'=>'Female'`) asendab katkise `=== '0' ? Female : Male` loogika.
- **Meie kood:** 🔴 **VIGA ALLES** — `data-handler.class.php:95` on endiselt katkine vorm.
- **Kategooria:** 🟠 Bug fix.
- **Soovitus:** **Cherry-pick.** Vale sugu sünkitakse Smaily-sse kõigi naissoost kontaktide puhul.

### 🟠 #125 — `1aa773a` fix: Customer sync using 0 time when timestamp parsing fails
- **Diff:** `integrations/woocommerce/data-handler.class.php` (+4/-1). `strtotime() === false`
  kontroll; pars-vea korral jäetakse birthday ära, mitte ei saadeta 1970-01-01.
- **Meie kood:** 🔴 **VIGA ALLES** — `data-handler.class.php:108` `gmdate( 'Y-m-d', strtotime( $birthday ) )` ilma false-kontrollita.
- **Kategooria:** 🟠 Bug fix.
- **Soovitus:** **Cherry-pick.** Sama fail kui #124 — rakendada koos, järjekorras (#125 enne #124, nagu upstream'is).

### 🟠 #126 — `38446de` fix: User profile data not correctly saved
- **Diff:** `integrations/woocommerce/profile-settings.class.php` (+2/-2). `wp_update_user( $sanitized_data )`
  kasutas vale muutujat → ainult `ID` salvestus. Õige on `$user_data`.
- **Meie kood:** 🔴 **VIGA ALLES** — `profile-settings.class.php:263-264` kasutab `$sanitized_data`.
- **Kategooria:** 🟠 Bug fix.
- **Soovitus:** **Cherry-pick.** Kasutaja profiili-väljade (eesnimi, telefon, DOB, sugu) muudatused
  visatakse vaikselt minema. Mõjuv.

### 🔵 #127 — `fbec661` maint: Substitute printf func with wp_kses
- **Diff:** `admin/partials/smaily-admin-credentials.php` (+9/-6). `printf(esc_html__(...))` →
  `echo wp_kses(sprintf(__(...)), array('strong'=>array()))`.
- **Kategooria:** 🔵 Stiil-only. **Mitte security** — `esc_html__` escape'is juba korrektselt;
  muudatus lubab `<strong>` rendi (kosmeetiline) ja ühtlustab stiili.
- **Soovitus:** **Ignoreerida.** Pärand-admin-partial; kui see fail meie UI's püsib, võib hiljem
  käsitsi rakendada, kuid mitte-blokeeriv.

### 🟡 #120 — `1aec522` Add missing translation messages
- **Diff:** `languages/smaily-connect-et.po` (+575), `smaily-connect.pot` (+250), 2 admin-klassi (string-muudatused).
- **Kategooria:** 🟡 Compat (i18n).
- **Soovitus:** **Vaja-arutada-Erkki-ga.** Meie `.po`/`.pot` on tõenäoliselt juba lahknenud
  (uued stringid W1/W2-st). Otsene cherry-pick konflikteerib. Soovitus: kui me upstream'i admin-stringe
  veel kasutame, ühilda tõlked käsitsi `.pot` regenereerimisega meie poolelt.

### 🟡 #128 — `cb1564b` Support WordPress 7.0, increase supported min versions
- **Diff:** 15 faili. PHP min → 7.4, WP min → 6.5. Block.json'ide `apiVersion`/min,
  Dockerfile, admin-klassi WP7-kohandused, readme.
- **Kategooria:** 🟡 Compat — **otsene konflikt meie compat-otsustega.**
- **Soovitus:** **Vaja-arutada-Erkki-ga (PRIORITEET).** Meie pilootklient on WC 6.9.4 ja plugin floor
  on WC 6.9 ([[project_pilot_wc_version]]). PHP/WP miinimumide tõstmine on **teadlik otsus**, mitte
  mehaaniline cherry-pick. WP 7.0 forward-compat osad (admin-klassi kohandused) võivad olla väärt
  selektiivset ülevõtmist; miinimumide tõstmist EI tohi pimesi rakendada.
- **⚠️ LISALEID (2026-06-12, LAHENDATUD):** upstream release'is #128 koodi **versioonina 2.0.0**
  wordpress.org-i (sama `smaily-connect` slug, 2000+ installi). Kuna fork oli `2.0.0-beta.1`
  (< 2.0.0), oleks WP pakkunud — auto-update'iga *vaikselt rakendanud* — upstream'i paketi forki
  ASEMELE keset pilooti. Lahendus (DECISIONS **F3-35**): `Update URI` header (core jätab w.org-i
  uuendused täielikult vahele, WP 5.8+) + fork renumberdatud **2.1.0-beta.1**. Headerit EI tohi
  eemaldada enne upstream-merge'i.

### ⚪ #130 — `aadc7c9` maint: Update phpcs configuration
- **Diff:** `phpcs.xml` (+3/-2) — WP/PHP testVersion tõus, progress-logimine.
- **Kategooria:** ⚪ Mitte-relevantne.
- **Soovitus:** **Ignoreerida.** Meil on oma `phpcs.xml.dist` (eraldi toolchain, CI autoritatiivne —
  [[feedback_ci_strict]], [[feedback_no_phpcs_cache]]). Upstream'i fail meil isegi puudub.

### 🟢 #129 — `39e5eac` dev: Integrate PHPStan
- **Diff:** `composer.json`, `composer.lock` (+386), `phpstan.neon` (+25), `.zipignore`.
- **Kategooria:** 🟢 Ekvivalent meil olemas — meil on juba `phpstan.neon.dist` + composer'is
  `phpstan/phpstan ^1.11`, `szepeviktor/phpstan-wordpress`, `analyze` skript.
- **Soovitus:** **Ignoreerida.** Meie PHPStan-setup on juba olemas (võimalik et arenenum). Kui upstream'i
  `phpstan.neon` reeglid millegagi rangemad, võrdle hiljem — aga mitte cherry-pick.

### 🟡 #132 — `86da046` chore: Recover from HTTP 429 on releasing
- **Diff:** `release.sh` (+6/-3). SVN checkout jätkab katkemiskohast 429-throttling'u järel.
- **Meie kood:** `release.sh` on fork's olemas, meie pole seda forkimisest saadik puutunud → patch rakenduks puhtalt.
- **Kategooria:** 🟡 Compat (release-tööriist).
- **Soovitus:** **Vaja-arutada-Erkki-ga.** Asjakohane AINULT kui me publitseerime wp.org SVN-i selle
  skriptiga. Meie release-flow erineb (custom ZIP, /docs jt välistatud). Madal prioriteet, kui me
  upstream'i release.sh-d ei kasuta.

---

## Kokkuvõte / soovitatud tegevus

1. **Cherry-pick (6 bug-fix'i, kõik kinnitatud meie koodis alles):**
   `#123` `6a4abf0`, `#125` `1aa773a`, `#124` `17a0528`, `#126` `38446de`,
   `#122` `78c137b`, `#121` `d726c3f`.
   Kõik triviaalsed, eraldiseisvad. `#124`+`#125` on samas failis → rakenda järjekorras (#125, siis #124).
   Iga cherry-pick'i järel: jooksuta `npm run ci:strict` ([[feedback_ci_strict]]).

2. **Vaja arutada Erkki-ga (3):**
   - `#128` `cb1564b` — WP7/min-versioonide tõus vs meie WC 6.9 floor (KONFLIKT, prioriteet).
   - `#120` `1aec522` — tõlked, käsitsi ühildamine `.pot` regen'iga.
   - `#132` `86da046` — release.sh 429-recovery, ainult kui kasutame wp.org SVN-flow'd.

3. **Ignoreerida (5):** `#118` (release-bump), `#119` (stiil, konflikt), `#127` (stiil),
   `#130` (phpcs config — meil oma), `#129` (PHPStan — meil olemas).

**🔴 Security: 0** — eraldi tähelepanu pole vajalik.

### LESSON-väärt
6/6 upstream bug-fix'i puudutab koodi, mis on **endiselt meie fork's aktiivne pärand-kiht**.
Meie W1/W2 töö ehitas uue `src/`-arhitektuuri **kõrvale**, pärand-WooCommerce/Elementor integratsioone
välja vahetamata. Õppetund: forkist üle võetud pärand-kiht jätkab upstream'i vigade kandmist, kuni
see on kas (a) eemaldatud või (b) upstream'iga sünkroonis hoitud. Tasub kaaluda, kas need pärand-failid
on plaanis asendada — kui jah, võib mõni cherry-pick olla raisatud töö; kui ei, vajavad nad teadlikku
upstream-sünki-rütmi.
