# CONSENT_STRATEGY_COMPARISON.md — engine vs plugin consent strategies

> **Purpose.** Two systems Erkki owns both touch Smaily consent: the **rec-engine**
> (`re/`) and this **Connect plugin** (`connect/`). They operate on *different
> consent layers* but share one authority (Smaily) and one event model (pull).
> This doc maps how the two strategies differ and align, so the teams stay
> coordinated and nobody mistakes one layer's "mode" for the other's. Authority
> for the consent model itself is `connect/docs/DATA_MODEL_GDPR.md`; the engine's
> reconciliation design is `re/docs/CONTACT_RECONCILIATION_DESIGN.md`.

## 1. The core insight — two distinct consent layers

GDPR consent here is **granular** (Estonian AKI guidance): independent legal
bases, never one switch. Two of them are Smaily-owned but **separate
parameters**:

| Layer | Governs | Smaily parameter | Whose feature |
|---|---|---|---|
| **Marketing consent** | *Sending* marketing email | the standard subscribe / `is_unsubscribed` status | **Plugin** contact-sync |
| **Profiling consent** | *Profiling* a contact for recommendations (browse beacon + recs) | `smaily_rec_profiling` (separate custom param) | **Engine** rec personalization |
| Tracking/cookie consent | Setting beacon cookies | browser (CookieYes / WP Consent API) | Plugin beacon (gate) |

**The one rule that prevents bugs:** a **marketing unsubscribe ≠ a profiling
objection.** A contact can be unsubscribed from email (Smaily suppresses sending)
yet still profiled (engine keeps computing recs), and vice-versa. Both
`DATA_MODEL_GDPR.md` §25 and `CONTACT_RECONCILIATION_DESIGN.md` §1 state this
explicitly. Neither system's reconcile may leak across the layers.

## 2. Side-by-side

| Dimension | **Engine** (`re/`) | **Plugin** (`connect/`) |
|---|---|---|
| Consent layer | Profiling (recs) | Marketing (email sync) |
| Smaily param read/written | `smaily_rec_profiling` (+ts) | subscribe status / `is_unsubscribed` |
| Local store | `customers.opted_out` (Art 21 cache), `customers.in_smaily` / `smaily_unsubscribed` (overlap flags) | WP `user_newsletter` user-meta (opt-in record) |
| "Modes" terminology | **Conservative / Go-live / Growth** (`prospect_personalization`: who gets *profiled*) | **All customers / Subscribers only / Checkout opt-in only** (who gets *synced as a contact*) |
| Sync policy | `contact_sync_policy`: all-with-recs / existing-only / seed (create vs update Smaily contacts) | contact-sync mode preset → audience (legitimate interest = all; consent = opt-in; checkout = checkbox) |
| Audience | engine `customers` = WooCommerce buyers | WP registered users (+ optional guests) |
| Reconcile target | engine `customers` flags ↔ Smaily | WP `user_newsletter` ↔ Smaily (consent mode only) |
| Reconcile source | Smaily **action-log** (deltas) + periodic full `list=1` import | Smaily **action-log** (`optin`/`optout`/`delete`/`complaint` deltas) + occasional full `list=1` re-baseline |
| Art 21 opt-out | engine §10 opt-out (retain + exclude + reversible) | n/a (marketing-unsub is not Art 21; it only stops sending) |

## 3. Shared principles (both sides honour these)

- **Smaily is the consent authority.** Neither system invents consent; both read
  it from Smaily and mirror it locally. Local stores are *caches*, not sources.
- **Pull, not push.** Smaily has no webhooks. Both poll the action-log
  (`GET /api/history.php`, `since_seq_id` cursor, ~30-day retention) for deltas,
  and fall back to a periodic full pull (`GET /api/contact.php?list=1`) to
  re-establish truth / cover gaps. (Smaily-recommended pattern;
  `re/docs/smaily-api/reference/action-log.md`.)
- **Never silently broaden.** Both default to the consent-safe behaviour
  (engine: `existing_only` for new tenants; plugin: `consent` preset); the broad
  options (engine `all_with_recs`/seed; plugin legitimate-interest) are deliberate,
  warned opt-ins.

## 4. Where they touch — and the confusion to avoid

- **"Mode" is overloaded.** Engine modes = *profiling* breadth
  (Conservative/Go-live/Growth). Plugin modes = *marketing-sync* audience (All
  customers / Subscribers only / Checkout opt-in). They are **different layers** —
  a store can be plugin-"All customers" (sync every buyer for email) yet
  engine-"Conservative" (profile only buyers, not prospects). When discussing
  cross-team, always say **"profiling mode" vs "contact-sync mode."**
- **Both pull from the same Smaily account.** Two independent action-log pollers
  (engine cursor, plugin cursor) + two periodic full imports. Acceptable (each
  needs its own layer's data), but worth noting for rate-limit budgeting
  (10 req/s shared) if cadences ever tighten.
- **The plugin answers an engine open question.** `CONTACT_RECONCILIATION_DESIGN`
  §3/§6.2 asks *"does the connector push all buyers or only opt-in?"* (MiuMjau: 460
  buyers not in Smaily). The plugin's contact-sync mode now **decides** this per
  store (consent = opt-in only; legitimate interest = all), so the engine's
  "engine-only" set size is a function of the chosen plugin preset, not a fixed
  connector behaviour.

## 5. References
- `connect/docs/DATA_MODEL_GDPR.md` — authoritative consent model (marketing vs profiling vs tracking).
- `connect/docs/CONTACT_SYNC_MODES.md` — the plugin's contact-sync mode engine (F3-48).
- `re/docs/CONTACT_RECONCILIATION_DESIGN.md` — the engine's overlap/reconciliation + `contact_sync_policy`.
- `re/docs/CONTACT_MODES_UX_COPY.md` — the engine's profiling-mode UX copy (Conservative/Go-live/Growth).
- `re/docs/smaily-api/reference/action-log.md` — the pull/delta event model both sides use.
