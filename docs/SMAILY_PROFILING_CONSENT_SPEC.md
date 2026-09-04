# Smaily Profiling-Consent — Integration Spec

**Purpose.** Defines how the plugin reads and writes the **profiling consent**
that authorises the rec-engine to profile a Smaily contact. This is the input
for the deferred wiring piece (DECISIONS F3-28.5): "Smaily profiling-consent
wiring + beacon two-gate stop."

**Authority.** Smaily owns consent (RECENGINE_API_CONTRACT line 722 — consent is
NOT part of the rec-engine contract). This is a **Smaily-platform API** call
(alongside the existing marketing contact sync), not a rec-engine call. The
plugin writes the consent state to Smaily and reads it back; the read-back value
is the authority for whether profiling is allowed.

> **Fields confirmed (Erkki, 2026-06):** two contact fields hold profiling
> consent:
> - `smaily_rec_profiling` — boolean `1`/`0` (1 = may profile). The fast
>   enforcement value.
> - `smaily_rec_profiling_ts` — ISO-8601 timestamp of the last consent change.
>   The audit trail (GDPR Art 7 — consent must be demonstrable: when it was
>   granted or withdrawn, not just its current state).
>
> The boolean drives enforcement; the timestamp records when the state last
> changed. Both are written together on any consent change.

---

## Endpoint

Reuse the existing marketing contact endpoint:

- **Base:** `{subdomain}.sendsmaily.net/api/contact/`
- **Auth:** subdomain + API username + password (Basic), same as the existing
  contact sync. No new credential.
- This is the same surface the plugin already uses for marketing contact sync —
  no new integration point, just an additional field on the contact.

---

## The two consent signals on a contact

A Smaily contact carries two relevant signals:

1. **`is_unsubscribed`** (existing Smaily field) — the **general** opt-out. `1`
   = the contact has left marketing entirely.
2. **`smaily_rec_profiling`** (new, separate field) + `smaily_rec_profiling_ts`
   (timestamp) — the **granular** profiling consent. Holds whether the
   rec-engine may profile this contact, independent of general marketing.

This matches the granular-consent model (AKI guidance): a shopper can withhold
profiling alone (profiling off, marketing on) or leave entirely
(`is_unsubscribed = 1`).

---

## Consent model — OPT-OUT, default-on (Erkki decision, 2026-06)

The model is **opt-out**: profile **unless** the contact has explicitly opted out
(or left marketing entirely). Estonian AKI does not currently require an explicit
separate profiling opt-in — it requires a *transparent action* + a working opt-out.
A conscious decision with a small, separately-tracked GDPR risk (DECISIONS F3-31),
mitigated by transparency (privacy policy mentions profiling) + a working opt-out.

> The enforcement logic below was **inverted** from the original opt-in draft to
> this opt-out model. Read-back values are Smaily **strings** (`"0"`/`"1"`), so the
> comparison is string-based.

## Enforcement rule — profile UNLESS explicitly opted out

```
profile  IF  is_unsubscribed != "1"  AND  smaily_rec_profiling != "0"
```

- `smaily_rec_profiling == "1"` → profile.
- `smaily_rec_profiling` **absent** / contact not in Smaily (206) → **profile**
  (default-on; the field marks an opt-out, not the default state — so the sync
  does **not** need to write `=1`, the field appears only on an opt-out `=0`).
- `smaily_rec_profiling == "0"` → do **not** profile (explicit profiling opt-out).
- `is_unsubscribed == "1"` → do **not** profile (general unsubscribe — the
  stronger signal; leaving marketing entirely also stops profiling).

The only "do not profile" conditions are `is_unsubscribed == "1"` **OR**
`smaily_rec_profiling == "0"`. A read-back error **fails open** (profiles) —
consistent with default-on; never a silent block.

---

## Two directions — this is bidirectional sync

### Write (WP → Smaily)
When the shopper changes profiling consent on the WordPress side (e.g. a
settings-page opt-out), the plugin writes `smaily_rec_profiling` to the contact via
`/api/contact/` (the same upsert call used for marketing sync — the field rides
along).

### Read-back (Smaily → WP) — the authority
The plugin reads `is_unsubscribed` + `smaily_rec_profiling` back from the contact.
**This read-back is the authority, not the local WP state**, because consent can
change on the Smaily side too:

- Opt-out done in WP → written to Smaily → read back → enforced.
- Opt-out done on the **Smaily side** (Smaily UI, an email unsubscribe link) →
  read back → enforced — even though WP never initiated it.

The read-back covers **both origins**. The plugin must not assume WP-local state
is current; the Smaily contact is the source of truth. A shopper who left on the
Smaily side must stop being profiled in WordPress, or the plugin would profile
someone without consent (a GDPR problem). Equally, the WP UI should display the
read-back value so it shows the true state (closes the "WP shows opt-in while
Smaily says opt-out" gap).

---

## Effect of profiling-consent OFF — the two actions

When the read-back resolves to "do not profile" (either condition), the plugin
performs both:

1. **Engine opt-out** — call `Client::customer_opt_out(email, true, reason)` (the
   §10 method already built in 3.8). Data retained, excluded from
   recommendations, reversible.
2. **Beacon-stop** — no new browse events collected for this contact. This is the
   profiling gate on the beacon (the second of the two gates below).

### The beacon's two gates
The browse beacon already gates on browser-cookie consent (CookieYes / WP
Consent API, built in 3.4.2). Profiling consent adds a **second** gate. The
beacon sends only if **both** are on:

1. **Browser-cookie consent** (CookieYes) — the ePrivacy basis for cookies and
   firing the beacon at all.
2. **Profiling consent** (this spec, read back from Smaily) — the GDPR profiling
   basis.

Either off → beacon stops. They are distinct legal bases, so both are required.

---

## Open question for the WP marketing opt-in field (Erkki's second idea)

Erkki flagged: does WP have a marketing opt-in field such that reading the
contact back also surfaces whether the shopper left on the Smaily side, so the
WP settings page can display the correct state and pass through a WP-side
opt-out?

This is partly answered by the read-back design above (read-back is the
authority, surfaces Smaily-side departures). The remaining piece — whether to
mirror the read-back value into a WP-visible field on the settings page, and how
the WP settings-page opt-out maps to the write — is a UI/wiring detail to settle
when this piece is built. Tracked here, not lost.

---

## What's settled vs still open

**Settled (Erkki, 2026-06):**
- Endpoint: `/api/contact/` (existing, no new credential).
- Fields: `smaily_rec_profiling` (boolean 1/0, enforcement) +
  `smaily_rec_profiling_ts` (ISO-8601, Art 7 audit trail).
- Read-back is the authority (covers both WP-side and Smaily-side opt-out).
- **Model: OPT-OUT, default-on.** Enforcement: profile IF
  `is_unsubscribed != "1" AND smaily_rec_profiling != "0"` (don't-profile only on
  general unsubscribe OR explicit profiling opt-out; missing/206 → profile).
- OFF → engine opt-out (§10) + beacon-stop (the second beacon gate).

**Probe-confirmed against the live Smaily API ((a).0, 2026-06-09):**
- **Write works** via the existing `upsert_subscribers` (form-encoded array) →
  `{code:101}`; the custom fields **auto-create** (no Smaily account setup).
- **Read-back works** — `GET /api/contact.php?email=` returns the contact with
  `is_unsubscribed` + `smaily_rec_profiling` (+ `_ts`); a miss → `{code:206}`.
- Gotcha: Smaily's write **rejects `.test` email domains** (`{code:203}` "Invalid
  data") — live-walk test contacts must use a deliverable-format domain
  (`@example.com`). No latent bug in the existing contact sync (form-encoding is
  accepted).

**Still open:**
- **WP settings-page opt-out UX** ((a).2) — now a **GDPR requirement, not a nice-
  to-have**: the opt-out model is only lawful if the shopper has a working way to
  opt out. Covers the WP-side opt-out + mirroring the read-back value into a
  WP-visible field. Also: the **privacy policy must mention profiling**
  (transparency) — Erkki / docs side.
- **TODO — explicit opt-in if AKI requires it later.** The rule is built to invert:
  flip `is_allowed()` to opt-in (`profile IFF smaily_rec_profiling == "1"`) when
  needed. Tracked in DECISIONS F3-31.
