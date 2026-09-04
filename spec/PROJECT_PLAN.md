# Smaily Connect — Project Plan for Claude Code

**Versioon**: 1.1
**Avaldatud**: 2026-05-19
**Adressaat**: Claude Code (implementatsiooni-agent)
**Owner**: Erkki
**Authoritative documents** (loe enne tööd):
1. `PLUGIN.md` v0.6 — funktsionaalne spec, mis ehitada (**autoritatiivne** arhitektuuri ja scope osas)
2. `RECENGINE_API_CONTRACT.md` v1.0 — rec-engine API spec, mootori-poolelt autoritatiivne (request/response schemas, error-codes, rate-limits)
3. `SUGGESTION.md` — prototüübi-üleandmise juhend, kriitiline arhitektuurne otsus (komponentide reuse)
4. `smaily-connect-plus-wizard.jsx` — UX prototüüp, Faas 2 visuaalne baas
5. `STYLE_MAPPING.md` — Tailwind config Smaily-tokenitega (loe Faas 2 alguses)
6. **See dokument** — *kuidas* ehitada, *mis järjekorras*, *mis on acceptance*

**Lisaviide** (mootori-poolelt, ei ole autoritatiivne):
- `PLUGIN_IMPLEMENTATION_WP.md` v1.0 — mootori-tiim koostas WP-implementatsiooni juhendi koodinäidetega (WPML/Polylang API-kasutus, HPOS order reader, beacon JS, GDPR handler, EngineClient retry). **Vasturääkivuste korral võidab meie PLUGIN.md** — eriti plugin-nimi (`smaily-connect`, mitte `smaily-rec-engine`) ja paigutus (`includes/`-kaust, mitte `src/`). Mootori dokument räägib uue plugin'i loomisest; meie strategy on fork olemasolevast Smaily Connectist (§2 PLUGIN.md). Loe mootori dokumenti **koodinäidete jaoks**, mitte arhitektuuri jaoks.

---

## 1. Üldjuhend Code-agendile

### 1.1. Töö-rütm

**Iga faas algab** sinu lühikese plaaniga (1-2 lõiku) selle kohta, mida sa kavatsed teha. Erkki kinnitab või parandab.

**Iga faas lõpeb** sinu kokkuvõttega: mis tehti, mis testitud, mis on auditeerinud (PCP, PHPCS, manual), mis on edasilükatud järgmisesse faasi. Erkki vaatab üle ja kinnitab edasi liikumise.

**Kõik faasid annavad PR-i** `main` branch'i vastu, mille Erkki review'b. Mitte iseseisvalt merge'ida.

**Branch-nimi per faas**:
- Faas 1: `feat/phase-1-scaffold-smaily-core`
- Faas 2: `feat/phase-2-react-ui`
- Faas 3: `feat/phase-3-rec-engine`
- Faas 4: `feat/phase-4-polish-marketplace`

**Sub-PR-id faasi sees** lubatud, kui faasi-sisu jaotub loogiliselt (näit Faas 1 algus: scaffold + composer + activation; Faas 1 lõpp: hooks + queue + multilingual router). Sub-PR-id merge'itakse faasi-branch'i, **mitte** `main`-i.

### 1.2. Autonoomia-ulatus

Sa võid teha autonoomselt järgmisi tehnilisi otsuseid:

- PHP class-hierarhia, kausta-struktuur `includes/` sees (kui ühildub PLUGIN.md §2-ga)
- Composer dependency-versioonid (kui ühildub `>=8.0` PHP, AS `^3.7`)
- Konkreetne DB-migration tee (sh batched migrations, kui see on parem kui üks suur)
- PHPCS-ruleset detailid, GitHub Actions workflow-i sisu
- React-komponendi prop-API täpne kuju (kui ühildub prototüübi mustriga)
- Tailwind config detailid (sh dark-mode toetus — mu ettepanek: ÄRA tee MVP-s, hoia lihtsana)
- Error-handling konkreetne implementatsioon (sh logger-formaat)
- Test-cases'ide nimekiri (kui kaetud PLUGIN.md §15 acceptance criteria)

**Erkki kinnitust nõuavad** järgmised:
- Igasugune **UX-otsus**, mis ei ole prototüübis kaetud (näit. "kuidas peaks veast Settings'is visualizeerima?")
- Igasugune **API-leping muudatus** rec-engine'iga (vt RECENGINE_API_CONTRACT)
- Igasugune **DB-skeema muudatus** PLUGIN.md §7-st
- Igasugune **scope muudatus** (välja-jäetud featuuride lisamine v1-sse, või MVP-st välja-jätmine)
- **Production-URL vahetus** (näit kui mootori-agent annab uue base URL-i)
- **Faasi ettepanek lõpetada enne acceptance criteria täitmist**

Kui pole kindel, kas otsus on autonoomne või mitte — **peatu, küsi**. Parem üks päev hilineda kui terve faas ümber teha.

### 1.3. Kuidas raporteerida

**Faasi lõpus annad** järgmise struktuuri:

```
## Faas X — Kokkuvõte

### Mida tegin
- Lühike loend, 5-10 punkti

### Mida testisin
- Unit tests: X% coverage kriitilistele klassidele
- Integration tests: konkreetsed scenariod (PLUGIN.md §15 mapping)
- Manual review: konkreetsed käsitsi-testid
- Tool checks: PCP, PHPCS, PHPStan, etc. — passed/warnings/failed

### Mida ei kata
- Konkreetsed punktid, mis on edasi-lükatud
- Pakutud lahendus iga jaoks (kas järgmine faas, kas backlog, kas hilisem)

### Avatud küsimused Erkkile
- Numbered list — kõik otsused, mis vajavad sinu kinnitust enne järgmist faasi
```

**Vahepeal töö ajal**: kui jääd ummikusse > 1h, jäta märkus PR-i konteksti või kommentaaridesse. Mitte ootada vaikselt — anda Erkkile teada, mis blokeerib.

### 1.4. Kvaliteedinõuded

Kõik need PEAVAD läbima enne faasi-PR-i review'le saatmist:

| Tööriist | Konfiguratsioon | Vajalik tase |
|----------|-----------------|--------------|
| **WordPress Plugin Check (PCP)** | Standard ruleset | Roheline (0 errors), warnings dokumenteeritud |
| **PHPCS** | `phpcs.xml.dist` (PSR-12 + WPCS hybrid) | 0 violations |
| **PHPStan** | Level 6 minimum | 0 errors |
| **PHPUnit** | Bundled | Kriitilised klassid >= 70% coverage |
| **GitHub Actions CI** | `.github/workflows/ci.yml` | Roheline kogu pipeline |

**Mis on "kriitiline klass" Coverage'i mõttes:**
- `MultilingualRouter` (kogu automation-routing loogika)
- `SmailyClient` ja `RecEngineClient` (API-kõnede formuleerimine ja retry)
- `EventQueue` mõlemad (`Smaily\EventQueue`, `RecEngine\EventQueue`)
- `BackfillJob` (idempotency-loogika)
- `IdentityMerger` (cookie + URL-param resolution, kolm trigger-tüüpi)
- `NotificationManager` (severity + throttling-loogika)
- `BeaconBuffer` (transient + flush)

**Mis on aktsepteeritav lower coverage:**
- React-bundle (UI-rendering — kaetud manual-test'idega)
- Migration-scripts (üks-kord, lihtne)
- Admin views (UI-tase)

---

## 2. Repository setup ja konventsioonid

### 2.1. Repository struktuur

**Algses paigutuses (post-fork):**

```
smaily-wordpress-plugin/                    # Repository root
├── .github/
│   └── workflows/
│       ├── ci.yml                          # Faas 1: lint + test
│       └── plugin-check.yml                # Faas 4: WP-org Plugin Check
├── .gitignore                              # vendor/, node_modules/, build/
├── README.md                               # Public-facing, BETA-faasis lihtne
├── readme.txt                              # WordPress.org formaat (Faas 4-s täitmine)
├── composer.json                           # Faas 1 alguses
├── phpcs.xml.dist                          # PHPCS ruleset
├── phpstan.neon.dist                       # PHPStan config
├── phpunit.xml.dist                        # PHPUnit config
├── package.json                            # Faas 2: React build
├── tailwind.config.js                      # Faas 2: design tokens
├── postcss.config.js                       # Faas 2
├── vite.config.js                          # Faas 2: bundle Settings + Wizard
├── smaily-connect.php                      # Plugin bootstrap (peamine fail, slug säilib)
├── uninstall.php                           # Faas 4: data purge if toggle on
├── includes/                               # PHP klassid
│   ├── Bootstrap.php                       # Plugin singleton
│   ├── Activation.php                      # register_activation_hook
│   ├── Deactivation.php                    # register_deactivation_hook
│   ├── Smaily/                             # Olemasolev kood — Faas 1 säilita, laienda
│   │   ├── Client.php
│   │   ├── EventQueue.php
│   │   ├── AutomationRouter.php            # Faas 1: multilingual-aware
│   │   └── BackfillJob.php
│   ├── RecEngine/                          # Uus kogu kaust, Faas 3
│   │   ├── Client.php
│   │   ├── EventQueue.php
│   │   ├── BeaconBuffer.php
│   │   ├── BackfillJob.php
│   │   ├── IdentityMerger.php
│   │   └── GDPRHandler.php
│   ├── Multilingual/                       # Faas 1
│   │   ├── DetectorInterface.php
│   │   ├── WPMLAdapter.php
│   │   ├── PolylangAdapter.php
│   │   ├── TranslatePressAdapter.php
│   │   ├── SiteLocaleAdapter.php
│   │   ├── DetectorFactory.php             # WPML → Polylang → TranslatePress → site_locale fallback
│   │   └── Router.php                      # MultilingualRouter::triggerAutomation()
│   ├── Wizard/                             # Faas 1: PHP controller, Faas 2: React mount
│   │   ├── Controller.php
│   │   ├── StepDetector.php
│   │   └── EnvDetector.php                 # Detect: keeled, Elementor, CF7, store totals
│   ├── Settings/                           # Faas 1: PHP controller, Faas 2: React mount
│   │   ├── Controller.php
│   │   ├── OptionsRepository.php           # Krüpteeritud salvestus
│   │   └── RestEndpoints.php               # AJAX endpoints test-connection, backfill, etc.
│   ├── Notifications/                      # Faas 4 (Faas 1-3 kasutavad lihtsalt error_log)
│   │   ├── NotificationManager.php
│   │   ├── AdminNoticeRenderer.php
│   │   ├── EmailSender.php
│   │   └── Throttler.php
│   ├── Integrations/                       # Olemasolev — Faas 1 säilita
│   │   ├── ContactForm7/
│   │   ├── Elementor/
│   │   └── WooCommerce/                    # Hooks
│   ├── REST/                               # Faas 1: nonce + capability check, Faas 3: beacon
│   │   ├── TestConnectionEndpoint.php
│   │   ├── BackfillEndpoint.php
│   │   ├── EventsEndpoint.php
│   │   └── BeaconEndpoint.php              # Faas 3
│   └── DB/
│       ├── Migrator.php
│       └── Schemas/
│           ├── 001_event_queue.sql
│           ├── 002_backfill_job.sql
│           ├── 003_automation_mapping.sql
│           ├── 004_rec_event_queue.sql     # Faas 3
│           └── 005_visitor.sql             # Faas 3
├── admin/                                  # Faas 2
│   ├── settings.php                        # React-mount Settings'ile
│   ├── wizard.php                          # React-mount Wizard'ile
│   └── src/
│       ├── index.tsx                       # Vite entry, mount detection
│       ├── components/
│       │   ├── primitives/                 # Button, Input, Select, ...
│       │   ├── wizard/                     # StepRail, WizardFooter, MultilingualModePicker
│       │   ├── steps/                      # Step1Connect.tsx, ..., Step6Done.tsx
│       │   └── settings/                   # Empty — taaskasutab steps/, vt SUGGESTION §1
│       ├── state/                          # wizard-reducer, settings-reducer, types.ts
│       ├── api/                            # AJAX wrappers
│       ├── hooks/                          # useTestConnection, useBackfillProgress, jne
│       └── utils/                          # cn, format-number, jne
├── assets/
│   ├── fonts/                              # Faas 2: bundled fonts (vt STYLE_MAPPING.md)
│   ├── images/
│   │   └── rec-block-preview.png           # Step 4b marketing hero
│   └── icons/
├── public/                                 # Faas 3
│   ├── js/
│   │   └── beacon.js                       # Client-side tracker
│   └── css/
│       └── subscription-form.css           # Front-end styles
├── languages/                              # Faas 1 algselt minimaalne, Faas 4 täitmine
│   ├── smaily-connect.pot
│   ├── smaily-connect-et.po
│   └── smaily-connect-en.po
├── templates/                              # Faas 4
│   └── email/
│       ├── critical.html
│       ├── critical.txt
│       ├── error.html
│       └── error.txt
└── tests/
    ├── Unit/
    ├── Integration/
    └── bootstrap.php
```

### 2.2. Plugin entry-point

**`smaily-connect.php`** sisaldab plugin header'i:

```php
<?php
/**
 * Plugin Name:       Smaily Connect (BETA)
 * Plugin URI:        https://github.com/erkki/smaily-wordpress-plugin
 * Description:       Connect your WooCommerce shop to Smaily for email marketing automation and personalised recommendations.
 * Version:           2.0.0-beta.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   8.5
 * Author:            Smaily
 * Author URI:        https://smaily.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smaily-connect
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SMAILY_CONNECT_VERSION', '2.0.0-beta.1');
define('SMAILY_CONNECT_FILE', __FILE__);
define('SMAILY_CONNECT_DIR', plugin_dir_path(__FILE__));
define('SMAILY_CONNECT_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/vendor/autoload.php';

\Smaily\Connect\Bootstrap::instance()->boot();
```

### 2.3. Commit-strateegia

**Conventional Commits** formaat:
- `feat:` — uus funktsionaalsus
- `fix:` — bugfix
- `refactor:` — kood-restruktuur ilma käitumis-muudatuseta
- `docs:` — dokumentatsiooni-muudatus
- `test:` — testid
- `chore:` — config, dependency-update, build-setup
- `perf:` — performance-parandus

Scope optional: `feat(wizard):`, `fix(beacon):`, etc.

**Iga commit on iseseisev** — võiks teoreetiliselt revert'ida ilma järgmisi commit'eid katkestamata. Tähendab: ära mixi WIP-i lõpetatud koodi sisse.

### 2.4. PR-template

`.github/pull_request_template.md`:

```markdown
## Faas: X

## Mida see PR sisaldab
<!-- Lühike loend, viiteid PLUGIN.md/PROJECT_PLAN.md sektsioonidele -->

## Acceptance criteria (PROJECT_PLAN.md Faas X)
<!-- Märgi täidetud kriteeriumid: -->
- [ ] Criteria 1
- [ ] Criteria 2
- ...

## Quality gates
- [ ] PCP roheline (0 errors)
- [ ] PHPCS 0 violations
- [ ] PHPStan level 6 0 errors
- [ ] PHPUnit jookseb läbi (coverage X%)
- [ ] Manual review checklist täidetud

## Mida ei kata
<!-- Edasi-lükatud asjad, viited backlog'ile -->

## Avatud küsimused
<!-- Numbered list — kõik, mis vajab Erkki kinnitust enne merge'i -->
```

---

## 3. Faas 1 — Plugin scaffold + Smaily core

**Eesmärk**: PHP-tasandil töötav plugin, mis säilitab kogu olemasoleva Smaily Connect funktsionaalsuse + lisab multilingual-routing + Action Scheduler queue + uued DB-tabelid. **Ilma React UI-ta** — Settings on praegu vana wp-admin form-stiilis (olemasolev kood). Wizard-ekraani ei ole veel.

**Eeldatav kestus**: 5-7 päeva
**Branch**: `feat/phase-1-scaffold-smaily-core`
**Sub-PR strateegia**: lubatud, ehita inkrementaalselt

### 3.1. Mida ehitada (detailne loend)

**Scaffold-osa (sub-PR 1):**

1. **`composer.json`** (PSR-4 autoload, AS dependency, dev-dependencies WPCS + PHPStan + PHPUnit)
2. **`phpcs.xml.dist`** (PSR-12 + WPCS hybrid, hooks-callbackid snake_case-erand)
3. **`phpstan.neon.dist`** (level 6, WP stubs)
4. **`phpunit.xml.dist`** (bootstrap, coverage exclude assets/)
5. **`.github/workflows/ci.yml`** (matrix: PHP 8.0/8.1/8.2, WP 6.2/latest, Woo 7/latest)
6. **`smaily-connect.php`** plugin header (vt §2.2)
7. **`includes/Bootstrap.php`** singleton-pattern, hookide registreerimine
8. **`includes/Activation.php`** — DB-migrations, default options
9. **`includes/Deactivation.php`** — cron'id maha, AS jätta alles
10. **`includes/Constants.php`** — plugin-tasandi konstandid, sh `SETUP_BASE_URL = 'https://intelligence.smaily.com/setup/exchange'`. Production-migratsiooni puhul muudetakse konstandi-väärtus uue plugin-versiooniga. Plugin'i koodi kasutus: `apply_filters('smaily_connect_setup_url', Constants::SETUP_BASE_URL)` — filter võimaldab per-site override-i.

**Implementatsiooni-noot:** PLUGIN_IMPLEMENTATION_WP.md koodinäide `SetupTokenExchange::SETUP_BASE_URL` private const'na on **liiga jäik**. Meie versioon kasutab filtrit, et production-migratsiooni puhul saaks Erkki ühe rea PR-iga uuendada ja klient saab plugin-update'i kaudu (mitte iga klient kirjutab uut tokenit). Vt PLUGIN.md §8.

**DB-migration (sub-PR 2):**

10. **`includes/DB/Migrator.php`** — versioonitud migration-runner
11. **`migrations/001-005.sql`** — tabelite loomine PLUGIN.md §7 järgi (kõik 5 tabelit, sh `smly_rec_event_queue` `depends_on_event_id`-iga, kuigi rec-engine osa tuleb Faas 3-s)
12. **Activation hook'is** migrations käivitatakse

**Smaily-poolse koodi laiendus (sub-PR 3):**

13. **Olemasolev `includes/Smaily/Client.php`** — laienda kui vaja API-kõnede retry-loogikaga (kui upstream'is veel pole)
14. **`includes/Smaily/EventQueue.php`** — uus klass, kasutab Action Scheduler'it. API: `enqueue($event_type, $entity_id, $payload, $depends_on = null)`, `flush()`, `retry($id)`
15. **`includes/Smaily/AutomationRouter.php`** — `triggerAutomation($trigger_type, $contact_data, $additional_fields)`. Sõltub Multilingual moodulist (sub-PR 4).
16. **`includes/Smaily/BackfillJob.php`** — kontaktide backfill, idempotent (re-run uuendab `_smaily_synced_at` meta'd vanematele kui X päeva)

**Multilingual moodul (sub-PR 4):**

17. **`includes/Multilingual/DetectorInterface.php`** — `getDetectedLanguages()`, `getCurrentLanguage()`, `getTranslatedPostId($post_id, $lang)`, `getTranslatedPermalink(...)`, `getTranslations($post_id): array` (mass-fetch kõik 3 välja — name, description, url — kasutab catalog-payload'i ehitus)
18. **`includes/Multilingual/WPMLAdapter.php`** — implementeerib interface, konkreetne API:
    ```php
    $languages = apply_filters('wpml_active_languages', null);
    $translated_id = apply_filters('wpml_object_id', $post_id, 'product', false, $lang_code);
    $permalink = apply_filters('wpml_permalink', get_permalink($translated_id), $lang_code);
    ```
19. **`includes/Multilingual/PolylangAdapter.php`** — konkreetne API:
    ```php
    $languages = pll_languages_list();
    $translated_id = pll_get_post($post_id, $lang);
    $permalink = get_permalink($translated_id);
    ```
20. **`includes/Multilingual/TranslatePressAdapter.php`** — `trp_get_url_for_language($url, $lang_code)`
21. **`includes/Multilingual/SiteLocaleAdapter.php`** — fallback, üks-keelne, kasutab `get_locale()` + tagastab `default` mapping'i
22. **`includes/Multilingual/DetectorFactory.php`** — adapter-resolution järjekorras: WPML (`defined('ICL_SITEPRESS_VERSION')`) → Polylang (`function_exists('pll_languages_list')`) → TranslatePress (`function_exists('trp_get_url_for_language')`) → SiteLocale
23. **`includes/Multilingual/Router.php`** — `MultilingualRouter::resolveWorkflowId($trigger, $language, $mode)` — valib õige workflow-ID `smly_plus_automation_mapping`-tabelist `multilingual_mode`-i põhjal

**Implementatsiooni-noot**: konkreetsed API-näited PLUGIN_IMPLEMENTATION_WP.md §"Multilingual support" sees, copy-paste-able.

**Hooks-osa (sub-PR 5):**

24. **`includes/Integrations/WooCommerce/Hooks.php`** — kõik hook-callbackid:
    - `user_register` → contact.sync + (kui welcome aktiveeritud) automation.welcome
    - `woocommerce_created_customer` → sama
    - `profile_update`, `woocommerce_save_account_details` → contact.sync
    - `woocommerce_checkout_order_processed` → automation.first_order (kui esimene tellimus) + **order-meta cookie-salvestus** (`_smaily_anon_session_id`, `_smaily_visitor_token`, `_smaily_rec_id`, `_smaily_rec_ctx` order-meta'sse, et hiljemates kontekstides oleksid kättesaadavad — vt PLUGIN.md §9)
    - `woocommerce_order_status_changed` → (Faas 3-s rec-engine)
    - `save_post_product`, `before_delete_post` → (Faas 3-s rec-engine)
25. **Action Scheduler jobs** registreeritakse koos **deduplication-check'iga** (vt PLUGIN.md §11):
    ```php
    if (as_next_scheduled_action('smly_plus_sync_event', $hook_args)) {
        return;  // Already queued
    }
    as_enqueue_async_action('smly_plus_sync_event', $hook_args, 'smaily-connect');
    ```
    - `smly_plus_flush_event_queue` (iga 60s)
    - `smly_plus_retry_failed_events` (iga 5 min)
    - `smly_plus_contact_sync` (daily)
    - `smly_plus_abandoned_cart` (iga 15 min, migreeritud upstream WP-Cron-ist)
26. **Olemasolev abandoned-cart-loogika** migreeri WP-Cron'ist Action Scheduler'isse — säilita käitumine, vahetada ainult scheduler

**Settings UI (sub-PR 6):**

27. **`includes/Settings/Controller.php`** — registreerib admin-menu, render'b olemasolevat Smaily Connect Settings-vaadet
28. **Olemasolev Settings-vaade säilib** (WP form-stiil), aga lisada **uus väli**: "Multilingual mode" (A/B/C) — kui multi-language detekteeritud. Mode A puhul: dünaamilised credential-set'id per language.
29. **Olemasolev Smaily-API kasutaja-tundmine** säilib

**Tools (sub-PR 7):**

30. **`includes/REST/TestConnectionEndpoint.php`** — `/wp-json/smaily-connect/v1/test-smaily` (vajab nonce + manage_options capability)
31. **`includes/REST/BackfillEndpoint.php`** — `/wp-json/smaily-connect/v1/backfill/start`, `/status`, `/cancel`

### 3.2. Acceptance criteria Faas 1 lõpus

**Funktsionaalsus:**

- [ ] Plugin aktiveerub puhtas WP + Woo paigaldus, ilma errors/warnings (`WP_DEBUG=true`)
- [ ] Olemasolevad Smaily Connect funktsionaalsused (contact sync, kontaktivormid CF7+Elementor, subscription form Gutenberg block) töötavad muutusteta — regression-test
- [ ] Settings'is saab sisestada Smaily credentialid + multilingual mode (kui multi-lang)
- [ ] Test connection nupp kutsub `/api/account/`, näitab tulemust
- [ ] Backfill saab käivitada Settings'ist — 100-batch'id, progress-bar, idempotent re-run
- [ ] Welcome automation käivitub `user_register`-il, **multilingual** (Mode B testitud ET + EN keelega)
- [ ] First-order automation käivitub esimese order-i puhul, mitte teise
- [ ] Abandoned cart cron töötab Action Scheduler'ist, mitte WP-Cron'ist
- [ ] HPOS-deklareeritud, kõik order-päringud läbi `wc_get_order()` / `wc_get_orders()`

**Code quality:**

- [ ] PCP läbib roheliselt (`wp plugin check smaily-connect`)
- [ ] PHPCS 0 violations
- [ ] PHPStan level 6 0 errors
- [ ] PHPUnit jookseb läbi, kriitilised klassid >= 70% coverage:
  - `Multilingual\Router`
  - `Smaily\EventQueue`
  - `Smaily\AutomationRouter`
  - `Smaily\BackfillJob`
  - `DB\Migrator`
- [ ] GitHub Actions CI roheline (PHP 8.0/8.1/8.2, WP 6.2/latest, Woo 7/latest)

**Manual review checklist:**

- [ ] Plugin install + activate + deactivate + uninstall (kõik puhas)
- [ ] Backfill 100 test-kontakti — kõik jõuab Smaily-sse, idempotent re-run
- [ ] Mode B test: ET + EN kasutajad, eraldi workflow'd Smaily-s, õige workflow käivitub
- [ ] Action Scheduler admin UI näitab plugin-job'e, kõik success-staatusega
- [ ] Olemasolev abandoned-cart workflow käivitub 15 min cron-i järel
- [ ] CF7 olemasoleva integration ei murdunud (test üks vorm)
- [ ] Elementor subscription form widget töötab

**Mida selles faasis ei kata:**

- React UI (Faas 2)
- Rec-engine integration kogu (Faas 3)
- Notifications-süsteem (Faas 4 — vahepeal lihtsalt `error_log()`)
- Event Log vaade (Faas 4)
- Tõlgete täielikkus (Faas 4 — vahepeal hooks ainult, `__()` kõik UI-tekst)
- WordPress.org marketplace-readiness (Faas 4)

---

## 4. Faas 2 — React Settings + Wizard UI

**Eesmärk**: Vahetada Faas 1-s säilitatud WP form-stiilis Settings-vaade Tailwind-styled React-bundle'ile, mis taaskasutab prototüübi komponente. Lisada täisarvelist wizard-flow (6 step). Settings ja Wizard kasutavad **sama React-komponente** (`inSettings` prop) — SUGGESTION.md §1 kriitiline arhitektuurne otsus.

**Eeldatav kestus**: 4-5 päeva
**Branch**: `feat/phase-2-react-ui`
**Eeldused**: STYLE_MAPPING.md valmis (Erkki annab Faas 1 ajal paralleelselt)

### 4.1. Mida ehitada

**Build setup (sub-PR 1):**

1. **`package.json`** — React 18, Vite, Tailwind 3, lucide-react, ja prototüübi-vajalikud
2. **`vite.config.js`** — kaks entry-point'i (`admin/settings`, `admin/wizard`), output `dist/admin/`
3. **`tailwind.config.js`** — Smaily design tokens STYLE_MAPPING.md järgi
4. **`postcss.config.js`** — Tailwind + autoprefixer
5. **`assets/fonts/`** — bundled font-failid (Inter `.woff2` 4 weighti, vt STYLE_MAPPING.md)
6. **`admin/src/index.tsx`** — mount detection, renderdab `<Settings>` või `<Wizard>` `data-view`-atribuudi põhjal

**TypeScript types (sub-PR 2):**

7. **`admin/src/state/types.ts`** — kõik state-tüübid (WizardState, AutomationState, BackfillState, jne) prototüübi `wizardReducer`-ist tuletatuna
8. **`admin/src/state/wizard-reducer.ts`** — TypeScripti port prototüübi reducer-ist
9. **`admin/src/state/settings-reducer.ts`** — taaskasutab wizard-reducer'i action-tüüpe, eraldi initial state (server-loaded)

**Primitives komponendid (sub-PR 3):**

10. **`admin/src/components/primitives/`** — Button, Input, Select, Checkbox, Toggle, Card, Banner, Pill, PillTabs, ProgressBar, Radio, NumberInput
    - Inline-stiilid prototüübist → Tailwind utility-klassid + `cn()` helper
    - Hover/focus states → Tailwind `hover:`, `focus-visible:` variantsid
    - Tokens'id → Tailwind config'st (`text-primary`, `bg-brand`, jne)

**Wizard shell (sub-PR 4):**

11. **`admin/src/components/wizard/StepRail.tsx`** — vasak sidebar
12. **`admin/src/components/wizard/WizardFooter.tsx`** — back/continue/finish nupud
13. **`admin/src/components/wizard/MultilingualModePicker.tsx`** — 3-radio-card valik

**Step-komponendid (sub-PR 5):**

14. **`admin/src/components/steps/Step1Connect.tsx`** — Smaily credentials + multilingual mode + (optional) rec-engine setup-token (UI olemas, hook'itud Faas 3-s)
15. **`admin/src/components/steps/Step2Subscribers.tsx`** — field-grid, opt-in toggleid, backfill panel
16. **`admin/src/components/steps/Step3WooCommerce.tsx`** — 3 sektsiooni (welcome / first_order / abandoned_cart) multilingual-aware
17. **`admin/src/components/steps/Step4Recommendations.tsx`** — 4a/4b dual variant
18. **`admin/src/components/steps/Step5Integrations.tsx`** — IntegrationCard'id
19. **`admin/src/components/steps/Step6Done.tsx`** — kokkuvõte + linkid

**Settings (sub-PR 6):**

20. **`admin/src/components/settings/index.tsx`** — peamine Settings-komponent, taaskasutab Step-komponente `inSettings`-propiga (SUGGESTION.md §1 mustriga)
21. **Tab'id**: Connection, Subscribers, WooCommerce, Recommendations, Integrations + Event Log placeholder (Faas 4-s täidetakse)

**API wrappers (sub-PR 7):**

22. **`admin/src/api/`** — TypeScripti wrapperid:
    - `testConnection.ts` (Smaily + rec-engine)
    - `startBackfill.ts`, `getBackfillStatus.ts`
    - `getWorkflows.ts` (Smaily autoresponder list)
    - `saveSettings.ts`
    - Iga wrapper kasutab `wp.apiFetch`-i (nonce + REST URL automaatselt)
23. **Custom hooks** `admin/src/hooks/`:
    - `useTestConnection`
    - `useBackfillProgress` (polling 5s wizard'is, 30s Settings'is)
    - `useWorkflows` (cache'itud, manual refresh)

**PHP-poolne mount + ENV-detection (sub-PR 8):**

24. **`admin/settings.php`** — registreerib admin-menu, enqueue's bundle'i, väljastab `<div id="smaily-connect-app" data-view="settings" data-env="...">`
25. **`admin/wizard.php`** — sama wizard'ile, `data-view="wizard"`. Linked Settings'i ülal "Re-run setup wizard" nupult
26. **`includes/Wizard/EnvDetector.php`** — koondab andmed, mis lähevad `data-env`-i:
    - Detected languages (Multilingual mooduli kaudu)
    - Elementor present (`did_action('elementor/loaded') > 0`)
    - CF7 present (`class_exists('WPCF7')`)
    - Store totals (`count(get_users())`, `wc_get_orders([...])`, `wp_count_posts('product')`)
    - Connection statuses (read from options)
    - Multilingual mode (saved value)

### 4.2. Stiilide migratsioon (kriitiline)

Vt SUGGESTION.md §2 detailne juhend. Põhi-idee: prototüübi `t.c.brand` → Tailwind `bg-brand` (config'is `colors.brand.DEFAULT = '#E91E63'`). Iga inline `style={{...}}` → Tailwind utility-klassid + tingimuslik `cn()` helper.

**STYLE_MAPPING.md** annab täpse mapping-tabeli iga prototüübi-tokeni → Tailwind config'i väärtus.

### 4.3. Acceptance criteria Faas 2 lõpus

**Funktsionaalsus:**

- [ ] Wizard avab WP-admin → "Smaily Connect" menüü → "Setup Wizard"
- [ ] Wizard läbib end-to-end: 6 stepi, kõik valikud salvestatakse `wp_options`-i
- [ ] Step 4 4b-variant (rec-engine ühendamata) kuvatakse marketing-sisuga
- [ ] Step 4 4a-variant **ei testita** (rec-engine endpoint pole veel implementeeritud — Faas 3)
- [ ] Settings ülal `data-view="settings"`, 5 tab'i kuvatakse, sama komponendi-treega
- [ ] Settings → Wizard üleminek säilitab state'i (mode-vahetus näiteks)
- [ ] Mode A test: kaks Smaily-konto credentials'i, mõlemad valideeritud, mode change A→B säilitab "Default account"
- [ ] Backfill progress live'is wizard'is (polling 5s)
- [ ] Konsoolis 0 errors/warnings React-rendering ajal

**Code quality:**

- [ ] Kõik primitives komponendid kasutavad Tailwindit (mitte inline-stiilid)
- [ ] TypeScripti `strict: true`, 0 errors
- [ ] Geist/Inter font'id bundled `assets/fonts/`, MITTE Google Fonts CDN
- [ ] Vite-build < 250KB gzipped (Wizard + Settings koos)

**Manual review checklist:**

- [ ] Wizard 6 step + Settings 5 tab'i visuaalselt sarnased prototüübile
- [ ] Stiili-Smaily-ühildumine (mis täpsemalt — vajab Erkki visuaalne kontroll STYLE_MAPPING.md järgi)
- [ ] Mobiil-responsiivsus: kuni 768px läheb mõistlikult (mitte täielik mobile-first, aga ei murdunud)
- [ ] Kõik formide submit'id kasutavad WP-nonce'i
- [ ] Browser DevTools: 0 console errors, 0 warnings, 0 network failures (kui mitte rec-engine-nullide pärast)

**Mida selles faasis ei kata:**

- Rec-engine API endpoints (Faas 3)
- Beacon JS (Faas 3)
- Identity-merge (Faas 3)
- GDPR endpointid (Faas 3)
- Event Log full sisu (Faas 4 — placeholder UI ainult)
- Notifications email-osa (Faas 4)
- Tõlgete täielikkus (Faas 4)

---

## 5. Faas 3 — Rec-engine integration

**Eesmärk**: Implementeerida `RECENGINE_API_CONTRACT.md` v1.0 kõik 10 endpointi plugin-pool. Beacon JS + server-side proxy. Identity-merge kolme triggeriga. GDPR-endpointide WP integratsioon. Step 4 4a aktiivne (rec-engine ühendatud).

**Eeldatav kestus**: 5-7 päeva
**Branch**: `feat/phase-3-rec-engine`
**Eeldused**: Faas 2 lõpetatud + RECENGINE_API_CONTRACT.md v1.0 olemas + production engine_base_url valmis (või Vercel preview)

### 5.1. Mida ehitada

**Client + Setup (sub-PR 1):**

1. **`includes/RecEngine/Client.php`** — kõik 10 endpoint-call'i, Bearer-auth, retry-logic
   - 4xx (validation/auth/not-found): ei retry
   - 429: austa `Retry-After`, exponential backoff (1s, 2s, 4s, 8s, 16s, max 5 katset)
   - 5xx: exponential backoff
   - 401 `api_key_revoked`: ära retry, märgi notification `critical`
2. **Setup-token exchange**: `POST {engine_base_url}/setup/exchange` koos `plugin_info` payload'iga (`name: "smaily-connect"`, `version: "2.0.0-beta.1"`)
3. **Krüpteeritud API-key salvestus** `wp_options`-i `autoload=false`-iga
4. **Config-salvestus** (cookie nimed, URL-parameetrite nimed, rate-limits) — kõik tuleb setup-vastusest, salvestatakse koos
5. **`X-Engine-Version` parsing** — võrdle plugin'i `compatible_engine_version_range`-iga, kui out-of-range: notification `error` (major) või `warning` (minor)
6. **`request_id` logging** — iga error-response'i `request_id` admin notice'isse + Event Log'i

**Cookies + URL-param capture (sub-PR 2):**

7. **`includes/RecEngine/CookieManager.php`** — 5 cookie haldus (vt PLUGIN.md §10)
   - `smaily_rec_uid`, `smaily_anon_sid`, `smaily_rec_id`, `smaily_rec_ctx`, `smly_btok`
   - Cookie nimed **config'st** (mitte hardcode)
   - Set: HttpOnly=false, Secure=true, SameSite=Lax, Path=/
8. **`includes/RecEngine/URLParamCapture.php`** — `template_redirect` hook:
   - Detekteeri `smaily_vt`, `smaily_rec`, `smaily_ctx` URL-ist
   - Verifitseeri `smaily_vt` HMAC-allkiri (rec-engine pub-key setup-vastusest)
   - Set cookies'd, strip params URL-ist `wp_safe_redirect`-iga

**Event queue + dependency (sub-PR 3):**

9. **`includes/RecEngine/EventQueue.php`** — durable queue, Action Scheduler'iga
   - API: `enqueue($event_type, $entity_id, $payload, $depends_on = null)`
   - **Dependency-loogika** (vt PLUGIN.md §11 event_dependency):
     - Status `blocked` kui `depends_on_event_id` viidatud event ei ole `sent`
     - Flush-job kontrollib dependency-staatust enne saatmist
     - Cascade failure: parent `failed` → child `failed` (`dependency_failed`)
10. **Action Scheduler jobs registreeritakse:**
    - `smly_rec_flush_event_queue` (iga 60s)
    - `smly_rec_retry_failed_events` (iga 5 min)

**Catalog / Customers / Orders hooks (sub-PR 4):**

11. **`includes/Integrations/WooCommerce/RecEngineHooks.php`**:
    - `save_post_product` → catalog.upsert (variable products: iga variant eraldi)
    - `before_delete_post` (kui product) → catalog.delete
    - `woocommerce_created_customer`, `user_register`, `profile_update`, `woocommerce_save_account_details` → customer.upsert
    - `woocommerce_checkout_order_processed` (HPOS-aware!) — kaks asja:
      1. **Salvesta 4 küpsist order-meta'sse** (`_smaily_anon_session_id`, `_smaily_visitor_token`, `_smaily_rec_id`, `_smaily_rec_ctx`) — küpsised pole hilisemates kontekstides saadaval, vt PLUGIN.md §9
      2. Enqueue customer.upsert (kui uus) + order.created (with `depends_on` customer)
    - `woocommerce_order_status_changed` → order.updated
    - **Attribution payload** order-eventidesse: loe 4 väärtust **order-meta'st** (mitte küpsisest — order-status-change võib käivituda admin-poolt päevi hiljem, küpsised on kadunud)
12. **SKU-validation** product-event'idele: tühi SKU → skip + notification `warning`
13. **Multilingual catalog**: `name`, `description`, `product_url` object-formaadis Multilingual mooduli kaudu (Faas 1 sub-PR 4 laiendab `getTranslations($post_id)` meetodit)

**Backfill rec-engine'i poole (sub-PR 5):**

14. **`includes/RecEngine/BackfillJob.php`** — 3 jobi-tüüpi (orders, customers, products)
    - Batch 100, AS-iteration 30s vahedega rate-limit'i vältimiseks
    - Idempotent: SKU/email/order_id alusel, UPSERT mootori-poolelt
    - `smly_rec_backfill_orders`, `smly_rec_backfill_customers`, `smly_rec_backfill_products` Action Scheduler jobs
15. **Combined progress UI** Settings-Recommendations tabis: kolm progress-bari, üks "Start backfill" nupp käivitab kõik 3

**Beacon JS + server-side proxy (sub-PR 6):**

16. **`public/js/beacon.js`** — client-side tracker:
    - Detekteerib page-type'i (product, category, search, cart)
    - Saadab event'e WP REST endpoint'ile (`/wp-json/smaily-connect/v1/beacon`)
    - `navigator.sendBeacon()` primary, `fetch(... {keepalive: true})` fallback
    - 30s aknas client-side batching (mitte üksik event per page-view)
    - `event_id` UUID iga eventi
    - Loeb `smaily_anon_sid` cookie'st session_id
    - Identifeeritud kasutaja puhul (logged in) lisab email payloadi
17. **`includes/REST/BeaconEndpoint.php`**:
    - POST endpoint, public (`__return_true` permission), aga **origin validation** (CORS-style)
    - Decode + validate payload
    - Lisa transient-buffer'isse (mitte DB-tabelisse — vt PLUGIN.md §7)
18. **`includes/RecEngine/BeaconBuffer.php`** — transient API, 30s flush'iga:
    - `smly_rec_flush_browse_buffer` AS-job iga 30s
    - Loeb transient, saadab batch (kuni 100) rec-engine'ile `POST /api/v1/ingest/browse`
    - Failed batchid: 1-2 retry, siis drop (mitte durable queue — vt PLUGIN.md §3)

**Koodinäited**: PLUGIN_IMPLEMENTATION_WP.md §"Browse-events beacon-proxy" sisaldab täielikku JS-koodi (UUID-genereerimine, sendBeacon + fallback) ja PHP-koodi (BeaconEndpoint + flush handler). Kasuta neid baasiks, kohenda paigutust (`includes/REST/` mitte `src/Rest/`) ja namespacing-i (`Smaily\Connect\` mitte `Smaily\RecEngine\`).

**Identity-merge (sub-PR 7):**

19. **`includes/RecEngine/IdentityMerger.php`** — 3 trigger-tüüpi:
    - `wp_login`, `user_register`, `woocommerce_created_customer` → source='login'/'register'
    - `woocommerce_checkout_order_processed` → source='checkout'
    - `template_redirect` URL-param capture → source='email_link'
20. **Dependency-handling**: identity.merge event sõltub customer.upsert event'ist sama email-iga. Vt event_dependency mehhanism.
21. **`smly_rec_visitor` tabel** uuendatakse (anon → identified mapping local-side)

**Koodinäited**: PLUGIN_IMPLEMENTATION_WP.md §"Identity merge triggers" näitab `wp_login` + `woocommerce_checkout_order_processed` registreerimist. Meie versioonis **lisandub** template_redirect URL-param capture'ist 3. trigger (mootori dokumendis pole) — vt PLUGIN.md §10.

**GDPR endpointid (sub-PR 8):**

22. **`includes/RecEngine/GDPRHandler.php`** — WP Privacy API integration:
    - `wp_privacy_personal_data_eraser_handler` → `DELETE /api/v1/customer/{email}` (idempotent — 404 OK)
    - `wp_privacy_personal_data_exporter_handler` → `GET /api/v1/customer/{email}/export` (vastus ZIP-i)
23. **"My Account" → "Privacy" toggle** "Don't use my data for recommendations":
    - WP user-meta `_smly_rec_opt_out`
    - Toggle change → `POST /api/v1/customer/{email}/opt-out`

**Koodinäited**: PLUGIN_IMPLEMENTATION_WP.md §"GDPR integration" näitab `wp_privacy_personal_data_exporters` ja `wp_privacy_personal_data_erasers` filter-registreerimist, response-format konversiooni WP-eksport-format'i.

**Step 4 4a aktiveerimine (sub-PR 9):**

24. **Step 4 React-komponendis** (`admin/src/components/steps/Step4Recommendations.tsx`):
    - Connect-status check: kui setup-token vahetatud, näita 4a
    - Backfill panels: hookida WP REST endpointidele
    - Browsing toggle: aktiveeritakse → consent-detect kuvatakse + beacon JS hakkab tööle
25. **Cookie consent integration**:
    - WP Consent API: kontrollib `wp_has_consent('marketing')` enne beacon-skripti enqueue'i
    - Cookiebot/Complianz/CookieYes detect: kui consent puudub, beacon ei käivitu

### 5.2. Acceptance criteria Faas 3 lõpus

PLUGIN.md §15 testid 1-19 läbivad:

- [ ] Test 1 — plugin aktiveerub HPOS-iga
- [ ] Test 2 — Wizard Step 1 setup-token exchange õnnestub, "Connected to tenant" kuvatakse
- [ ] Test 3 — kontaktide backfill jõuab Smaily-sse
- [ ] Test 4 — Step 3 multilingual workflow-mappings salvestuvad
- [ ] Test 5 — rec-engine backfill (3 data-tüüpi) jõuab mootori
- [ ] Test 6 — integration links töötavad in-window
- [ ] Test 7 — Step 6 kokkuvõte näitab kõik aktiveeritud
- [ ] **Test 8** — Welcome workflow käivitub korrektse keelega
- [ ] **Test 9** — First-order workflow käivitub esimese tellimuse puhul, mitte teise
- [ ] **Test 10** — Abandoned cart event jõuab rec-engine'i + workflow Smaily-s käivitub
- [ ] **Test 11** — Browsing events 30s aknas + identity.merge login'il
- [ ] **Test 12** — Email-link merge (cross-device): smaily_vt URL → cookie + merge event
- [ ] **Test 13** — Product update event 60s jooksul rec-engine'is
- [ ] Test 14 — Mode B → A vahetus
- [ ] **Test 15** — GDPR DELETE jõuab rec-engine'i
- [ ] **Test 16** — Engine version mismatch admin notice
- [ ] **Test 17** — Failure test (firewall) retry + recovery

**Code quality**: PCP, PHPCS, PHPStan, PHPUnit kõik rohelised. Coverage'i lisad:

- `RecEngine\Client` >= 70% (sh retry-paths, error-handling)
- `RecEngine\EventQueue` >= 70% (sh dependency-loogika)
- `RecEngine\IdentityMerger` >= 70% (3 source'i)
- `RecEngine\BeaconBuffer` >= 70%
- `RecEngine\GDPRHandler` >= 70%

**Manual review checklist:**

- [ ] Browseri DevTools network-tab: beacon-requests jõuavad WP REST-i, mitte otse rec-engine'i (server-side proxy turvalisus)
- [ ] Email-link merge: tee päris-test 2 brauseriga (anonüümne + sisselogitud), kontrolli, et merge töötab
- [ ] Cookie consent: testitada Cookiebot või Complianz-iga (kui pilot kasutab)
- [ ] Action Scheduler admin: kõik plugin-jobid jooksevad ilma errors'ita

**Mida selles faasis ei kata:**

- Event Log full UI (Faas 4)
- Notifications-süsteem täielikult (Faas 4 — vahepeal admin-notice'd ad-hoc)
- Tõlgete täielikkus (Faas 4)
- Marketplace readme.txt (Faas 4)
- Performance optimisations (kui PLUGIN.md §15 test #19 läbib, OK)

---

## 6. Faas 4 — Polish + Event Log + marketplace prep

**Eesmärk**: Lõpetada kõik MVP-pinged. Plugin valmis pilootkliendi pikemaks kasutamiseks ja **valmistuda upstream-merge'iks**, mis vajab WP marketplace KK läbimist.

**Eeldatav kestus**: 3-4 päeva
**Branch**: `feat/phase-4-polish-marketplace`

### 6.1. Mida ehitada

**Event Log (sub-PR 1):**

1. **`includes/REST/EventsEndpoint.php`** — `/wp-json/smaily-connect/v1/events`:
   - Paginated (50/page)
   - Filter: event_type, status, source
   - UNION `smly_plus_event_queue` + `smly_rec_event_queue`
   - Single-event drill-down: full payload, error, retry-history
2. **`includes/REST/EventsRetryEndpoint.php`** — `POST /events/{id}/retry`
3. **`admin/src/components/settings/EventLogTab.tsx`** — TanStack Query, tabel + filtrid + drill-down modal
4. **"Export failed as CSV"** — server-side endpoint, streamib CSV-failina
5. **Sticky failure banner** — kui failed count > 0 last 24h

**Notifications-süsteem (sub-PR 2):**

6. **`includes/Notifications/NotificationManager.php`** — full implementation per PLUGIN.md §13a
7. **`includes/Notifications/AdminNoticeRenderer.php`** — `admin_notices` hook, dismissible/sticky
8. **`includes/Notifications/EmailSender.php`** — `wp_mail()` + template loading
9. **`includes/Notifications/Throttler.php`** — `wp_options`-i singleton 24h-window
10. **`templates/email/*.html` + `.txt`** — 4 severity-mall (info/warning/error/critical)
11. **Settings → Notifications subpanel** — toggleid + test-email nupp
12. **Health-check cron** (`smly_health_check` AS, iga tund):
    - Test Smaily connection
    - Test rec-engine connection
    - Count failed events last 24h
    - Trigger notification kui probleemid

**i18n full pass (sub-PR 3):**

13. **`wp i18n make-pot`** — genereeritud `languages/smaily-connect.pot`
14. **ET tõlge täielik** — `languages/smaily-connect-et.po` + `.mo`
15. **EN tõlge täielik** — `languages/smaily-connect-en.po` + `.mo` (kui vajalik — fallback default)
16. **React i18n**: `wp.i18n.__('...', 'smaily-connect')` kõikjal, `wp-i18n` dependency
17. **Build-script**: `npm run i18n:extract` ekstraheerib React-bundle'ist string'd `.pot`-i

**Marketplace prep (sub-PR 4):**

18. **`readme.txt`** WP-stiil:
    - Plugin name, contributors, tags
    - Stable tag, requires PHP, requires WP, tested up to
    - Short + long description
    - Installation steps
    - FAQ
    - Screenshots (sama kui assets/screenshots-1.png, -2.png, ...)
    - Changelog
    - Upgrade notice
19. **Screenshots** `assets/screenshots-1.png` ... `-5.png` — wizard step'id + Settings
20. **`uninstall.php`** — kui Settings-toggle "Remove all data on uninstall" sees:
    - DROP tabelid (5 plugin-tabelit)
    - DELETE FROM wp_options WHERE option_name LIKE 'smly_%'
    - Delete user-meta `_smly_*`
    - Cancel kõik AS-jobid
    - Vaikimisi: toggle väljas, säilita andmed
21. **Final PCP pass**: `wp plugin check smaily-connect` 0 errors, 0 warnings
22. **Performance audit**:
    - 5000-kasutaja backfill < 30 min (PLUGIN.md test #19)
    - 100-product upsert < 5s
    - Beacon round-trip < 100ms
23. **Security audit** (käsitsi-checklist):
    - Kõik AJAX-endpointid nonce + capability
    - Kõik user-input sanitize'itud
    - Kõik output escape'itud
    - API-keys krüpteeritud `wp_options`-is
    - Cookies SameSite/Secure korrektsed
    - SQL: ükski raw query, kõik `$wpdb->prepare()` või abstractions

### 6.2. Acceptance criteria Faas 4 lõpus

**Funktsionaalsus:**

- [ ] Event Log töötab täielikult — kõik filtrid, drill-down, retry, CSV export
- [ ] Notifications: kõik 4 severity-tasandit testitud, email throttling toimib
- [ ] "Send test email" Settings'is töötab
- [ ] ET + EN tõlked täielikud, kasutaja-keele detect toimib
- [ ] WordPress.org Plugin Check 0 errors, 0 warnings
- [ ] readme.txt valmis, kõik sektsioonid täidetud
- [ ] Uninstall kustutab kõik andmed (toggle sees) või säilitab (toggle väljas)

**Code quality:**

- [ ] PCP roheliselt
- [ ] PHPCS 0 violations
- [ ] PHPStan level 6 0 errors
- [ ] PHPUnit jookseb läbi, kogu plugin-coverage >= 60%
- [ ] CI roheline kõikidel matrix-tasanditel

**Manual review final checklist:**

- [ ] PLUGIN.md §15 kõik 19 testi rohelised
- [ ] Pilot-klient saab plugin'i ZIP-i, install + activate töötab puhtas keskkonnas
- [ ] Plugin BETA-faasis distribueeritav GitHub Release-na
- [ ] Upstream-merge'i ettevalmistus: PR-draft `sendsmaily/smaily-wordpress-plugin` peale, diff vaadeldav (Smaily tiim review'b hiljem)

---

## 7. Inter-phase dependencies + risk register

### 7.1. Sõltuvused

| Faas | Sõltub eelmistest | Sõltub väliselt |
|------|-------------------|------------------|
| 1 | — | PHP 8.0+, Composer, WP 6.2+ test-env |
| 2 | Faas 1 (PHP scaffold) | STYLE_MAPPING.md (Erkki kirjutab Faas 1 ajal) |
| 3 | Faas 2 (React UI) | RECENGINE_API_CONTRACT.md v1.0 (olemas), production engine_base_url (TBD) |
| 4 | Faas 3 (kõik featuurid) | Smaily tiimi PR-review valmidus (upstream-merge) |

### 7.2. Riskid

| Risk | Tõenäosus | Mõju | Mitigatsioon |
|------|-----------|------|--------------|
| Upstream Smaily Connect koodi-baasi-muudatused konfliktivad fork-i muudatustega | Madal | Keskmine | Faas 1 alguses rebase upstream `main`-st. Edasi: peri'odiline upstream-rebase iga faasi alguses. |
| WPML/Polylang API muudatus mootor-side | Madal | Madal | Adapter-pattern isoleerib mõju ühte klass'i. |
| Production engine_base_url muudatus mid-development | Keskmine | Madal | Plugin loeb URL-i config'st, mitte hardcode. Klient teeb uue setup-token exchange'i. |
| Pet-piloodi WC-versioon < 8.2 (pre-HPOS) | Madal | Keskmine | Plugin **kohustab** WC 7.0+, soovitab 8.2+. HPOS deklareeritud, aga kasutab abstractions'eid. Pre-HPOS töötab automaatselt. |
| Smaily marketplace-tiim ei aktsepteeri PR-i | Madal | Suur | BETA-faasis hoiame distrubutsiooni GitHub-Release-il. Pilot pole sõltuv marketplace-merge'st. |
| Code-agent jooksub korruptsioonisse pikas tsüklis | Keskmine | Keskmine | Hybrid-rütm (faasi-haaval review). Erkki vaatab üle PR enne järgmise faasi alust. |

---

## 8. Esimese faasi kick-off (Code-agendi esimene tegevus)

Lugemise järjekord:

1. **PLUGIN.md** v0.5 — kogu funktsionaalne spec
2. **SUGGESTION.md** — prototüübi-üleandmise juhend
3. **RECENGINE_API_CONTRACT.md** v1.0 — rec-engine API
4. **smaily-connect-plus-wizard.jsx** — UX-mustand
5. **See dokument** (PROJECT_PLAN.md) — faaside-plaan

**Esimene tegevus**: kirjuta Erkkile **Faas 1 plaan** (1-2 lõiku) selle kohta, mida kavatsed teha sub-PR-i kaupa, mis järjekorras, mis on esimese sub-PR scope. Erkki kinnitab või parandab. Siis alusta.

**Kasuta** olemasolevat upstream-koodi maksimaalselt — fork-strateegia tähendab, et vana kood on **väärtuslik baas**, mitte hädavajalik asendamine. Säilita CF7/Elementori integratsioonid, säilita olemasolev Smaily-API klass, lisa peale. Faas 1 ei alusta nullist.

**Küsi varakult**, kui:
- Olemasolev kood on segane ja sa ei ole kindel, kas refactorida või jätta
- DB-skeem PLUGIN.md §7-st tundub kohmakas — võibolla on parem viis
- Action Scheduler integreerimine olemasoleva WP-Cron-loogikaga tekitab konflikti
- Mis iganes muu **arhitektuurne** küsimus

**Ära küsi**, kui:
- Sa tead juba vastust mõistlikust PHP-praktikast (näit "kas kasutada `final` klass'e?")
- See on style-küsimus, mis ei mõjuta käitumist
- See on triviaalne nimetamis-otsus

---

## 9. Faasidevaheliste kokkuvõtete formaat

Iga faasi PR'i Erkkile review'le saates, kirjuta ka kommentaaridesse:

```markdown
# Faas X kokkuvõte

## Status: VALMIS / OSALISELT VALMIS

## Mis tehti
[Lühike loend]

## Mis testitud (Auto)
- PCP: ✓/✗
- PHPCS: ✓/✗ (X violations)
- PHPStan: ✓/✗ (X errors)
- PHPUnit: ✓/✗ (X% coverage)
- CI: ✓/✗

## Mis testitud (Manual)
[Loend manual testidest]

## Mis EI kata (PLUGIN.md §15 acceptance järgi)
[Konkreetsed punktid, mis on edasi-lükatud + plaan]

## Avatud küsimused enne Faas X+1
1. ...
2. ...

## Soovitused Faas X+1 alguseks
[Kui sa avastasid midagi tööd tehes, mis võiks järgmist faasi mõjutada]
```

---

## 10. Hilis-muudatuste log (faas-haaval)

Otsused, mis on tehtud spec'i v1.1 publitseerimise järel ja mis kõrvalkalduvad literaal-tekstist. Pikemas plaanis liiguvad need järgmise PROJECT_PLAN versiooni põhi-teksti.

### Faas 1 (2026-05-19)

**WorkflowResolverInterface signatuur** (sub-PR 3, commit `7e0e9ba`, kinnitatud sub-PR 4 review'l):

Spec'i §3.1 p23 pakkus:
```php
MultilingualRouter::resolveWorkflowId($trigger, $language, $mode): ?string
```

Implementeeritud kuju:
```php
WorkflowResolverInterface::resolve_workflow(string $trigger_type, ?string $language): ?WorkflowMatch
```

Kolm autonoomset signatuuri-otsust:

1. **snake_case meetodinimed** — kogu uus `Smaily\Connect\*` namespace kasutab WP-konventsiooni snake_case'i (`enqueue`, `trigger_automation`, jne). Kõrvalkalduvus spec'i camelCase'ist ühtsuse pärast.

2. **`?WorkflowMatch` (tagastus) asemel `?string`** — Mode A vajab nii `workflow_id` kui `account_key`'d, et valida õige Smaily credential set. Skalaarse stringi puhul peaks `AutomationRouter` teadma Mode A loogikat ja päringuma settings'i credentials'i jaoks eraldi. Object-tagastus hoiab AutomationRouter'i mode-agnostiliseks.

3. **`$mode` parameetri eemaldus** — Router-i implementatsioon loeb mode-i ise `smly_plus_multilingual_mode` option'ist. Single-source-of-truth — caller-side ei pea iga kutsega mode'i sünk hoidma.

Põhjendus aktsepteeritud Erkki poolt sub-PR 4 review'l (2026-05-19): "Kolm autonoomset signatuuri-otsust on kõik paremad kui mu spec'i pakutud literaal."

**PHPCS baas-ruleset** (sub-PR 1.E, commit `ac2cb2c`):

Spec'i §1.4 pakkus "PSR-12 + WPCS hybrid". Implementeeritud kuju: `WordPress-Core` baas (matching upstream `sendsmaily/smaily-wordpress-plugin`), millele lisanduvad `WordPress.Security` + `WordPress.WP.I18n` + `PHPCompatibilityWP`. Põhjus: upstream-merge-kompatibilsus.  Stricter PSR-12-style reeglid uue koodi jaoks lisanduvad path-spetsiifiliselt, kui vajaduseks on.

**PHPCS cache disabled** (sub-PR 4, commit `93b540d`):

Spec'i ei pakkunud konkreetset cache-strateegiat. Implementeeritud: cache **keelatud** phpcs.xml.dist-is. Põhjus: sub-PR 4 CI-failure näitas, et cache'iga lokaalne "0 errors" ja CI tegelik 18 errors võivad kõrvalkalduda. CI on alati cold, lokaalne peab match'ima.

**Composer platform.php pin** (sub-PR 4, commit `c54af5f`):

Spec'i §1.4 ei spetsifitseerinud composer-resolve-strateegiat. Implementeeritud: `config.platform.php = "8.0.99"` composer.json-i. Põhjus: ilma selleta resolve'iti deps kohaliku PHP versiooni vastu (8.5), mis lock'is PHP 8.4+ pakette → CI matrix PHP 8.0-8.3 failub. Platform-pin hoiab lock-file stabiilsuse kõikide matrix-versioonide vahel.

**`readonly` properties tagasi-lükkamine** (sub-PR 4, commit `c54af5f`):

`Smaily\Connect\Smaily\WorkflowMatch` oli algses kujuga `public readonly` constructor property promotion'iga (PHP 8.1+ feature). Plugin floor on PHP 8.0 (PLUGIN.md "Requires PHP: 8.0"). Implementeeritud: regular `public` properties + body-style constructor. Docblock-mark säilitab semantilist immutability-d. **Tagasi panna kui PHP floor liigub 8.1-le.**

---

**Lõpp**

See plaan on **autoritatiivne suunis Code-agendile**. Otsused, mis siin pole kirjas ja mis ei ole **autonoomia-ulatuse** (vt §1.2) sees, vajavad Erkki kinnitust enne implementatsiooni.

Edu! 🚀
