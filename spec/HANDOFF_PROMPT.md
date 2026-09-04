# Handoff Prompt for Claude Code — Smaily Connect WordPress Plugin

> ⚠️ **SUPERSEDED (pre-Phase-3 briefing).** A fresh agent should read
> **`/CLAUDE.md`** (agent working guide + operational knowledge) and
> **`/STATUS.md`** (current state) at the repo root instead — they are kept
> current. This file is retained for history: its forward-looking design notes
> (attribution, identity-merge, beacon) still live in `spec/PLUGIN.md` and
> `docs/DECISIONS.md`. Do not rely on this file for current status.

**Sa oled Claude Code, implementatsiooni-agent.** Sinu ülesanne on ehitada **Smaily Connect** WordPress plugin (versioon 2.0.0-beta.1, BETA-faas) — fork olemasolevast `sendsmaily/smaily-wordpress-plugin` repositorist, mis lisab rec-engine'i andmevahetuse + onboarding wizard'i + multilingual automation routing'u.

## Lugemise järjekord (kõik dokumendid sees on autoritatiivsed)

Loe enne tööd **selles järjekorras**:

1. **`PROJECT_PLAN.md`** v1.1 — see annab sulle töö-rütmi, faaside-jaotuse, acceptance criteria, autonoomia-ulatuse. **Sinu peamine viide kogu projekti vältel.**
2. **`PLUGIN.md`** v0.6 — funktsionaalne spec, mis ehitada. Tehniline ja täielik.
3. **`RECENGINE_API_CONTRACT.md`** v1.0 — rec-engine API spec (mootori-poolelt). Faas 3-s muutub kriitiliseks, Faas 1-2 vajalik üldteadmiseks.
4. **`SUGGESTION.md`** — prototüübi-üleandmise juhend. Kriitiline §1 (komponentide reuse pattern wizard ↔ settings) — see on Faas 2 arhitektuurne alus.
5. **`smaily-connect-plus-wizard.jsx`** — UX prototüüp ~3940 rida. Faas 2 visuaalne ja loogiline baas.
6. **`STYLE_MAPPING.md`** v1.0 — Tailwind config Smaily-tokenitega. Loe Faas 2 alguses.
7. **`PLUGIN_IMPLEMENTATION_WP.md`** v1.0 — mootori-poolne viitedokument koodinäidetega. **NB: arhitektuurselt mitte autoritatiivne** — kui vastuolu meie PLUGIN.md spec'iga (plugin-nimi `smaily-rec-engine`, paigutus `src/`), meie spec võidab. Kasuta koodinäidete jaoks (WPML/Polylang API-kasutus, beacon JS, EngineClient retry, GDPR-hookid), kohenda paigutust ja namespacing-i meie struktuurile.

## Esimene tegevus

**Mitte alusta kohe koodimisega.** Esimene asi:

1. Loe ülaltoodud dokumendid läbi (~30-45 min)
2. Kirjuta lühike (1-2 lõiku) **Faas 1 plaan**:
   - Millised sub-PR-id sa kavatsed teha ja mis järjekorras
   - Mis on esimese sub-PR scope
   - Kas sul on selguse-küsimusi Erkkile enne, kui alustad
3. Oota Erkki kinnitust või parandust

**Ära alusta scaffold-koodi enne**, kui Erkki on plaani kinnitanud — Faas 1 algus on kriitiline, hilisem korrigeerimine on kallis.

## Repository setup

Repository on Erkki private GitHub'i alla forkitud `sendsmaily/smaily-wordpress-plugin`-st. Sa saad selle ligipääsu Erkki kaudu.

**Branch-strateegia:**
- `main` — Erkki review-line, sa siia ei push'i otse
- `feat/phase-1-scaffold-smaily-core` — Faas 1 töö
- `feat/phase-1-scaffold-smaily-core-pr-1` jne — sub-PR-id Faas 1 sees, merge'itakse Faas 1 branch'i (mitte main'i)
- Faas 1 lõpus: Faas 1 branch → PR `main`-i vastu Erkki review'le

**Commit-strateegia**: Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`). Vt PROJECT_PLAN.md §2.3.

## Töö-rütm (kiire ülevaade)

- **Faas alguses**: 1-2 lõigu plaan → Erkki kinnitab
- **Sub-PR-i kaupa töötamine**: ehita inkrementaalselt, lubad endale ka WIP-commit'eid
- **Sub-PR PR**: review'd Erkki (kui tahad), aga **sub-PR-id ei lähe `main`-i otse**
- **Faasi lõpus**: faasi-branch → PR `main` vastu + lühike kokkuvõte PROJECT_PLAN.md §9 formaadis
- **Erkki kinnitab edasi liikumise järgmisse faasi**

## Autonoomia (kiire ülevaade)

**Sa otsustad autonoomselt:**
- PHP class-hierarhia detailid, kausta-struktuur `includes/`-i sees (ühildades PLUGIN.md §2-ga)
- Composer dependency-versioonid (kui ühildub `>=8.0` PHP, AS `^3.7`)
- PHPCS-ruleset detailid, GitHub Actions workflow-i sisu
- React-komponendi prop-API täpne kuju
- Error-handling konkreetne implementatsioon
- Test-cases'ide nimekiri (kui kaetud PLUGIN.md §15)

**Vajab Erkki kinnitust:**
- UX-otsused, mis ei ole prototüübis kaetud
- API-leping muudatused
- DB-skeema muudatused PLUGIN.md §7-st
- Scope muudatused
- Production-URL vahetus

**Vt PROJECT_PLAN.md §1.2 täielik nimekiri.**

## Kvaliteedinõuded (kiire ülevaade)

Iga PR enne review'le saatmist:
- WordPress Plugin Check (PCP): 0 errors
- PHPCS: 0 violations
- PHPStan level 6: 0 errors
- PHPUnit: kriitilised klassid >= 70% coverage
- GitHub Actions CI roheline (matrix: PHP 8.0/8.1/8.2, WP 6.2/latest, Woo 7/latest)

## Konkreetsed tähelepanekud, mis on hiljutise dialoogi käigus välja tulnud

Need on **olulised detailid**, mis on dokumentides, aga väärivad rõhutamist:

### Fork-strateegia
Plugin **ei alusta tühjast lehelt**. Säilita olemasolev kood:
- CF7 ja Elementori integratsioonid — **ära puutu**, lihtsalt jälgi et need ei murra
- Olemasolev `includes/Smaily/Client.php` — **laienda**, mitte asenda
- Plugin slug `smaily-connect` — **säilib**, see asendab vana versiooni install'imisel
- BETA-versioon `2.0.0-beta.1` — major bump tähistab arhitektuurilist nihet

### Komponentide reuse pattern (Faas 2)
**Kriitiline arhitektuurne otsus**: wizard-step ja settings-tab on **sama React-komponent** `inSettings` propi erinevusega. Vt SUGGESTION.md §1. Mitte tee `<Step1Connect>` ja `<ConnectionTab>` eraldi komponentideks — see kaotab state-sünki.

### URL ja cookie nimed (Faas 3)
**Kõik tulevad config'st**, mitte hardcode. Setup-token exchange response sisaldab `endpoints` ja `config` objekte. Plugin loeb cookie-nime `smaily_rec_uid` jne **mitte** hardcode'itud kuju, **vaid** dünaamiliselt config'st (kuna mootor võib neid muuta).

### Setup-URL filter
Esimese setup-call'i URL (kui veel pole api_key'd) ei tohi olla `private const`-iga jäik. Kasuta `apply_filters('smaily_connect_setup_url', Constants::SETUP_BASE_URL)` — Erkki saab override'ida per-site basis'el.

### Order-meta attribution
`woocommerce_checkout_order_processed` hookis **kohe** salvesta 4 küpsist order-meta'sse (`_smaily_anon_session_id`, `_smaily_visitor_token`, `_smaily_rec_id`, `_smaily_rec_ctx`). Küpsised pole `woocommerce_order_status_completed` ajaks saadaval (admin-poolt päevi hiljem). Vt PLUGIN.md §9.

### Action Scheduler dedupe
`save_post_product` võib käivituda mitu korda samas requesti'is (autosave + manual save). Kasuta `as_next_scheduled_action()` check'i enne `as_enqueue_async_action()`-i. Vt PLUGIN.md §11.

### Identity merge — 3 trigger'it (mitte 2)
Mootori-poolne dokument näitab 2 triggerit (login + checkout). Meil on **3**: login/register, checkout, ja `template_redirect` URL-param capture (`smaily_vt` → identity.merge source='email_link'). See on **cross-device merge** ilma sisselogimata. Vt PLUGIN.md §10.

### Identity merge dependency
`identity.merge` event **sõltub** `customer.upsert` event'ist sama emailiga — mootor tagastab 404, kui customer ei eksisteeri. Kasuta `EventQueue::enqueue($type, $entity, $payload, depends_on: $customer_event_id)` — vt PLUGIN.md §11 event_dependency mehhanism.

### Browse-buffer (transient, mitte DB)
Browse-eventid **ei** kirjuta `smly_rec_event_queue`-tabelisse. Transient buffer + 30s flush — failed batchid drop'itakse. See on **kavatsuslik**, kuna mootori ML on 5-10% kaotuse-tolerantne ja DB-rea-arvu vältimiseks. Vt PLUGIN.md §3.

### Beacon turvalisus
API-key **MITTE KUNAGI** client-side koodis. Beacon JS saadab WP REST endpoint'ile (`/wp-json/smaily-connect/v1/beacon`), server-side proxy lisab Authorization header'i. PLUGIN_IMPLEMENTATION_WP.md §"Browse-events beacon-proxy" näitab täielikku koodi.

### Notifications-süsteem
Faas 4-s täielikult, aga Faas 1-3 vältimiseks segaduse: kasuta `error_log()` ja ad-hoc `add_action('admin_notices', ...)` Faas 1-3 vältel, Faas 4-s **refactori** tervet süsteemi (`NotificationManager`, email-templates, throttling). Vt PLUGIN.md §13a.

### WordPress.org marketplace
Faas 4-s täielikult, aga Faas 1-st alates **jälgi nõudeid**:
- Ei mingit CDN-pärinevat resurssi (Inter font bundle'ima, mitte Google Fonts)
- HPOS-deklareerimine + `wc_get_order()` ainult
- Sanitization (`sanitize_*`) ja escaping (`esc_*`) **alati**
- Nonce + capability check kõikidel admin-actions

Vt PLUGIN.md §14.

## Esimene konkreetne küsimus, mille Erkki sulle eeldab

Faas 1 plaan peaks vastama vähemalt nendele:

1. **Sub-PR jaotus** — kas teed 7 sub-PR (nagu PROJECT_PLAN.md §3.1 pakub) või konsolideerid mõned?
2. **Olemasoleva koodi käsitlus** — kas eelistad enne `composer.json`-i ja autoload-setup'i (mis võib nõuda olemasoleva koodi liigutamist), või säilitad olemasoleva paigutuse ja lisad uusi klasse peale?
3. **PHPCS-ruleset** — kas alustad WPCS-iga ja kohendad mõne reegli (näit `WordPress.NamingConventions.PrefixAllGlobals`), või PSR-12 baasiga ja lisad WPCS-elemendid? Vt PROJECT_PLAN.md kus ma soovitan hybrid'i — sina otsustad konkreetset ruleset-konfi.
4. **Test-environment** — kas kasutad `wp-env`, Docker Compose, või muu WordPress test-keskkonna? Faas 1 CI peab kuskil tegelikult joosta.

Vasta Erkkile nende kohta — peale tema kinnitust alusta sub-PR 1-ga.

---

## Pidev tähelepanu

- **Iga muudatuse ajal mõtle marketplace-kvaliteedile**: sanitization, escaping, nonces, capabilities, i18n. PCP läbiminek on Faas 4-s, aga pinnaga peab algusest alates olema.
- **HPOS** — kõikjal `wc_get_order()`, mitte SQL `wp_posts` vastu. See on tavaline Code-vea allikas WC-koodis.
- **Action Scheduler vs WP-Cron** — alati AS. Kui sa näed kuskil olemasolevas koodis `wp_schedule_event` või `wp_cron_*`, migreeri see AS-ile (välja arvatud kui sa avastad, et see on tahtlikult väljaspool AS-i — siis küsi Erkkilt).
- **i18n** — kogu UI-tekst `__()` / `_e()` / `_n()`-iga. React-bundle'is `wp.i18n.__()`. Text domain `smaily-connect`. Mitte ainult Faas 4 — algusest peale.

---

## Õnnestumiste indikaatorid

Sa tead, et Faas 1 läks hästi, kui:

- Sub-PR-id on väikesed (1-5 faili keskmiselt) ja iga PR on **iseseisev** — võiks revert'ida ilma järgmist katkestamata
- PHPCS jookseb 0 violations'iga **iga commit'iga** (mitte ainult lõpus)
- Manual testimine puhtas WP+Woo install'is näitab, et plugin aktiveerub ilma errorita ja ei murra olemasolevat funktsionaalsust
- Erkki review-arvustused on enamasti minimaalsed (puudutavad style'i, mitte arhitektuuri)

Sa tead, et midagi on valesti, kui:

- Sa tunned, et oled Erkki kontekstist välja läinud — peatu, küsi
- Sa kirjutad spec'iga vastuolus oleva koodi, sest see "tundub mõistlikum" — peatu, sõnasta otsus, küsi
- Sa avastad, et üks otsus, mille tegid algses sub-PR-is, mõjutab kolme hilisemat sub-PR — peatu, raportee Erkkile

---

**Edu! Loe dokumendid läbi ja kirjuta esimese Faas 1 plaani.**
