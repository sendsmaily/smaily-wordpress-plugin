# Smaily Connect — Personal Data Model & GDPR Rights

**Purpose.** A single map of every personal-data element the rec-engine
integration touches: where it lives, which system owns it, and what each GDPR
right (export / erase / opt-out) does to it. This is the input for the 3.8 GDPR
work and the factual basis for the privacy-policy section.

**Scope note.** This document's primary focus is rec-engine personal data.
WooCommerce's own data (orders, addresses, purchase history) is owned and
exported/erased by **WooCommerce's own GDPR tooling**, not this plugin. The
rec-engine plugin must not duplicate or re-export Woo data. One exception,
added PRO-1194: the plugin also stores one local-only PII surface that has
nothing to do with the rec-engine — the abandoned-cart session tracker
(`smly_plus_cart_session`, PRO-1195) — documented in its own subsection below
for completeness, since it otherwise wouldn't appear in any GDPR inventory.

---

## The four systems

Personal data related to recommendations lives across four systems with
different ownership:

- **Engine** — the recommendation engine (separate repo). Holds the rec-specific
  personal data: browse events, visitor tokens, computed recommendations,
  attribution. Owns the §8/§9/§10 GDPR API.
- **Plugin** (this repo) — stores local rec-specific markers in WordPress
  (order-meta, user-meta) that link a WooCommerce shopper to engine records.
- **Smaily** — the marketing platform. Owns **consent**, including the
  **profiling consent** (separate granular parameter) that authorises the
  rec-engine to profile a contact. Plugin reads this back; Smaily is the
  authority.
- **WooCommerce** — owns order/customer commerce data. **Out of scope** for this
  plugin's GDPR handlers (Woo has its own).

---

## Data element inventory

Each row is one personal-data element: where it lives, what it is, and the GDPR
disposition. "Export" = Art 15 (subject access), "Erase" = Art 17, "Opt-out" =
Art 21 (profiling objection).

### Engine-held (rec-specific personal data)

| Element | What it is | Export (Art 15) | Erase (Art 17) |
|---|---|---|---|
| browse_events | Page/product views, cart events tied to a visitor/customer | Yes — personal data | Deleted (CASCADE) |
| visitor_tokens | Anonymous-visitor identifiers | Yes — personal data | Deleted |
| recommendations | Computed product recs surfaced to the customer | Yes — personal data | Deleted (CASCADE) |
| email_events | Engine-tracked email interaction signals | Yes — personal data | Deleted (CASCADE) |
| customer (engine record) | Engine's customer row (email natural-key) | Yes — personal data | Deleted (CASCADE) |
| rec_attribution | Which rec drove which order — decision-logic credit | **No — omitted** (trade secret / decision logic; legal-review pending) | Deleted (records_removed counts it) |
| gdpr_audit_log row | Proof a delete happened | n/a | **Retained** (compliance proof) |
| lift_metrics_daily | Aggregate lift stats | n/a | **Anonymised**, not deleted (aggregate, no PII after) |

### Plugin-held (local rec markers in WordPress)

| Element | Where | What it is | Export (Art 15) | Erase (Art 17) |
|---|---|---|---|---|
| _smaily_rec_id | order-meta | Which rec was attributed to this order | Yes — rec-specific | Removed |
| _smaily_visitor_token | order-meta | Visitor token captured at checkout | Yes — rec-specific | Removed |
| _smaily_rec_ctx | order-meta | Rec context at attribution | Yes — rec-specific | Removed |
| _smaily_anon_session_id | order-meta | Anon session linked to the order | Yes — rec-specific | Removed |
| _smaily_rec_merged_anon_sid | user-meta | Last anon-session merged into this user (3.7 dedup marker) | Yes — rec-specific | Removed |

**Important boundary.** `_smaily_rec_id` and friends sit *on* a WooCommerce order
object, but they are rec-specific meta the plugin added. The plugin's exporter
returns **these meta values**; it does **not** export the order itself (line
items, totals, address) — that is WooCommerce's exporter's job. The plugin reads
rec-meta off the order; it does not re-export Woo data.

### Smaily-held (consent authority)

| Element | What it is | GDPR role |
|---|---|---|
| Marketing consent | Permission to send marketing email | Smaily-owned; governs email, not profiling |
| **Profiling consent** | Separate granular parameter authorising rec-engine profiling | **Authority for Art 21 opt-out.** Plugin writes it to Smaily and reads it back. Withdrawn → no profiling. |
| Tracking consent | (Today not yet mandatory) | Smaily-owned |

### WooCommerce-held — OUT OF SCOPE

Orders, line items, addresses, purchase history → WooCommerce's own GDPR
exporters/erasers. The rec-engine plugin does not touch these.

### Plugin-held — local abandoned-cart tracker (non-rec-engine, PRO-1195)

Not rec-engine data at all — it never leaves the merchant's own WordPress
database — but it is PII the plugin itself stores locally, so it belongs in
this inventory (PRO-1194 gap-closing; audit
[2026-07-13-SECURITY_QUALITY_RE_AUDIT_PRO1195_CART_REWRITE.md](audits/2026-07-13-SECURITY_QUALITY_RE_AUDIT_PRO1195_CART_REWRITE.md)
finding 2).

| Element | Where | What it is | Export (Art 15) | Erase (Art 17) |
|---|---|---|---|---|
| `smly_plus_cart_session` row | Merchant's own WordPress DB table (`{prefix}smly_plus_cart_session`, migration 009) | One row per live/abandoned cart session: `cart_token`, `user_id`, `email`, `first_name`, `last_name`, `cart_content` (JSON array of `{product_id, variation_id, quantity}`), `cart_updated`, `reminder_enqueued_at`, `created_at` | **Yes** — `GdprHandler`'s exporter surfaces any in-flight row matched by e-mail (PRO-1343) | **Yes** — `GdprHandler`'s eraser deletes any matching row on request, in addition to the automatic retention below |

**Purpose.** Powers the abandoned-cart reminder e-mail sent via Smaily (one
`automation.abandoned_cart` event), independent of the rec-engine connection
— gated by the setup wizard (`smly_plus_setup_completed`) and the merchant's
abandoned-cart toggle (`CartHookHandler`, `CartAbandonmentSweeper`).

**Retention (code-derived — `CartAbandonmentSweeper::sweep()` /
`CartSessionStore`, not assumed):**
- Every row is deleted once its `cart_updated` timestamp is older than
  ~24 hours (`DAY_IN_SECONDS`, filterable via
  `smaily_connect_abandoned_cart_max_age_seconds`) — whether or not a
  reminder was ever sent (`delete_expired()` covers un-reminded rows,
  `prune_notified()` covers already-reminded ones). This housekeeping runs on
  every 15-minute sweep tick, **even while the abandoned-cart feature is
  toggled off**, so tracked rows never outlive the window regardless of
  feature state.
- A row is deleted immediately (independent of the ~24h window) when: the
  cart is emptied, an order completes (classic or Store API checkout —
  `clear_for_order()` deletes by session token, customer id, and billing
  email), or a guest cart's identity migrates to a logged-in session
  (`delete_other_rows_for_email()` drops the stale guest-session duplicate).

**GDPR-tooling coverage (PRO-1343).** `GdprHandler`
(`includes/Privacy/GdprHandler.php`) registers the plugin's only WP Privacy
exporter/eraser, and it now covers `smly_plus_cart_session` rows in addition
to rec-engine data + the plugin's rec-meta (above): a subject-access request
surfaces any in-flight row matched by the requester's e-mail (plus, as a
defensive belt-and-suspenders match, a row keyed to their WP user id even if
its `email` column were ever empty), and an erasure request deletes those
same rows — independent of the rec-engine connection, since this tracker has
nothing to do with the engine. The ~24h auto-purge (below) remains the
retention backstop; the exporter/eraser now additionally cover the window
before that purge runs.

---

## Consent model — granular, not one switch

Following Estonian AKI guidance, consent is **granular** — a shopper can grant
or withhold each independently; a single "cookies: no" must NOT switch all of
them off:

1. **Marketing email** (Smaily email) — Smaily consent.
2. **Tracking** (beacon cookies) — browser-cookie consent (CookieYes / WP
   Consent API). Today not yet mandatory.
3. **Profiling** (rec-engine via Woo, incl. beacon use) — **Smaily profiling
   consent** (separate parameter). This is the one that governs us.

### Two consent gates on the beacon

The browse beacon now requires **both** of these to be ON before it sends:

1. **Browser-cookie consent** (CookieYes / WP Consent API) — the ePrivacy /
   cookie-law basis for setting cookies and firing the beacon at all. (Built in
   3.4.2.)
2. **Profiling consent** (Smaily) — the GDPR profiling basis. If profiling
   consent is OFF, the beacon **stops** even when cookie consent is ON — they
   are distinct legal bases, so both are required.

Profiling consent OFF therefore produces **two actions**:
- **Engine opt-out** (§10) — customer excluded from recommendations; data
  retained; reversible.
- **Beacon-stop** — no new browse events collected.

---

## GDPR rights — what each one does

### Art 15 — Export (subject access)

Returns the shopper's **rec-engine personal data**, and nothing more:

- Included: engine browse_events, visitor_tokens, recommendations, email_events,
  engine customer record; plugin rec-meta (the `_smaily_*` markers).
- **Not included:** `rec_attribution` (decision logic / trade secret — omitted,
  not flagged as "request separately", because it is not a subject-access right);
  the rec-engine's decision logic / weights (trade secret, as Google/Meta also
  withhold); WooCommerce order data (Woo's exporter handles that).
- The privacy policy explains *what categories of data are used and why* — that
  is a separate layer from the export, which returns the personal data itself.

### Art 17 — Erase

Full deletion, asymmetric to export (export is conservative, erase is complete):

- Engine §9 DELETE: customer + browse_events + recommendations + email_events +
  visitor_tokens + **rec_attribution** all deleted (CASCADE). Idempotent (a
  second call returns 404 → treat as success).
- Retained after erase: a `gdpr_audit_log` row (proof the deletion happened) and
  `lift_metrics_daily` in anonymised form (aggregate, no PII).
- Plugin side: the `_smaily_*` order-meta and user-meta markers are removed, so
  no rec-specific traces are left behind in WordPress.

### Art 21 — Opt-out (profiling objection)

- Authority: **Smaily profiling consent** (separate parameter). The plugin
  writes the consent state to Smaily and reads it back; withdrawal is the
  trigger.
- Effect: engine opt-out (§10 — data retained, excluded from recommendations,
  reversible) **plus** beacon-stop (no new events). Distinct from erase: opt-out
  keeps the data and is reversible; erase deletes it.

---

## Build split (3.8 and what follows)

- **3.8 GDPR (this work)** builds the **mechanism**: the engine §8/§9/§10 client
  methods (export / delete / opt-out) and the WP Privacy API exporter + eraser
  (covering engine rec-data + plugin rec-meta, NOT Woo data). Self-contained;
  does not depend on the Smaily consent API.
- **Smaily profiling-consent wiring + beacon-stop** is a **separate piece**
  (after 3.8) because it depends on the Smaily profiling-consent parameter API
  (how the plugin stores and reads it). It *uses* the 3.8 engine-opt-out method,
  so 3.8 must ship the mechanism first.

---

## One-line summary per system

- **Engine** — holds rec personal data; export omits attribution, erase deletes
  everything, opt-out excludes-but-keeps.
- **Plugin** — holds local rec markers; exports/erases its own meta; reads
  profiling consent from Smaily.
- **Smaily** — owns profiling consent (the Art 21 authority); plugin reads it
  back.
- **WooCommerce** — owns commerce data; out of scope for this plugin's GDPR
  handlers.

---

## Merchant privacy-policy template (PRO-1194)

> **SIGNED OFF by Erkki, 2026-07-14 (PRO-1194).** The three items that were
> blocking sign-off — the Smaily legal entity name, the Smaily privacy-policy
> URL, and the lawful-basis framing — are resolved below (see "Template ↔ code
> fact map" → "Open placeholders"). It is still a TEMPLATE for merchants to
> adapt into their own store privacy policy, and it is NOT legal advice —
> every merchant must have their own legal counsel review the adapted text
> (including the lawful-basis choice and, for a legitimate-interest basis, a
> documented balancing test / LIA) — that requirement is not removed by this
> sign-off. The remaining `[BRACKETED]` items are deliberate merchant-side
> fill-ins (store name, contact address, supervisory authority) or the
> merchant-legal-review caveat itself — not open items pending our
> confirmation. Ported to the merchant docs site (`docs/site/index.html`,
> EN+ET) in the same commit as this sign-off.

**Roles, for the merchant's orientation (not part of the pasted text):** the
store is the **data controller**; Smaily (the Smaily marketing platform + the
Campaign Intelligence recommendation engine) acts as the store's **data
processor**. The template below is written from the store's voice to the
store's customers. Every factual claim in it is derived from what the plugin
actually does (see the inventory above and DECISIONS F3-31/F3-46/F3-49/F3-50);
if the merchant disables browse tracking or is not connected to Campaign
Intelligence, the corresponding sentences must be removed.

### EN template — "Personalised product recommendations (profiling)"

```
Personalised product recommendations (profiling)

Who processes the data. [STORE NAME] ("we") is the data controller. To
personalise our email marketing we use the Smaily Campaign Intelligence
service provided by Sendsmaily OÜ ("Smaily"), which processes this data on
our behalf as our data processor, under a data processing agreement.
Smaily's own privacy information is available at
https://connect.smaily.com/privacy.

What we do. We use your purchase history and — if you have accepted
marketing cookies — your browsing activity in our online store to
personalise the product recommendations shown in the emails we send you.
This is "profiling" within the meaning of the GDPR: an automated analysis
of your shopping behaviour to predict which products may interest you. It
only affects which products we show you in our emails and recommendations —
it has no legal or similarly significant effect on you, and no automated
decisions within the meaning of Article 22 GDPR are made about you.

What data is used.
- Contact and account details: e-mail address, name, phone number, country
  and preferred language.
- Purchase history: products ordered, quantities, prices, order dates and
  order statuses.
- Browsing activity in our store (only if you have accepted marketing
  cookies): pages and products viewed, cart events and searches, linked to
  a pseudonymous visitor identifier — and, while you are logged in and have
  not opted out of personalisation, linked directly to your account.
- Technical identifiers: a pseudonymous visitor token and session
  identifier, and first-party cookies we set when you arrive in our store
  through a recommendation link in one of our emails (cookies
  `smaily_rec_id` and `smaily_rec_ctx`, kept for up to 30 days, and
  `smaily_rec_uid`, kept for up to 365 days, by default). These are used to
  measure which recommendations led to purchases.
- Abandoned-cart reminders (if enabled, separate from the profiling described
  above): if you leave items in your cart without completing checkout, we
  may store your cart contents, e-mail address and name for a short time in
  our own store's systems (not the Smaily Campaign Intelligence
  recommendation engine) to send you a reminder e-mail.

Legal basis. We process this data on the basis of our legitimate interest
(Article 6(1)(f) GDPR) in offering relevant marketing to our customers,
combined with your right to object at any time (Article 21 GDPR).
Personalisation is on by default; you can switch it off at any time (see
below). Browsing data is collected only with your cookie consent
(marketing cookies). [MERCHANT LEGAL REVIEW: confirm the lawful basis for
your store and document a legitimate-interest assessment.]

How to opt out. Log in to your account and open My Account: under
"Smaily Campaign Intelligence", untick "Use my data for personalised
recommendations" and save. Opting out stops the use of your data for
personalised recommendations — you will still receive our emails, just
without personalisation. You can also opt out by contacting us at
[CONTACT E-MAIL]. We act on an opt-out without undue delay; it may take up
to 24 hours to take effect across all systems. Withdrawing your
marketing-cookie consent (in the cookie settings) stops the collection of
browsing data.

Your rights. You have the right to access the personal data used for
recommendations and receive a copy of it, to have it erased, to object to
profiling (as described above), and to lodge a complaint with your data
protection supervisory authority [FOR ESTONIAN STORES: Andmekaitse
Inspektsioon]. To exercise access or erasure, contact us at
[CONTACT E-MAIL].

Retention. Your browsing activity in our store (pages and products viewed,
cart events, linked to the pseudonymous visitor identifier) is kept for up
to 90 days after it occurs, and then automatically and permanently deleted.
Your purchase history and recommendation profile are kept for as long as
you remain our customer. The product recommendations computed for you, and
the internal records used to measure which recommendation led to which
purchase, are kept for up to 2 years; engagement signals from your email
interactions are kept for up to 1 year. If we stop using this service for
our store, all associated data is deleted. If you ask us to erase your
data at any time (see "Your rights" below), it is deleted immediately
regardless of these periods; we retain only a record showing that the
deletion was carried out. Abandoned-cart reminder data (cart contents,
e-mail, name — a separate feature from the profiling above) is kept for a
short period, by default up to 24 hours since your last cart activity, and
then automatically deleted, or deleted immediately once you complete your
order or empty your cart.
```

### ET template — "Personaalsed tootesoovitused (profileerimine)"

```
Personaalsed tootesoovitused (profileerimine)

Kes andmeid töötleb. [POE NIMI] ("meie") on vastutav töötleja. E-posti
turunduse personaliseerimiseks kasutame Smaily Campaign Intelligence'i
teenust, mida osutab Sendsmaily OÜ ("Smaily") ja mis töötleb neid andmeid
meie nimel volitatud töötlejana, andmetöötluslepingu alusel. Smaily enda
privaatsusteave on kättesaadav aadressil
https://connect.smaily.com/privacy.

Mida me teeme. Kasutame sinu ostuajalugu ja — kui oled andnud nõusoleku
turundusküpsisteks — sinu sirvimistegevust meie e-poes, et personaliseerida
meie saadetavates e-kirjades kuvatavaid tootesoovitusi. See on
profileerimine isikuandmete kaitse üldmääruse (GDPR) tähenduses: sinu
ostukäitumise automaatne analüüs eesmärgiga ennustada, millised tooted
võiksid sind huvitada. See mõjutab üksnes seda, milliseid tooteid sulle
meie kirjades ja soovitustes näitame — sellel ei ole sinu jaoks õiguslikke
ega muid samaväärselt olulisi tagajärgi ning sinu suhtes ei tehta
automatiseeritud otsuseid GDPR artikli 22 tähenduses.

Milliseid andmeid kasutatakse.
- Kontakt- ja kontoandmed: e-posti aadress, nimi, telefoninumber, riik ja
  eelistatud keel.
- Ostuajalugu: tellitud tooted, kogused, hinnad, tellimuste kuupäevad ja
  staatused.
- Sirvimistegevus meie poes (ainult turundusküpsiste nõusolekul): vaadatud
  lehed ja tooted, ostukorvisündmused ja otsingud, seotuna pseudonüümse
  külastajatunnusega — ning sisselogituna ja kui sa ei ole personaliseerimisest
  loobunud, seotakse see lisaks otse sinu kontoga.
- Tehnilised identifikaatorid: pseudonüümne külastajatunnus (visitor
  token) ja seansitunnus ning esimese osapoole küpsised, mille paigaldame,
  kui jõuad meie poodi meie e-kirjas olnud soovituslingi kaudu (küpsised
  `smaily_rec_id` ja `smaily_rec_ctx`, säilivad vaikimisi kuni 30 päeva,
  ning `smaily_rec_uid`, säilib vaikimisi kuni 365 päeva). Nende abil
  mõõdame, millised soovitused viisid ostuni.
- Hüljatud ostukorvi meeldetuletused (kui funktsioon on sisse lülitatud,
  eraldiseisev eespool kirjeldatud profileerimisest): kui jätad ostukorvi
  tellimust vormistamata, võime lühikeseks ajaks salvestada sinu ostukorvi
  sisu, e-posti aadressi ja nime meie enda poe süsteemides (mitte Smaily
  Campaign Intelligence soovitusmootoris), et saata sulle meeldetuletuse
  e-kiri.

Õiguslik alus. Töötleme neid andmeid oma õigustatud huvi alusel (GDPR
art 6 lg 1 p f) pakkuda oma klientidele asjakohast turundust, koos sinu
õigusega esitada igal ajal vastuväide (GDPR art 21). Personaliseerimine on
vaikimisi sisse lülitatud; saad selle igal ajal välja lülitada (vt allpool).
Sirvimisandmeid kogume ainult sinu küpsisenõusolekul (turundusküpsised).
[POE ÕIGUSNÕUSTAJA: kinnita oma poe õiguslik alus ja dokumenteeri
õigustatud huvi kaalumisotsus.]

Kuidas loobuda. Logi sisse ja ava Minu konto: eemalda jaotises "Smaily
Campaign Intelligence" linnuke valikult "Kasuta minu andmeid personaalsete
soovituste jaoks" ja salvesta. Loobumine peatab sinu andmete kasutamise
personaalsete soovituste jaoks — e-kirju saad edasi, lihtsalt ilma
personaliseerimiseta. Loobuda saad ka, kirjutades meile aadressil
[KONTAKT-E-POST]. Rakendame loobumise põhjendamatu viivituseta; kõigis
süsteemides jõustumine võib võtta kuni 24 tundi. Turundusküpsiste
nõusoleku tagasivõtmine (küpsiste seadetes) peatab sirvimisandmete
kogumise.

Sinu õigused. Sul on õigus tutvuda soovituste jaoks kasutatavate
isikuandmetega ja saada neist koopia, nõuda nende kustutamist, esitada
vastuväide profileerimisele (nagu eespool kirjeldatud) ning esitada kaebus
andmekaitse järelevalveasutusele [EESTI POODIDELE: Andmekaitse
Inspektsioon]. Andmetega tutvumiseks või kustutamiseks kirjuta meile
aadressil [KONTAKT-E-POST].

Säilitamine. Sinu sirvimistegevust meie poes (vaadatud lehed ja tooted,
ostukorvisündmused), mis on seotud pseudonüümse külastajatunnusega,
säilitame kuni 90 päeva pärast sündmuse toimumist ning seejärel kustutame
selle automaatselt ja jäädavalt. Sinu ostuajalugu ja soovitusprofiili
säilitame seni, kuni oled meie klient. Sulle arvutatud tootesoovitusi ning
sisemisi kirjeid, mille abil mõõdame, milline soovitus millise ostuni viis,
säilitame kuni 2 aastat; sinu e-kirjadega seotud kaasatussignaale säilitame
kuni 1 aasta. Kui me lõpetame selle teenuse kasutamise oma poe jaoks,
kustutatakse kõik sellega seotud andmed. Kui palud oma andmed igal ajal
kustutada (vt allpool "Sinu õigused"), kustutatakse need kohe, sõltumata
eespool nimetatud tähtaegadest; alles jääb üksnes kirje, mis tõendab, et
kustutamine on tehtud. Hüljatud ostukorvi meeldetuletuse andmeid (ostukorvi
sisu, e-post, nimi — eraldiseisev funktsioon eespool kirjeldatud
profileerimisest) säilitame lühikest aega, vaikimisi kuni 24 tundi alates
viimasest ostukorvi muudatusest, ning kustutame need seejärel automaatselt,
või kustutame kohe, kui vormistad tellimuse või tühjendad ostukorvi.
```

### Template ↔ code fact map (why each claim is true)

| Template claim | Source in this repo |
|---|---|
| Profiling = purchase history + (consented) browse | Engine ingest: orders/customers always when connected; browse only behind the WP Consent API `marketing` gate (`beacon-core.ts detectConsent`, F3-50) AND the profiling gate (`BeaconEndpoint` second gate, F3-31 (a).1) |
| Contact/account fields listed | `CustomerPayloadBuilder`: email, first/last name, phone, country, language, first_seen_at |
| Purchase-history fields listed | `OrderPayloadBuilder`: items (sku/qty/prices), amounts, status, ordered_at, currency |
| Pseudonymous visitor token / session id; the CLIENT never adds rec_id/email to browse | F3-49 (`enrich()` sends `session_id` + `smaily_visitor_token` only). PRO-1389 addendum: for a logged-in, non-opted-out session, `BeaconEndpoint::attach_logged_in_identity()` attaches `customer_email` SERVER-side (from the WP auth cookie) on the outbound engine request only — never in the JS blob or the `/relay` response; an opted-out contact's event stays anonymous, not dropped |
| Attribution cookies `smaily_rec_id`/`smaily_rec_ctx` 30 d, `smaily_rec_uid` 365 d (defaults; engine config can override) | `LandingCapture` (`rec_id_ttl_days` 30, `context_ttl_days` 30, `cookie_ttl_days` 365); set consent-ungated per F3-46 (Erkki) — hence they MUST be disclosed in the policy |
| Opt-out via My Account, still receives emails | `ProfilingConsentAccount` (My Account dashboard section "Smaily Campaign Intelligence", checkbox "Use my data for personalised recommendations" / et: "Kasuta minu andmeid personaalsete soovituste jaoks") |
| "Up to 24 hours to take effect across all systems" | `ProfilingConsent` daily-TTL cache: a WP-side opt-out is immediate (cache + engine §10 fire at once); a Smaily-side opt-out propagates at the next cache refresh, ≤ 24 h (see the fail-open review below) |
| Access / erasure | `GdprHandler` — WP Privacy API exporter (Art 15) + eraser (Art 17); erase = engine §9 CASCADE + plugin `_smaily_*` meta removal |
| "We retain only a record showing that the deletion was carried out" | Engine `gdpr_audit_log` row retained (this doc, inventory) |
| No Art 22 automated decisions | Recommendations only select email/on-site content; no legal or similarly significant effect (this doc; F3-31 model) |
| Retention periods (browse 90 d; recommendations/rec_attribution 2 yr; email_events 1 yr; orders/customers = duration of merchant relationship, no fixed calendar period) | Engine team answer + Erkki decision, 2026-07-12 (PRO-1194): engine-enforced global horizons, daily `cleanup-expired-data` cron. Browse events (incl. `smaily_visitor_token` rows) and visitor-token↔customer bindings: 90-day TTL from creation, hard-deleted (binding also dies on GDPR erase / customer deletion). Order & customer ingest rows: no fixed period — retained for the duration of the merchant relationship, individual control is the Art 17 erase (`DELETE /api/v1/customer/{email}`); natural upper bound is merchant offboarding (tenant purge on engine side — tracked as an engine-backlog follow-up, not yet built). Recommendations 730 days from issue (a not-yet-issued/pending recommendation is not aged out early); rec_attribution 730 days; email_events 365 days; decision_log (engine-internal automated-decision log, not a customer-facing data element in the inventory above) 30 days. |
| Abandoned-cart reminders: cart contents/e-mail/name stored locally (not the rec engine), kept ~24h by default, deleted on order completion/cart clear | `smly_plus_cart_session` (migration 009): fields per `CartSessionStore`; ~24h auto-purge per `CartAbandonmentSweeper::sweep()` (`DAY_IN_SECONDS`, filter `smaily_connect_abandoned_cart_max_age_seconds`); immediate delete on order/cart-clear per `CartHookHandler::clear_for_order()`/`on_cart_updated()` — see the "Plugin-held — local abandoned-cart tracker" subsection above (PRO-1194/PRO-1195) |

Placeholders resolved at sign-off (Erkki, 2026-07-14, PRO-1194):
1. ~~`[CONFIRM: Smaily legal entity name]`~~ — **RESOLVED 2026-07-14.** The
   controller-facing legal entity is **Sendsmaily OÜ**; both templates now
   name it directly.
2. ~~`[LINK: Smaily privacy policy]`~~ — **RESOLVED 2026-07-14.**
   `https://connect.smaily.com/privacy`. A separate cross-team issue
   (PRO-1406) is making this URL platform-agnostic (it currently reads
   Shopify-specific on the destination page) — the URL itself is stable, so
   this template does not wait on that rework.
3. ~~`[CONFIRM WITH ENGINE TEAM: retention period]`~~ — **RESOLVED 2026-07-12.**
   Engine team supplied the enforced retention horizons and Erkki decided the
   orders/customers wording (no fixed calendar period; see the fact-map row
   above and the retention paragraph in both templates). No longer an open
   placeholder.
4. Lawful basis: the template drafts **legitimate interest + Art 21 opt-out**
   (matches the F3-31 opt-out/default-on model and the AKI reading recorded
   there: transparent action + working opt-out). **RESOLVED 2026-07-14** —
   Erkki confirmed this framing as-drafted. The `[MERCHANT LEGAL REVIEW]` /
   `[POE ÕIGUSNÕUSTAJA]` caveat in both templates is unchanged by this
   sign-off: each merchant must still have their own counsel confirm the
   basis for their store and document a balancing test/LIA. If AKI tightens
   to explicit opt-in, `ProfilingConsent::is_allowed()` is built invertible
   (F3-31 TODO) and this template would need rewriting.

Remaining bracketed items in both templates — `[STORE NAME]`/`[POE NIMI]`,
`[CONTACT E-MAIL]`/`[KONTAKT-E-POST]`, the `[MERCHANT LEGAL REVIEW]`/
`[POE ÕIGUSNÕUSTAJA]` caveat, and `[FOR ESTONIAN STORES]`/`[EESTI POODIDELE]`
(supervisory authority) — are deliberate merchant-side fill-ins, not open
sign-off items: each merchant fills in their own store name and contact
address, confirms their own supervisory authority, and has their own counsel
complete the legal-review caveat.

---

## Fail-open GDPR window — decision review + hardening (PRO-1194)

**Status: IMPLEMENTED 2026-07-13.** This section originally restated the
shipped behavior, analysed the risk, and listed alternatives for Erkki/legal
sign-off. The recommended hardening (options B + C below) is now built —
see "Implemented behavior (2026-07-13)" at the end of this section for the
final matrix. The analysis above the recommendation is left as-written
(historical review record); only the recommendation itself is superseded.

### Behavior before this hardening (code facts as originally shipped)

- `may_profile( $email )` reads a per-email transient
  (`smly_profiling_<md5(email)>`, TTL **1 day**). Cache hit → cached answer.
- Cache miss → `refresh()`: read the contact back from Smaily and apply the
  pure rule (`is_allowed()`: don't-profile only on `is_unsubscribed === '1'`
  or `smaily_rec_profiling === '0'`; missing/unknown → profile).
- **Fail-open:** a read-back **error** (Smaily API failure) — or an
  unconfigured Smaily client — resolves to `allowed = true` and is **cached
  for the full TTL**. This is deliberate (DECISIONS F3-31): consistent with
  the opt-out/default-on model; an undeterminable state defaults to profiling
  rather than a silent block.
- A **WP-side** opt-out (`ProfilingConsentAccount` → `opt_out()`) is immediate:
  cache set to `'0'` and the engine §10 `customer_opt_out` fired in the same
  call. A **Smaily-side** opt-out is only seen at the next cache refresh
  (≤ 24 h), which then also fires the engine opt-out.
- Enforcement consumers: the `BeaconEndpoint` second gate (drops browse events
  whose `customer_email` resolves to opted-out, pre-forward),
  `IdentityHookHandler` (skips `identity.merge` for opted-out), and — since
  2026-07-03 — **engine-side server enforcement** on the visitor-token path
  (F3-49 pt 3: an opted-out contact's browse event is never bound to a
  customer, engine-side, regardless of what the plugin forwards).

### The window, precisely

Two distinct exposures, often conflated:

1. **The fail-open window proper (read error).** Someone is profiled without
   a valid basis only when ALL of: (a) their cache entry is absent/expired,
   (b) the Smaily read-back errors, and (c) they had actually opted out —
   *and* (d) the engine does not already hold their opt-out state (i.e. the
   opt-out was Smaily-side-only and had never yet propagated; a WP-side
   opt-out already fired §10, so the engine keeps excluding them even if the
   plugin briefly fails open). The wrong `'1'` is then cached, so a single
   failed read extends the exposure to up to the TTL (≤ 24 h) past the outage.
2. **The propagation window (no error at all).** A Smaily-side opt-out takes
   effect plugin-side at the next refresh — up to 24 h. This is not a failure
   mode; it is the designed cache freshness.

One sharpening not in F3-31: **transients are not guaranteed storage** (an
external object cache can evict them at any time), so a cached `'0'` can
disappear before its TTL — meaning a known opt-out's continued enforcement
does ultimately depend on the read-back succeeding at the next miss. That
makes the fail-open slightly wider than the F3-31 wording implies, and it is
the strongest argument for hardening option C below.

### Risk analysis

- **Worst realistic case:** contact opts out on the Smaily side; before the
  daily refresh sees it, Smaily's API has an outage; their browse events keep
  being forwarded and bound, and personalised sends continue, for the outage
  duration + up to 24 h of wrong-cache. Data involved is behavioral
  (browse/purchase signals), not special-category; no Art 22 effects.
- **Legal shape:** Art 21(3) says once an objection is made the data "shall
  no longer be processed" for that purpose. Regulators read this as "without
  undue delay" — a short, bounded, disclosed technical propagation delay is
  defensible; an unbounded fail-open that can override a *known* objection is
  much harder to defend. Today's design keeps known WP-side objections safe
  (immediate cache + engine §10) but a Smaily-side-only objection combined
  with a read failure can be overridden — narrow, transient, but real
  (F3-31 already flags exactly this for Erkki's review).
- **Mitigating layers already shipped:** engine-side opt-out enforcement on
  every binding path (server-side, since 2026-07-03), the WP-side immediate
  path, and the 24 h TTL bound (the wrong state can never persist past a day
  after Smaily recovers).

### Alternatives

| Option | What it is | Assessment |
|---|---|---|
| A. Fail-closed on read error | Read error → don't profile | Any Smaily outage silently stops profiling for EVERY contact — the exact "silent 0 events, indistinguishable from feature-off" failure class this project has been burned by (F3-50, LESSONS). Disproportionate to the narrow risk; contradicts the F3-31 default-on decision. Not recommended. |
| B. Serve-stale-on-error | On read error, reuse the last known (even expired) cached value; fail open only for never-seen contacts | Removes the worst case (a KNOWN opt-out re-profiled during an outage) at trivial cost. Small, contained change to `refresh()` + cache bookkeeping. **IMPLEMENTED 2026-07-13.** |
| C. Persist opt-outs durably | Mirror a known opt-out into non-expiring storage (user meta / option), checked before fail-open; cleared on opt-in | Makes a known objection immune to transient eviction AND outages. Complements B (covers cache-evicted entries B alone cannot). **IMPLEMENTED 2026-07-13.** |
| D. Shorter cache TTL | e.g. 1 h instead of 24 h | Shrinks only the propagation window, at ~24× the Smaily read volume; 24 h is already within "undue delay" tolerance for marketing profiling (F3-31: "a week would be a problem", a day is not). Not recommended alone. |
| E. Switch to explicit opt-in | Flip `is_allowed()` per the F3-31 TODO | The fallback if AKI/legal tightens. A model change, not a window fix — out of scope here; kept invertible by design. |

### Implemented behavior (2026-07-13)

**Kept the fail-open default (F3-31 stands), hardened it with B + C.** An
opt-out the plugin has *ever seen* can now never be overridden by a read
failure or transient cache eviction — the residual fail-open covers only
contacts whose objection no plugin-side system has ever observed, and the
engine-side enforcement layer (F3-49 pt 3) covers most of that remainder.
`ProfilingConsent` (`includes/Privacy/ProfilingConsent.php`) now resolves a
profiling decision through four layers, in order:

| Layer | Storage | Lifetime | When it wins |
|---|---|---|---|
| 1. Fresh cache | Per-email transient (`smly_profiling_<hash>`) | 1 day TTL | Normal path — any cache hit answers directly, no Smaily read. Unchanged from before this hardening. |
| 2. Durable opt-out registry | Single option (`smly_profiling_optouts`, autoload=false), keyed by hashed email, opt-outs only | No expiry, immune to transient/object-cache eviction | On a fresh-cache miss with a read error (or no Smaily client configured): checked FIRST. If the email is in the registry, profiling is denied — unconditionally, regardless of any stale value. Only a later **successful** engine read-back showing opt-in removes the entry; an error can never clear it. |
| 3. Stale cache | Second per-email transient (`smly_profiling_stale_<hash>`), no TTL | Until overwritten by the next successful read | Consulted only if layer 2 has no entry. Serves the last successfully fetched answer (allow or deny) instead of defaulting to allowed. |
| 4. True fail-open | — | — | Only when NEITHER a durable opt-out NOR a stale value exists for the email — i.e. a genuinely never-seen contact whose first-ever read errors. This is the sole residual fail-open window; it can never re-allow a contact the plugin has previously resolved either way. |

Every successful read-back (and every WP-side `opt_out()`/`opt_in()` call)
writes all three stores together: the fresh cache, the stale cache, and the
durable registry (add on opt-out, remove on opt-in). The propagation window
(exposure 2 above) is unchanged by this hardening — it is designed cache
freshness, not a failure mode — and stays disclosed in the privacy-policy
template ("may take up to 24 hours to take effect across all systems").
Tests: `tests/Unit/Privacy/ProfilingConsentTest.php` covers all four layers
(fresh hit, stale-served-on-error, durable-opt-out-wins-over-error,
durable-cleared-by-a-successful-opt-in-read, opt-out-persists-durably,
true fail-open for a never-seen contact + error).
