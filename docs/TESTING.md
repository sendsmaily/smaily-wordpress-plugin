# Smaily Connect — Pilot Acceptance Plan

**Purpose.** Defines what makes the pilot a **pass** (ready to onboard real
merchants) or a **fail** (needs more work). This is the business-level
engagement plan, distinct from INSTALL.md §3 (the merchant's technical
verify-it-works step). Audience: Erkki + whoever evaluates the pilot.

**Pilot context.** First pilot, single merchant (MiuMjau pet-shop). The goal is
to validate that the product **works reliably and is manageable** with a real
merchant — not yet to prove sales lift. Business metrics (CTR, conversion) are
**tracked for learning and future baselines, but are not pass/fail** at this
stage: one pilot lacks the volume and the before/after baseline for a
statistically meaningful business signal, and conversion is confounded by season
/ pricing / marketing. "Does it work and is it manageable" comes first; "does it
sell" comes with scale.

---

## Pass/fail dimension 1 — Technical stability

The sync works reliably across the pilot period.

| Criterion | Pass threshold | How to check |
|---|---|---|
| Sync reliability | No lost data; failed-count stays low and any failures are recoverable | Event Log: failed rows are rare, and Retry clears them |
| Backfill completeness | Initial backfill reaches 100% engine-confirmed, 0 stuck failed | Backfill panels (engine-confirmed counts, 3.10.0) |
| Engine availability | Rec-engine reachable through the pilot; no prolonged outages | Health notices (3.10.2): no sustained "engine unreachable" |
| Smaily sync | Contact/email sync works; no sustained "Smaily unreachable" | Health notices (Smaily-down signal) |
| No critical bugs | No data-corruption, no silent data loss, no crash | Event Log + error monitoring across the period |
| Recovery works | A sync gap can be seen and recovered without SSH/SQL | Troubleshoot flow (INSTALL §4): Event Log → Retry |

**Dimension 1 passes if:** the merchant's data syncs reliably, failures are
visible and recoverable through the UI, and no critical/data-loss bug occurs.

---

## Pass/fail dimension 2 — Merchant experience

The product is usable and manageable by a real merchant, not just technically
correct.

| Criterion | Pass threshold | How to check |
|---|---|---|
| Setup self-service | The merchant completes setup (the wizard) without developer intervention | Did setup need hand-holding, or did INSTALL.md suffice? |
| Diagnostics usefulness | When something looked wrong, the Event Log / notices explained it | Did the merchant (or support) resolve issues via the UI? |
| Onboarding clarity | INSTALL.md was enough to install, set up, verify | Gaps the merchant hit that the doc didn't cover |
| Overall satisfaction | The merchant would continue / recommend it | Direct merchant feedback at pilot end |

**Dimension 2 passes if:** the merchant could set up and operate the plugin
largely on their own, the diagnostics helped rather than confused, and the
merchant is satisfied enough to continue.

---

## Tracked but NOT pass/fail — Business metrics

Collected for learning and to establish baselines for future, larger pilots.
**Not gating** — a weak number here does not fail the pilot; it's signal for
later.

- **Recommendation CTR** — do shoppers click recommended products?
- **Conversion from recommendations** — do recommendations lead to orders?
- **Email engagement** (if rec-driven emails run) — open / click rates.
- **Attribution** — orders the engine credits to a recommendation
  (rec_attribution).

**Why tracked, not gated:** one pilot lacks volume and a clean before/after
baseline; conversion is confounded by season, pricing, and marketing. These
numbers inform whether/how to measure business lift in a scaled rollout — they
don't decide this pilot.

---

## Pilot logistics

Proposed defaults for the MiuMjau first pilot — adjust to the merchant
relationship and business calendar.

- **Duration — 4–6 weeks.** Long enough for a real stability signal (sync gaps,
  engine-uptime, and edge cases surface over time, not on day one) and for
  meaningful merchant feedback; short enough not to stall the first pilot. The
  goal is "does it work and is it manageable," not long-run business
  measurement.
- **Data scope — real data from pilot start.** MiuMjau's test/placeholder data
  is replaced with the real catalog / customers / orders before go-live, so the
  pilot tests the real thing. The initial backfill on real data is itself the
  first acceptance step (does backfill complete at real volume?). Precondition:
  MiuMjau is ready with real data before the pilot starts.
- **Check-in cadence — twice weekly for the first 2 weeks, then weekly.** Setup
  and the first sync are the riskiest window (failures show early), so check in
  more often at the start: review the Event Log (failed-count), the health
  notices, and gather merchant feedback. After it stabilises, weekly is enough.
- **Go/no-go review — at pilot end (4–6 weeks), Erkki + merchant feedback.**
  Erkki makes the technical + business call; the merchant supplies the
  experience/satisfaction input. Decided against the two pass/fail dimensions
  (technical stability + merchant experience); business metrics are reviewed as
  learning, not as a gate.

---

## Pilot pass = both dimensions pass

The pilot is a **pass** when **both** Technical stability **and** Merchant
experience pass. Business metrics are reviewed as learning, not as a gate. A
pass means: the product works reliably, a real merchant can operate it, and it's
ready to onboard further merchants — at which point business-lift measurement
(with baselines and volume) becomes the next question.
