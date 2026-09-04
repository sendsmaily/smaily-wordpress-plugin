# Documentation index

A catalog of every document in the Smaily Connect project — plugin repo, engine
repo, and Erkki's internal notes. Use this as the starting point if you don't know
where something is.

**Language convention:**
- All published documents in repos: **English**
  - *Exception:* the **merchant docs site** (`docs/site/index.html`) is **bilingual
    EN/ET** — it faces end-user merchants and mirrors the ET/EN Smaily docs sites.
    Keep both languages in sync in the same commit.
- Internal drafts and notes (only Erkki): **Estonian or English** (Erkki's choice)
- Code comments, commit messages, technical PR/issue text: **English**
- Conversations with Claude (development workflow): **Estonian** (Erkki's working
  language)

**Locations:**
- `plugin-repo/docs/` — Smaily Connect plugin documentation
- `engine-repo/docs/` — rec-engine documentation (separate repo, engine team owns it)
- Erkki's local working drafts — outside repos, regenerated to repos when ready

---

## Plugin documentation (`plugin-repo/docs/`)

### User-facing — ships with the plugin or in marketplace

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| `README.md` | Anyone evaluating the plugin | **Exists** | First impression. What the plugin does, who it's for, install summary, links to deeper docs. Lives at repo root, also surfaces in marketplace listings. (Refined further at Phase 3 end.) |
| `docs/site/index.html` | Any merchant (install → use → troubleshoot) | **Written** (2026-07-09) | **The merchant documentation site.** Single self-contained bilingual (EN/ET) HTML page mirroring the Shopify docs at `connect.smaily.com/docs`: Overview, Getting started (install + 6-step wizard), Settings, Importing existing data, Error messages & fixes, FAQ, Data & privacy. No build step, no external deps; hosted separately by Erkki, **not shipped in the ZIP** (`.zipignore` excludes `docs/`). Keep current in the same commit that changes user-visible behavior, **in both languages** — see CLAUDE.md "Keeping the docs current" + "Merchant docs site". |
| `docs/INSTALL.md` | Links from README/STATUS/INDEX | **Stub → site** (2026-07-09) | Now a thin pointer to `docs/site/index.html` (which superseded it) + the requirements table + a "where to look" map. The full install/wizard/verify/troubleshoot content moved into the site so it can't drift in two places. |
| `MIGRATION.md` | WP-admin running the wordpress.org `smaily-connect` 2.0.0 package | **Written** (`docs/MIGRATION.md`) | How to upgrade 2.0.0 → 3.11.2: the directory's own update, what persists, wizard takeover, rollback path. (This row previously said TODO after the doc had shipped — fixed 2026-06-11.) |
| `FAQ.md` / `TROUBLESHOOTING.md` | WP-admin running into problems | **Partly done — deliberately deferred** (PRO-1197 left it open on purpose); first pass lives in the docs site (2026-07-09) | A first FAQ + "Error messages & fixes" now lives in `docs/site/index.html` (built from the known error/notice surfaces). The **real user questions** — symptom→cause→fix patterns from live pilot support — are deliberately written only **after** the pilot asks them (writing them earlier would be speculation); fold them back into the site. |
| `CHANGELOG.md` | Anyone tracking versions | **Written** (2026-06-11, repo root) | Version-by-version what changed: full 2.0.0-beta.1 entry + the 1.x history. `readme.txt` carries the same content in wp.org format. |
| `docs/TESTING.md` (pilot-acceptance) | Pilot engagement: is it production-ready? | **Written** (2026-06-11, from Erkki's criteria) | Business pass/fail criteria for the pilot engagement (distinct from INSTALL.md's technical verify): two gating dimensions (technical stability + merchant experience), business metrics tracked-not-gated, and logistics (4–6 wk, real data from start, check-in cadence, go/no-go review). NB: the existing root `/TESTING.md` is a separate Phase-2 dev sanity-test, not this. |

### Developer-facing — lives in repo, may be public or repo-only

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| `CLAUDE.md` (repo root) | Any agent picking up the repo | **Exists** | Agent working guide — the entry point. Workflow rhythm, operational knowledge (`sg docker`, setup-token, woocommerce-stubs PHPStan-only, IsoDate), do-not-do scars, the architecture pattern. Read first. |
| `STATUS.md` (repo root) | Any agent/dev, the coordinator | **Active** | Single source of "where we are now": done/in-progress, lock conditions, pilot go-live checklist, roadmap. Kept current in the same commit that changes reality. |
| `BACKLOG.md` (repo root) | Any agent/dev, pilot go/no-go | **Active** | Consolidated deferred-work index — every deferred item with priority (🔴 pilot-need / 🟡 post-pilot / 🟢 nice-to-have / 🔵 manual verification), why-deferred, and doc location. STATUS = "where we are now"; BACKLOG = "what's deferred"; DECISIONS = the rationale (linked, not duplicated). |
| `ARCHITECTURE.md` | Future developers, possible upstream reviewers | **Written** (2026-07-11, refreshed 2026-07-21, `docs/ARCHITECTURE.md`, PRO-1197) | High-level map: dual-namespace coexistence + the two gates (`setup_completed` vs `is_connected()`), the ingest pipeline pattern (PayloadBuilder → IngestQueue → Flusher, D6), storefront pieces (sc-runtime/relay, LandingCapture + browser-side attribution capture, ongoing-session identity injection, consent), Action Scheduler jobs, custom tables + migrations, admin React app, i18n. Links to `DECISIONS.md` for reasoning. |
| `docs/DECISIONS.md` | Future developers | **Finalized** (2026-06-11, was `DECISIONS_DRAFT.md`) | All significant technical decisions with reasoning — a single-file, F-numbered decision log, kept current in the same commit a decision changes. The ADR-per-file split was considered and rejected: the F-numbered log IS the working format. |
| `DEVELOPER.md` | Future contributors | **Written** (2026-07-11, reviewed 2026-07-21 — no changes needed, `docs/DEVELOPER.md`, PRO-1197) | Dev environment (wp-env, `sg docker`, the `smly_rec_*` snapshot guard), build/test commands (ci:strict, integration wrapper, filtered runs, live-walks), release pointer, coding + testing conventions, the same-commit docs rule. Summarizes CLAUDE.md for humans; CLAUDE.md stays the detailed operational source. |
| `API.md` | Anyone extending the plugin | **Written** (2026-07-11, refreshed 2026-07-21, `docs/API.md`, PRO-1197) | The plugin's own surfaces: all `smaily-connect/v1` REST routes (incl. the public `/relay` proxy, its defense layers, and the PRO-1389 logged-in identity injection), every `smaily_connect_*` filter, action hooks, the `window.smailyConnectBeacon` JS global, `bin/` tooling; pointers to the engine contract for outbound APIs. |
| `LESSONS.md` | Future developers, Erkki for next projects | **Exists** | General lessons from building with an AI agent. Carries forward to the next project (Shopify app, etc.). Updated as new lessons emerge. |
| `WP7_COMPAT.md` | Future developers, strategy reviewers | **Exists** | WordPress 7 compatibility plan and strategic opportunities (Connectors / Abilities / MCP). Updated as WP 7 ecosystem matures. |
| `RECENGINE_API_CONTRACT.md` | Plugin developers + engine team | **Exists** | Authoritative API contract between plugin and rec engine. Single source of truth. Both teams reference this. |
| `PLUGIN.md` | Plugin developers | **Exists** | Original plugin specification. Some sections superseded by `DECISIONS.md` and `ARCHITECTURE.md` after Phase 3. |
| `PLUGIN_IMPLEMENTATION_WP.md` | Plugin developers | **Exists, partially outdated** | WordPress-specific implementation guide. Updated at Phase 3 end (variant A custom queue replaces old AS-native sketch — see `DECISIONS.md` F3-7). |
| `STYLE_MAPPING.md` | UI developers | **Exists** | Tailwind tokens, color palette, the layered-input pattern, primitive components. |
| `FIELD_MAPPING.md` | Plugin developers, integration consumers | **Exists** | Canonical field-naming standard (Smaily WC plugin convention is canonical). Required reading for anyone touching subscriber sync. |
| **Audits** (`docs/audits/`) | Devs, security / merge reviewers | **Active register** | All audit reports + the register table live in `docs/audits/` — start at [`docs/audits/INDEX.md`](audits/INDEX.md). Holds: codebase audit (Fable), Security audit, Code-quality + wp.org-readiness audit, Upstream audit, Upstream comparison (snapshot), Mock↔engine divergence. The **re-audit policy** (when bigger changes force a fresh pass) lives there too. |
| `ENGINE_TEAM_PILOT_SYNC.md` | Engine team + Erkki | **Written** (2026-06-12) | Pilot go-live joint-sync brief: what the engine will see from the pilot tenant after beta.2 (synthetic `wc-{id}` keys, catalog backfill jump, orders retry flood, browse sku), the joint verification checklist, contract byte-sync action. Paste into the engine conversation; one-shot doc for the go-live window. |
| `SPEC_DRAFT_BROWSE_ABANDONED_CART.md` | Engine team + Erkki | **Draft** (2026-06-12, post-pilot 🟡) | Feature spec draft: engine-side abandoned-cart from browse signals for guest-only stores (identity via Smaily-click/checkout merge; order-based suppression; consent gate; F3-37 age-window requirement). BACKLOG 🟡 entry links here. |
| `TRIGGER_ROADMAP_DRAFT.md` | Erkki, product/strategy | **Draft** (2026-06-12, post-pilot 🟡) | Idea register: Family A = engine-free Woo→Smaily triggers (post-purchase, review ask, cross/upsell, contact-field enrichment, cancellation recovery); Family B = engine-side sweeps (replenishment, win-back, back-in-stock…). Two-tier engine-upsell frame; 2 concrete plugin items (variation-stock hook gap, P10 no-email guard). |

### Internal — Erkki's working drafts

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| ~~`DECISIONS_DRAFT.md`~~ | — | **Finalized** as `docs/DECISIONS.md` (2026-06-11) | Was the Phase-3 working draft; now the canonical decision log (see the developer-facing table above). |
| `HANDOFF_PROMPT.md` (`spec/`) | — | **Superseded** | Old re-briefing template (pre-Phase-3). Replaced by `/CLAUDE.md` + `/STATUS.md`; kept with a banner for history. |
| `PROJECT_PLAN.md` | Erkki | **Exists** | Original project plan with phases. Some sections superseded by reality (Phase 2 took longer than planned). |
| `ROADMAP.md` | Erkki, strategic | **Exists** | Long-term roadmap: Milestone 1 (WC plugin), Milestone 2 (npm @smaily/recengine-client), Milestone 3 (Shopify app), Milestone 4 (Magento, TBD). |
| `SUGGESTION.md` | Erkki | **Exists** | Early-phase product suggestions, mostly historical. |

---

## Engine documentation (`engine-repo/docs/`)

These live in the **engine repo**, owned by the engine team. The plugin team
references them; the engine team writes them. Listed here for completeness so
anyone working across both sides knows what exists.

| Document | Audience | Description |
|----------|----------|-------------|
| `RECENGINE_API_CONTRACT.md` (copy/mirror of plugin's) | Both teams | The canonical API contract. Single source of truth. Should match the plugin-side copy byte-for-byte. |
| `PILOT_MONITORING.md` | Erkki, engine team | SQL queries, admin UI checkpoints, Vercel logs filters for monitoring the pilot's first live data. Used during pilot rollout. |
| Engine architecture / internals | Engine team | Owned by the engine team; plugin team doesn't need direct access. |

---

## Documentation that doesn't exist yet but probably should

These come up in conversation but haven't been written. Listed here so they don't
get forgotten.

| Document | When to write | Notes |
|----------|--------------|-------|
| ~~`SECURITY_AUDIT.md`~~ | — | **Written** (2026-06-25) as [`docs/audits/SECURITY_AUDIT.md`](audits/SECURITY_AUDIT.md): high-risk-surface + broad security pass on the 906cf3d..HEAD delta, wp.org bar, dependency audit. 0 Critical / 0 High. |
| ~~`CODE_QUALITY_AUDIT.md`~~ | — | **Written** (2026-06-25) as [`docs/audits/CODE_QUALITY_AUDIT.md`](audits/CODE_QUALITY_AUDIT.md): changed-code quality/architecture + full PCP 2.0.0 + plugin-review readiness; carries the 3.0-GA + upstream-merge punch-list. |
| ~~`UPSTREAM_MERGE_PROPOSAL.md`~~ | — | **Written** (2026-06-25) as [`docs/UPSTREAM_MERGE_PROPOSAL.md`](UPSTREAM_MERGE_PROPOSAL.md): the case + decision request for the Smaily team to take the fork's rewrite over wholesale into the wordpress.org plugin (superset argument, the GCM-vs-CBC + test-coverage comparison, and the prerequisites/prep checklist). Links to DECISIONS + `docs/audits/`. |

---

## Maintenance

When adding a new document to either repo, add a row to this index. When a
document changes status (e.g. `TODO` → `Exists`), update its row.

This index is itself a document — keep it current. Stale indexes erode trust faster
than missing ones.

**Last reviewed:** 2026-07-11 — `ARCHITECTURE.md`, `DEVELOPER.md`, `API.md`
written (PRO-1197; the last three TODO developer docs). FAQ/TROUBLESHOOTING
stays deliberately deferred until pilot support traffic supplies real questions.

**Refreshed 2026-07-21** (PRO-1197 currency pass over the 700d870..HEAD delta):
`ARCHITECTURE.md` and `API.md` updated for PRO-1389 (server-side logged-in
identity injection on `/relay`), PRO-1388 (consent-independent browser-side
attribution capture), PRO-1390 (`cart_add`/`cart_remove` sku resolution via
`SkuResolver`), PRO-1446 (batch-size cap ordering), and the v1.5.0 contract
sync (§14 notifications ingest, PRO-1447). `DEVELOPER.md` reviewed — nothing
in the delta touched dev environment/build/test/release process, no edit
needed.
